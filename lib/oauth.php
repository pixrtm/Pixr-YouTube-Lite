<?php

/**
 * Google OAuth helpers for YouTube Lite (classic UI).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/http.php';

function ytl_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_name('ytlite_sess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/ytlite',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** @return array{client_id:string,client_secret:string,redirect_uri:string}|null */
function ytl_oauth_config(): ?array
{
    $cfg = ytl_config();
    $o = $cfg['google_oauth'] ?? null;
    if (!is_array($o)) {
        return null;
    }
    $id = trim((string) ($o['client_id'] ?? ''));
    $secret = trim((string) ($o['client_secret'] ?? ''));
    $redirect = trim((string) ($o['redirect_uri'] ?? ''));
    if ($id === '' || $secret === '' || $redirect === '') {
        return null;
    }
    return [
        'client_id' => $id,
        'client_secret' => $secret,
        'redirect_uri' => $redirect,
    ];
}

function ytl_oauth_scopes(): string
{
    return implode(' ', [
        'openid',
        'email',
        'profile',
        'https://www.googleapis.com/auth/youtube.force-ssl',
    ]);
}

function ytl_oauth_token_dir(): string
{
    $dir = dirname(__DIR__) . '/data/oauth';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

function ytl_oauth_crypto_key(): string
{
    $cfg = ytl_config();
    $o = ytl_oauth_config();
    $material = (string) ($cfg['setup_key'] ?? 'ytlite') . '|' . (string) ($o['client_secret'] ?? '');
    return hash('sha256', $material, true);
}

function ytl_oauth_encrypt(string $plain): string
{
    $key = ytl_oauth_crypto_key();
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        return '';
    }
    return base64_encode($iv . $cipher);
}

function ytl_oauth_decrypt(string $blob): string
{
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 17) {
        return '';
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', ytl_oauth_crypto_key(), OPENSSL_RAW_DATA, $iv);
    return is_string($plain) ? $plain : '';
}

function ytl_oauth_token_path(string $sub): string
{
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $sub) ?: 'unknown';
    return ytl_oauth_token_dir() . '/' . $safe . '.json';
}

/**
 * @param array<string,mixed> $data
 */
function ytl_oauth_store_tokens(string $sub, array $data): bool
{
    $path = ytl_oauth_token_path($sub);
    $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload) || $payload === '') {
        return false;
    }
    $enc = ytl_oauth_encrypt($payload);
    if ($enc === '') {
        return false;
    }
    $ok = @file_put_contents($path, $enc, LOCK_EX);
    if ($ok === false) {
        return false;
    }
    @chmod($path, 0600);
    return true;
}

/** @return array<string,mixed>|null */
function ytl_oauth_load_tokens(string $sub): ?array
{
    $path = ytl_oauth_token_path($sub);
    if (!is_readable($path)) {
        return null;
    }
    $raw = (string) file_get_contents($path);
    $plain = ytl_oauth_decrypt($raw);
    if ($plain === '') {
        return null;
    }
    $data = json_decode($plain, true);
    return is_array($data) ? $data : null;
}

function ytl_oauth_delete_tokens(string $sub): void
{
    $path = ytl_oauth_token_path($sub);
    if (is_file($path)) {
        @unlink($path);
    }
}

