<?php

require_once __DIR__ . '/invidious.php';
require_once __DIR__ . '/piped.php';
require_once __DIR__ . '/innertube.php';
require_once __DIR__ . '/oembed.php';
require_once __DIR__ . '/ytdlp.php';

/**
 * Facade: Piped browse, Innertube/yt-dlp streams, oEmbed metadata fallback.
 */
class YtlApi
{
    private array $config;
    private ?PipedClient $piped = null;
    private ?InvidiousClient $invidious = null;
    private ?InnertubeClient $innertube = null;
    private ?OEmbedClient $oembed = null;
    private ?YtDlpClient $ytdlp = null;
    private ?string $activeInstance = null;
    private ?string $activeBackend = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getActiveInstance(): ?string
    {
        return $this->activeInstance;
    }

    public function getActiveBackend(): ?string
    {
        return $this->activeBackend;
    }

    public function search(string $query, int $page = 1): array
    {
        foreach ($this->browseBackends() as $name) {
            $client = $this->client($name);
            if ($client === null) {
                continue;
            }
            $rows = $client->search($query, $page);
            if (count($rows) > 0) {
                $this->remember($name, $client->getActiveInstance());
                return $rows;
            }
        }
        return [];
    }

    public function trending(?string $region = null): array
    {
        foreach ($this->browseBackends() as $name) {
            $client = $this->client($name);
            if ($client === null) {
                continue;
            }
            $rows = $name === 'piped'
                ? $client->trending($region ?: 'US')
                : $client->trending($region);
            if (count($rows) > 0) {
                $this->remember($name, $client->getActiveInstance());
                return $rows;
            }
        }
        return [];
    }

    /**
     * All Categories / plain home = logged-out style popular feed (no account cookies).
     * Uses Invidious /popular (mainstream), not Piped "trending" (often niche livestreams).
     *
     * @return list<array<string,mixed>>
     */
    public function guestHomeFeed(int $limit = 40): array
    {
        $pool = max($limit, 20);
        // Fresh cache (15 min) — home must stay instant.
        $cached = $this->readFeedCache('guest_home', $pool, 900);
        if ($cached !== null) {
            return array_slice($cached, 0, $limit);
        }
        // Stale OK up to 2h. Soft-refresh with a tiny budget; never wait on Piped.
        $stale = $this->readFeedCacheAllowStale('guest_home', $pool, 7200);
        $budget = $stale !== null ? 2 : 4;

        $rows = [];
        try {
            $fast = new InvidiousClient(array_merge($this->config, ['http_timeout' => $budget]));
            $rows = $fast->popular();
            if (count($rows) > 0 && method_exists($fast, 'getActiveInstance')) {
                $this->remember('invidious', $fast->getActiveInstance());
            }
        } catch (Throwable $e) {
            $rows = [];
        }
        // Trending fallback only on cold miss (no stale to show).
        if (count($rows) === 0 && $stale === null) {
            try {
                $fast = new InvidiousClient(array_merge($this->config, ['http_timeout' => 3]));
                $rows = $this->filterGuestFriendly($fast->trending('US'));
            } catch (Throwable $e) {
                $rows = [];
            }
        }
        $rows = array_slice($rows, 0, $pool);
        if (count($rows) > 0) {
            $this->writeFeedCache('guest_home', $rows);
            return array_slice($rows, 0, $limit);
        }
        if ($stale !== null) {
            return array_slice($stale, 0, $limit);
        }
        return [];
    }

    /**
     * Live Accueil-style feed when cookie personalized Home is unavailable.
     * Prefers trending (distinct from All Categories /popular).
     *
     * @return list<array<string,mixed>>
     */
    public function accueilFeed(int $limit = 40): array
    {
        $pool = max($limit, 28);
        $cached = $this->readFeedCache('accueil_home', $pool, 120);
        if ($cached !== null) {
            return $this->rotateFeedWindow($cached, $limit, 20);
        }
        $stale = $this->readFeedCacheAllowStale('accueil_home', $pool, 3600);
        $budget = $stale !== null ? 2 : 4;
        // Invidious only — skip Piped trending (known OVH hang).
        $rows = [];
        try {
            $fast = new InvidiousClient(array_merge($this->config, ['http_timeout' => $budget]));
            $rows = $this->filterGuestFriendly($fast->trending('US'));
            if (count($rows) === 0 && $stale === null) {
                $rows = $fast->popular();
            }
            if (count($rows) > 0 && method_exists($fast, 'getActiveInstance')) {
                $this->remember('invidious', $fast->getActiveInstance());
            }
        } catch (Throwable $e) {
            $rows = [];
        }
        $rows = array_slice($rows, 0, $pool);
        if (count($rows) > 0) {
            $this->writeFeedCache('accueil_home', $rows);
            return array_slice($rows, 0, $limit);
        }
        if ($stale !== null) {
            return $this->rotateFeedWindow($stale, $limit, 20);
        }
        return [];
    }

