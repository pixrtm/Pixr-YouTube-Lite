<?php

require_once __DIR__ . '/http.php';

/**
 * Piped API client (Invidious-compatible normalized output).
 */
class PipedClient
{
    /** @var string[] */
    private array $instances;
    private int $timeout;
    private ?string $activeInstance = null;

    public function __construct(array $config)
    {
        $this->instances = array_values(array_filter($config['piped_instances'] ?? []));
        $this->timeout = (int) ($config['http_timeout'] ?? 15);
    }

    public function getActiveInstance(): ?string
    {
        return $this->activeInstance;
    }

    public function search(string $query, int $page = 1): array
    {
        // Piped search has no classic page param on all instances; ignore page > 1 softly
        $q = rawurlencode($query);
        $data = $this->apiGet("/search?q={$q}&filter=videos");
        if ($data === null) {
            $data = $this->apiGet("/search?q={$q}&filter=all");
        }
        if (!is_array($data)) {
            return [];
        }
        $items = $data['items'] ?? $data;
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = $item['type'] ?? 'stream';
            if ($type !== 'stream' && $type !== 'video') {
                continue;
            }
            $norm = self::normalizeListItem($item);
            if ($norm !== null) {
                $out[] = $norm;
            }
        }
        return $out;
    }

    public function trending(?string $region = 'US'): array
    {
        $region = $region ?: 'US';
        $data = $this->apiGet('/trending?region=' . rawurlencode($region));
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $norm = self::normalizeListItem($item);
            if ($norm !== null) {
                $out[] = $norm;
            }
        }
        return $out;
    }

    public function video(string $videoId): ?array
    {
        $id = rawurlencode($videoId);
        $data = $this->apiGet("/streams/{$id}");
        if (!is_array($data) || empty($data['title']) || !empty($data['error'])) {
            return null;
        }
        return self::normalizeVideo($videoId, $data);
    }

    /**
     * Flux muxés de chaque instance (pas seulement la plus rapide).
     * Une instance peut être bloquée par YouTube pendant qu’une autre sert encore le MP4.
     *
     * @return list<array{url:string,host:string,height:int,container:string,backend:string}>
     */
    public function muxedFromAllInstances(string $videoId): array
    {
        $urls = [];
        foreach ($this->instances as $base) {
            $base = rtrim($base, '/');
            $urls[$base] = $base . '/streams/' . rawurlencode($videoId);
        }
        if (count($urls) === 0 || !function_exists('curl_multi_init')) {
            return [];
        }

        $timeout = min(12, max(6, $this->timeout));
        $sslVerify = function_exists('ytl_http_ssl_verify') ? ytl_http_ssl_verify() : false;
        $mh = curl_multi_init();
        $map = [];
        foreach ($urls as $base => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept: application/json, text/plain, */*',
                ],
                CURLOPT_SSL_VERIFYPEER => $sslVerify,
                CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
                CURLOPT_ENCODING => '',
            ]);
            if (defined('CURL_IPRESOLVE_V4')) {
                curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            }
            curl_multi_add_handle($mh, $ch);
            $map[(int) $ch] = $ch;
        }
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.2);
            }
        } while ($running && $status === CURLM_OK);

        // One candidate per Piped API instance (do not collapse by stream host —
        // many instances share proxy.piped.* hosts and 403 independently).
        $best = [];
        $i = 0;
        foreach ($map as $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = curl_multi_getcontent($ch);
            $eff = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_multi_remove_handle($mh, $ch);
            $i++;
            if ($code < 200 || $code >= 400 || !is_string($body) || $body === '') {
                continue;
            }
            $data = json_decode($body, true);
            if (!is_array($data) || empty($data['title']) || !empty($data['error'])) {
                continue;
            }
            $apiHost = strtolower((string) (parse_url($eff, PHP_URL_HOST) ?? ('piped' . $i)));
            $norm = self::normalizeVideo($videoId, $data);
            $picked = null;
            foreach ($norm['formatStreams'] as $f) {
                if (!is_array($f) || empty($f['url'])) {
                    continue;
                }
                $host = strtolower((string) (parse_url((string) $f['url'], PHP_URL_HOST) ?? ''));
                if ($host === '') {
                    continue;
                }
                $container = strtolower((string) ($f['container'] ?? ''));
                $height = 0;
                if (preg_match('/(\d{3,4})/', (string) ($f['qualityLabel'] ?? ''), $m)) {
                    $height = (int) $m[1];
                }
                $row = [
                    'url' => (string) $f['url'],
                    'host' => $host,
                    'height' => $height,
                    'container' => $container,
                    'backend' => 'piped',
                    'api' => $apiHost,
                    'referer' => 'https://' . $apiHost . '/',
                ];
                if ($picked === null) {
                    $picked = $row;
                    continue;
                }
                $rowMp4 = $container === 'mp4';
                $prevMp4 = $picked['container'] === 'mp4';
                if ($rowMp4 !== $prevMp4) {
                    if ($rowMp4) {
                        $picked = $row;
                    }
                    continue;
                }
                if (abs($height - 360) < abs((int) $picked['height'] - 360)) {
                    $picked = $row;
                }
            }
            if ($picked !== null) {
                $best[$apiHost] = $picked;
            }
        }
        curl_multi_close($mh);
        return array_values($best);
    }

    public function channel(string $channelId): ?array
    {
        $id = rawurlencode($channelId);
        $data = $this->apiGet("/channel/{$id}");
        if (!is_array($data)) {
            return null;
        }
        return [
            'author' => (string) ($data['name'] ?? $channelId),
            'authorId' => $channelId,
            'description' => (string) ($data['description'] ?? ''),
            'subCount' => (int) ($data['subscriberCount'] ?? 0),
            'avatarUrl' => (string) ($data['avatarUrl'] ?? ''),
            'verified' => !empty($data['verified']),
        ];
    }

    public function channelVideos(string $channelId, ?string $continuation = null): array
    {
        $id = rawurlencode($channelId);
        $path = "/channel/{$id}";
        if ($continuation && str_starts_with($continuation, 'search:')) {
            // Search fallback has no real continuation
            return ['videos' => [], 'continuation' => null];
        }
        if ($continuation) {
            $path = '/nextpage/channel/' . $id . '?nextpage=' . rawurlencode($continuation);
        }
        $data = $this->apiGet($path);
        $videos = [];
        $next = null;
        if (is_array($data)) {
            $related = $data['relatedStreams'] ?? [];
            foreach ($related as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $norm = self::normalizeListItem($item);
                if ($norm !== null) {
                    $videos[] = $norm;
                }
            }
            $next = $data['nextpage'] ?? null;
        }

        // Many Piped instances currently return an empty relatedStreams list.
        // Fall back to a name search filtered by channel id.
        if (count($videos) === 0 && !$continuation) {
            $meta = $this->channel($channelId);
            $name = is_array($meta) ? trim((string) ($meta['author'] ?? '')) : '';
            if ($name !== '') {
                $results = $this->search($name, 1);
                foreach ($results as $row) {
                    if (($row['authorId'] ?? '') === $channelId) {
                        $videos[] = $row;
                    }
                }
            }
            return ['videos' => $videos, 'continuation' => null];
        }

        return [
            'videos' => $videos,
            'continuation' => $next,
        ];
    }

    /** @return array{comments:list<array<string,mixed>>,continuation:?string,count:int,disabled:bool}|null */
    public function comments(string $videoId, ?string $continuation = null): ?array
    {
        $path = '/comments/' . rawurlencode($videoId);
        if ($continuation !== null && $continuation !== '') {
            $path .= '?nextpage=' . rawurlencode($continuation);
        }
        $data = $this->apiGet($path);
        if (!is_array($data) || !array_key_exists('comments', $data)) {
            return null;
        }
        $comments = [];
        foreach ($data['comments'] as $c) {
            if (!is_array($c)) {
                continue;
            }
            $text = html_entity_decode(strip_tags((string) ($c['commentText'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $comments[] = [
                'author' => (string) ($c['author'] ?? ''),
                'authorId' => self::extractIdFromUrl((string) ($c['commentorUrl'] ?? '')),
                'text' => trim($text),
                'publishedText' => (string) ($c['commentedTime'] ?? ''),
                'likeCount' => (int) ($c['likeCount'] ?? 0),
                'replyCount' => (int) ($c['replyCount'] ?? 0),
                'pinned' => !empty($c['pinned']),
            ];
        }
        $next = $data['nextpage'] ?? null;
        return [
            'comments' => $comments,
            'continuation' => is_string($next) && $next !== '' ? $next : null,
            'count' => (int) ($data['commentCount'] ?? count($comments)),
            'disabled' => !empty($data['disabled']),
        ];
    }

    private function apiGet(string $pathAndQuery): ?array
    {
        $urls = [];
        foreach ($this->instances as $base) {
            $urls[] = rtrim($base, '/') . $pathAndQuery;
        }
        $race = ytl_http_get_json_race($urls, $this->timeout);
        if ($race === null) {
            return null;
        }
        [$data, $url] = $race;
        foreach ($this->instances as $base) {
            $base = rtrim($base, '/');
            if (str_starts_with($url, $base)) {
                $this->activeInstance = $base;
                break;
            }
        }
        if ($this->activeInstance === null && count($urls) > 0) {
            $this->activeInstance = rtrim((string) $this->instances[0], '/');
        }
        return $data;
    }

    public static function extractIdFromUrl(string $url): string
    {
        if (preg_match('/[?&]v=([A-Za-z0-9_-]{6,20})/', $url, $m)) {
            return $m[1];
        }
        if (preg_match('/\/watch\/([A-Za-z0-9_-]{6,20})/', $url, $m)) {
            return $m[1];
        }
        if (preg_match('/\/channel\/([A-Za-z0-9_-]+)/', $url, $m)) {
            return $m[1];
        }
        return '';
    }

    public static function normalizeListItem(array $item): ?array
    {
        $url = (string) ($item['url'] ?? '');
        $id = self::extractIdFromUrl($url);
        if ($id === '' && !empty($item['videoId'])) {
            $id = (string) $item['videoId'];
        }
        if ($id === '') {
            return null;
        }
        $thumb = (string) ($item['thumbnail'] ?? '');
        $authorUrl = (string) ($item['uploaderUrl'] ?? '');
        $authorId = self::extractIdFromUrl($authorUrl);

        return [
            'type' => 'video',
            'title' => (string) ($item['title'] ?? 'Untitled'),
            'videoId' => $id,
            'author' => (string) ($item['uploaderName'] ?? ''),
            'authorId' => $authorId,
            'authorUrl' => $authorUrl,
            'videoThumbnails' => $thumb !== '' ? [
                ['quality' => 'medium', 'url' => $thumb, 'width' => 320, 'height' => 180],
            ] : [],
            'description' => (string) ($item['shortDescription'] ?? ''),
            'viewCount' => (int) ($item['views'] ?? 0),
            'published' => (int) ($item['uploaded'] ?? 0),
            'publishedText' => (string) ($item['uploadedDate'] ?? ''),
            // Piped uses -1 for unknown duration — never treat as live solely from that.
            'lengthSeconds' => max(0, (int) ($item['duration'] ?? 0)),
            'liveNow' => self::itemIsLive($item),
        ];
    }

    public static function itemIsLive(array $item): bool
    {
        if (!empty($item['livestream']) || !empty($item['isLive']) || !empty($item['liveNow'])) {
            return true;
        }
        if (!empty($item['isShort'])) {
            return false;
        }
        $date = strtolower((string) ($item['uploadedDate'] ?? ''));
        $title = strtolower((string) ($item['title'] ?? ''));
        if ($date === 'live' || str_starts_with($title, 'live ') || str_starts_with($title, 'live:') || str_contains($title, '🔴')) {
            return true;
        }
        // uploaded=-1 / duration=-1 are "unknown" on modern Piped — NOT livestream markers.
        return false;
    }

    public static function normalizeVideo(string $videoId, array $data): array
    {
        $thumbs = [];
        if (!empty($data['thumbnailUrl'])) {
            $thumbs[] = [
                'quality' => 'maxres',
                'url' => (string) $data['thumbnailUrl'],
                'width' => 1280,
                'height' => 720,
            ];
        }

        $formats = [];
        foreach ($data['videoStreams'] ?? [] as $s) {
            if (!is_array($s) || empty($s['url'])) {
                continue;
            }
            // Skip video-only (DASH); old players need muxed A+V
            if (!empty($s['videoOnly'])) {
                continue;
            }
            $quality = (string) ($s['quality'] ?? $s['qualityLabel'] ?? '');
            $format = strtoupper((string) ($s['format'] ?? ''));
            $container = 'mp4';
            if (str_contains($format, 'WEBM') || str_contains(strtolower((string) ($s['mimeType'] ?? '')), 'webm')) {
                $container = 'webm';
            } elseif (str_contains($format, 'HLS')) {
                // HLS not useful for progressive <video> on ancient IE
                continue;
            }
            $height = 0;
            if (preg_match('/(\d{3,4})p/', $quality, $m)) {
                $height = (int) $m[1];
            }
            $itag = $quality . '-' . $container . '-' . substr(md5($s['url']), 0, 6);
            $formats[] = [
                'url' => (string) $s['url'],
                'itag' => $itag,
                'type' => (string) ($s['mimeType'] ?? ('video/' . $container)),
                'quality' => $quality,
                'container' => $container,
                'encoding' => (string) ($s['codec'] ?? ''),
                'qualityLabel' => $quality !== '' ? $quality : ($height ? $height . 'p' : 'default'),
                'resolution' => $height ? ($height . 'p') : '',
                'size' => '',
            ];
        }

        $recommended = [];
        foreach ($data['relatedStreams'] ?? [] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $norm = self::normalizeListItem($r);
            if ($norm !== null) {
                $recommended[] = $norm;
            }
        }

        $uploaderUrl = (string) ($data['uploaderUrl'] ?? '');
        $authorId = self::extractIdFromUrl($uploaderUrl);

        $avatar = trim((string) ($data['uploaderAvatar'] ?? $data['avatarUrl'] ?? ''));

        return [
            'type' => 'video',
            'title' => (string) ($data['title'] ?? ''),
            'videoId' => $videoId,
            'videoThumbnails' => $thumbs,
            'description' => (string) ($data['description'] ?? ''),
            'descriptionHtml' => (string) ($data['description'] ?? ''),
            'published' => 0,
            'publishedText' => isset($data['uploadDate']) ? (string) $data['uploadDate'] : '',
            'viewCount' => (int) ($data['views'] ?? 0),
            'likeCount' => (int) ($data['likes'] ?? $data['likeCount'] ?? 0),
            'dislikeCount' => (int) ($data['dislikes'] ?? $data['dislikeCount'] ?? -1),
            'subCount' => (int) ($data['uploaderSubscriberCount'] ?? $data['subscriberCount'] ?? 0),
            'author' => (string) ($data['uploader'] ?? ''),
            'authorId' => $authorId,
            'authorUrl' => $uploaderUrl,
            'avatarUrl' => $avatar,
            'authorThumbnails' => $avatar !== '' ? [['url' => $avatar, 'width' => 88, 'height' => 88]] : [],
            'lengthSeconds' => (int) ($data['duration'] ?? 0),
            'formatStreams' => $formats,
            'adaptiveFormats' => [],
            'recommendedVideos' => $recommended,
            'hlsUrl' => $data['hls'] ?? null,
            'dashUrl' => $data['dash'] ?? null,
        ];
    }
}
