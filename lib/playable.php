<?php

require_once __DIR__ . '/http.php';

/**
 * Classic IDs that public Piped instances often still serve as muxed MP4
 * even when most of YouTube is bot-blocked for datacenter IPs.
 */
function ytl_classic_video_ids(): array
{
    return [
        'dQw4w9WgXcQ', // Never Gonna Give You Up
        'kJQP7kiw5Fk', // Despacito
        'jNQXAC9IVRw', // Me at the zoo
        'M7lc1UVf-VE', // YouTube Developers Live sample
    ];
}

/**
 * Keep only videos that expose a muxed progressive MP4 (Piped, then Innertube).
 *
 * @param list<array> $videos
 * @param string[] $pipedInstances
 * @return list<array>
 */
function ytl_keep_playable_mp4(array $videos, array $pipedInstances, int $want = 24, int $timeout = 7): array
{
    $candidates = [];
    foreach ($videos as $v) {
        if (!is_array($v)) {
            continue;
        }
        $id = (string) ($v['videoId'] ?? '');
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) {
            continue;
        }
        $candidates[$id] = $v;
        if (count($candidates) >= 36) {
            break;
        }
    }
    if (count($candidates) === 0) {
        return [];
    }

    $playable = [];
    $remaining = $candidates;

    foreach ($pipedInstances as $base) {
        if (count($playable) >= $want || count($remaining) === 0) {
            break;
        }
        $base = rtrim((string) $base, '/');
        if ($base === '') {
            continue;
        }
        $found = ytl_piped_muxed_ids(array_keys($remaining), $base, $timeout);
        foreach ($found as $id => $meta) {
            if (!isset($remaining[$id])) {
                continue;
            }
            $row = $remaining[$id];
            if (!empty($meta['title'])) {
                $row['title'] = $meta['title'];
            }
            if (!empty($meta['thumbnail'])) {
                $row['videoThumbnails'] = [[
                    'quality' => 'medium',
                    'url' => $meta['thumbnail'],
                    'width' => 320,
                    'height' => 180,
                ]];
            }
            $playable[] = $row;
            unset($remaining[$id]);
            if (count($playable) >= $want) {
                break;
            }
        }
    }

    // Innertube fallback (works on residential IPs; often blocked on VPS)
    if (count($playable) < $want && count($remaining) > 0) {
        require_once __DIR__ . '/innertube.php';
        $cfg = is_file(dirname(__DIR__) . '/config.php') ? include dirname(__DIR__) . '/config.php' : [];
        $inner = new InnertubeClient(is_array($cfg) ? $cfg : []);
        foreach (array_keys($remaining) as $id) {
            if (count($playable) >= $want) {
                break;
            }
            $video = $inner->video($id);
            if ($video === null || empty($video['formatStreams'])) {
                continue;
            }
            $row = $remaining[$id];
            if (!empty($video['title'])) {
                $row['title'] = (string) $video['title'];
            }
            $playable[] = $row;
            unset($remaining[$id]);
        }
    }

    return $playable;
}

/**
 * Build a small guaranteed shelf from classic IDs that Piped can play.
 *
 * @param string[] $pipedInstances
 * @return list<array>
 */
function ytl_classic_playable_shelf(array $pipedInstances, int $timeout = 8): array
{
    $out = [];
    foreach (ytl_classic_video_ids() as $id) {
        $row = [
            'type' => 'video',
            'videoId' => $id,
            'title' => $id,
            'author' => '',
            'authorId' => '',
            'videoThumbnails' => [[
                'quality' => 'medium',
                'url' => 'https://i.ytimg.com/vi/' . $id . '/mqdefault.jpg',
                'width' => 320,
                'height' => 180,
            ]],
            'lengthSeconds' => 1,
            'viewCount' => 0,
            'publishedText' => '',
        ];
        $out[] = $row;
    }
    $kept = ytl_keep_playable_mp4($out, $pipedInstances, count($out), $timeout);
    return count($kept) > 0 ? $kept : $out;
}

/**
 * @param string[] $ids
 * @return array<string, array{title?:string, thumbnail?:string}>
 */
function ytl_piped_muxed_ids(array $ids, string $instanceBase, int $timeout): array
{
    $ids = array_values($ids);
    if (count($ids) === 0) {
        return [];
    }

    $urls = [];
    foreach ($ids as $id) {
        $urls[$id] = rtrim($instanceBase, '/') . '/streams/' . rawurlencode($id);
    }

    if (!function_exists('curl_multi_init')) {
        $out = [];
        foreach ($urls as $id => $url) {
            $data = ytl_http_get_json($url, $timeout);
            if (ytl_piped_has_muxed($data)) {
                $out[$id] = [
                    'title' => (string) ($data['title'] ?? ''),
                    'thumbnail' => (string) ($data['thumbnailUrl'] ?? ''),
                ];
            }
        }
        return $out;
    }

    $sslVerify = ytl_http_ssl_verify();
    $mh = curl_multi_init();
    $map = [];
    foreach ($urls as $id => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'User-Agent: YouTubeLite/1.0 (compatible; old-browser client)',
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_ENCODING => '',
        ]);
        ytl_curl_set_proxy($ch);
        curl_multi_add_handle($mh, $ch);
        $map[(int) $ch] = [$ch, $id];
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running && $status === CURLM_OK);

    $out = [];
    foreach ($map as [$ch, $id]) {
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        if ($code < 200 || $code >= 400 || !is_string($body) || $body === '') {
            continue;
        }
        $data = json_decode($body, true);
        if (!ytl_piped_has_muxed($data)) {
            continue;
        }
        $out[$id] = [
            'title' => (string) ($data['title'] ?? ''),
            'thumbnail' => (string) ($data['thumbnailUrl'] ?? ''),
        ];
    }
    curl_multi_close($mh);
    return $out;
}

function ytl_piped_has_muxed(?array $data): bool
{
    if (!is_array($data) || empty($data['title']) || !empty($data['error'])) {
        return false;
    }
    foreach ($data['videoStreams'] ?? [] as $s) {
        if (!is_array($s) || empty($s['url'])) {
            continue;
        }
        if (!empty($s['videoOnly'])) {
            continue;
        }
        $format = strtoupper((string) ($s['format'] ?? ''));
        if (str_contains($format, 'HLS')) {
            continue;
        }
        return true;
    }
    return false;
}
