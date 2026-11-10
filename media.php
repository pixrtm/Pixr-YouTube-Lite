<?php
/**
 * Sert un MP4/MP3 du cache local avec Range.
 * ?dl=1 force le téléchargement (Downloads).
 * ?fmt=mp3|mp4 (défaut mp4).
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/convert.php';

$videoId = isset($_GET['v']) ? trim((string) $_GET['v']) : '';
$height = ytl_convert_normalize_quality(isset($_GET['q']) ? (int) $_GET['q'] : 360);
$fmt = ytl_convert_normalize_fmt(isset($_GET['fmt']) ? (string) $_GET['fmt'] : 'mp4');
$download = isset($_GET['dl']) && (string) $_GET['dl'] !== '' && (string) $_GET['dl'] !== '0';

if ($videoId === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Bad request';
    exit;
}

$path = ytl_convert_media_path($videoId, $height, $fmt);
$min = $fmt === 'mp3' ? 4000 : 1000;
if (!is_file($path) || filesize($path) < $min) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not ready';
    exit;
}

$size = (int) filesize($path);
$start = 0;
$end = $size - 1;
$status = 200;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') {
        $start = (int) $m[1];
    }
    if ($m[2] !== '') {
        $end = (int) $m[2];
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    $end = min($end, $size - 1);
    $status = 206;
}

$length = $end - $start + 1;
if ($fmt === 'mp3') {
    $filename = $videoId . '.mp3';
    $mime = 'audio/mpeg';
} else {
    $filename = $videoId . '_' . ytl_convert_quality_label($height) . '.mp4';
    $mime = 'video/mp4';
}
$filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: ($videoId . '.' . $fmt);

http_response_code($status);
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
if ($status === 206) {
    header("Content-Range: bytes $start-$end/$size");
}
if ($download) {
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store');
} else {
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: public, max-age=86400');
}

$fp = fopen($path, 'rb');
if ($fp === false) {
    http_response_code(500);
    exit;
}
if ($start > 0) {
    fseek($fp, $start);
}
$left = $length;
while ($left > 0 && !feof($fp)) {
    $chunk = fread($fp, min(8192, $left));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $left -= strlen($chunk);
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}
fclose($fp);
