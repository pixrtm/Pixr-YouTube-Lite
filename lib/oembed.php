<?php

require_once __DIR__ . '/http.php';

/**
 * YouTube oEmbed — metadata without stream extraction.
 * Works from most datacenter IPs when Innertube is bot-blocked.
 */
class OEmbedClient
{
    private int $timeout;
    private ?string $activeInstance = 'https://www.youtube.com/oembed';

    public function __construct(array $config)
    {
        $this->timeout = (int) ($config['http_timeout'] ?? 15);
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

    public function video(string $videoId): ?array
    {
        $watch = 'https://www.youtube.com/watch?v=' . rawurlencode($videoId);
        $url = 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode($watch);
        $data = ytl_http_get_json($url, $this->timeout);
        if (!is_array($data) || empty($data['title'])) {
            return null;
        }

        $thumb = (string) ($data['thumbnail_url'] ?? ('https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg'));
        $author = (string) ($data['author_name'] ?? '');
        $authorUrl = (string) ($data['author_url'] ?? '');
        $authorId = '';
        if (preg_match('#/(channel|user|c|@)([^/?]+)#', $authorUrl, $m)) {
            $authorId = $m[2];
        }

        return [
            'type' => 'video',
            'title' => (string) $data['title'],
            'videoId' => $videoId,
            'videoThumbnails' => [
                ['quality' => 'hqdefault', 'url' => $thumb, 'width' => 480, 'height' => 360],
            ],
            'description' => '',
            'publishedText' => '',
            'viewCount' => 0,
            'likeCount' => 0,
            'author' => $author,
            'authorId' => $authorId,
            'authorUrl' => $authorUrl,
            'lengthSeconds' => 0,
            'formatStreams' => [],
            'adaptiveFormats' => [],
            'recommendedVideos' => [],
            'hlsUrl' => null,
            'dashUrl' => null,
            '_source' => 'oembed',
            '_embed_only' => true,
        ];
    }
}
