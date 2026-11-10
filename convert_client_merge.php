<?php
/**
 * Merge browser-fetched Invidious local video+audio parts into a cached MP4.
 * Parts are written by convert_client_put.php?part=v|a
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

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$sameOrigin = $origin === '' || str_contains($origin, $host) || str_ends_with($origin, 'pixr.dev');
if (!$sameOrigin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$videoId = isset($_GET['v']) ? trim((string) $_GET['v']) : '';
if ($videoId === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_id']);
    exit;
}

$height = ytl_convert_normalize_quality(isset($_GET['q']) ? (int) $_GET['q'] : 360);
@set_time_limit(0);
ignore_user_abort(true);

$dir = ytl_convert_dir();
$base = $dir . '/' . $videoId . ($height === 360 ? '' : ('_' . $height));
$vidPart = $base . '.clientvid';
$audPart = $base . '.clientaud';
$dest = ytl_convert_media_path($videoId, $height, 'mp4');
$tmp = $dest . '.merge.mp4';

if (!is_file($vidPart) || filesize($vidPart) < 12000 || !is_file($audPart) || filesize($audPart) < 1000) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'missing_parts',
        'video_bytes' => is_file($vidPart) ? (int) filesize($vidPart) : 0,
        'audio_bytes' => is_file($audPart) ? (int) filesize($audPart) : 0,
    ]);
    exit;
}

$ffmpeg = __DIR__ . '/bin/ffmpeg';
if ((!is_file($ffmpeg) || !is_executable($ffmpeg)) && is_file(__DIR__ . '/bin/ffmpeg.gz') && function_exists('exec')) {
    @exec('gzip -dc ' . escapeshellarg(__DIR__ . '/bin/ffmpeg.gz') . ' > ' . escapeshellarg($ffmpeg) . ' 2>/dev/null');
    @chmod($ffmpeg, 0755);
}
if (!is_file($ffmpeg) || !is_executable($ffmpeg)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'no_ffmpeg']);
    exit;
}

@unlink($tmp);
$cmd = escapeshellarg($ffmpeg)
    . ' -y -loglevel error'
    . ' -i ' . escapeshellarg($vidPart)
    . ' -i ' . escapeshellarg($audPart)
    . ' -c copy -map 0:v:0 -map 1:a:0 -shortest -movflags +faststart '
    . escapeshellarg($tmp)
    . ' 2>&1';
$out = [];
$code = 1;
@exec($cmd, $out, $code);

$log = $base . '.log';
@file_put_contents(
    $log,
    date('c') . ' client_invidious merge exit=' . $code . ' ' . substr(implode(' ', $out), 0, 240) . "\n",
    FILE_APPEND
);

if ($code !== 0 || !is_file($tmp) || filesize($tmp) < 20000 || !ytl_mp4_payload_ok($tmp)) {
    @unlink($tmp);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'merge_fail', 'code' => $code]);
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

@unlink($vidPart);
@unlink($audPart);
ytl_convert_purge_other_media($videoId);
ytl_convert_write_status($videoId, 'ready', [
    'percent' => 100,
    'message' => 'Ready',
    'quality' => $height,
    'format' => 'mp4',
    'bytes' => (int) filesize($dest),
    'source' => 'client_invidious',
]);

@file_put_contents($log, date('c') . ' client_invidious merge ok bytes=' . filesize($dest) . "\n", FILE_APPEND);

echo json_encode([
    'ok' => true,
    'bytes' => (int) filesize($dest),
    'ready' => true,
    'videoId' => $videoId,
    'quality' => $height,
]);
