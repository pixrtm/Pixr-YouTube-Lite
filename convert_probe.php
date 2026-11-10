<?php
/**
 * Admin convert probe — runs on OVH (avoids OpenResty blocking external curl).
 * GET ?key=SETUP_KEY&v=VIDEO_ID[&q=360][&fmt=mp4]
 * Optional: &ids=id1,id2,id3 for a batch (max 5).
 * Optional: &diag=1 — probe each backend quickly (no full convert) + return error codes.
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/convert.php';
require_once __DIR__ . '/lib/oauth.php';
require_once __DIR__ . '/lib/ytdlp_session.php';

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

@set_time_limit(0);
@ini_set('max_execution_time', '0');
ignore_user_abort(true);

$height = ytl_convert_normalize_quality(isset($_GET['q']) ? (int) $_GET['q'] : 360);
$fmt = ytl_convert_normalize_fmt(isset($_GET['fmt']) ? (string) $_GET['fmt'] : 'mp4');
$diagOnly = isset($_GET['diag']) && (string) $_GET['diag'] === '1';

$markerPath = __DIR__ . '/data/deploy_marker.txt';
$deployMarker = is_readable($markerPath) ? trim((string) @file_get_contents($markerPath)) : '';

$ids = [];
if (isset($_GET['ids']) && is_string($_GET['ids'])) {
    foreach (explode(',', (string) $_GET['ids']) as $part) {
        $part = trim($part);
        if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $part)) {
            $ids[] = $part;
        }
    }
}
if (isset($_GET['v']) && is_string($_GET['v'])) {
    $v = trim((string) $_GET['v']);
    if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $v)) {
        array_unshift($ids, $v);
    }
}
$ids = array_values(array_unique($ids));
if ($ids === []) {
    $ids = ['jNQXAC9IVRw'];
}
$ids = array_slice($ids, 0, $diagOnly ? 1 : 5);
$videoId = $ids[0];

$ytdlp = ytl_ytdlp_session_status();
$service = ytl_service_oauth_status();

/**
 * @return list<array{step:string,result:string,err:string}>
 */
function ytl_probe_parse_steps(string $logTail): array
{
    $steps = [];
    foreach (preg_split("/\r\n|\n|\r/", $logTail) ?: [] as $line) {
        $line = trim((string) $line);
        if (!str_starts_with($line, 'step=')) {
            continue;
        }
        $step = '';
        $result = '';
        $err = '';
        if (preg_match('/step=([^\s]+)/', $line, $m)) {
            $step = $m[1];
        }
        if (preg_match('/result=([^\s]+)/', $line, $m)) {
            $result = $m[1];
        }
        if (preg_match('/err=(.+)$/', $line, $m)) {
            $err = trim($m[1]);
        }
        $steps[] = [
            'step' => $step,
            'result' => $result,
            'err' => $err,
            'code' => ytl_convert_classify_error($err !== '' ? $err : ($step . ' ' . $result)),
        ];
    }
    return $steps;
}

