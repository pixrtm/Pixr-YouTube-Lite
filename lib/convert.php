<?php
/* ytl-deploy:2026-11-09T07:45Z-client-cobalt-progress-fix */
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/cookie_auth.php';
require_once __DIR__ . '/ytdlp_session.php';

/**
 * Prépare data/videos/{id}.mp4.
 * YouTube bloque les flux directs depuis l’hébergeur : on demande d’abord
 * un MP4 déjà récupéré par une API Cobalt, puis les URL progressives habituelles.
 */

function ytl_convert_enabled(): bool
{
    $cfg = ytl_http_config();
    if (array_key_exists('convert_enabled', $cfg)) {
        return (bool) $cfg['convert_enabled'];
    }
    return true;
}

function ytl_convert_dir(): string
{
    $cfg = ytl_http_config();
    $dir = (string) ($cfg['video_cache_dir'] ?? (dirname(__DIR__) . '/data/videos'));
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/** Qualités proposées (hauteur max). */
function ytl_convert_qualities(): array
{
    return [240, 360, 480, 720, 1080, 2160];
}

function ytl_convert_normalize_quality(int $height): int
{
    $height = $height > 0 ? $height : 360;
    if (in_array($height, ytl_convert_qualities(), true)) {
        return $height;
    }
    return 360;
}

function ytl_convert_quality_label(int $height): string
{
    $height = ytl_convert_normalize_quality($height);
    return $height >= 2160 ? '4K' : ($height . 'p');
}

function ytl_convert_normalize_fmt(string $fmt): string
{
    return strtolower(trim($fmt)) === 'mp3' ? 'mp3' : 'mp4';
}

/** Id YouTube depuis une URL ou un id brut. */
function ytl_parse_youtube_id(string $input): string
{
    $s = trim($input);
    if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $s)) {
        return $s;
    }
    if (preg_match('~(?:v=|/shorts/|/embed/|youtu\.be/)([A-Za-z0-9_-]{6,20})~', $s, $m)) {
        return $m[1];
    }
    return '';
}

function ytl_convert_mp4_path(string $videoId, int $height = 360): string
{
    $height = ytl_convert_normalize_quality($height);
    $dir = ytl_convert_dir();
    if ($height === 360) {
        $legacy = $dir . '/' . $videoId . '.mp4';
        $named = $dir . '/' . $videoId . '_360.mp4';
        if (is_file($named)) {
            return $named;
        }
        return $legacy;
    }
    return $dir . '/' . $videoId . '_' . $height . '.mp4';
}

function ytl_convert_media_path(string $videoId, int $height = 360, string $fmt = 'mp4'): string
{
    $fmt = ytl_convert_normalize_fmt($fmt);
    if ($fmt === 'mp3') {
        return ytl_convert_dir() . '/' . $videoId . '.mp3';
    }
    return ytl_convert_mp4_path($videoId, $height);
}

function ytl_convert_status_path(string $videoId, int $height = 360, string $fmt = 'mp4'): string
{
    $fmt = ytl_convert_normalize_fmt($fmt);
    $height = ytl_convert_normalize_quality($height);
    if ($fmt === 'mp3') {
        return ytl_convert_dir() . '/' . $videoId . '_mp3.status.json';
    }
    if ($height === 360) {
        return ytl_convert_dir() . '/' . $videoId . '.status.json';
    }
    return ytl_convert_dir() . '/' . $videoId . '_' . $height . '.status.json';
}

/** @return array{state:string,updated?:int,bytes?:int,error?:string} */
function ytl_convert_status(string $videoId, int $height = 360, string $fmt = 'mp4'): array
{
    $fmt = ytl_convert_normalize_fmt($fmt);
    $height = ytl_convert_normalize_quality($height);
    $file = ytl_convert_media_path($videoId, $height, $fmt);
    if ($fmt === 'mp3') {
        if (is_file($file) && filesize($file) >= 4000) {
            return ['state' => 'ready', 'bytes' => (int) filesize($file), 'updated' => (int) filemtime($file), 'quality' => $height, 'format' => 'mp3'];
        }
    } elseif (is_file($file) && ytl_mp4_payload_ok($file)) {
        return ['state' => 'ready', 'bytes' => (int) filesize($file), 'updated' => (int) filemtime($file), 'quality' => $height, 'format' => 'mp4'];
    }
    $path = ytl_convert_status_path($videoId, $height, $fmt);
    if (!is_readable($path)) {
        return ['state' => 'idle'];
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || empty($data['state'])) {
        return ['state' => 'idle'];
    }
    return $data;
}

function ytl_convert_is_ready(string $videoId, int $height = 360, string $fmt = 'mp4'): bool
{
    $fmt = ytl_convert_normalize_fmt($fmt);
    $path = ytl_convert_media_path($videoId, $height, $fmt);
    if ($fmt === 'mp3') {
        return is_file($path) && filesize($path) >= 4000;
    }
    return is_file($path) && ytl_mp4_payload_ok($path);
}

function ytl_convert_write_status(string $videoId, string $state, array $extra = []): void
{
    $height = ytl_convert_normalize_quality((int) ($extra['quality'] ?? $extra['height'] ?? 360));
    $fmt = ytl_convert_normalize_fmt((string) ($extra['format'] ?? $extra['fmt'] ?? 'mp4'));
    $payload = array_merge(['state' => $state, 'updated' => time(), 'videoId' => $videoId, 'quality' => $height, 'format' => $fmt], $extra);
    @file_put_contents(ytl_convert_status_path($videoId, $height, $fmt), json_encode($payload), LOCK_EX);
}

function ytl_convert_lock_path(string $videoId, int $height = 360, string $fmt = 'mp4'): string
{
    $fmt = ytl_convert_normalize_fmt($fmt);
    $height = ytl_convert_normalize_quality($height);
    if ($fmt === 'mp3') {
        return ytl_convert_dir() . '/' . $videoId . '_mp3.lock';
    }
    if ($height === 360) {
        return ytl_convert_dir() . '/' . $videoId . '.lock';
    }
    return ytl_convert_dir() . '/' . $videoId . '_' . $height . '.lock';
}

/** Progression lue par la page de préparation (polling). */
function ytl_convert_progress_payload(string $videoId, int $height = 360, string $fmt = 'mp4'): array
{
    $fmt = ytl_convert_normalize_fmt($fmt);
    $height = ytl_convert_normalize_quality($height);
    if (ytl_convert_is_ready($videoId, $height, $fmt)) {
        return [
            'ready' => true,
            'state' => 'ready',
            'percent' => 100,
            'message' => $fmt === 'mp3' ? 'MP3 ready' : 'Video ready',
            'quality' => $height,
            'format' => $fmt,
        ];
    }
    $st = ytl_convert_status($videoId, $height, $fmt);
    $percent = isset($st['percent']) ? (int) $st['percent'] : 0;
    if ($percent < 0) {
        $percent = 0;
    }
    if ($percent > 99) {
        $percent = 99;
    }
    $state = (string) ($st['state'] ?? 'idle');
    $message = (string) ($st['message'] ?? ($fmt === 'mp3' ? 'Preparing MP3…' : 'Preparing video…'));
    $errorCode = (string) ($st['error_code'] ?? '');
    if ($errorCode === '' && !empty($st['error']) && is_string($st['error'])) {
        $errorCode = ytl_convert_classify_error((string) $st['error']);
    }
    // Normalize terminal errors to short message + stable code for everyone.
    if ($state === 'error') {
        $payload = ytl_convert_error_payload(
            $message !== '' ? $message : $errorCode,
            ytl_convert_visitor_signed_in(),
            $errorCode
        );
        $message = $payload['message'];
        $errorCode = $payload['code'];
    }
    $clientCobalt = $state === 'client_cobalt' || !empty($st['client_cobalt']);
    // Handoff is not a terminal error — browser mint from visitor IP.
    if ($clientCobalt) {
        $state = 'client_cobalt';
        if ($percent < 40) {
            $percent = 40;
        }
        if ($message === '' || stripos($message, 'fail') !== false || stripos($message, 'error') !== false) {
            $message = 'Fetching via browser mirrors…';
        }
        if ($errorCode === '' || $errorCode === 'download failed' || $errorCode === 'download_failed') {
            $errorCode = ytl_convert_classify_error((string) ($st['reason'] ?? 'server_exhausted'));
        }
    }
    return [
        'ready' => false,
        'state' => $state,
        'percent' => $percent,
        'message' => $message,
        'error' => $clientCobalt ? '' : (string) ($st['error'] ?? ''),
        'error_code' => $errorCode,
        'quality' => $height,
        'format' => $fmt,
        'client_cobalt' => $clientCobalt,
    ];
}

/**
 * Server backends exhausted — leave a non-terminal status so watch.js runs browser Cobalt.
 */
function ytl_convert_handoff_client_cobalt(
    string $videoId,
    int $height,
    string $fmt,
    string $authTag,
    string $logFile,
    string $reason
): void {
    $fmt = ytl_convert_normalize_fmt($fmt);
    $height = ytl_convert_normalize_quality($height);
    // Browser PUT may have won the race while yt-dlp/Cobalt was still running.
    if (ytl_convert_is_ready($videoId, $height, $fmt)) {
        if ($logFile !== '') {
            @file_put_contents($logFile, "client_cobalt handoff skipped — already ready\n", FILE_APPEND);
        }
        ytl_convert_write_status($videoId, 'ready', [
            'percent' => 100,
            'message' => 'Ready',
            'quality' => $height,
            'format' => $fmt,
            'bytes' => (int) @filesize(ytl_convert_media_path($videoId, $height, $fmt)),
            'source' => 'client_or_server_race',
            'auth' => $authTag,
        ]);
        ytl_convert_purge_other_media($videoId);
        return;
    }
    $code = ytl_convert_classify_error($reason);
    if ($code === 'download_failed' || $code === 'unknown') {
        $code = 'server_exhausted';
    }
    if ($logFile !== '') {
        @file_put_contents(
            $logFile,
            'client_cobalt handoff — code=' . $code . ' reason=' . substr($reason, 0, 160) . "\n",
            FILE_APPEND
        );
    }
    ytl_convert_write_status($videoId, 'client_cobalt', [
        'percent' => 45,
        'message' => 'Fetching via browser mirrors…',
        'quality' => $height,
        'format' => $fmt,
        'auth' => $authTag,
        'client_cobalt' => true,
        'reason' => $reason,
        // Keep server cause for diagnostics, but UI must not treat handoff as terminal.
        'error_code' => $code,
        'server_error_code' => $code,
    ]);
}

function ytl_convert_script(): string
{
    $cfg = ytl_http_config();
    return (string) ($cfg['converter_path']
        ?? $cfg['convertisseur_path']
        ?? (dirname(__DIR__) . '/scripts/converter.py'));
}

/**
 * @return array{ok:bool,bin:?string,detail:string}
 */
