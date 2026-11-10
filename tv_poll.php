<?php
/**
 * JSON poll for YouTube TV device-code completion.
 */
require_once __DIR__ . '/lib/youtube_tv_oauth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

ytl_session_start();
$result = ytl_tv_oauth_poll();
http_response_code(!empty($result['ok']) || !empty($result['pending']) ? 200 : 400);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
