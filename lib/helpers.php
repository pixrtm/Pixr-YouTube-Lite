<?php

/**
 * Shared view helpers for YouTube Lite (YouTube ~2012 UI).
 */

require_once __DIR__ . '/invidious.php';
require_once __DIR__ . '/settings.php';

function ytl_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require dirname(__DIR__) . '/config.php';
        // Overlay per-browser server lists (cookie) onto runtime config.
        $s = ytl_settings_get();
        if (!empty($s['meta'])) {
            $cfg['invidious_instances'] = array_values(array_unique(array_merge(
                $s['meta'],
                (array) ($cfg['invidious_instances'] ?? [])
            )));
        }
        if (!empty($s['convert'])) {
            $cfg['cobalt_apis'] = array_values(array_unique(array_merge(
                $s['convert'],
                (array) ($cfg['cobalt_apis'] ?? [])
            )));
        }
        if (!empty($s['invidious'])) {
            $cfg['invidious_client'] = array_values(array_unique($s['invidious']));
        }
    }
    return $cfg;
}

/** Proxied Google/YouTube avatar, or empty. */
function ytl_user_picture_src(array $user): string
{
    $pic = trim((string) ($user['picture'] ?? ''));
    if ($pic === '' || !preg_match('#^https?://#i', $pic)) {
        return '';
    }
    return ytl_proxy_url($pic, 'image');
}

/** Initials for letter avatar when no photo. */
function ytl_user_initials(array $user): string
{
    $name = trim((string) ($user['name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($user['email'] ?? 'U'));
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    $a = strtoupper(substr((string) ($parts[0] ?? 'U'), 0, 1));
    $b = count($parts) > 1 ? strtoupper(substr((string) $parts[1], 0, 1)) : '';
    return $a . $b;
}

function ytl_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** UI locale: default English. Classic may follow Accept-Language; Lite stays English. */
function ytl_ui_locale(bool $forceEnglish = false): string
{
    if ($forceEnglish) {
        return 'en';
    }
    $al = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if ($al !== '' && preg_match('/\b([a-z]{2})\b/', $al, $m)) {
        $code = $m[1];
        if (in_array($code, ['fr', 'es', 'de', 'it', 'pt', 'nl', 'pl', 'ru', 'ja', 'ko', 'zh'], true)) {
            return $code;
        }
    }
    return 'en';
}

/** Tiny UI dictionary (English default). */
function ytl_t(string $key, ?string $locale = null): string
{
    static $dict = [
        'en' => [
            'preparing' => 'Preparing video…',
            'preparing_q' => 'Preparing video (%s)…',
            'starting' => 'Starting…',
            'checking' => 'Checking…',
            'downloading' => 'Downloading…',
            'ready_play' => 'Ready — opening…',
            'failed' => 'Failed',
            'retry' => 'Retry',
            'quality' => 'Quality',
            'home' => 'Home',
            'unavailable' => 'Converter unavailable.',
            'play_error' => 'This video could not be prepared.',
            'loading_desc' => 'Loading description…',
            'loading_comments' => 'Loading comments…',
            'loading_meta' => 'Loading…',
            'no_description' => 'No description.',
            'no_comments' => 'No comments.',
            'comments_off' => 'Comments are turned off.',
            'comments_unavailable' => 'Comments are unavailable right now.',
        ],
        'fr' => [
            'preparing' => 'Préparation de la vidéo…',
            'preparing_q' => 'Préparation de la vidéo (%s)…',
            'starting' => 'Démarrage…',
            'checking' => 'Vérification…',
            'downloading' => 'Téléchargement…',
            'ready_play' => 'Prêt — ouverture…',
            'failed' => 'Échec',
            'retry' => 'Réessayer',
            'quality' => 'Qualité',
            'home' => 'Accueil',
            'unavailable' => 'Convertisseur indisponible.',
            'play_error' => 'Impossible de préparer cette vidéo.',
            'loading_desc' => 'Chargement de la description…',
            'loading_comments' => 'Chargement des commentaires…',
            'loading_meta' => 'Chargement…',
            'no_description' => 'Pas de description.',
            'no_comments' => 'Pas de commentaires.',
            'comments_off' => 'Les commentaires sont désactivés.',
            'comments_unavailable' => 'Commentaires indisponibles pour le moment.',
        ],
    ];
    $locale = $locale ?? ytl_ui_locale();
    if (!isset($dict[$locale])) {
        $locale = 'en';
    }
    return $dict[$locale][$key] ?? $dict['en'][$key] ?? $key;
}

function ytl_url(string $path, array $params = []): string
{
    $q = http_build_query($params);
    return $path . ($q !== '' ? '?' . $q : '');
}

function ytl_client(): YtlApi
{
    static $client = null;
    if ($client === null) {
        require_once __DIR__ . '/api.php';
        $client = new YtlApi(ytl_config());
    }
    return $client;
}

/** True when the shared site cookie is configured (visitors never see it). */
function ytl_has_youtube_cookie(): bool
{
    $cfg = ytl_config();
    if (trim((string) ($cfg['youtube_cookie'] ?? '')) !== '') {
        return true;
    }
    $file = (string) ($cfg['youtube_cookie_file'] ?? '');
    return $file !== '' && is_readable($file) && @filesize($file) > 10;
}

/** Raw shared cookie file/config content (header OR Netscape), or empty. */
function ytl_youtube_cookie_raw(): string
{
    $cfg = ytl_config();
    $cookie = trim((string) ($cfg['youtube_cookie'] ?? ''));
    $file = (string) ($cfg['youtube_cookie_file'] ?? '');
    if ($file !== '' && is_readable($file)) {
        $fromFile = trim((string) file_get_contents($file));
        if ($fromFile !== '') {
            $cookie = $fromFile;
        }
    }
    return $cookie;
}

/** Normalize cookie file content to a single Cookie request header. */
function ytl_cookie_to_header(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (!str_contains($raw, "\n") && !str_contains($raw, "\t") && str_contains($raw, '=')) {
        return $raw;
    }
    $parts = [];
    foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, '#HttpOnly_')) {
            $line = substr($line, strlen('#HttpOnly_'));
        } elseif (str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=') && !str_contains($line, "\t")) {
            $parts[] = rtrim($line, ';');
            continue;
        }
        $cols = preg_split('/\t/', $line);
        if (is_array($cols) && count($cols) >= 7) {
            $parts[] = $cols[5] . '=' . $cols[6];
        }
    }
    return implode('; ', $parts);
}