    /**
     * Invidious popular across instances (true guest/popular homepage feel).
     *
     * @return list<array<string,mixed>>
     */
    public function popular(): array
    {
        $client = $this->client('invidious');
        if ($client instanceof InvidiousClient) {
            $rows = $client->popular();
            if (count($rows) > 0) {
                $this->remember('invidious', $client->getActiveInstance());
                return $rows;
            }
        }
        return [];
    }

    /**
     * Drop low-view / live junk when falling back to trending APIs.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function filterGuestFriendly(array $rows): array
    {
        $out = [];
        foreach ($rows as $v) {
            if (!is_array($v)) {
                continue;
            }
            if (!empty($v['liveNow'])) {
                continue;
            }
            $title = (string) ($v['title'] ?? '');
            if ($title !== '' && (str_contains($title, '🔴') || preg_match('/\bLIVE\b/i', $title))) {
                continue;
            }
            $views = (int) ($v['viewCount'] ?? $v['views'] ?? 0);
            $len = (int) ($v['lengthSeconds'] ?? 0);
            if ($views > 0 && $views < 50000) {
                continue;
            }
            if ($len > 0 && $len < 60) {
                continue;
            }
            $out[] = $v;
        }
        return $out;
    }

    /**
     * Logged-in YouTube homepage recommendations (shared server cookie = test account).
     * Short TTL so a reload can pick up a fresher homepage; YouTube itself rarely reshuffles hard.
     *
     * @return list<array<string,mixed>>
     */
    public function homeFeed(int $limit = 40): array
    {
        $pool = max($limit, 40);
        $cached = $this->readFeedCache('rec_home', $pool, 25);
        if ($cached !== null) {
            return $this->rotateFeedWindow($cached, $limit, 20);
        }
        $innertube = $this->client('innertube');
        if ($innertube instanceof InnertubeClient) {
            $rows = $innertube->homeFeed($pool);
            if (count($rows) > 0) {
                $this->remember('innertube', $innertube->getActiveInstance());
                $this->writeFeedCache('rec_home', $rows);
                return $this->rotateFeedWindow($rows, $limit, 20);
            }
        }
        return [];
    }

    /**
     * Shared-cookie subscriptions shelf (fallback when Home history is empty).
     *
     * @return list<array<string,mixed>>
     */
    public function subscriptionsFeed(int $limit = 40): array
    {
        $pool = max($limit, 40);
        $cached = $this->readFeedCache('rec_subs', $pool, 60);
        if ($cached !== null) {
            return $this->rotateFeedWindow($cached, $limit, 20);
        }
        $innertube = $this->client('innertube');
        if ($innertube instanceof InnertubeClient) {
            $rows = $innertube->subscriptionsFeed($pool);
            if (count($rows) > 0) {
                $this->remember('innertube', $innertube->getActiveInstance());
                $this->writeFeedCache('rec_subs', $rows);
                return $this->rotateFeedWindow($rows, $limit, 20);
            }
        }
        return [];
    }

    /**
     * Recommended feed for an OAuth user: Bearer → visitor cookie jar (never Pixr shared).
     *
     * @return list<array<string,mixed>>
     */
    public function userHomeFeed(string $accessToken, string $userSub, int $limit = 40): array
    {
        $safeSub = preg_replace('/[^A-Za-z0-9._-]/', '_', $userSub) ?: 'user';
        $cacheKey = 'rec_user_v3_' . $safeSub;
        $pool = max($limit, 40);
        // Short TTL — home should feel like a fresh YouTube Accueil.
        $cached = $this->readFeedCache($cacheKey, $pool, 30);
        if ($cached !== null) {
            return $this->rotateFeedWindow($cached, $limit, 20);
        }
        $innertube = $this->client('innertube');
        if ($innertube instanceof InnertubeClient && trim($accessToken) !== '') {
            $rows = $innertube->homeFeedWithBearer($accessToken, $pool);
            if (count($rows) > 0) {
                $this->remember('innertube', $innertube->getActiveInstance());
                $this->writeFeedCache($cacheKey, $rows);
                return $this->rotateFeedWindow($rows, $limit, 20);
            }
        }
        $visitor = $this->visitorInnertube($userSub);
        if ($visitor instanceof InnertubeClient) {
            $rows = $visitor->homeFeed($pool);
            if (count($rows) === 0) {
                $rows = $visitor->subscriptionsFeed($pool);
            }
            if (count($rows) > 0) {
                $this->remember('innertube', $visitor->getActiveInstance());
                $this->writeFeedCache($cacheKey, $rows);
                return $this->rotateFeedWindow($rows, $limit, 20);
            }
        }
        // Same Pixr YouTube Lite identity: reuse invisible service OAuth (no Account UI / paste).
        if (!function_exists('ytl_user_may_use_shared_youtube_jar')) {
            require_once __DIR__ . '/helpers.php';
        }
        if (ytl_user_may_use_shared_youtube_jar()) {
            $svc = $this->serviceHomeFeed($pool);
            if (count($svc) > 0) {
                $this->writeFeedCache($cacheKey, $svc);
                return $this->rotateFeedWindow($svc, $limit, 20);
            }
        }
        // Data API often rejects TV Bearers; keep as last resort for Cloud tokens.
        if (trim($accessToken) !== '') {
            require_once __DIR__ . '/youtube_api.php';
            $yt = new YtlYoutubeApi($accessToken, (int) ($this->config['http_timeout'] ?? 20));
            $rows = $yt->subscriptionVideos($pool);
            if (count($rows) > 0) {
                $this->writeFeedCache($cacheKey, $rows);
                return array_slice($rows, 0, $limit);
            }
        }
        return [];
    }

