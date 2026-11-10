<?php
/**
 * Per-browser external server overrides (cookie ytl_servers).
 * Lets visitors point meta / convert / Invidious at hosts they control when public mirrors die.
 */

/** Raw config.php only — never call ytl_config() (avoids recursion with cookie overlay). */
function ytl_settings_config_file(): array
{
    static $raw = null;
    if ($raw === null) {
        $path = dirname(__DIR__) . '/config.php';
        $raw = is_readable($path) ? (require $path) : [];
        if (!is_array($raw)) {
            $raw = [];
        }
    }
    return $raw;
}

/** @return array{meta:list<string>,convert:list<string>,invidious:list<string>} */
function ytl_settings_defaults(): array
{
    $cfg = ytl_settings_config_file();
    $meta = [];
    foreach ((array) ($cfg['invidious_instances'] ?? []) as $u) {
        $u = ytl_settings_normalize_url((string) $u);
        if ($u !== '') {
            $meta[] = $u;
        }
    }
    if ($meta === []) {
        $meta = ['https://yt.chocolatemoo53.com'];
    }
    $convert = [];
    foreach ((array) ($cfg['cobalt_apis'] ?? []) as $u) {
        $u = ytl_settings_normalize_url((string) $u);
        if ($u !== '') {
            $convert[] = $u;
        }
    }
    if ($convert === []) {
        $convert = ['https://rue-cobalt.xenon.zone'];
    }
    return [
        'meta' => array_values(array_unique($meta)),
        'convert' => array_values(array_unique($convert)),
        'invidious' => array_values(array_unique(array_merge(
            ['https://yt.chocolatemoo53.com'],
            array_slice($meta, 0, 4)
        ))),
    ];
}

function ytl_settings_normalize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    $url = rtrim($url, '/');
    // Allow pasting /api or /api/v1/… — keep host base only.
    $url = (string) preg_replace('#/(api/v1|api|youtubei)(/.*)?$#i', '', $url);
    $url = (string) preg_replace('#/tunnel.*$#i', '', $url);
    $url = rtrim($url, '/');
    if (!preg_match('#^https?://[A-Za-z0-9._:-]+#', $url)) {
        return '';
    }
    // Block obvious local/metadata SSRF targets for server-side fetches.
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
    if ($host === '' || $host === 'localhost' || str_starts_with($host, '127.') || $host === '::1') {
        return '';
    }
    return $url;
}

/**
 * @param list<string>|string $raw
 * @return list<string>
 */
function ytl_settings_parse_list($raw): array
{
    if (is_string($raw)) {
        $raw = preg_split('/[\s,;]+/', $raw) ?: [];
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $u) {
        $n = ytl_settings_normalize_url((string) $u);
        if ($n !== '') {
            $out[] = $n;
        }
        if (count($out) >= 12) {
            break;
        }
    }
    return array_values(array_unique($out));
}

/** @return array{meta:list<string>,convert:list<string>,invidious:list<string>} */
function ytl_settings_from_array(array $data): array
{
    $def = ytl_settings_defaults();
    $meta = ytl_settings_parse_list($data['meta'] ?? ($data['meta_servers'] ?? []));
    $convert = ytl_settings_parse_list($data['convert'] ?? ($data['convert_servers'] ?? []));
    $inv = ytl_settings_parse_list($data['invidious'] ?? ($data['inv_servers'] ?? []));
    return [
        'meta' => $meta !== [] ? $meta : $def['meta'],
        'convert' => $convert !== [] ? $convert : $def['convert'],
        'invidious' => $inv !== [] ? $inv : $def['invidious'],
    ];
}

/** @return array{meta:list<string>,convert:list<string>,invidious:list<string>} */
function ytl_settings_get(): array
{
    $def = ytl_settings_defaults();
    $raw = (string) ($_COOKIE['ytl_servers'] ?? '');
    if ($raw === '') {
        return $def;
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        // Older/simple form: try base64
        $try = json_decode((string) base64_decode($raw, true), true);
        if (!is_array($try)) {
            return $def;
        }
        $json = $try;
    }
    return ytl_settings_from_array($json);
}

/**
 * @param array{meta?:list<string>|string,convert?:list<string>|string,invidious?:list<string>|string} $data
 */
function ytl_settings_save_cookie(array $data): void
{
    $s = ytl_settings_from_array($data);
    $payload = json_encode($s, JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return;
    }
    // Keep under ~2KB for cookie limits.
    if (strlen($payload) > 1800) {
        $s = [
            'meta' => array_slice($s['meta'], 0, 4),
            'convert' => array_slice($s['convert'], 0, 4),
            'invidious' => array_slice($s['invidious'], 0, 4),
        ];
        $payload = (string) json_encode($s, JSON_UNESCAPED_SLASHES);
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
    setcookie('ytl_servers', $payload, [
        'expires' => time() + 86400 * 400,
        'path' => '/',
        'secure' => $secure,
        'httponly' => false, // JS (watch.php) also reads/writes
        'samesite' => 'Lax',
    ]);
    $_COOKIE['ytl_servers'] = $payload;
}
