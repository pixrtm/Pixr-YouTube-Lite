<?php
/**
 * Backend préparation : ?status=1 (JSON), ?run=1 (téléchargement), ?noscript=1.
 * Qualité via ?q=240|360|480|720|1080|2160 (défaut 360).
 * Format via ?fmt=mp4|mp3 (défaut mp4).
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/convert.php';
require_once __DIR__ . '/lib/oauth.php';
require_once __DIR__ . '/lib/youtube_tv_oauth.php';

$videoId = isset($_GET['v']) ? trim((string) $_GET['v']) : '';
$ui = (isset($_GET['ui']) && $_GET['ui'] === 'lite') ? 'lite' : '';
$height = ytl_convert_normalize_quality(isset($_GET['q']) ? (int) $_GET['q'] : 360);
$fmt = ytl_convert_normalize_fmt(isset($_GET['fmt']) ? (string) $_GET['fmt'] : 'mp4');

if ($videoId === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Bad video id';
    exit;
}

$watch = ytl_url($ui === 'lite' ? 'lite/watch.php' : 'watch.php', ['v' => $videoId, 'q' => $height]);
$prepare = ytl_url($ui === 'lite' ? 'lite/watch.php' : 'watch.php', ['v' => $videoId, 'q' => $height]);
$label = $fmt === 'mp3' ? 'MP3' : ytl_convert_quality_label($height);

// Warm session so signed-in convert can use the visitor OAuth token.
ytl_session_start();

if (isset($_GET['status'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(ytl_convert_progress_payload($videoId, $height, $fmt));
    exit;
}

if (isset($_GET['run'])) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    @set_time_limit(0);
    ignore_user_abort(true);

    // Do NOT purge other videos here — a failed convert would wipe the only
    // working cache. LRU purge runs only after a successful ready/put.

    if (ytl_convert_is_ready($videoId, $height, $fmt)) {
        echo "ready\n";
        exit;
    }
    if (!ytl_convert_available()) {
        ytl_convert_write_status($videoId, 'error', [
            'error' => 'unavailable',
            'message' => ytl_convert_available_detail(),
            'percent' => 0,
            'quality' => $height,
            'format' => $fmt,
        ]);
        echo "unavailable\n";
        exit;
    }

    ytl_convert_write_status($videoId, 'downloading', [
        'percent' => 0,
        'message' => 'Starting…',
        'quality' => $height,
        'format' => $fmt,
    ]);
    ytl_convert_run_blocking($videoId, $height, null, $fmt);
    echo ytl_convert_is_ready($videoId, $height, $fmt) ? "ready\n" : "error\n";
    exit;
}

if (isset($_GET['noscript'])) {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Accel-Buffering: no');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Preparing…</title></head><body>';
    echo '<p><strong>Preparing (' . ytl_h($label) . ')…</strong></p>';
    echo '<p>Downloading…</p>';
    flush();
    if (!ytl_convert_is_ready($videoId, $height, $fmt)) {
        if (!ytl_convert_available()) {
            echo '<p>Converter unavailable.</p></body></html>';
            exit;
        }
        ytl_convert_run_blocking($videoId, $height, null, $fmt);
    }
    if (ytl_convert_is_ready($videoId, $height, $fmt)) {
        echo '<p>Ready.</p><meta http-equiv="refresh" content="0;url=' . ytl_h($watch) . '">';
        echo '<p><a href="' . ytl_h($watch) . '">Open</a></p>';
    } else {
        echo '<p>Failed. <a href="' . ytl_h($prepare) . '">Retry</a></p>';
    }
    echo '</body></html>';
    exit;
}

header('Location: ' . $watch);
exit;
