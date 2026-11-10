<?php
/**
 * Ouvre le MP4 dans le lecteur intégré du navigateur.
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/convert.php';

$videoId = isset($_GET['v']) ? trim((string) $_GET['v']) : '';
$height = ytl_convert_normalize_quality(isset($_GET['q']) ? (int) $_GET['q'] : 360);

if ($videoId === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
    http_response_code(400);
    echo 'Bad video id';
    exit;
}

if (!ytl_convert_is_ready($videoId, $height)) {
    header('Location: ' . ytl_url('watch.php', ['v' => $videoId, 'q' => $height]));
    exit;
}

$src = ytl_convert_media_url($videoId, $height);
$label = ytl_convert_quality_label($height);
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo ytl_h($videoId . ' · ' . $label); ?></title>
<style>
html, body { margin: 0; background: #000; height: 100%; }
video { display: block; width: 100%; height: 100%; background: #000; }
</style>
</head>
<body>
<video controls="controls" autoplay="autoplay" playsinline="playsinline" src="<?php echo ytl_h($src); ?>"></video>
</body>
</html>
