<?php
/**
 * Sign in — Tube Replacer model under the hood (YouTube-on-TV OAuth).
 * UI: plain Google Sign-in (email / password / 2FA). Never mention YouTube TV.
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/youtube_tv_oauth.php';

ytl_session_start();

// Already Convert-ready → home.
if (ytl_tv_session_ready()) {
    header('Location: ' . ytl_url('index.php', ['cat' => 'recommended', 'signin' => 'ok']));
    exit;
}

// Drop useless Cloud-only session so Convert cannot think we are "signed in".
if (isset($_SESSION['ytl_user'])) {
    $auth = (string) ($_SESSION['ytl_user']['auth'] ?? '');
    $sub = (string) ($_SESSION['ytl_user']['sub'] ?? '');
    if ($auth !== 'youtube_tv' && !str_starts_with($sub, 'tv_')) {
        unset($_SESSION['ytl_user'], $_SESSION['ytl_oauth_state'], $_SESSION['ytl_oauth_mode']);
    }
}

$start = ytl_tv_oauth_start();
$userCode = (string) ($start['user_code'] ?? '');
$verifyUrl = (string) ($start['verification_url'] ?? 'https://www.google.com/device');
$interval = (int) ($start['interval'] ?? 5);
$err = empty($start['ok']) ? (string) ($start['error'] ?? 'Could not start Sign in.') : '';

// Prefill code when Google supports it (same window experience as Tube Replacer).
$openUrl = $verifyUrl;
if ($userCode !== '' && !str_contains($verifyUrl, 'user_code=')) {
    $openUrl .= (str_contains($verifyUrl, '?') ? '&' : '?') . 'user_code=' . rawurlencode($userCode);
}

ytl_layout_start('Sign in', '', 'all', 'videos', false);
?>
<style>
.signin-box { max-width: 480px; margin: 22px auto; padding: 18px 16px; border: 1px solid #ddd; background: #fafafa; }
.signin-box h2 { margin: 0 0 8px; font-size: 20px; }
.signin-box p { margin: 0 0 10px; font-size: 13px; line-height: 1.45; color: #333; }
.signin-code { display:none; font-size: 26px; letter-spacing: 3px; font-weight: bold; font-family: monospace; margin: 10px 0; }
.signin-status { margin-top: 12px; font-size: 13px; color: #444; min-height: 1.4em; }
</style>

<div class="signin-box">
  <h2>Sign in with Google</h2>
  <?php if ($err !== ''): ?>
  <div class="error-box"><?php echo ytl_h($err); ?></div>
  <p><a class="yt-btn" href="tv_signin.php">Try again</a></p>
  <?php else: ?>
  <p>A Google window opens — enter your email, password, and any verification code. Keep this Pixr tab open; it returns here automatically after Google confirms.</p>
  <p><a class="yt-btn" href="<?php echo ytl_h($openUrl); ?>" target="ytl_google_signin" id="signin-open">Continue to Google</a></p>
  <p style="font-size:12px;color:#666;margin-top:8px;">If Google asks for a code, use:</p>
  <div class="signin-code" id="signin-code"><?php echo ytl_h($userCode); ?></div>
  <div class="signin-status" id="signin-status">Opening Google…</div>
  <?php endif; ?>
</div>

<?php if ($err === ''): ?>
<script>
(function () {
  var intervalMs = Math.max(3, <?php echo (int) $interval; ?>) * 1000;
  var statusEl = document.getElementById('signin-status');
  var codeEl = document.getElementById('signin-code');
  var openBtn = document.getElementById('signin-open');
  var openUrl = <?php echo json_encode($openUrl, JSON_UNESCAPED_SLASHES); ?>;
  var done = false;
  var googleWin = null;
  function openGoogle() {
    try {
      // Named window (no noopener) so we can close it when Pixr detects success.
      googleWin = window.open(openUrl, 'ytl_google_signin');
    } catch (e) { googleWin = null; }
    return !!(googleWin && !googleWin.closed);
  }
  var opened = openGoogle();
  if (openBtn) {
    openBtn.onclick = function () {
      openGoogle();
      return true;
    };
  }
  if (!opened && codeEl) {
    codeEl.style.display = 'block';
    statusEl.textContent = 'Popup blocked — click “Continue to Google”, then confirm.';
  } else {
    statusEl.textContent = 'Waiting for Google confirmation…';
    setTimeout(function () {
      if (!done && codeEl) codeEl.style.display = 'block';
    }, 4000);
  }
  function finishOk(name) {
    done = true;
    statusEl.textContent = 'Signed in as ' + (name || 'Google') + ' — returning to Pixr…';
    try {
      if (googleWin && !googleWin.closed) googleWin.close();
    } catch (e) {}
    try { window.focus(); } catch (e2) {}
    window.location.href = 'index.php?cat=recommended&signin=ok';
  }
  function poll() {
    if (done) return;
    fetch('tv_poll.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j) return;
        if (j.ok) {
          finishOk(j.name || 'Google');
          return;
        }
        if (j.pending) {
          statusEl.textContent = 'Waiting for Google confirmation…';
          return;
        }
        if (j.error) {
          done = true;
          statusEl.textContent = 'Error: ' + j.error;
        }
      })
      .catch(function () {
        statusEl.textContent = 'Network hiccup — retrying…';
      });
  }
  setInterval(poll, intervalMs);
  setTimeout(poll, 1200);
})();
</script>
<?php endif; ?>

<?php
ytl_layout_end();