function ytl_oauth_csrf_token(): string
{
    ytl_session_start();
    if (empty($_SESSION['ytl_csrf']) || !is_string($_SESSION['ytl_csrf'])) {
        $_SESSION['ytl_csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['ytl_csrf'];
}

function ytl_oauth_csrf_check(?string $token): bool
{
    ytl_session_start();
    $expect = (string) ($_SESSION['ytl_csrf'] ?? '');
    return $expect !== '' && is_string($token) && hash_equals($expect, $token);
}

/** Fixed token file id for the invisible server Google account (Pixr YouTube Lite). */
function ytl_oauth_service_id(): string
{
    return 'service_pixr_youtube_lite';
}

/**
 * @param array{mode?:string,setup_key?:string,login_hint?:string} $opts
 */
function ytl_oauth_authorize_url(array $opts = []): string
{
    $o = ytl_oauth_config();
    if ($o === null) {
        return '';
    }
    ytl_session_start();
    $state = bin2hex(random_bytes(16));
    $_SESSION['ytl_oauth_state'] = $state;
    $mode = (($opts['mode'] ?? '') === 'service') ? 'service' : 'visitor';
    $_SESSION['ytl_oauth_mode'] = $mode;
    if ($mode === 'service') {
        $_SESSION['ytl_oauth_setup_key'] = (string) ($opts['setup_key'] ?? '');
    } else {
        unset($_SESSION['ytl_oauth_setup_key']);
    }
    $params = [
        'client_id' => $o['client_id'],
        'redirect_uri' => $o['redirect_uri'],
        'response_type' => 'code',
        'scope' => ytl_oauth_scopes(),
        'access_type' => 'offline',
        'include_granted_scopes' => 'true',
        // Always force consent so we receive a refresh_token for long-lived server auth.
        'prompt' => 'consent',
        'state' => $state,
    ];
    $hint = trim((string) ($opts['login_hint'] ?? ''));
    if ($hint !== '') {
        $params['login_hint'] = $hint;
    }
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * @return array{ok:bool,error?:string,mode?:string,email?:string}
 */
function ytl_oauth_handle_callback(string $code, string $state): array
{
    $o = ytl_oauth_config();
    if ($o === null) {
        return ['ok' => false, 'error' => 'OAuth not configured'];
    }
    ytl_session_start();
    $expect = (string) ($_SESSION['ytl_oauth_state'] ?? '');
    $mode = (string) ($_SESSION['ytl_oauth_mode'] ?? 'visitor');
    unset($_SESSION['ytl_oauth_state'], $_SESSION['ytl_oauth_mode']);
    if ($expect === '' || !hash_equals($expect, $state)) {
        return ['ok' => false, 'error' => 'Invalid OAuth state'];
    }
    $tokenResp = ytl_oauth_http_post('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => $o['client_id'],
        'client_secret' => $o['client_secret'],
        'redirect_uri' => $o['redirect_uri'],
        'grant_type' => 'authorization_code',
    ]);
    if ($tokenResp === null || empty($tokenResp['access_token'])) {
        return ['ok' => false, 'error' => 'Token exchange failed'];
    }
    $access = (string) $tokenResp['access_token'];
    $refresh = (string) ($tokenResp['refresh_token'] ?? '');
    $expiresIn = (int) ($tokenResp['expires_in'] ?? 3600);
    $profile = ytl_oauth_fetch_userinfo($access);
    $sub = (string) ($profile['sub'] ?? '');
    $email = strtolower(trim((string) ($profile['email'] ?? '')));
    if ($sub === '') {
        return ['ok' => false, 'error' => 'Missing Google user id'];
    }

    if ($mode === 'service') {
        // Server account must be Pixr YouTube Lite — never a random visitor.
        if ($email === '' || !str_contains($email, 'pixryoutubelite')) {
            return [
                'ok' => false,
                'error' => 'Service Google must be pixryoutubelite@gmail.com (got: '
                    . ($email !== '' ? $email : 'unknown') . ')',
                'mode' => 'service',
            ];
        }
        $existing = ytl_oauth_load_tokens(ytl_oauth_service_id());
        if ($refresh === '' && is_array($existing)) {
            $refresh = (string) ($existing['refresh_token'] ?? '');
        }
        if ($refresh === '') {
            return [
                'ok' => false,
                'error' => 'No refresh_token — revoke Pixr YouTube Lite in Google account permissions, then reconnect.',
                'mode' => 'service',
            ];
        }
        $store = [
            'sub' => $sub,
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_at' => time() + max(60, $expiresIn - 60),
            'name' => (string) ($profile['name'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'picture' => (string) ($profile['picture'] ?? ''),
            'updated_at' => time(),
            'role' => 'service',
        ];
        if (!ytl_oauth_store_tokens(ytl_oauth_service_id(), $store)) {
            return ['ok' => false, 'error' => 'Could not store service tokens', 'mode' => 'service'];
        }
        // Do NOT set visitor session — Sign in button stays for users.
        return [
            'ok' => true,
            'mode' => 'service',
            'email' => (string) ($store['email'] ?? ''),
        ];
    }

    // Visitor Cloud Web OAuth is identity-only and cannot download (player HTTP 400).
    // Do not create a Convert session here — Sign in must go through tv_signin.php.
    unset($_SESSION['ytl_user']);
    return ['ok' => true, 'mode' => 'visitor', 'email' => $email];
}

/**
 * Long-lived server Google access token (auto-refreshed). Null if not linked.
 */
function ytl_service_access_token(): ?string
{
    $tokens = ytl_oauth_load_tokens(ytl_oauth_service_id());
    if ($tokens === null) {
        return null;
    }
    $access = (string) ($tokens['access_token'] ?? '');
    $exp = (int) ($tokens['expires_at'] ?? 0);
    if ($access !== '' && $exp > time() + 30) {
        return $access;
    }
    return ytl_oauth_refresh(ytl_oauth_service_id(), $tokens);
}

/**
 * @return array{linked:bool,email:string,expires_at:int,has_refresh:bool}
 */
function ytl_service_oauth_status(): array
{
    $tokens = ytl_oauth_load_tokens(ytl_oauth_service_id());
    if ($tokens === null) {
        return ['linked' => false, 'email' => '', 'expires_at' => 0, 'has_refresh' => false];
    }
    return [
        'linked' => true,
        'email' => (string) ($tokens['email'] ?? ''),
        'expires_at' => (int) ($tokens['expires_at'] ?? 0),
        'has_refresh' => trim((string) ($tokens['refresh_token'] ?? '')) !== '',
    ];
}

function ytl_service_oauth_unlink(): void
{
    ytl_oauth_delete_tokens(ytl_oauth_service_id());
}

/** @return array<string,mixed>|null */
function ytl_oauth_fetch_userinfo(string $accessToken): ?array
{
    return ytl_oauth_http_get('https://openidconnect.googleapis.com/v1/userinfo', $accessToken);
}

/** @return array{sub:string,name:string,email:string,picture:string}|null */
function ytl_current_user(): ?array
{
    ytl_session_start();
    $u = $_SESSION['ytl_user'] ?? null;
    if (!is_array($u) || empty($u['sub'])) {
        return null;
    }
    return [
        'sub' => (string) $u['sub'],
        'name' => (string) ($u['name'] ?? ''),
        'email' => (string) ($u['email'] ?? ''),
        'picture' => (string) ($u['picture'] ?? ''),
    ];
}

function ytl_user_access_token(): ?string
{
    $user = ytl_current_user();
    if ($user === null) {
        return null;
    }
    ytl_session_start();
    // YouTube TV device-code session — tokens live in tv_*.json; use ytl_tv_access_token().
    if (((string) ($_SESSION['ytl_user']['auth'] ?? '')) === 'youtube_tv'
        || str_starts_with($user['sub'], 'tv_')) {
        return null;
    }
    $tokens = ytl_oauth_load_tokens($user['sub']);
    if ($tokens === null) {
        return null;
    }
    // Never treat a YouTube-TV token file as Cloud Web OAuth.
    if (((string) ($tokens['kind'] ?? '')) === 'youtube_tv') {
        return null;
    }
    $access = (string) ($tokens['access_token'] ?? '');
    $exp = (int) ($tokens['expires_at'] ?? 0);
    if ($access !== '' && $exp > time() + 30) {
        return $access;
    }
    $refreshed = ytl_oauth_refresh($user['sub'], $tokens);
    return $refreshed;
}

/**
 * @param array<string,mixed> $tokens
 */
function ytl_oauth_refresh(string $sub, array $tokens): ?string
{
    $o = ytl_oauth_config();
    $refresh = (string) ($tokens['refresh_token'] ?? '');
    if ($o === null || $refresh === '') {
        return null;
    }
    $resp = ytl_oauth_http_post('https://oauth2.googleapis.com/token', [
        'client_id' => $o['client_id'],
        'client_secret' => $o['client_secret'],
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
    ytl_oauth_store_tokens($sub, $tokens);
    return (string) $tokens['access_token'];
}

function ytl_oauth_logout(): void
{
    ytl_session_start();
    $user = ytl_current_user();
    unset($_SESSION['ytl_user'], $_SESSION['ytl_oauth_state']);
    // Keep refresh tokens on disk so re-login is smoother; user can revoke in Google.
    if ($user !== null) {
        // no-op store keep
    }
}

/**
 * @param array<string,string> $fields
 * @return array<string,mixed>|null
 */
function ytl_oauth_http_post(string $url, array $fields): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => ytl_http_ssl_verify(),
        CURLOPT_SSL_VERIFYHOST => ytl_http_ssl_verify() ? 2 : 0,
    ]);
    ytl_curl_set_proxy($ch);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (!is_string($body) || $code < 200 || $code >= 300) {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * @return array<string,mixed>|null
 */
function ytl_oauth_http_get(string $url, string $accessToken): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => ytl_http_ssl_verify(),
        CURLOPT_SSL_VERIFYHOST => ytl_http_ssl_verify() ? 2 : 0,
    ]);
    ytl_curl_set_proxy($ch);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (!is_string($body) || $code < 200 || $code >= 300) {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}
