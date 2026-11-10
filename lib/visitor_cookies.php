<?php
/**
 * Per-visitor YouTube Netscape cookie jars for signed-in convert.
 *
 * Google Cloud OAuth only stores access/refresh tokens (data/oauth/{sub}.json).
 * YouTube’s player API rejects those Bearers (HTTP 400), and yt-dlp removed
 * YouTube OAuth (2024.11). Real downloads need SID/HSID/SSID/LOGIN_INFO /
 * __Secure-* cookies in Netscape form.
 *
 * After Sign-in, the visitor pastes THEIR youtube.com export into
 * data/oauth/{sub}_cookies.txt. Convert uses that jar — never the Pixr guest jar —
 * when the visitor is signed in.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/oauth.php';

function ytl_visitor_cookie_safe_sub(string $sub): string
{
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $sub);
    return is_string($safe) && $safe !== '' ? $safe : 'unknown';
}

function ytl_visitor_cookie_path(?string $sub = null): string
{
    if ($sub === null) {
        $user = ytl_current_user();
        $sub = $user['sub'] ?? '';
    }
    $sub = trim((string) $sub);
    if ($sub === '') {
        return '';
    }
    return ytl_oauth_token_dir() . '/' . ytl_visitor_cookie_safe_sub($sub) . '_cookies.txt';
}

function ytl_visitor_cookie_status_path(?string $sub = null): string
{
    if ($sub === null) {
        $user = ytl_current_user();
        $sub = $user['sub'] ?? '';
    }
    $sub = trim((string) $sub);
    if ($sub === '') {
        return '';
    }
    return ytl_oauth_token_dir() . '/' . ytl_visitor_cookie_safe_sub($sub) . '_cookies_status.json';
}

/**
 * @return list<string>
 */
function ytl_visitor_cookie_names_from(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $names = [];
    if (str_contains($raw, "\t") || str_contains($raw, '#HttpOnly_') || str_contains($raw, 'Netscape')) {
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            $line = trim((string) $line);
            if (str_starts_with($line, '#HttpOnly_')) {
                $line = substr($line, strlen('#HttpOnly_'));
            } elseif ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $cols = preg_split('/\t/', $line);
            if (is_array($cols) && count($cols) >= 7) {
                $names[] = $cols[5];
            }
        }
    } else {
        preg_match_all('/(?:^|;\s*)([^=\s]+)=/', $raw, $m);
        $names = $m[1] ?? [];
    }
    return array_values(array_unique(array_map('strval', $names)));
}

/**
 * @return list<string>
 */
function ytl_visitor_cookie_missing_from(string $raw): array
{
    $names = ytl_visitor_cookie_names_from($raw);
    if ($names === []) {
        return ['(aucun cookie)'];
    }
    // Modern Chrome often lacks SID/HSID on .youtube.com — Secure-PSID + LOGIN_INFO is enough.
    $need = ['LOGIN_INFO', '__Secure-1PSID', '__Secure-3PSID'];
    $soft = ['SAPISID', '__Secure-3PAPISID', 'SSID'];
    $missing = [];
    foreach ($need as $n) {
        if (!in_array($n, $names, true)) {
            $missing[] = $n;
        }
    }
    $hasSapisid = in_array('SAPISID', $names, true) || in_array('__Secure-3PAPISID', $names, true);
    if (!$hasSapisid) {
        $missing[] = 'SAPISID/__Secure-3PAPISID';
    }
    // SSID optional if Secure-PSID present.
    unset($soft);
    return $missing;
}

/**
 * @return list<string>
 */
function ytl_visitor_cookie_export_steps(): array
{
    return [
        'Open a private/incognito window.',
        'Sign in to YouTube with the SAME Google account you used on Pixr Lite Sign in.',
        'In that same tab, open https://www.youtube.com/robots.txt (do not browse the YouTube homepage with this session).',
        'Export youtube.com cookies with Cookie-Editor or “Get cookies.txt LOCALLY” (Netscape format).',
        'Close the entire private window so YouTube never rotates those cookies again.',
        'Paste the export below and Save — Convert will use YOUR jar, not the guest Pixr session.',
    ];
}

