<?php

require_once __DIR__ . '/layout.php';
require_once dirname(__DIR__) . '/lib/convert.php';

$videoId = isset($_GET['v']) ? trim((string) $_GET['v']) : '';
$quality = 360;

if ($videoId === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
    ytl_lite_start('Watch');
    echo '<p>Missing video.</p>';
    ytl_lite_end();
    exit;
}

$ready = ytl_convert_is_ready($videoId, $quality);

if (!$ready) {
    // Paths relative to /ytlite/lite/ → parent app root.
    $runUrl = '../convert_run.php?' . http_build_query(['v' => $videoId, 'q' => $quality, 'run' => '1']);
    $statusUrl = '../convert_run.php?' . http_build_query(['v' => $videoId, 'q' => $quality, 'status' => '1']);
    $watchUrl = 'watch.php?' . http_build_query(['v' => $videoId]);
    $putUrl = '../convert_client_put.php?' . http_build_query(['v' => $videoId, 'q' => $quality]);
    $noscript = '../convert_run.php?' . http_build_query(['v' => $videoId, 'q' => $quality, 'noscript' => '1', 'ui' => 'lite']);
    $retry = isset($_GET['retry']) && (string) $_GET['retry'] !== '';
    $serverSettings = ytl_settings_get();
    $cobaltApis = [];
    foreach (array_merge(
        (array) ($serverSettings['convert'] ?? []),
        (array) (ytl_config()['cobalt_apis'] ?? [])
    ) as $api) {
        $api = rtrim(trim((string) $api), '/');
        if ($api !== '' && str_starts_with($api, 'https://')) {
            $cobaltApis[] = $api;
        }
    }
    $cobaltApis = array_values(array_unique($cobaltApis));

    ytl_lite_start('Loading');
    ?>
<p><a href="index.php">&laquo; Back</a></p>
<p><b>Loading…</b></p>
<p class="meta" id="msg">Please wait.</p>
<p class="meta" id="err" style="display:none;color:#900;"></p>
<noscript>
  <p><a href="<?php echo ytl_h($noscript); ?>">Continue without JavaScript</a></p>
</noscript>
<script type="text/javascript">
(function () {
  var runUrl = <?php echo json_encode($runUrl); ?>;
  var statusUrl = <?php echo json_encode($statusUrl); ?>;
  var watchUrl = <?php echo json_encode($watchUrl); ?>;
  var putUrl = <?php echo json_encode($putUrl); ?>;
  var videoId = <?php echo json_encode($videoId); ?>;
  var cobaltApis = <?php echo json_encode($cobaltApis, JSON_UNESCAPED_SLASHES); ?>;
  if (window.YtlSettings) {
    var _s = YtlSettings.get();
    if (_s.convert && _s.convert.length) {
      cobaltApis = YtlSettings.mergeInto(cobaltApis, _s.convert);
    }
  }
  var forceRun = <?php echo $retry ? 'true' : 'false'; ?>;
  var msg = document.getElementById('msg');
  var err = document.getElementById('err');
  var done = false;
  var started = false;
  var fails = 0;
  var clientTried = false;

  function showErr(text) {
    done = true;
    if (msg) msg.innerHTML = 'Failed.';
    if (err) {
      err.style.display = 'block';
      err.innerHTML = (text || 'Error') + ' <a href="' + watchUrl + '&retry=1">Retry</a>';
    }
  }

  function startDownload() {
    if (started || done) return;
    started = true;
    // Prefer visitor-IP Cobalt from Settings (same as Classic), then server convert_run.
    if (!clientTried && window.fetch && cobaltApis && cobaltApis.length) {
      clientTried = true;
      if (msg) msg.innerHTML = 'Downloading via convert server…';
      tryClientCobalt(function (ok) {
        if (ok) {
          done = true;
          if (msg) msg.innerHTML = 'Ready.';
          location.replace(watchUrl);
          return;
        }
        if (msg) msg.innerHTML = 'Trying server convert…';
        kickServer();
      });
      return;
    }
    kickServer();
  }

  function kickServer() {
    try {
      if (window.fetch) fetch(runUrl, { cache: 'no-store', credentials: 'same-origin' }).catch(function () {});
      else { var x0 = new XMLHttpRequest(); x0.open('GET', runUrl, true); x0.send(null); }
    } catch (e) {}
  }

  function tryClientCobalt(onDone) {
    var i = 0;
    function next() {
      if (i >= cobaltApis.length) { onDone(false); return; }
      var api = String(cobaltApis[i++] || '').replace(/\/$/, '');
      if (!api) { next(); return; }
      fetch(api, {
        method: 'POST',
        mode: 'cors',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'Origin': location.origin || 'https://pixr.dev'
        },
        body: JSON.stringify({
          url: 'https://www.youtube.com/watch?v=' + videoId,
          downloadMode: 'auto',
          videoQuality: '360',
          filenameStyle: 'basic'
        })
      }).then(function (r) { return r.json().then(function (j) { return { r: r, j: j }; }); })
        .then(function (pack) {
          var j = pack.j || {};
          var st = String(j.status || '');
          var url = String(j.url || j.tunnel || '');
          if (!((st === 'tunnel' || st === 'redirect' || st === 'local-processing') && url.indexOf('https://') === 0)) {
            throw new Error('no_tunnel');
          }
          return fetch(url, { mode: 'cors', cache: 'no-store' }).then(function (tr) {
            if (!tr.ok) throw new Error('tunnel');
            return tr.blob();
          }).then(function (blob) {
            if (!blob || blob.size < 12000) throw new Error('empty');
            return fetch(putUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/octet-stream' },
              body: blob
            });
          }).then(function (pr) {
            if (!pr.ok) throw new Error('put');
            onDone(true);
          });
        })
        .catch(function () { next(); });
    }
    next();
  }

  function poll() {
    if (done) return;
    var x = new XMLHttpRequest();
    x.open('GET', statusUrl + '&_=' + (new Date().getTime()), true);
    x.onreadystatechange = function () {
      if (x.readyState !== 4) return;
      if (done) return;
      if (x.status < 200 || x.status >= 300) {
        fails++;
        if (fails > 40) { showErr('Could not reach converter.'); return; }
        setTimeout(poll, 800);
        return;
      }
      fails = 0;
      var data = null;
      try { data = JSON.parse(x.responseText); } catch (e) { data = null; }
      if (!data) { setTimeout(poll, 800); return; }

      if (data.ready) {
        done = true;
        if (msg) msg.innerHTML = 'Ready.';
        location.replace(watchUrl);
        return;
      }

      var state = String(data.state || '');
      var message = String(data.message || '');
      var errText = String(data.error || '');
      var isFail = (state === 'error' || errText !== '' || message.indexOf('ERROR:') === 0);

      // Fresh open after a previous failure → show error (Retry link sets retry=1).
      function cleanErr(t) {
        t = String(t || '');
        if (/yt_live_broadcast|keepalive request failed|\[hls @/i.test(t)) {
          return "Live streams aren't supported — open a regular (non-live) video.";
        }
        t = t.replace(/https?:\/\/\S+/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
        if (t.length > 160) t = t.substring(0, 157) + '…';
        return t || 'Download failed.';
      }

      if (isFail && !forceRun && !started) {
        showErr(cleanErr(message || errText));
        return;
      }
      // Convert we started has failed.
      if (isFail && started) {
        showErr(cleanErr(message || errText));
        return;
      }

      if (message && msg) msg.innerHTML = message;

      if (!started) {
        if (forceRun || state === 'idle' || state === '') {
          startDownload();
        }
        // else downloading/running: wait for the job already in progress
      }

      setTimeout(poll, 500);
    };
    try { x.send(null); } catch (e) { setTimeout(poll, 800); }
  }

  setTimeout(poll, 150);
})();
</script>
    <?php
    ytl_lite_end();
    exit;
}

