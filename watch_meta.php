<?php
/**
 * Async watch metadata (title, likes, description, comments, related).
 * Keeps ?play=1 instant; the page fills these in after load.
 *
 * ?part=meta|comments|all (default all)
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/api.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$videoId = isset($_GET['v']) ? trim((string) $_GET['v']) : '';
$part = isset($_GET['part']) ? strtolower(trim((string) $_GET['part'])) : 'all';
if (!in_array($part, ['meta', 'comments', 'all'], true)) {
    $part = 'all';
}

if ($videoId === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid id']);
    exit;
}

$client = ytl_client();
$out = ['ok' => true, 'part' => $part];

if ($part === 'meta' || $part === 'all') {
    $meta = $client->videoMeta($videoId);
    $title = is_array($meta) ? (string) ($meta['title'] ?? '') : '';
    $author = is_array($meta) ? (string) ($meta['author'] ?? '') : '';
    $authorId = is_array($meta) ? (string) ($meta['authorId'] ?? '') : '';
    $desc = is_array($meta) ? (string) ($meta['description'] ?? '') : '';
    // videoMeta already returns plain text; keep a light sanitize for safety.
    if ($desc !== '') {
        $desc = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $desc)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $desc = trim(preg_replace("/[ \t]+\n/", "\n", preg_replace("/\n{3,}/", "\n\n", $desc)) ?? $desc);
    }
    $viewCount = is_array($meta) ? (int) ($meta['viewCount'] ?? 0) : 0;
    $likeCount = is_array($meta) ? (int) ($meta['likeCount'] ?? 0) : 0;
    $dislikeCount = is_array($meta) ? (int) ($meta['dislikeCount'] ?? -1) : -1;
    $published = is_array($meta) ? (string) ($meta['publishedText'] ?? '') : '';
    $subs = is_array($meta) ? (int) ($meta['subCount'] ?? 0) : 0;

    $avatar = '';
    if (is_array($meta)) {
        $direct = ytl_normalize_avatar_url(trim((string) ($meta['avatarUrl'] ?? '')));
        // Prefer a real image URL, not a /channel/… page link.
        if ($direct !== '' && (
            str_contains($direct, 'yt3.')
            || str_contains($direct, 'ggpht')
            || str_contains($direct, 'googleusercontent.com')
            || preg_match('/\.(jpg|jpeg|png|webp)(\?|$)/i', $direct)
        )) {
            $avatar = ytl_proxy_url($direct, 'image');
        }
        if ($avatar === '') {
            $thumbs = $meta['authorThumbnails'] ?? [];
            if (is_array($thumbs)) {
                $best = null;
                foreach ($thumbs as $t) {
                    if (!is_array($t)) {
                        continue;
                    }
                    $u = ytl_normalize_avatar_url(trim((string) ($t['url'] ?? '')));
                    if ($u === '') {
                        continue;
                    }
                    $w = (int) ($t['width'] ?? 0);
                    if ($best === null || ($w > 0 && $w >= 48 && ($best['w'] < 48 || abs($w - 120) < abs($best['w'] - 120)))) {
                        $best = ['w' => $w > 0 ? $w : 120, 'url' => $u];
                    }
                }
                if ($best !== null) {
                    $avatar = ytl_proxy_url($best['url'], 'image');
                }
            }
        }
        // Avatar gaps: browser enrichMetaClient fills them — do not stall related here.
    }
    $channelUrl = $authorId !== '' ? ytl_url('channel.php', ['id' => $authorId]) : '';

    $related = [];
    $rec = is_array($meta) ? ($meta['recommendedVideos'] ?? []) : [];
    if (is_array($rec)) {
        foreach ($rec as $rv) {
            if (!is_array($rv) || empty($rv['videoId'])) {
                continue;
            }
            $rid = (string) $rv['videoId'];
            $related[] = [
                'videoId' => $rid,
                'title' => (string) ($rv['title'] ?? 'Video'),
                'author' => (string) ($rv['author'] ?? ''),
                'thumb' => ytl_thumb_url($rv),
                'watch' => ytl_url('watch.php', ['v' => $rid]),
                'views' => isset($rv['viewCount']) ? InvidiousClient::formatViews((int) $rv['viewCount']) : '',
                'length' => isset($rv['lengthSeconds']) ? InvidiousClient::formatDuration((int) $rv['lengthSeconds']) : '',
            ];
            if (count($related) >= 8) {
                break;
            }
        }
    }

    $out['ok'] = $meta !== null;
    $out['title'] = $title !== '' ? $title : 'Video';
    $out['author'] = $author;
    $out['authorId'] = $authorId;
    $out['channelUrl'] = $channelUrl;
    $out['avatar'] = $avatar;
    // Direct (unproxied) fallbacks for the browser when proxy.php fails — ≥2 sources.
    $avatarAlts = [];
    if ($avatar !== '' && preg_match('/[?&]url=([^&]+)/', $avatar, $am)) {
        $direct = rawurldecode($am[1]);
        if ($direct !== '') {
            $avatarAlts[] = $direct;
        }
    }
    if (is_array($meta)) {
        foreach ((array) ($meta['authorThumbnails'] ?? []) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $u = ytl_normalize_avatar_url(trim((string) ($t['url'] ?? '')));
            if ($u !== '' && !in_array($u, $avatarAlts, true)) {
                $avatarAlts[] = $u;
            }
            if (count($avatarAlts) >= 3) {
                break;
            }
        }
    }
    $out['avatarAlts'] = $avatarAlts;
    $out['subs'] = $subs > 0 ? InvidiousClient::formatViews($subs) : '';
    $out['views'] = $viewCount > 0 ? InvidiousClient::formatViews($viewCount) : '';
    $out['likes'] = $likeCount > 0 ? InvidiousClient::formatViews($likeCount) : '';
    $out['dislikes'] = $dislikeCount > 0 ? InvidiousClient::formatViews($dislikeCount) : '';
    $out['likeCount'] = max(0, $likeCount);
    $out['dislikeCount'] = $dislikeCount > 0 ? $dislikeCount : 0;
    $out['published'] = $published;
    $out['description'] = $desc;
    $out['related'] = $related;
}

if ($part === 'comments' || $part === 'all') {
    $thread = $client->comments($videoId, null);
    $comments = [];
    foreach (($thread['comments'] ?? []) as $c) {
        if (!is_array($c) || trim((string) ($c['text'] ?? '')) === '') {
            continue;
        }
        $cAuthorId = (string) ($c['authorId'] ?? '');
        $comments[] = [
            'text' => (string) $c['text'],
            'author' => (string) ($c['author'] ?? ''),
            'authorUrl' => $cAuthorId !== '' ? ytl_url('channel.php', ['id' => $cAuthorId]) : '',
            'publishedText' => (string) ($c['publishedText'] ?? ''),
            'likeCount' => (int) ($c['likeCount'] ?? 0),
            'likeLabel' => ((int) ($c['likeCount'] ?? 0)) > 0
                ? InvidiousClient::formatViews((int) $c['likeCount'])
                : '',
            'replyCount' => (int) ($c['replyCount'] ?? 0),
            'pinned' => !empty($c['pinned']),
        ];
        if (count($comments) >= 40) {
            break;
        }
    }
    $out['commentCount'] = (int) ($thread['count'] ?? 0);
    $out['commentCountLabel'] = ((int) ($thread['count'] ?? 0)) > 0
        ? InvidiousClient::formatViews((int) $thread['count'])
        : '';
    $out['comments'] = $comments;
    $out['commentsDisabled'] = !empty($thread['disabled']);
    $out['commentsError'] = !empty($thread['error']);
    $out['continuation'] = (string) ($thread['continuation'] ?? '');
    if ($part === 'comments') {
        $out['ok'] = !empty($thread['disabled']) || !empty($thread['error']) || count($comments) > 0 || ((int) ($thread['count'] ?? 0) === 0);
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