/**
 * @return array{linked:bool,ok:bool,bytes:int,updated_at:int,reason:string,account:string,path:string}
 */
function ytl_visitor_cookie_status(?string $sub = null): array
{
    $path = ytl_visitor_cookie_path($sub);
    $linked = $path !== '' && is_readable($path) && @filesize($path) > 20;
    $meta = [];
    $sp = ytl_visitor_cookie_status_path($sub);
    if ($sp !== '' && is_readable($sp)) {
        $j = json_decode((string) file_get_contents($sp), true);
        if (is_array($j)) {
            $meta = $j;
        }
    }
    return [
        'linked' => $linked,
        'ok' => $linked && !empty($meta['ok']),
        'bytes' => $linked ? (int) filesize($path) : 0,
        'updated_at' => (int) ($meta['updated_at'] ?? ($linked ? (int) @filemtime($path) : 0)),
        'reason' => (string) ($meta['reason'] ?? ($linked
            ? 'Cookie jar present (not verified).'
            : 'No YouTube cookie jar for this Sign-in yet.')),
        'account' => (string) ($meta['account'] ?? ''),
        'path' => $path,
    ];
}

/** @param array<string,mixed> $data */
function ytl_visitor_cookie_write_status(array $data, ?string $sub = null): void
{
    $sp = ytl_visitor_cookie_status_path($sub);
    if ($sp === '') {
        return;
    }
    $payload = array_merge([
        'ok' => false,
        'updated_at' => time(),
        'reason' => '',
        'account' => '',
        'method' => 'netscape_robots_txt',
    ], $data);
    $payload['updated_at'] = time();
    @file_put_contents(
        $sp,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n",
        LOCK_EX
    );
    @chmod($sp, 0600);
}

function ytl_visitor_cookie_usable(?string $sub = null): bool
{
    $st = ytl_visitor_cookie_status($sub);
    if (!$st['linked'] || $st['bytes'] < 20) {
        return false;
    }
    // Prefer ok=true, but a present jar is still tried by yt-dlp (health can be stale).
    return true;
}

/**
 * Cookie path for converter.py when the visitor is signed in.
 * Returns '-' when there is no visitor jar (do NOT fall back to Pixr for identity).
 */
function ytl_visitor_cookie_for_convert(): string
{
    $user = ytl_current_user();
    if ($user === null) {
        return '-';
    }
    $path = ytl_visitor_cookie_path($user['sub']);
    if ($path === '' || !is_readable($path) || @filesize($path) < 20) {
        return '-';
    }
    return $path;
}

/**
 * Save Netscape / Cookie-header text as this visitor’s download jar.
 *
 * @return array{ok:bool,reason:string,account?:string}
 */
