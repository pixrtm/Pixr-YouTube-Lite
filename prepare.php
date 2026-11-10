<?php
/** Ancien lien : redirige vers la page classic (préparation intégrée). */
$videoId = isset($_GET['v']) ? trim((string) $_GET['v']) : '';
if ($videoId === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
    http_response_code(400);
    echo 'Bad video id';
    exit;
}
header('Location: watch.php?v=' . rawurlencode($videoId), true, 302);
exit;