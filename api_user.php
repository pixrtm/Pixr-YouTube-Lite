<?php
/**
 * Authenticated user actions (like / comment / subscribe).
 * YouTube TV Sign-in → Innertube TVHTML5 actions.
 * Cloud OAuth → YouTube Data API v3.
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/oauth.php';
require_once __DIR__ . '/lib/youtube_tv_oauth.php';
require_once __DIR__ . '/lib/youtube_api.php';
require_once __DIR__ . '/lib/api.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

ytl_session_start();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

$raw = file_get_contents('php://input');
$data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($data)) {
    $data = $_POST;
}

$csrf = (string) ($data['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''));
if (!ytl_oauth_csrf_check($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF']);
    exit;
}

if (ytl_current_user() === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sign in required']);
    exit;
}

$token = ytl_session_bearer();
if ($token === null || $token === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No access token — Sign out, then Sign in again']);
    exit;
}

$isTv = ((string) ($_SESSION['ytl_user']['auth'] ?? '')) === 'youtube_tv'
    || str_starts_with((string) ($_SESSION['ytl_user']['sub'] ?? ''), 'tv_')
    || ytl_tv_current_id() !== null;

$action = strtolower(trim((string) ($data['action'] ?? '')));
$videoId = trim((string) ($data['videoId'] ?? ''));
$channelId = trim((string) ($data['channelId'] ?? ''));
$subscriptionId = trim((string) ($data['subscriptionId'] ?? ''));
$text = (string) ($data['text'] ?? '');

$result = ['ok' => false, 'error' => 'Unknown action'];

if ($isTv) {
    $cfg = ytl_config();
    $tube = new InnertubeClient($cfg);
    switch ($action) {
        case 'status':
            // TV Data API ratings are unavailable — UI stays optimistic.
            $result = [
                'ok' => true,
                'rating' => 'none',
                'subscribed' => false,
                'subscriptionId' => '',
                'auth' => 'youtube_tv',
            ];
            break;
        case 'like':
            $result = preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)
                ? $tube->tvRateVideo($token, $videoId, 'like')
                : ['ok' => false, 'error' => 'Invalid video id'];
            break;
        case 'unlike':
        case 'undislike':
            $result = preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)
                ? $tube->tvRateVideo($token, $videoId, 'none')
                : ['ok' => false, 'error' => 'Invalid video id'];
            break;
        case 'dislike':
            $result = preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)
                ? $tube->tvRateVideo($token, $videoId, 'dislike')
                : ['ok' => false, 'error' => 'Invalid video id'];
            break;
        case 'comment':
            $result = preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)
                ? $tube->tvPostComment($token, $videoId, $text)
                : ['ok' => false, 'error' => 'Invalid video id'];
            break;
        case 'subscribe':
            $result = preg_match('/^[A-Za-z0-9_-]{10,64}$/', $channelId)
                ? $tube->tvSubscribe($token, $channelId, true)
                : ['ok' => false, 'error' => 'Invalid channel id'];
            break;
        case 'unsubscribe':
            // TV path unsubscribes by channel id (subscriptionId is a Data API concept).
            if ($channelId === '' && str_starts_with($subscriptionId, 'tv_')) {
                $channelId = substr($subscriptionId, 3);
            }
            $result = preg_match('/^[A-Za-z0-9_-]{10,64}$/', $channelId)
                ? $tube->tvSubscribe($token, $channelId, false)
                : ['ok' => false, 'error' => 'Missing channel id'];
            break;
        default:
            $result = ['ok' => false, 'error' => 'Unknown action'];
    }
} else {
    $api = YtlYoutubeApi::forCurrentUser();
    if ($api === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'No access token']);
        exit;
    }
    switch ($action) {
        case 'status':
            $rating = 'none';
            if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
                $r = $api->getVideoRating($videoId);
                if (!empty($r['ok'])) {
                    $rating = (string) ($r['rating'] ?? 'none');
                }
            }
            $subId = '';
            if (preg_match('/^[A-Za-z0-9_-]{10,64}$/', $channelId)) {
                $subId = $api->subscriptionIdForChannel($channelId);
            }
            $result = [
                'ok' => true,
                'rating' => $rating,
                'subscribed' => $subId !== '',
                'subscriptionId' => $subId,
            ];
            break;
        case 'like':
            $result = preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)
                ? $api->rateVideo($videoId, 'like')
                : ['ok' => false, 'error' => 'Invalid video id'];
            break;
        case 'unlike':
            $result = preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)
                ? $api->rateVideo($videoId, 'none')
                : ['ok' => false, 'error' => 'Invalid video id'];
            break;
        case 'dislike':
            $result = preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)
                ? $api->rateVideo($videoId, 'dislike')
                : ['ok' => false, 'error' => 'Invalid video id'];
            break;
        case 'undislike':
            $result = preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)
                ? $api->rateVideo($videoId, 'none')
                : ['ok' => false, 'error' => 'Invalid video id'];
            break;
        case 'comment':
            $result = preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)
                ? $api->postComment($videoId, $text)
                : ['ok' => false, 'error' => 'Invalid video id'];
            break;
        case 'subscribe':
            if (!preg_match('/^[A-Za-z0-9_-]{10,64}$/', $channelId)) {
                $result = ['ok' => false, 'error' => 'Invalid channel id'];
                break;
            }
            $existing = $api->subscriptionIdForChannel($channelId);
            if ($existing !== '') {
                $result = ['ok' => true, 'subscriptionId' => $existing, 'subscribed' => true];
                break;
            }
            $result = $api->subscribe($channelId);
            if (!empty($result['ok'])) {
                $result['subscribed'] = true;
            }
            break;
        case 'unsubscribe':
            if ($subscriptionId === '' && preg_match('/^[A-Za-z0-9_-]{10,64}$/', $channelId)) {
                $subscriptionId = $api->subscriptionIdForChannel($channelId);
            }
            if ($subscriptionId === '') {
                $result = ['ok' => false, 'error' => 'Not subscribed'];
                break;
            }
            $result = $api->unsubscribe($subscriptionId);
            if (!empty($result['ok'])) {
                $result['subscribed'] = false;
                $result['subscriptionId'] = '';
            }
            break;
        default:
            $result = ['ok' => false, 'error' => 'Unknown action'];
    }
}

http_response_code(!empty($result['ok']) ? 200 : 400);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
