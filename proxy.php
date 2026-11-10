<?php
/**
 * Stream / image proxy for old browsers without HTTPS or CORS issues.
 * Supports HTTP Range requests for progressive playback.
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/http.php';

$cfg = ytl_config();
$url = isset($_GET['url']) ? (string) $_GET['url'] : '';
$type = isset($_GET['t']) ? (string) $_GET['t'] : 'stream';

if ($url === '' || !preg_match('#^https?://#i', $url)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Bad request';
    exit;
}

// Basic SSRF guard: only allow http(s) and block obvious local targets
$parts = parse_url($url);
$host = strtolower((string) ($parts['host'] ?? ''));
if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden host';
    exit;
}
if (filter_var($host, FILTER_VALIDATE_IP)) {
    if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Forbidden host';
        exit;
    }
}

if ($type === 'image' && empty($cfg['proxy_images'])) {
    header('Location: ' . $url, true, 302);
    exit;
}
if ($type !== 'image' && empty($cfg['proxy_streams'])) {
    header('Location: ' . $url, true, 302);
    exit;
}

if (!function_exists('curl_init')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'cURL required for proxy';
    exit;
}

$timeout = (int) ($cfg['http_timeout'] ?? 15);
$range = isset($_SERVER['HTTP_RANGE']) ? (string) $_SERVER['HTTP_RANGE'] : null;
$sslVerify = ytl_http_ssl_verify();

// Match clients that minted the URL — wrong UA/Origin often yields googlevideo 403
$isGoogleVideo = str_contains($host, 'googlevideo.com') || str_contains($host, 'googleusercontent.com');
$isPiped = str_contains($host, 'piped');
$ua = $isGoogleVideo
    ? 'com.google.android.youtube/20.10.38 (Linux; U; Android 14) gzip'
    : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

$requestHeaders = [
    'User-Agent: ' . $ua,
    'Accept: */*',
    'Accept-Language: en-US,en;q=0.9',
];
if ($isGoogleVideo) {
    $requestHeaders[] = 'Referer: https://www.youtube.com/';
    $cookieHdr = ytl_cookie_to_header(ytl_youtube_cookie_raw());
    if ($cookieHdr !== '') {
        $requestHeaders[] = 'Cookie: ' . $cookieHdr;
    }
} elseif ($isPiped) {
    $requestHeaders[] = 'Referer: https://piped.video/';
}
if ($range !== null && $range !== '') {
    $requestHeaders[] = 'Range: ' . $range;
}

$responseHeaders = [];
$status = 0;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => $timeout,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_HTTPHEADER => $requestHeaders,
    CURLOPT_SSL_VERIFYPEER => $sslVerify,
    CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
    CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) use (&$responseHeaders, &$status) {
        $len = strlen($headerLine);
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $headerLine, $m)) {
            $status = (int) $m[1];
            return $len;
        }
        $parts = explode(':', $headerLine, 2);
        if (count($parts) === 2) {
            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            // Keep last value for duplicates
            $responseHeaders[$name] = $value;
        }
        return $len;
    },
    CURLOPT_WRITEFUNCTION => function ($curl, $data) {
        echo $data;
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
        return strlen($data);
    },
]);
ytl_curl_set_proxy($ch);

// Decide Content-Type before body when possible via a HEAD? Not reliable for signed URLs.
// We stream with passthrough of useful headers after curl starts — set defaults first.
if ($type === 'image') {
    $defaultType = 'image/jpeg';
} else {
    $defaultType = 'video/mp4';
}

// Buffer headers until we know status — use a two-phase approach:
// For simplicity and Range support: disable WRITE until headers received via curl exec with header callback,
// but WRITEFUNCTION fires after headers. So we send HTTP status + headers on first write.

$headersSent = false;
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) use (&$headersSent, &$status, &$responseHeaders, $defaultType, $type) {
    if (!$headersSent) {
        $headersSent = true;
        if ($status === 0) {
            $status = 200;
        }
        http_response_code($status);

        $ctype = $responseHeaders['content-type'] ?? $defaultType;
        // Strip parameters we don't want to blindly pass for images/videos
        header('Content-Type: ' . $ctype);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=3600');
        header('X-Content-Type-Options: nosniff');

        if (isset($responseHeaders['content-length'])) {
            header('Content-Length: ' . $responseHeaders['content-length']);
        }
        if (isset($responseHeaders['content-range'])) {
            header('Content-Range: ' . $responseHeaders['content-range']);
        }
        if ($type !== 'image') {
            // Encourage download / native player open
            $ext = (stripos($ctype, 'webm') !== false) ? 'webm' : 'mp4';
            header('Content-Disposition: inline; filename="video.' . $ext . '"');
        }
    }

    echo $data;
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
    return strlen($data);
});

// Avoid PHP output buffering eating the stream
while (ob_get_level() > 0) {
    ob_end_clean();
}

$ok = curl_exec($ch);
$errno = curl_errno($ch);

if (!$ok || $errno) {
    if (!$headersSent) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Upstream fetch failed';
    }
    exit;
}

if (!$headersSent) {
    // Empty body but OK
    http_response_code($status > 0 ? $status : 204);
}