// ——— Ready: play local MP4 (keep meta light so the page stays instant) ———
$title = $videoId;
$author = '';
$poster = '../' . ytl_proxy_url('https://i.ytimg.com/vi/' . rawurlencode($videoId) . '/mqdefault.jpg', 'image');
try {
    require_once dirname(__DIR__) . '/lib/oembed.php';
    $oembed = new OEmbedClient(ytl_config());
    $meta = $oembed->video($videoId);
    if (is_array($meta)) {
        if (trim((string) ($meta['title'] ?? '')) !== '') {
            $title = (string) $meta['title'];
        }
        $author = (string) ($meta['author'] ?? '');
    }
} catch (Throwable $e) {
    // Keep playback even if metadata backends fail.
}

$play = '../media.php?' . http_build_query(['v' => $videoId, 'q' => $quality]);
$dl = '../media.php?' . http_build_query(['v' => $videoId, 'q' => $quality, 'dl' => '1']);
/* Open = MP4 inline → lecteur intégré (BlackBerry / navigateur), pas attachment. */
$open = $play;

ytl_lite_start($title);
?>
<p><a href="index.php">&laquo; Back</a></p>
<video width="320" height="180" controls="controls" preload="none" poster="<?php echo ytl_h($poster); ?>">
  <source src="<?php echo ytl_h($play); ?>" type="video/mp4">
</video>
<p><b><?php echo ytl_h($title); ?></b></p>
<?php if ($author !== ''): ?>
  <p class="meta"><?php echo ytl_h($author); ?></p>
<?php endif; ?>
<p>
  <a href="<?php echo ytl_h($dl); ?>">Download</a>
  &nbsp;|&nbsp;
  <a href="<?php echo ytl_h($open); ?>">Open</a>
</p>
<?php
ytl_lite_end();