/**
 * Convert a Cookie header string (or already-Netscape file) to Netscape format
 * so yt-dlp --cookies can read it.
 */
function ytl_cookie_to_netscape(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    // Already Netscape-ish.
    if (str_contains($raw, "\t") || str_contains($raw, '#HttpOnly_') || stripos($raw, 'Netscape') !== false) {
        if (!str_contains($raw, 'Netscape')) {
            return "# Netscape HTTP Cookie File\n" . $raw . "\n";
        }
        return $raw . (str_ends_with($raw, "\n") ? '' : "\n");
    }

    $header = ytl_cookie_to_header($raw);
    $lines = ["# Netscape HTTP Cookie File", "# https://curl.se/docs/http-cookies.html"];
    $expires = (string) (time() + 3600 * 24 * 365);
    foreach (explode(';', $header) as $pair) {
        $pair = trim($pair);
        if ($pair === '' || !str_contains($pair, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $pair, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '') {
            continue;
        }
        $secure = str_starts_with($name, '__Secure-') || str_starts_with($name, '__Host-') || $name === 'LOGIN_INFO';
        $domain = $secure ? '.youtube.com' : '.youtube.com';
        $flag = 'TRUE';
        $path = '/';
        $secureFlag = $secure ? 'TRUE' : 'FALSE';
        $lines[] = implode("\t", [$domain, $flag, $path, $secureFlag, $expires, $name, $value]);
    }
    return implode("\n", $lines) . "\n";
}

/** Persist shared YouTube cookies (header or Netscape) as Netscape for yt-dlp. */
function ytl_save_youtube_cookies(string $raw): bool
{
    $cfg = ytl_config();
    $file = (string) ($cfg['youtube_cookie_file'] ?? (__DIR__ . '/../data/youtube.cookies.txt'));
    if ($file === '') {
        return false;
    }
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return false;
    }
    $netscape = ytl_cookie_to_netscape($raw);
    if ($netscape === '') {
        return false;
    }
    $ok = @file_put_contents($file, $netscape);
    if ($ok === false) {
        return false;
    }
    @chmod($file, 0644);
    // Drop cached personalized feed + account label so the new account shows immediately.
    $cacheDir = rtrim((string) ($cfg['video_cache_dir'] ?? (__DIR__ . '/../data/videos')), '/\\') . '/.cache';
    foreach (['feed_rec_home.json', 'feed_rec_subs.json', 'acct_label.json', 'feed_guest_home.json'] as $name) {
        $p = $cacheDir . '/' . $name;
        if (is_file($p)) {
            @unlink($p);
        }
    }
    return true;
}

/**
 * Signed-in visitor is the Pixr YouTube Lite account → may reuse the shared server jar
 * (auto-synced; no manual Account paste).
 */
function ytl_user_may_use_shared_youtube_jar(?array $user = null): bool
{
    $user = $user ?? ytl_current_user();
    if ($user === null) {
        return false;
    }
    $email = strtolower(trim((string) ($user['email'] ?? '')));
    $name = strtolower(trim((string) ($user['name'] ?? '')));
    $cfg = ytl_config();
    $allow = $cfg['youtube_cookie_expect']['allow'] ?? ['pixryoutubelite', 'pixr youtube lite'];
    if (!is_array($allow)) {
        $allow = ['pixryoutubelite', 'pixr youtube lite'];
    }
    foreach ($allow as $good) {
        $good = strtolower(trim((string) $good));
        if ($good === '') {
            continue;
        }
        if (($email !== '' && str_contains($email, $good))
            || ($name !== '' && str_contains($name, $good))) {
            return true;
        }
    }
    return false;
}

/**
 * Whether the signed-in cookie account looks like the expected test account.
 *
 * @param array{handle?:string,name?:string,email?:string} $label
 * @return array{ok:bool,reason:string}
 */
function ytl_youtube_cookie_account_ok(array $label): array
{
    $cfg = ytl_config();
    $expect = $cfg['youtube_cookie_expect'] ?? [];
    $allow = is_array($expect['allow'] ?? null) ? $expect['allow'] : ['pixryoutubelite', 'pixr youtube lite'];
    $deny = is_array($expect['deny'] ?? null) ? $expect['deny'] : [];
    $email = strtolower(trim((string) ($label['email'] ?? '')));
    $handle = strtolower(trim((string) ($label['handle'] ?? '')));
    $name = trim((string) ($label['name'] ?? ''));
    $who = trim($name . ' ' . (string) ($label['handle'] ?? '') . ' ' . (string) ($label['email'] ?? ''));
    // Prefer email + handle — display name alone is unreliable (language menus, etc.).
    $identity = trim($email . ' ' . $handle);
    // Deny only when we positively recognize a blocked account (email/handle/name).
    foreach ($deny as $bad) {
        $bad = strtolower(trim((string) $bad));
        if ($bad === '') {
            continue;
        }
        if (($email !== '' && str_contains($email, $bad))
            || ($handle !== '' && str_contains($handle, $bad))
            || ($name !== '' && str_contains(strtolower($name), $bad))) {
            return [
                'ok' => false,
                'reason' => 'Wrong account (“' . $who . '”). Need Pixr YouTube Lite — pixryoutubelite@gmail.com.',
            ];
        }
    }
    foreach ($allow as $good) {
        $good = strtolower(trim((string) $good));
        if ($good === '') {
            continue;
        }
        if ($identity !== '' && str_contains($identity, $good)) {
            return ['ok' => true, 'reason' => ''];
        }
        if ($name !== '' && str_contains(strtolower($name), $good)) {
            return ['ok' => true, 'reason' => ''];
        }
    }
    // Display name alone is unreliable (language menus, UI chrome). Only hard-fail
    // when email/handle is present and does not match the allow list.
    if ($identity === '') {
        return ['ok' => true, 'reason' => ''];
    }
    $shown = $who !== '' ? $who : '(unknown)';
    return [
        'ok' => false,
        'reason' => 'Account is “' . $shown . '”. Expected Pixr YouTube Lite (pixryoutubelite@gmail.com).',
    ];
}

/**
 * Cookie looks complete enough for datacenter Innertube.
 * Modern Chrome often has no SID/HSID on .youtube.com — Secure-PSID is enough.
 * @return list<string> missing cookie names
 */
