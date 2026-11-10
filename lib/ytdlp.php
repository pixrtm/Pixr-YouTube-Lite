<?php

require_once __DIR__ . '/http.php';

/**
 * Optional yt-dlp backend (needs binary on the server).
 */
class YtDlpClient
{
    private array $config;
    private ?string $activeInstance = null;

    public function __construct(array $config)
    {
        $this->config = $config;
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

    public function channel(string $channelId): ?array
    {
        return null;
    }

    public function channelVideos(string $channelId, ?string $continuation = null): array
    {
        return ['videos' => [], 'continuation' => null];
    }

    public function isAvailable(): bool
    {
        if (function_exists('ytl_convert_available')) {
            require_once __DIR__ . '/convert.php';
            return ytl_convert_available();
        }
        $bin = (string) ($this->config['ytdlp_path'] ?? 'yt-dlp');
        if ($bin === '') {
            return false;
        }
        if (!function_exists('exec') && !function_exists('shell_exec')) {
            return false;
        }
        $out = [];
        $code = 1;
        @exec(escapeshellarg($bin) . ' --version 2>/dev/null', $out, $code);
        return $code === 0 && count($out) > 0;
    }

    public function video(string $videoId): ?array
    {
        $bin = (string) ($this->config['ytdlp_path'] ?? 'yt-dlp');
        $url = 'https://www.youtube.com/watch?v=' . $videoId;
        $proxy = trim((string) ($this->config['http_proxy'] ?? ''));
        if ($proxy === '' && function_exists('ytl_http_proxy_info')) {
            $proxy = (string) (ytl_http_proxy_info()['url'] ?? '');
        }
        $cmd = escapeshellarg($bin) . ' -J --no-playlist --no-warnings --no-check-certificates';
        if ($proxy !== '') {
            $cmd .= ' --proxy ' . escapeshellarg($proxy);
        }
        $cookieFile = (string) ($this->config['youtube_cookie_file'] ?? '');
        if ($cookieFile !== '' && is_readable($cookieFile) && filesize($cookieFile) > 10) {
            $cmd .= ' --cookies ' . escapeshellarg($cookieFile);
        }
        $cmd .= ' --extractor-args ' . escapeshellarg('youtube:player_client=android,android_vr,ios')
            . ' ' . escapeshellarg($url) . ' 2>/dev/null';

        $json = null;
        if (function_exists('shell_exec')) {
            $json = shell_exec($cmd);
        } elseif (function_exists('exec')) {
            $lines = [];
            exec($cmd, $lines);
            $json = implode("\n", $lines);
        }
        if (!is_string($json) || $json === '') {
            ytl_http_last_error('yt-dlp returned empty (not installed, or blocked)');
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['id'])) {
            ytl_http_last_error('yt-dlp invalid JSON');
            return null;
        }

        $this->activeInstance = 'yt-dlp';
        return $this->normalize($videoId, $data);
    }

    private function normalize(string $videoId, array $data): array
    {
        $formats = [];
        foreach ($data['formats'] ?? [] as $f) {
            if (!is_array($f) || empty($f['url'])) {
                continue;
            }
            // Need progressive (audio+video in one file)
            $vc = $f['vcodec'] ?? 'none';
            $ac = $f['acodec'] ?? 'none';
            if ($vc === 'none' || $ac === 'none') {
                continue;
            }
            $ext = strtolower((string) ($f['ext'] ?? 'mp4'));
            if ($ext !== 'mp4' && $ext !== 'webm') {
                continue;
            }
            $height = (int) ($f['height'] ?? 0);
            $label = $height > 0 ? ($height . 'p') : (string) ($f['format_note'] ?? 'default');
            $formats[] = [
                'url' => (string) $f['url'],
                'itag' => (string) ($f['format_id'] ?? $label),
                'type' => 'video/' . $ext,
                'quality' => $label,
                'container' => $ext,
                'encoding' => '',
                'qualityLabel' => $label,
                'resolution' => $height ? ($height . 'p') : '',
                'size' => '',
            ];
        }

        $thumbs = [];
        if (!empty($data['thumbnail'])) {
            $thumbs[] = [
                'quality' => 'hqdefault',
                'url' => (string) $data['thumbnail'],
                'width' => 480,
                'height' => 360,
            ];
        }

        return [
            'type' => 'video',
            'title' => (string) ($data['title'] ?? ''),
            'videoId' => $videoId,
            'videoThumbnails' => $thumbs,
            'description' => (string) ($data['description'] ?? ''),
            'publishedText' => '',
            'viewCount' => (int) ($data['view_count'] ?? 0),
            'likeCount' => (int) ($data['like_count'] ?? 0),
            'author' => (string) ($data['uploader'] ?? ''),
            'authorId' => (string) ($data['channel_id'] ?? ''),
            'authorUrl' => (string) ($data['channel_url'] ?? ''),
            'lengthSeconds' => (int) ($data['duration'] ?? 0),
            'liveNow' => !empty($data['is_live']),
            'formatStreams' => $formats,
            'adaptiveFormats' => [],
            'recommendedVideos' => [],
            'hlsUrl' => $data['manifest_url'] ?? null,
            'dashUrl' => null,
            '_source' => 'yt-dlp',
        ];
    }
}
