<?php
/**
 * Durable yt-dlp download session for invisible Pixr YouTube Lite.
 *
 * YouTube removed OAuth login from yt-dlp (2024.11+). The only supported
 * always-on auth for yt-dlp is a Netscape cookie jar exported once via the
 * robots.txt method and never reopened in a browser tab.
 *
 * This store is the convert engine for guests only.
 * Signed-in visitors use data/oauth/{sub}_cookies.txt (account.php) — never this Pixr jar.
 * Google Cloud OAuth is separate — feeds / best-effort Innertube Bearer only.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cookie_auth.php';

function ytl_ytdlp_session_dir(): string
{
    $dir = dirname(__DIR__) . '/data/ytdlp_oauth';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
    return $dir;
}

function ytl_ytdlp_session_cookie_path(): string
{
    return ytl_ytdlp_session_dir() . '/cookies.txt';
}

function ytl_ytdlp_session_status_path(): string
{
    return ytl_ytdlp_session_dir() . '/status.json';
}

/** @return array{linked:bool,ok:bool,bytes:int,updated_at:int,reason:string,account:string,method:string} */
function ytl_ytdlp_session_status(): array
{
    $path = ytl_ytdlp_session_cookie_path();
    $linked = is_readable($path) && @filesize($path) > 20;
    $meta = [];
    $sp = ytl_ytdlp_session_status_path();
    if (is_readable($sp)) {
        $j = json_decode((string) file_get_contents($sp), true);
        if (is_array($j)) {
            $meta = $j;
        }
    }
    return [
        'linked' => $linked,
        'ok' => $linked && !empty($meta['ok']),
        'bytes' => $linked ? (int) filesize($path) : 0,
        'updated_at' => (int) ($meta['updated_at'] ?? ($linked ? (int) filemtime($path) : 0)),
        'reason' => (string) ($meta['reason'] ?? ($linked ? 'Session présente (non vérifiée).' : 'Pas de session yt-dlp.')),
        'account' => (string) ($meta['account'] ?? ''),
        'method' => 'netscape_robots_txt', // yt-dlp YouTube OAuth removed by upstream
    ];
}

function ytl_ytdlp_session_write_status(array $data): void
{
    $payload = array_merge([
        'ok' => false,
        'updated_at' => time(),
        'reason' => '',
        'account' => '',
        'method' => 'netscape_robots_txt',
    ], $data);
    $payload['updated_at'] = time();
    @file_put_contents(
        ytl_ytdlp_session_status_path(),
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n",
        LOCK_EX
    );
}

/**
 * Save Netscape / Cookie-header text as the durable yt-dlp session.
 * Also mirrors into youtube.cookies.txt for Innertube cookie fallbacks.
 */
function ytl_ytdlp_session_save(string $cookieText): array
{
    if (!function_exists('ytl_save_youtube_cookies')) {
        require_once __DIR__ . '/helpers.php';
    }
    $cookieText = trim($cookieText);
    if ($cookieText === '') {
        return ['ok' => false, 'reason' => 'Cookie vide.'];
    }
    // Reuse existing Netscape normalizer via shared cookie save, then copy.
    if (!ytl_save_youtube_cookies($cookieText)) {
        return ['ok' => false, 'reason' => 'Écriture cookie partagé impossible.'];
    }
    $cfg = ytl_config();
    $shared = (string) ($cfg['youtube_cookie_file'] ?? (dirname(__DIR__) . '/data/youtube.cookies.txt'));
    if (!is_readable($shared)) {
        return ['ok' => false, 'reason' => 'Fichier cookie partagé introuvable après save.'];
    }
    $dest = ytl_ytdlp_session_cookie_path();
    if (!@copy($shared, $dest)) {
        $raw = (string) file_get_contents($shared);
        if (@file_put_contents($dest, $raw, LOCK_EX) === false) {
            return ['ok' => false, 'reason' => 'Écriture data/ytdlp_oauth/cookies.txt impossible.'];
        }
    }
    @chmod($dest, 0600);

    require_once __DIR__ . '/api.php';
    $acctLabel = ytl_client()->cookieAccountLabel();
    $acctCheck = ytl_youtube_cookie_account_ok($acctLabel);
    if (!$acctCheck['ok']) {
        // Keep the durable jar on disk — convert prefers Cobalt/Piped; never delete on probe fail.
        ytl_ytdlp_session_write_status([
            'ok' => false,
            'reason' => (string) $acctCheck['reason'] . ' (jar kept)',
            'account' => '',
        ]);
        return ['ok' => false, 'reason' => (string) $acctCheck['reason']];
    }
    $health = ytl_cookie_health_probe('ytdlp_session');
    $who = trim(($acctLabel['name'] ?? '') . ' ' . ($acctLabel['handle'] ?? '') . ' ' . ($acctLabel['email'] ?? ''));
    $ok = !empty($health['ok']);
    $reason = $ok
        ? ('Session yt-dlp OK — ' . ($who !== '' ? $who : 'pixryoutubelite'))
        : (string) ($health['reason'] !== '' ? $health['reason'] : 'Session enregistrée, santé incertaine.');
    ytl_ytdlp_session_write_status([
        'ok' => $ok,
        'reason' => $reason,
        'account' => $who,
    ]);
    return ['ok' => $ok, 'reason' => $reason, 'account' => $who];
}

