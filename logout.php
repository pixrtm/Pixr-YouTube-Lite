<?php
require_once __DIR__ . '/lib/oauth.php';
require_once __DIR__ . '/lib/youtube_tv_oauth.php';

ytl_tv_oauth_logout();
ytl_oauth_logout();
header('Location: ' . ytl_url('index.php'), true, 302);
exit;