function ytl_python_probe(): array
{
    if (!function_exists('exec')) {
        return ['ok' => false, 'bin' => null, 'detail' => 'exec PHP désactivé'];
    }
    $cfg = ytl_http_config();
    $configured = trim((string) ($cfg['python_path'] ?? ''));
    $candidates = array_values(array_filter(array_unique([
        $configured,
        'python3',
        'python3.12',
        'python3.11',
        'python3.10',
        'python3.9',
        '/usr/bin/python3',
        '/usr/local/bin/python3',
        'python',
    ])));

    foreach ($candidates as $bin) {
        $out = [];
        $code = 1;
        @exec(escapeshellarg($bin) . ' --version 2>&1', $out, $code);
        $text = trim(implode(' ', $out));
        if ($code === 0 && $text !== '') {
            return ['ok' => true, 'bin' => $bin, 'detail' => $text];
        }
    }
    return ['ok' => false, 'bin' => null, 'detail' => 'python3 introuvable sur ce serveur'];
}

function ytl_python_bin(): ?string
{
    static $done = false;
    static $bin = null;
    if ($done) {
        return $bin;
    }
    $done = true;
    $p = ytl_python_probe();
    $bin = $p['ok'] ? $p['bin'] : null;
    return $bin;
}

function ytl_convert_available(): bool
{
    if (!ytl_convert_enabled()) {
        return false;
    }
    if (!function_exists('curl_init')) {
        return false;
    }
    // YouTube exige yt-dlp 2026 + CPython ≥3.11 (pas le vieux pyz 2023).
    return ytl_convert_stack_ready();
}

/** @return array{ok:bool,detail:string,missing:list<string>} */
function ytl_convert_stack_info(): array
{
    $bin = dirname(__DIR__) . '/bin';
    $missing = [];
    $modern = $bin . '/yt-dlp-2026.pyz';
    $cpython = $bin . '/cpython.tar.gz';
    $py312 = $bin . '/python/bin/python3';
    $deno = $bin . '/deno';
    $denoGz = $bin . '/deno.gz';
    $ffmpeg = $bin . '/ffmpeg';
    $ffmpegGz = $bin . '/ffmpeg.gz';
    $script = ytl_convert_script();

    if (!is_readable($script)) {
        $missing[] = 'scripts/converter.py';
    }
    if (!is_file($modern)) {
        $missing[] = 'bin/yt-dlp-2026.pyz';
    }
    if (!is_file($py312) && !is_file($cpython)) {
        $missing[] = 'bin/cpython.tar.gz';
    }
    if (!is_file($deno) && !is_file($denoGz)) {
        $missing[] = 'bin/deno.gz';
    }

    $bits = [];
    if (is_file($modern)) {
        $bits[] = 'yt-dlp-2026.pyz=' . (int) filesize($modern);
    }
    if (is_file($py312)) {
        $bits[] = 'python3.12=oui';
    } elseif (is_file($cpython)) {
        $bits[] = 'cpython.tar.gz=' . (int) filesize($cpython);
    }
    if (is_file($deno) || is_file($denoGz)) {
        $bits[] = 'deno=oui';
    }
    if (is_file($ffmpeg) || is_file($ffmpegGz)) {
        $bits[] = 'ffmpeg=oui';
    } else {
        $bits[] = 'ffmpeg=non (recommandé)';
    }

    if ($missing !== []) {
        return [
            'ok' => false,
            'detail' => 'MANQUE : ' . implode(', ', $missing),
            'missing' => $missing,
        ];
    }
    return [
        'ok' => true,
        'detail' => 'OK — ' . implode(' · ', $bits),
        'missing' => [],
    ];
}

function ytl_convert_stack_ready(): bool
{
    return ytl_convert_stack_info()['ok'];
}

function ytl_convert_available_detail(): string
{
    if (!ytl_convert_enabled()) {
        return 'désactivé';
    }
    if (!function_exists('exec')) {
        return 'exec PHP désactivé — impossible de lancer converter.py';
    }
    $stack = ytl_convert_stack_info();
    if (!$stack['ok']) {
        return $stack['detail'] . ' — sans ça YouTube = « format not available »';
    }
    return $stack['detail'];
}

/**
 * True when YouTube reports the id as a live/premiere stream (not convertible to MP4 cache).
 */
function ytl_convert_is_live_video(string $videoId): bool
{
    if (!preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
        return false;
    }
    try {
        require_once __DIR__ . '/innertube.php';
        $cfg = ytl_http_config();
        $client = new InnertubeClient($cfg);
        $details = $client->playerDetails($videoId);
        if (is_array($details)) {
            if (!empty($details['isLive']) || !empty($details['isLiveContent'])) {
                return true;
            }
            // Some payloads only expose live via playability / videoDetails aliases.
            if (!empty($details['liveNow']) || !empty($details['isUpcoming'])) {
                return true;
            }
        }
        $video = $client->video($videoId);
        if (is_array($video) && !empty($video['liveNow'])) {
            return true;
        }
        // HLS-only with no progressive formats often means live.
        if (is_array($video)
            && empty($video['formatStreams'])
            && !empty($video['hlsUrl'])
            && str_contains((string) $video['hlsUrl'], 'yt_live_broadcast')
        ) {
            return true;
        }
    } catch (Throwable $e) {
        // Fall through — yt-dlp match-filter is the backstop.
    }
    return false;
}

/**
 * Map raw backend / yt-dlp text to a stable error code for logs + UI.
 */
function ytl_convert_classify_error(string $hint): string
{
    $low = strtolower(trim($hint));
    if ($low === '') {
        return 'unknown';
    }
    if (
        str_contains($low, 'yt_live_broadcast')
        || str_contains($low, 'live streams aren')
        || (str_contains($low, 'is_live') && str_contains($low, 'filter'))
        || str_contains($low, 'keepalive request failed')
        || str_contains($low, '[hls @')
    ) {
        return 'live_unsupported';
    }
    if (str_contains($low, 'jwt') || str_contains($low, 'turnstile')) {
        return 'cobalt_jwt';
    }
    if (str_contains($low, 'cobalt') && (str_contains($low, '403') || str_contains($low, 'http=403'))) {
        return 'cobalt_403';
    }
    if (str_contains($low, 'error.api.youtube.login') || str_contains($low, 'youtube.login')) {
        return 'cobalt_login';
    }
    if (str_contains($low, 'piped') && (str_contains($low, 'muxed hosts=0') || str_contains($low, 'mux=0') || str_contains($low, 'empty'))) {
        return 'piped_mux_empty';
    }
    if (str_contains($low, 'oauth_http_400') || str_contains($low, 'http 400') || str_contains($low, 'http=400')) {
        return 'oauth_http_400';
    }
    if (
        str_contains($low, 'sign in to confirm')
        || str_contains($low, 'not a bot')
        || str_contains($low, 'confirm you')
        || str_contains($low, 'bot check')
        || str_contains($low, 'bot-check')
    ) {
        return 'bot_check';
    }
    if (
        str_contains($low, 'cookie') && (
            str_contains($low, 'rotat')
            || str_contains($low, 'expired')
            || str_contains($low, 'invalid')
            || str_contains($low, 'dead')
        )
    ) {
        return 'cookie_rotated';
    }
    if (
        str_contains($low, 'no cookie')
        || str_contains($low, 'cookies.php')
        || str_contains($low, 'robots.txt')
        || str_contains($low, 'cookie jar')
        || str_contains($low, 'session expired')
        || str_contains($low, 'session yt-dlp')
    ) {
        return 'cookie_dead';
    }
    if (str_contains($low, 'timeout') || str_contains($low, 'timed out') || str_contains($low, 'operation timed out')) {
        return 'timeout';
    }
    if (str_contains($low, 'server_exhausted') || str_contains($low, 'ladder') && str_contains($low, 'exhausted')) {
        return 'server_exhausted';
    }
    if (str_contains($low, 'client_cobalt') || str_contains($low, 'browser mirrors')) {
        return 'client_cobalt_fail';
    }
    if (str_contains($low, 'forbidden') || str_contains($low, 'http error 403') || str_contains($low, 'http=403')) {
        return 'http_403';
    }
    if (str_contains($low, 'unavailable') || str_contains($low, 'private video') || str_contains($low, 'video unavailable')) {
        return 'video_unavailable';
    }
    if (str_contains($low, 'python') || str_contains($low, 'popen')) {
        return 'engine';
    }
    return 'download_failed';
}

/**
 * Short UI message + stable code in parentheses for targeting.
 *
 * @return array{code:string,message:string}
 */
function ytl_convert_error_payload(string $hint, bool $visitorSignedIn = false, string $forcedCode = ''): array
{
    $cloudOnly = $visitorSignedIn
        && function_exists('ytl_convert_cloud_only_session')
        && ytl_convert_cloud_only_session();
    $code = $forcedCode !== '' ? $forcedCode : ytl_convert_classify_error($hint);

    if ($code === 'live_unsupported') {
        return [
            'code' => $code,
            'message' => "Live streams aren't supported — open a regular (non-live) video. (" . $code . ')',
        ];
    }
    if ($cloudOnly && in_array($code, ['cookie_dead', 'cookie_rotated', 'bot_check', 'oauth_http_400'], true)) {
        return [
            'code' => 'cloud_only',
            'message' => 'Sign out, then Sign in again to enable downloads. (cloud_only)',
        ];
    }

    $byCode = [
        'bot_check' => 'YouTube blocked this server IP (bot check).',
        'oauth_http_400' => 'Account token rejected by YouTube player.',
        'cobalt_jwt' => 'Cobalt mirror requires auth (JWT) from this server.',
        'cobalt_403' => 'Cobalt mirror blocked this server.',
        'cobalt_login' => 'Cobalt mirror needs a YouTube login.',
        'piped_mux_empty' => 'Piped returned no downloadable streams.',
        'cookie_rotated' => 'Server cookie jar is expired or rotated.',
        'cookie_dead' => 'Server cookie session is missing or invalid.',
        'timeout' => 'Download timed out talking to YouTube/mirrors.',
        'http_403' => 'Mirror returned HTTP 403.',
        'video_unavailable' => 'Video unavailable or private.',
        'client_cobalt_fail' => 'Browser mirror fetch failed.',
        'server_exhausted' => 'Server mirrors failed — browser retry needed.',
        'cobalt_no_api' => 'No browser mirror responded.',
        'cobalt_empty' => 'Browser mirrors returned an empty file for this video.',
        'cobalt_unavailable' => 'Browser mirrors say this video is unavailable.',
        'cobalt_tunnel' => 'Browser mirror tunnel download failed.',
        'engine' => 'Converter engine missing on server.',
        'download_failed' => 'Download failed on server.',
        'unknown' => 'Download failed.',
    ];
    $base = $byCode[$code] ?? 'Download failed.';
    return [
        'code' => $code,
        'message' => $base . ' Try another video or retry later. (' . $code . ')',
    ];
}

/**
 * Soften raw yt-dlp / ffmpeg lines for the prepare UI (esp. lite).
 * When $visitorSignedIn, never point at admin cookies.php — use their Google session wording.
 */
function ytl_convert_friendly_error(string $hint, bool $visitorSignedIn = false): string
{
    $low = strtolower($hint);
    // Legacy hard-coded Sign-out copy (status JSON / older deploys) — rewrite unless Cloud-only.
    $cloudOnly = $visitorSignedIn
        && function_exists('ytl_convert_cloud_only_session')
        && ytl_convert_cloud_only_session();
    if (
        !$cloudOnly
        && (
            str_contains($low, 'sign out, sign in again')
            || str_contains($low, 'sign out, then sign in')
            || str_contains($low, 'download failed — sign out')
        )
    ) {
        $hint = 'bot_check';
    }
    return ytl_convert_error_payload($hint, $visitorSignedIn)['message'];
}

