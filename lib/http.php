<?php

/**
 * Minimal HTTP helpers for PHP 8 (cURL preferred, streams fallback).
 */

function ytl_http_last_error(?string $set = null): string
{
    static $err = '';
    if ($set !== null) {
        $err = $set;
    }
    return $err;
}

function ytl_http_ssl_verify(): bool
{
    static $verify = null;
    if ($verify === null) {
        $cfgFile = dirname(__DIR__) . '/config.php';
        $cfg = is_file($cfgFile) ? include $cfgFile : [];
        // Default true when key absent
        $verify = !is_array($cfg) || !array_key_exists('ssl_verify', $cfg)
            ? true
            : (bool) $cfg['ssl_verify'];
    }
    return $verify;
}

/** @return array<string,mixed> */
function ytl_http_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfgFile = dirname(__DIR__) . '/config.php';
        $loaded = is_file($cfgFile) ? include $cfgFile : [];
        $cfg = is_array($loaded) ? $loaded : [];
    }
    return $cfg;
}

/**
 * Residential / HTTP(S) proxy for outbound YouTube traffic.
 * Fill config.php → http_proxy (e.g. http://user:pass@host:port)
 *
 * @return array{enabled:bool,url:string,safe:string}
 */
function ytl_http_proxy_info(): array
{
    static $info = null;
    if ($info !== null) {
        return $info;
    }
    $cfg = ytl_http_config();
    $url = trim((string) ($cfg['http_proxy'] ?? ''));
    if ($url === '' && !empty($cfg['proxy_host'])) {
        $host = trim((string) $cfg['proxy_host']);
        $port = (int) ($cfg['proxy_port'] ?? 0);
        $user = (string) ($cfg['proxy_user'] ?? '');
        $pass = (string) ($cfg['proxy_pass'] ?? '');
        $scheme = (string) ($cfg['proxy_scheme'] ?? 'http');
        if ($host !== '' && $port > 0) {
            $auth = ($user !== '') ? (rawurlencode($user) . ':' . rawurlencode($pass) . '@') : '';
            $url = $scheme . '://' . $auth . $host . ':' . $port;
        }
    }
    $safe = '';
    if ($url !== '') {
        $parts = parse_url($url);
        if (is_array($parts) && !empty($parts['host'])) {
            $safe = ($parts['scheme'] ?? 'http') . '://'
                . (!empty($parts['user']) ? '***:***@' : '')
                . $parts['host']
                . (isset($parts['port']) ? (':' . $parts['port']) : '');
        } else {
            $safe = '(proxy configuré)';
        }
    }
    $info = [
        'enabled' => $url !== '',
        'url' => $url,
        'safe' => $safe,
    ];
    return $info;
}

/** Apply residential proxy options to an open curl handle. */
function ytl_curl_set_proxy($ch): void
{
    // OVH often prefers broken IPv6 paths to Google / Piped → force IPv4.
    if (defined('CURL_IPRESOLVE_V4')) {
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }
    $info = ytl_http_proxy_info();
    if (!$info['enabled'] || $info['url'] === '') {
        return;
    }
    curl_setopt($ch, CURLOPT_PROXY, $info['url']);
    curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
    // Allow http and socks5 schemes from URL
    $scheme = strtolower((string) (parse_url($info['url'], PHP_URL_SCHEME) ?? 'http'));
    if ($scheme === 'socks5' || $scheme === 'socks5h') {
        if (defined('CURLPROXY_SOCKS5_HOSTNAME')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        } elseif (defined('CURLPROXY_SOCKS5')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        }
    } elseif ($scheme === 'socks4') {
        if (defined('CURLPROXY_SOCKS4')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
        }
    } else {
        if (defined('CURLPROXY_HTTP')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }
    }
}

function ytl_http_get(string $url, int $timeout = 15, array $extraHeaders = []): ?string
{
    ytl_http_last_error('');
    $headers = array_merge([
        'User-Agent: YouTubeLite/1.0 (compatible; old-browser client)',
        'Accept: application/json, text/plain, */*',
    ], $extraHeaders);

    $sslVerify = ytl_http_ssl_verify();

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_ENCODING => '',
        ]);
        ytl_curl_set_proxy($ch);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        if ($body === false || $code < 200 || $code >= 400) {
            ytl_http_last_error('HTTP ' . $code . ($cerr !== '' ? (' / ' . $cerr) : '') . ' for ' . $url);
            return null;
        }
        return $body;
    }

    $headerLines = '';
    foreach ($headers as $h) {
        $headerLines .= $h . "\r\n";
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => $headerLines,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        ytl_http_last_error('file_get_contents failed for ' . $url);
        return null;
    }

    if (function_exists('http_get_last_response_headers')) {
        $respHeaders = http_get_last_response_headers();
        if (is_array($respHeaders) && isset($respHeaders[0]) && !preg_match('/\s2\d\d\s/', $respHeaders[0])) {
            ytl_http_last_error($respHeaders[0] . ' for ' . $url);
            return null;
        }
    }

    return $body;
}

