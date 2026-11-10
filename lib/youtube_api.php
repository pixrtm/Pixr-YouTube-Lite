<?php

/**
 * YouTube Data API v3 wrappers (user OAuth Bearer).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/oauth.php';

class YtlYoutubeApi
{
    private string $accessToken;
    private int $timeout;

    public function __construct(string $accessToken, int $timeout = 20)
    {
        $this->accessToken = trim($accessToken);
        $this->timeout = $timeout;
    }

    public static function forCurrentUser(): ?self
    {
        // Prefer any session Bearer (YouTube TV Sign-in OR Cloud OAuth).
        if (!function_exists('ytl_session_bearer')) {
            require_once __DIR__ . '/helpers.php';
        }
        $token = function_exists('ytl_session_bearer') ? ytl_session_bearer() : ytl_user_access_token();
        if ($token === null || $token === '') {
            return null;
        }
        $cfg = ytl_config();
        return new self($token, (int) ($cfg['http_timeout'] ?? 20));
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public function rateVideo(string $videoId, string $rating): array
    {
        $rating = strtolower($rating);
        if (!in_array($rating, ['like', 'dislike', 'none'], true)) {
            return ['ok' => false, 'error' => 'Invalid rating'];
        }
        $url = 'https://www.googleapis.com/youtube/v3/videos/rate?' . http_build_query([
            'id' => $videoId,
            'rating' => $rating,
        ]);
        $resp = $this->request('POST', $url, null, true);
        if ($resp['code'] >= 200 && $resp['code'] < 300) {
            return ['ok' => true, 'rating' => $rating];
        }
        return ['ok' => false, 'error' => $resp['error'] ?? ('HTTP ' . $resp['code'])];
    }

    /**
     * Current user rating for a video: like | dislike | none | unspecified.
     *
     * @return array{ok:bool,rating:string,error?:string}
     */
    public function getVideoRating(string $videoId): array
    {
        $url = 'https://www.googleapis.com/youtube/v3/videos/getRating?' . http_build_query([
            'id' => $videoId,
        ]);
        $resp = $this->request('GET', $url);
        if ($resp['code'] < 200 || $resp['code'] >= 300 || !is_array($resp['json'])) {
            return ['ok' => false, 'rating' => 'none', 'error' => $resp['error'] ?? ('HTTP ' . $resp['code'])];
        }
        $items = $resp['json']['items'] ?? [];
        $rating = 'none';
        if (is_array($items) && isset($items[0]) && is_array($items[0])) {
            $rating = strtolower((string) ($items[0]['rating'] ?? 'none'));
        }
        if (!in_array($rating, ['like', 'dislike', 'none', 'unspecified'], true)) {
            $rating = 'none';
        }
        if ($rating === 'unspecified') {
            $rating = 'none';
        }
        return ['ok' => true, 'rating' => $rating];
    }

    /**
     * Find the current user's subscription id for a channel (empty if not subscribed).
     */
    public function subscriptionIdForChannel(string $channelId): string
    {
        $channelId = trim($channelId);
        if ($channelId === '') {
            return '';
        }
        $url = 'https://www.googleapis.com/youtube/v3/subscriptions?' . http_build_query([
            'part' => 'id,snippet',
            'mine' => 'true',
            'forChannelId' => $channelId,
            'maxResults' => 1,
        ]);
        $resp = $this->request('GET', $url);
        if ($resp['code'] < 200 || $resp['code'] >= 300 || !is_array($resp['json'])) {
            return '';
        }
        $items = $resp['json']['items'] ?? [];
        if (!is_array($items) || !isset($items[0]) || !is_array($items[0])) {
            return '';
        }
        return (string) ($items[0]['id'] ?? '');
    }

    /**
     * @return array{ok:bool,error?:string,id?:string}
     */
    public function postComment(string $videoId, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'error' => 'Empty comment'];
        }
        $body = [
            'snippet' => [
                'videoId' => $videoId,
                'topLevelComment' => [
                    'snippet' => [
                        'textOriginal' => $text,
                    ],
                ],
            ],
        ];
        $url = 'https://www.googleapis.com/youtube/v3/commentThreads?part=snippet';
        $resp = $this->request('POST', $url, $body);
        if ($resp['code'] >= 200 && $resp['code'] < 300 && is_array($resp['json'])) {
            $id = (string) ($resp['json']['id'] ?? '');
            return ['ok' => true, 'id' => $id];
        }
        return ['ok' => false, 'error' => $resp['error'] ?? ('HTTP ' . $resp['code'])];
    }

    /**
     * @return array{ok:bool,error?:string,subscriptionId?:string}
     */
    public function subscribe(string $channelId): array
    {
        $body = [
            'snippet' => [
                'resourceId' => [
                    'kind' => 'youtube#channel',
                    'channelId' => $channelId,
                ],
            ],
        ];
        $url = 'https://www.googleapis.com/youtube/v3/subscriptions?part=snippet';
        $resp = $this->request('POST', $url, $body);
        if ($resp['code'] >= 200 && $resp['code'] < 300 && is_array($resp['json'])) {
            return ['ok' => true, 'subscriptionId' => (string) ($resp['json']['id'] ?? '')];
        }
        return ['ok' => false, 'error' => $resp['error'] ?? ('HTTP ' . $resp['code'])];
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public function unsubscribe(string $subscriptionId): array
    {
        $url = 'https://www.googleapis.com/youtube/v3/subscriptions?' . http_build_query([
            'id' => $subscriptionId,
        ]);
        $resp = $this->request('DELETE', $url, null, true);
        if ($resp['code'] >= 200 && $resp['code'] < 300) {
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => $resp['error'] ?? ('HTTP ' . $resp['code'])];
    }

    /**
     * @return list<array{channelId:string,title:string,thumbnail:string,subscriptionId:string}>
     */
    public function listSubscriptions(int $max = 50): array
    {
        $url = 'https://www.googleapis.com/youtube/v3/subscriptions?' . http_build_query([
            'part' => 'snippet',
            'mine' => 'true',
            'maxResults' => min(50, max(1, $max)),
            'order' => 'relevance',
        ]);
        $resp = $this->request('GET', $url);
        $out = [];
        if ($resp['code'] < 200 || $resp['code'] >= 300 || !is_array($resp['json'])) {
            return $out;
        }
        foreach (($resp['json']['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $snip = $item['snippet'] ?? [];
            if (!is_array($snip)) {
                continue;
            }
            $res = $snip['resourceId'] ?? [];
            $channelId = is_array($res) ? (string) ($res['channelId'] ?? '') : '';
            if ($channelId === '') {
                continue;
            }
            $thumbs = is_array($snip['thumbnails'] ?? null) ? $snip['thumbnails'] : [];
            $thumb = '';
            foreach (['medium', 'default', 'high'] as $k) {
                if (isset($thumbs[$k]['url'])) {
                    $thumb = (string) $thumbs[$k]['url'];
                    break;
                }
            }
            $out[] = [
                'channelId' => $channelId,
                'title' => (string) ($snip['title'] ?? ''),
                'thumbnail' => $thumb,
                'subscriptionId' => (string) ($item['id'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Library playlists via Data API (liked / watch later) — no Netscape paste.
     *
     * @return list<array<string,mixed>>
     */
    public function libraryPlaylist(string $kind, int $limit = 40): array
    {
        $kind = preg_replace('/[^a-z_]/', '', $kind) ?: '';
        $map = [
            'liked' => 'likes',
            'watch_later' => 'watchLater',
        ];
        if (!isset($map[$kind])) {
            return [];
        }
        $url = 'https://www.googleapis.com/youtube/v3/channels?' . http_build_query([
            'part' => 'contentDetails',
            'mine' => 'true',
        ]);
        $resp = $this->request('GET', $url);
        if (!is_array($resp['json'])) {
            return [];
        }
        $items = $resp['json']['items'] ?? [];
        if (!is_array($items) || !isset($items[0]) || !is_array($items[0])) {
            return [];
        }
        $related = $items[0]['contentDetails']['relatedPlaylists'] ?? [];
        if (!is_array($related)) {
            return [];
        }
        $playlistId = (string) ($related[$map[$kind]] ?? '');
        if ($playlistId === '') {
            return [];
        }
        return array_slice($this->playlistItems($playlistId, min(50, max(1, $limit))), 0, $limit);
    }

    /**
     * Recent uploads from subscribed channels (quota-aware, limited channels).
     *
     * @return list<array<string,mixed>>
     */
    public function subscriptionVideos(int $limit = 28): array
    {
        $subs = $this->listSubscriptions(25);
        if (count($subs) === 0) {
            return [];
        }
        $channelIds = array_map(static fn($s) => $s['channelId'], $subs);
        $uploads = $this->uploadsPlaylists(array_slice($channelIds, 0, 12));
        $videos = [];
        $seen = [];
        foreach ($uploads as $playlistId) {
            if (count($videos) >= $limit * 2) {
                break;
            }
            foreach ($this->playlistItems($playlistId, 4) as $v) {
                $id = (string) ($v['videoId'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $videos[] = $v;
            }
        }
        usort($videos, static function ($a, $b) {
            return strcmp((string) ($b['published'] ?? ''), (string) ($a['published'] ?? ''));
        });
        return array_slice($videos, 0, $limit);
    }

    /**
     * @param list<string> $channelIds
     * @return list<string> uploads playlist ids
     */
    private function uploadsPlaylists(array $channelIds): array
    {
        if (count($channelIds) === 0) {
            return [];
        }
        $url = 'https://www.googleapis.com/youtube/v3/channels?' . http_build_query([
            'part' => 'contentDetails',
            'id' => implode(',', $channelIds),
            'maxResults' => count($channelIds),
        ]);
        $resp = $this->request('GET', $url);
        $out = [];
        if (!is_array($resp['json'])) {
            return $out;
        }
        foreach (($resp['json']['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $up = $item['contentDetails']['relatedPlaylists']['uploads'] ?? '';
            if (is_string($up) && $up !== '') {
                $out[] = $up;
            }
        }
        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function playlistItems(string $playlistId, int $max = 5): array
    {
        $url = 'https://www.googleapis.com/youtube/v3/playlistItems?' . http_build_query([
            'part' => 'snippet,contentDetails',
            'playlistId' => $playlistId,
            'maxResults' => min(10, max(1, $max)),
        ]);
        $resp = $this->request('GET', $url);
        $out = [];
        if (!is_array($resp['json'])) {
            return $out;
        }
        foreach (($resp['json']['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $snip = $item['snippet'] ?? [];
            if (!is_array($snip)) {
                continue;
            }
            $vid = (string) (($item['contentDetails']['videoId'] ?? '') ?: ($snip['resourceId']['videoId'] ?? ''));
            if ($vid === '') {
                continue;
            }
            $thumbs = is_array($snip['thumbnails'] ?? null) ? $snip['thumbnails'] : [];
            $thumb = '';
            foreach (['medium', 'high', 'default'] as $k) {
                if (isset($thumbs[$k]['url'])) {
                    $thumb = (string) $thumbs[$k]['url'];
                    break;
                }
            }
            $out[] = [
                'type' => 'video',
                'title' => (string) ($snip['title'] ?? ''),
                'videoId' => $vid,
                'videoThumbnails' => $thumb !== '' ? [['url' => $thumb, 'width' => 320, 'height' => 180]] : [],
                'author' => (string) ($snip['channelTitle'] ?? ''),
                'authorId' => (string) ($snip['channelId'] ?? ''),
                'published' => (string) ($snip['publishedAt'] ?? ''),
                'lengthSeconds' => 0,
                'viewCount' => 0,
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed>|null $jsonBody
     * @return array{code:int,json:?array,error?:string}
     */
    private function request(string $method, string $url, ?array $jsonBody = null, bool $emptyOk = false): array
    {
        if ($this->accessToken === '' || !function_exists('curl_init')) {
            return ['code' => 0, 'json' => null, 'error' => 'No access token'];
        }
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Accept: application/json',
        ];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => ytl_http_ssl_verify(),
            CURLOPT_SSL_VERIFYHOST => ytl_http_ssl_verify() ? 2 : 0,
        ];
        if ($jsonBody !== null) {
            $encoded = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
            $opts[CURLOPT_POSTFIELDS] = $encoded;
        }
        curl_setopt_array($ch, $opts);
        ytl_curl_set_proxy($ch);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($emptyOk && ($body === '' || $body === false) && $code >= 200 && $code < 300) {
            return ['code' => $code, 'json' => null];
        }
        $json = null;
        $error = null;
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $json = $decoded;
                if (isset($decoded['error']['message'])) {
                    $error = (string) $decoded['error']['message'];
                }
            }
        }
        if ($code < 200 || $code >= 300) {
            return ['code' => $code, 'json' => $json, 'error' => $error ?? ('HTTP ' . $code)];
        }
        return ['code' => $code, 'json' => $json];
    }
}
