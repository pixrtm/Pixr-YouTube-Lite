<?php
/**
 * Admin — Google OAuth feeds (service) + session download yt-dlp Pixr (always-on).
 *
 * Note: yt-dlp removed YouTube OAuth (2024.11). Durable download auth = Netscape
 * jar exported once via robots.txt into data/ytdlp_oauth/cookies.txt.
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/cookie_auth.php';
require_once __DIR__ . '/lib/oauth.php';
require_once __DIR__ . '/lib/ytdlp_session.php';

$cfg = ytl_config();
$key = (string) ($cfg['setup_key'] ?? '');
$provided = isset($_GET['key']) ? (string) $_GET['key'] : (isset($_POST['key']) ? (string) $_POST['key'] : '');
$cookieFile = (string) ($cfg['youtube_cookie_file'] ?? (__DIR__ . '/data/youtube.cookies.txt'));
$msg = '';
$err = '';
$acctLabel = ['handle' => '', 'name' => '', 'email' => ''];
$acctCheck = ['ok' => false, 'reason' => ''];
$health = ytl_cookie_health_read();
$serviceStatus = ytl_service_oauth_status();
$ytdlpStatus = ytl_ytdlp_session_status();

$authed = $key !== '' && $key !== 'ytlite-change-me' && hash_equals($key, $provided);

$serviceFlash = isset($_GET['service']) ? trim((string) $_GET['service']) : '';
if ($serviceFlash === 'ok') {
    $msg = 'Compte Google serveur (feeds) connecté. Recommended utilise ce compte en invisible — Sign in public reste pour les visiteurs.';
    $serviceStatus = ytl_service_oauth_status();
} elseif ($serviceFlash === 'error') {
    $err = 'Échec OAuth serveur : ' . trim((string) ($_GET['msg'] ?? 'inconnu'));
}

if ($key === '' || $key === 'ytlite-change-me') {
    $err = 'Définir setup_key dans config.php.';
} elseif (!$authed) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $err = 'Clé d’administration incorrecte.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'unlink_service') {
        ytl_service_oauth_unlink();
        $serviceStatus = ytl_service_oauth_status();
        $msg = 'Google serveur (feeds) délié.';
    } elseif ($action === 'unlink_ytdlp') {
        ytl_ytdlp_session_unlink();
        $ytdlpStatus = ytl_ytdlp_session_status();
        $msg = 'Session yt-dlp Pixr déliée. Reconnecte-la pour le convert invité.';
    } elseif ($action === 'clear') {
        if (is_file($cookieFile)) {
            @unlink($cookieFile);
        }
        ytl_ytdlp_session_unlink();
        $cacheDir = rtrim((string) ($cfg['video_cache_dir'] ?? (__DIR__ . '/data/videos')), '/\\') . '/.cache';
        foreach (['feed_rec_home.json', 'acct_label.json', 'feed_rec_service.json'] as $name) {
            $p = $cacheDir . '/' . $name;
            if (is_file($p)) {
                @unlink($p);
            }
        }
        ytl_cookie_health_write([
            'ok' => false,
            'state' => 'missing',
            'checked_at' => time(),
            'reason' => 'Cookie / session effacés manuellement.',
            'source' => 'admin',
            'alert' => true,
        ]);
        $msg = 'Cookie + session yt-dlp effacés.';
        $health = ytl_cookie_health_read();
        $ytdlpStatus = ytl_ytdlp_session_status();
    } elseif ($action === 'probe') {
        $health = ytl_cookie_health_probe('admin');
        if (ytl_ytdlp_session_usable()) {
            $res = ytl_ytdlp_session_save((string) file_get_contents(ytl_ytdlp_session_cookie_path()));
            $ytdlpStatus = ytl_ytdlp_session_status();
            if (!empty($res['ok'])) {
                $msg = (string) $res['reason'];
            } else {
                $err = (string) ($res['reason'] !== '' ? $res['reason'] : 'Session yt-dlp invalide.');
            }
        } elseif (!empty($health['ok'])) {
            $msg = (string) $health['reason'];
        } else {
            $err = (string) ($health['reason'] !== '' ? $health['reason'] : 'Cookie invalide.');
        }
    } else {
        // Default: save as durable yt-dlp session (always-on convert engine).
        $cookie = trim((string) ($_POST['cookie'] ?? ''));
        if ($cookie === '') {
            $err = 'Cookie vide.';
        } elseif (!str_contains($cookie, '=') && !str_contains($cookie, "\t")) {
            $err = 'Format invalide (Netscape ou en-tête Cookie).';
        } else {
            $res = ytl_ytdlp_session_save($cookie);
            $ytdlpStatus = ytl_ytdlp_session_status();
            $health = ytl_cookie_health_read();
            if (!empty($res['ok'])) {
                $msg = (string) $res['reason'];
            } else {
                $err = (string) ($res['reason'] !== '' ? $res['reason'] : 'Échec enregistrement session.');
            }
        }
    }
}

$hasFile = is_readable($cookieFile) && @filesize($cookieFile) > 10;
$currentPreview = '';
$feedProbe = ['ok' => false, 'logged_in' => false, 'history_off' => false, 'videos' => 0, 'reason' => ''];
if ($hasFile) {
    $raw = (string) file_get_contents($cookieFile);
    $currentPreview = substr($raw, 0, 36) . '… (' . strlen($raw) . ' caractères)';
    if ($authed && ($acctLabel['handle'] ?? '') === '' && ($acctLabel['name'] ?? '') === '') {
        $acctLabel = ytl_client()->cookieAccountLabel();
        $acctCheck = ytl_youtube_cookie_account_ok($acctLabel);
    }
    if ($authed && $hasFile) {
        $feedProbe = ytl_client()->cookieHomeProbe(8);
        if (($health['checked_at'] ?? 0) < time() - 300 || ($health['state'] ?? '') === 'unknown') {
            $health = ytl_cookie_health_probe('admin_view');
        }
    }
}
$missingNow = $hasFile ? ytl_youtube_cookie_missing() : ['(aucun cookie)'];
$steps = ytl_cookie_durable_export_steps();
$connectUrl = 'oauth_start.php?service=1&key=' . rawurlencode($provided !== '' ? $provided : $key);

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>YouTube Lite — auth serveur</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div id="body-container"><div id="page">
  <div id="masthead"><strong>YouTube Lite — auth serveur</strong></div>
  <div id="content" style="padding:16px;">
    <div class="fallback-note" style="border-color:#8c8;background:#efe;">
      <strong>Toujours connecté (invisible)</strong><br>
      1) <strong>Session yt-dlp Pixr</strong> = convert invité (jar auto via <code>cookie_sync.php</code>).<br>
      2) <strong>Google OAuth serveur</strong> = Recommended / feeds (pas le téléchargeur).<br>
      3) Visiteur Sign in (pixryoutubelite) → jar partagé / OAuth service — <em>pas</em> d’UI Account.
    </div>

    <?php if ($msg): ?><div class="fallback-note" style="border-color:#8c8;background:#efe;"><?php echo ytl_h($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="error-box"><?php echo ytl_h($err); ?></div><?php endif; ?>

    <?php if (!$authed): ?>
    <form method="get" action="cookies.php">
      <p>
        Clé d’administration :<br>
        <input type="password" name="key" size="40" value="">
      </p>
      <p><button type="submit">Ouvrir</button></p>
    </form>
    <?php else: ?>

    <h3 style="margin-top:8px;">1. Session download yt-dlp (Pixr) — convert always-on</h3>
    <div class="fallback-note">
      Compte : <code>pixryoutubelite@gmail.com</code>.
      Méthode : export Netscape via robots.txt (yt-dlp n’accepte plus OAuth YouTube).
      Stockage : <code>data/ytdlp_oauth/cookies.txt</code> — jamais rouvir YouTube avec cette session.
    </div>
    <?php if (!empty($ytdlpStatus['linked'])): ?>
    <div class="fallback-note" style="border-color:<?php echo !empty($ytdlpStatus['ok']) ? '#8c8' : '#c80'; ?>;background:<?php echo !empty($ytdlpStatus['ok']) ? '#efe' : '#fec'; ?>;">
      Session : <strong><?php echo !empty($ytdlpStatus['ok']) ? 'OK' : 'présente / à vérifier'; ?></strong>
      <?php if (($ytdlpStatus['account'] ?? '') !== ''): ?> — <?php echo ytl_h((string) $ytdlpStatus['account']); ?><?php endif; ?>
      <br><?php echo ytl_h((string) ($ytdlpStatus['reason'] ?? '')); ?>
      <?php if (!empty($ytdlpStatus['updated_at'])): ?>
      <br><small>Maj <?php echo ytl_h(gmdate('Y-m-d H:i:s', (int) $ytdlpStatus['updated_at'])); ?> UTC · <?php echo (int) ($ytdlpStatus['bytes'] ?? 0); ?> octets</small>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="error-box">Session yt-dlp : <strong>non liée</strong> — le convert invité tombera sur Piped/Cobalt puis bot wall.</div>
    <?php endif; ?>

    <ol style="margin:8px 0 12px 18px;padding:0;">
      <?php foreach ($steps as $step): ?>
      <li style="margin-bottom:4px;"><?php echo ytl_h($step); ?></li>
      <?php endforeach; ?>
    </ol>
    <form method="post" action="cookies.php">
      <input type="hidden" name="key" value="<?php echo ytl_h($provided); ?>">
      <input type="hidden" name="action" value="save">
      <p>
        Coller le Netscape / Cookie header Pixr YouTube Lite :<br>
        <textarea name="cookie" rows="10" cols="80" style="width:95%;font-family:monospace;font-size:11px;" placeholder="# Netscape HTTP Cookie File …"></textarea>
      </p>
      <p><button type="submit">Enregistrer la session yt-dlp (always-on)</button></p>
    </form>
    <form method="post" action="cookies.php" style="display:inline-block;margin-right:12px;">
      <input type="hidden" name="key" value="<?php echo ytl_h($provided); ?>">
      <input type="hidden" name="action" value="probe">
      <button type="submit">Revérifier session</button>
    </form>
    <?php if (!empty($ytdlpStatus['linked'])): ?>
    <form method="post" action="cookies.php" style="display:inline-block;" onsubmit="return confirm('Délier la session yt-dlp ?');">
      <input type="hidden" name="key" value="<?php echo ytl_h($provided); ?>">
      <input type="hidden" name="action" value="unlink_ytdlp">
      <button type="submit">Délier session yt-dlp</button>
    </form>
    <?php endif; ?>

    <hr style="margin:20px 0;border:0;border-top:1px solid #ccc;">

    <h3>2. Google OAuth serveur — Recommended / feeds seulement</h3>
    <?php if (!empty($serviceStatus['linked'])): ?>
    <div class="fallback-note" style="border-color:#8c8;background:#efe;">
      Lié : <strong>oui</strong>
      <?php if (($serviceStatus['email'] ?? '') !== ''): ?>
        — <?php echo ytl_h((string) $serviceStatus['email']); ?>
      <?php endif; ?>
      <?php if (!empty($serviceStatus['has_refresh'])): ?> · refresh OK<?php else: ?> · <strong>sans refresh</strong><?php endif; ?>
    </div>
    <?php else: ?>
    <div class="fallback-note">Lié : <strong>non</strong> — Recommended invité n’a pas encore de Google serveur.</div>
    <?php endif; ?>
    <p>
      <a class="lite-btn" href="<?php echo ytl_h($connectUrl); ?>">Connecter le Google serveur (feeds)</a>
      <span style="font-size:12px;color:#555;"> — choisir <code>pixryoutubelite@gmail.com</code></span>
    </p>
    <?php if (!empty($serviceStatus['linked'])): ?>
    <form method="post" action="cookies.php" style="margin:8px 0 16px;" onsubmit="return confirm('Délier le Google serveur feeds ?');">
      <input type="hidden" name="key" value="<?php echo ytl_h($provided); ?>">
      <input type="hidden" name="action" value="unlink_service">
      <button type="submit">Délier Google feeds</button>
    </form>
    <?php endif; ?>

    <hr style="margin:20px 0;border:0;border-top:1px solid #ccc;">

    <h3>3. État cookie partagé (miroir)</h3>
    <?php if (!empty($health['alert']) || (($health['state'] ?? '') !== '' && ($health['state'] ?? '') !== 'ok' && ($health['state'] ?? '') !== 'unknown')): ?>
    <div class="error-box">
      Santé : <code><?php echo ytl_h((string) ($health['state'] ?? 'invalid')); ?></code>
      — <?php echo ytl_h((string) ($health['reason'] ?? '')); ?>
    </div>
    <?php elseif (!empty($health['ok'])): ?>
    <div class="fallback-note" style="border-color:#8c8;background:#efe;">Santé cookie partagé : OK</div>
    <?php endif; ?>
    <p>Fichier : <?php echo $hasFile ? ('présent — ' . ytl_h($currentPreview)) : 'absent'; ?>
      <?php if ($hasFile && count($missingNow) > 0): ?> · manque <code><?php echo ytl_h(implode(', ', $missingNow)); ?></code><?php endif; ?>
    </p>
    <?php if ($hasFile && !empty($feedProbe['videos'])): ?>
    <p>Probe Accueil cookie : <?php echo (int) $feedProbe['videos']; ?> vidéos</p>
    <?php endif; ?>
    <form method="post" action="cookies.php" style="margin-top:8px;">
      <input type="hidden" name="key" value="<?php echo ytl_h($provided); ?>">
      <input type="hidden" name="action" value="clear">
      <button type="submit">Effacer cookie + session</button>
    </form>
    <p style="margin-top:16px;font-size:12px;color:#555;">
      Probe convert :<br>
      <code>https://pixr.dev/ytlite/convert_probe.php?key=<?php echo ytl_h($provided); ?>&amp;v=_x58r9TBois</code>
    </p>
    <?php endif; ?>

    <p><a href="index.php">Accueil</a> · <a href="index.php?cat=recommended">Recommended</a> · <a href="test.php">Test</a></p>
  </div>
</div></div>
</body>
</html>