    /**
     * Personalized home shelves (YouTube Accueil style).
     *
     * @return list<array{0:string,1:list<array<string,mixed>>}>
     */
    public function userHomeShelves(string $accessToken, string $userSub, int $limit = 48): array
    {
        $safeSub = preg_replace('/[^A-Za-z0-9._-]/', '_', $userSub) ?: 'user';
        $cacheKey = 'rec_user_shelves_v3_' . $safeSub;
        $cached = $this->readFeedCache($cacheKey, $limit, 45);
        if ($cached !== null && isset($cached[0]) && is_array($cached[0]) && isset($cached[0][0], $cached[0][1])) {
            return $cached;
        }
        $innertube = $this->client('innertube');
        if ($innertube instanceof InnertubeClient && trim($accessToken) !== '') {
            $shelves = $innertube->homeShelvesWithBearer($accessToken, $limit);
            if (count($shelves) > 0) {
                $this->remember('innertube', $innertube->getActiveInstance());
                $this->writeFeedCache($cacheKey, $shelves);
                return $shelves;
            }
        }
        $flat = $this->userHomeFeed($accessToken, $userSub, $limit);
        return count($flat) > 0 ? [['Recommended for You', $flat]] : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function userLibraryFeed(string $accessToken, string $userSub, string $kind, int $limit = 40): array
    {
        $safeSub = preg_replace('/[^A-Za-z0-9._-]/', '_', $userSub) ?: 'user';
        $safeKind = preg_replace('/[^a-z_]/', '', $kind) ?: 'history';
        $cacheKey = 'lib_v3_' . $safeKind . '_' . $safeSub;
        $cached = $this->readFeedCache($cacheKey, $limit, 90);
        if ($cached !== null) {
            return $cached;
        }
        $innertube = $this->client('innertube');
        if ($innertube instanceof InnertubeClient && trim($accessToken) !== '') {
            $rows = $innertube->libraryFeedWithBearer($accessToken, $safeKind, $limit);
            if (count($rows) > 0) {
                $this->remember('innertube', $innertube->getActiveInstance());
                $this->writeFeedCache($cacheKey, $rows);
                return $rows;
            }
        }
        $visitor = $this->visitorInnertube($userSub);
        if ($visitor instanceof InnertubeClient) {
            $rows = $visitor->libraryFeedWithCookie($safeKind, $limit);
            if (count($rows) > 0) {
                $this->remember('innertube', $visitor->getActiveInstance());
                $this->writeFeedCache($cacheKey, $rows);
                return $rows;
            }
        }
        // Liked / WL via Data API when Bearer has scopes (no cookie paste).
        if (trim($accessToken) !== '' && in_array($safeKind, ['liked', 'watch_later'], true)) {
            require_once __DIR__ . '/youtube_api.php';
            $yt = new YtlYoutubeApi($accessToken, (int) ($this->config['http_timeout'] ?? 20));
            $rows = $yt->libraryPlaylist($safeKind, $limit);
            if (count($rows) > 0) {
                $this->writeFeedCache($cacheKey, $rows);
                return $rows;
            }
        }
        return [];
    }

    /**
     * Innertube with personal cookies for You / Recommended:
     * 1) silent auto-synced visitor jar (background script)
     * 2) shared Pixr jar only if Sign-in is the Pixr YouTube Lite account
     */
    private function visitorInnertube(string $userSub): ?InnertubeClient
    {
        if (!function_exists('ytl_visitor_cookie_usable')) {
            require_once __DIR__ . '/visitor_cookies.php';
        }
        if (ytl_visitor_cookie_usable($userSub)) {
            $path = ytl_visitor_cookie_path($userSub);
            if ($path !== '' && is_readable($path)) {
                return new InnertubeClient(array_merge($this->config, [
                    'youtube_cookie' => '',
                    'youtube_cookie_file' => $path,
                ]));
            }
        }
        if (!function_exists('ytl_user_may_use_shared_youtube_jar')) {
            require_once __DIR__ . '/helpers.php';
        }
        if (!ytl_user_may_use_shared_youtube_jar()) {
            return null;
        }
        $shared = trim((string) ($this->config['youtube_cookie_file'] ?? ''));
        if ($shared === '' || !is_readable($shared) || @filesize($shared) < 20) {
            return null;
        }
        $client = new InnertubeClient(array_merge($this->config, [
            'youtube_cookie' => '',
            'youtube_cookie_file' => $shared,
        ]));
        // Extra safety: shared jar must resolve to the expected Pixr account.
        if (function_exists('ytl_youtube_cookie_account_ok')) {
            $check = ytl_youtube_cookie_account_ok($client->accountLabel());
            if (empty($check['ok'])) {
                return null;
            }
        }
        return $client;
    }

    /**
     * Invisible server Google (permanent OAuth) homepage — not shown as Sign in.
     * Prefer this over shared Netscape cookies when linked.
     *
     * @return list<array<string,mixed>>
     */
    public function serviceHomeFeed(int $limit = 40): array
    {
        if (!function_exists('ytl_service_access_token')) {
            require_once __DIR__ . '/oauth.php';
        }
        $token = ytl_service_access_token();
        if (!is_string($token) || $token === '') {
            return [];
        }
        $pool = max($limit, 40);
        $cached = $this->readFeedCache('rec_service', $pool, 120);
        if ($cached !== null) {
            return $this->rotateFeedWindow($cached, $limit, 20);
        }
        $stale = $this->readFeedCacheAllowStale('rec_service', $pool, 3600);
        // Soft refresh: keep Recommended responsive when Google is slow from OVH.
        $innertube = $this->client('innertube');
        if ($innertube instanceof InnertubeClient) {
            $rows = $innertube->homeFeedWithBearer($token, $pool);
            if (count($rows) > 0) {
                $this->remember('innertube', $innertube->getActiveInstance());
                $this->writeFeedCache('rec_service', $rows);
                return $this->rotateFeedWindow($rows, $limit, 20);
            }
        }
        if ($stale !== null) {
            return $this->rotateFeedWindow($stale, $limit, 20);
        }
        return [];
    }

    /**
     * Recent videos from the OAuth user's subscriptions (Data API).
     *
     * @return list<array<string,mixed>>
     */
    public function userSubscriptionFeed(string $accessToken, string $userSub, int $limit = 28): array
    {
        $safeSub = preg_replace('/[^A-Za-z0-9._-]/', '_', $userSub) ?: 'user';
        $cacheKey = 'subs_user_' . $safeSub;
        $cached = $this->readFeedCache($cacheKey, $limit, 600);
        if ($cached !== null) {
            return $cached;
        }
        require_once __DIR__ . '/youtube_api.php';
        $yt = new YtlYoutubeApi($accessToken, (int) ($this->config['http_timeout'] ?? 20));
        $rows = $yt->subscriptionVideos($limit);
        if (count($rows) > 0) {
            $this->writeFeedCache($cacheKey, $rows);
        }
        return $rows;
    }

    /**
     * @return array{handle:string,name:string,email:string}
     */
    /**
     * @return array{ok:bool,logged_in:bool,history_off:bool,videos:int,reason:string}
     */
    public function cookieHomeProbe(int $limit = 12): array
    {
        $innertube = $this->client('innertube');
        if ($innertube instanceof InnertubeClient) {
            return $innertube->cookieHomeProbe($limit);
        }
        return [
            'ok' => false,
            'logged_in' => false,
            'history_off' => false,
            'videos' => 0,
            'reason' => 'Innertube unavailable.',
        ];
    }

    public function cookieAccountLabel(): array
    {
        $cached = $this->readJsonCache('acct_label', 21600);
        // Ignore stale caches that only have a bogus display name (e.g. language "Afrikaans").
        $cachedHandle = is_array($cached) ? trim((string) ($cached['handle'] ?? '')) : '';
        $cachedEmail = is_array($cached) ? trim((string) ($cached['email'] ?? '')) : '';
        $cachedName = is_array($cached) ? trim((string) ($cached['name'] ?? '')) : '';
        $cacheUsable = is_array($cached) && ($cachedHandle !== '' || $cachedEmail !== ''
            || ($cachedName !== '' && stripos($cachedName, 'pixr') !== false));
        if ($cacheUsable) {
            return [
                'handle' => $cachedHandle,
                'name' => $cachedName,
                'email' => $cachedEmail,
            ];
        }
        $innertube = $this->client('innertube');
        if ($innertube instanceof InnertubeClient) {
            $label = $innertube->accountLabel();
            if (($label['name'] ?? '') !== '' || ($label['handle'] ?? '') !== '' || ($label['email'] ?? '') !== '') {
                $this->writeJsonCache('acct_label', $label);
            }
            return $label;
        }
        return ['handle' => '', 'name' => '', 'email' => ''];
    }

    /**
     * Shift the visible window every $bucketSec so a reload is less frozen
     * while we keep a short YouTube cache.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function rotateFeedWindow(array $rows, int $limit, int $bucketSec = 20): array
    {
        $n = count($rows);
        if ($n <= $limit) {
            return array_slice($rows, 0, $limit);
        }
        $step = max(1, (int) floor(($n - $limit) / 4));
        $offset = (intdiv(time(), max(5, $bucketSec)) * $step) % max(1, $n - $limit + 1);
        return array_slice($rows, $offset, $limit);
    }

    /** @return list<array<string,mixed>>|null */
    private function readFeedCache(string $key, int $limit, int $ttlSec): ?array
    {
        $data = $this->readJsonCache('feed_' . $key, $ttlSec);
        if ($data === null || !isset($data['videos']) || !is_array($data['videos'])) {
            return null;
        }
        return array_slice($data['videos'], 0, $limit);
    }

    /** Same as readFeedCache but allows a longer max-age (stale-while-revalidate). */
    private function readFeedCacheAllowStale(string $key, int $limit, int $maxAgeSec): ?array
    {
        return $this->readFeedCache($key, $limit, $maxAgeSec);
    }

    /** @param list<array<string,mixed>> $videos */
    private function writeFeedCache(string $key, array $videos): void
    {
        $this->writeJsonCache('feed_' . $key, ['ts' => time(), 'videos' => array_values($videos)]);
    }

    /** @return array<string,mixed>|null */
    private function readJsonCache(string $key, int $ttlSec): ?array
    {
        $path = $this->cacheFilePath($key);
        if ($path === '' || !is_readable($path)) {
            return null;
        }
        if (filemtime($path) + $ttlSec < time()) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $data */
    private function writeJsonCache(string $key, array $data): void
    {
        $path = $this->cacheFilePath($key);
        if ($path === '') {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, json_encode($data));
    }

    private function cacheFilePath(string $key): string
    {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower($key)) ?: 'cache';
        $base = (string) ($this->config['video_cache_dir'] ?? '');
        if ($base === '') {
            $base = dirname(__DIR__) . '/data/videos';
        }
        return rtrim($base, '/\\') . '/.cache/' . $key . '.json';
    }

    private function feedCachePath(string $key): string
    {
        return $this->cacheFilePath('feed_' . $key);
    }

    /**
     * Toutes les URLs progressives (Piped / Invidious / Innertube), les proxys d’abord.
     *
     * @return list<array{url:string,backend:string,height:int,container:string,host:string,proxyish:bool}>
     */
    public function progressiveCandidates(string $videoId, int $preferredHeight = 360): array
    {
        $out = [];
        foreach ($this->watchBackends() as $name) {
            $client = $this->client($name);
            if ($client === null) {
                continue;
            }
            $video = $client->video($videoId);
            if ($video === null) {
                continue;
            }
            foreach ($this->listProgressiveFormats($video) as $f) {
                $url = (string) ($f['url'] ?? '');
                if ($url === '') {
                    continue;
                }
                $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
                $out[] = [
                    'url' => $url,
                    'backend' => $name,
                    'height' => (int) ($f['height'] ?? 0),
                    'container' => strtolower((string) ($f['container'] ?? '')),
                    'host' => $host,
                    'proxyish' => !str_contains($host, 'googlevideo')
                        && !str_contains($host, 'googleusercontent'),
                ];
            }
        }
        usort($out, static function (array $a, array $b) use ($preferredHeight): int {
            if ($a['proxyish'] !== $b['proxyish']) {
                return $a['proxyish'] ? -1 : 1;
            }
            $ma = $a['container'] === 'mp4' ? 0 : 1;
            $mb = $b['container'] === 'mp4' ? 0 : 1;
            if ($ma !== $mb) {
                return $ma <=> $mb;
            }
            return abs($a['height'] - $preferredHeight) <=> abs($b['height'] - $preferredHeight);
        });
        return $out;
    }

    /**
     * Première URL MP4 progressive trouvée (pour cache local), sans filtre stream_ok.
     * @return array{url:string,backend:string,video:array}|null
     */
    public function progressiveForCache(string $videoId, int $preferredHeight = 360): ?array
    {
        foreach ($this->watchBackends() as $name) {
            $client = $this->client($name);
            if ($client === null) {
                continue;
            }
            $video = $client->video($videoId);
            if ($video === null) {
                continue;
            }
            $pick = $this->pickProgressiveFormat($video, $preferredHeight);
            if ($pick !== null && !empty($pick['url'])) {
                $this->remember($name, $client->getActiveInstance());
                return [
                    'url' => (string) $pick['url'],
                    'backend' => $name,
                    'video' => $video,
                ];
            }
        }
        return null;
    }

    /**
     * Métadonnées only (titre, auteur, likes…) — n’exige pas un MP4 jouable.
     * Fast path: oEmbed + ReturnYouTubeDislike, then optional Invidious/Piped enrichment.
     */
    public function videoMeta(string $videoId): ?array
    {
        $plainDesc = static function (string $raw): string {
            $raw = str_replace(['<br>', '<br/>', '<br />', '<BR>', '<BR/>', '<BR />'], "\n", $raw);
            $raw = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $raw = preg_replace("/[ \t]+\n/", "\n", $raw) ?? $raw;
            $raw = preg_replace("/\n{3,}/", "\n\n", $raw) ?? $raw;
            return trim($raw);
        };

        $video = null;

        // 1) oEmbed first — ~40ms from OVH; never block the watch page on Piped/Invidious races.
        $oembed = ytl_http_get_json(
            'https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=' . $videoId),
            4
        );
        if (is_array($oembed) && trim((string) ($oembed['title'] ?? '')) !== '') {
            $this->remember('oembed', 'https://www.youtube.com/oembed');
            $authorUrl = trim((string) ($oembed['author_url'] ?? ''));
            $authorId = '';
            if (preg_match('#/(channel|c|user|@)([^/?#]+)#', $authorUrl, $m)) {
                // Prefer UC… channel ids when present in the URL.
                if ($m[1] === 'channel') {
                    $authorId = rawurldecode($m[2]);
                }
            }
            $video = [
                'type' => 'video',
                'title' => (string) $oembed['title'],
                'videoId' => $videoId,
                'description' => '',
                'author' => (string) ($oembed['author_name'] ?? ''),
                'authorId' => $authorId,
                'authorUrl' => $authorUrl,
                'viewCount' => 0,
                'lengthSeconds' => 0,
                'likeCount' => 0,
                'dislikeCount' => -1,
                'recommendedVideos' => [],
                'videoThumbnails' => [[
                    'quality' => 'hqdefault',
                    'url' => 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg',
                    'width' => 480,
                    'height' => 360,
                ]],
            ];
        }

        // 2) Innertube playerDetails — description / channelId when cookies work (short timeouts inside).
        $innertube = $this->client('innertube');
        if ($innertube instanceof InnertubeClient) {
            $details = $innertube->playerDetails($videoId);
            if (is_array($details) && trim((string) ($details['title'] ?? '')) !== '') {
                $this->remember('innertube', 'https://www.youtube.com (playerDetails)');
                if ($video === null) {
                    $video = [
                        'type' => 'video',
                        'title' => (string) $details['title'],
                        'videoId' => $videoId,
                        'description' => '',
                        'author' => '',
                        'authorId' => '',
                        'viewCount' => 0,
                        'lengthSeconds' => 0,
                        'likeCount' => 0,
                        'dislikeCount' => -1,
                        'recommendedVideos' => [],
                        'videoThumbnails' => [[
                            'quality' => 'hqdefault',
                            'url' => 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg',
                            'width' => 480,
                            'height' => 360,
                        ]],
                    ];
                }
                $video['title'] = (string) $details['title'];
                $desc = $plainDesc((string) ($details['description'] ?? ''));
                if ($desc !== '') {
                    $video['description'] = $desc;
                }
                if (trim((string) ($details['author'] ?? '')) !== '') {
                    $video['author'] = (string) $details['author'];
                }
                if (trim((string) ($details['authorId'] ?? '')) !== '') {
                    $video['authorId'] = (string) $details['authorId'];
                }
                if ((int) ($details['viewCount'] ?? 0) > 0) {
                    $video['viewCount'] = (int) $details['viewCount'];
                }
                if ((int) ($details['lengthSeconds'] ?? 0) > 0) {
                    $video['lengthSeconds'] = (int) $details['lengthSeconds'];
                }
            }
        }

        // 3) At most one short Invidious hit for description/related (no Piped, no multi-host stall).
        if ($video === null || trim((string) ($video['description'] ?? '')) === ''
            || !is_array($video['recommendedVideos'] ?? null) || count($video['recommendedVideos']) === 0) {
            $invHosts = [];
            if (function_exists('ytl_settings_get')) {
                foreach ((array) (ytl_settings_get()['meta'] ?? []) as $h) {
                    $h = rtrim(trim((string) $h), '/');
                    if ($h !== '' && str_starts_with($h, 'https://')) {
                        $invHosts[] = $h;
                    }
                }
            }
            foreach ((array) ($this->config['invidious_instances'] ?? []) as $h) {
                $h = rtrim(trim((string) $h), '/');
                if ($h !== '' && str_starts_with($h, 'https://')) {
                    $invHosts[] = $h;
                }
            }
            $invHosts = array_values(array_unique($invHosts));
            if ($invHosts === []) {
                $invHosts = ['https://yt.chocolatemoo53.com'];
            }
            // Try at most 2 hosts — keep meta fast.
            foreach (array_slice($invHosts, 0, 2) as $host) {
                $extra = ytl_http_get_json(rtrim($host, '/') . '/api/v1/videos/' . rawurlencode($videoId), 2);
                if (!is_array($extra) || trim((string) ($extra['title'] ?? '')) === '') {
                    continue;
                }
                $this->remember('invidious', $host);
                if ($video === null) {
                    $video = $extra;
                    $video['description'] = $plainDesc((string) ($extra['description'] ?? ($extra['descriptionHtml'] ?? '')));
                } else {
                    if (trim((string) ($video['description'] ?? '')) === '') {
                        $d = $plainDesc((string) ($extra['description'] ?? ($extra['descriptionHtml'] ?? '')));
                        if ($d !== '') {
                            $video['description'] = $d;
                        }
                    }
                    if (trim((string) ($video['authorId'] ?? '')) === '' && trim((string) ($extra['authorId'] ?? '')) !== '') {
                        $video['authorId'] = (string) $extra['authorId'];
                    }
                    if (trim((string) ($video['author'] ?? '')) === '' && trim((string) ($extra['author'] ?? '')) !== '') {
                        $video['author'] = (string) $extra['author'];
                    }
                    if ((int) ($video['viewCount'] ?? 0) <= 0 && (int) ($extra['viewCount'] ?? 0) > 0) {
                        $video['viewCount'] = (int) $extra['viewCount'];
                    }
                    if ((int) ($video['likeCount'] ?? 0) <= 0 && (int) ($extra['likeCount'] ?? 0) > 0) {
                        $video['likeCount'] = (int) $extra['likeCount'];
                    }
                    if (trim((string) ($video['publishedText'] ?? '')) === '' && trim((string) ($extra['publishedText'] ?? '')) !== '') {
                        $video['publishedText'] = (string) $extra['publishedText'];
                    }
                    if (trim((string) ($video['avatarUrl'] ?? '')) === '') {
                        $av = ytl_normalize_avatar_url(trim((string) ($extra['authorThumbnails'][0]['url'] ?? ($extra['avatarUrl'] ?? ''))));
                        if ($av !== '') {
                            $video['avatarUrl'] = $av;
                            $video['authorThumbnails'] = [['url' => $av, 'width' => 88, 'height' => 88]];
                        }
                    }
                    $rec = $extra['recommendedVideos'] ?? null;
                    if (is_array($rec) && count($rec) > 0) {
                        $video['recommendedVideos'] = $rec;
                    }
                }
                break;
            }
        }

        if ($video === null) {
            return null;
        }

        if (!isset($video['description'])) {
            $video['description'] = '';
        } else {
            $video['description'] = $plainDesc((string) $video['description']);
        }

        if (!empty($video['avatarUrl'])) {
            $video['avatarUrl'] = ytl_normalize_avatar_url((string) $video['avatarUrl']);
        }

        // Likes/dislikes: RYD fills gaps (short timeout — do not stall related).
        if ((int) ($video['likeCount'] ?? 0) <= 0) {
            $ryd = ytl_http_get_json(
                'https://returnyoutubedislikeapi.com/votes?videoId=' . rawurlencode($videoId),
                1
            );
            if (is_array($ryd)) {
                if ((int) ($ryd['likes'] ?? 0) > 0) {
                    $video['likeCount'] = (int) $ryd['likes'];
                }
                if ((int) ($ryd['dislikes'] ?? 0) > 0) {
                    $video['dislikeCount'] = (int) $ryd['dislikes'];
                }
                if ((int) ($video['viewCount'] ?? 0) <= 0 && (int) ($ryd['viewCount'] ?? 0) > 0) {
                    $video['viewCount'] = (int) $ryd['viewCount'];
                }
            }
        }

        // Related: short popular seed only — never race every Invidious instance.
        $rec = $video['recommendedVideos'] ?? null;
        if (!is_array($rec) || count($rec) === 0) {
            $seed = [];
            $pop = ytl_http_get_json('https://yt.chocolatemoo53.com/api/v1/popular?fields=videoId,title,author,videoThumbnails,lengthSeconds,viewCount', 1);
            if (!is_array($pop)) {
                $pop = ytl_http_get_json('https://yt.chocolatemoo53.com/api/v1/popular', 1);
            }
            if (is_array($pop)) {
                foreach ($pop as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $rid = (string) ($row['videoId'] ?? '');
                    if ($rid === '' || $rid === $videoId) {
                        continue;
                    }
                    $seed[] = $row;
                    if (count($seed) >= 12) {
                        break;
                    }
                }
            }
            $video['recommendedVideos'] = $seed;
        }

        // Strip stream URLs from meta payload (player uses local convert cache).
        $video['formatStreams'] = [];
        $video['adaptiveFormats'] = [];
        $video['hlsUrl'] = null;
        return $video;
    }

