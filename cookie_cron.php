<?php
/**
 * Cron / health check for the shared Pixr YouTube Lite cookie.
 *
 * Examples:
 *   https://pixr.dev/ytlite/cookie_cron.php?key=SETUP_KEY
 *   https://pixr.dev/ytlite/cookie_cron.php?key=SETUP_KEY&format=text
 *
 * OVH cron (daily):
 *   curl -fsS "https://pixr.dev/ytlite/cookie_cron.php?key=SETUP_KEY"
 *
 * Exit semantics for CLI wrappers: HTTP 200 always; JSON "ok" false when re-export needed.
 */
require_once __DIR__ . '/lib/cookie_auth.php';

$cfg = ytl_config();
$key = (string) ($cfg['setup_key'] ?? '');
$provided = isset($_GET['key']) ? (string) $_GET['key'] : (isset($_POST['key']) ? (string) $_POST['key'] : '');
$format = isset($_GET['format']) ? strtolower((string) $_GET['format']) : 'json';

if ($key === '' || $key === 'ytlite-change-me' || !hash_equals($key, $provided)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "forbidden\n";
    exit;
}

$result = ytl_cookie_health_probe('cron');
if (isset($_GET['innertube'])) {
    require_once __DIR__ . '/lib/innertube.php';
    require_once __DIR__ . '/lib/convert.php';
    $vid = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['v'] ?? 'oc8G53Tbsvs')) ?: 'oc8G53Tbsvs';
    $cfg2 = $cfg; $cfg2['youtube_cookie'] = ''; $cfg2['youtube_cookie_file'] = '';
    $c = new InnertubeClient($cfg2);
    $v = $c->video($vid);
    $probe = [
        'video' => $vid,
        'anon_ok' => is_array($v),
        'anon_formats' => is_array($v) ? count($v['formatStreams'] ?? []) : 0,
        'anon_err' => ytl_http_last_error(),
        'anon_instance' => $c->getActiveInstance(),
    ];
    if (is_array($v)) {
        $u = ytl_convert_pick_progressive_url($v, 360);
        $probe['pick'] = $u !== '';
        if ($u !== '' && isset($_GET['save'])) {
            $ok = ytl_convert_save_url($vid, $u, true, 360);
            $probe['save'] = $ok;
            $probe['bytes'] = is_file(ytl_convert_mp4_path($vid, 360)) ? filesize(ytl_convert_mp4_path($vid, 360)) : 0;
        }
    }
    if (isset($_GET['piped'])) {
        require_once __DIR__ . '/lib/api.php';
        $cands = ytl_client()->progressiveCandidates($vid, 360);
        $probe['candidates'] = [];
        foreach (array_slice($cands, 0, 6) as $cand) {
            $probe['candidates'][] = [
                'backend' => $cand['backend'] ?? '',
                'host' => $cand['host'] ?? '',
                'proxyish' => !empty($cand['proxyish']),
            ];
        }
        if (isset($_GET['save'])) {
            foreach ($cands as $cand) {
                if (empty($cand['url']) || ($cand['backend'] ?? '') === 'innertube') {
                    continue;
                }
                $ok = ytl_convert_save_url($vid, (string) $cand['url'], true, 360);
                $probe['piped_save'] = [
                    'backend' => $cand['backend'] ?? '',
                    'ok' => $ok,
                    'bytes' => is_file(ytl_convert_mp4_path($vid, 360)) ? filesize(ytl_convert_mp4_path($vid, 360)) : 0,
                ];
                if ($ok) {
                    break;
                }
            }
        }
    }
    $result['innertube_probe'] = $probe;
}
$payload = [
    'ok' => !empty($result['ok']),
    'state' => (string) ($result['state'] ?? ''),
    'logged_in' => !empty($result['logged_in']),
    'account' => (string) ($result['account'] ?? ''),
    'reason' => (string) ($result['reason'] ?? ''),
    'missing' => $result['missing'] ?? [],
    'videos' => (int) ($result['videos'] ?? 0),
    'checked_at' => (int) ($result['checked_at'] ?? time()),
    'alert' => !empty($result['alert']),
    'cookies_admin' => 'cookies.php?key=' . rawurlencode($provided),
    'hard_limit' => 'Sync auto: scripts/sync_firefox_yt_cookies.py → cookie_sync.php (pas d’UI Account).',
];
if (isset($result['innertube_probe'])) {
    $payload['innertube_probe'] = $result['innertube_probe'];
}

header('Cache-Control: no-store');
if ($format === 'text') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo ($payload['ok'] ? 'OK' : 'ALERT') . ' state=' . $payload['state'] . "\n";
    echo 'account=' . $payload['account'] . "\n";
    echo 'reason=' . $payload['reason'] . "\n";
    echo 'checked_at=' . gmdate('c', $payload['checked_at']) . "\n";
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
echo "\n";