function ytl_youtube_cookie_missing(): array
{
    $raw = ytl_youtube_cookie_raw();
    if ($raw === '') {
        return ['(aucun cookie)'];
    }
    // Header string OR Netscape (#HttpOnly_ lines)
    $names = [];
    if (str_contains($raw, "\t") || str_contains($raw, '#HttpOnly_') || str_contains($raw, 'Netscape')) {
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            $line = trim((string) $line);
            if (str_starts_with($line, '#HttpOnly_')) {
                $line = substr($line, strlen('#HttpOnly_'));
            } elseif ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $cols = preg_split('/\t/', $line);
            if (is_array($cols) && count($cols) >= 7) {
                $names[] = $cols[5];
            }
        }
    } else {
        preg_match_all('/(?:^|;\s*)([^=\s]+)=/', $raw, $m);
        $names = $m[1] ?? [];
    }
    $need = ['LOGIN_INFO', 'SAPISID', '__Secure-1PSID', '__Secure-3PSID', 'SSID'];
    $missing = [];
    foreach ($need as $n) {
        if (!in_array($n, $names, true)) {
            $missing[] = $n;
        }
    }
    return $missing;
}

/**
 * Rewrite Invidious/Piped avatar mirrors to a direct YouTube host, bump tiny sizes.
 */
function ytl_normalize_avatar_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return '';
    }
    // Invidious often rewrites yt3 → instance/ggpht/... which breaks when re-proxied from OVH.
    if (preg_match('#^https?://[^/]+/(?:ggpht|yt3)/(.*)$#i', $url, $m)) {
        $url = 'https://yt3.googleusercontent.com/' . $m[1];
    }
    // Prefer a readable size for the 48px watch avatar.
    if (str_contains($url, 'googleusercontent.com') || str_contains($url, 'yt3.ggpht.com') || str_contains($url, 'ggpht.com')) {
        $url = preg_replace('/=s\d+-/', '=s176-', $url) ?? $url;
        $url = preg_replace('/=w\d+-/', '=s176-', $url) ?? $url;
    }
    return $url;
}

function ytl_proxy_url(string $remoteUrl, string $kind = 'stream'): string
{
    $cfg = ytl_config();
    if ($kind === 'image') {
        $remoteUrl = ytl_normalize_avatar_url($remoteUrl) ?: $remoteUrl;
    }
    $useProxy = $kind === 'image'
        ? !empty($cfg['proxy_images'])
        : !empty($cfg['proxy_streams']);

    if (!$useProxy) {
        return $remoteUrl;
    }

    return 'proxy.php?' . http_build_query([
        'url' => $remoteUrl,
        't' => $kind,
    ]);
}

function ytl_thumb_url(array $item): string
{
    $id = (string) ($item['videoId'] ?? '');
    // mqdefault = 320×180 vrai 16:9 (hqdefault = 4:3 + bandes noires baked-in).
    if ($id !== '' && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) {
        return ytl_proxy_url('https://i.ytimg.com/vi/' . rawurlencode($id) . '/mqdefault.jpg', 'image');
    }
    $thumbs = $item['videoThumbnails'] ?? [];
    $url = InvidiousClient::bestThumbnail(is_array($thumbs) ? $thumbs : []);
    if ($url === '') {
        return 'assets/YTLite.png';
    }
    return ytl_proxy_url($url, 'image');
}

function ytl_channel_avatar(array $channel): string
{
    $url = ytl_normalize_avatar_url(trim((string) ($channel['avatarUrl'] ?? '')));
    if ($url === '' && is_array($channel['authorThumbnails'] ?? null)) {
        $best = null;
        foreach ($channel['authorThumbnails'] as $t) {
            if (!is_array($t) || empty($t['url'])) {
                continue;
            }
            $cand = ytl_normalize_avatar_url((string) $t['url']);
            if ($cand === '') {
                continue;
            }
            $w = (int) ($t['width'] ?? 0);
            // Prefer ~120px channel avatar (crisp for 48px display).
            $score = $w > 0 ? abs($w - 120) : 999;
            if ($best === null || $score < $best['score']) {
                $best = ['score' => $score, 'url' => $cand];
            }
        }
        if ($best !== null) {
            $url = $best['url'];
        }
    }
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return '';
    }
    return ytl_proxy_url($url, 'image');
}

/**
 * Sidebar entries. type: link | you_header | you (sign-in library).
 *
 * @return list<array{id:string,label:string,q:string,type?:string}>
 */
function ytl_categories(): array
{
    return [
        ['id' => 'all', 'label' => 'All Categories', 'q' => '', 'type' => 'link'],
        ['id' => 'recommended', 'label' => 'Recommended for You', 'q' => '', 'type' => 'link'],
        ['id' => 'you', 'label' => 'You', 'q' => '', 'type' => 'you_header'],
        ['id' => 'your_channel', 'label' => 'Votre chaîne', 'q' => '', 'type' => 'you'],
        ['id' => 'history', 'label' => 'Historique', 'q' => '', 'type' => 'you'],
        ['id' => 'playlists', 'label' => 'Playlists', 'q' => '', 'type' => 'you'],
        ['id' => 'watch_later', 'label' => 'À regarder plus tard', 'q' => '', 'type' => 'you'],
        ['id' => 'liked', 'label' => 'Vidéos "J\'aime"', 'q' => '', 'type' => 'you'],
        ['id' => 'browse', 'label' => 'Explore', 'q' => '', 'type' => 'browse_header'],
        ['id' => 'news', 'label' => 'Actualités et politique', 'q' => 'actualité politique news', 'type' => 'link'],
        ['id' => 'pets', 'label' => 'Animaux', 'q' => 'animaux pets animals', 'type' => 'link'],
        ['id' => 'nonprofit', 'label' => 'Associations et engagement', 'q' => 'association engagement nonprofit', 'type' => 'link'],
        ['id' => 'autos', 'label' => 'Auto/Moto', 'q' => 'auto moto cars vehicles', 'type' => 'link'],
        ['id' => 'entertainment', 'label' => 'Divertissement', 'q' => 'divertissement entertainment', 'type' => 'link'],
        ['id' => 'education', 'label' => 'Éducation', 'q' => 'éducation education', 'type' => 'link'],
        ['id' => 'film', 'label' => 'Films et animations', 'q' => 'film animation', 'type' => 'link'],
        ['id' => 'comedy', 'label' => 'Humour', 'q' => 'humour comedy funny', 'type' => 'link'],
        ['id' => 'gaming', 'label' => 'Jeux vidéo', 'q' => 'jeux vidéo gaming', 'type' => 'link'],
        ['id' => 'music', 'label' => 'Musique', 'q' => 'musique music', 'type' => 'link'],
        ['id' => 'people', 'label' => 'People et blogs', 'q' => 'people blogs vlog', 'type' => 'link'],
        ['id' => 'science', 'label' => 'Science et technologie', 'q' => 'science technologie technology', 'type' => 'link'],
        ['id' => 'sports', 'label' => 'Sport', 'q' => 'sport sports', 'type' => 'link'],
        ['id' => 'howto', 'label' => 'Vie pratique et style', 'q' => 'vie pratique style howto', 'type' => 'link'],
        ['id' => 'travel', 'label' => 'Voyages et événements', 'q' => 'voyage événement travel', 'type' => 'link'],
    ];
}