/** @var array{code:string,detail:string}|null */
$GLOBALS['ytl_convert_last_fail'] = null;

function ytl_convert_set_last_fail(string $code, string $detail = ''): void
{
    $GLOBALS['ytl_convert_last_fail'] = [
        'code' => $code !== '' ? $code : 'download_failed',
        'detail' => substr($detail, 0, 240),
    ];
}

/** @return array{code:string,detail:string} */
function ytl_convert_get_last_fail(): array
{
    $f = $GLOBALS['ytl_convert_last_fail'] ?? null;
    if (!is_array($f)) {
        return ['code' => 'download_failed', 'detail' => ''];
    }
    return [
        'code' => (string) ($f['code'] ?? 'download_failed'),
        'detail' => (string) ($f['detail'] ?? ''),
    ];
}

/** Log one convert ladder step with a stable result code. */
function ytl_convert_log_step(string $logFile, string $step, string $result, string $detail = ''): void
{
    if ($logFile === '') {
        return;
    }
    $line = 'step=' . $step . ' result=' . $result;
    if ($detail !== '') {
        $line .= ' err=' . substr(preg_replace('/\s+/', ' ', $detail) ?? $detail, 0, 220);
    }
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
}

/**
 * Retry a convert attempt a few times with short backoff (OVH flaky mirrors).
 *
 * @param callable():bool $fn
 */
function ytl_convert_retry(callable $fn, int $times, string $logFile, string $step): bool
{
    $times = max(1, min(4, $times));
    for ($i = 1; $i <= $times; $i++) {
        if ($fn()) {
            ytl_convert_log_step($logFile, $step, 'ok', 'attempt=' . $i);
            return true;
        }
        ytl_convert_log_step($logFile, $step, 'fail', 'attempt=' . $i);
        if ($i < $times) {
            usleep(400000 * $i);
        }
    }
    return false;
}

/** True when the browser session has a Sign-in user (even if token refresh fails). */
function ytl_convert_visitor_signed_in(): bool
{
    if (!function_exists('ytl_current_user')) {
        require_once __DIR__ . '/oauth.php';
    }
    return ytl_current_user() !== null;
}

/**
 * OAuth access token of the signed-in visitor, or null for guests.
 */
function ytl_convert_user_oauth_token(): ?string
{
    if (!function_exists('ytl_user_access_token')) {
        require_once __DIR__ . '/oauth.php';
    }
    $token = ytl_user_access_token();
    return (is_string($token) && $token !== '') ? $token : null;
}

/**
 * Bearer for Innertube progressive streams during convert.
 * Prefer YouTube TV device-code token (player-accepted). Cloud Web visitor
 * OAuth is never used for download (HTTP 400) — skip straight to Pixr service
 * only when there is no TV session. Never passed to yt-dlp / ANDROID.
 *
 * @return array{token:string,kind:string}|null
 */
function ytl_convert_stream_oauth_info(): ?array
{
    if (!function_exists('ytl_tv_access_token')) {
        $tvLib = __DIR__ . '/youtube_tv_oauth.php';
        if (is_readable($tvLib)) {
            require_once $tvLib;
        }
    }
    if (function_exists('ytl_tv_access_token')) {
        $tv = ytl_tv_access_token();
        if (is_string($tv) && $tv !== '') {
            return ['token' => $tv, 'kind' => 'youtube_tv'];
        }
    }
    // Cloud Web visitor Bearer cannot download — do not prefer it over TV/service.
    // Guests / Cloud-only still get Pixr service OAuth as a best-effort try.
    if (!function_exists('ytl_service_access_token')) {
        require_once __DIR__ . '/oauth.php';
    }
    $service = ytl_service_access_token();
    if (is_string($service) && $service !== '') {
        return ['token' => $service, 'kind' => 'service_oauth'];
    }
    return null;
}

/** Cloud Web OAuth session without YouTube TV / personal jar (useless for Convert). */
function ytl_convert_cloud_only_session(): bool
{
    if (!ytl_convert_visitor_signed_in()) {
        return false;
    }
    if (!function_exists('ytl_tv_current_id')) {
        $tvLib = __DIR__ . '/youtube_tv_oauth.php';
        if (is_readable($tvLib)) {
            require_once $tvLib;
        }
    }
    if (function_exists('ytl_tv_session_ready') && ytl_tv_session_ready()) {
        return false;
    }
    if (function_exists('ytl_tv_current_id') && ytl_tv_current_id() !== null) {
        return false;
    }
    ytl_session_start();
    $auth = (string) ($_SESSION['ytl_user']['auth'] ?? '');
    $sub = (string) ($_SESSION['ytl_user']['sub'] ?? '');
    if ($auth === 'youtube_tv' || str_starts_with($sub, 'tv_')) {
        return false;
    }
    return true;
}

function ytl_convert_stream_oauth_token(): ?string
{
    $info = ytl_convert_stream_oauth_info();
    return $info['token'] ?? null;
}

/**
 * LRU cache cleanup for data/videos/.
 * Call ONLY after a successful convert/put — never at convert start
 * (otherwise a failed attempt deletes the last working MP4).
 *
 * Keeps $keepVideoId plus the newest other videos up to $keepCount total.
 * Leaves .cache, .htaccess, .gitkeep alone.
 */
function ytl_convert_purge_other_media(string $keepVideoId, int $keepCount = 5): int
{
    $keep = preg_replace('/[^A-Za-z0-9_-]/', '', $keepVideoId) ?? '';
    if ($keep === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $keep)) {
        return 0;
    }
    $keepCount = max(1, min(20, $keepCount));
    $dir = ytl_convert_dir();
    if (!is_dir($dir)) {
        return 0;
    }
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return 0;
    }

    /** @var array<string, array{mtime:int, files:list<string>}> $byId */
    $byId = [];
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..' || $name === '.cache' || $name === '.htaccess' || $name === '.gitkeep') {
            continue;
        }
        $path = $dir . '/' . $name;
        if (is_dir($path)) {
            continue;
        }
        // {id}.mp4|mp3|log|lock|status.json, {id}_{q}.mp4, {id}_mp3.*, {id}.f*.*
        if (!preg_match(
            '/^([A-Za-z0-9_-]{6,20})(?:_\d+|_mp3)?(?:\.f\d+)?\.(?:mp4|mp3|webm|mkv|m4a|opus|part|ytdl|mhtml|lock|log|status\.json)$/',
            $name,
            $m
        )) {
            continue;
        }
        $id = $m[1];
        $mtime = (int) (@filemtime($path) ?: 0);
        if (!isset($byId[$id])) {
            $byId[$id] = ['mtime' => $mtime, 'files' => []];
        }
        $byId[$id]['files'][] = $path;
        if ($mtime > $byId[$id]['mtime']) {
            $byId[$id]['mtime'] = $mtime;
        }
    }

    // Always retain the video that just succeeded.
    $retain = [$keep => true];
    $others = [];
    foreach ($byId as $id => $info) {
        if ($id === $keep) {
            continue;
        }
        $others[] = ['id' => $id, 'mtime' => $info['mtime']];
    }
    usort($others, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
    $slots = $keepCount - 1;
    foreach ($others as $row) {
        if ($slots <= 0) {
            break;
        }
        $retain[$row['id']] = true;
        $slots--;
    }

    $removed = 0;
    foreach ($byId as $id => $info) {
        if (isset($retain[$id])) {
            continue;
        }
        foreach ($info['files'] as $path) {
            if (@unlink($path)) {
                $removed++;
            }
        }
    }
    return $removed;
}

function ytl_convert_build_cmd(string $videoId, int $height = 360, string $fmt = 'mp4'): string
{
    return ytl_convert_build_cmd_with_cookie($videoId, $height, $fmt);
}

/**
 * yt-dlp command. Default cookie = durable Pixr ytdlp session (always-on).
 * Pass '-' to force android/tv without cookies.
 */
function ytl_convert_build_cmd_with_cookie(string $videoId, int $height = 360, string $fmt = 'mp4', ?string $cookiePath = null): string
{
    $py = ytl_python_bin();
    $script = ytl_convert_script();
    if ($py === null || !is_readable($script)) {
        return '';
    }
    $fmt = ytl_convert_normalize_fmt($fmt);
    $dir = ytl_convert_dir();
    if ($cookiePath === null) {
        $cookie = ytl_ytdlp_session_cookie_for_convert();
    } else {
        $cookie = trim($cookiePath);
    }
    if ($cookie === '' || $cookie === '-') {
        $cookie = '-';
    } elseif (!is_readable($cookie) || @filesize($cookie) < 20) {
        $cookie = '-';
    }
    $url = 'https://www.youtube.com/watch?v=' . $videoId;
    return escapeshellarg($py)
        . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg($cookie)
        . ' ' . escapeshellarg($dir)
        . ' ' . escapeshellarg((string) ($height > 0 ? $height : 360))
        . ' ' . escapeshellarg($fmt)
        . ' ' . escapeshellarg($url);
}

/**
 * Pick best progressive MP4 URL from an Innertube video payload.
 *
 * @param array<string,mixed> $video
 */
function ytl_convert_pick_progressive_url(array $video, int $height): string
{
    if (empty($video['formatStreams']) || !is_array($video['formatStreams'])) {
        return '';
    }
    $height = ytl_convert_normalize_quality($height);
    $bestUrl = '';
    $bestScore = PHP_INT_MAX;
    foreach ($video['formatStreams'] as $f) {
        if (!is_array($f)) {
            continue;
        }
        $url = (string) ($f['url'] ?? '');
        if ($url === '' || !str_starts_with($url, 'http')) {
            continue;
        }
        $container = strtolower((string) ($f['container'] ?? ''));
        $type = strtolower((string) ($f['type'] ?? ''));
        if ($container !== 'mp4' && !str_contains($type, 'mp4')) {
            continue;
        }
        $label = (string) ($f['qualityLabel'] ?? ($f['quality'] ?? ($f['resolution'] ?? '')));
        $h = 0;
        if (preg_match('/(\d{3,4})/', $label, $m)) {
            $h = (int) $m[1];
        }
        if ($h <= 0) {
            $h = 360;
        }
        $score = abs($h - $height);
        if ($h > $height) {
            $score += 50;
        }
        if ($score < $bestScore) {
            $bestScore = $score;
            $bestUrl = $url;
        }
    }
    return $bestUrl;
}

/**
 * Convert using YouTube TV / Cloud OAuth access token.
 * YouTube TV Bearer + TVHTML5 first (Tube Replacer-style); Cloud Bearer often HTTP 400.
 * Proxies are a last resort inside this helper; yt-dlp cookie jar is handled by the caller.
 */
