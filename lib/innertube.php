<?php
/* ytl-deploy:2026-11-09T07:25Z-client-cobalt-handoff */
require_once __DIR__ . '/http.php';

/**
 * Direct YouTube Innertube player client (no third-party instance).
 */
class InnertubeClient
{
    private int $timeout;
    private string $cookie;
    private ?string $activeInstance = 'https://youtubei.googleapis.com (innertube)';

    /** Android client API key (public, used by NewPipe / yt-dlp style clients) */
    private const ANDROID_KEY = 'AIzaSyA8eiZmM1FaDVjRy-df2KTyQ_vz_yYM39w';
    private const IOS_KEY = 'AIzaSyB-63vPrdThhKuerbB2N_HA78s19XVKu4Ip';

    public function __construct(array $config)
    {
        $this->timeout = (int) ($config['http_timeout'] ?? 20);
        $this->cookie = trim((string) ($config['youtube_cookie'] ?? ''));
        $cookieFile = (string) ($config['youtube_cookie_file'] ?? '');
        if ($cookieFile !== '' && is_readable($cookieFile)) {
            $fromFile = self::cookieHeaderFromFile($cookieFile);
            if ($fromFile !== '') {
                $this->cookie = $fromFile;
            }
        }
    }

    private static function cookieHeaderFromFile(string $path): string
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return '';
        }
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (!str_contains($raw, "\n") && !str_contains($raw, "\t") && str_contains($raw, '=')) {
            return $raw;
        }
        $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
        $parts = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            // Netscape: HttpOnly cookies are prefixed with "#HttpOnly_"
            $httpOnly = false;
            if (str_starts_with($line, '#HttpOnly_')) {
                $line = substr($line, strlen('#HttpOnly_'));
                $httpOnly = true;
            } elseif (str_starts_with($line, '#')) {
                // Real comments (# Netscape HTTP Cookie File, etc.)
                continue;
            }
            // Single Cookie header line pasted as multi-line leftover
            if (!$httpOnly && str_contains($line, '=') && !str_contains($line, "\t")) {
                $parts[] = rtrim($line, ';');
                continue;
            }
            // Netscape tab-separated: domain flag path secure expiry name value
            $cols = preg_split('/\t/', $line);
            if (is_array($cols) && count($cols) >= 7) {
                $parts[] = $cols[5] . '=' . $cols[6];
            }
        }
        return implode('; ', $parts);
    }

    private static function cookieValue(string $header, string $name): string
    {
        if (preg_match('/(?:^|;\s*)' . preg_quote($name, '/') . '=([^;]*)/', $header, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    public function getActiveInstance(): ?string
    {
        return $this->activeInstance;
    }

    public function search(string $query, int $page = 1): array
    {
        return [];
    }

    public function trending(?string $region = null): array
    {
        return [];
    }

    /**
     * Lightweight WEB player call for title / description / views (no stream parsing).
     * Used when Piped/Invidious omit descriptions.
     *
     * @return array{title:string,description:string,author:string,authorId:string,viewCount:int,lengthSeconds:int}|null
     */
    public function playerDetails(string $videoId): ?array
    {
        if ($videoId === '' || !function_exists('curl_init')) {
            return null;
        }

        // Prefer WEB+cookies on OVH (anonymous player is often blocked from datacenter IPs).
        $clients = [];
        if ($this->cookie !== '') {
            $clients[] = [
                'endpoint' => 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false',
                'clientName' => 'WEB',
                'clientVersion' => '2.20241120.01.00',
                'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'extra' => [],
                'withCookie' => true,
            ];
        }
        $clients[] = [
            'endpoint' => 'https://youtubei.googleapis.com/youtubei/v1/player?key=' . self::ANDROID_KEY,
            'clientName' => 'ANDROID',
            'clientVersion' => '21.26.364',
            'ua' => 'com.google.android.youtube/21.26.364 (Linux; U; Android 11) gzip',
            'extra' => ['androidSdkVersion' => 30, 'osName' => 'Android', 'osVersion' => '11'],
            'withCookie' => false,
        ];
        $clients[] = [
            'endpoint' => 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false',
            'clientName' => 'WEB',
            'clientVersion' => '2.20241120.01.00',
            'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'extra' => [],
            'withCookie' => false,
        ];

        foreach ($clients as $c) {
            $client = array_merge([
                'clientName' => $c['clientName'],
                'clientVersion' => $c['clientVersion'],
                'hl' => 'en',
                'gl' => 'US',
            ], $c['extra']);
            $payload = json_encode([
                'context' => ['client' => $client],
                'videoId' => $videoId,
                'contentCheckOk' => true,
                'racyCheckOk' => true,
            ], JSON_UNESCAPED_SLASHES);
            $headers = [
                'Content-Type: application/json',
                'User-Agent: ' . $c['ua'],
            ];
            if ($c['clientName'] === 'WEB') {
                $headers[] = 'X-YouTube-Client-Name: 1';
                $headers[] = 'X-YouTube-Client-Version: ' . $c['clientVersion'];
                $headers[] = 'Origin: https://www.youtube.com';
                $headers[] = 'Referer: https://www.youtube.com/watch?v=' . rawurlencode($videoId);
            }
            if (!empty($c['withCookie']) && $this->cookie !== '') {
                $headers[] = 'Cookie: ' . $this->cookie;
                $sapisid = self::cookieValue($this->cookie, 'SAPISID');
                if ($sapisid === '') {
                    $sapisid = self::cookieValue($this->cookie, '__Secure-3PAPISID');
                }
                if ($sapisid !== '') {
                    $origin = 'https://www.youtube.com';
                    $ts = (string) time();
                    $headers[] = 'Authorization: SAPISIDHASH ' . $ts . '_' . sha1($ts . ' ' . $sapisid . ' ' . $origin);
                    $headers[] = 'X-Origin: ' . $origin;
                }
            }
            $ch = curl_init($c['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => ytl_http_ssl_verify(),
                CURLOPT_SSL_VERIFYHOST => ytl_http_ssl_verify() ? 2 : 0,
            ]);
            ytl_curl_set_proxy($ch);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (!is_string($body) || $body === '' || $code < 200 || $code >= 400) {
                continue;
            }
            $data = json_decode($body, true);
            if (!is_array($data)) {
                continue;
            }
            $vd = $data['videoDetails'] ?? null;
            if (!is_array($vd) || trim((string) ($vd['title'] ?? '')) === '') {
                continue;
            }
            return [
                'title' => (string) ($vd['title'] ?? ''),
                'description' => (string) ($vd['shortDescription'] ?? ''),
                'author' => (string) ($vd['author'] ?? ''),
                'authorId' => (string) ($vd['channelId'] ?? ''),
                'viewCount' => (int) ($vd['viewCount'] ?? 0),
                'lengthSeconds' => (int) ($vd['lengthSeconds'] ?? 0),
                'isLive' => !empty($vd['isLive']),
                'isLiveContent' => !empty($vd['isLiveContent']),
                'isUpcoming' => !empty($vd['isUpcoming']),
                'liveNow' => !empty($vd['isLive']) || !empty($vd['isLiveContent']),
            ];
        }
        return null;
    }

    /**
     * Logged-out / guest homepage (FEwhat_to_watch) — no cookies.
     * Universal "what YouTube shows a visitor", not account recommendations.
     *
     * @return list<array<string,mixed>>
     */
    public function guestHomeFeed(int $limit = 40): array
    {
        return $this->browseFeed('FEwhat_to_watch', $limit, false, null);
    }

    /**
     * Personalized homepage using the shared server cookie (test account).
     *
     * @return list<array<string,mixed>>
     */
    public function homeFeed(int $limit = 40): array
    {
        if ($this->cookie === '') {
            return [];
        }
        return $this->browseFeed('FEwhat_to_watch', $limit, true, null);
    }

    /**
     * Diagnose why cookie Home / Recommended is empty (logged-out session vs history off).
     *
     * @return array{ok:bool,logged_in:bool,history_off:bool,videos:int,reason:string}
     */
    public function cookieHomeProbe(int $limit = 12): array
    {
        $out = [
            'ok' => false,
            'logged_in' => false,
            'history_off' => false,
            'videos' => 0,
            'reason' => 'No YouTube cookies on the server.',
        ];
        if ($this->cookie === '') {
            return $out;
        }
        $raw = $this->browseRaw('FEwhat_to_watch', true, null);
        if ($raw === null || $raw === '') {
            $out['reason'] = 'YouTube browse failed (network / blocked). Retry later.';
            return $out;
        }
        $loggedIn = (bool) preg_match('/"key":"logged_in","value":"1"/', $raw)
            || (bool) preg_match('/"logged_in","value":"1"/', $raw);
        $historyOff = stripos($raw, 'history is off') !== false
            || (stripos($raw, 'feedNudgeRenderer') !== false && stripos($raw, 'history') !== false);
        $data = json_decode($raw, true);
        $videos = [];
        $seen = [];
        if (is_array($data)) {
            $this->collectHomeVideos($data, $videos, $seen, $limit);
        }
        $count = count($videos);
        $out['logged_in'] = $loggedIn;
        $out['history_off'] = $historyOff;
        $out['videos'] = $count;
        if ($count > 0) {
            $out['ok'] = true;
            $out['reason'] = '';
            return $out;
        }
        if ($historyOff) {
            $out['reason'] = 'YouTube watch history is OFF for this cookie session. Turn it on at youtube.com/feed/history (button must say “Pause” / “Suspendre”), then re-export cookies.';
            return $out;
        }
        if (!$loggedIn) {
            $out['reason'] = 'Cookie session is logged out for Innertube browse. Re-export while signed in as pixryoutubelite@gmail.com (Cookie-Editor → Netscape).';
            return $out;
        }
        $out['reason'] = 'Home feed returned no videos. Re-export fresh cookies with history on.';
        return $out;
    }

    /**
     * Subscriptions feed for the shared cookie account (when Home history is off / empty).
     *
     * @return list<array<string,mixed>>
     */
    public function subscriptionsFeed(int $limit = 40): array
    {
        if ($this->cookie === '') {
            return [];
        }
        return $this->browseFeed('FEsubscriptions', $limit, true, null);
    }

    /**
     * Personalized homepage for an OAuth user (Bearer access token, no shared cookie).
     *
     * @return list<array<string,mixed>>
     */
    public function homeFeedWithBearer(string $accessToken, int $limit = 40): array
    {
        $token = trim($accessToken);
        if ($token === '') {
            return [];
        }
        foreach (['TVHTML5', 'WEB', 'MWEB', 'ANDROID'] as $client) {
            $rows = $this->browseFeed('FEwhat_to_watch', $limit, false, $token, $client);
            if (count($rows) > 0) {
                return $rows;
            }
        }
        foreach (['TVHTML5', 'WEB', 'ANDROID'] as $client) {
            $rows = $this->browseFeed('FEsubscriptions', $limit, false, $token, $client);
            if (count($rows) > 0) {
                return $rows;
            }
        }
        return [];
    }

    /**
     * YouTube-home style shelves (title + videos) for Recommended.
     *
     * @return list<array{0:string,1:list<array<string,mixed>>}>
     */
    public function homeShelvesWithBearer(string $accessToken, int $limit = 48): array
    {
        $token = trim($accessToken);
        if ($token === '') {
            return [];
        }
        foreach (['TVHTML5', 'WEB', 'MWEB', 'ANDROID'] as $client) {
            $body = $this->browseRaw('FEwhat_to_watch', false, $token, $client);
            if ($body === null || $body === '') {
                continue;
            }
            $data = json_decode($body, true);
            if (!is_array($data)) {
                continue;
            }
            $shelves = [];
            $this->collectHomeShelves($data, $shelves, $limit);
            if (count($shelves) > 0) {
                $this->activeInstance = 'https://www.youtube.com (oauth:shelves:' . $client . ')';
                return $shelves;
            }
            // Body OK but no shelf titles — flatten videos into one Accueil-style shelf.
            $flat = [];
            $seen = [];
            $this->collectHomeVideos($data, $flat, $seen, $limit);
            if (count($flat) === 0) {
                $flat = $this->extractVideoIdsFromRaw($body, $limit);
            }
            if (count($flat) > 0) {
                $this->activeInstance = 'https://www.youtube.com (oauth:flat:' . $client . ')';
                return [['Recommended for You', $flat]];
            }
        }
        // Flat fallback → one shelf.
        $rows = $this->homeFeedWithBearer($token, $limit);
        if (count($rows) === 0) {
            return [];
        }
        return [['Recommended for You', $rows]];
    }

    /**
     * Library feeds (history / playlists / watch later / liked).
     *
     * @return list<array<string,mixed>>
     */
    public function libraryFeedWithBearer(string $accessToken, string $kind, int $limit = 40): array
    {
        $token = trim($accessToken);
        if ($token === '') {
            return [];
        }
        $browseId = $this->libraryBrowseId($kind);
        if ($browseId === '') {
            return [];
        }
        foreach (['TVHTML5', 'WEB', 'ANDROID'] as $client) {
            $rows = $this->browseFeed($browseId, $limit, false, $token, $client);
            if (count($rows) > 0) {
                return $rows;
            }
        }
        // Playlists aggregation browseId varies by client.
        if ($kind === 'playlists') {
            foreach (['TVHTML5', 'WEB'] as $client) {
                $rows = $this->browseFeed('FEplaylist_aggregation', $limit, false, $token, $client);
                if (count($rows) > 0) {
                    return $rows;
                }
            }
        }
        return [];
    }

    /**
     * Same library browseIds as Bearer, authenticated with this client's Netscape jar
     * (visitor cookie path — never the shared Pixr jar for personal You).
     *
     * @return list<array<string,mixed>>
     */
    public function libraryFeedWithCookie(string $kind, int $limit = 40): array
    {
        if ($this->cookie === '') {
            return [];
        }
        $browseId = $this->libraryBrowseId($kind);
        if ($browseId === '') {
            return [];
        }
        foreach (['WEB', 'MWEB', 'ANDROID'] as $client) {
            $rows = $this->browseFeed($browseId, $limit, true, null, $client);
            if (count($rows) > 0) {
                return $rows;
            }
        }
        if ($kind === 'playlists') {
            foreach (['WEB', 'MWEB'] as $client) {
                $rows = $this->browseFeed('FEplaylist_aggregation', $limit, true, null, $client);
                if (count($rows) > 0) {
                    return $rows;
                }
            }
        }
        return [];
    }

    private function libraryBrowseId(string $kind): string
    {
        $map = [
            'history' => 'FEhistory',
            'playlists' => 'FElibrary',
            'watch_later' => 'VLWL',
            'liked' => 'VLLL',
        ];
        return $map[$kind] ?? '';
    }

    /**
     * Channel handle / name / email for the shared cookie account (for UI verification).
     *
     * @return array{handle:string,name:string,email:string}
     */
    public function accountLabel(): array
    {
        $out = ['handle' => '', 'name' => '', 'email' => ''];
        if ($this->cookie === '' || !function_exists('curl_init')) {
            return $out;
        }
        $clientVersion = '2.20241120.01.00';
        $payload = json_encode([
            'context' => [
                'client' => [
                    'clientName' => 'WEB',
                    'clientVersion' => $clientVersion,
                    'hl' => 'en',
                    'gl' => 'US',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);
        $headers = [
            'Content-Type: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Cookie: ' . $this->cookie,
            'Origin: https://www.youtube.com',
        ];
        $sapisid = self::cookieValue($this->cookie, 'SAPISID');
        if ($sapisid === '') {
            $sapisid = self::cookieValue($this->cookie, '__Secure-3PAPISID');
        }
        if ($sapisid !== '') {
            $origin = 'https://www.youtube.com';
            $ts = (string) time();
            $headers[] = 'Authorization: SAPISIDHASH ' . $ts . '_' . sha1($ts . ' ' . $sapisid . ' ' . $origin);
            $headers[] = 'X-Origin: ' . $origin;
        }
        $ch = curl_init('https://www.youtube.com/youtubei/v1/account/account_menu?prettyPrint=false');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => ytl_http_ssl_verify(),
            CURLOPT_SSL_VERIFYHOST => ytl_http_ssl_verify() ? 2 : 0,
        ]);
        ytl_curl_set_proxy($ch);
        $body = curl_exec($ch);
        if (!is_string($body) || $body === '') {
            return $out;
        }
        return self::parseAccountMenuLabel($body);
    }

    /**
     * Extract channel handle / display name / email from account_menu JSON.
     * Never trust the first simpleText alone (language picker often starts with "Afrikaans").
     *
     * @return array{handle:string,name:string,email:string}
     */
    public static function parseAccountMenuLabel(string $body): array
    {
        $out = ['handle' => '', 'name' => '', 'email' => ''];
        if (preg_match_all('/([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})/i', $body, $emails)) {
            foreach ($emails[1] as $em) {
                $em = (string) $em;
                if (stripos($em, 'noreply') !== false || stripos($em, 'youtube.com') !== false) {
                    continue;
                }
                $out['email'] = $em;
                break;
            }
        }
        if (preg_match_all('/"simpleText"\s*:\s*"(@[A-Za-z0-9._-]+)"/', $body, $hms)) {
            $out['handle'] = (string) $hms[1][0];
        } elseif (preg_match('/"canonicalBaseUrl"\s*:\s*"\/(@[A-Za-z0-9._-]+)"/', $body, $hm2)) {
            $out['handle'] = $hm2[1];
        } elseif (preg_match('/"browseId"\s*:\s*"(UC[A-Za-z0-9_-]{20,})"/', $body)) {
            // Channel id present — treat as signed-in; handle may still be empty.
        }
        // Also accept /channel/UC… vanity in urls next to account items.
        if ($out['handle'] === '' && preg_match('/\/@([A-Za-z0-9._-]{2,})/', $body, $hm3)) {
            $out['handle'] = '@' . $hm3[1];
        }
        // Display name: prefer simpleText immediately before the @handle (account header).
        if ($out['handle'] !== '' && preg_match(
            '/"simpleText"\s*:\s*"([^"]{2,80})"\s*[,}].{0,240}?"simpleText"\s*:\s*"' . preg_quote($out['handle'], '/') . '"/s',
            $body,
            $nm
        )) {
            $candidate = html_entity_decode($nm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($candidate !== $out['handle'] && stripos($candidate, 'Google') === false) {
                $out['name'] = $candidate;
            }
        }
        if ($out['name'] === '' && preg_match('/"accountName"\s*:\s*\{[^}]*"simpleText"\s*:\s*"([^"]{2,80})"/s', $body, $an)) {
            $out['name'] = html_entity_decode($an[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        // Last resort: walk decoded JSON for accountItem sections (skip language lists).
        if ($out['name'] === '' || ($out['handle'] === '' && $out['email'] === '')) {
            $data = json_decode($body, true);
            if (is_array($data)) {
                $walk = self::walkAccountMenuIdentity($data);
                if ($out['handle'] === '' && $walk['handle'] !== '') {
                    $out['handle'] = $walk['handle'];
                }
                if ($out['email'] === '' && $walk['email'] !== '') {
                    $out['email'] = $walk['email'];
                }
                if ($out['name'] === '' && $walk['name'] !== '') {
                    $out['name'] = $walk['name'];
                }
            }
        }
        return $out;
    }

    /**
     * @param mixed $node
     * @return array{handle:string,name:string,email:string}
     */
    private static function walkAccountMenuIdentity($node): array
    {
        $found = ['handle' => '', 'name' => '', 'email' => ''];
        $stack = [$node];
        while ($stack) {
            $cur = array_pop($stack);
            if (!is_array($cur)) {
                continue;
            }
            // accountItem with channelHandleText / accountName / email
            if (isset($cur['accountItem'])) {
                $item = $cur['accountItem'];
                if (is_array($item)) {
                    $h = self::simpleTextFrom($item['channelHandle'] ?? $item['channelHandleText'] ?? null);
                    $n = self::simpleTextFrom($item['accountName'] ?? $item['title'] ?? null);
                    if ($h !== '' && str_starts_with($h, '@') && $found['handle'] === '') {
                        $found['handle'] = $h;
                    }
                    if ($n !== '' && $found['name'] === '') {
                        $found['name'] = $n;
                    }
                }
            }
            foreach ($cur as $v) {
                if (is_array($v)) {
                    $stack[] = $v;
                } elseif (is_string($v)) {
                    if ($found['email'] === '' && preg_match('/^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$/i', $v)
                        && stripos($v, 'noreply') === false) {
                        $found['email'] = $v;
                    }
                    if ($found['handle'] === '' && preg_match('/^@[A-Za-z0-9._-]{2,}$/', $v)) {
                        $found['handle'] = $v;
                    }
                }
            }
            if ($found['handle'] !== '' && $found['name'] !== '' && $found['email'] !== '') {
                break;
            }
        }
        return $found;
    }

    /** @param mixed $node */
    private static function simpleTextFrom($node): string
    {
        if (!is_array($node)) {
            return is_string($node) ? $node : '';
        }
        if (isset($node['simpleText']) && is_string($node['simpleText'])) {
            return $node['simpleText'];
        }
        if (isset($node['runs']) && is_array($node['runs'])) {
            $parts = [];
            foreach ($node['runs'] as $run) {
                if (is_array($run) && isset($run['text'])) {
                    $parts[] = (string) $run['text'];
                }
            }
            return implode('', $parts);
        }
        return '';
    }

    /**
     * Raw Innertube browse body, or null on failure.
     *
     * @param 'WEB'|'MWEB'|'TVHTML5'|'ANDROID' $clientName
     */
    private function browseRaw(string $browseId, bool $withCookie, ?string $bearerToken = null, string $clientName = 'WEB'): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $clientName = strtoupper(trim($clientName));
        if ($clientName === 'TVHTML5') {
            // Match working TV player OAuth client (old 2024 versions often return empty / 400).
            $clientVersion = '7.20260817.13.00';
            $ua = 'Mozilla/5.0 (PlayStation; PlayStation 4/12.00)';
            $clientId = '7';
        } elseif ($clientName === 'MWEB') {
            $clientVersion = '2.20241120.01.00';
            $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
            $clientId = '2';
        } elseif ($clientName === 'ANDROID') {
            $clientName = 'ANDROID';
            $clientVersion = '19.35.36';
            $ua = 'com.google.android.youtube/19.35.36 (Linux; U; Android 14) gzip';
            $clientId = '3';
        } else {
            $clientName = 'WEB';
            $clientVersion = '2.20241120.01.00';
            $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
            $clientId = '1';
        }
        $clientCtx = [
            'clientName' => $clientName,
            'clientVersion' => $clientVersion,
            'hl' => 'fr',
            'gl' => 'FR',
            'userAgent' => $ua,
        ];
        if ($clientName === 'TVHTML5') {
            $clientCtx['platform'] = 'TV';
            // HOME for feeds; WATCH was wrong for FEwhat_to_watch / library browse.
            $clientCtx['clientScreen'] = 'HOME';
        }
        $payload = json_encode([
            'context' => [
                'client' => $clientCtx,
            ],
            'browseId' => $browseId,
        ], JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type: application/json',
            'User-Agent: ' . $ua,
            'Accept: application/json',
            'Accept-Language: fr-FR,fr;q=0.9,en;q=0.8',
            'X-YouTube-Client-Name: ' . $clientId,
            'X-YouTube-Client-Version: ' . $clientVersion,
            'Origin: https://www.youtube.com',
            'Referer: https://www.youtube.com/',
        ];
        $bearer = is_string($bearerToken) ? trim($bearerToken) : '';
        if ($bearer !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearer;
            $headers[] = 'X-Origin: https://www.youtube.com';
        } elseif ($withCookie && $this->cookie !== '') {
            $headers[] = 'Cookie: ' . $this->cookie;
            $sapisid = self::cookieValue($this->cookie, 'SAPISID');
            if ($sapisid === '') {
                $sapisid = self::cookieValue($this->cookie, '__Secure-3PAPISID');
            }
            if ($sapisid !== '') {
                $origin = 'https://www.youtube.com';
                $ts = (string) time();
                $headers[] = 'Authorization: SAPISIDHASH ' . $ts . '_' . sha1($ts . ' ' . $sapisid . ' ' . $origin);
                $headers[] = 'X-Origin: ' . $origin;
            }
        }

        $ch = curl_init('https://www.youtube.com/youtubei/v1/browse?prettyPrint=false');
        $timeout = $withCookie || $bearer !== '' ? $this->timeout : min(10, max(5, $this->timeout));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => ytl_http_ssl_verify(),
            CURLOPT_SSL_VERIFYHOST => ytl_http_ssl_verify() ? 2 : 0,
        ]);
        ytl_curl_set_proxy($ch);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false || $code < 200 || $code >= 400) {
            $hint = is_string($body) ? trim(preg_replace('/\s+/', ' ', substr($body, 0, 160))) : '';
            $mode = $bearer !== '' ? ('bearer:' . $clientName) : ($withCookie ? 'home' : 'guest');
            ytl_http_last_error('Innertube ' . $mode . ' HTTP ' . $code . ($hint !== '' ? (' — ' . $hint) : ''));
            return null;
        }
        return is_string($body) ? $body : null;
    }

    /**
     * @param 'WEB'|'TVHTML5' $clientName
     * @return list<array<string,mixed>>
     */
    private function browseFeed(string $browseId, int $limit, bool $withCookie, ?string $bearerToken = null, string $clientName = 'WEB'): array
    {
        $bearer = is_string($bearerToken) ? trim($bearerToken) : '';
        $body = $this->browseRaw($browseId, $withCookie, $bearerToken, $clientName);
        if ($body === null) {
            return [];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [];
        }

        $videos = [];
        $seen = [];
        $this->collectHomeVideos($data, $videos, $seen, $limit);
        if (count($videos) === 0 && is_string($body) && $body !== '') {
            $videos = $this->extractVideoIdsFromRaw($body, $limit);
        }
        if ($bearer !== '') {
            $this->activeInstance = 'https://www.youtube.com (oauth:' . $browseId . ':' . $clientName . ')';
        } elseif ($withCookie) {
            $this->activeInstance = 'https://www.youtube.com (' . $browseId . ')';
        } else {
            $this->activeInstance = 'https://www.youtube.com (guest)';
        }
        return $videos;
    }

    /**
     * @param array<string,mixed> $node
     * @param list<array{0:string,1:list<array<string,mixed>>}> $out
     */
    private function collectHomeShelves(array $node, array &$out, int $limit): void
    {
        if (count($out) >= 12) {
            return;
        }
        $shelf = null;
        if (isset($node['richShelfRenderer']) && is_array($node['richShelfRenderer'])) {
            $shelf = $node['richShelfRenderer'];
        } elseif (isset($node['shelfRenderer']) && is_array($node['shelfRenderer'])) {
            $shelf = $node['shelfRenderer'];
        }
        if (is_array($shelf)) {
            $title = $this->runsText($shelf['title'] ?? null);
            if ($title === '') {
                $title = $this->runsText($shelf['headerRenderer']['shelfHeaderRenderer']['title'] ?? null);
            }
            if ($title === '') {
                $title = $this->runsText($shelf['header']['shelfHeaderRenderer']['title'] ?? null);
            }
            if ($title === '') {
                $title = 'Recommended';
            }
            $videos = [];
            $seen = [];
            $this->collectHomeVideos($shelf, $videos, $seen, min(12, $limit));
            if (count($videos) > 0) {
                $out[] = [$title, $videos];
            }
            return;
        }
        foreach ($node as $value) {
            if (count($out) >= 12) {
                return;
            }
            if (is_array($value)) {
                $this->collectHomeShelves($value, $out, $limit);
            }
        }
    }

    /** @param mixed $titleNode */
    private function runsText($titleNode): string
    {
        if (is_string($titleNode)) {
            return trim($titleNode);
        }
        if (!is_array($titleNode)) {
            return '';
        }
        if (isset($titleNode['simpleText'])) {
            return trim((string) $titleNode['simpleText']);
        }
        $runs = $titleNode['runs'] ?? null;
        if (!is_array($runs)) {
            return '';
        }
        $bits = [];
        foreach ($runs as $r) {
            if (is_array($r) && isset($r['text'])) {
                $bits[] = (string) $r['text'];
            }
        }
        return trim(implode('', $bits));
    }

    /**
     * Walk Innertube browse JSON and collect videoRenderer / lockup entries.
     *
     * @param array<string,mixed> $node
     * @param list<array<string,mixed>> $out
     * @param array<string,bool> $seen
     */
    private function collectHomeVideos(array $node, array &$out, array &$seen, int $limit): void
    {
        if (count($out) >= $limit) {
            return;
        }
        if (isset($node['videoRenderer']) && is_array($node['videoRenderer'])) {
            $row = $this->normalizeBrowseVideo($node['videoRenderer']);
            if ($row !== null) {
                $id = (string) $row['videoId'];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $out[] = $row;
                }
            }
            return;
        }
        if (isset($node['compactVideoRenderer']) && is_array($node['compactVideoRenderer'])) {
            $row = $this->normalizeBrowseVideo($node['compactVideoRenderer']);
            if ($row !== null) {
                $id = (string) $row['videoId'];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $out[] = $row;
                }
            }
            return;
        }
        // Newer home cards sometimes use lockupViewModel
        if (isset($node['lockupViewModel']) && is_array($node['lockupViewModel'])) {
            $row = $this->normalizeLockup($node['lockupViewModel']);
            if ($row !== null) {
                $id = (string) $row['videoId'];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $out[] = $row;
                }
            }
        }
        // YouTube TV home / history / library cards.
        if (isset($node['tileRenderer']) && is_array($node['tileRenderer'])) {
            $row = $this->normalizeTileRenderer($node['tileRenderer']);
            if ($row !== null) {
                $id = (string) $row['videoId'];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $out[] = $row;
                }
            }
            return;
        }
        if (isset($node['gridVideoRenderer']) && is_array($node['gridVideoRenderer'])) {
            $row = $this->normalizeBrowseVideo($node['gridVideoRenderer']);
            if ($row !== null) {
                $id = (string) $row['videoId'];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $out[] = $row;
                }
            }
            return;
        }
        foreach ($node as $value) {
            if (count($out) >= $limit) {
                return;
            }
            if (is_array($value)) {
                $this->collectHomeVideos($value, $out, $seen, $limit);
            }
        }
    }

    /**
     * TVHTML5 tileRenderer → same shape as WEB video rows.
     *
     * @param array<string,mixed> $tile
     * @return array<string,mixed>|null
     */
    private function normalizeTileRenderer(array $tile): ?array
    {
        $id = '';
        foreach (['onSelectCommand', 'navigationEndpoint', 'onLongPressCommand'] as $key) {
            $cmd = $tile[$key] ?? null;
            if (!is_array($cmd)) {
                continue;
            }
            $id = (string) ($cmd['watchEndpoint']['videoId'] ?? ($cmd['reelWatchEndpoint']['videoId'] ?? ''));
            if ($id !== '') {
                break;
            }
            // Sometimes nested under command or showMiniplayerCommand.
            foreach ($cmd as $sub) {
                if (!is_array($sub)) {
                    continue;
                }
                $id = (string) ($sub['videoId'] ?? ($sub['watchEndpoint']['videoId'] ?? ''));
                if ($id !== '') {
                    break 2;
                }
            }
        }
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) {
            return null;
        }
        $meta = $tile['metadata']['tileMetadataRenderer'] ?? null;
        $title = '';
        if (is_array($meta)) {
            $title = $this->runsText($meta['title'] ?? null);
        }
        if ($title === '') {
            $title = $this->runsText($tile['title'] ?? null);
        }
        if ($title === '') {
            $title = 'Video';
        }
        $author = '';
        if (is_array($meta) && isset($meta['lines']) && is_array($meta['lines'])) {
            foreach ($meta['lines'] as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $t = $this->runsText($line['lineRenderer']['items'][0]['lineItemRenderer']['text'] ?? null);
                if ($t === '') {
                    $t = $this->runsText($line['lineRenderer']['items'][0]['text'] ?? null);
                }
                if ($t !== '' && !preg_match('/^\d/', $t) && stripos($t, 'view') === false) {
                    $author = $t;
                    break;
                }
            }
        }
        $length = 0;
        $overlays = $tile['header']['tileHeaderRenderer']['thumbnailOverlays'] ?? ($tile['thumbnailOverlays'] ?? []);
        if (is_array($overlays)) {
            foreach ($overlays as $ov) {
                if (!is_array($ov)) {
                    continue;
                }
                $lenText = (string) ($ov['thumbnailOverlayTimeStatusRenderer']['text']['simpleText'] ?? '');
                if ($lenText === '') {
                    $lenText = $this->runsText($ov['thumbnailOverlayTimeStatusRenderer']['text'] ?? null);
                }
                if ($lenText !== '' && preg_match('/^(?:(\d+):)?(\d+):(\d+)$/', $lenText, $m)) {
                    $length = ((int) ($m[1] !== '' ? $m[1] : 0)) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
                    break;
                }
            }
        }
        return [
            'type' => 'video',
            'title' => $title,
            'videoId' => $id,
            'author' => $author,
            'authorId' => '',
            'viewCount' => 0,
            'lengthSeconds' => $length,
            'publishedText' => '',
            'videoThumbnails' => [[
                'quality' => 'mqdefault',
                'url' => 'https://i.ytimg.com/vi/' . $id . '/mqdefault.jpg',
                'width' => 320,
                'height' => 180,
            ]],
        ];
    }

    /**
     * Last-resort scrape of videoIds from a TV/WEB browse JSON body.
     *
     * @return list<array<string,mixed>>
     */
    private function extractVideoIdsFromRaw(string $raw, int $limit): array
    {
        if (!preg_match_all('/"videoId"\s*:\s*"([A-Za-z0-9_-]{11})"/', $raw, $m)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($m[1] as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'type' => 'video',
                'title' => 'Video',
                'videoId' => $id,
                'author' => '',
                'authorId' => '',
                'viewCount' => 0,
                'lengthSeconds' => 0,
                'publishedText' => '',
                'videoThumbnails' => [[
                    'quality' => 'mqdefault',
                    'url' => 'https://i.ytimg.com/vi/' . $id . '/mqdefault.jpg',
                    'width' => 320,
                    'height' => 180,
                ]],
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $vr */
    private function normalizeBrowseVideo(array $vr): ?array
    {
        $id = (string) ($vr['videoId'] ?? '');
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) {
            return null;
        }
        $title = '';
        if (isset($vr['title']['simpleText'])) {
            $title = (string) $vr['title']['simpleText'];
        } elseif (isset($vr['title']['runs'][0]['text'])) {
            $title = (string) $vr['title']['runs'][0]['text'];
        }
        if ($title === '') {
            return null;
        }
        $author = '';
        if (isset($vr['ownerText']['runs'][0]['text'])) {
            $author = (string) $vr['ownerText']['runs'][0]['text'];
        } elseif (isset($vr['shortBylineText']['runs'][0]['text'])) {
            $author = (string) $vr['shortBylineText']['runs'][0]['text'];
        }
        $authorId = '';
        $nav = $vr['ownerText']['runs'][0]['navigationEndpoint']['browseEndpoint']['browseId']
            ?? $vr['shortBylineText']['runs'][0]['navigationEndpoint']['browseEndpoint']['browseId']
            ?? '';
        if (is_string($nav) && str_starts_with($nav, 'UC')) {
            $authorId = $nav;
        }
        $length = 0;
        $lenText = (string) ($vr['lengthText']['simpleText'] ?? '');
        if ($lenText !== '' && preg_match('/^(?:(\d+):)?(\d+):(\d+)$/', $lenText, $m)) {
            $length = ((int) ($m[1] !== '' ? $m[1] : 0)) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
        }
        $views = 0;
        $viewText = (string) ($vr['viewCountText']['simpleText'] ?? '');
        if ($viewText === '' && isset($vr['shortViewCountText']['simpleText'])) {
            $viewText = (string) $vr['shortViewCountText']['simpleText'];
        }
        if (preg_match('/([\d,.]+)\s*([KMB])?/i', $viewText, $vm)) {
            $n = (float) str_replace(',', '', $vm[1]);
            $u = strtoupper($vm[2] ?? '');
            if ($u === 'K') {
                $n *= 1000;
            } elseif ($u === 'M') {
                $n *= 1000000;
            } elseif ($u === 'B') {
                $n *= 1000000000;
            }
            $views = (int) $n;
        }
        $published = '';
        if (isset($vr['publishedTimeText']['simpleText'])) {
            $published = (string) $vr['publishedTimeText']['simpleText'];
        }
        $badgeJson = !empty($vr['badges']) ? json_encode($vr['badges']) : '';
        $live = is_string($badgeJson) && $badgeJson !== ''
            && (stripos($badgeJson, 'LIVE') !== false || stripos($badgeJson, 'UPCOMING') !== false);
        if ($length <= 0 && stripos($viewText, 'watching') !== false) {
            $live = true;
        }
        if (!$live && (str_contains($title, '🔴') || preg_match('/\bLIVE\b/i', $title))) {
            $live = true;
        }
        if (!$live && preg_match('/\bwatching\b/i', $published)) {
            $live = true;
        }

        return [
            'type' => 'video',
            'title' => $title,
            'videoId' => $id,
            'author' => $author,
            'authorId' => $authorId,
            'authorUrl' => $authorId !== '' ? '/channel/' . $authorId : '',
            'videoThumbnails' => [[
                'quality' => 'hqdefault',
                'url' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
                'width' => 480,
                'height' => 360,
            ]],
            'description' => '',
            'viewCount' => $views,
            'viewCountText' => $viewText,
            'publishedText' => $published,
            'lengthSeconds' => $length,
            'liveNow' => $live,
            '_source' => 'innertube-home',
        ];
    }

    /** @param array<string,mixed> $lockup */
    private function normalizeLockup(array $lockup): ?array
    {
        $id = (string) ($lockup['contentId'] ?? '');
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) {
            // Sometimes nested under contentId / rendererContext
            $id = (string) ($lockup['rendererContext']['commandContext']['onTap']['innertubeCommand']['watchEndpoint']['videoId'] ?? '');
        }
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) {
            return null;
        }
        $title = (string) ($lockup['metadata']['lockupMetadataViewModel']['title']['content'] ?? '');
        if ($title === '') {
            $title = (string) ($lockup['title']['content'] ?? '');
        }
        if ($title === '') {
            return null;
        }
        $author = (string) ($lockup['metadata']['lockupMetadataViewModel']['metadata']['contentMetadataViewModel']['metadataRows'][0]['metadataParts'][0]['text']['content'] ?? '');

        // Duration / LIVE badge on thumbnail overlays only (do NOT scan whole lockup —
        // guest home JSON is full of other LIVE cards and that falsely marks every video).
        $length = 0;
        $live = false;
        $lenText = (string) ($lockup['contentImage']['thumbnailViewModel']['overlays'][0]['thumbnailOverlayBadgeViewModel']['thumbnailBadges'][0]['thumbnailBadgeViewModel']['text'] ?? '');
        $overlays = $lockup['contentImage']['thumbnailViewModel']['overlays'] ?? null;
        if (is_array($overlays)) {
            $encOverlays = json_encode($overlays);
            if (is_string($encOverlays)) {
                if ($lenText === '' && preg_match('/"text"\s*:\s*"((?:\d+:)?\d{1,2}:\d{2})"/', $encOverlays, $lm)) {
                    $lenText = $lm[1];
                }
                if (preg_match('/"text"\s*:\s*"LIVE"/i', $encOverlays) || stripos($encOverlays, 'BADGE_STYLE_TYPE_LIVE') !== false) {
                    $live = true;
                }
            }
        }
        if ($lenText !== '' && preg_match('/^(?:(\d+):)?(\d+):(\d+)$/', $lenText, $m)) {
            $length = ((int) ($m[1] !== '' ? $m[1] : 0)) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
        }
        // Title / metadata rows still catch livestreams when the badge overlay is missing.
        if (!$live && (str_contains($title, '🔴') || preg_match('/\bLIVE\b/i', $title))) {
            $live = true;
        }
        $metaRows = $lockup['metadata']['lockupMetadataViewModel']['metadata']['contentMetadataViewModel']['metadataRows'] ?? null;
        $metaEnc = is_array($metaRows) ? json_encode($metaRows) : '';
        if (!$live && is_string($metaEnc) && preg_match('/watching/i', $metaEnc)) {
            $live = true;
        }
        // No duration + live-ish title → treat as live (home lockups often omit the LIVE badge path).
        if (!$live && $length <= 0 && preg_match('/\blive\b/i', $title)) {
            $live = true;
        }

        return [
            'type' => 'video',
            'title' => $title,
            'videoId' => $id,
            'author' => $author,
            'authorId' => '',
            'authorUrl' => '',
            'videoThumbnails' => [[
                'quality' => 'hqdefault',
                'url' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
                'width' => 480,
                'height' => 360,
            ]],
            'description' => '',
            'viewCount' => 0,
            'publishedText' => '',
            'lengthSeconds' => $length,
            'liveNow' => $live,
            '_source' => 'innertube-home',
        ];
    }

    public function channel(string $channelId): ?array
    {
        $id = trim($channelId);
        if ($id === '' || !preg_match('/^UC[A-Za-z0-9_-]{20,}$/', $id)) {
            return null;
        }
        // Prefer cookie browse on OVH; fall back to guest.
        $body = $this->browseRaw($id, true, null);
        if ($body === null || $body === '') {
            $body = $this->browseRaw($id, false, null);
        }
        if ($body === null || $body === '') {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        $meta = null;
        $stack = [$data];
        while ($stack) {
            $node = array_pop($stack);
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['channelMetadataRenderer']) && is_array($node['channelMetadataRenderer'])) {
                $meta = $node['channelMetadataRenderer'];
                break;
            }
            foreach ($node as $v) {
                if (is_array($v)) {
                    $stack[] = $v;
                }
            }
        }
        if (!is_array($meta)) {
            return null;
        }

        $thumbs = [];
        foreach (($meta['avatar']['thumbnails'] ?? []) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $u = trim((string) ($t['url'] ?? ''));
            if ($u === '') {
                continue;
            }
            $thumbs[] = [
                'url' => $u,
                'width' => (int) ($t['width'] ?? 0),
                'height' => (int) ($t['height'] ?? 0),
            ];
        }
        // Newer pageHeader avatar sources (fallback).
        if (count($thumbs) === 0) {
            $stack = [$data];
            while ($stack) {
                $node = array_pop($stack);
                if (!is_array($node)) {
                    continue;
                }
                if (isset($node['avatarViewModel']['image']['sources']) && is_array($node['avatarViewModel']['image']['sources'])) {
                    foreach ($node['avatarViewModel']['image']['sources'] as $t) {
                        if (!is_array($t)) {
                            continue;
                        }
                        $u = trim((string) ($t['url'] ?? ''));
                        if ($u === '') {
                            continue;
                        }
                        $thumbs[] = [
                            'url' => $u,
                            'width' => (int) ($t['width'] ?? 0),
                            'height' => (int) ($t['height'] ?? 0),
                        ];
                    }
                    if (count($thumbs) > 0) {
                        break;
                    }
                }
                foreach ($node as $v) {
                    if (is_array($v)) {
                        $stack[] = $v;
                    }
                }
            }
        }

        $avatarUrl = '';
        if (count($thumbs) > 0) {
            usort($thumbs, static fn($a, $b) => abs(($a['width'] ?: 999) - 88) <=> abs(($b['width'] ?: 999) - 88));
            $avatarUrl = (string) $thumbs[0]['url'];
            // Prefer a crisp ~120–176px if available.
            foreach ($thumbs as $t) {
                $w = (int) ($t['width'] ?? 0);
                if ($w >= 88 && $w <= 176) {
                    $avatarUrl = (string) $t['url'];
                    break;
                }
            }
        }

        $this->activeInstance = 'https://www.youtube.com (channel)';
        return [
            'author' => (string) ($meta['title'] ?? $id),
            'authorId' => (string) ($meta['externalId'] ?? $id),
            'description' => (string) ($meta['description'] ?? ''),
            'subCount' => 0,
            'avatarUrl' => $avatarUrl,
            'authorThumbnails' => $thumbs,
            'verified' => false,
        ];
    }

    public function channelVideos(string $channelId, ?string $continuation = null): array
    {
        return ['videos' => [], 'continuation' => null];
    }

    public function video(string $videoId): ?array
    {
        return $this->videoWithClients($videoId, $this->defaultPlayerClients(true), null);
    }

    /**
     * Player fetch for a signed-in visitor.
     * Google Cloud OAuth Bearer is often rejected by Innertube WEB/TV (HTTP 400) since 2025 —
     * still try TV/WEB Bearer, then anonymous ANDROID/IOS which return progressive/hls URLs
     * when the egress IP is allowed. Never attach shared Pixr cookies here.
     */
    public function videoWithBearer(string $videoId, string $accessToken): ?array
    {
        $token = trim($accessToken);
        if ($token === '') {
            return null;
        }
        return $this->videoWithClients($videoId, $this->oauthPlayerClients(), $token);
    }

    /**
     * YouTube TV device-code Bearer + TVHTML5 (PlayStation UA).
     * This is the Tube Replacer / YouTube.js path — Cloud Web OAuth must NOT use this alone.
     */
    public function videoWithTvOauth(string $videoId, string $accessToken): ?array
    {
        $token = trim($accessToken);
        if ($token === '') {
            return null;
        }
        $video = $this->videoWithClients($videoId, $this->tvOauthPlayerClients(), $token);
        if ($video !== null) {
            $video['_source'] = 'innertube-tv-oauth';
        }
        return $video;
    }

    /**
     * TVHTML5 clients that accept YouTube-on-TV OAuth Bearers.
     * Cobalt UAs are rejected ("page needs to be reloaded"); PS4 UA works (2026).
     *
     * @return list<array<string,mixed>>
     */
    private function tvOauthPlayerClients(): array
    {
        // Lavalink youtube-source: short PS4 UA + params=2AMB (Cobalt UAs → "page needs reload").
        $ps4 = 'Mozilla/5.0 (PlayStation; PlayStation 4/12.00)';
        $ps4Full = 'Mozilla/5.0 (PlayStation; PlayStation 4/12.00) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Safari/605.1.15';
        return [
            [
                'endpoint' => 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false',
                'clientName' => 'TVHTML5',
                'clientVersion' => '7.20260817.13.00',
                'extra' => [],
                'ua' => $ps4,
                'client_id' => '7',
                'oauth' => true,
                'needs_sts' => true,
                'params' => '2AMB',
            ],
            [
                'endpoint' => 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false',
                'clientName' => 'TVHTML5',
                'clientVersion' => '7.20250319.10.00',
                'extra' => [],
                'ua' => $ps4,
                'client_id' => '7',
                'oauth' => true,
                'needs_sts' => true,
                'params' => '2AMB',
            ],
            [
                'endpoint' => 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false',
                'clientName' => 'TVHTML5',
                'clientVersion' => '7.20241120.07.00',
                'extra' => [],
                'ua' => $ps4Full,
                'client_id' => '7',
                'oauth' => true,
                'needs_sts' => false,
            ],
            // Anonymous ANDROID/IOS after TV Bearer miss (no Bearer — Bearer+ANDROID = HTTP 400).
            [
                'endpoint' => 'https://youtubei.googleapis.com/youtubei/v1/player?key=' . self::ANDROID_KEY,
                'clientName' => 'ANDROID',
                'clientVersion' => '21.26.364',
                'extra' => [
                    'androidSdkVersion' => 30,
                    'osName' => 'Android',
                    'osVersion' => '11',
                ],
                'ua' => 'com.google.android.youtube/21.26.364 (Linux; U; Android 11) gzip',
            ],
            [
                'endpoint' => 'https://youtubei.googleapis.com/youtubei/v1/player?key=' . self::IOS_KEY,
                'clientName' => 'IOS',
                'clientVersion' => '21.26.4',
                'extra' => [
                    'deviceMake' => 'Apple',
                    'deviceModel' => 'iPhone16,2',
                    'osName' => 'iPhone',
                    'osVersion' => '18.3.2.22D82',
                ],
                'ua' => 'com.google.ios.youtube/21.26.4 (iPhone16,2; U; CPU iOS 18_3_2 like Mac OS X;)',
            ],
        ];
    }

    /**
     * Account convert clients: anonymous ANDROID/IOS first (real googlevideo URLs),
     * then TV/WEB + Bearer (may 400 on Cloud OAuth — still tried for account identity).
     *
     * @return list<array<string,mixed>>
     */
    private function oauthPlayerClients(): array
    {
        $webVer = $this->webClientVersion();
        $ps4 = 'Mozilla/5.0 (PlayStation; PlayStation 4/12.00) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Safari/605.1.15';
        return [
            // Progressive itag 18 often present — no Bearer (Bearer + ANDROID = HTTP 400).
            [
                'endpoint' => 'https://youtubei.googleapis.com/youtubei/v1/player?key=' . self::ANDROID_KEY,
                'clientName' => 'ANDROID',
                'clientVersion' => '21.26.364',
                'extra' => [
                    'androidSdkVersion' => 30,
                    'osName' => 'Android',
                    'osVersion' => '11',
                ],
                'ua' => 'com.google.android.youtube/21.26.364 (Linux; U; Android 11) gzip',
            ],
            [
                'endpoint' => 'https://youtubei.googleapis.com/youtubei/v1/player?key=' . self::IOS_KEY,
                'clientName' => 'IOS',
                'clientVersion' => '21.26.4',
                'extra' => [
                    'deviceMake' => 'Apple',
                    'deviceModel' => 'iPhone16,2',
                    'osName' => 'iPhone',
                    'osVersion' => '18.3.2.22D82',
                ],
                'ua' => 'com.google.ios.youtube/21.26.4 (iPhone16,2; U; CPU iOS 18_3_2 like Mac OS X;)',
            ],
            [
                'endpoint' => 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false',
                'clientName' => 'TVHTML5',
                'clientVersion' => '7.20241120.07.00',
                'extra' => [],
                'ua' => $ps4,
                'client_id' => '7',
                'oauth' => true,
                'needs_sts' => false,
            ],
            [
                'endpoint' => 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false',
                'clientName' => 'WEB_EMBEDDED_PLAYER',
                'clientVersion' => '1.20241120.01.00',
                'extra' => [],
                'ua' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.5 Safari/605.1.15',
                'client_id' => '56',
                'oauth' => true,
                'needs_sts' => true,
                'third_party' => ['embedUrl' => 'https://www.youtube.com/'],
            ],
            [
                'endpoint' => 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false',
                'clientName' => 'WEB',
                'clientVersion' => $webVer,
                'extra' => [],
                'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'web_auth' => true,
                'client_id' => '1',
                'oauth' => true,
                'needs_sts' => true,
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $clients
     */
    private function videoWithClients(string $videoId, array $clients, ?string $bearerToken): ?array
    {
        $best = null;
        $lastError = '';
        $bearer = is_string($bearerToken) ? trim($bearerToken) : '';
        foreach ($clients as $c) {
            $data = $this->player($videoId, $c, $bearer !== '' ? $bearer : null);
            if ($data === null) {
                $lastError = ytl_http_last_error();
                continue;
            }
            $norm = $this->normalize($videoId, $data);
            if ($norm === null) {
                continue;
            }
            if ($bearer !== '') {
                $norm['_source'] = 'innertube-oauth';
            }
            $prog = count($norm['formatStreams'] ?? []);
            $adUrls = 0;
            foreach ($norm['adaptiveFormats'] ?? [] as $af) {
                if (is_array($af) && !empty($af['url']) && str_starts_with((string) $af['url'], 'http')) {
                    $adUrls++;
                }
            }
            $hasHls = !empty($norm['hlsUrl']);
            // Prefer progressive; else adaptive with direct URLs (ffmpeg mux).
            if ($prog > 0 || $adUrls >= 2) {
                return $norm;
            }
            if ($best === null || ($hasHls && empty($best['hlsUrl']))) {
                $best = $norm;
            }
        }
        if ($best === null && $lastError !== '') {
            ytl_http_last_error($lastError);
        }
        return $best;
    }

    /**
     * Clients that still return a direct progressive URL (no JS decipher).
     * Cookies are NOT sent to Android/iOS — that triggers HTTP 400 on datacenter IPs.
     *
     * @return list<array<string,mixed>>
     */
    private function defaultPlayerClients(bool $includeCookieWeb): array
    {
        $clients = [
            [
                'endpoint' => 'https://youtubei.googleapis.com/youtubei/v1/player?key=' . self::ANDROID_KEY,
                'clientName' => 'ANDROID',
                'clientVersion' => '21.26.364',
                'extra' => [
                    'androidSdkVersion' => 30,
                    'osName' => 'Android',
                    'osVersion' => '11',
                ],
                'ua' => 'com.google.android.youtube/21.26.364 (Linux; U; Android 11) gzip',
            ],
            [
                'endpoint' => 'https://youtubei.googleapis.com/youtubei/v1/player?key=' . self::ANDROID_KEY,
                'clientName' => 'ANDROID_VR',
                'clientVersion' => '1.65.10',
                'extra' => [
                    'deviceMake' => 'Oculus',
                    'deviceModel' => 'Quest 3',
                    'osName' => 'Android',
                    'osVersion' => '12L',
                    'androidSdkVersion' => 32,
                ],
                'ua' => 'com.google.android.apps.youtube.vr.oculus/1.65.10 (Linux; U; Android 12L; eureka-user Build/SQ3A.220605.009.A1) gzip',
            ],
            [
                'endpoint' => 'https://youtubei.googleapis.com/youtubei/v1/player?key=' . self::IOS_KEY,
                'clientName' => 'IOS',
                'clientVersion' => '21.26.4',
                'extra' => [
                    'deviceMake' => 'Apple',
                    'deviceModel' => 'iPhone16,2',
                    'osName' => 'iPhone',
                    'osVersion' => '18.3.2.22D82',
                ],
                'ua' => 'com.google.ios.youtube/21.26.4 (iPhone16,2; U; CPU iOS 18_3_2 like Mac OS X;)',
            ],
            [
                'endpoint' => 'https://youtubei.googleapis.com/youtubei/v1/player?key=' . self::IOS_KEY,
                'clientName' => 'VISIONOS',
                'clientVersion' => '1.02',
                'extra' => [
                    'deviceMake' => 'Apple',
                    'deviceModel' => 'RealityDevice17,1',
                    'osName' => 'visionOS',
                    'osVersion' => '26.5.23O471',
                ],
                'ua' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_7_3) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15',
            ],
            [
                'endpoint' => 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false',
                'clientName' => 'TVHTML5',
                'clientVersion' => '5.20260707',
                'extra' => [
                    'deviceMake' => 'Samsung',
                    'deviceModel' => 'UKS9800',
                    'osName' => 'Tizen',
                    'osVersion' => '2.4.0',
                ],
                'ua' => 'Mozilla/5.0 (SMART-TV; Linux; Tizen 2.4.0) AppleWebKit/538.1 (KHTML, like Gecko) Version/2.4.0 TV Safari/538.1',
                'client_id' => '7',
            ],
            [
                'endpoint' => 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false',
                'clientName' => 'WEB_EMBEDDED_PLAYER',
                'clientVersion' => '2.20260708.00.00',
                'extra' => [],
                'ua' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.5 Safari/605.1.15,gzip(gfe)',
                'client_id' => '56',
            ],
            [
                'endpoint' => 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false',
                'clientName' => 'WEB',
                'clientVersion' => '2.20260708.00.00',
                'extra' => [],
                'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'web_auth' => true,
                'client_id' => '1',
            ],
            [
                'endpoint' => 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false',
                'clientName' => 'MWEB',
                'clientVersion' => '2.20260708.05.00',
                'extra' => [],
                'ua' => 'Mozilla/5.0 (iPad; CPU OS 16_7_10 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1,gzip(gfe)',
                'web_auth' => true,
                'client_id' => '2',
            ],
        ];

        if (!$includeCookieWeb) {
            // OAuth Bearer path: keep WEB/MWEB (Bearer), drop cookie-only dependency.
            return $clients;
        }

        if ($this->cookie === '') {
            return array_values(array_filter(
                $clients,
                static fn(array $c): bool => empty($c['web_auth'])
            ));
        }
        return $clients;
    }

    private function player(string $videoId, array $client, ?string $bearerToken = null): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $clientName = (string) ($client['clientName'] ?? '');
        $clientBlock = array_merge([
            'clientName' => $clientName,
            'clientVersion' => $client['clientVersion'],
            'hl' => 'en',
            'gl' => 'US',
        ], $client['extra'] ?? []);

        $bearer = is_string($bearerToken) ? trim($bearerToken) : '';
        $bearerOk = $bearer !== '' && (
            !empty($client['oauth'])
            || !empty($client['web_auth'])
            || in_array($clientName, ['WEB', 'MWEB', 'WEB_EMBEDDED_PLAYER', 'TVHTML5', 'TVHTML5_SIMPLY'], true)
        );
        // Never attach Bearer to ANDROID/IOS googleapis endpoints.
        if ($bearer !== '' && !$bearerOk) {
            $bearer = '';
            $bearerOk = false;
        }

        $context = ['client' => $clientBlock];
        if (!empty($client['third_party']) && is_array($client['third_party'])) {
            $context['thirdParty'] = $client['third_party'];
        }
        $baseBody = [
            'context' => $context,
            'videoId' => $videoId,
            'contentCheckOk' => true,
            'racyCheckOk' => true,
        ];
        if (!empty($client['params']) && is_string($client['params'])) {
            $baseBody['params'] = (string) $client['params'];
        }

        // Wrong / missing signatureTimestamp ⇒ HTTP 400 invalid argument on WEB(+Bearer).
        // Prefer sts-bearing bodies first; TV / ANDROID usually work without sts.
        $needsSts = $bearerOk && (
            !empty($client['needs_sts'])
            || in_array($clientName, ['WEB', 'MWEB', 'WEB_EMBEDDED_PLAYER'], true)
        );
        $bodyVariants = [];
        if ($needsSts) {
            // Max 2 attempts: live sts (yt-dlp shape), then one fallback sts.
            foreach (array_slice($this->signatureTimestamps(), 0, 2) as $sts) {
                $bodyVariants[] = array_merge($baseBody, [
                    'playbackContext' => [
                        'contentPlaybackContext' => [
                            'html5Preference' => 'HTML5_PREF_WANTS',
                            'signatureTimestamp' => $sts,
                            'vis' => 0,
                            'splay' => false,
                            'lactMilliseconds' => '-1',
                        ],
                    ],
                ]);
            }
        } else {
            $bodyVariants[] = $baseBody;
        }

        $headersBase = [
            'Content-Type: application/json',
            'User-Agent: ' . $client['ua'],
            'Accept: application/json',
            'Accept-Language: en-US,en;q=0.9',
        ];
        if (!empty($client['client_id'])) {
            $headersBase[] = 'X-YouTube-Client-Name: ' . $client['client_id'];
            $headersBase[] = 'X-YouTube-Client-Version: ' . $client['clientVersion'];
        }
        if ($bearerOk) {
            // Never send Bearer together with ?key= (API Gateway → INVALID_ARGUMENT).
            if (str_contains((string) ($client['endpoint'] ?? ''), 'key=')) {
                $bearer = '';
                $bearerOk = false;
            } else {
                $headersBase[] = 'Authorization: Bearer ' . $bearer;
                // Origin (CORS) matters; keep X-Origin for older Innertube CSRF checks.
                $headersBase[] = 'Origin: https://www.youtube.com';
                $headersBase[] = 'X-Origin: https://www.youtube.com';
                $headersBase[] = 'Referer: https://www.youtube.com/watch?v=' . rawurlencode($videoId);
            }
        }
        if (!$bearerOk && $this->cookie !== '' && !empty($client['web_auth'])) {
            $headersBase[] = 'Cookie: ' . $this->cookie;
            $sapisid = self::cookieValue($this->cookie, 'SAPISID');
            if ($sapisid === '') {
                $sapisid = self::cookieValue($this->cookie, '__Secure-3PAPISID');
            }
            if ($sapisid !== '') {
                $origin = 'https://www.youtube.com';
                $ts = (string) time();
                $headersBase[] = 'Authorization: SAPISIDHASH ' . $ts . '_' . sha1($ts . ' ' . $sapisid . ' ' . $origin);
                $headersBase[] = 'X-Origin: ' . $origin;
                $headersBase[] = 'Origin: ' . $origin;
            }
        }

        $lastHint = '';
        foreach ($bodyVariants as $bodyArr) {
            $payload = json_encode($bodyArr, JSON_UNESCAPED_SLASHES);
            $ch = curl_init($client['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headersBase,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_CONNECTTIMEOUT => $bearerOk ? 4 : min(8, max(4, $this->timeout)),
                CURLOPT_TIMEOUT => $bearerOk ? 12 : min(20, max(8, $this->timeout)),
                CURLOPT_SSL_VERIFYPEER => ytl_http_ssl_verify(),
                CURLOPT_SSL_VERIFYHOST => ytl_http_ssl_verify() ? 2 : 0,
            ]);
            ytl_curl_set_proxy($ch);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            if ($body === false || $code < 200 || $code >= 400) {
                $lastHint = is_string($body) ? trim(preg_replace('/\s+/', ' ', substr($body, 0, 160))) : '';
                ytl_http_last_error('Innertube HTTP ' . $code
                    . ' ' . $clientName
                    . ($cerr !== '' ? (' / ' . $cerr) : '')
                    . ($lastHint !== '' ? (' — ' . $lastHint) : ''));
                if ($code === 400 && $bearerOk) {
                    continue;
                }
                return null;
            }
            $data = json_decode($body, true);
            if (!is_array($data)) {
                ytl_http_last_error('Innertube invalid JSON');
                return null;
            }
            $status = (string) ($data['playabilityStatus']['status'] ?? '');
            if ($status !== 'OK' && $status !== 'LIVE_STREAM_OFFLINE') {
                $reason = (string) ($data['playabilityStatus']['reason'] ?? $status);
                ytl_http_last_error('Innertube playability: ' . $reason . ' (' . $clientName . ')');
                // Soft misses: try next body variant (sts), then caller tries next client.
                // Do not abort the whole OAuth client list on the first LOGIN_REQUIRED/bot.
                if ($bearerOk && in_array($status, ['ERROR', 'LOGIN_REQUIRED', 'UNPLAYABLE'], true)) {
                    continue;
                }
                return null;
            }
            $this->activeInstance = preg_replace('#\?.*$#', '', $client['endpoint']) . ' [' . $clientName . ']';
            if ($bearerOk) {
                $this->activeInstance .= ' (oauth-bearer)';
            }
            return $data;
        }
        if ($lastHint !== '') {
            ytl_http_last_error('Innertube HTTP 400 ' . $clientName . ' — ' . $lastHint);
        }
        return null;
    }

    /** Live WEB clientVersion from ytcfg (cached). Stale versions ⇒ HTTP 400. */
    private function webClientVersion(): string
    {
        $cfg = $this->webPlayerConfig();
        $ver = (string) ($cfg['clientVersion'] ?? '');
        return $ver !== '' ? $ver : '2.20261105.05.01';
    }

    /** Best-effort primary sts for WEB player (cached). 0 = omit. */
    private function signatureTimestamp(): int
    {
        $list = $this->signatureTimestamps();
        return $list[0] ?? 0;
    }

    /**
     * Candidate signatureTimestamp values (live first, then known-good fallbacks).
     *
     * @return list<int>
     */
    private function signatureTimestamps(): array
    {
        $cfg = $this->webPlayerConfig();
        $out = [];
        $live = (int) ($cfg['sts'] ?? 0);
        if ($live > 0) {
            $out[] = $live;
        }
        // Recent known-good fallbacks if ytcfg/base.js fetch fails on OVH.
        foreach ([20760, 20709, 20655, 20598, 20434, 20362, 20255, 20152] as $fb) {
            if (!in_array($fb, $out, true)) {
                $out[] = $fb;
            }
        }
        return $out;
    }

    /**
     * Cached ytcfg bits: sts + clientVersion from watch HTML (preferred) or player base.js.
     *
     * @return array{sts:int,clientVersion:string}
     */
    private function webPlayerConfig(): array
    {
        static $cached = null;
        static $cachedAt = 0;
        if (is_array($cached) && (time() - $cachedAt) < 3600) {
            return $cached;
        }
        $cached = ['sts' => 0, 'clientVersion' => ''];
        $cachedAt = time();
        if (!function_exists('curl_init')) {
            return $cached;
        }

        $fetch = function (string $url, int $timeout) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                CURLOPT_SSL_VERIFYPEER => ytl_http_ssl_verify(),
                CURLOPT_SSL_VERIFYHOST => ytl_http_ssl_verify() ? 2 : 0,
            ]);
            ytl_curl_set_proxy($ch);
            $body = curl_exec($ch);
            return is_string($body) ? $body : '';
        };

        // 1) Watch HTML ytcfg — has "STS":20760 and INNERTUBE_CLIENT_VERSION (no 3MB JS).
        $html = $fetch('https://www.youtube.com/watch?v=jNQXAC9IVRw', 10);
        if ($html !== '') {
            if (preg_match('/"STS"\s*:\s*(\d{5,})/', $html, $m)
                || preg_match('/signatureTimestamp["\s:]+(\d{5,})/', $html, $m)
            ) {
                $cached['sts'] = (int) $m[1];
            }
            if (preg_match('/"INNERTUBE_CLIENT_VERSION"\s*:\s*"([^"]+)"/', $html, $m)) {
                $cached['clientVersion'] = (string) $m[1];
            }
            if ($cached['sts'] > 0 && $cached['clientVersion'] !== '') {
                return $cached;
            }
        }

        // 2) iframe_api → player id → base.js signatureTimestamp
        $js = $fetch('https://www.youtube.com/iframe_api', 6);
        $playerId = '';
        if ($js !== '') {
            if (preg_match('/signatureTimestamp["\s:]+(\d{5,})/', $js, $m)) {
                $cached['sts'] = (int) $m[1];
            }
            if (
                preg_match('#/s/player/([a-zA-Z0-9._-]+)/#', $js, $m)
                || preg_match('#player\\\\?/([a-zA-Z0-9._-]+)\\\\?/www-widgetapi#', $js, $m)
                || preg_match('#player/([a-zA-Z0-9._-]+)/www-widgetapi#', $js, $m)
            ) {
                $playerId = $m[1];
            }
        }
        if ($cached['sts'] <= 0 && $playerId !== '') {
            $base = $fetch(
                'https://www.youtube.com/s/player/' . $playerId . '/player_ias.vflset/en_US/base.js',
                12
            );
            if ($base !== '' && preg_match('/signatureTimestamp[:=](\d{5,})/', $base, $mm)) {
                $cached['sts'] = (int) $mm[1];
            }
        }

        return $cached;
    }

    private function normalize(string $videoId, array $data): ?array
    {
        $details = $data['videoDetails'] ?? [];
        if (empty($details['title'])) {
            return null;
        }

        $streaming = $data['streamingData'] ?? [];
        $formatStreams = [];
        foreach ($streaming['formats'] ?? [] as $f) {
            if (!is_array($f)) {
                continue;
            }
            $mapped = $this->mapFormat($f);
            if ($mapped !== null) {
                $formatStreams[] = $mapped;
            }
        }

        $adaptiveFormats = [];
        foreach ($streaming['adaptiveFormats'] ?? [] as $f) {
            if (!is_array($f)) {
                continue;
            }
            $mapped = $this->mapFormat($f);
            if ($mapped !== null) {
                $adaptiveFormats[] = $mapped;
            }
        }

        $thumbs = [];
        foreach ($details['thumbnail']['thumbnails'] ?? [] as $t) {
            if (!empty($t['url'])) {
                $thumbs[] = [
                    'quality' => 'medium',
                    'url' => (string) $t['url'],
                    'width' => (int) ($t['width'] ?? 0),
                    'height' => (int) ($t['height'] ?? 0),
                ];
            }
        }

        $authorId = (string) ($details['channelId'] ?? '');
        $isLive = !empty($details['isLive']) || !empty($details['isLiveContent']);

        return [
            'type' => 'video',
            'title' => (string) $details['title'],
            'videoId' => $videoId,
            'videoThumbnails' => $thumbs,
            'description' => (string) ($details['shortDescription'] ?? ''),
            'publishedText' => '',
            'viewCount' => (int) ($details['viewCount'] ?? 0),
            'likeCount' => 0,
            'author' => (string) ($details['author'] ?? ''),
            'authorId' => $authorId,
            'authorUrl' => $authorId !== '' ? '/channel/' . $authorId : '',
            'lengthSeconds' => (int) ($details['lengthSeconds'] ?? 0),
            'liveNow' => $isLive,
            'formatStreams' => $formatStreams,
            'adaptiveFormats' => $adaptiveFormats,
            'recommendedVideos' => [],
            'hlsUrl' => $streaming['hlsManifestUrl'] ?? null,
            'dashUrl' => $streaming['dashManifestUrl'] ?? null,
            '_source' => 'innertube',
        ];
    }

    private function mapFormat(array $f): ?array
    {
        $url = (string) ($f['url'] ?? '');
        if ($url === '' || !empty($f['signatureCipher'])) {
            return null;
        }
        $mime = (string) ($f['mimeType'] ?? '');
        $container = str_contains($mime, 'webm') ? 'webm' : 'mp4';
        $label = (string) ($f['qualityLabel'] ?? ($f['quality'] ?? ''));
        $itag = (string) ($f['itag'] ?? '');
        $height = 0;
        if (preg_match('/(\d{3,4})p/', $label, $m)) {
            $height = (int) $m[1];
        } elseif (!empty($f['height'])) {
            $height = (int) $f['height'];
            if ($label === '') {
                $label = $height . 'p';
            }
        }

        return [
            'url' => $url,
            'itag' => $itag !== '' ? $itag : ($label . '-' . $container),
            'type' => $mime !== '' ? $mime : ('video/' . $container),
            'quality' => $label,
            'container' => $container,
            'encoding' => '',
            'qualityLabel' => $label !== '' ? $label : 'default',
            'resolution' => $height ? ($height . 'p') : '',
            'size' => '',
        ];
    }

    /**
     * TV OAuth like / dislike / remove (Data API v3 rejects YouTube-TV Bearers).
     *
     * @return array{ok:bool,error?:string,rating?:string}
     */
    public function tvRateVideo(string $accessToken, string $videoId, string $rating): array
    {
        $token = trim($accessToken);
        $videoId = trim($videoId);
        $rating = strtolower(trim($rating));
        if ($token === '' || $videoId === '') {
            return ['ok' => false, 'error' => 'Missing token or video'];
        }
        $path = 'like/like';
        $outRating = 'like';
        if ($rating === 'dislike') {
            $path = 'like/dislike';
            $outRating = 'dislike';
        } elseif ($rating === 'none' || $rating === 'unlike' || $rating === 'undislike') {
            $path = 'like/removelike';
            $outRating = 'none';
        } elseif ($rating !== 'like') {
            return ['ok' => false, 'error' => 'Invalid rating'];
        }
        $resp = $this->tvActionPost($token, $path, [
            'target' => ['videoId' => $videoId],
        ]);
        if ($resp['ok']) {
            return ['ok' => true, 'rating' => $outRating];
        }
        return ['ok' => false, 'error' => $resp['error'] ?? 'Like failed'];
    }

    /**
     * @return array{ok:bool,error?:string,subscriptionId?:string,subscribed?:bool}
     */
    public function tvSubscribe(string $accessToken, string $channelId, bool $subscribe = true): array
    {
        $token = trim($accessToken);
        $channelId = trim($channelId);
        if ($token === '' || $channelId === '') {
            return ['ok' => false, 'error' => 'Missing token or channel'];
        }
        $path = $subscribe ? 'subscription/subscribe' : 'subscription/unsubscribe';
        $resp = $this->tvActionPost($token, $path, [
            'channelIds' => [$channelId],
            'params' => $subscribe ? 'EgIIAhgA' : 'CgIIAhgA',
        ]);
        if ($resp['ok']) {
            return [
                'ok' => true,
                'subscribed' => $subscribe,
                'subscriptionId' => $subscribe ? ('tv_' . $channelId) : '',
            ];
        }
        return ['ok' => false, 'error' => $resp['error'] ?? 'Subscribe failed'];
    }

    /**
     * Post a top-level comment via TV Innertube (pull createCommentParams from /next).
     *
     * @return array{ok:bool,error?:string,id?:string}
     */
    public function tvPostComment(string $accessToken, string $videoId, string $text): array
    {
        $token = trim($accessToken);
        $videoId = trim($videoId);
        $text = trim($text);
        if ($token === '' || $videoId === '' || $text === '') {
            return ['ok' => false, 'error' => 'Missing token, video, or text'];
        }
        $params = $this->tvCreateCommentParams($token, $videoId);
        if ($params === '') {
            return ['ok' => false, 'error' => 'Could not open comment box for this video'];
        }
        $resp = $this->tvActionPost($token, 'comment/create_comment', [
            'commentText' => $text,
            'createCommentParams' => $params,
        ]);
        if ($resp['ok']) {
            return ['ok' => true, 'id' => ''];
        }
        return ['ok' => false, 'error' => $resp['error'] ?? 'Comment failed'];
    }

    /** Extract createCommentParams from watch next response. */
    private function tvCreateCommentParams(string $accessToken, string $videoId): string
    {
        $raw = $this->tvActionRaw($accessToken, 'next', ['videoId' => $videoId]);
        if ($raw === null || $raw === '') {
            return '';
        }
        if (preg_match('/"createCommentParams"\s*:\s*"([^"]+)"/', $raw, $m)) {
            return html_entity_decode(str_replace('\\u003d', '=', $m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    /**
     * @param array<string,mixed> $extra
     * @return array{ok:bool,error?:string,body?:string}
     */
    private function tvActionPost(string $accessToken, string $path, array $extra): array
    {
        $raw = $this->tvActionRaw($accessToken, $path, $extra);
        if ($raw === null) {
            return ['ok' => false, 'error' => 'YouTube TV action failed'];
        }
        if (stripos($raw, '"error"') !== false && preg_match('/"message"\s*:\s*"([^"]+)"/', $raw, $m)) {
            return ['ok' => false, 'error' => html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')];
        }
        return ['ok' => true, 'body' => $raw];
    }

    /** @param array<string,mixed> $extra */
    private function tvActionRaw(string $accessToken, string $path, array $extra = []): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $clientVersion = '7.20260817.13.00';
        $ua = 'Mozilla/5.0 (PlayStation; PlayStation 4/12.00)';
        $payload = array_merge([
            'context' => [
                'client' => [
                    'clientName' => 'TVHTML5',
                    'clientVersion' => $clientVersion,
                    'platform' => 'TV',
                    'hl' => 'fr',
                    'gl' => 'FR',
                    'userAgent' => $ua,
                ],
            ],
        ], $extra);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return null;
        }
        $url = 'https://www.youtube.com/youtubei/v1/' . ltrim($path, '/') . '?prettyPrint=false';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
                'X-YouTube-Client-Name: 7',
                'X-YouTube-Client-Version: ' . $clientVersion,
                'X-Origin: https://www.youtube.com',
                'Origin: https://www.youtube.com',
                'User-Agent: ' . $ua,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => max(12, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => ytl_http_ssl_verify(),
            CURLOPT_SSL_VERIFYHOST => ytl_http_ssl_verify() ? 2 : 0,
        ]);
        ytl_curl_set_proxy($ch);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (!is_string($raw) || $raw === '' || $code < 200 || $code >= 400) {
            $hint = is_string($raw) ? trim(preg_replace('/\s+/', ' ', substr($raw, 0, 140))) : '';
            ytl_http_last_error('TV action ' . $path . ' HTTP ' . $code . ($hint !== '' ? (' — ' . $hint) : ''));
            return null;
        }
        return $raw;
    }
}