/** @return list<array{id:string,label:string,q:string}> */
function ytl_guide_tabs(): array
{
    return [
        ['id' => 'home', 'label' => 'Accueil', 'q' => ''],
        ['id' => 'music', 'label' => 'Musique', 'q' => 'music'],
        ['id' => 'movies', 'label' => 'Films et séries TV', 'q' => 'movies series film'],
        ['id' => 'gaming', 'label' => 'Jeux vidéo', 'q' => 'gaming video games'],
        ['id' => 'podcasts', 'label' => 'Podcasts', 'q' => 'podcast'],
    ];
}

/** Visitor Bearer: YouTube TV Sign-in first, else Cloud OAuth. */
function ytl_session_bearer(): ?string
{
    if (!function_exists('ytl_tv_access_token')) {
        require_once __DIR__ . '/youtube_tv_oauth.php';
    }
    $tv = ytl_tv_access_token();
    if (is_string($tv) && $tv !== '') {
        return $tv;
    }
    if (function_exists('ytl_user_access_token')) {
        $cloud = ytl_user_access_token();
        if (is_string($cloud) && $cloud !== '') {
            return $cloud;
        }
    }
    return null;
}

function ytl_layout_start(string $title, string $query = '', string $activeCat = 'all', string $activeGuide = '', bool $showSidebar = true): void
{
    require_once __DIR__ . '/oauth.php';
    ytl_session_start();
    $cfg = ytl_config();
    $site = $cfg['site_name'] ?? 'Pixr Lite';
    $fullTitle = $title === '' ? $site : ($title . ' - ' . $site);
    $htmlLang = ytl_ui_locale();
    $user = ytl_current_user();
    // Drop leftover Cloud Web Sign-in (no TV Bearer) so masthead shows Sign in → TV flow.
    if ($user !== null) {
        $auth = (string) ($_SESSION['ytl_user']['auth'] ?? '');
        $sub = (string) ($_SESSION['ytl_user']['sub'] ?? '');
        if ($auth !== 'youtube_tv' && !str_starts_with($sub, 'tv_')) {
            unset($_SESSION['ytl_user'], $_SESSION['ytl_oauth_state'], $_SESSION['ytl_oauth_mode']);
            $user = null;
        }
    }
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html lang="<?php echo ytl_h($htmlLang); ?>">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=4, viewport-fit=cover">
<meta name="format-detection" content="telephone=no">
<meta http-equiv="X-UA-Compatible" content="IE=EmulateIE8">
<meta name="application-name" content="<?php echo ytl_h($site); ?>">
<title><?php echo ytl_h($fullTitle); ?></title>
<link rel="stylesheet" type="text/css" href="assets/style.css?v=72">
<link rel="icon" type="image/png" href="/ytlite/favicon-96x96.png?v=50" sizes="96x96">
<link rel="icon" type="image/svg+xml" href="/ytlite/favicon.svg?v=50">
<link rel="shortcut icon" href="/ytlite/favicon.ico?v=50">
<link rel="apple-touch-icon" sizes="180x180" href="/ytlite/apple-touch-icon.png?v=50">
<meta name="apple-mobile-web-app-title" content="<?php echo ytl_h($site); ?>">
<link rel="manifest" href="/ytlite/site.webmanifest?v=50">
<script type="text/javascript">
(function () {
  // Lock-screen / PWA resume must open pixr.dev, never cloud.pixr.dev.
  try {
    var h = String(location.hostname || '');
    if (h === 'cloud.pixr.dev' || h.slice(-15) === '.cloud.pixr.dev') {
      location.replace('https://pixr.dev' + location.pathname + location.search + location.hash);
      return;
    }
  } catch (e0) {}
})();
</script>
</head>
<body>
<div id="body-container">
<div id="page">

  <div id="masthead">
    <table class="masthead-table" width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        <td class="logo-cell" valign="middle">
          <a href="index.php"><img src="assets/YTLite-Logo.png?v=39" alt="<?php echo ytl_h($site); ?>" width="80" height="32" border="0"></a>
        </td>
        <td class="search-cell" valign="middle">
          <form action="index.php" method="get" class="search-form" autocomplete="on">
            <!-- Unique name + autocomplete token so history stays on Pixr Lite only. -->
            <input type="text" name="ytlite_q" value="<?php echo ytl_h($query); ?>" class="search-input"
              autocomplete="ytlite-search" autocapitalize="off" autocorrect="off" spellcheck="false"
              data-lpignore="true" data-1p-ignore="true" data-form-type="other" role="searchbox"
              placeholder="Search" title="Search <?php echo ytl_h($site); ?>">
            <?php if ($activeCat !== '' && $activeCat !== 'all'): ?>
              <input type="hidden" name="cat" value="<?php echo ytl_h($activeCat); ?>">
            <?php endif; ?>
            <button type="submit" class="search-btn" title="Search">Search</button>
          </form>
        </td>
        <td class="masthead-links" valign="middle">
          <a class="lite-btn" href="lite/index.php">Lite</a>
          <a class="lite-btn masthead-extra" href="settings.php" title="External servers">Settings</a>
        </td>
        <td class="signin-cell" align="right" valign="middle">
          <?php if ($user !== null): ?>
            <span class="signin-user" title="<?php echo ytl_h($user['email'] !== '' ? $user['email'] : $user['name']); ?>">
              <?php
                $picSrc = ytl_user_picture_src($user);
                $initials = ytl_user_initials($user);
                $displayName = $user['name'] !== '' ? $user['name'] : $user['email'];
              ?>
              <?php if ($picSrc !== ''): ?>
                <span class="signin-avatar-link">
                  <img class="signin-avatar" src="<?php echo ytl_h($picSrc); ?>" alt="" width="28" height="28" referrerpolicy="no-referrer"
                    onerror="this.style.display='none';var s=document.getElementById('signin-avatar-fb');if(s)s.style.display='inline-block';">
                  <span class="signin-avatar-fallback" id="signin-avatar-fb" style="display:none;"><?php echo ytl_h($initials); ?></span>
                </span>
              <?php else: ?>
                <span class="signin-avatar-link">
                  <span class="signin-avatar-fallback"><?php echo ytl_h($initials); ?></span>
                </span>
              <?php endif; ?>
              <span class="signin-name"><?php echo ytl_h($displayName); ?></span>
            </span>
            <a class="lite-btn lite-btn-out" href="logout.php" id="ytl-signout-btn">Sign out</a>
          <?php else: ?>
            <a class="lite-btn" href="tv_signin.php" title="Sign in with Google">Sign in</a>
          <?php endif; ?>
        </td>
      </tr>
    </table>
  </div>

  <?php if ($user !== null): ?>
  <div id="ytl-signout-overlay" class="ytl-lite-overlay" style="display:none;" aria-hidden="true">
    <div class="ytl-lite-popup" role="dialog" aria-labelledby="ytl-signout-title">
      <div class="ytl-lite-popup-title" id="ytl-signout-title">Sign out</div>
      <p class="ytl-lite-popup-text">Sign out of your Google account on Pixr Lite?</p>
      <div class="ytl-lite-popup-actions">
        <button type="button" class="yt-btn" id="ytl-signout-no">Cancel</button>
        <a class="yt-btn yt-btn-danger" href="logout.php" id="ytl-signout-yes">Sign out</a>
      </div>
    </div>
  </div>
  <script type="text/javascript">
  (function () {
    var overlay = document.getElementById('ytl-signout-overlay');
    var openBtn = document.getElementById('ytl-signout-btn');
    var noBtn = document.getElementById('ytl-signout-no');
    if (!overlay || !openBtn) return;
    function show() {
      overlay.style.display = 'block';
      overlay.setAttribute('aria-hidden', 'false');
    }
    function hide() {
      overlay.style.display = 'none';
      overlay.setAttribute('aria-hidden', 'true');
    }
    openBtn.onclick = function (e) {
      if (e && e.preventDefault) e.preventDefault();
      show();
      return false;
    };
    if (noBtn) noBtn.onclick = hide;
    overlay.onclick = function (e) {
      if (e.target === overlay) hide();
    };
    document.onkeydown = function (e) {
      e = e || window.event;
      if ((e.key === 'Escape' || e.keyCode === 27) && overlay.style.display !== 'none') hide();
    };
  })();
  </script>
  <?php endif; ?>

  <div id="guide-bar">
    <ul class="guide-tabs">
      <?php foreach (ytl_guide_tabs() as $tab):
          // Accueil = home (no guide=); other tabs browse by guide id.
          if ($tab['id'] === 'home') {
              $href = 'index.php';
              $cls = (($activeGuide === '' || $activeGuide === 'home')
                  && ($activeCat === 'all' || $activeCat === '')) ? 'active' : '';
          } else {
              $href = ytl_url('index.php', ['guide' => $tab['id']]);
              $cls = ($activeGuide === $tab['id']) ? 'active' : '';
          }
          ?>
        <li class="<?php echo $cls; ?>"><a href="<?php echo ytl_h($href); ?>"><?php echo ytl_h($tab['label']); ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <table id="content-layout" width="100%" cellpadding="0" cellspacing="0" border="0">
  <tr>
  <?php if ($showSidebar): ?>
    <td id="guide" valign="top">
      <ul class="guide-menu">
        <?php
        $inYou = false;
        $shortYou = [
            'your_channel' => 'Chaîne',
            'history' => 'Hist.',
            'playlists' => 'Lists',
            'watch_later' => 'Plus tard',
            'liked' => "J'aime",
        ];
        foreach (ytl_categories() as $cat):
            $type = (string) ($cat['type'] ?? 'link');
            if ($type === 'you_header') {
                if ($user === null) {
                    continue;
                }
                $inYou = true;
                $youName = trim((string) ($user['name'] !== '' ? $user['name'] : $user['email']));
                $picSrc = ytl_user_picture_src($user);
                $initials = ytl_user_initials($user);
                echo '<li class="guide-you-block">';
                echo '<div class="guide-you-head">';
                if ($picSrc !== '') {
                    echo '<img class="guide-you-avatar" src="' . ytl_h($picSrc) . '" alt="" width="28" height="28" referrerpolicy="no-referrer">';
                } else {
                    echo '<span class="guide-you-avatar guide-you-avatar-fb">' . ytl_h($initials) . '</span>';
                }
                echo '<div class="guide-you-meta">';
                echo '<div class="guide-you-label">You</div>';
                if ($youName !== '') {
                    echo '<div class="guide-you-name">' . ytl_h($youName) . '</div>';
                }
                echo '</div></div><ul class="guide-you-list">';
                continue;
            }
            if ($type === 'browse_header') {
                if ($inYou) {
                    echo '</ul></li>';
                    $inYou = false;
                }
                echo '<li class="guide-section guide-section-browse">' . ytl_h((string) $cat['label']) . '</li>';
                continue;
            }
            if ($type === 'you' && $user === null) {
                continue;
            }
            if ($cat['id'] === 'all') {
                $href = 'index.php';
            } else {
                $href = ytl_url('index.php', ['cat' => $cat['id']]);
            }
            $cls = ($activeCat === $cat['id']) ? 'active' : '';
            if ($type === 'you') {
                $cls = trim($cls . ' guide-you');
            }
            $short = $shortYou[$cat['id']] ?? '';
            ?>
          <li class="<?php echo $cls; ?>"><a href="<?php echo ytl_h($href); ?>"><?php if ($short !== ''): ?><span class="guide-label-full"><?php echo ytl_h($cat['label']); ?></span><span class="guide-label-short"><?php echo ytl_h($short); ?></span><?php else: ?><?php echo ytl_h($cat['label']); ?><?php endif; ?></a></li>
        <?php endforeach;
        if ($inYou) {
            echo '</ul></li>';
        }
        ?>
      </ul>
    </td>
  <?php endif; ?>
    <td id="content" valign="top">
<?php
}

