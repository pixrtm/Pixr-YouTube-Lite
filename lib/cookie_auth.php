<?php

/**
 * Shared YouTube cookie health for convert / Recommended.
 *
 * Hard limit: Google blocked password/OAuth login for yt-dlp. Only Netscape
 * cookies work. This module validates them, records status, and flags expiry
 * so the admin can re-export without guessing.
 */

require_once __DIR__ . '/helpers.php';

function ytl_cookie_health_path(): string
{
    return dirname(__DIR__) . '/data/cookie_health.json';
}

/**
 * @return array{
 *   ok:bool,
 *   state:string,
 *   checked_at:int,
 *   logged_in:bool,
 *   account:string,
 *   missing:list<string>,
 *   reason:string,
 *   source:string,
 *   alert:bool
 * }
 */
function ytl_cookie_health_default(): array
{
    return [
        'ok' => false,
        'state' => 'unknown',
        'checked_at' => 0,
        'logged_in' => false,
        'account' => '',
        'missing' => [],
        'reason' => '',
        'source' => '',
        'alert' => false,
    ];
}

/** @return array<string,mixed> */
function ytl_cookie_health_read(): array
{
    $path = ytl_cookie_health_path();
    if (!is_readable($path)) {
        return ytl_cookie_health_default();
    }
    $raw = (string) file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ytl_cookie_health_default();
    }
    return array_merge(ytl_cookie_health_default(), $data);
}

/** @param array<string,mixed> $data */
function ytl_cookie_health_write(array $data): bool
{
    $path = ytl_cookie_health_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return false;
    }
    $payload = array_merge(ytl_cookie_health_default(), $data);
    $payload['checked_at'] = (int) ($payload['checked_at'] ?: time());
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    $ok = @file_put_contents($path, $json . "\n", LOCK_EX);
    if ($ok === false) {
        return false;
    }
    @chmod($path, 0644);
    return true;
}

/**
 * Mark cookies invalid from convert/yt-dlp output (no network probe).
 */
function ytl_cookie_health_mark_invalid(string $reason, string $source = 'convert'): void
{
    $reason = trim($reason);
    if ($reason === '') {
        $reason = 'YouTube cookies no longer valid';
    }
    if (strlen($reason) > 220) {
        $reason = substr($reason, 0, 217) . '…';
    }
    $prev = ytl_cookie_health_read();
    ytl_cookie_health_write([
        'ok' => false,
        'state' => 'invalid',
        'checked_at' => time(),
        'logged_in' => false,
        'account' => (string) ($prev['account'] ?? ''),
        'missing' => is_array($prev['missing'] ?? null) ? $prev['missing'] : [],
        'reason' => $reason,
        'source' => $source,
        'alert' => true,
    ]);
}

/** True when yt-dlp / convert log line means the shared session is dead. */
function ytl_cookie_auth_failure_text(string $text): bool
{
    $t = strtolower($text);
    if ($t === '') {
        return false;
    }
    $needles = [
        'cookies are no longer valid',
        'provided youtube account cookies are no longer valid',
        'sign in to confirm you',
        'please sign in',
        'login required',
        'use --cookies',
        'http error 401',
        'http error 403: forbidden',
    ];
    foreach ($needles as $n) {
        if (str_contains($t, $n)) {
            return true;
        }
    }
    return false;
}

/**
 * Live probe: file presence, required names, Innertube account + logged_in.
 *
 * @return array{
 *   ok:bool,
 *   state:string,
 *   checked_at:int,
 *   logged_in:bool,
 *   account:string,
 *   missing:list<string>,
 *   reason:string,
 *   source:string,
 *   alert:bool,
 *   videos:int
 * }
 */
function ytl_cookie_health_probe(string $source = 'probe'): array
{
    $out = ytl_cookie_health_default();
    $out['checked_at'] = time();
    $out['source'] = $source;

    if (!ytl_has_youtube_cookie()) {
        $out['state'] = 'missing';
        $out['reason'] = 'Aucun cookie Pixr YouTube Lite sur le serveur.';
        $out['alert'] = true;
        $out['missing'] = ['(aucun cookie)'];
        ytl_cookie_health_write($out);
        return $out + ['videos' => 0];
    }

    $missing = ytl_youtube_cookie_missing();
    $out['missing'] = $missing;
    if ($missing !== []) {
        $out['state'] = 'incomplete';
        $out['reason'] = 'Cookie incomplet — manque : ' . implode(', ', $missing);
        $out['alert'] = true;
        ytl_cookie_health_write($out);
        return $out + ['videos' => 0];
    }

    require_once __DIR__ . '/api.php';
    $client = ytl_client();
    $label = $client->cookieAccountLabel();
    $acct = ytl_youtube_cookie_account_ok($label);
    $who = trim(
        (string) ($label['name'] ?? '') . ' '
        . (string) ($label['handle'] ?? '') . ' '
        . (string) ($label['email'] ?? '')
    );
    $out['account'] = $who !== '' ? $who : 'Pixr YouTube Lite';

    if (!$acct['ok']) {
        $out['state'] = 'wrong_account';
        $out['reason'] = (string) ($acct['reason'] ?? 'Wrong Google account');
        $out['alert'] = true;
        ytl_cookie_health_write($out);
        return $out + ['videos' => 0];
    }

    $probe = $client->cookieHomeProbe(8);
    $loggedIn = !empty($probe['logged_in']);
    $out['logged_in'] = $loggedIn;
    $videos = (int) ($probe['videos'] ?? 0);

    if (!$loggedIn) {
        $out['state'] = 'expired';
        $out['reason'] = 'Session morte — relancer scripts/sync_firefox_yt_cookies.py (sync auto → cookie_sync.php).';
        $out['alert'] = true;
        $out['ok'] = false;
        ytl_cookie_health_write($out);
        return $out + ['videos' => $videos];
    }

    $out['ok'] = true;
    $out['state'] = 'ok';
    $out['alert'] = false;
    $out['reason'] = $videos > 0
        ? ('Cookie OK — Accueil personnalisé ' . $videos . ' vidéos')
        : 'Cookie OK (connecté) — Accueil perso vide, Recommended utilisera l’Accueil live';
    ytl_cookie_health_write($out);
    return $out + ['videos' => $videos];
}

/**
 * Human steps for a long-lived cookie (yt-dlp wiki).
 *
 * @return list<string>
 */
function ytl_cookie_durable_export_steps(): array
{
    return [
        'Sur le Mac : connecte-toi à YouTube dans Firefox avec pixryoutubelite@gmail.com.',
        'Donne Full Disk Access à Terminal (Préférences Système → Confidentialité).',
        'Lance en arrière-plan : python3 scripts/sync_firefox_yt_cookies.py --loop 900',
        'Le script pousse le jar vers cookie_sync.php (aucune UI Account / collage manuel).',
        'Option admin : cookies.php reste dispo pour un upload d’urgence uniquement.',
    ];
}
