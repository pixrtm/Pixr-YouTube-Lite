<?php
/**
 * Silent cookie sync endpoint (no UI). Background script POSTs Netscape jars.
 *
 * Shared Pixr jar (Recommended/You for pixryoutubelite Sign-in):
 *   curl -fsS -X POST -F "key=SETUP_KEY" -F "cookies=@cookies.txt" \
 *     https://pixr.dev/ytlite/cookie_sync.php
 *
 * Per-visitor jar (optional, when TV sub is known):
 *   curl -fsS -X POST -F "key=SETUP_KEY" -F "sub=USER_SUB" -F "cookies=@cookies.txt" \
 *     https://pixr.dev/ytlite/cookie_sync.php
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/visitor_cookies.php';
require_once __DIR__ . '/lib/cookie_auth.php';

$cfg = ytl_config();
$key = (string) ($cfg['setup_key'] ?? '');
$provided = isset($_POST['key']) ? (string) $_POST['key'] : (isset($_GET['key']) ? (string) $_GET['key'] : '');
header('Content-Type: application/json; charset=UTF-8');

if ($key === '' || $key === 'ytlite-change-me' || !hash_equals($key, $provided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'reason' => 'forbidden']);
    exit;
}

$raw = '';
if (isset($_FILES['cookies']) && is_uploaded_file($_FILES['cookies']['tmp_name'] ?? '')) {
    $raw = (string) file_get_contents((string) $_FILES['cookies']['tmp_name']);
} elseif (isset($_POST['cookies'])) {
    $raw = (string) $_POST['cookies'];
}
$raw = trim($raw);
if ($raw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'reason' => 'empty cookies']);
    exit;
}

$sub = trim((string) ($_POST['sub'] ?? $_GET['sub'] ?? ''));
if ($sub !== '') {
    $res = ytl_visitor_cookie_save($raw, $sub);
    if (!empty($res['ok'])) {
        ytl_visitor_cookie_bust_feed_caches($sub);
    }
    echo json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Default: refresh shared Pixr YouTube Lite jar (same path as cookies.php / convert guests).
$netscape = ytl_cookie_to_netscape($raw);
if ($netscape === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'reason' => 'invalid format']);
    exit;
}
$missing = ytl_visitor_cookie_missing_from($netscape);
if ($missing !== []) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'reason' => 'missing: ' . implode(', ', $missing)]);
    exit;
}

$path = (string) ($cfg['youtube_cookie_file'] ?? (__DIR__ . '/data/youtube.cookies.txt'));
$dir = dirname($path);
if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'reason' => 'mkdir failed']);
    exit;
}
if (@file_put_contents($path, $netscape, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'reason' => 'write failed']);
    exit;
}
@chmod($path, 0600);

// Verify jar is logged in as expected Pixr account.
require_once __DIR__ . '/lib/innertube.php';
$it = new InnertubeClient([
    'http_timeout' => (int) ($cfg['http_timeout'] ?? 20),
    'youtube_cookie' => '',
    'youtube_cookie_file' => $path,
]);
$label = $it->accountLabel();
$check = ytl_youtube_cookie_account_ok($label);
$probe = $it->cookieHomeProbe(6);
$ok = !empty($check['ok']) && !empty($probe['logged_in']);
$who = trim((string) ($label['name'] ?? '') . ' ' . (string) ($label['handle'] ?? '') . ' ' . (string) ($label['email'] ?? ''));

if (function_exists('ytl_cookie_health_write')) {
    ytl_cookie_health_write([
        'ok' => $ok,
        'ts' => time(),
        'source' => 'cookie_sync',
        'account' => $who,
        'reason' => $ok ? 'synced' : ((string) ($check['reason'] ?? $probe['reason'] ?? 'probe failed')),
    ]);
}

// Bust shared-ish caches that may pin empty Recommended.
$cacheDir = rtrim((string) ($cfg['video_cache_dir'] ?? (__DIR__ . '/data/videos')), '/\\') . '/.cache';
if (is_dir($cacheDir)) {
    foreach (glob($cacheDir . '/feed_rec_*.json') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($cacheDir . '/feed_lib_v3_*.json') ?: [] as $f) {
        @unlink($f);
    }
}

echo json_encode([
    'ok' => $ok,
    'account' => $who,
    'logged_in' => !empty($probe['logged_in']),
    'videos' => (int) ($probe['videos'] ?? 0),
    'reason' => $ok ? 'shared jar synced' : ((string) ($check['reason'] ?? $probe['reason'] ?? 'not ok')),
    'bytes' => (int) @filesize($path),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