function ytl_convert_try_oauth_innertube(string $videoId, int $height, string $accessToken, string $logFile = '', string $kind = 'cloud_oauth', bool $tryProxies = true): bool
{
    $token = trim($accessToken);
    if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
        return false;
    }
    $log = static function (string $line) use ($logFile): void {
        if ($logFile === '') {
            return;
        }
        @file_put_contents($logFile, $line . "\n", FILE_APPEND);
    };

    require_once __DIR__ . '/innertube.php';
    $cfg = ytl_http_config();
    $cfg['youtube_cookie'] = '';
    $cfg['youtube_cookie_file'] = '';
    $client = new InnertubeClient($cfg);

    ytl_convert_write_status($videoId, 'downloading', [
        'percent' => 14,
        'message' => $kind === 'youtube_tv' ? 'Fetching with your Google account…' : 'Fetching with your account…',
        'quality' => $height,
        'format' => 'mp4',
    ]);
    if ($kind === 'youtube_tv') {
        $video = $client->videoWithTvOauth($videoId, $token);
    } else {
        // Cloud Web OAuth: still try, but expect HTTP 400 on WEB/TV often.
        $video = $client->videoWithBearer($videoId, $token);
    }
    $bestUrl = is_array($video) ? ytl_convert_pick_progressive_url($video, $height) : '';
    $adCount = is_array($video) ? count($video['adaptiveFormats'] ?? []) : 0;
    $log('innertube account kind=' . $kind
        . ' formats=' . (is_array($video) ? count($video['formatStreams'] ?? []) : 0)
        . ' adaptive=' . $adCount
        . ' hls=' . (!empty($video['hlsUrl']) ? '1' : '0')
        . ' src=' . (is_array($video) ? (string) ($video['_source'] ?? '') : 'null')
        . ' inst=' . (string) ($client->getActiveInstance() ?? '')
        . ' err=' . ytl_http_last_error());
    if ($bestUrl !== '') {
        $ok = ytl_convert_save_url($videoId, $bestUrl, true, $height);
        $log('innertube account progressive save=' . ($ok ? 'ok' : 'fail'));
        if ($ok) {
            return true;
        }
    }
    if (is_array($video) && ytl_convert_try_adaptive_merge($videoId, $video, $height, $logFile)) {
        $log('innertube account adaptive merge=ok');
        return true;
    }
    if (is_array($video) && !empty($video['hlsUrl']) && ytl_convert_save_hls($videoId, (string) $video['hlsUrl'], $height, $logFile)) {
        $log('innertube account hls save=ok');
        return true;
    }

    // 2) Optional mirrors (caller may already have run Cobalt/Piped).
    if ($tryProxies) {
        ytl_convert_write_status($videoId, 'downloading', [
            'percent' => 22,
            'message' => 'Trying mirrors…',
            'quality' => $height,
            'format' => 'mp4',
        ]);
        if (ytl_convert_try_proxy_backends($videoId, $height, $logFile)) {
            return true;
        }
    }

    return false;
}

/**
 * Mux adaptive video+audio URLs (when Innertube returns direct urls) via ffmpeg.
 *
 * @param array<string,mixed> $video
 */
function ytl_convert_try_adaptive_merge(string $videoId, array $video, int $height, string $logFile = ''): bool
{
    $height = ytl_convert_normalize_quality($height);
    $formats = $video['adaptiveFormats'] ?? [];
    if (!is_array($formats) || $formats === []) {
        return false;
    }
    $bestVideo = null;
    $bestVideoScore = PHP_INT_MAX;
    $bestAudio = null;
    foreach ($formats as $f) {
        if (!is_array($f)) {
            continue;
        }
        $url = (string) ($f['url'] ?? '');
        if ($url === '' || !str_starts_with($url, 'http')) {
            continue;
        }
        $type = strtolower((string) ($f['type'] ?? ''));
        $label = (string) ($f['qualityLabel'] ?? ($f['quality'] ?? ($f['resolution'] ?? '')));
        if (str_contains($type, 'video')) {
            $h = 0;
            if (preg_match('/(\d{3,4})/', $label, $m)) {
                $h = (int) $m[1];
            }
            if ($h <= 0) {
                $h = 360;
            }
            $score = abs($h - $height);
            if ($h > $height) {
                $score += 40;
            }
            // Prefer mp4/avc over webm when scores tie.
            if (!str_contains($type, 'mp4')) {
                $score += 5;
            }
            if ($score < $bestVideoScore) {
                $bestVideoScore = $score;
                $bestVideo = $f;
            }
        } elseif (str_contains($type, 'audio')) {
            // Prefer original/default track — auto-dubs (ES/FR/…) often ship as extra audio formats.
            $track = is_array($f['audioTrack'] ?? null) ? $f['audioTrack'] : [];
            $label = strtolower(trim(
                (string) ($track['displayName'] ?? ($track['id'] ?? ($f['language'] ?? ($f['audioTrackType'] ?? ''))))
            ));
            $score = (int) ($f['bitrate'] ?? 0);
            if (!empty($track['audioIsDefault']) || preg_match('/original|default|primary/', $label)) {
                $score += 5_000_000;
            }
            if (preg_match('/\ben\b|english|anglais/', $label)) {
                $score += 2_000_000;
            }
            if (preg_match('/dub|espa[nñ]ol|spanish|castellano|latino|fran[cç]ais|french|deutsch|german|portugu/', $label)
                && !preg_match('/original/', $label)) {
                $score -= 8_000_000;
            }
            if (str_contains($type, 'mp4')) {
                $score += 50_000;
            }
            $prev = is_array($bestAudio) ? (int) ($bestAudio['_ytl_score'] ?? -1) : -1;
            if ($bestAudio === null || $score > $prev) {
                $f['_ytl_score'] = $score;
                $bestAudio = $f;
            }
        }
    }
    if ($bestVideo === null || $bestAudio === null) {
        return false;
    }
    $vUrl = (string) ($bestVideo['url'] ?? '');
    $aUrl = (string) ($bestAudio['url'] ?? '');
    if ($vUrl === '' || $aUrl === '') {
        return false;
    }

    $bin = dirname(__DIR__) . '/bin';
    $ffmpeg = $bin . '/ffmpeg';
    if (!is_file($ffmpeg) || !is_executable($ffmpeg)) {
        $gz = $bin . '/ffmpeg.gz';
        if (is_file($gz) && function_exists('exec')) {
            @exec('gzip -dc ' . escapeshellarg($gz) . ' > ' . escapeshellarg($ffmpeg) . ' 2>/dev/null');
            @chmod($ffmpeg, 0755);
        }
    }
    if (!is_file($ffmpeg) || !is_executable($ffmpeg)) {
        if ($logFile !== '') {
            @file_put_contents($logFile, "adaptive: ffmpeg missing\n", FILE_APPEND);
        }
        return false;
    }

    $dest = ytl_convert_mp4_path($videoId, $height);
    $tmp = $dest . '.adapt.mp4';
    @unlink($tmp);
    ytl_convert_write_status($videoId, 'downloading', [
        'percent' => 20,
        'message' => 'Downloading (adaptive)…',
        'quality' => $height,
        'format' => 'mp4',
    ]);
    $cmd = escapeshellarg($ffmpeg)
        . ' -y -loglevel error'
        . ' -user_agent ' . escapeshellarg('com.google.android.youtube/21.26.364 (Linux; U; Android 11) gzip')
        . ' -headers ' . escapeshellarg("Referer: https://www.youtube.com/\r\n")
        . ' -i ' . escapeshellarg($vUrl)
        . ' -i ' . escapeshellarg($aUrl)
        . ' -c copy -map 0:v:0 -map 1:a:0 -shortest '
        . escapeshellarg($tmp)
        . ' 2>&1';
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    if ($logFile !== '') {
        @file_put_contents($logFile, 'adaptive ffmpeg exit=' . $code . ' ' . substr(implode(' ', $out), 0, 200) . "\n", FILE_APPEND);
    }
    if ($code !== 0 || !is_file($tmp) || filesize($tmp) < 50000) {
        @unlink($tmp);
        return false;
    }
    @unlink($dest);
    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        return false;
    }
    return ytl_convert_is_ready($videoId, $height, 'mp4');
}

/** Cobalt-only fast path (used before Bearer so signed-in convert does not starve proxies). */
function ytl_convert_try_cobalt(string $videoId, int $height, string $logFile = ''): bool
{
    $log = static function (string $line) use ($logFile): void {
        if ($logFile === '') {
            return;
        }
        @file_put_contents($logFile, $line . "\n", FILE_APPEND);
    };
    $lastDetail = 'cobalt_empty';
    foreach (ytl_cobalt_apis() as $api) {
        // Visitor browser may have finished convert_client_put while we loop.
        if (ytl_convert_is_ready($videoId, $height, 'mp4')) {
            $log('cobalt-first skipped — client already ready');
            return true;
        }
        $res = ytl_cobalt_lookup($api, $videoId, $height);
        $host = $res['host'] !== '' ? $res['host'] : $api;
        $detail = $host . ' status=' . $res['status'] . ' http=' . $res['http']
            . ($res['error'] !== '' ? (' err=' . $res['error']) : '');
        $log('cobalt-first ' . $detail);
        $code = ytl_convert_classify_error(
            ($res['error'] !== '' ? $res['error'] : '') . ' cobalt http=' . $res['http']
        );
        ytl_convert_set_last_fail($code, $detail);
        $lastDetail = $detail;
        // jwt/turnstile hosts cannot work from OVH — skip fast.
        if ($res['error'] !== '' && (str_contains($res['error'], 'jwt') || str_contains($res['error'], 'turnstile'))) {
            continue;
        }
        if ($res['url'] !== '') {
            $cobaltRef = 'https://' . ($res['host'] !== '' ? $res['host'] : 'cobalt.tools') . '/';
            if (str_contains($res['host'], 'xenon.zone')) {
                $cobaltRef = 'https://cobalt.xenon.zone/';
            }
            if (ytl_convert_save_url($videoId, $res['url'], true, $height, $cobaltRef)) {
                $log('cobalt-first save=ok');
                return true;
            }
            $log('cobalt-first save=fail (empty tunnel?) ' . $detail);
            ytl_convert_set_last_fail('cobalt_tunnel', 'empty_or_short ' . $detail);
            $lastDetail = 'empty_or_short ' . $detail;
            continue;
        }
    }
    if (!ytl_convert_is_ready($videoId, $height, 'mp4')) {
        ytl_convert_set_last_fail(ytl_convert_classify_error($lastDetail), $lastDetail);
        return false;
    }
    return true;
}

/**
 * Download an HLS master playlist to MP4 via bundled ffmpeg (visitor OAuth / TV clients).
 */
