<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/oauth.php';
require_once __DIR__ . '/lib/youtube_tv_oauth.php';

ytl_session_start();
$cfg = ytl_config();
$client = ytl_client();
$user = ytl_current_user();
$userToken = ytl_session_bearer();

// Prefer ytlite_q (scoped autocomplete); still accept legacy ?q= from old links.
$userQuery = '';
if (isset($_GET['ytlite_q'])) {
    $userQuery = trim((string) $_GET['ytlite_q']);
} elseif (isset($_GET['q'])) {
    $userQuery = trim((string) $_GET['q']);
}
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$cat = isset($_GET['cat']) ? trim((string) $_GET['cat']) : 'all';
$guide = isset($_GET['guide']) ? trim((string) $_GET['guide']) : '';
$error = null;
$recNote = '';
$videos = [];
$shelves = [];
$hasCookie = ytl_has_youtube_cookie();
$hideLive = !empty($cfg['hide_live']);

$youIds = ['your_channel', 'history', 'playlists', 'watch_later', 'liked'];

// Explicit search box only — not category/guide browse.
$isSearch = $userQuery !== '';

// Guide tab browse term (Musique / Films / Jeux / Podcasts).
$guideQ = '';
$guideLabel = '';
if (!$isSearch && $guide !== '') {
    foreach (ytl_guide_tabs() as $tab) {
        if ($tab['id'] === $guide && $tab['q'] !== '') {
            $guideQ = (string) $tab['q'];
            $guideLabel = (string) $tab['label'];
            break;
        }
    }
}

// Sidebar category browse term (Autos, Gaming, …) — not You / Recommended.
$catQ = '';
$catLabel = '';
if (!$isSearch && $guideQ === '' && $cat !== '' && $cat !== 'all' && $cat !== 'recommended' && !in_array($cat, $youIds, true)) {
    foreach (ytl_categories() as $c) {
        if ($c['id'] === $cat && ($c['q'] ?? '') !== '') {
            $catQ = (string) $c['q'];
            $catLabel = (string) $c['label'];
            break;
        }
    }
}

// You section requires Sign-in (Bearer and/or visitor cookies load the feed).
if (in_array($cat, $youIds, true) && $user === null) {
    header('Location: ' . ytl_url('tv_signin.php'));
    exit;
}

// Legacy subscriptions URL → Recommended.
if ($cat === 'subscriptions') {
    header('Location: ' . ytl_url('index.php', ['cat' => 'recommended']));
    exit;
}

$wantRecommended = !$isSearch && $guideQ === '' && $cat === 'recommended';
$wantYou = !$isSearch && $guideQ === '' && in_array($cat, $youIds, true);
$wantHome = !$isSearch && $guideQ === '' && $catQ === '' && !$wantYou && ($cat === 'all' || $cat === '');
$wantGuide = !$isSearch && $guideQ !== '';
$wantCategory = !$isSearch && $catQ !== '';

