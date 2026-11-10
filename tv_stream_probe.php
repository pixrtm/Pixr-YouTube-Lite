<?php
/**
 * Admin probe: prove stored YouTube Sign-in tokens return player streams.
 * GET ?key=SETUP_KEY&v=VIDEO_ID
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/oauth.php';
require_once __DIR__ . '/lib/youtube_tv_oauth.php';
require_once __DIR__ . '/lib/innertube.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$cfg = ytl_config();
$key = (string) ($cfg['setup_key'] ?? '');
$provided = isset($_GET['key']) ? (string) $_GET['key'] : '';
if ($key === '' || $key === 'ytlite-change-me' || !hash_equals($key, $provided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$videoId = isset($_GET['v']) ? trim((string) $_GET['v']) : 'jNQXAC9IVRw';
if (!preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
    $videoId = 'jNQXAC9IVRw';
}

$dir = ytl_oauth_token_dir();
$tokens = [];
foreach (glob($dir . '/tv_*.json') ?: [] as $path) {
    $base = basename($path, '.json');
    $id = str_starts_with($base, 'tv_') ? substr($base, 3) : $base;
    $t = ytl_tv_oauth_load($id);
    if (!is_array($t)) {
        continue;
    }
    $access = (string) ($t['access_token'] ?? '');
    $exp = (int) ($t['expires_at'] ?? 0);
    if ($access === '' || $exp <= time() + 30) {
        $access = (string) (ytl_tv_oauth_refresh($id, $t) ?? '');
    }
    if ($access !== '') {
        $tokens[] = [
            'id' => $id,
            'access' => $access,
            'name' => (string) ($t['name'] ?? ''),
        ];
    }
}

$out = [
    'ok' => false,
    'videoId' => $videoId,
    'tv_files' => count(glob($dir . '/tv_*.json') ?: []),
    'usable_tokens' => count($tokens),
    'results' => [],
];

$client = new InnertubeClient(ytl_http_config());
foreach ($tokens as $row) {
    $video = $client->videoWithTvOauth($videoId, $row['access']);
    $formats = is_array($video) ? count($video['formatStreams'] ?? []) : 0;
    $adaptive = is_array($video) ? count($video['adaptiveFormats'] ?? []) : 0;
    $hls = is_array($video) && !empty($video['hlsUrl']);
    $progressive = '';
    if (is_array($video)) {
        require_once __DIR__ . '/lib/convert.php';
        $progressive = ytl_convert_pick_progressive_url($video, 360);
        if ($progressive === '') {
            foreach ($video['formatStreams'] ?? [] as $f) {
                if (!empty($f['url'])) {
                    $progressive = (string) $f['url'];
                    break;
                }
            }
        }
    }
    $item = [
        'id' => $row['id'],
        'name' => $row['name'],
        'formats' => $formats,
        'adaptive' => $adaptive,
        'hls' => $hls ? 1 : 0,
        'progressive' => $progressive !== '',
        'src' => is_array($video) ? (string) ($video['_source'] ?? '') : 'null',
        'err' => ytl_http_last_error(),
    ];
    $out['results'][] = $item;
    if ($formats > 0 || $adaptive > 0 || $hls || $progressive !== '') {
        $out['ok'] = true;
    }
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