    public function video(string $videoId): ?array
    {
        $errors = [];
        $fallbackNoProgressive = null;

        foreach ($this->watchBackends() as $name) {
            $client = $this->client($name);
            if ($client === null) {
                continue;
            }
            if ($name === 'ytdlp' && $client instanceof YtDlpClient && !$client->isAvailable()) {
                $errors[] = 'ytdlp: binary not available';
                continue;
            }
            $video = $client->video($videoId);
            if ($video === null) {
                $errors[] = $name . ': ' . (ytl_http_last_error() ?: 'fail');
                continue;
            }
            $formats = $this->listProgressiveFormats($video);
            if (count($formats) > 0) {
                $okFormats = [];
                foreach ($formats as $fmt) {
                    $u = (string) ($fmt['url'] ?? '');
                    if ($u !== '' && ytl_http_stream_ok($u)) {
                        $okFormats[] = $fmt;
                    }
                }
                if (count($okFormats) > 0) {
                    // Keep only streams this server can actually fetch (avoids black player / proxy 403)
                    $video['formatStreams'] = $okFormats;
                    $this->remember($name, $client->getActiveInstance());
                    return $video;
                }
                $errors[] = $name . ': MP4 URL unreachable from server (403)';
                if ($fallbackNoProgressive === null) {
                    $fallbackNoProgressive = [$name, $client->getActiveInstance(), $video];
                }
                continue;
            }
            if ($fallbackNoProgressive === null || (!empty($video['hlsUrl']) && empty($fallbackNoProgressive[2]['hlsUrl']))) {
                $fallbackNoProgressive = [$name, $client->getActiveInstance(), $video];
            }
            $errors[] = $name . ': no progressive MP4';
        }

        if ($fallbackNoProgressive !== null) {
            $this->remember($fallbackNoProgressive[0], $fallbackNoProgressive[1]);
            $video = $fallbackNoProgressive[2];
            if (count($errors) > 0) {
                $video['_extract_errors'] = implode(' | ', $errors);
            }
            return $video;
        }

        $oembed = $this->client('oembed');
        if ($oembed !== null) {
            $video = $oembed->video($videoId);
            if ($video !== null) {
                $this->remember('oembed', $oembed->getActiveInstance());
                if (count($errors) > 0) {
                    $video['_extract_errors'] = implode(' | ', $errors);
                }
                return $video;
            }
        }

        if (count($errors) > 0) {
            ytl_http_last_error(implode(' | ', $errors));
        }
        return null;
    }