function ytl_convert_save_hls(string $videoId, string $hlsUrl, int $height = 360, string $logFile = ''): bool
{
    $hlsUrl = trim($hlsUrl);
    if ($hlsUrl === '' || !preg_match('#^https?://#i', $hlsUrl)) {
        return false;
    }
    if (str_contains($hlsUrl, 'yt_live_broadcast')) {
        return false;
    }
    $bin = dirname(__DIR__) . '/bin';
    $ffmpeg = $bin . '/ffmpeg';
    if (!is_file($ffmpeg) || !is_executable($ffmpeg)) {
        // Try extracting .gz once (same as converter.py setup).
        $gz = $bin . '/ffmpeg.gz';
        if (is_file($gz) && function_exists('exec')) {
            @exec('gzip -dc ' . escapeshellarg($gz) . ' > ' . escapeshellarg($ffmpeg) . ' 2>/dev/null');
            @chmod($ffmpeg, 0755);
        }
    }
    if (!is_file($ffmpeg) || !is_executable($ffmpeg)) {
        if ($logFile !== '') {
            @file_put_contents($logFile, "hls: ffmpeg missing\n", FILE_APPEND);
        }
        return false;
    }
    $height = ytl_convert_normalize_quality($height);
    $dest = ytl_convert_mp4_path($videoId, $height);
    $tmp = $dest . '.hls.mp4';
    @unlink($tmp);
    ytl_convert_write_status($videoId, 'downloading', [
        'percent' => 25,
        'message' => 'Downloading (HLS)…',
        'quality' => $height,
        'format' => 'mp4',
    ]);
    $cmd = escapeshellarg($ffmpeg)
        . ' -y -loglevel error -user_agent '
        . escapeshellarg('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
        . ' -headers ' . escapeshellarg("Referer: https://www.youtube.com/\r\n")
        . ' -i ' . escapeshellarg($hlsUrl)
        . ' -c copy -bsf:a aac_adtstoasc '
        . escapeshellarg($tmp)
        . ' 2>&1';
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    if ($logFile !== '') {
        @file_put_contents($logFile, 'hls ffmpeg exit=' . $code . ' ' . substr(implode(' ', $out), 0, 200) . "\n", FILE_APPEND);
    }
    if ($code !== 0 || !is_file($tmp) || filesize($tmp) < 50000) {
        @unlink($tmp);
        return false;
    }
    @unlink($dest);
    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        return false;
    }
    return ytl_convert_is_ready($videoId, $height, 'mp4');
}

/**
 * Cache MP4 from Piped (all instances) / Invidious / Cobalt — no shared YouTube cookie.
 */
function ytl_convert_try_proxy_backends(string $videoId, int $height, string $logFile = ''): bool
{
    $log = static function (string $line) use ($logFile): void {
        if ($logFile === '') {
            return;
        }
        @file_put_contents($logFile, $line . "\n", FILE_APPEND);
    };

    // 1) Cobalt first (off-host MP4) — must not be starved by Piped fan-out.
    if (ytl_convert_try_cobalt($videoId, $height, $logFile)) {
        return true;
    }

    // 2) Piped: fan-out every configured instance (one instance often 403 while another works).
    try {
        require_once __DIR__ . '/piped.php';
        $piped = new PipedClient(ytl_http_config());
        $muxed = $piped->muxedFromAllInstances($videoId);
        $log('piped muxed hosts=' . count($muxed));
        if (count($muxed) === 0) {
            ytl_convert_set_last_fail('piped_mux_empty', 'piped muxed hosts=0');
            ytl_convert_log_step($logFile, 'piped', 'fail', 'muxed hosts=0');
        }
        usort($muxed, static function (array $a, array $b) use ($height): int {
            $ma = (($a['container'] ?? '') === 'mp4') ? 0 : 1;
            $mb = (($b['container'] ?? '') === 'mp4') ? 0 : 1;
            if ($ma !== $mb) {
                return $ma <=> $mb;
            }
            return abs((int) ($a['height'] ?? 0) - $height) <=> abs((int) ($b['height'] ?? 0) - $height);
        });
        foreach ($muxed as $row) {
            $url = (string) ($row['url'] ?? '');
            $host = (string) ($row['host'] ?? '');
            if ($url === '') {
                continue;
            }
            $api = (string) ($row['api'] ?? '');
            $log('piped try host=' . $host . ' api=' . $api . ' h=' . (int) ($row['height'] ?? 0));
            $ref = (string) ($row['referer'] ?? '');
            if (ytl_convert_save_url($videoId, $url, true, $height, $ref)) {
                $log('piped save=ok host=' . $host . ' api=' . $api);
                return true;
            }
            $log('piped save=fail host=' . $host . ' api=' . $api);
        }
    } catch (Throwable $e) {
        $log('piped muxed error: ' . $e->getMessage());
    }

    require_once __DIR__ . '/api.php';
    try {
        $api = ytl_client();
        $cands = $api->progressiveCandidates($videoId, $height);
        $log('proxy candidates=' . count($cands));
        foreach ($cands as $cand) {
            if (!is_array($cand) || empty($cand['url'])) {
                continue;
            }
            $backend = (string) ($cand['backend'] ?? '?');
            if ($backend === 'piped') {
                continue; // already tried all Piped instances above
            }
            $host = strtolower((string) ($cand['host'] ?? (parse_url((string) $cand['url'], PHP_URL_HOST) ?? '')));
            $plain = !empty($cand['proxyish'])
                || str_contains($host, 'googlevideo')
                || str_contains($host, 'googleusercontent');
            $log('proxy try backend=' . $backend . ' host=' . $host);
            if (ytl_convert_save_url($videoId, (string) $cand['url'], $plain, $height)) {
                $log('proxy backend save=ok (' . $backend . ')');
                return true;
            }
            $log('proxy backend save=fail (' . $backend . ')');
        }
    } catch (Throwable $e) {
        $log('proxy backends error: ' . $e->getMessage());
    }

    // Innertube: anonymous ANDROID/IOS first (no Pixr jar), then shared-cookie player.
    try {
        require_once __DIR__ . '/innertube.php';
        $cfg = ytl_http_config();
        $anonCfg = $cfg;
        $anonCfg['youtube_cookie'] = '';
        $anonCfg['youtube_cookie_file'] = '';
        $anon = new InnertubeClient($anonCfg);
        $video = $anon->video($videoId);
        $bestUrl = is_array($video) ? ytl_convert_pick_progressive_url($video, $height) : '';
        $log('innertube-anon formats=' . (is_array($video) ? count($video['formatStreams'] ?? []) : 0)
            . ' hls=' . (!empty($video['hlsUrl']) ? '1' : '0')
            . ' err=' . ytl_http_last_error());
        if ($bestUrl !== '' && ytl_convert_save_url($videoId, $bestUrl, true, $height)) {
            $log('innertube-anon save=ok');
            return true;
        }
        if (is_array($video) && ytl_convert_try_adaptive_merge($videoId, $video, $height, $logFile)) {
            $log('innertube-anon adaptive merge=ok');
            return true;
        }
        if (is_array($video) && !empty($video['hlsUrl']) && ytl_convert_save_hls($videoId, (string) $video['hlsUrl'], $height, $logFile)) {
            $log('innertube-anon hls save=ok');
            return true;
        }
    } catch (Throwable $e) {
        $log('innertube-anon error: ' . $e->getMessage());
    }
    try {
        require_once __DIR__ . '/innertube.php';
        $cfg = ytl_http_config();
        $client = new InnertubeClient($cfg);
        $video = $client->video($videoId);
        $bestUrl = is_array($video) ? ytl_convert_pick_progressive_url($video, $height) : '';
        $log('innertube+cookie formats=' . (is_array($video) ? count($video['formatStreams'] ?? []) : 0)
            . ' err=' . ytl_http_last_error());
        if ($bestUrl !== '' && ytl_convert_save_url($videoId, $bestUrl, true, $height)) {
            $log('innertube+cookie save=ok');
            return true;
        }
    } catch (Throwable $e) {
        $log('innertube+cookie error: ' . $e->getMessage());
    }

    return false;
}

/** True when shared Pixr cookie is known-dead (skip useless yt-dlp). */
function ytl_convert_shared_cookie_usable(): bool
{
    $cfg = ytl_http_config();
    $file = (string) ($cfg['youtube_cookie_file'] ?? '');
    if ($file === '' || !is_readable($file) || @filesize($file) < 20) {
        return false;
    }
    $health = dirname(__DIR__) . '/data/cookie_health.json';
    if (is_readable($health)) {
        $j = json_decode((string) file_get_contents($health), true);
        if (is_array($j)) {
            $state = (string) ($j['state'] ?? '');
            if (in_array($state, ['expired', 'invalid', 'history_off'], true) || !empty($j['alert'])) {
                if (empty($j['ok'])) {
                    return false;
                }
            }
        }
    }
    return true;
}

function ytl_convert_is_mp4_file(string $path): bool
{
    if (!is_file($path) || filesize($path) < 20000) {
        return false;
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return false;
    }
    $head = fread($fh, 64);
    fclose($fh);
    if (!is_string($head) || $head === '') {
        return false;
    }
    if (str_starts_with(ltrim($head), '<') || str_starts_with($head, '{')) {
        return false;
    }
    return str_contains($head, 'ftyp') || str_contains($head, 'moov');
}

/** Durée annoncée dans le moov (mvhd), ou null. */
function ytl_mp4_duration_seconds(string $path): ?float
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return null;
    }
    $buf = fread($fh, 700000);
    fclose($fh);
    if (!is_string($buf)) {
        return null;
    }
    $p = strpos($buf, 'mvhd');
    if ($p === false || strlen($buf) < $p + 24) {
        return null;
    }
    $ver = ord($buf[$p + 4]);
    if ($ver === 0) {
        $scale = unpack('N', substr($buf, $p + 16, 4))[1];
        $dur = unpack('N', substr($buf, $p + 20, 4))[1];
    } else {
        if (strlen($buf) < $p + 36) {
            return null;
        }
        $scale = unpack('N', substr($buf, $p + 24, 4))[1];
        $hi = unpack('N', substr($buf, $p + 28, 4))[1];
        $lo = unpack('N', substr($buf, $p + 32, 4))[1];
        $dur = ($hi * 4294967296) + $lo;
    }
    if ($scale < 1) {
        return null;
    }
    return $dur / $scale;
}

/** Les boîtes de premier niveau couvrent tout le fichier (téléchargement non coupé). */
function ytl_mp4_boxes_complete(string $path): bool
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return false;
    }
    $size = (int) filesize($path);
    $offset = 0;
    $sawFtyp = false;
    $sawMedia = false;
    while ($offset + 8 <= $size) {
        if (fseek($fh, $offset) !== 0) {
            fclose($fh);
            return false;
        }
        $hdr = fread($fh, 16);
        if (!is_string($hdr) || strlen($hdr) < 8) {
            fclose($fh);
            return false;
        }
        $box = unpack('N', substr($hdr, 0, 4))[1];
        $type = substr($hdr, 4, 4);
        if ($type === 'ftyp') {
            $sawFtyp = true;
        }
        if ($type === 'mdat' || $type === 'moof' || $type === 'moov') {
            $sawMedia = true;
        }
        if ($box === 0) {
            fclose($fh);
            return $sawFtyp && $sawMedia;
        }
        if ($box === 1) {
            if (strlen($hdr) < 16) {
                fclose($fh);
                return false;
            }
            $hi = unpack('N', substr($hdr, 8, 4))[1];
            $lo = unpack('N', substr($hdr, 12, 4))[1];
            $box = ($hi * 4294967296) + $lo;
        }
        if ($box < 8 || $offset + $box > $size) {
            fclose($fh);
            return false;
        }
        $offset += $box;
    }
    fclose($fh);
    return $offset === $size && $sawFtyp && $sawMedia;
}

/** Refuse un MP4 coupé ou dont le moov décrit une vidéo bien plus longue que les octets reçus. */
function ytl_mp4_payload_ok(string $path): bool
{
    if (!ytl_convert_is_mp4_file($path) || !ytl_mp4_boxes_complete($path)) {
        return false;
    }
    $size = (int) filesize($path);
    $dur = ytl_mp4_duration_seconds($path);
    if ($dur === null || $dur < 1) {
        return $size > 20000;
    }
    // 360p réel est largement au-dessus de 8 Ko/s. En dessous, le téléchargement est coupé.
    return $size >= (int) ($dur * 8000);
}

