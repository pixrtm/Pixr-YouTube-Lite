<?php
/**
 * Guest convert helper: browser fetched a Cobalt/Piped MP4 (OVH IP is blocked),
 * then POSTs the bytes here so watch.php can play from local cache.
 *
 * POST body: raw video/mp4 bytes
 * Query: v=VIDEO_ID&q=360&key=SETUP_KEY  (key optional if same-origin session)
 * Headers: Content-Type: application/octet-stream (or video/mp4)
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/convert.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$cfg = ytl_config();
$setupKey = (string) ($cfg['setup_key'] ?? '');
$provided = isset($_GET['key']) ? (string) $_GET['key'] : '';
// Allow same-origin browser posts without setup key; require key for cross-tools.
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$sameOrigin = $origin === '' || str_contains($origin, $host) || str_ends_with($origin, 'pixr.dev');
if (!$sameOrigin) {
    if ($setupKey === '' || $provided === '' || !hash_equals($setupKey, $provided)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
}

$videoId = isset($_GET['v']) ? trim((string) $_GET['v']) : '';
if ($videoId === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_id']);
    exit;
}

$height = ytl_convert_normalize_quality(isset($_GET['q']) ? (int) $_GET['q'] : 360);
$fmt = ytl_convert_normalize_fmt(isset($_GET['fmt']) ? (string) $_GET['fmt'] : 'mp4');
$part = strtolower(trim((string) ($_GET['part'] ?? '')));
// part=v|a → store adaptive tracks for convert_client_merge.php; empty → final MP4.
if ($part !== '' && $part !== 'v' && $part !== 'a') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'part']);
    exit;
}
if ($part === '' && $fmt !== 'mp4') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'fmt']);
    exit;
}

@set_time_limit(0);
ignore_user_abort(true);

$dest = ytl_convert_media_path($videoId, $height, $fmt === '' ? 'mp4' : $fmt);
if ($part === 'v' || $part === 'a') {
    $base = ytl_convert_dir() . '/' . $videoId . ($height === 360 ? '' : ('_' . $height));
    $dest = $base . ($part === 'v' ? '.clientvid' : '.clientaud');
}
$tmp = $dest . '.clientpart';
@unlink($tmp);

$maxBytes = 220 * 1024 * 1024; // 220 MiB hard cap
$in = fopen('php://input', 'rb');
if ($in === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'no_body']);
    exit;
}
$out = fopen($tmp, 'wb');
if ($out === false) {
    fclose($in);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'write']);
    exit;
}

$written = 0;
while (!feof($in)) {
    $chunk = fread($in, 1024 * 256);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $written += strlen($chunk);
    if ($written > $maxBytes) {
        fclose($in);
        fclose($out);
        @unlink($tmp);
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'too_large']);
        exit;
    }
    fwrite($out, $chunk);
}
fclose($in);
fclose($out);

$minBytes = $part === 'a' ? 1000 : 12000;
if ($written < $minBytes) {
    @unlink($tmp);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'too_small', 'bytes' => $written]);
    exit;
}
// Final MP4 must look like MP4; adaptive parts may be fragmented (still ISO-BMFF).
if ($part === '' && !ytl_mp4_payload_ok($tmp)) {
    @unlink($tmp);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'not_mp4', 'bytes' => $written]);
    exit;
}

@unlink($dest);
if (!@rename($tmp, $dest)) {
    if (!@copy($tmp, $dest)) {
        @unlink($tmp);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'move']);
        exit;
    }
    @unlink($tmp);
}

$log = ytl_convert_dir() . '/' . $videoId . ($height === 360 ? '' : ('_' . $height)) . '.log';
if ($part === 'v' || $part === 'a') {
    @file_put_contents($log, date('c') . " client_part {$part} ok bytes=" . filesize($dest) . "\n", FILE_APPEND);
    echo json_encode([
        'ok' => true,
        'part' => $part,
        'bytes' => (int) filesize($dest),
        'ready' => false,
        'videoId' => $videoId,
        'quality' => $height,
    ]);
    exit;
}

ytl_convert_purge_other_media($videoId);
ytl_convert_write_status($videoId, 'ready', [
    'percent' => 100,
    'message' => 'Ready',
    'quality' => $height,
    'format' => $fmt,
    'bytes' => (int) filesize($dest),
    'source' => 'client_cobalt',
]);

@file_put_contents($log, date('c') . " client_cobalt put ok bytes=" . filesize($dest) . "\n", FILE_APPEND);

echo json_encode([
    'ok' => true,
    'bytes' => (int) filesize($dest),
    'ready' => true,
    'videoId' => $videoId,
    'quality' => $height,
]);
