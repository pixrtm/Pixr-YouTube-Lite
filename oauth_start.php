<?php
/**
 * Visitor Sign-in → Tube Replacer-style YouTube OAuth (tv_signin.php).
 * Admin service feeds still use Cloud OAuth via ?service=1&key=…
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/oauth.php';

ytl_session_start();

$service = isset($_GET['service']) && (string) $_GET['service'] === '1';
if (!$service) {
    // Cloud Web OAuth cannot download (player HTTP 400). Convert needs TV Bearer.
    header('Location: ' . ytl_url('tv_signin.php'), true, 302);
    exit;
}

$cfg = ytl_config();
$key = (string) ($cfg['setup_key'] ?? '');
$provided = isset($_GET['key']) ? (string) $_GET['key'] : '';
if ($key === '' || $key === 'ytlite-change-me' || !hash_equals($key, $provided)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "forbidden\n";
    exit;
}
$opts = [
    'mode' => 'service',
    'setup_key' => $provided,
    'login_hint' => 'pixryoutubelite@gmail.com',
];

$url = ytl_oauth_authorize_url($opts);
if ($url === '') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Google OAuth is not configured.';
    exit;
}
header('Location: ' . $url, true, 302);
exit;