/** @return list<string> */
function ytl_cobalt_apis(): array
{
    $cfg = ytl_http_config();
    $list = $cfg['cobalt_apis'] ?? [
        'https://rue-cobalt.xenon.zone',
    ];
    if (!is_array($list)) {
        return ['https://rue-cobalt.xenon.zone'];
    }
    $out = [];
    foreach ($list as $api) {
        $api = rtrim(trim((string) $api), '/');
        if ($api !== '' && str_starts_with($api, 'https://')) {
            $out[] = $api;
        }
    }
    return $out !== [] ? $out : ['https://rue-cobalt.xenon.zone'];
}

/**
 * Demande un MP4 360p à une API qui télécharge YouTube de son côté.
 *
 * @return array{url:string,http:int,status:string,error:string,host:string}
 */
/**
 * Best-effort Cobalt session Bearer (instances that require JWT without Turnstile).
 */
function ytl_cobalt_session_token(string $api): string
{
    $ssl = function_exists('ytl_http_ssl_verify') ? ytl_http_ssl_verify() : false;
    $ch = curl_init(rtrim($api, '/') . '/session');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '{}',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ],
        CURLOPT_SSL_VERIFYPEER => $ssl,
        CURLOPT_SSL_VERIFYHOST => $ssl ? 2 : 0,
    ]);
    if (defined('CURL_IPRESOLVE_V4')) {
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }
    $raw = curl_exec($ch);
    curl_close($ch);
    if (!is_string($raw) || $raw === '') {
        return '';
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return '';
    }
    $token = (string) ($json['token'] ?? '');
    if ($token === '' && isset($json['data']) && is_array($json['data'])) {
        $token = (string) ($json['data']['token'] ?? '');
    }
    return $token;
}

function ytl_cobalt_lookup(string $api, string $videoId, int $height): array
{
    $host = (string) (parse_url($api, PHP_URL_HOST) ?? '');
    $result = ['url' => '', 'http' => 0, 'status' => '', 'error' => '', 'host' => $host];
    $body = json_encode([
        'url' => 'https://www.youtube.com/watch?v=' . $videoId,
        'videoQuality' => (string) ($height > 0 ? $height : 360),
        'youtubeVideoCodec' => 'h264',
        'youtubeVideoContainer' => 'mp4',
        'filenameStyle' => 'basic',
        'downloadMode' => 'auto',
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        $result['error'] = 'encode';
        return $result;
    }

    $ssl = function_exists('ytl_http_ssl_verify') ? ytl_http_ssl_verify() : false;
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ];
    // Public Cobalt CF often 403s bare datacenter IPs — spoof the instance frontend origin.
    $origin = 'https://' . ($host !== '' ? $host : 'cobalt.tools');
    if (str_contains($host, 'xenon.zone')) {
        $origin = 'https://cobalt.xenon.zone';
    } elseif (str_contains($host, 'cjs.nz')) {
        $origin = 'https://cobalt.cjs.nz';
    }
    $headers[] = 'Origin: ' . $origin;
    $headers[] = 'Referer: ' . $origin . '/';
    // Some public instances require a short-lived Bearer from POST /session.
    $session = ytl_cobalt_session_token($api);
    if ($session !== '') {
        $headers[] = 'Authorization: Bearer ' . $session;
    }

    $ch = curl_init(rtrim($api, '/') . '/');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => $ssl,
        CURLOPT_SSL_VERIFYHOST => $ssl ? 2 : 0,
    ]);
    // OVH sort souvent en IPv6, et ces API répondent alors 403.
    if (defined('CURL_IPRESOLVE_V4')) {
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }
    $raw = curl_exec($ch);
    $result['http'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $json = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($json)) {
        $result['error'] = $curlErr !== '' ? $curlErr : 'invalid';
        return $result;
    }
    $result['status'] = (string) ($json['status'] ?? '');
    if (isset($json['error']['code']) && is_string($json['error']['code'])) {
        $result['error'] = $json['error']['code'];
    } elseif ($curlErr !== '') {
        $result['error'] = $curlErr;
    }
    if (in_array($result['status'], ['tunnel', 'redirect', 'local-processing'], true)) {
        $url = (string) ($json['url'] ?? '');
        if ($url === '' && isset($json['tunnel']) && is_string($json['tunnel'])) {
            $url = (string) $json['tunnel'];
        }
        if (str_starts_with($url, 'https://')) {
            $result['url'] = $url;
        }
    }
    return $result;
}

/**
 * Enregistre une URL progressive dans le cache MP4.
 * $plain = flux déjà hors session YouTube cookie.
 * $referer = optional Referer (Piped API origin helps some proxies).
 */
function ytl_convert_save_url(string $videoId, string $url, bool $plain = false, int $height = 360, string $referer = ''): bool
{
    $height = ytl_convert_normalize_quality($height);
    $dest = ytl_convert_mp4_path($videoId, $height);
    $tmp = $dest . '.part';
    @unlink($tmp);
    $fp = fopen($tmp, 'wb');
    if ($fp === false) {
        return false;
    }

    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
    $isGv = !$plain && (str_contains($host, 'googlevideo.com') || str_contains($host, 'googleusercontent.com'));
    $headers = [
        'Accept: */*',
        'User-Agent: ' . ($isGv || $plain
            ? 'com.google.android.youtube/20.10.38 (Linux; U; Android 14) gzip'
            : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'),
    ];
    $ref = trim($referer);
    if ($isGv) {
        $headers[] = 'Referer: ' . ($ref !== '' ? $ref : 'https://www.youtube.com/');
        // Shared Pixr cookie only for guest (non-plain) googlevideo fetches.
        if (function_exists('ytl_cookie_to_header') && function_exists('ytl_youtube_cookie_raw')) {
            $cookieHdr = ytl_cookie_to_header(ytl_youtube_cookie_raw());
            if ($cookieHdr !== '') {
                $headers[] = 'Cookie: ' . $cookieHdr;
            }
        }
    } elseif ($plain && (str_contains($host, 'googlevideo.com') || str_contains($host, 'googleusercontent.com'))) {
        $headers[] = 'Referer: ' . ($ref !== '' ? $ref : 'https://www.youtube.com/');
    } elseif ($ref !== '') {
        $headers[] = 'Referer: ' . $ref;
    } elseif (!$plain) {
        $headers[] = 'Referer: https://piped.video/';
    } elseif (str_contains($host, 'piped')) {
        $headers[] = 'Referer: https://piped.video/';
    }

    $ssl = function_exists('ytl_http_ssl_verify') ? ytl_http_ssl_verify() : false;
    $fmt = 'mp4';
    // Real byte progress → status JSON (prepare bar follows this, not a fake crawl).
    ytl_convert_write_status($videoId, 'downloading', [
        'percent' => 20,
        'message' => 'Downloading…',
        'quality' => $height,
        'format' => $fmt,
    ]);
    $progressState = ['last' => 20, 'tick' => 0];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => $ssl,
        CURLOPT_SSL_VERIFYHOST => $ssl ? 2 : 0,
        CURLOPT_BUFFERSIZE => 262144,
        CURLOPT_NOPROGRESS => false,
    ]);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static function ($resource, $dlTotal, $dlNow, $ulTotal = 0.0, $ulNow = 0.0) use ($videoId, $height, $fmt, &$progressState) {
        $total = (float) $dlTotal;
        $now = (float) $dlNow;
        if ($total <= 0 && $now <= 0) {
            return 0;
        }
        if ($total > 0) {
            $raw = ($now / $total) * 100.0;
            $pct = (int) floor(20 + ($raw * 0.75));
        } else {
            // Unknown Content-Length: +1% per ~400 KiB from 20.
            $pct = min(90, 20 + (int) floor($now / (400 * 1024)));
        }
        if ($pct < 20) {
            $pct = 20;
        }
        if ($pct > 95) {
            $pct = 95;
        }
        $tick = (int) floor(microtime(true) * 4); // ~250ms
        if ($pct > $progressState['last'] && ($pct - $progressState['last'] >= 1 || $tick !== $progressState['tick'])) {
            $progressState['last'] = $pct;
            $progressState['tick'] = $tick;
            $shown = $total > 0 ? (int) floor(($now / $total) * 100.0) : $pct;
            ytl_convert_write_status($videoId, 'downloading', [
                'percent' => $pct,
                'message' => 'Downloading… ' . max(0, min(100, $shown)) . '%',
                'quality' => $height,
                'format' => $fmt,
            ]);
        }
        return 0;
    });
    if (defined('CURL_IPRESOLVE_V4')) {
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }
    if (!$plain && function_exists('ytl_curl_set_proxy')) {
        ytl_curl_set_proxy($ch);
    }
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $expected = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);
    fclose($fp);

    $got = (int) (@filesize($tmp) ?: 0);
    $log = ytl_convert_dir() . '/' . $videoId . '.log';
    $dur = is_file($tmp) ? ytl_mp4_duration_seconds($tmp) : null;
    @file_put_contents(
        $log,
        'GET ' . $host . ' HTTP ' . $code
        . ($err !== '' ? (' ' . $err) : '')
        . ' bytes=' . $got
        . ' expected=' . $expected
        . ' dur=' . ($dur === null ? '?' : (string) round($dur))
        . "\n",
        FILE_APPEND
    );

    $short = $expected > 0 && $got + 4096 < $expected;
    if (!$ok || ($code !== 200 && $code !== 206) || $short || !ytl_mp4_payload_ok($tmp)) {
        @unlink($tmp);
        return false;
    }
    @unlink($dest);
    if (!@rename($tmp, $dest)) {
        @copy($tmp, $dest);
        @unlink($tmp);
    }
    return ytl_convert_is_mp4_file($dest);
}

/** Même shell que la page test : python, cookie, dossier, 360, lien en dernier. */
function ytl_convert_shell(string $cmd, ?string $oauthToken = null): string
{
    $cache = ytl_convert_dir() . '/.cache';
    if (!is_dir($cache)) {
        @mkdir($cache, 0755, true);
    }
    $home = dirname(ytl_convert_dir());
    $env = 'HOME=' . escapeshellarg($home)
        . ' TMPDIR=' . escapeshellarg($cache)
        . ' XDG_CACHE_HOME=' . escapeshellarg($cache);
    // Never pass Cloud OAuth Bearer into yt-dlp (HTTP 400). Env kept empty on purpose.
    $env .= ' YT_OAUTH_ACCESS_TOKEN=';
    if (is_string($oauthToken) && $oauthToken !== '') {
        $runtimeCookie = $cache . '/cookies.txt';
        if (is_file($runtimeCookie)) {
            @unlink($runtimeCookie);
        }
    }
    // Residential proxy (config http_proxy) — required for cookie-less OVH egress.
    if (function_exists('ytl_http_proxy_info')) {
        $px = ytl_http_proxy_info();
        if (!empty($px['enabled']) && !empty($px['url'])) {
            $env .= ' YT_HTTP_PROXY=' . escapeshellarg((string) $px['url']);
        } else {
            $env .= ' YT_HTTP_PROXY=';
        }
    }
    return $env . ' ' . $cmd . ' 2>&1';
}

