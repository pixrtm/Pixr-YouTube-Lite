<?php
/**
 * YouTube TV (TVHTML5) device-code OAuth — same class of auth as Tube Replacer /
 * YouTube.js TV sign-in. Yields Bearers the youtubei TV player accepts.
 *
 * Google Cloud Web OAuth (oauth_start.php) is a different client — player returns HTTP 400.
 * yt-dlp removed YouTube OAuth; this path is for Innertube TVHTML5 progressive/HLS fetch.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/oauth.php';

/** Public YouTube on TV OAuth client (shared by ytmusicapi / YouTube.js / Lavalink). */
function ytl_tv_oauth_default_client(): array
{
    return [
        'client_id' => '861556708454-d6dlm3lh05idd8npek18k6be8ba3oc68.apps.googleusercontent.com',
        'client_secret' => 'SboVhoG9s0rNafixCSGGKXAT',
    ];
}

function ytl_tv_oauth_dir(): string
{
    return ytl_oauth_token_dir();
}

function ytl_tv_oauth_path(string $id): string
{
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $id) ?: 'unknown';
    return ytl_tv_oauth_dir() . '/tv_' . $safe . '.json';
}

/**
 * @param array<string,mixed> $data
 */
function ytl_tv_oauth_store(string $id, array $data): bool
{
    $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload) || $payload === '') {
        return false;
    }
    $enc = ytl_oauth_encrypt($payload);
    if ($enc === '') {
        return false;
    }
    $ok = @file_put_contents(ytl_tv_oauth_path($id), $enc, LOCK_EX);
    if ($ok === false) {
        return false;
    }
    @chmod(ytl_tv_oauth_path($id), 0600);
    return true;
}

/** @return array<string,mixed>|null */
function ytl_tv_oauth_load(string $id): ?array
{
    $path = ytl_tv_oauth_path($id);
    if (!is_readable($path)) {
        return null;
    }
    $plain = ytl_oauth_decrypt((string) file_get_contents($path));
    if ($plain === '') {
        return null;
    }
    $data = json_decode($plain, true);
    return is_array($data) ? $data : null;
}

function ytl_tv_oauth_delete(string $id): void
{
    $path = ytl_tv_oauth_path($id);
    if (is_file($path)) {
        @unlink($path);
    }
}

/** @return array<string,mixed>|null */
function ytl_tv_oauth_http_json(string $url, array $payload): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => ytl_http_ssl_verify(),
        CURLOPT_SSL_VERIFYHOST => ytl_http_ssl_verify() ? 2 : 0,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (PlayStation; PlayStation 4/12.00) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Safari/605.1.15',
    ]);
    if (function_exists('ytl_curl_set_proxy')) {
        ytl_curl_set_proxy($ch);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (!is_string($body) || $body === '') {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return null;
    }
    $data['_http'] = $code;
    return $data;
}

/**
 * Start device-code flow. Stores pending state in PHP session.
 *
 * @return array{ok:bool,user_code?:string,verification_url?:string,expires_in?:int,error?:string}
 */
function ytl_tv_oauth_start(): array
{
    $client = ytl_tv_oauth_default_client();
    $resp = ytl_tv_oauth_http_json('https://www.youtube.com/o/oauth2/device/code', [
        'client_id' => $client['client_id'],
        'scope' => 'http://gdata.youtube.com https://www.googleapis.com/auth/youtube',
        'device_id' => bin2hex(random_bytes(16)),
        'device_model' => 'ytlr::',
    ]);
    if ($resp === null || empty($resp['device_code']) || empty($resp['user_code'])) {
        return ['ok' => false, 'error' => 'Could not start Google Sign-in.'];
    }
    ytl_session_start();
    $_SESSION['ytl_tv_pending'] = [
        'device_code' => (string) $resp['device_code'],
        'user_code' => (string) $resp['user_code'],
        'verification_url' => (string) ($resp['verification_url'] ?? 'https://www.google.com/device'),
        'interval' => max(3, (int) ($resp['interval'] ?? 5)),
        'expires_at' => time() + max(60, (int) ($resp['expires_in'] ?? 1800)),
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
        'started_at' => time(),
    ];
    return [
        'ok' => true,
        'user_code' => (string) $resp['user_code'],
        'verification_url' => (string) ($resp['verification_url'] ?? 'https://www.google.com/device'),
        'expires_in' => (int) ($resp['expires_in'] ?? 1800),
        'interval' => max(3, (int) ($resp['interval'] ?? 5)),
    ];
}