if ($diagOnly) {
    $backends = [];
    $logFile = ytl_convert_dir() . '/_diag_' . $videoId . '.log';
    @file_put_contents($logFile, 'diag start ' . $videoId . ' ' . gmdate('c') . "\n");

    // Cobalt sweep (no download save required for code — first host response codes).
    $cobaltCodes = [];
    if (function_exists('ytl_cobalt_apis') && function_exists('ytl_cobalt_lookup')) {
        $n = 0;
        foreach (ytl_cobalt_apis() as $api) {
            if ($n >= 6) {
                break;
            }
            $n++;
            $res = ytl_cobalt_lookup($api, $videoId, $height);
            $detail = ($res['host'] !== '' ? $res['host'] : $api)
                . ' status=' . $res['status'] . ' http=' . $res['http']
                . ($res['error'] !== '' ? (' err=' . $res['error']) : '');
            $code = ytl_convert_classify_error(
                ($res['error'] !== '' ? $res['error'] : '') . ' cobalt http=' . $res['http']
            );
            if ($res['url'] !== '') {
                $code = 'ok_url';
            }
            $cobaltCodes[] = [
                'host' => $res['host'] !== '' ? $res['host'] : $api,
                'http' => (int) $res['http'],
                'error' => (string) $res['error'],
                'code' => $code,
                'has_url' => $res['url'] !== '',
            ];
            @file_put_contents($logFile, 'diag cobalt ' . $detail . ' code=' . $code . "\n", FILE_APPEND);
        }
    }
    $backends['cobalt'] = $cobaltCodes;

    // Piped mux count.
    $pipedCode = 'piped_mux_empty';
    $pipedHosts = 0;
    try {
        require_once __DIR__ . '/lib/piped.php';
        $piped = new PipedClient(ytl_http_config());
        $muxed = $piped->muxedFromAllInstances($videoId);
        $pipedHosts = count($muxed);
        $pipedCode = $pipedHosts > 0 ? 'ok_mux' : 'piped_mux_empty';
        @file_put_contents($logFile, 'diag piped muxed=' . $pipedHosts . "\n", FILE_APPEND);
    } catch (Throwable $e) {
        $pipedCode = 'timeout';
        @file_put_contents($logFile, 'diag piped err=' . $e->getMessage() . "\n", FILE_APPEND);
    }
    $backends['piped'] = ['hosts' => $pipedHosts, 'code' => $pipedCode];

    // Innertube anon player (no bearer) — formats count.
    $innertube = ['formats' => 0, 'code' => 'download_failed'];
    try {
        require_once __DIR__ . '/lib/innertube.php';
        $cfgHttp = ytl_http_config();
        $cfgHttp['youtube_cookie'] = '';
        $cfgHttp['youtube_cookie_file'] = '';
        $client = new InnertubeClient($cfgHttp);
        $video = $client->video($videoId);
        $formats = is_array($video) ? count($video['formatStreams'] ?? []) : 0;
        $err = function_exists('ytl_http_last_error') ? (string) ytl_http_last_error() : '';
        $innertube = [
            'formats' => $formats,
            'err' => $err,
            'code' => $formats > 0 ? 'ok_formats' : ytl_convert_classify_error($err !== '' ? $err : 'oauth_http_400'),
        ];
        @file_put_contents($logFile, 'diag innertube formats=' . $formats . ' err=' . $err . "\n", FILE_APPEND);
    } catch (Throwable $e) {
        $innertube = ['formats' => 0, 'err' => $e->getMessage(), 'code' => 'timeout'];
    }
    $backends['innertube_anon'] = $innertube;

    // Cookie jar health.
    $jarPath = function_exists('ytl_ytdlp_session_cookie_for_convert')
        ? ytl_ytdlp_session_cookie_for_convert()
        : '-';
    $jarOk = is_string($jarPath) && $jarPath !== '-' && is_readable($jarPath) && filesize($jarPath) > 80;
    $backends['ytdlp_jar'] = [
        'present' => $jarOk,
        'code' => $jarOk ? 'jar_present' : 'cookie_dead',
        'path' => $jarOk ? basename(dirname($jarPath)) . '/' . basename($jarPath) : '',
    ];

    echo json_encode([
        'ok' => true,
        'mode' => 'diag',
        'deploy_marker' => $deployMarker,
        'videoId' => $videoId,
        'backends' => $backends,
        'ytdlp_session' => $ytdlp,
        'classify_samples' => [
            'bot' => ytl_convert_classify_error('Sign in to confirm you’re not a bot'),
            'jwt' => ytl_convert_classify_error('error.api.auth.jwt.missing'),
            'piped' => ytl_convert_classify_error('piped muxed hosts=0'),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$results = [];

foreach ($ids as $videoId) {
    // Clear prior status/log so probe is clean.
    foreach ([
        ytl_convert_status_path($videoId, $height, $fmt),
        ytl_convert_dir() . '/' . $videoId . ($fmt === 'mp3' ? '_mp3' : ($height === 360 ? '' : ('_' . $height))) . '.log',
        ytl_convert_media_path($videoId, $height, $fmt),
    ] as $p) {
        if (is_file($p)) {
            @unlink($p);
        }
    }
    $started = microtime(true);
    $code = ytl_convert_run_blocking($videoId, $height, null, $fmt);
    $status = ytl_convert_status($videoId, $height, $fmt);
    $logPath = ytl_convert_dir() . '/' . $videoId . ($fmt === 'mp3' ? '_mp3' : ($height === 360 ? '' : ('_' . $height))) . '.log';
    $logTail = '';
    $logRaw = '';
    if (is_readable($logPath)) {
        $logRaw = (string) file_get_contents($logPath);
        $logTail = substr($logRaw, max(0, strlen($logRaw) - 1600));
    }
    $errorCode = (string) ($status['error_code'] ?? '');
    if ($errorCode === '' && !empty($status['reason'])) {
        $errorCode = ytl_convert_classify_error((string) $status['reason']);
    }
    if ($errorCode === '' && $logTail !== '') {
        $errorCode = ytl_convert_classify_error($logTail);
    }
    $results[] = [
        'videoId' => $videoId,
        'code' => $code,
        'state' => (string) ($status['state'] ?? ''),
        'error_code' => $errorCode,
        'message' => (string) ($status['message'] ?? ($status['error'] ?? '')),
        'auth' => (string) ($status['auth'] ?? ''),
        'bytes' => (int) ($status['bytes'] ?? 0),
        'ms' => (int) round((microtime(true) - $started) * 1000),
        'steps' => ytl_probe_parse_steps($logRaw),
        'log_tail' => $logTail,
    ];
}

$ready = 0;
foreach ($results as $r) {
    if (($r['state'] ?? '') === 'ready' || ($r['state'] ?? '') === 'client_cobalt') {
        // client_cobalt is a valid server handoff (browser finishes).
        if (($r['state'] ?? '') === 'ready') {
            $ready++;
        }
    }
}

echo json_encode([
    'ok' => $ready > 0,
    'ready' => $ready,
    'total' => count($results),
    'deploy_marker' => $deployMarker,
    'ytdlp_session' => $ytdlp,
    'service_oauth' => [
        'linked' => !empty($service['linked']),
        'email' => (string) ($service['email'] ?? ''),
    ],
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