function ytl_ytdlp_session_unlink(): void
{
    $path = ytl_ytdlp_session_cookie_path();
    if (is_file($path)) {
        @unlink($path);
    }
    ytl_ytdlp_session_write_status([
        'ok' => false,
        'reason' => 'Session yt-dlp déliée.',
        'account' => '',
    ]);
}

/**
 * Restore durable Netscape jar from shared / .bak-history if the session file is missing.
 * Never deletes backups. Returns path used, or '' if nothing restored.
 */
function ytl_ytdlp_session_ensure_jar(): string
{
    $session = ytl_ytdlp_session_cookie_path();
    if (is_readable($session) && (int) @filesize($session) > 20) {
        return $session;
    }
    $cfg = function_exists('ytl_http_config') ? ytl_http_config() : ytl_config();
    $shared = (string) ($cfg['youtube_cookie_file'] ?? (dirname(__DIR__) . '/data/youtube.cookies.txt'));
    $candidates = [];
    if ($shared !== '') {
        $candidates[] = $shared;
        $candidates[] = $shared . '.bak-history';
        $candidates[] = $shared . '.bak';
        $dir = dirname($shared);
        $candidates[] = $dir . '/youtube.cookies.txt.bak-history';
    }
    foreach ($candidates as $src) {
        if ($src === '' || !is_readable($src) || (int) @filesize($src) <= 20) {
            continue;
        }
        if (@copy($src, $session) || @file_put_contents($session, (string) file_get_contents($src), LOCK_EX) !== false) {
            @chmod($session, 0600);
            ytl_ytdlp_session_write_status([
                'ok' => true,
                'reason' => 'Restored durable jar from ' . basename($src),
                'account' => (string) (ytl_ytdlp_session_status()['account'] ?? ''),
            ]);
            return $session;
        }
    }
    return is_readable($session) && (int) @filesize($session) > 20 ? $session : '';
}

/**
 * Cookie path for converter.py — prefers durable ytdlp session, else shared file.
 * Prefer a present Netscape jar over a stale cookie_health "invalid" flag: yt-dlp is
 * the real probe. Never treat health "invalid" as missing jar.
 */
function ytl_ytdlp_session_cookie_for_convert(): string
{
    ytl_ytdlp_session_ensure_jar();

    $session = ytl_ytdlp_session_cookie_path();
    $sessMeta = ytl_ytdlp_session_status();
    $sessionBytes = is_readable($session) ? (int) @filesize($session) : 0;
    $sessionOk = $sessionBytes > 20 && !empty($sessMeta['ok']);
    if ($sessionOk) {
        return $session;
    }
    // Jar on disk: use it even if health JSON is stale-invalid (common after a one-off fail).
    if ($sessionBytes > 20) {
        return $session;
    }
    $cfg = function_exists('ytl_http_config') ? ytl_http_config() : ytl_config();
    $shared = (string) ($cfg['youtube_cookie_file'] ?? '');
    if ($shared !== '' && is_readable($shared) && @filesize($shared) > 20) {
        // Always let yt-dlp try a present shared jar — health JSON is advisory only.
        return $shared;
    }
    return '-';
}

function ytl_ytdlp_session_usable(): bool
{
    $p = ytl_ytdlp_session_cookie_for_convert();
    return $p !== '-' && $p !== '' && is_readable($p) && @filesize($p) > 20;
}
