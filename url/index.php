<?php
/**
 * Paste a YouTube URL → MP4/MP3 download (Cosmic Panda / 2012 UI).
 */
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/convert.php';

$qualities = ytl_convert_qualities();
$locale = ytl_ui_locale();

// Layout assets are relative to /ytlite/ — keep links working from /ytlite/url/.
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html lang="<?php echo ytl_h($locale); ?>">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=4, viewport-fit=cover">
<base href="/ytlite/">
<title>URL - <?php echo ytl_h(ytl_config()['site_name'] ?? 'Pixr Lite'); ?></title>
<link rel="stylesheet" type="text/css" href="assets/style.css?v=42">
<link rel="icon" type="image/png" href="/ytlite/favicon-96x96.png?v=41" sizes="96x96">
<link rel="icon" type="image/svg+xml" href="/ytlite/favicon.svg?v=41">
<link rel="shortcut icon" href="/ytlite/favicon.ico?v=41">
<link rel="apple-touch-icon" sizes="180x180" href="/ytlite/apple-touch-icon.png?v=41">
<style type="text/css">
  .url-panel {
    background: #fff;
    border: 1px solid #ccc;
    padding: 14px 16px 18px;
    margin: 0 0 16px 0;
  }
  .url-panel h1 {
    font-size: 18px;
    margin: 0 0 6px 0;
    color: #222;
  }
  .url-panel .sub {
    color: #666;
    font-size: 12px;
    margin: 0 0 14px 0;
  }
  .url-panel label {
    display: block;
    font-size: 11px;
    font-weight: bold;
    color: #333;
    margin: 10px 0 3px 0;
  }
  .url-panel .yt-field {
    width: 98%;
    max-width: 520px;
    font: 13px/1.3 Arial, Helvetica, sans-serif;
    padding: 5px 6px;
    border: 1px solid #a0a0a0;
    background: #fff;
  }
  .url-panel select.yt-field {
    width: auto;
    min-width: 120px;
  }
  .url-row {
    margin-top: 4px;
  }
  .url-row td {
    padding-right: 16px;
    vertical-align: top;
  }
  .url-actions {
    margin-top: 14px;
  }
  /* Classic YouTube 2012 primary red (Upload-era) */
  button.yt-btn-convert {
    font: bold 13px/1.2 Arial, Helvetica, sans-serif;
    color: #fff !important;
    text-shadow: 0 1px 0 #7a0f12;
    padding: 7px 18px;
    border: 1px solid #8c1014;
    border-bottom-color: #6e0c0f;
    cursor: pointer;
    background: #cc181e;
    background: -webkit-linear-gradient(top, #e52d27 0%, #b31217 100%);
    background: -moz-linear-gradient(top, #e52d27 0%, #b31217 100%);
    background: linear-gradient(to bottom, #e52d27 0%, #b31217 100%);
    -webkit-border-radius: 3px;
    -moz-border-radius: 3px;
    border-radius: 3px;
  }
  button.yt-btn-convert:hover {
    background: #dd1f24;
    background: -webkit-linear-gradient(top, #f03530 0%, #c4161b 100%);
    background: linear-gradient(to bottom, #f03530 0%, #c4161b 100%);
  }
  button.yt-btn-convert:disabled {
    opacity: .55;
    cursor: default;
  }
  .url-status {
    margin-top: 12px;
    font-size: 12px;
    color: #444;
    min-height: 1.3em;
  }
  .url-bar {
    height: 10px;
    background: #e8e8e8;
    border: 1px solid #ccc;
    margin-top: 8px;
    display: none;
    max-width: 420px;
  }
  .url-bar span {
    display: block;
    height: 100%;
    width: 0;
    background: #167ac6;
    background: -webkit-linear-gradient(top, #3ea6ff, #167ac6);
    background: linear-gradient(to bottom, #3ea6ff, #167ac6);
  }
  .url-err {
    color: #c00;
    font-size: 12px;
    margin-top: 8px;
  }
  .clip-hint {
    display: none;
    margin: 8px 0 0;
    padding: 6px 8px;
    border: 1px solid #c5d7ef;
    background: #f0f6fc;
    font-size: 12px;
    color: #234;
    max-width: 520px;
  }
  .clip-hint .clip-url { color: #555; word-break: break-all; }
  .clip-hint .yt-btn { margin-left: 6px; }
</style>
</head>
<body>
<div id="body-container">
<div id="page">

  <div id="masthead">
    <table class="masthead-table" width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        <td class="logo-cell" valign="middle">
          <a href="index.php"><img src="assets/YTLite-Logo.png?v=39" alt="<?php echo ytl_h(ytl_config()['site_name'] ?? 'Pixr Lite'); ?>" width="80" height="32" border="0"></a>
        </td>
        <td class="search-cell" valign="middle">
          <form action="index.php" method="get" class="search-form" autocomplete="on">
            <input type="text" name="ytlite_q" value="" class="search-input"
              autocomplete="ytlite-search" placeholder="Search" title="Search">
            <button type="submit" class="search-btn" title="Search">Search</button>
          </form>
        </td>
        <td class="masthead-links" valign="middle">
          <a class="lite-btn" href="lite/index.php">Lite</a>
          <a class="lite-btn masthead-extra" href="url/">URL</a>
        </td>
        <td class="signin-cell" align="right" valign="middle">
          <a class="lite-btn" href="index.php">Home</a>
        </td>
      </tr>
    </table>
  </div>

  <div id="guide-bar">
    <ul class="guide-tabs">
      <li><a href="index.php">Home</a></li>
      <li class="active"><a href="url/">URL</a></li>
    </ul>
  </div>

  <table id="content-layout" width="100%" cellpadding="0" cellspacing="0" border="0">
  <tr>
    <td id="content" valign="top">

      <div class="url-panel">
        <h1>Convert a YouTube URL</h1>
        <p class="sub">Paste a link, pick format / quality, then download the file.</p>

        <form id="f" action="#" method="get" autocomplete="off">
          <label for="url">YouTube URL</label>
          <input class="yt-field" type="text" id="url" name="ytlite_yt_url" inputmode="url"
            placeholder="https://www.youtube.com/watch?v=…" required
            autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
            data-lpignore="true" data-1p-ignore="true" data-form-type="other" role="textbox">
          <div class="clip-hint" id="clip-hint">
            YouTube link in clipboard:
            <span class="clip-url" id="clip-url"></span>
            <button type="button" class="yt-btn" id="clip-paste">Paste</button>
          </div>

          <table class="url-row" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td>
                <label for="fmt">Format</label>
                <select class="yt-field" id="fmt" name="fmt" autocomplete="off">
                  <option value="mp4" selected>MP4</option>
                  <option value="mp3">MP3</option>
                </select>
              </td>
              <td id="q-wrap">
                <label for="q">Quality</label>
                <select class="yt-field" id="q" name="q" autocomplete="off">
                  <?php foreach ($qualities as $q): ?>
                    <option value="<?php echo (int) $q; ?>"<?php echo $q === 360 ? ' selected="selected"' : ''; ?>>
                      <?php echo ytl_h(ytl_convert_quality_label($q)); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          </table>

          <div class="url-actions">
            <button type="submit" class="yt-btn yt-btn-convert" id="go">Convert</button>
          </div>
        </form>

        <div class="url-status" id="msg"></div>
        <div class="url-bar" id="bar"><span id="fill"></span></div>
        <div class="url-err" id="err"></div>
      </div>

    </td>
  </tr>
  </table>

  <div id="footer">
    <div class="footer-links">
      <a href="index.php">Home</a> ·
      <a href="url/">URL</a> ·
      <a href="/privacy/">Privacy</a>
    </div>
  </div>

</div>
</div>

<script type="text/javascript">
(function () {
  var form = document.getElementById('f');
  var go = document.getElementById('go');
  var msg = document.getElementById('msg');
  var err = document.getElementById('err');
  var bar = document.getElementById('bar');
  var fill = document.getElementById('fill');
  var fmtEl = document.getElementById('fmt');
  var qWrap = document.getElementById('q-wrap');
  var urlEl = document.getElementById('url');
  var clipHint = document.getElementById('clip-hint');
  var clipUrl = document.getElementById('clip-url');
  var clipPaste = document.getElementById('clip-paste');
  var clipValue = '';
  var done = false;
  var soft = 0;
  var softT = null;
  var lastPct = 0;

  function parseId(raw) {
    raw = (raw || '').trim();
    if (/^[A-Za-z0-9_-]{6,20}$/.test(raw)) return raw;
    var m = raw.match(/(?:v=|\/shorts\/|\/embed\/|youtu\.be\/)([A-Za-z0-9_-]{6,20})/);
    return m ? m[1] : '';
  }

  function isYoutubeUrl(raw) {
    raw = (raw || '').trim();
    if (!raw) return false;
    if (/^[A-Za-z0-9_-]{6,20}$/.test(raw)) return true;
    return /(?:youtube\.com|youtu\.be|youtube-nocookie\.com)/i.test(raw) && !!parseId(raw);
  }

  function showClip(text) {
    text = (text || '').trim();
    if (!isYoutubeUrl(text) || (urlEl.value || '').trim() !== '') {
      clipHint.style.display = 'none';
      clipValue = '';
      return;
    }
    clipValue = text;
    clipUrl.textContent = text.length > 72 ? (text.slice(0, 69) + '\u2026') : text;
    clipHint.style.display = 'block';
  }

  function checkClipboard() {
    if (!navigator.clipboard || !navigator.clipboard.readText) return;
    navigator.clipboard.readText().then(function (text) {
      showClip(text);
    }).catch(function () {});
  }

  clipPaste.addEventListener('click', function () {
    if (!clipValue) return;
    urlEl.value = clipValue;
    clipHint.style.display = 'none';
    urlEl.focus();
  });

  urlEl.addEventListener('focus', checkClipboard);
  urlEl.addEventListener('click', checkClipboard);
  urlEl.addEventListener('input', function () {
    if ((urlEl.value || '').trim() !== '') clipHint.style.display = 'none';
  });
  setTimeout(checkClipboard, 200);

  function setProg(p, text) {
    if (p < 0) p = 0;
    if (p > 100) p = 100;
    bar.style.display = 'block';
    fill.style.width = Math.floor(p) + '%';
    if (text) msg.textContent = text;
  }

  function toggleQ() {
    qWrap.style.display = fmtEl.value === 'mp3' ? 'none' : '';
  }
  fmtEl.addEventListener('change', toggleQ);
  toggleQ();

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    err.textContent = '';
    done = false;
    lastPct = 0;
    soft = 0;
    if (softT) clearInterval(softT);

    var id = parseId(urlEl.value);
    if (!id) {
      err.textContent = 'Invalid YouTube URL.';
      return;
    }
    var fmt = fmtEl.value === 'mp3' ? 'mp3' : 'mp4';
    var q = parseInt(document.getElementById('q').value, 10) || 360;
    // Absolute /ytlite/ paths — <base> would otherwise break these.
    var runUrl = '/ytlite/convert_run.php?v=' + encodeURIComponent(id) + '&q=' + q + '&fmt=' + fmt + '&run=1';
    var statusUrl = '/ytlite/convert_run.php?v=' + encodeURIComponent(id) + '&q=' + q + '&fmt=' + fmt + '&status=1';
    var dlUrl = '/ytlite/media.php?v=' + encodeURIComponent(id) + '&q=' + q + '&fmt=' + fmt + '&dl=1';

    go.disabled = true;
    setProg(1, 'Converting\u2026');
    softT = setInterval(function () {
      if (done) return;
      if (lastPct >= 20) return;
      var cap = lastPct > 0 ? Math.min(19, lastPct + 8) : 18;
      soft += soft < 12 ? 0.45 : 0.18;
      if (soft > cap) soft = cap;
      setProg(Math.max(soft, lastPct), 'Converting\u2026');
    }, 700);

    try {
      if (window.fetch) fetch(runUrl, { cache: 'no-store', credentials: 'same-origin' }).catch(function () {});
      else { var x0 = new XMLHttpRequest(); x0.open('GET', runUrl, true); x0.send(null); }
    } catch (ex) {}

    function poll() {
      if (done) return;
      var x = new XMLHttpRequest();
      x.open('GET', statusUrl + '&_=' + Date.now(), true);
      x.onreadystatechange = function () {
        if (x.readyState !== 4) return;
        if (x.status !== 200) { setTimeout(poll, 600); return; }
        var data;
        try { data = JSON.parse(x.responseText); } catch (ex) { setTimeout(poll, 600); return; }
        var pct = data.percent || 0;
        if (pct > lastPct) lastPct = pct;
        if (data.message) msg.textContent = data.message;
        if (lastPct >= 20) {
          if (softT) { clearInterval(softT); softT = null; }
          setProg(lastPct, data.message || 'Converting\u2026');
        } else if (lastPct > 0) {
          setProg(Math.max(soft, lastPct), data.message || 'Converting\u2026');
        }
        if (data.ready) {
          done = true;
          if (softT) clearInterval(softT);
          setProg(100, 'Ready \u2014 downloading\u2026');
          go.disabled = false;
          window.location = dlUrl;
          return;
        }
        if (data.state === 'error') {
          done = true;
          if (softT) clearInterval(softT);
          err.textContent = data.message || data.error || 'Failed';
          go.disabled = false;
          return;
        }
        setTimeout(poll, 400);
      };
      x.send(null);
    }
    setTimeout(poll, 200);
  });
})();
</script>
</body>
</html>