function ytl_layout_end(?string $instance = null, ?string $backend = null): void
{
    ?>
    </td>
  </tr>
  </table>

</div>
</div>
</body>
</html>
<?php
}

function ytl_layout_end_from_client(YtlApi $client): void
{
    ytl_layout_end($client->getActiveInstance(), $client->getActiveBackend());
}

function ytl_render_video_card(array $v): void
{
    $id = (string) ($v['videoId'] ?? '');
    if ($id === '') {
        return;
    }
    $title = (string) ($v['title'] ?? 'Untitled');
    $author = (string) ($v['author'] ?? '');
    $authorId = (string) ($v['authorId'] ?? '');
    $views = isset($v['viewCount']) ? InvidiousClient::formatViews($v['viewCount']) : '';
    $len = isset($v['lengthSeconds']) ? InvidiousClient::formatDuration((int) $v['lengthSeconds']) : '';
    $published = (string) ($v['publishedText'] ?? '');
    $thumb = ytl_thumb_url($v);
    $watch = ytl_url('watch.php', ['v' => $id]);
    $channel = $authorId !== '' ? ytl_url('channel.php', ['id' => $authorId]) : '#';
    $shortTitle = strlen($title) > 55 ? substr($title, 0, 52) . '...' : $title;
    ?>
    <div class="video-cell">
      <div class="thumb-wrap">
        <a href="<?php echo ytl_h($watch); ?>">
          <img src="<?php echo ytl_h($thumb); ?>" alt="" width="186" height="105" border="0" class="thumb" loading="lazy" decoding="async">
        </a>
        <?php if ($len !== '' && $len !== '0:00'): ?>
          <span class="video-time"><?php echo ytl_h($len); ?></span>
        <?php endif; ?>
      </div>
      <div class="video-info">
        <a class="video-title" href="<?php echo ytl_h($watch); ?>" title="<?php echo ytl_h($title); ?>"><?php echo ytl_h($shortTitle); ?></a>
        <div class="video-meta">
          <?php if ($views !== ''): ?><?php echo ytl_h($views); ?> views<?php endif; ?>
          <?php if ($views !== '' && $published !== ''): ?> | <?php endif; ?>
          <?php if ($published !== ''): ?><?php echo ytl_h($published); ?><?php endif; ?>
        </div>
        <?php if ($author !== ''): ?>
          <div class="video-author"><a href="<?php echo ytl_h($channel); ?>"><?php echo ytl_h($author); ?></a></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

function ytl_is_live_item(array $v): bool
{
    if (!empty($v['liveNow']) || !empty($v['isLive']) || !empty($v['isLiveContent']) || !empty($v['isUpcoming'])) {
        return true;
    }
    $title = (string) ($v['title'] ?? '');
    $author = (string) ($v['author'] ?? '');
    $published = (string) ($v['publishedText'] ?? '');
    $len = (int) ($v['lengthSeconds'] ?? -1);

    if (str_contains($title, '🔴') || preg_match('/\bLIVE\b/i', $title)) {
        return true;
    }
    // YouTube live cards often say "1.2K watching" instead of a publish date.
    if (preg_match('/\bwatching\b/i', $published) || preg_match('/\bwatching\b/i', (string) ($v['viewCountText'] ?? ''))) {
        return true;
    }
    // Premiere / upcoming
    if (preg_match('/\bpremiere\b/i', $title) || preg_match('/\bpremier(?:e|ing)\b/i', $published)) {
        return true;
    }
    // "Kai Cenat Live", channel names ending in Live + no duration
    if ($len <= 0 && (preg_match('/\bis\s+Live\b/i', $title) || preg_match('/\bLive\s*$/i', $author))) {
        return true;
    }
    // Zero-length + "live" in title (common when badge parsing missed LIVE overlay)
    if ($len <= 0 && preg_match('/\blive\b/i', $title)) {
        return true;
    }
    // Explicit livestream type from Piped / Invidious
    $type = strtolower((string) ($v['type'] ?? ''));
    if ($type === 'livestream' || $type === 'live') {
        return true;
    }
    return false;
}

/** Merge video lists, unique by videoId, drop empties. */
function ytl_merge_videos(array ...$lists): array
{
    $out = [];
    $seen = [];
    foreach ($lists as $list) {
        foreach ($list as $v) {
            if (!is_array($v)) {
                continue;
            }
            $id = (string) ($v['videoId'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $v;
        }
    }
    return $out;
}

/** @param list<array> $videos */
function ytl_filter_videos(array $videos, bool $hideLive = true): array
{
    if (!$hideLive) {
        return $videos;
    }
    $out = [];
    foreach ($videos as $v) {
        if (!is_array($v)) {
            continue;
        }
        if (ytl_is_live_item($v)) {
            continue;
        }
        $out[] = $v;
    }
    return $out;
}

function ytl_render_video_grid(array $videos, int $cols = 4): void
{
    // $cols kept for callers; real column count is CSS (grid), no float clearfix holes.
    unset($cols);
    $hideLive = !empty(ytl_config()['hide_live']);
    if ($hideLive) {
        $videos = ytl_filter_videos($videos, true);
    }
    echo '<div class="video-grid">';
    foreach ($videos as $v) {
        if (!is_array($v)) {
            continue;
        }
        if (($v['type'] ?? 'video') !== 'video' && empty($v['videoId'])) {
            continue;
        }
        ytl_render_video_card($v);
    }
    echo '</div>';
}

function ytl_render_video_row(array $v): void
{
    $id = (string) ($v['videoId'] ?? '');
    if ($id === '') {
        return;
    }
    $title = (string) ($v['title'] ?? 'Untitled');
    $author = (string) ($v['author'] ?? '');
    $authorId = (string) ($v['authorId'] ?? '');
    $views = isset($v['viewCount']) ? InvidiousClient::formatViews($v['viewCount']) : '';
    $len = isset($v['lengthSeconds']) ? InvidiousClient::formatDuration((int) $v['lengthSeconds']) : '';
    $published = (string) ($v['publishedText'] ?? '');
    $thumb = ytl_thumb_url($v);
    $watch = ytl_url('watch.php', ['v' => $id]);
    $channel = $authorId !== '' ? ytl_url('channel.php', ['id' => $authorId]) : '#';
    ?>
    <tr class="video-row">
      <td class="thumb-cell" valign="top">
        <div class="thumb-wrap thumb-wrap-sm">
          <a href="<?php echo ytl_h($watch); ?>">
            <img src="<?php echo ytl_h($thumb); ?>" alt="" width="186" height="105" border="0" class="thumb" loading="lazy" decoding="async">
          </a>
          <?php if ($len !== '' && $len !== '0:00'): ?>
            <span class="video-time"><?php echo ytl_h($len); ?></span>
          <?php endif; ?>
        </div>
      </td>
      <td class="meta-cell" valign="top">
        <a class="video-title" href="<?php echo ytl_h($watch); ?>"><?php echo ytl_h($title); ?></a>
        <div class="video-meta">
          <?php if ($author !== ''): ?>
            by <a href="<?php echo ytl_h($channel); ?>"><?php echo ytl_h($author); ?></a>
          <?php endif; ?>
          <?php if ($views !== ''): ?>
            &nbsp;&middot;&nbsp; <?php echo ytl_h($views); ?> views
          <?php endif; ?>
          <?php if ($published !== ''): ?>
            &nbsp;&middot;&nbsp; <?php echo ytl_h($published); ?>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php
}

function ytl_render_video_table(array $videos): void
{
    echo '<table class="results" width="100%" cellpadding="6" cellspacing="0" border="0">';
    foreach ($videos as $v) {
        if (!is_array($v)) {
            continue;
        }
        $type = $v['type'] ?? 'video';
        if ($type !== 'video' && empty($v['videoId'])) {
            continue;
        }
        ytl_render_video_row($v);
    }
    echo '</table>';
}

/**
 * Real player: Plyr — poster-only until the user clicks Play (no chrome flash).
 */
function ytl_render_classic_player(string $playMode, string $playUrl, string $mime, string $posterUrl, string $title, string $videoId): void
{
    // Deferred src (#ext-… / empty) is filled by watch.php JS — still show the player chrome.
    $deferred = $playMode === 'mp4' && ($playUrl === '' || str_starts_with($playUrl, '#'));
    $showMp4 = $playMode === 'mp4' && ($playUrl !== '' || $deferred);
    ?>
  <link rel="stylesheet" type="text/css" href="assets/plyr/plyr.css">
  <div class="classic-player" id="movie_player">
    <div class="classic-player-screen">
      <?php if ($showMp4): ?>
        <div class="classic-poster" id="watch-poster">
          <?php if ($posterUrl !== ''): ?>
            <img src="<?php echo ytl_h($posterUrl); ?>" alt="" width="640" height="360" border="0">
          <?php else: ?>
            <div class="classic-poster-empty">&nbsp;</div>
          <?php endif; ?>
          <div class="classic-big-play">
            <a class="classic-play-btn" href="#" id="watch-start" onclick="return false;">&#9658; Play</a>
          </div>
        </div>
        <div id="watch-player-wrap" style="display:none;">
          <video id="watch-video" playsinline preload="<?php echo $deferred ? 'none' : 'auto'; ?>" referrerpolicy="no-referrer"
                 poster="<?php echo ytl_h($posterUrl); ?>">
            <?php if (!$deferred && $playUrl !== ''): ?>
            <source src="<?php echo ytl_h($playUrl); ?>" type="<?php echo ytl_h($mime); ?>">
            <?php endif; ?>
          </video>
        </div>
      <?php elseif ($playMode === 'hls' && $playUrl !== ''): ?>
        <div class="classic-poster">
          <?php if ($posterUrl !== ''): ?>
            <img src="<?php echo ytl_h($posterUrl); ?>" alt="" width="640" height="360" border="0">
          <?php endif; ?>
          <div class="classic-big-play">
            <a class="classic-play-btn" href="<?php echo ytl_h($playUrl); ?>">&#9658; Open in VLC / system player</a>
          </div>
        </div>
      <?php else: ?>
        <div class="classic-poster">
          <?php if ($posterUrl !== ''): ?>
            <img src="<?php echo ytl_h($posterUrl); ?>" alt="" width="640" height="360" border="0">
          <?php else: ?>
            <div class="classic-poster-empty">&nbsp;</div>
          <?php endif; ?>
          <div class="classic-big-play">
            <div class="classic-unavailable">Impossible de lire cette vidéo pour le moment.</div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($showMp4): ?>
  <script src="assets/plyr/plyr.min.js"></script>
  <script type="text/javascript">
  (function () {
    var start = document.getElementById('watch-start');
    var poster = document.getElementById('watch-poster');
    var wrap = document.getElementById('watch-player-wrap');
    var started = false;
    var waitingSrc = false;
    var plyrPlayer = null;
    function hasPlayableSrc(v) {
      if (!v) return false;
      var srcEl = v.querySelector('source');
      var url = (srcEl && srcEl.getAttribute('src')) || v.currentSrc || v.src || '';
      if (!url) return false;
      if (url.charAt(0) === '#') return false;
      if (url.indexOf('javascript:') === 0) return false;
      return true;
    }
    function kickPlay(player, v0, asMuted) {
      try {
        if (v0 && (!v0.volume || v0.volume < 0.05)) v0.volume = 1;
        if (asMuted) {
          if (v0) { v0.muted = true; v0.setAttribute('muted', 'muted'); }
          if (player) player.muted = true;
        } else {
          if (v0) { v0.muted = false; v0.defaultMuted = false; v0.removeAttribute('muted'); }
          if (player) { player.muted = false; player.volume = 1; }
        }
      } catch (ePrep) {}
      var p = null;
      try {
        p = player ? player.play() : (v0 && v0.play());
      } catch (ePlay) {
        if (!asMuted) return kickPlay(player, v0, true);
        return null;
      }
      if (p && p.catch) {
        p.catch(function () {
          if (!asMuted) kickPlay(player, v0, true);
        });
      }
      return p;
    }
    function startPlayer() {
      var v0 = document.getElementById('watch-video');
      if (!hasPlayableSrc(v0)) return false;
      // Already inited: user re-clicked — just play again (unmuted; click = gesture).
      if (started) {
        if (poster) poster.style.display = 'none';
        if (wrap) wrap.style.display = 'block';
        kickPlay(plyrPlayer, v0, false);
        return false;
      }
      started = true;
      if (poster) poster.style.display = 'none';
      if (wrap) wrap.style.display = 'block';
      // Click is a user gesture → prefer sound. Fallback to muted only if blocked.
      try {
        v0.muted = false;
        v0.defaultMuted = false;
        v0.removeAttribute('muted');
        if (!v0.volume || v0.volume < 0.05) v0.volume = 1;
      } catch (eM) {}
      if (!window.Plyr) {
        try { v0.setAttribute('controls', 'controls'); } catch (eC) {}
        kickPlay(null, v0, false);
        return false;
      }
      try {
        plyrPlayer = new Plyr('#watch-video', {
          iconUrl: 'assets/plyr/plyr.svg',
          controls: ['play', 'progress', 'current-time', 'mute', 'volume', 'settings', 'fullscreen'],
          settings: ['speed'],
          autoplay: false,
          muted: false,
          volume: 1,
          hideControls: true,
          keyboard: { focused: true, global: false },
          tooltips: { controls: true, seek: true },
          fullscreen: { enabled: true, fallback: true, iosNative: true }
        });
        plyrPlayer.on('ready', function () {
          kickPlay(plyrPlayer, document.getElementById('watch-video'), false);
          bindMediaSession();
        });
      } catch (e) {
        kickPlay(null, v0, false);
        bindMediaSession();
      }
      return false;
    }
    function bindMediaSession() {
      try {
        if (!('mediaSession' in navigator)) return;
        var v0 = document.getElementById('watch-video');
        var title = <?php echo json_encode($title !== '' ? $title : 'Video', JSON_UNESCAPED_UNICODE); ?>;
        var vid = <?php echo json_encode($videoId); ?>;
        var thumb = <?php echo json_encode($posterUrl !== '' ? $posterUrl : ('https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg')); ?>;
        // Prefer absolute same-origin artwork; fall back to YouTube thumb (not the app icon).
        var art = thumb;
        if (art && art.charAt(0) === '/') {
          art = location.origin + art;
        }
        navigator.mediaSession.metadata = new MediaMetadata({
          title: title || 'YouTube Lite',
          artist: 'Pixr YouTube Lite',
          album: 'pixr.dev/ytlite',
          artwork: [
            { src: art, sizes: '480x360', type: 'image/jpeg' },
            { src: location.origin + '/ytlite/favicon-96x96.png', sizes: '96x96', type: 'image/png' }
          ]
        });
        navigator.mediaSession.setActionHandler('play', function () { kickPlay(plyrPlayer, v0, false); });
        navigator.mediaSession.setActionHandler('pause', function () {
          try { if (plyrPlayer) plyrPlayer.pause(); else if (v0) v0.pause(); } catch (eP) {}
        });
        // Keep lock-screen “open app” on this watch URL (pixr.dev), not another host.
        try {
          if (vid && location.hostname.indexOf('pixr.dev') >= 0) {
            history.replaceState(null, '', location.pathname + location.search);
          }
        } catch (eH) {}
      } catch (eMs) {}
    }
    function boot() {
      if (waitingSrc) return false;
      if (started) return startPlayer();
      var v0 = document.getElementById('watch-video');
      var srcEl = v0 && v0.querySelector('source');
      var u = (srcEl && srcEl.getAttribute('src')) || '';
      var needFreshTunnel = !!window.__ytlNeedTunnel
        || !hasPlayableSrc(v0)
        || (u.indexOf('https://') === 0 && u.indexOf('/tunnel') >= 0);
      // Ext/Cobalt one-shot: mint+load only on Play (never reuse a burned tunnel).
      if (needFreshTunnel && typeof window.__ytlEnsurePlayableSrc === 'function') {
        waitingSrc = true;
        if (start) start.innerHTML = '…';
        window.__ytlEnsurePlayableSrc().then(function () {
          waitingSrc = false;
          if (start) start.innerHTML = '&#9658; Play';
          startPlayer();
        }).catch(function () {
          waitingSrc = false;
          if (start) start.innerHTML = '&#9658; Play';
          if (typeof window.__ytlResolveExt === 'function') {
            Promise.resolve(window.__ytlResolveExt()).then(function () { boot(); });
          }
        });
        return false;
      }
      if (!hasPlayableSrc(v0)) {
        if (typeof window.__ytlResolveExt === 'function') {
          waitingSrc = true;
          if (start) start.innerHTML = '…';
          Promise.resolve(window.__ytlResolveExt()).then(function () {
            waitingSrc = false;
            if (start) start.innerHTML = '&#9658; Play';
            boot();
          }).catch(function () {
            waitingSrc = false;
            if (start) start.innerHTML = '&#9658; Play';
          });
        }
        return false;
      }
      // Local media.php / blob: ensure loaded then play.
      if (v0 && (u.indexOf('blob:') === 0 || u.indexOf('media.php') >= 0)) {
        try {
          if (!v0.currentSrc || v0.readyState < 1) v0.load();
        } catch (eLoad) {}
      }
      return startPlayer();
    }
    if (start) start.onclick = function (e) {
      if (e && e.preventDefault) e.preventDefault();
      boot();
      return false;
    };
    if (poster) poster.onclick = function (e) {
      if (e && e.target && e.target.tagName === 'A') return;
      boot();
    };
    // Local media.php: prefetch under the poster so Play is not a second silent wait.
    (function warmLocal() {
      var v0 = document.getElementById('watch-video');
      if (!v0 || !hasPlayableSrc(v0)) return;
      var srcEl = v0.querySelector('source');
      var u = (srcEl && srcEl.getAttribute('src')) || '';
      if (u.indexOf('media.php') < 0 && u.indexOf('blob:') !== 0) return;
      if (start) start.innerHTML = '…';
      try { v0.preload = 'auto'; v0.load(); } catch (eW) {}
      var n = 0;
      var warm = setInterval(function () {
        n += 1;
        if ((v0.readyState || 0) >= 3 || n > 120) {
          clearInterval(warm);
          if (start && !started) start.innerHTML = '&#9658; Play';
        }
      }, 250);
    })();
    // Coming from prepare @ 100%: start immediately (no second “Play” wait).
    (function autoFromPrepare() {
      try {
        var vid = <?php echo json_encode($videoId); ?>;
        if (!window.sessionStorage || !vid) return;
        if (sessionStorage.getItem('ytl_autoplay_' + vid) !== '1') return;
        sessionStorage.removeItem('ytl_autoplay_' + vid);
        setTimeout(function () { boot(); }, 60);
      } catch (eAuto) {}
    })();
  })();
  </script>
  <?php endif; ?>
    <?php
}