function ytl_http_get_json(string $url, int $timeout = 15): ?array
{
    $body = ytl_http_get($url, $timeout);
    if ($body === null || $body === '') {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        ytl_http_last_error('Invalid JSON from ' . $url);
        return null;
    }
    return $data;
}

/**
 * Quick check: can this server actually download the first bytes of a media URL?
 * Avoids returning Piped links that look valid but 403 when proxied.
 */
function ytl_http_stream_ok(string $url, int $timeout = 8): bool
{
    if ($url === '' || !function_exists('curl_init')) {
        return false;
    }
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
    $isGv = str_contains($host, 'googlevideo.com') || str_contains($host, 'googleusercontent.com');
    $isPiped = str_contains($host, 'piped');
    $ua = $isGv
        ? 'com.google.android.youtube/20.10.38 (Linux; U; Android 14) gzip'
        : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    $headers = [
        'User-Agent: ' . $ua,
        'Accept: */*',
        'Range: bytes=0-1023',
    ];
    if ($isGv) {
        $headers[] = 'Referer: https://www.youtube.com/';
        if (function_exists('ytl_cookie_to_header') && function_exists('ytl_youtube_cookie_raw')) {
            $cookieHdr = ytl_cookie_to_header(ytl_youtube_cookie_raw());
            if ($cookieHdr !== '') {
                $headers[] = 'Cookie: ' . $cookieHdr;
            }
        }
    } elseif ($isPiped) {
        $headers[] = 'Referer: https://piped.video/';
    }

    $sslVerify = ytl_http_ssl_verify();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => $sslVerify,
        CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
    ]);
    ytl_curl_set_proxy($ch);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (!is_string($body) || $body === '' || ($code !== 200 && $code !== 206)) {
        return false;
    }
    // Reject HTML error pages
    $start = substr($body, 0, 8);
    if (str_starts_with($start, '<!') || str_starts_with($start, '<html')) {
        return false;
    }
    return true;
}

/**
 * Race several URLs: return the first successful JSON array (and its URL).
 * Speeds up Piped/Invidious failover a lot vs trying instances one by one.
 *
 * @param string[] $urls
 * @return array{0: array, 1: string}|null
 */
function ytl_http_get_json_race(array $urls, int $timeout = 10): ?array
{
    $urls = array_values(array_filter(array_map('strval', $urls)));
    if (count($urls) === 0) {
        return null;
    }
    if (count($urls) === 1 || !function_exists('curl_multi_init')) {
        $data = ytl_http_get_json($urls[0], $timeout);
        return $data !== null ? [$data, $urls[0]] : null;
    }

    ytl_http_last_error('');
    $sslVerify = ytl_http_ssl_verify();
    $mh = curl_multi_init();
    $map = [];
    foreach ($urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'User-Agent: YouTubeLite/1.0 (compatible; old-browser client)',
                'Accept: application/json, text/plain, */*',
            ],
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_ENCODING => '',
        ]);
        ytl_curl_set_proxy($ch);
        curl_multi_add_handle($mh, $ch);
        $map[(int) $ch] = [$ch, $url];
    }

    $winner = null;
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        while ($info = curl_multi_info_read($mh)) {
            if ($winner !== null) {
                continue;
            }
            $ch = $info['handle'];
            $id = (int) $ch;
            if (!isset($map[$id])) {
                continue;
            }
            $url = $map[$id][1];
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = curl_multi_getcontent($ch);
            if ($info['result'] === CURLE_OK && $code >= 200 && $code < 400 && is_string($body) && $body !== '') {
                $data = json_decode($body, true);
                // Reject {"error":"..."} payloads some instances return with HTTP 200
                if (is_array($data) && !(isset($data['error']) && !isset($data['title']) && !isset($data[0]))) {
                    $winner = [$data, $url];
                }
            }
        }
        if ($running && $winner === null) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running && $winner === null && $status === CURLM_OK);

    foreach ($map as [$ch]) {
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);

    if ($winner !== null) {
        return $winner;
    }

    // Do NOT retry every URL sequentially after a parallel race — that turns a
    // single timeout into N×timeout and can hang the homepage for minutes.
    // One short single-URL attempt only when curl_multi itself looked broken.
    if ($status !== CURLM_OK && isset($urls[0])) {
        $data = ytl_http_get_json($urls[0], min(4, max(2, $timeout)));
        if ($data !== null) {
            return [$data, $urls[0]];
        }
    }
    ytl_http_last_error('All raced URLs failed');
    return null;
}