    public function channel(string $channelId): ?array
    {
        // Innertube first — Piped/Invidious are often down from OVH; channel browse is fast with cookies.
        foreach (array_merge(['innertube'], $this->browseBackends()) as $name) {
            $client = $this->client($name);
            if ($client === null) {
                continue;
            }
            $ch = $client->channel($channelId);
            if ($ch !== null && (trim((string) ($ch['avatarUrl'] ?? '')) !== '' || trim((string) ($ch['author'] ?? '')) !== '')) {
                $this->remember($name, $client->getActiveInstance());
                return $ch;
            }
        }
        return null;
    }

    public function channelVideos(string $channelId, ?string $continuation = null): array
    {
        foreach ($this->browseBackends() as $name) {
            $client = $this->client($name);
            if ($client === null) {
                continue;
            }
            $bundle = $client->channelVideos($channelId, $continuation);
            $videos = $bundle['videos'] ?? [];
            if (is_array($videos) && count($videos) > 0) {
                $this->remember($name, $client->getActiveInstance());
                return $bundle;
            }
        }
        return ['videos' => [], 'continuation' => null];
    }

    /** @return array{comments:list<array<string,mixed>>,continuation:?string,count:int,disabled:bool,error?:bool} */
    public function comments(string $videoId, ?string $continuation = null): array
    {
        foreach (['piped', 'invidious'] as $name) {
            $client = $this->client($name);
            if (!is_object($client) || !method_exists($client, 'comments')) {
                continue;
            }
            $bundle = $client->comments($videoId, $continuation);
            if (is_array($bundle)) {
                $this->remember($name, $client->getActiveInstance());
                return $bundle;
            }
        }
        return [
            'comments' => [],
            'continuation' => null,
            'count' => 0,
            'disabled' => false,
            'error' => true,
        ];
    }