function ytl_visitor_cookie_save(string $cookieText, ?string $sub = null): array
{
    if ($sub === null) {
        $user = ytl_current_user();
        $sub = $user['sub'] ?? '';
    }
    $sub = trim((string) $sub);
    if ($sub === '') {
        return ['ok' => false, 'reason' => 'Sign in required.'];
    }
    $cookieText = trim($cookieText);
    if ($cookieText === '') {
        return ['ok' => false, 'reason' => 'Empty cookie paste.'];
    }
    $netscape = ytl_cookie_to_netscape($cookieText);
    if ($netscape === '') {
        return ['ok' => false, 'reason' => 'Invalid format (need Netscape or Cookie header).'];
    }
    $missing = ytl_visitor_cookie_missing_from($netscape);
    if ($missing !== []) {
        return [
            'ok' => false,
            'reason' => 'Incomplete YouTube session — missing: ' . implode(', ', $missing)
                . '.',
        ];
    }

    $path = ytl_visitor_cookie_path($sub);
    if ($path === '') {
        return ['ok' => false, 'reason' => 'Could not resolve cookie path.'];
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return ['ok' => false, 'reason' => 'Could not create data/oauth directory.'];
    }
    if (@file_put_contents($path, $netscape, LOCK_EX) === false) {
        return ['ok' => false, 'reason' => 'Could not write cookie jar.'];
    }
    @chmod($path, 0600);

    require_once __DIR__ . '/innertube.php';
    $cfg = ytl_config();
    $cfg['youtube_cookie'] = '';
    $cfg['youtube_cookie_file'] = $path;
    $it = new InnertubeClient($cfg);
    $label = $it->accountLabel();
    $probe = $it->cookieHomeProbe(6);
    $who = trim(
        (string) ($label['name'] ?? '') . ' '
        . (string) ($label['handle'] ?? '') . ' '
        . (string) ($label['email'] ?? '')
    );
    $loggedIn = !empty($probe['logged_in']);
    $oauthUser = ytl_current_user();
    $oauthEmail = strtolower(trim((string) ($oauthUser['email'] ?? '')));
    $cookieEmail = strtolower(trim((string) ($label['email'] ?? '')));
    $mismatch = '';
    if ($oauthEmail !== '' && $cookieEmail !== '' && $oauthEmail !== $cookieEmail
        && !str_contains($cookieEmail, explode('@', $oauthEmail)[0])) {
        $mismatch = ' Warning: cookie account email (' . $cookieEmail
            . ') differs from Sign-in (' . $oauthEmail . ').';
    }

    if (!$loggedIn) {
        @unlink($path);
        ytl_visitor_cookie_write_status([
            'ok' => false,
            'reason' => 'YouTube did not see a logged-in session for this optional jar.' . $mismatch,
            'account' => $who,
        ], $sub);
        return [
            'ok' => false,
            'reason' => 'YouTube did not see a logged-in session for this optional jar.' . $mismatch,
        ];
    }

    $reason = 'Your YouTube cookie jar is ready for You feeds + Convert'
        . ($who !== '' ? (' — ' . $who) : '')
        . '.' . $mismatch;
    ytl_visitor_cookie_write_status([
        'ok' => true,
        'reason' => $reason,
        'account' => $who,
    ], $sub);
    return ['ok' => true, 'reason' => $reason, 'account' => $who];
}

/** Clear cached personalized feeds after jar save/remove. */
function ytl_visitor_cookie_bust_feed_caches(?string $sub = null): void
{
    if ($sub === null) {
        $user = ytl_current_user();
        $sub = $user['sub'] ?? '';
    }
    $safe = ytl_visitor_cookie_safe_sub((string) $sub);
    if ($safe === '' || $safe === 'unknown') {
        return;
    }
    $base = dirname(__DIR__) . '/data/videos/.cache';
    if (!is_dir($base)) {
        return;
    }
    $patterns = [
        'feed_lib_v3_*_' . $safe . '.json',
        'feed_rec_user_v3_' . $safe . '.json',
        'feed_rec_user_shelves_v3_' . $safe . '.json',
    ];
    foreach ($patterns as $pat) {
        foreach (glob($base . '/' . $pat) ?: [] as $file) {
            @unlink($file);
        }
    }
    // Also clear any lib keys where sub was sanitized differently
    foreach (glob($base . '/feed_lib_v3_*.json') ?: [] as $file) {
        if (str_contains(basename($file), $safe)) {
            @unlink($file);
        }
    }
}

function ytl_visitor_cookie_unlink(?string $sub = null): void
{
    $path = ytl_visitor_cookie_path($sub);
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
    ytl_visitor_cookie_write_status([
        'ok' => false,
        'reason' => 'YouTube cookie jar removed.',
        'account' => '',
    ], $sub);
}

function ytl_visitor_cookie_mark_invalid(string $reason, ?string $sub = null): void
{
    $st = ytl_visitor_cookie_status($sub);
    ytl_visitor_cookie_write_status([
        'ok' => false,
        'reason' => $reason !== '' ? $reason : 'Cookie jar rejected by YouTube / yt-dlp.',
        'account' => (string) ($st['account'] ?? ''),
    ], $sub);
}