/**
 * Poll pending device code once.
 *
 * @return array{ok:bool,pending?:bool,error?:string,id?:string,name?:string}
 */
function ytl_tv_oauth_poll(): array
{
    ytl_session_start();
    $pending = $_SESSION['ytl_tv_pending'] ?? null;
    if (!is_array($pending) || empty($pending['device_code'])) {
        return ['ok' => false, 'error' => 'No pending Sign-in.'];
    }
    if (time() > (int) ($pending['expires_at'] ?? 0)) {
        unset($_SESSION['ytl_tv_pending']);
        return ['ok' => false, 'error' => 'Code expired — start Sign in again.'];
    }
    $resp = ytl_tv_oauth_http_json('https://www.youtube.com/o/oauth2/token', [
        'client_id' => (string) $pending['client_id'],
        'client_secret' => (string) $pending['client_secret'],
        'code' => (string) $pending['device_code'],
        'grant_type' => 'http://oauth.net/grant_type/device/1.0',
    ]);
    if ($resp === null) {
        return ['ok' => false, 'pending' => true, 'error' => 'network'];
    }
    $err = (string) ($resp['error'] ?? '');
    if ($err === 'authorization_pending' || $err === 'slow_down') {
        return ['ok' => false, 'pending' => true];
    }
    if ($err === 'access_denied') {
        unset($_SESSION['ytl_tv_pending']);
        return ['ok' => false, 'error' => 'Access denied on Google.'];
    }
    if ($err === 'expired_token' || $err !== '') {
        unset($_SESSION['ytl_tv_pending']);
        return ['ok' => false, 'error' => $err !== '' ? $err : 'Token poll failed.'];
    }
    $access = (string) ($resp['access_token'] ?? '');
    $refresh = (string) ($resp['refresh_token'] ?? '');
    if ($access === '') {
        return ['ok' => false, 'pending' => true];
    }
    $expiresIn = (int) ($resp['expires_in'] ?? 3600);
    $id = substr(hash('sha256', $refresh !== '' ? $refresh : $access), 0, 24);
    $profile = ytl_tv_oauth_profile($access);
    $store = [
        'id' => $id,
        'access_token' => $access,
        'refresh_token' => $refresh,
        'expires_at' => time() + max(60, $expiresIn - 60),
        'client_id' => (string) $pending['client_id'],
        'client_secret' => (string) $pending['client_secret'],
        'name' => (string) ($profile['name'] ?? 'YouTube account'),
        'handle' => (string) ($profile['handle'] ?? ''),
        'email' => (string) ($profile['email'] ?? ''),
        'picture' => (string) ($profile['picture'] ?? ''),
        'channelId' => (string) ($profile['channelId'] ?? ''),
        'updated_at' => time(),
        'kind' => 'youtube_tv',
    ];
    if (!ytl_tv_oauth_store($id, $store)) {
        return ['ok' => false, 'error' => 'Could not store Google tokens.'];
    }
    unset($_SESSION['ytl_tv_pending']);
    // Supersede any Cloud Web OAuth visitor session — Convert must use TV Bearer.
    unset($_SESSION['ytl_oauth_state'], $_SESSION['ytl_oauth_mode']);
    $_SESSION['ytl_tv_id'] = $id;
    // Drive the existing masthead / convert “signed in” session from TV identity.
    $_SESSION['ytl_user'] = [
        'sub' => 'tv_' . $id,
        'name' => $store['name'],
        'email' => $store['email'] !== '' ? $store['email'] : $store['handle'],
        'picture' => $store['picture'],
        'channelId' => $store['channelId'],
        'auth' => 'youtube_tv',
    ];
    return [
        'ok' => true,
        'id' => $id,
        'name' => $store['name'],
    ];
}