/**
 * Lance la commande de la page test.
 * Si le MP4 est déjà en cache, ne relance pas Python.
 * $onLine reçoit chaque ligne (la page publique n’en affiche aucune).
 */
function ytl_convert_run_blocking(string $videoId, int $height = 360, ?callable $onLine = null, string $fmt = 'mp4'): int
{
    if (!preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
        return 1;
    }
    $fmt = ytl_convert_normalize_fmt($fmt);
    $height = ytl_convert_normalize_quality($height);

    // Purge only after success (see ready paths / convert_client_put).
    // Purging here deleted the last working MP4 whenever a new convert failed.

    if (ytl_convert_is_ready($videoId, $height, $fmt)) {
        return 0;
    }

    @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    ignore_user_abort(true);

    $lockPath = ytl_convert_lock_path($videoId, $height, $fmt);
    $lock = @fopen($lockPath, 'c+');
    if ($lock === false) {
        ytl_convert_write_status($videoId, 'error', [
            'error' => 'lock',
            'message' => 'Impossible de démarrer',
            'quality' => $height,
            'format' => $fmt,
        ]);
        return 1;
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        // Un autre téléchargement est déjà en cours pour cette qualité.
        return 0;
    }

    $log = ytl_convert_dir() . '/' . $videoId . ($fmt === 'mp3' ? '_mp3' : ($height === 360 ? '' : ('_' . $height))) . '.log';

    // Block live / premiere early — progressive MP4 convert cannot do yt_live_broadcast HLS.
    if (ytl_convert_is_live_video($videoId)) {
        $msg = "Live streams aren't supported — open a regular (non-live) video.";
        @file_put_contents($log, "reject live " . $videoId . "\n");
        ytl_convert_write_status($videoId, 'error', [
            'error' => 'live_unsupported',
            'error_code' => 'live_unsupported',
            'message' => $msg . ' (live_unsupported)',
            'quality' => $height,
            'format' => $fmt,
            'percent' => 0,
        ]);
        flock($lock, LOCK_UN);
        fclose($lock);
        return 1;
    }

    $visitorSignedIn = ytl_convert_visitor_signed_in();
    $cloudOnly = ytl_convert_cloud_only_session();
    $oauthInfo = ytl_convert_stream_oauth_info();
    $streamOauth = $oauthInfo['token'] ?? null;
    $oauthKind = (string) ($oauthInfo['kind'] ?? '');
    $hasTvOauth = $oauthKind === 'youtube_tv';
    // Per-user Netscape jar (account.php) optional. Guests + Cloud Sign-in use Pixr jar.
    // Convert must never depend on TV session — Cobalt/Piped/yt-dlp guest path always runs.
    if (!function_exists('ytl_visitor_cookie_for_convert')) {
        require_once __DIR__ . '/visitor_cookies.php';
    }
    // Ensure durable Pixr jar exists (restore from backup if wiped).
    if (function_exists('ytl_ytdlp_session_ensure_jar')) {
        ytl_ytdlp_session_ensure_jar();
    }
    $visitorJar = $visitorSignedIn ? ytl_visitor_cookie_for_convert() : '-';
    $usingVisitorJar = $visitorSignedIn && $visitorJar !== '-' && $visitorJar !== '';
    if ($usingVisitorJar) {
        $ytdlpCookie = $visitorJar;
    } else {
        // Guest, Cloud-only, or TV-signed-in fallthrough: Pixr durable jar.
        $ytdlpCookie = ytl_ytdlp_session_cookie_for_convert();
    }
    $authTag = $hasTvOauth
        ? 'youtube_tv'
        : ($usingVisitorJar
            ? 'visitor_cookies'
            : ($cloudOnly
                ? 'cloud_oauth_guest_path'
                : ($visitorSignedIn ? 'signed_in_guest_path' : ($streamOauth !== null ? 'service_oauth' : 'proxy'))));
    ytl_session_start();
    $tvIdLog = (string) ($_SESSION['ytl_tv_id'] ?? '');
    $authLog = (string) ($_SESSION['ytl_user']['auth'] ?? '');
    ytl_convert_write_status($videoId, 'downloading', [
        'percent' => 0,
        'message' => 'Starting…',
        'quality' => $height,
        'format' => $fmt,
        'auth' => $authTag,
    ]);
    @file_put_contents(
        $log,
        'start ' . $videoId . ' ' . $authTag
        . ' signed_in=' . ($visitorSignedIn ? '1' : '0')
        . ' cloud_only=' . ($cloudOnly ? '1' : '0')
        . ' oauth_kind=' . ($oauthKind !== '' ? $oauthKind : 'none')
        . ' tv_id=' . ($tvIdLog !== '' ? 'yes' : 'no')
        . ' sess_auth=' . ($authLog !== '' ? $authLog : 'none')
        . ' token=' . ($streamOauth !== null ? 'yes' : 'no')
        . ' visitor_jar=' . ($usingVisitorJar ? 'yes' : 'no')
        . ' ytdlp_cookie=' . ($ytdlpCookie === '-' ? 'none' : basename(dirname($ytdlpCookie)) . '/' . basename($ytdlpCookie))
        . ' ' . $fmt . ' ' . $height . "p\n"
    );

    // Cloud Web Sign-in has no YouTube-usable Bearer — demote to guest Cobalt path
    // (same as unsigned visitors). Do not fail-fast; do not soft-lie about Sign-in.
    if ($fmt === 'mp4' && $cloudOnly && !$hasTvOauth) {
        ytl_session_start();
        unset($_SESSION['ytl_user'], $_SESSION['ytl_oauth_state'], $_SESSION['ytl_oauth_mode']);
        $visitorSignedIn = false;
        $cloudOnly = false;
        $usingVisitorJar = false;
        $ytdlpCookie = ytl_ytdlp_session_cookie_for_convert();
        $authTag = $streamOauth !== null ? 'service_oauth' : 'proxy';
        @file_put_contents($log, "demote cloud_only → guest Cobalt/yt-dlp path\n", FILE_APPEND);
        ytl_convert_write_status($videoId, 'downloading', [
            'percent' => 4,
            'message' => 'Starting…',
            'quality' => $height,
            'format' => $fmt,
            'auth' => $authTag,
        ]);
    }

    // MP4 ladder: TV Bearer → Cobalt → Piped/Innertube → yt-dlp (retries + precise codes).
    $ladderFailCode = 'download_failed';
    $ladderFailDetail = '';
    if ($fmt === 'mp4') {
        ytl_convert_write_status($videoId, 'downloading', [
            'percent' => 8,
            'message' => 'Searching streams…',
            'quality' => $height,
            'format' => $fmt,
            'auth' => $authTag,
        ]);
        @file_put_contents($log, 'convert: '
            . ($hasTvOauth ? 'account-first TV Bearer → Cobalt/Piped' : 'guest-first Cobalt/Piped')
            . " (retries on)\n", FILE_APPEND);

        $okStream = false;

        // 1) Signed-in TV Bearer / HLS first (Tube Replacer), 2 attempts.
        if ($hasTvOauth && $streamOauth !== null) {
            ytl_convert_write_status($videoId, 'downloading', [
                'percent' => 12,
                'message' => 'Fetching with your Google account…',
                'quality' => $height,
                'format' => $fmt,
                'auth' => $authTag,
            ]);
            $okStream = ytl_convert_retry(
                static function () use ($videoId, $height, $streamOauth, $log): bool {
                    return ytl_convert_try_oauth_innertube(
                        $videoId,
                        $height,
                        $streamOauth,
                        $log,
                        'youtube_tv',
                        false
                    );
                },
                2,
                $log,
                'tv_bearer'
            );
            if (!$okStream) {
                $httpErr = function_exists('ytl_http_last_error') ? (string) ytl_http_last_error() : '';
                $ladderFailCode = ytl_convert_classify_error($httpErr !== '' ? $httpErr : 'oauth_http_400');
                $ladderFailDetail = $httpErr !== '' ? $httpErr : 'tv_bearer_miss';
                ytl_convert_set_last_fail($ladderFailCode, $ladderFailDetail);
            }
        }

        // 2) Cobalt (guest + TV fallthrough), 2 full sweeps.
        if (!$okStream) {
            ytl_convert_write_status($videoId, 'downloading', [
                'percent' => 16,
                'message' => 'Fetching via mirrors…',
                'quality' => $height,
                'format' => $fmt,
                'auth' => $authTag,
            ]);
            $okStream = ytl_convert_retry(
                static function () use ($videoId, $height, $log): bool {
                    return ytl_convert_try_cobalt($videoId, $height, $log);
                },
                2,
                $log,
                'cobalt'
            );
            if (!$okStream) {
                $lf = ytl_convert_get_last_fail();
                $ladderFailCode = $lf['code'];
                $ladderFailDetail = $lf['detail'] !== '' ? $lf['detail'] : 'cobalt_miss';
            }
        }

        // 3) Piped + Innertube anon / cookie, 2 attempts.
        if (!$okStream) {
            ytl_convert_write_status($videoId, 'downloading', [
                'percent' => 20,
                'message' => 'Trying more mirrors…',
                'quality' => $height,
                'format' => $fmt,
                'auth' => $authTag,
            ]);
            $okStream = ytl_convert_retry(
                static function () use ($videoId, $height, $log): bool {
                    return ytl_convert_try_proxy_backends($videoId, $height, $log);
                },
                2,
                $log,
                'piped_proxy'
            );
            if (!$okStream) {
                $ladderFailCode = 'piped_mux_empty';
                $ladderFailDetail = 'piped_proxy_miss';
                ytl_convert_set_last_fail($ladderFailCode, $ladderFailDetail);
            }
        }

        if ($okStream) {
            ytl_convert_write_status($videoId, 'ready', [
                'bytes' => (int) filesize(ytl_convert_media_path($videoId, $height, $fmt)),
                'backend' => 'stream-proxy',
                'quality' => $height,
                'format' => $fmt,
                'auth' => $authTag,
            ]);
            ytl_convert_purge_other_media($videoId);
            @file_put_contents($log, "stream account/cobalt/proxy OK\n", FILE_APPEND);
            flock($lock, LOCK_UN);
            fclose($lock);
            return 0;
        }

        ytl_convert_log_step($log, 'ladder', 'exhausted', $ladderFailCode . ' ' . $ladderFailDetail);

        if ($ytdlpCookie === '-' || $ytdlpCookie === '') {
            @file_put_contents($log, "stream miss — no yt-dlp jar after mirrors\n", FILE_APPEND);
            ytl_convert_handoff_client_cobalt(
                $videoId,
                $height,
                $fmt,
                $authTag,
                $log,
                $ladderFailCode . ':' . $ladderFailDetail
            );
            flock($lock, LOCK_UN);
            fclose($lock);
            return 0;
        }

        $ytAuth = $usingVisitorJar ? 'ytdlp_visitor_cookies' : ($hasTvOauth ? 'ytdlp_after_tv' : 'ytdlp_pixr_session');
        @file_put_contents(
            $log,
            'stream miss — yt-dlp auth=' . $ytAuth
            . ' after_code=' . $ladderFailCode
            . " (never Cloud Bearer into yt-dlp)\n",
            FILE_APPEND
        );
        ytl_convert_write_status($videoId, 'downloading', [
            'percent' => 28,
            'message' => 'Starting download…',
            'quality' => $height,
            'format' => $fmt,
            'auth' => $ytAuth,
            'error_code' => $ladderFailCode,
        ]);
    }

    // Never pass OAuth Bearer into yt-dlp / ANDROID — causes HTTP 400 / bot checks.
    $ytDlpOauth = null;
    $cmd = ytl_convert_build_cmd_with_cookie($videoId, $height, $fmt, $ytdlpCookie);
    if ($cmd === '') {
        ytl_convert_write_status($videoId, 'error', [
            'error' => 'python',
            'message' => 'Python introuvable',
            'quality' => $height,
            'format' => $fmt,
        ]);
        @file_put_contents($log, "python ou converter.py introuvable\n", FILE_APPEND);
        flock($lock, LOCK_UN);
        fclose($lock);
        return 1;
    }

    $fp = @popen(ytl_convert_shell($cmd, $ytDlpOauth), 'r');
    if ($fp === false) {
        ytl_convert_write_status($videoId, 'error', [
            'error' => 'popen',
            'message' => 'Lancement impossible',
            'quality' => $height,
            'format' => $fmt,
        ]);
        @file_put_contents($log, "popen impossible\n", FILE_APPEND);
        flock($lock, LOCK_UN);
        fclose($lock);
        return 1;
    }
    $lastPct = -1;
    while (!feof($fp)) {
        $line = fgets($fp);
        if ($line === false) {
            break;
        }
        @file_put_contents($log, $line, FILE_APPEND);
        $trim = trim($line);
        $msg = null;
        $pct = null;
        if (preg_match('/\[download\]\s+([\d.]+)%/', $trim, $m)) {
            // Map yt-dlp 0–100 into 20–99 so the bar continues after the fetch phase.
            $raw = (float) $m[1];
            $pct = (int) floor(20 + ($raw * 0.79));
            if ($pct > 99) {
                $pct = 99;
            }
            $msg = 'Downloading… ' . (int) floor($raw) . '%';
        } elseif (preg_match('/\(frag\s+(\d+)\/(\d+)\)/i', $trim, $fm) && (int) $fm[2] > 0) {
            $raw = ((int) $fm[1] / (int) $fm[2]) * 100.0;
            $pct = (int) floor(20 + ($raw * 0.79));
            if ($pct > 99) {
                $pct = 99;
            }
            $msg = 'Downloading…';
        } elseif (stripos($trim, 'extract cpython') !== false || stripos($trim, 'restore deno') !== false || stripos($trim, 'restore ffmpeg') !== false) {
            $msg = 'Preparing engine…';
            $pct = max(16, $lastPct);
        } elseif (stripos($trim, 'Downloading webpage') !== false) {
            $msg = 'Connecting to YouTube…';
            $pct = max(18, $lastPct);
        } elseif (stripos($trim, 'Downloading player') !== false || stripos($trim, 'player API') !== false) {
            $msg = 'Analyzing video…';
            $pct = max(19, $lastPct);
        } elseif (stripos($trim, 'Solving JS') !== false) {
            $msg = 'Checking YouTube…';
            $pct = max(19, $lastPct);
        } elseif (stripos($trim, 'Merging') !== false) {
            $msg = 'Merging MP4…';
            $pct = max(95, $lastPct);
        } elseif (preg_match('/Downloading 1 format/i', $trim)) {
            $msg = 'Downloading…';
            $pct = max(20, $lastPct);
        }
        if ($msg !== null || ($pct !== null && $pct !== $lastPct)) {
            // Never decrease % (yt-dlp restarts at 0% on the audio stream).
            if ($pct !== null && $pct > $lastPct) {
                $lastPct = $pct;
            }
            ytl_convert_write_status($videoId, 'downloading', [
                'percent' => $lastPct < 0 ? 1 : $lastPct,
                'message' => $msg ?? 'Downloading…',
                'quality' => $height,
                'format' => $fmt,
            ]);
        }
        if ($onLine !== null) {
            $onLine($line);
        }
    }
    $code = pclose($fp);
    flock($lock, LOCK_UN);
    fclose($lock);

    if (ytl_convert_is_ready($videoId, $height, $fmt)) {
        $readyPath = ytl_convert_media_path($videoId, $height, $fmt);
        ytl_convert_write_status($videoId, 'ready', [
            'bytes' => (int) filesize($readyPath),
            'backend' => 'converter.py',
            'quality' => $height,
            'format' => $fmt,
            'auth' => ($usingVisitorJar
                ? 'ytdlp_visitor_cookies'
                : ($ytdlpCookie !== '-' ? 'ytdlp_pixr_session' : 'ytdlp_nocookie')),
        ]);
        ytl_convert_purge_other_media($videoId);
        return 0;
    }

    $hint = $fmt === 'mp3' ? 'MP3 download failed' : 'MP4 download failed';
    $failCode = isset($ladderFailCode) ? $ladderFailCode : 'download_failed';
    if (is_readable($log)) {
        $tail = @file_get_contents($log);
        if (is_string($tail) && $tail !== '') {
            if ($usingVisitorJar && ytl_cookie_auth_failure_text($tail)) {
                // Keep the jar on disk — optional power-user paste; convert uses mirrors first.
                ytl_visitor_cookie_mark_invalid('yt-dlp rejected personal cookie jar (jar kept).');
            } elseif (!$visitorSignedIn && ytl_cookie_auth_failure_text($tail)) {
                // Flag health only — never delete the durable Pixr jar.
                ytl_cookie_health_mark_invalid(
                    'yt-dlp cookie probe failed (jar kept; mirrors preferred).',
                    'convert'
                );
                ytl_ytdlp_session_write_status([
                    'ok' => false,
                    'reason' => 'yt-dlp cookie probe failed — jar kept for retry; Cobalt/Piped preferred.',
                    'account' => (string) (ytl_ytdlp_session_status()['account'] ?? ''),
                ]);
            }
            $lines = preg_split("/\r\n|\n|\r/", trim($tail)) ?: [];
            $interesting = [];
            foreach (array_reverse($lines) as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/ERROR:|error:|cookie|Sign in|Forbidden|HTTP Error|Unavailable|Requested format|No video|fichier non|supprime |\.mhtml|step=/i', $line)) {
                    $interesting[] = $line;
                    if (count($interesting) >= 3) {
                        break;
                    }
                }
            }
            if ($interesting !== []) {
                $hint = implode(' · ', array_reverse($interesting));
                if (strlen($hint) > 220) {
                    $hint = substr($hint, 0, 217) . '…';
                }
            }
            $failCode = ytl_convert_classify_error($hint);
            $errPayload = ytl_convert_error_payload($hint, $visitorSignedIn, $failCode);
            $hint = $errPayload['message'];
            $failCode = $errPayload['code'];
        }
    }

    @file_put_contents($log, 'python exit ' . $code . ' fail_code=' . $failCode . "\n", FILE_APPEND);
    // MP4: never terminal-error the UI — browser Cobalt (visitor IP) is the proven path.
    if ($fmt === 'mp4') {
        ytl_convert_handoff_client_cobalt(
            $videoId,
            $height,
            $fmt,
            $usingVisitorJar
                ? 'ytdlp_visitor_cookies'
                : ($ytdlpCookie !== '-' ? 'ytdlp_pixr_session' : 'ytdlp_nocookie'),
            $log,
            $failCode . ':yt_dlp_failed'
        );
        return 0;
    }
    ytl_convert_write_status($videoId, 'error', [
        'error' => $failCode,
        'error_code' => $failCode,
        'code' => $code,
        'quality' => $height,
        'format' => $fmt,
        'message' => $hint,
        'auth' => $usingVisitorJar
            ? 'ytdlp_visitor_cookies'
            : ($ytdlpCookie !== '-' ? 'ytdlp_pixr_session' : 'ytdlp_nocookie'),
    ]);
    return $code === 0 ? 1 : $code;
}

