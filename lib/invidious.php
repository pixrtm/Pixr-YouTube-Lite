<?php

require_once __DIR__ . '/http.php';

/**
 * Invidious API client with instance failover.
 */
class InvidiousClient
{
    /** @var string[] */
    private array $instances;
    private int $timeout;
    private ?string $activeInstance = null;

    public function __construct(array $config)
    {
        $this->instances = array_values(array_filter($config['invidious_instances'] ?? []));
        $this->timeout = (int) ($config['http_timeout'] ?? 15);
    }

    public function getActiveInstance(): ?string
    {
        return $this->activeInstance;
    }

    public function search(string $query, int $page = 1): array
    {
        $q = rawurlencode($query);
        $page = max(1, $page);
        $data = $this->apiGet("/api/v1/search?q={$q}&page={$page}&type=video");
        if (!is_array($data)) {
            return [];
        }
        return $data;
    }

    public function trending(?string $region = null): array
    {
        $path = '/api/v1/trending';
        if ($region) {
            $path .= '?region=' . rawurlencode($region);
        }
        $data = $this->apiGet($path);
        if (!is_array($data)) {
            return [];
        }
        return $data;
    }

    /**
     * Logged-out style popular videos (mainstream channels) — better guest home than /trending.
     *
     * @return list<array<string,mixed>>
     */
    public function popular(): array
    {
        $data = $this->apiGet('/api/v1/popular');
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string) ($item['videoId'] ?? '');
            if ($id === '') {
                continue;
            }
            // Skip livestreams / zero-length placeholders.
            if (!empty($item['liveNow']) || (($item['lengthSeconds'] ?? 1) === 0 && ($item['viewCount'] ?? 0) < 1000)) {
                continue;
            }
            $item['type'] = $item['type'] ?? 'video';
            $out[] = $item;
        }
        return $out;
    }

    public function video(string $videoId): ?array
    {
        $id = rawurlencode($videoId);
        $data = $this->apiGet("/api/v1/videos/{$id}");
        if (!is_array($data) || empty($data['videoId'])) {
            return null;
        }
        return $data;
    }

    public function channel(string $channelId): ?array
    {
        $id = rawurlencode($channelId);
        $data = $this->apiGet("/api/v1/channels/{$id}");
        if (!is_array($data)) {
            return null;
        }
        return $data;
    }

    public function channelVideos(string $channelId, ?string $continuation = null): array
    {
        $id = rawurlencode($channelId);
        $path = "/api/v1/channels/{$id}/videos";
        if ($continuation) {
            $path .= '?continuation=' . rawurlencode($continuation);
        }
        $data = $this->apiGet($path);
        if (!is_array($data)) {
            return ['videos' => [], 'continuation' => null];
        }
        return [
            'videos' => $data['videos'] ?? $data,
            'continuation' => $data['continuation'] ?? null,
        ];
    }

    /** @return array{comments:list<array<string,mixed>>,continuation:?string,count:int,disabled:bool}|null */
    public function comments(string $videoId, ?string $continuation = null): ?array
    {
        $path = '/api/v1/comments/' . rawurlencode($videoId);
        if ($continuation !== null && $continuation !== '') {
            $path .= '?continuation=' . rawurlencode($continuation);
        }
        $data = $this->apiGet($path);
        if (!is_array($data) || !isset($data['comments']) || !is_array($data['comments'])) {
            return null;
        }
        $comments = [];
        foreach ($data['comments'] as $c) {
            if (!is_array($c)) {
                continue;
            }
            $text = html_entity_decode(strip_tags((string) ($c['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $replies = is_array($c['replies'] ?? null) ? $c['replies'] : [];
            $comments[] = [
                'author' => (string) ($c['author'] ?? ''),
                'authorId' => (string) ($c['authorId'] ?? ''),
                'text' => trim($text),
                'publishedText' => (string) ($c['publishedText'] ?? ''),
                'likeCount' => (int) ($c['likeCount'] ?? 0),
                'replyCount' => (int) ($replies['replyCount'] ?? 0),
                'pinned' => !empty($c['pinned']),
            ];
        }
        $next = $data['continuation'] ?? null;
        return [
            'comments' => $comments,
            'continuation' => is_string($next) && $next !== '' ? $next : null,
            'count' => (int) ($data['commentCount'] ?? count($comments)),
            'disabled' => false,
        ];
    }

    /**
     * Pick progressive (muxed) formatStreams, preferring MP4 near $preferredHeight.
     *
     * @return array{url:string,qualityLabel:string,container:string,resolution:string,itag:string}|null
     */
    public function pickProgressiveFormat(array $video, int $preferredHeight = 360): ?array
    {
        $streams = $video['formatStreams'] ?? [];
        if (!is_array($streams) || count($streams) === 0) {
            return null;
        }

        $scored = [];
        foreach ($streams as $s) {
            if (!is_array($s) || empty($s['url'])) {
                continue;
            }
            $container = strtolower((string) ($s['container'] ?? ''));
            $type = strtolower((string) ($s['type'] ?? ''));
            $label = (string) ($s['qualityLabel'] ?? $s['quality'] ?? '');
            $height = self::parseHeight($label, (string) ($s['resolution'] ?? ''));

            // Prefer mp4 near requested height; deprioritize unknown/LBRY (height 0)
            $score = abs($height - $preferredHeight);
            if ($height <= 0) {
                $score += 200;
            }
            if ($container === 'mp4' || str_contains($type, 'mp4')) {
                $score -= 50;
            }
            if ($container === 'webm' || str_contains($type, 'webm')) {
                $score += 20;
            }
            if (stripos($label, 'LBRY') !== false) {
                $score += 100;
            }

            $scored[] = [
                'score' => $score,
                'height' => $height,
                'stream' => [
                    'url' => (string) $s['url'],
                    'qualityLabel' => $label !== '' ? $label : ($height ? $height . 'p' : 'unknown'),
                    'container' => $container !== '' ? $container : 'mp4',
                    'resolution' => (string) ($s['resolution'] ?? ''),
                    'itag' => (string) ($s['itag'] ?? ''),
                ],
            ];
        }

        if (count($scored) === 0) {
            return null;
        }

        usort($scored, static function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $b['height'] <=> $a['height'];
            }
            return $a['score'] <=> $b['score'];
        });

        return $scored[0]['stream'];
    }

    /**
     * List unique progressive qualities for UI links.
     *
     * @return list<array{url:string,qualityLabel:string,container:string,resolution:string,itag:string,height:int}>
     */
    public function listProgressiveFormats(array $video): array
    {
        $streams = $video['formatStreams'] ?? [];
        $out = [];
        $seen = [];
        foreach ($streams as $s) {
            if (!is_array($s) || empty($s['url'])) {
                continue;
            }
            $label = (string) ($s['qualityLabel'] ?? $s['quality'] ?? '');
            $height = self::parseHeight($label, (string) ($s['resolution'] ?? ''));
            $key = $height . '|' . strtolower((string) ($s['container'] ?? ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'url' => (string) $s['url'],
                'qualityLabel' => $label !== '' ? $label : ($height ? $height . 'p' : 'unknown'),
                'container' => strtolower((string) ($s['container'] ?? 'mp4')),
                'resolution' => (string) ($s['resolution'] ?? ''),
                'itag' => (string) ($s['itag'] ?? ''),
                'height' => $height,
            ];
        }
        usort($out, static fn($a, $b) => $a['height'] <=> $b['height']);
        return $out;
    }

    public function resolveStreamUrl(array $video, ?string $itag, int $preferredHeight): ?array
    {
        $formats = $this->listProgressiveFormats($video);
        if ($itag !== null && $itag !== '') {
            foreach ($formats as $f) {
                if ($f['itag'] === $itag) {
                    return $f;
                }
            }
        }
        return $this->pickProgressiveFormat($video, $preferredHeight);
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
        if ($this->activeInstance === null && count($this->instances) > 0) {
            $this->activeInstance = rtrim((string) $this->instances[0], '/');
        }
        return $data;
    }

    public static function parseHeight(string $qualityLabel, string $resolution = ''): int
    {
        if (preg_match('/(\d{3,4})p/', $qualityLabel, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(\d+)\s*x\s*(\d+)/', $resolution, $m)) {
            return (int) $m[2];
        }
        if (preg_match('/^(\d+)$/', trim($qualityLabel), $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    public static function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0:00';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }
        return sprintf('%d:%02d', $m, $s);
    }

    public static function formatViews($count): string
    {
        $n = (int) $count;
        if ($n >= 1000000000) {
            return round($n / 1000000000, 1) . 'B';
        }
        if ($n >= 1000000) {
            return round($n / 1000000, 1) . 'M';
        }
        if ($n >= 1000) {
            return round($n / 1000, 1) . 'K';
        }
        return (string) $n;
    }

    public static function bestThumbnail(array $thumbnails): string
    {
        if (count($thumbnails) === 0) {
            return '';
        }
        // mqdefault / maxres = vrai 16:9. hqdefault = 4:3 avec bandes noires.
        $prefer = ['mqdefault', 'maxres', 'maxresdefault', 'medium', 'high', 'sddefault', 'hqdefault'];
        foreach ($prefer as $q) {
            foreach ($thumbnails as $t) {
                if (($t['quality'] ?? '') === $q && !empty($t['url'])) {
                    return (string) $t['url'];
                }
            }
        }
        // Fall back to last (often largest) then first
        $last = end($thumbnails);
        if (is_array($last) && !empty($last['url'])) {
            return (string) $last['url'];
        }
        return (string) ($thumbnails[0]['url'] ?? '');
    }
}
