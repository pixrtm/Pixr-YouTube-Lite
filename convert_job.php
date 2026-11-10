<?php
/**
 * CLI / cron : php convert_job.php VIDEO_ID [hauteur]
 * Lance converter.py en avant-plan (utile pour debug SSH).
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/convert.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$videoId = $argv[1] ?? '';
$height = isset($argv[2]) ? (int) $argv[2] : (int) (ytl_config()['default_quality'] ?? 360);

if ($videoId === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
    fwrite(STDERR, "Usage: php convert_job.php VIDEO_ID [height]\n");
    exit(1);
}

exit(ytl_convert_run_blocking($videoId, $height > 0 ? $height : 360));