/** @return array{state:string,started?:bool} */
function ytl_convert_ensure(string $videoId, int $height = 360): array
{
    $height = ytl_convert_normalize_quality($height);
    if (ytl_convert_is_ready($videoId, $height)) {
        return ytl_convert_status($videoId, $height);
    }
    ytl_convert_write_status($videoId, 'downloading', ['quality' => $height]);
    return ['state' => 'downloading', 'started' => true, 'quality' => $height];
}

function ytl_convert_media_url(string $videoId, int $height = 360, bool $download = false, string $fmt = 'mp4'): string
{
    $fmt = ytl_convert_normalize_fmt($fmt);
    $params = ['v' => $videoId, 'q' => ytl_convert_normalize_quality($height), 'fmt' => $fmt];
    if ($download) {
        $params['dl'] = '1';
    }
    return 'media.php?' . http_build_query($params);
}

function ytl_convert_play_url(string $videoId, int $height = 360): string
{
    return 'play.php?' . http_build_query([
        'v' => $videoId,
        'q' => ytl_convert_normalize_quality($height),
    ]);
}

/** @return array<string,mixed> */
function ytl_convert_video_stub(string $videoId, ?array $meta = null): array
{
    $title = is_array($meta) ? (string) ($meta['title'] ?? $videoId) : $videoId;
    $author = is_array($meta) ? (string) ($meta['author'] ?? '') : '';
    $authorId = is_array($meta) ? (string) ($meta['authorId'] ?? '') : '';
    $desc = is_array($meta) ? (string) ($meta['description'] ?? '') : '';
    $thumbs = is_array($meta) ? ($meta['videoThumbnails'] ?? []) : [];
    $rec = is_array($meta) ? ($meta['recommendedVideos'] ?? []) : [];
    $height = ytl_convert_normalize_quality((int) ($meta['quality'] ?? 360));
    $path = ytl_convert_mp4_path($videoId, $height);
    $bytes = is_file($path) ? (int) filesize($path) : 0;
    $label = ytl_convert_quality_label($height);

    return [
        'type' => 'video',
        'title' => $title,
        'videoId' => $videoId,
        'videoThumbnails' => is_array($thumbs) ? $thumbs : [],
        'description' => $desc,
        'publishedText' => is_array($meta) ? (string) ($meta['publishedText'] ?? '') : '',
        'viewCount' => (int) ($meta['viewCount'] ?? 0),
        'likeCount' => (int) ($meta['likeCount'] ?? 0),
        'dislikeCount' => (int) ($meta['dislikeCount'] ?? -1),
        'subCount' => (int) ($meta['subCount'] ?? 0),
        'author' => $author,
        'authorId' => $authorId,
        'authorUrl' => '',
        'lengthSeconds' => (int) ($meta['lengthSeconds'] ?? 0),
        'liveNow' => false,
        'formatStreams' => [[
            'url' => ytl_convert_media_url($videoId, $height),
            'itag' => 'local',
            'type' => 'video/mp4',
            'quality' => $label,
            'container' => 'mp4',
            'encoding' => '',
            'qualityLabel' => $label,
            'resolution' => $label,
            'size' => $bytes > 0 ? (string) $bytes : '',
            '_local' => true,
        ]],
        'adaptiveFormats' => [],
        'recommendedVideos' => is_array($rec) ? $rec : [],
        'hlsUrl' => null,
        'dashUrl' => null,
        '_source' => 'converter',
    ];
}