if ($isSearch) {
    $videos = ytl_filter_videos($client->search($userQuery, $page), $hideLive);
    if (count($videos) === 0) {
        $error = 'No results (or API backends are unreachable). Try again later.';
    }
} elseif ($wantYou) {
    // Always refresh channelId / handle from TV accounts_list when opening You items.
    if ($userToken !== null && $userToken !== '') {
        $prof = ytl_tv_oauth_profile($userToken);
        $cid = trim((string) ($prof['channelId'] ?? ''));
        if ($cid !== '') {
            $_SESSION['ytl_user']['channelId'] = $cid;
            $tvId = ytl_tv_current_id();
            if ($tvId !== null) {
                $store = ytl_tv_oauth_load($tvId);
                if (is_array($store)) {
                    $store['channelId'] = $cid;
                    if (($prof['handle'] ?? '') !== '') {
                        $store['handle'] = (string) $prof['handle'];
                    }
                    ytl_tv_oauth_store($tvId, $store);
                }
            }
        }
    }
    if ($cat === 'your_channel') {
        $channelId = trim((string) ($_SESSION['ytl_user']['channelId'] ?? ''));
        if ($channelId !== '' && preg_match('/^UC[A-Za-z0-9_-]{20,}$/', $channelId)) {
            header('Location: ' . ytl_url('channel.php', ['id' => $channelId]));
            exit;
        }
        $error = 'Could not find your channel yet. Sign out and Sign in again.';
    } else {
        $libKind = $cat === 'watch_later' ? 'watch_later' : $cat;
        $labelMap = [
            'history' => 'Historique',
            'playlists' => 'Playlists',
            'watch_later' => 'À regarder plus tard',
            'liked' => 'Vidéos "J\'aime"',
        ];
        $token = is_string($userToken) ? $userToken : '';
        $videos = ytl_filter_videos(
            $client->userLibraryFeed($token, (string) $user['sub'], $libKind, 40),
            $hideLive
        );
        if (count($videos) === 0) {
            $error = 'Nothing here yet (or Google blocked this feed from the server).';
        } else {
            $shelves[] = [$labelMap[$cat] ?? 'You', array_slice($videos, 0, 40)];
        }
    }
} elseif ($wantRecommended) {
    $recNote = '';
    if ($user !== null) {
        // Signed-in: only THIS account (Bearer and/or visitor cookies). Never Pixr shared/service.
        $token = is_string($userToken) ? $userToken : '';
        $rawShelves = $client->userHomeShelves($token, (string) $user['sub'], 48);
        foreach ($rawShelves as $row) {
            if (!is_array($row) || count($row) < 2) {
                continue;
            }
            $title = (string) $row[0];
            $vids = ytl_filter_videos(is_array($row[1]) ? $row[1] : [], $hideLive);
            if (count($vids) === 0) {
                continue;
            }
            $shelves[] = [$title !== '' ? $title : 'Recommended', array_slice($vids, 0, 12)];
        }
        if (count($shelves) === 0) {
            $videos = ytl_filter_videos($client->userHomeFeed($token, (string) $user['sub'], 40), $hideLive);
            if (count($videos) > 0) {
                $shelves[] = ['Recommended for You', array_slice($videos, 0, 24)];
            }
        }
        if (count($shelves) === 0) {
            $error = 'Could not load your recommendations. Try again later.';
        }
    } elseif (count($shelves) === 0) {
        // Guests only: Accueil-style fallbacks (not labeled as a signed-in account feed).
        $videos = ytl_filter_videos($client->serviceHomeFeed(24), $hideLive);
        if (count($videos) === 0 && $hasCookie) {
            $acct = $client->cookieAccountLabel();
            $check = ytl_youtube_cookie_account_ok($acct);
            if (!empty($check['ok'])) {
                $videos = ytl_filter_videos($client->homeFeed(24), $hideLive);
            }
        }
        if (count($videos) === 0) {
            $videos = ytl_filter_videos($client->accueilFeed(20), $hideLive);
        }
        if (count($videos) === 0) {
            $videos = ytl_filter_videos($client->guestHomeFeed(16), $hideLive);
        }
        if (count($videos) === 0) {
            $error = 'Could not load recommendations. Try again later.';
        } else {
            $shelves[] = ['Recommended for You', array_slice($videos, 0, 20)];
        }
    }
} elseif ($wantHome) {
    // All Categories = guest /popular (not Recommended).
    $videos = ytl_filter_videos($client->guestHomeFeed(16), $hideLive);
    $videos = array_slice($videos, 0, 16);
    if (count($videos) === 0) {
        $error = 'Could not load videos. Try again later.';
    } else {
        $shelves[] = ['Videos', $videos];
    }
} elseif ($wantGuide) {
    $videos = ytl_filter_videos($client->search($guideQ, $page), $hideLive);
    $videos = array_slice($videos, 0, 20);
    if (count($videos) === 0) {
        $error = 'No results. Try again later.';
    } else {
        $shelves[] = [$guideLabel !== '' ? $guideLabel : 'Videos', $videos];
    }
} elseif ($wantCategory) {
    $videos = ytl_filter_videos($client->search($catQ, $page), $hideLive);
    $videos = array_slice($videos, 0, 20);
    if (count($videos) === 0) {
        $error = 'No results. Try again later.';
    } else {
        $shelves[] = [$catLabel !== '' ? $catLabel : 'Videos', $videos];
    }
} else {
    $videos = ytl_filter_videos($client->guestHomeFeed(16), $hideLive);
    $shelves[] = ['Videos', array_slice($videos, 0, 16)];
}

$pageTitle = $isSearch ? ('Search: ' . $userQuery) : (
    $wantGuide ? $guideLabel : (
        $wantCategory ? $catLabel : (
            $wantRecommended ? 'Recommended' : (
                $wantYou ? (string) ($catLabel !== '' ? $catLabel : $cat) : 'Home'
            )
        )
    )
);
if ($wantYou) {
    foreach (ytl_categories() as $c) {
        if ($c['id'] === $cat) {
            $pageTitle = (string) $c['label'];
            break;
        }
    }
}
$searchBox = $isSearch ? $userQuery : '';
$activeGuide = $guide !== '' ? $guide : '';

ytl_layout_start($pageTitle, $searchBox, $cat !== '' ? $cat : 'all', $activeGuide, true);
?>

<?php if ($isSearch): ?>
  <h1>Search results for &ldquo;<?php echo ytl_h($searchBox); ?>&rdquo;</h1>
  <?php if ($error): ?>
    <div class="error-box"><?php echo ytl_h($error); ?></div>
  <?php endif; ?>
  <?php
  if (count($videos) > 0) {
      ytl_render_video_table($videos);
  }
  echo '<div class="pager">';
  if ($page > 1) {
      echo '<a href="' . ytl_h(ytl_url('index.php', ['ytlite_q' => $searchBox, 'page' => $page - 1])) . '">&laquo; Previous</a>';
  }
  echo '<a href="' . ytl_h(ytl_url('index.php', ['ytlite_q' => $searchBox, 'page' => $page + 1])) . '">Next &raquo;</a>';
  echo '</div>';
  ?>
<?php else: ?>
  <?php if ($error): ?>
    <div class="error-box"><?php echo ytl_h($error); ?></div>
  <?php endif; ?>
  <?php if (!empty($recNote)): ?>
    <div class="fallback-note"><?php echo ytl_h($recNote); ?></div>
  <?php endif; ?>

  <?php foreach ($shelves as [$shelfTitle, $shelfVideos]): ?>
    <div class="shelf-title"><?php echo ytl_h($shelfTitle); ?> <span class="arrow">&raquo;</span></div>
    <?php ytl_render_video_grid($shelfVideos, 4); ?>
  <?php endforeach; ?>
<?php endif; ?>

<?php
ytl_layout_end_from_client($client);