/**
 * Best-effort channel label via TVHTML5 account menu.
 *
 * @return array{name:string,handle:string,email:string,picture:string}
 */
function ytl_tv_oauth_profile(string $accessToken): array
{
    $out = ['name' => '', 'handle' => '', 'email' => '', 'picture' => '', 'channelId' => ''];
    if ($accessToken === '' || !function_exists('curl_init')) {
        return $out;
    }
    $body = json_encode([
        'context' => [
            'client' => [
                'clientName' => 'TVHTML5',
                'clientVersion' => '7.20241120.07.00',
                'hl' => 'en',
                'gl' => 'US',
            ],
        ],
    ], JSON_UNESCAPED_SLASHES);
    $ch = curl_init('https://www.youtube.com/youtubei/v1/account/accounts_list?prettyPrint=false');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
            'X-YouTube-Client-Name: 7',
            'X-YouTube-Client-Version: 7.20241120.07.00',
            'User-Agent: Mozilla/5.0 (PlayStation; PlayStation 4/12.00) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Safari/605.1.15',
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => ytl_http_ssl_verify(),
        CURLOPT_SSL_VERIFYHOST => ytl_http_ssl_verify() ? 2 : 0,
    ]);
    if (function_exists('ytl_curl_set_proxy')) {
        ytl_curl_set_proxy($ch);
    }
    $raw = curl_exec($ch);
    if (!is_string($raw) || $raw === '') {
        return $out;
    }
    if (preg_match('/"accountName"\s*:\s*"([^"]+)"/', $raw, $m)) {
        $out['name'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('/"channelHandle"\s*:\s*"([^"]+)"/', $raw, $m)) {
        $out['handle'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if ($out['name'] === '' && preg_match('/"title"\s*:\s*"([^"]+)"/', $raw, $m)) {
        $out['name'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    // Channel / account photo (yt3 / googleusercontent).
    if (preg_match('#https://(?:yt3\.ggpht\.com|yt3\.googleusercontent\.com|lh3\.googleusercontent\.com)/[^"\\\\]+#', $raw, $m)) {
        $pic = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pic = preg_replace('/\\\\u003d/', '=', $pic) ?? $pic;
        $pic = str_replace('\\/', '/', $pic);
        if (function_exists('ytl_normalize_avatar_url')) {
            $pic = ytl_normalize_avatar_url($pic) ?: $pic;
        }
        $out['picture'] = $pic;
    }
    if (preg_match('/"channelId"\s*:\s*"(UC[A-Za-z0-9_-]{20,})"/', $raw, $m)) {
        $out['channelId'] = $m[1];
    } elseif (preg_match('/"externalChannelId"\s*:\s*"(UC[A-Za-z0-9_-]{20,})"/', $raw, $m)) {
        $out['channelId'] = $m[1];
    } elseif (preg_match('/"browseId"\s*:\s*"(UC[A-Za-z0-9_-]{20,})"/', $raw, $m)) {
        $out['channelId'] = $m[1];
    }
    // Resolve @handle → UC… via Invidious when accounts_list omitted channelId.
    if ($out['channelId'] === '' && $out['handle'] !== '') {
        $handle = ltrim($out['handle'], '@');
        if ($handle !== '' && function_exists('ytl_http_get_json')) {
            foreach ([
                'https://yt.chocolatemoo53.com',
                'https://invidious.tiekoetter.com',
            ] as $host) {
                $chJson = ytl_http_get_json(
                    rtrim($host, '/') . '/api/v1/channels/' . rawurlencode($handle) . '?fields=authorId',
                    3
                );
                if (!is_array($chJson)) {
                    $chJson = ytl_http_get_json(rtrim($host, '/') . '/api/v1/channels/' . rawurlencode($handle), 3);
                }
                $cid = is_array($chJson) ? trim((string) ($chJson['authorId'] ?? '')) : '';
                if ($cid !== '' && str_starts_with($cid, 'UC')) {
                    $out['channelId'] = $cid;
                    break;
                }
            }
        }
    }
    return $out;
}

function ytl_tv_current_id(): ?string
{
    ytl_session_start();
    $id = (string) ($_SESSION['ytl_tv_id'] ?? '');
    if ($id === '') {
        // Recover from TV Sign-in session if ytl_tv_id was dropped but ytl_user remains.
        $sub = (string) ($_SESSION['ytl_user']['sub'] ?? '');
        $auth = (string) ($_SESSION['ytl_user']['auth'] ?? '');
        if ($auth === 'youtube_tv' && str_starts_with($sub, 'tv_')) {
            $id = substr($sub, 3);
            if ($id !== '' && is_readable(ytl_tv_oauth_path($id))) {
                $_SESSION['ytl_tv_id'] = $id;
                return $id;
            }
        }
        return null;
    }
    // Drop stale id pointing at a missing token file (avoid decrypt on every page).
    if (!is_readable(ytl_tv_oauth_path($id))) {
        unset($_SESSION['ytl_tv_id']);
        return null;
    }
    return $id;
}

/** True when this browser session is YouTube TV device-code Sign-in (Convert-ready). */
function ytl_tv_session_ready(): bool
{
    return ytl_tv_current_id() !== null && ytl_tv_access_token() !== null;
}

function ytl_tv_access_token(): ?string
{
    $id = ytl_tv_current_id();
    if ($id === null) {
        return null;
    }
    $tokens = ytl_tv_oauth_load($id);
    if ($tokens === null) {
        return null;
    }
    $access = (string) ($tokens['access_token'] ?? '');
    $exp = (int) ($tokens['expires_at'] ?? 0);
    if ($access !== '' && $exp > time() + 30) {
        return $access;
    }
    return ytl_tv_oauth_refresh($id, $tokens);
}

/**
 * @param array<string,mixed> $tokens
 */
function ytl_tv_oauth_refresh(string $id, array $tokens): ?string
{
    $refresh = (string) ($tokens['refresh_token'] ?? '');
    $clientId = (string) ($tokens['client_id'] ?? '');
    $clientSecret = (string) ($tokens['client_secret'] ?? '');
    if ($refresh === '' || $clientId === '' || $clientSecret === '') {
        $def = ytl_tv_oauth_default_client();
        $clientId = $clientId !== '' ? $clientId : $def['client_id'];
        $clientSecret = $clientSecret !== '' ? $clientSecret : $def['client_secret'];
    }
    if ($refresh === '') {
        return null;
    }
    $resp = ytl_tv_oauth_http_json('https://www.youtube.com/o/oauth2/token', [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refresh,
        'grant_type' => 'refresh_token',
    ]);
    if ($resp === null || empty($resp['access_token'])) {
        return null;
    }
    $tokens['access_token'] = (string) $resp['access_token'];
    $tokens['expires_at'] = time() + max(60, (int) ($resp['expires_in'] ?? 3600) - 60);
    $tokens['updated_at'] = time();
    if (!empty($resp['refresh_token'])) {
        $tokens['refresh_token'] = (string) $resp['refresh_token'];
    }
    ytl_tv_oauth_store($id, $tokens);
    return (string) $tokens['access_token'];
}

/** @return array{linked:bool,name:string,handle:string,expires_at:int} */
function ytl_tv_oauth_status(): array
{
    $id = ytl_tv_current_id();
    if ($id === null) {
        return ['linked' => false, 'name' => '', 'handle' => '', 'expires_at' => 0];
    }
    $t = ytl_tv_oauth_load($id);
    if ($t === null) {
        return ['linked' => false, 'name' => '', 'handle' => '', 'expires_at' => 0];
    }
    return [
        'linked' => true,
        'name' => (string) ($t['name'] ?? ''),
        'handle' => (string) ($t['handle'] ?? ''),
        'expires_at' => (int) ($t['expires_at'] ?? 0),
    ];
}

function ytl_tv_oauth_logout(): void
{
    ytl_session_start();
    $id = (string) ($_SESSION['ytl_tv_id'] ?? '');
    unset($_SESSION['ytl_tv_id'], $_SESSION['ytl_tv_pending']);
    // Keep tokens on disk for smoother re-link; unlink explicitly from account page.
    if ($id !== '' && isset($_GET['forget']) && (string) $_GET['forget'] === '1') {
        ytl_tv_oauth_delete($id);
    }
}
