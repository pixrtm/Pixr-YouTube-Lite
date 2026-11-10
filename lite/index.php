<?php

require_once __DIR__ . '/layout.php';

$q = '';
if (isset($_GET['ytlite_q'])) {
    $q = trim((string) $_GET['ytlite_q']);
} elseif (isset($_GET['q'])) {
    $q = trim((string) $_GET['q']);
}
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$client = ytl_client();
$videos = [];

if ($q !== '') {
    $videos = ytl_filter_videos($client->search($q, $page), true);
} else {
    // Same fast guest home path as index.php (cached Invidious /popular — not Piped trending).
    $videos = ytl_filter_videos($client->guestHomeFeed(16), true);
}

ytl_lite_start($q !== '' ? ('Search: ' . $q) : 'Home', $q);
if ($q !== '') {
    echo '<p><b>Search: ' . ytl_h($q) . '</b></p>';
}
ytl_lite_rows($videos);
if ($q !== '') {
    echo '<p>';
    if ($page > 1) {
        echo '<a href="' . ytl_h(ytl_url('index.php', ['ytlite_q' => $q, 'page' => $page - 1])) . '">&laquo; Previous</a> ';
    }
    echo '<a href="' . ytl_h(ytl_url('index.php', ['ytlite_q' => $q, 'page' => $page + 1])) . '">Next &raquo;</a>';
    echo '</p>';
}
ytl_lite_end();