    public function pickProgressiveFormat(array $video, int $preferredHeight = 360): ?array
    {
        return (new InvidiousClient($this->config))->pickProgressiveFormat($video, $preferredHeight);
    }

    public function listProgressiveFormats(array $video): array
    {
        return (new InvidiousClient($this->config))->listProgressiveFormats($video);
    }

    public function resolveStreamUrl(array $video, ?string $itag, int $preferredHeight): ?array
    {
        return (new InvidiousClient($this->config))->resolveStreamUrl($video, $itag, $preferredHeight);
    }

    /** @return string[] */
    private function browseBackends(): array
    {
        $list = $this->config['backends'] ?? ['piped', 'invidious'];
        return array_values(array_filter($list, static fn($b) => $b === 'piped' || $b === 'invidious'));
    }

    /** @return string[] */
    private function watchBackends(): array
    {
        $list = $this->config['watch_backends']
            ?? ['piped', 'invidious', 'innertube'];
        $allowed = ['innertube', 'ytdlp', 'piped', 'invidious'];
        return array_values(array_filter($list, static fn($b) => in_array($b, $allowed, true)));
    }

    private function client(string $name): PipedClient|InvidiousClient|InnertubeClient|OEmbedClient|YtDlpClient|null
    {
        if ($name === 'piped') {
            if ($this->piped === null) {
                $this->piped = new PipedClient($this->config);
            }
            return $this->piped;
        }
        if ($name === 'invidious') {
            if ($this->invidious === null) {
                $this->invidious = new InvidiousClient($this->config);
            }
            return $this->invidious;
        }
        if ($name === 'innertube') {
            if ($this->innertube === null) {
                $this->innertube = new InnertubeClient($this->config);
            }
            return $this->innertube;
        }
        if ($name === 'oembed') {
            if ($this->oembed === null) {
                $this->oembed = new OEmbedClient($this->config);
            }
            return $this->oembed;
        }
        if ($name === 'ytdlp') {
            if ($this->ytdlp === null) {
                $this->ytdlp = new YtDlpClient($this->config);
            }
            return $this->ytdlp;
        }
        return null;
    }

    private function remember(string $backend, ?string $instance): void
    {
        $this->activeBackend = $backend;
        $this->activeInstance = $instance;
    }
}
