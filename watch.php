<?php
/* ytl-deploy:2026-11-09T13:30Z-desc-embed-fallback */
/**
 * Clic accueil → TOUJOURS page légère + barre (comme test.php).
 * Lecteur lourd seulement avec ?play=1 une fois le MP4 prêt (100%).
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/convert.php';

$videoId = isset($_GET['v']) ? trim((string) $_GET['v']) : '';
$quality = ytl_convert_normalize_quality(isset($_GET['q']) ? (int) $_GET['q'] : 360);
$qualityLabel = ytl_convert_quality_label($quality);
$playMode = isset($_GET['play']) && (string) $_GET['play'] !== '' && (string) $_GET['play'] !== '0';

if ($videoId === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
    ytl_layout_start('Watch', '', 'all', 'videos', false);
    echo '<div class="error-box">Missing or invalid video id.</div>';
    ytl_layout_end();
    exit;
}

$extMode = isset($_GET['ext']) && (string) $_GET['ext'] === '1';
$embedMode = isset($_GET['embed']) && (string) $_GET['embed'] === '1';

// Fallback: play via YouTube embed when Cobalt/Invidious mirrors cannot deliver bytes.
if ($embedMode) {
    $locale = ytl_ui_locale();
    $metaUrl = ytl_url('watch_meta.php', ['v' => $videoId, 'part' => 'meta']);
    $commentsUrl = ytl_url('watch_meta.php', ['v' => $videoId, 'part' => 'comments']);
    $retryUrl = ytl_url('watch.php', ['v' => $videoId, 'q' => $quality]);
    header('Cache-Control: no-store');
    ytl_layout_start('Watch', '', 'all', 'videos', false);
    ?>
<div class="watch-layout">
  <div class="watch-main">
    <div class="watch-headline">
      <h1 class="watch-title" id="watch-title"><?php echo ytl_h(ytl_t('loading_meta', $locale)); ?></h1>
    </div>
    <div class="classic-player" id="movie_player">
      <div class="classic-player-screen" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;background:#000;">
        <iframe
          title="YouTube"
          src="https://www.youtube.com/embed/<?php echo ytl_h($videoId); ?>?autoplay=1&rel=0"
          style="position:absolute;left:0;top:0;width:100%;height:100%;border:0;"
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
          allowfullscreen
          referrerpolicy="strict-origin-when-cross-origin"
        ></iframe>
      </div>
    </div>
    <div class="watch-toolbar">
      <a class="lite-btn" href="<?php echo ytl_h($retryUrl); ?>">Retry Lite download</a>
      <span class="muted">Playing via YouTube (mirrors unavailable for this video)</span>
    </div>
    <div class="description-box muted" id="watch-desc"><?php echo ytl_h(ytl_t('loading_desc', $locale)); ?></div>
    <div class="description-box" id="watch-desc-full" style="display:none;"></div>
    <div class="watch-show-more" id="watch-more-wrap" style="display:none;"><a href="#watch-desc" id="watch-more-btn">Show more</a></div>
  </div>
</div>
<script src="assets/ytl-settings.js?v=1" type="text/javascript"></script>
<script type="text/javascript">
(function () {
  var metaUrl = <?php echo json_encode($metaUrl); ?>;
  var videoId = <?php echo json_encode($videoId); ?>;
  var i18n = <?php echo json_encode([
      'noDesc' => ytl_t('no_description', $locale),
  ], JSON_UNESCAPED_UNICODE); ?>;
  function esc(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s == null ? '' : String(s)));
    return d.innerHTML;
  }
  function htmlToPlain(html) {
    try {
      var d = document.createElement('div');
      d.innerHTML = String(html || '').replace(/<br\s*\/?>/gi, '\n');
      return String(d.textContent || d.innerText || '').replace(/\n{3,}/g, '\n\n').trim();
    } catch (e) { return ''; }
  }
  function applyDesc(desc) {
    var descEl = document.getElementById('watch-desc');
    var fullEl = document.getElementById('watch-desc-full');
    var moreWrap = document.getElementById('watch-more-wrap');
    if (!descEl) return;
    if (!desc) {
      descEl.className = 'description-box muted';
      descEl.innerHTML = esc(i18n.noDesc);
      if (moreWrap) moreWrap.style.display = 'none';
      return;
    }
    descEl.className = 'description-box';
    function descHtml(s) { return esc(s).replace(/\n/g, '<br>'); }
    if (desc.length > 280 && fullEl && moreWrap) {
      descEl.innerHTML = descHtml(desc.substring(0, 280) + '\u2026');
      fullEl.innerHTML = descHtml(desc);
      moreWrap.style.display = '';
      var btn = document.getElementById('watch-more-btn');
      var open = false;
      if (btn) btn.onclick = function () {
        open = !open;
        descEl.style.display = open ? 'none' : '';
        fullEl.style.display = open ? '' : 'none';
        btn.innerHTML = open ? 'Show less' : 'Show more';
        return false;
      };
    } else {
      descEl.innerHTML = descHtml(desc);
      if (moreWrap) moreWrap.style.display = 'none';
    }
  }
  function enrich(data) {
    var titleEl = document.getElementById('watch-title');
    if (data && data.title && titleEl) titleEl.innerHTML = esc(data.title);
    if (data && data.description) applyDesc(data.description);
    if (data && data.description) return;
    var hosts = ['https://yt.chocolatemoo53.com','https://invidious.tiekoetter.com','https://yewtu.be'];
    var i = 0;
    function next() {
      if (i >= hosts.length) { if (!(data && data.description)) applyDesc(''); return; }
      var base = hosts[i++];
      fetch(base + '/api/v1/videos/' + encodeURIComponent(videoId), { mode: 'cors', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.title) throw new Error('x');
          if (titleEl && j.title) titleEl.innerHTML = esc(j.title);
          var d = String(j.description || '');
          if (!d) d = htmlToPlain(j.descriptionHtml || '');
          applyDesc(d);
        }).catch(next);
    }
    next();
  }
  try {
    var cached = sessionStorage.getItem('ytl_watch_meta_' + videoId);
    if (cached) enrich(JSON.parse(cached));
  } catch (e0) {}
  fetch(metaUrl + (metaUrl.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now())
    .then(function (r) { return r.json(); })
    .then(enrich)
    .catch(function () { enrich({}); });
})();
</script>
    <?php
    ytl_layout_end();
    exit;
}

// ——— Prepare page: barre seule (pas de miniature / meta visibles) ———
if (!$playMode) {
    $locale = ytl_ui_locale();
    $cfgWatch = ytl_config();
    $serverSettings = ytl_settings_get();
    $cobaltApis = [];
    foreach (array_merge(
        (array) ($serverSettings['convert'] ?? []),
        (array) ($cfgWatch['cobalt_apis'] ?? [])
    ) as $api) {
        $api = rtrim(trim((string) $api), '/');
        if ($api !== '' && str_starts_with($api, 'https://')) {
            $cobaltApis[] = $api;
        }
    }
    $cobaltApis = array_values(array_unique($cobaltApis));
    $runUrl = ytl_url('convert_run.php', ['v' => $videoId, 'q' => $quality, 'run' => '1']);
    $statusUrl = ytl_url('convert_run.php', ['v' => $videoId, 'q' => $quality, 'status' => '1']);
    $playUrl = ytl_url('watch.php', ['v' => $videoId, 'q' => $quality, 'play' => '1']);
    $playExtUrl = ytl_url('watch.php', ['v' => $videoId, 'q' => $quality, 'play' => '1', 'ext' => '1']);
    $retryUrl = ytl_url('watch.php', ['v' => $videoId, 'q' => $quality]);
    $metaUrl = ytl_url('watch_meta.php', ['v' => $videoId, 'part' => 'meta']);
    $commentsUrl = ytl_url('watch_meta.php', ['v' => $videoId, 'part' => 'comments']);

    header('Cache-Control: no-store');
    ytl_layout_start(ytl_t('preparing', $locale), '', 'all', 'videos', false);
    ?>
<div class="watch-prepare-only">
  <div class="watch-headline">
    <h1 class="watch-title"><?php echo ytl_h(ytl_t('preparing', $locale)); ?></h1>
  </div>

  <div class="classic-player" id="movie_player">
    <div class="classic-player-screen classic-player-screen--prepare">
      <div class="prepare-overlay">
        <div class="prepare-box prepare-box-player" id="prepare-box">
          <div class="prepare-title"><?php echo ytl_h(sprintf(ytl_t('preparing_q', $locale), $qualityLabel)); ?></div>
          <div class="prepare-status" id="prepare-status"><?php echo ytl_h(ytl_t('starting', $locale)); ?></div>
          <div class="prepare-bar-wrap">
            <div class="prepare-bar prepare-bar-wait"><span id="prepare-bar-fill" style="transform:scaleX(0.01);-webkit-transform:scaleX(0.01);"></span></div>
          </div>
          <div class="prepare-pct" id="prepare-pct">0%</div>
          <div class="error-box" id="prepare-error" style="display:none;margin-top:10px;"></div>
        </div>
      </div>
    </div>
  </div>

  <div class="watch-toolbar">
    <form method="get" action="watch.php" class="quality-form">
      <input type="hidden" name="v" value="<?php echo ytl_h($videoId); ?>">
      <label for="q-prep"><?php echo ytl_h(ytl_t('quality', $locale)); ?></label>
      <select name="q" id="q-prep" onchange="this.form.submit()">
        <?php foreach (ytl_convert_qualities() as $qOpt): ?>
          <option value="<?php echo (int) $qOpt; ?>"<?php echo $qOpt === $quality ? ' selected="selected"' : ''; ?>><?php echo ytl_h(ytl_convert_quality_label($qOpt)); ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <span class="muted"><?php echo ytl_h($qualityLabel); ?></span>
  </div>
</div>
<script type="text/javascript">
(function () {
  /* Prefetch meta/comments/suggestions pendant la conversion — invisible jusqu'à ?play=1 */
  var videoId = <?php echo json_encode($videoId); ?>;
  var metaUrl = <?php echo json_encode($metaUrl); ?>;
  var commentsUrl = <?php echo json_encode($commentsUrl); ?>;

  function store(key, text) {
    try { if (window.sessionStorage) sessionStorage.setItem(key, text); } catch (e) {}
  }

  function htmlToPlain(html) {
    try {
      var d = document.createElement('div');
      d.innerHTML = String(html || '').replace(/<br\s*\/?>/gi, '\n');
      return String(d.textContent || d.innerText || '').replace(/\n{3,}/g, '\n\n').trim();
    } catch (e) {
      return '';
    }
  }

  function mapRelatedFromInv(rec) {
    var out = [];
    if (!rec || !rec.length) return out;
    for (var i = 0; i < rec.length && out.length < 8; i++) {
      var rv = rec[i];
      if (!rv || !rv.videoId) continue;
      var rid = String(rv.videoId);
      var thumb = '';
      try {
        var th = rv.videoThumbnails || [];
        for (var t = 0; t < th.length; t++) {
          if (th[t] && th[t].url) { thumb = String(th[t].url); break; }
        }
      } catch (eT) {}
      if (!thumb) thumb = 'https://i.ytimg.com/vi/' + rid + '/mqdefault.jpg';
      out.push({
        videoId: rid,
        title: String(rv.title || 'Video'),
        author: String(rv.author || ''),
        thumb: thumb,
        watch: 'watch.php?v=' + encodeURIComponent(rid),
        views: '',
        length: ''
      });
    }
    return out;
  }

  function enrichMetaClient(data, done) {
    if (!data) data = { ok: true };
    var needDesc = !(data.description || '');
    var needAv = !(data.avatar || '');
    var needRel = !(data.related && data.related.length);
    if (!needDesc && !needAv && !needRel) {
      done(data);
      return;
    }
    var hosts = [];
    try {
      if (window.YtlSettings) {
        var s = YtlSettings.get();
        if (s.meta && s.meta.length) hosts = hosts.concat(s.meta);
        if (s.invidious && s.invidious.length) hosts = hosts.concat(s.invidious);
      }
    } catch (e0) {}
    hosts = hosts.concat([
      'https://yt.chocolatemoo53.com',
      'https://invidious.tiekoetter.com',
      'https://yewtu.be',
      'https://inv.nadeko.net'
    ]);
    var seen = {};
    var list = [];
    for (var i = 0; i < hosts.length; i++) {
      var h = String(hosts[i] || '').replace(/\/$/, '');
      if (!h || seen[h]) continue;
      seen[h] = 1;
      list.push(h);
    }
    var idx = 0;
    function finished() {
      return (!(needDesc && !(data.description || '')))
        && (!(needAv && !(data.avatar || '')))
        && (!(needRel && !(data.related && data.related.length)));
    }
    function next() {
      if (idx >= list.length || finished()) {
        done(data);
        return;
      }
      var base = list[idx++];
      if (!window.fetch) {
        done(data);
        return;
      }
      var ctrl = window.AbortController ? new AbortController() : null;
      var timer = setTimeout(function () { try { if (ctrl) ctrl.abort(); } catch (e1) {} }, 3500);
      fetch(base + '/api/v1/videos/' + encodeURIComponent(videoId), {
        mode: 'cors',
        cache: 'no-store',
        signal: ctrl ? ctrl.signal : undefined,
        headers: { Accept: 'application/json' }
      }).then(function (r) {
        return r.json().then(function (j) { return { ok: r.ok, j: j }; });
      }).then(function (res) {
        clearTimeout(timer);
        var j = res && res.j ? res.j : null;
        if (!j || !j.title) throw new Error('meta');
        if (!(data.description || '')) {
          var d = String(j.description || '');
          if (!d) d = htmlToPlain(j.descriptionHtml || '');
          if (d) data.description = d;
        }
        if (!(data.avatar || '')) {
          var thumbs = j.authorThumbnails || [];
          var best = '';
          for (var t = 0; t < thumbs.length; t++) {
            var u = thumbs[t] && thumbs[t].url ? String(thumbs[t].url) : '';
            if (u) {
              if (u.indexOf('//') === 0) u = 'https:' + u;
              best = u;
              if ((thumbs[t].width || 0) >= 88) break;
            }
          }
          if (!best && j.authorThumbnails && j.authorThumbnails[0]) {
            best = String(j.authorThumbnails[0].url || '');
          }
          if (best) data.avatar = best;
        }
        if (!(data.related && data.related.length) && j.recommendedVideos) {
          var mapped = mapRelatedFromInv(j.recommendedVideos);
          if (mapped.length) data.related = mapped;
        }
        if (!(data.author || '') && j.author) data.author = String(j.author);
        if (!(data.title || '') && j.title) data.title = String(j.title);
        next();
      }).catch(function () {
        clearTimeout(timer);
        next();
      });
    }
    next();
  }

  function prefetch(url, key) {
    try {
      var xhr = new XMLHttpRequest();
      xhr.open('GET', url + (url.indexOf('?') >= 0 ? '&' : '?') + '_=' + new Date().getTime(), true);
      xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4 || xhr.status !== 200) return;
        try {
          var data = JSON.parse(xhr.responseText);
          // Cache suggestions immediately — enrich avatar/desc in background.
          store(key, JSON.stringify(data));
          var early = (data && data.related) ? data.related : [];
          for (var i = 0; i < early.length; i++) {
            if (!early[i] || !early[i].thumb) continue;
            var img0 = new Image();
            img0.src = early[i].thumb;
          }
          enrichMetaClient(data, function (enriched) {
            store(key, JSON.stringify(enriched));
            var rows = (enriched && enriched.related) ? enriched.related : [];
            for (var j = 0; j < rows.length; j++) {
              if (!rows[j] || !rows[j].thumb) continue;
              var img = new Image();
              img.src = rows[j].thumb;
            }
            if (enriched && enriched.avatar) {
              var av = new Image();
              av.src = enriched.avatar;
            }
          });
        } catch (e2) {
          store(key, xhr.responseText);
        }
      };
      xhr.send(null);
    } catch (e) {}
  }

  prefetch(metaUrl, 'ytl_watch_meta_' + videoId);
  prefetch(commentsUrl, 'ytl_watch_comments_' + videoId);
})();
</script>
<script src="assets/ytl-settings.js?v=1" type="text/javascript"></script>
<script type="text/javascript">
(function () {
  var statusUrl = <?php echo json_encode($statusUrl); ?>;
  var runUrl = <?php echo json_encode($runUrl); ?>;
  var playUrl = <?php echo json_encode($playUrl); ?>;
  var playExtUrl = <?php echo json_encode($playExtUrl); ?>;
  var retryUrl = <?php echo json_encode($retryUrl); ?>;
  var embedUrl = <?php echo json_encode(ytl_url('watch.php', ['v' => $videoId, 'q' => $quality, 'embed' => '1'])); ?>;
  var videoId = <?php echo json_encode($videoId); ?>;
  var quality = <?php echo (int) $quality; ?>;
  var putUrl = <?php echo json_encode(ytl_url('convert_client_put.php', ['v' => $videoId, 'q' => $quality])); ?>;
  var mergeUrl = <?php echo json_encode(ytl_url('convert_client_merge.php', ['v' => $videoId, 'q' => $quality])); ?>;
  var mediaUrl = <?php echo json_encode(ytl_convert_media_url($videoId, $quality)); ?>;
  var cobaltApis = <?php echo json_encode(array_values($cobaltApis), JSON_UNESCAPED_SLASHES); ?>;
  // CORS+API Invidious hosts (local=true proxies googlevideo through the instance).
  var invApis = <?php
    $invList = [];
    foreach (array_merge(
        (array) ($serverSettings['invidious'] ?? []),
        (array) ($cfgWatch['invidious_client'] ?? []),
        (array) ($cfgWatch['invidious_instances'] ?? [])
    ) as $inv) {
        $inv = rtrim(trim((string) $inv), '/');
        if ($inv !== '' && str_starts_with($inv, 'https://')) {
            $invList[] = $inv;
        }
    }
    $invList = array_values(array_unique($invList));
    echo json_encode($invList, JSON_UNESCAPED_SLASHES);
  ?>;
  if (window.YtlSettings) {
    var _s = YtlSettings.get();
    if (_s.convert && _s.convert.length) {
      cobaltApis = YtlSettings.mergeInto(cobaltApis, _s.convert);
    }
    if (_s.invidious && _s.invidious.length) {
      invApis = YtlSettings.mergeInto(invApis, _s.invidious);
    } else if (_s.meta && _s.meta.length) {
      invApis = YtlSettings.mergeInto(invApis, _s.meta);
    }
  }
  var i18n = <?php echo json_encode([
      'checking' => ytl_t('checking', $locale),
      'downloading' => ytl_t('downloading', $locale),
      'ready' => ytl_t('ready_play', $locale),
      'buffering' => 'Buffering…',
      'failed' => ytl_t('failed', $locale),
      'retry' => ytl_t('retry', $locale),
      'playError' => ytl_t('play_error', $locale),
  ], JSON_UNESCAPED_UNICODE); ?>;
  var fill = document.getElementById('prepare-bar-fill');
  var bar = fill ? fill.parentNode : null;
  var pctEl = document.getElementById('prepare-pct');
  var statusEl = document.getElementById('prepare-status');
  var errEl = document.getElementById('prepare-error');
  var done = false;
  var started = false;
  var clientBusy = false;
  var clientDone = false;
  var clientAttempts = 0;
  var pendingFail = null;
  var lastServerPct = 0;
  var displayPct = 1;
  var lastServerAt = 0;
  var softTimer = null;
  var clientSoftTimer = null;

  function setProg(p, msg, waiting) {
    if (p < 0) p = 0;
    if (p > 100) p = 100;
    // Hard monotonic: never shrink the bar (except true 100% finish is fine).
    if (p < displayPct) p = displayPct;
    displayPct = p;
    var rounded = Math.round(p);
    if (fill) {
      // scaleX(0..1) fills edge-to-edge; CSS width:% left a visible gap at “100%”.
      fill.style.transform = 'scaleX(' + (Math.max(0.01, p / 100)) + ')';
      fill.style.webkitTransform = fill.style.transform;
    }
    if (bar) bar.className = waiting ? 'prepare-bar prepare-bar-wait' : 'prepare-bar';
    if (pctEl) pctEl.innerHTML = rounded + '%';
    if (msg && statusEl) statusEl.innerHTML = msg;
  }

  function stopSoft() {
    if (softTimer) {
      clearInterval(softTimer);
      softTimer = null;
    }
  }

  function stopClientSoft() {
    if (clientSoftTimer) {
      clearInterval(clientSoftTimer);
      clientSoftTimer = null;
    }
  }

  function startClientSoft(msg) {
    if (clientSoftTimer) return;
    clientSoftTimer = setInterval(function () {
      if (done || !clientBusy) {
        stopClientSoft();
        return;
      }
      // Keep soft crawl under real download stages (50%+), so the bar never has to jump back.
      if (displayPct < 48) {
        setProg(displayPct + 0.35, msg || (statusEl ? statusEl.innerHTML : 'Fetching via browser mirrors…'), true);
      }
    }, 400);
  }

  function fetchTimeout(url, opts, ms) {
    var ctrl = window.AbortController ? new AbortController() : null;
    var timer = null;
    var o = {};
    var k;
    if (opts) {
      for (k in opts) {
        if (Object.prototype.hasOwnProperty.call(opts, k)) o[k] = opts[k];
      }
    }
    if (ctrl) o.signal = ctrl.signal;
    var p = fetch(url, o);
    if (ctrl) {
      timer = setTimeout(function () {
        try { ctrl.abort(); } catch (e1) {}
      }, ms || 14000);
      p = p.then(function (r) {
        if (timer) clearTimeout(timer);
        return r;
      }, function (err) {
        if (timer) clearTimeout(timer);
        throw err;
      });
    }
    return p;
  }

  function goPlay() {
    done = true;
    stopSoft();
    stopClientSoft();
    setProg(100, i18n.ready, false);
    window.location.replace(playUrl);
  }

  function goExtPlay(streamUrl) {
    done = true;
    stopSoft();
    stopClientSoft();
    try {
      if (window.sessionStorage) {
        if (streamUrl && String(streamUrl).indexOf('https://') === 0) {
          sessionStorage.setItem('ytl_ext_stream_' + videoId, streamUrl);
        }
        sessionStorage.setItem('ytl_ext_stream_q_' + videoId, String(quality));
        sessionStorage.setItem('ytl_ext_blob_' + videoId, '1');
      }
    } catch (e) {}
    setProg(100, i18n.ready, false);
    window.location.replace(playExtUrl);
  }

  /**
   * Keep the % prepare bar until the browser can actually play the bytes
   * (avoids finishing prepare then waiting on the native player buffer bar).
   */
  function waitUntilBuffered(url) {
    return new Promise(function (resolve) {
      if (!url) {
        resolve(false);
        return;
      }
      var v = document.createElement('video');
      v.muted = true;
      v.preload = 'auto';
      v.setAttribute('playsinline', 'playsinline');
      v.style.cssText = 'position:fixed;left:-9999px;top:0;width:1px;height:1px;opacity:0;pointer-events:none';
      document.body.appendChild(v);
      var settled = false;
      var tick = null;
      var startedAt = Date.now();
      function cleanup(ok) {
        if (settled) return;
        settled = true;
        if (tick) clearInterval(tick);
        try {
          v.pause();
          while (v.firstChild) v.removeChild(v.firstChild);
          v.removeAttribute('src');
          v.load();
        } catch (e1) {}
        try {
          if (v.parentNode) v.parentNode.removeChild(v);
        } catch (e2) {}
        resolve(!!ok);
      }
      tick = setInterval(function () {
        var rs = v.readyState || 0;
        var pct = 88 + Math.min(11, rs * 2 + Math.floor((Date.now() - startedAt) / 2500));
        setProg(Math.max(displayPct, Math.min(99, pct)), i18n.buffering || 'Buffering…', false);
        if (rs >= 3) cleanup(true);
        if (Date.now() - startedAt > 90000) cleanup(rs >= 2);
      }, 250);
      v.oncanplaythrough = function () { cleanup(true); };
      v.onloadeddata = function () { if ((v.readyState || 0) >= 3) cleanup(true); };
      v.onerror = function () { cleanup(false); };
      try {
        var s = document.createElement('source');
        s.src = url;
        s.type = 'video/mp4';
        v.appendChild(s);
        v.load();
      } catch (e3) {
        cleanup(false);
      }
    });
  }

  function goPlayWhenBuffered() {
    if (done) return;
    // MP4 is already on-disk (same-origin). Re-buffering here then reloading the
    // play page made “100% — opening…” hang for seconds. Jump straight to play.
    try { if (window.sessionStorage) sessionStorage.setItem('ytl_autoplay_' + videoId, '1'); } catch (eA) {}
    setProg(100, i18n.ready, false);
    goPlay();
  }

  function goExtWhenBuffered(streamUrl, blob) {
    if (done) return;
    try { if (window.sessionStorage) sessionStorage.setItem('ytl_autoplay_' + videoId, '1'); } catch (eA2) {}
    // Prefer local media when the client PUT already landed.
    if (mediaUrl) {
      setProg(100, i18n.ready, false);
      goPlay();
      return;
    }
    setProg(Math.max(displayPct, 92), i18n.ready, false);
    if (blob && blob.size > 12000) {
      // Blob already in memory / IDB — open play page immediately.
      goExtPlay(streamUrl || '');
      return;
    }
    var url = streamUrl && String(streamUrl).indexOf('https://') === 0 ? String(streamUrl) : '';
    if (!url) {
      goExtPlay('');
      return;
    }
    // Short probe only for one-shot tunnels (don’t sit at 100% for a full buffer).
    waitUntilBuffered(url).then(function () {
      goExtPlay(streamUrl || '');
    });
  }

  // Cobalt tunnels are single-use: after fetch() the URL 404s. Keep bytes in IDB for playback.
  function idbOpen() {
    return new Promise(function (resolve, reject) {
      if (!window.indexedDB) { reject(new Error('idb')); return; }
      var req = indexedDB.open('ytl_media', 1);
      req.onupgradeneeded = function () {
        var db = req.result;
        if (!db.objectStoreNames.contains('blobs')) db.createObjectStore('blobs');
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error || new Error('idb')); };
    });
  }
  function idbPutBlob(blob) {
    return idbOpen().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction('blobs', 'readwrite');
        tx.objectStore('blobs').put({
          blob: blob,
          type: blob.type || 'video/mp4',
          q: quality,
          t: Date.now()
        }, videoId);
        tx.oncomplete = function () { resolve(true); };
        tx.onerror = function () { reject(tx.error || new Error('idb_put')); };
      });
    });
  }

  var lastClientErr = '';

  function mintCobaltUrl() {
    // No /session + Authorization: many Cobalt hosts omit Authorization from
    // Access-Control-Allow-Headers, so Bearer breaks CORS. Skip jwt/turnstile hosts.
    // Probe tunnels immediately: Cobalt often returns status=tunnel with Content-Length:0
    // for blocked YouTube titles — that is NOT cobalt_no_api.
    var qualities = [];
    var q0 = parseInt(quality, 10) || 360;
    qualities.push(q0);
    if (q0 !== 240) qualities.push(240);
    if (q0 !== 360) qualities.push(360);
    var qi = 0;
    var i = 0;
    var retried = {};
    var pass = 0;
    var sawEmpty = false;
    function bodyFor(q) {
      return JSON.stringify({
        url: 'https://www.youtube.com/watch?v=' + videoId,
        videoQuality: String(q || 360),
        youtubeVideoCodec: 'h264',
        youtubeVideoContainer: 'mp4',
        filenameStyle: 'basic',
        downloadMode: 'auto'
      });
    }
    function tryOne(api, q) {
      return fetchTimeout(api + '/', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          // Prefer original English audio over YouTube auto-dubs (ES/FR/…).
          'Accept-Language': 'en-US,en;q=0.9'
        },
        body: bodyFor(q),
        mode: 'cors',
        cache: 'no-store'
      }, 22000).then(function (r) {
        return r.text().then(function (t) {
          var j = null;
          try { j = JSON.parse(t); } catch (e2) { j = null; }
          return { ok: r.ok, j: j, status: r.status };
        });
      }).then(function (res) {
        var j = res && res.j ? res.j : null;
        var errCode = '';
        if (j && j.error) {
          errCode = String((j.error && j.error.code) || j.error || '');
        }
        if (errCode.indexOf('jwt') >= 0 || errCode.indexOf('turnstile') >= 0) {
          lastClientErr = 'cobalt_jwt';
          throw new Error('auth_skip');
        }
        if (errCode.indexOf('youtube.login') >= 0) {
          lastClientErr = 'cobalt_login';
          throw new Error('login_skip');
        }
        if (errCode.indexOf('unavailable') >= 0) {
          lastClientErr = 'cobalt_unavailable';
          throw new Error('unavailable');
        }
        var st = j && j.status ? String(j.status) : '';
        var url = j && (j.url || j.tunnel) ? String(j.url || j.tunnel) : '';
        if (!((st === 'tunnel' || st === 'redirect' || st === 'local-processing') && url.indexOf('https://') === 0)) {
          lastClientErr = res && res.status === 403 ? 'cobalt_403' : 'cobalt_no_api';
          throw new Error('no_tunnel');
        }
        // Verify tunnel has bytes (one-shot — consume into blob here when small probe works).
        return fetchTimeout(url, { mode: 'cors', cache: 'no-store' }, 90000).then(function (tr) {
          var cl = parseInt(tr.headers.get('content-length') || '-1', 10);
          if (cl === 0) {
            sawEmpty = true;
            lastClientErr = 'cobalt_empty';
            throw new Error('tunnel_empty');
          }
          return readTunnelBlob(tr).then(function (blob) {
            if (!blob || blob.size < 12000) {
              sawEmpty = true;
              lastClientErr = 'cobalt_empty';
              throw new Error('tunnel_empty');
            }
            lastClientErr = '';
            // Return blob via synthetic object URL path marker for callers.
            return { url: url, blob: blob };
          });
        });
      });
    }
    function nextApi() {
      if (i >= cobaltApis.length) {
        qi += 1;
        i = 0;
        if (qi < qualities.length) {
          return new Promise(function (resolve) { setTimeout(resolve, 300); }).then(nextApi);
        }
        if (pass < 1) {
          pass += 1;
          qi = 0;
          i = 0;
          return new Promise(function (resolve) { setTimeout(resolve, 500); }).then(nextApi);
        }
        if (sawEmpty) lastClientErr = 'cobalt_empty';
        else if (!lastClientErr) lastClientErr = 'cobalt_no_api';
        return Promise.reject(new Error(sawEmpty ? 'empty' : 'no_api'));
      }
      var api = String(cobaltApis[i] || '').replace(/\/$/, '');
      var idx = i++;
      var q = qualities[qi] || 360;
      if (!api) return nextApi();
      return tryOne(api, q).catch(function (err) {
        var msg = err && err.message ? String(err.message) : '';
        if (msg !== 'auth_skip' && msg !== 'login_skip' && msg !== 'unavailable' && !retried[api + ':' + q] && (idx === 0 || api.indexOf('rue-cobalt') >= 0)) {
          retried[api + ':' + q] = 1;
          i = idx;
          return new Promise(function (resolve) { setTimeout(resolve, 450); }).then(nextApi);
        }
        return nextApi();
      });
    }
    return nextApi();
  }

  function readTunnelBlob(tr) {
    if (!tr || !tr.ok) return Promise.reject(new Error('tunnel'));
    var total = parseInt(tr.headers.get('content-length') || '0', 10) || 0;
    if (!tr.body || !tr.body.getReader) {
      return tr.blob();
    }
    var reader = tr.body.getReader();
    var chunks = [];
    var received = 0;
    function pump() {
      return reader.read().then(function (result) {
        if (result.done) {
          return new Blob(chunks, { type: (tr.headers.get('content-type') || 'video/mp4') });
        }
        chunks.push(result.value);
        received += result.value.byteLength || result.value.length || 0;
        var pct = 50;
        if (total > 0) {
          pct = Math.min(80, 50 + Math.floor(30 * received / total));
        } else {
          pct = Math.min(78, Math.max(displayPct, 52) + 0.5);
        }
        setProg(Math.max(displayPct, pct), i18n.downloading, false);
        return pump();
      });
    }
    return pump();
  }

  var lastErrorCode = '';

  function formatFailMsg(msg, code) {
    var c = String(code || lastErrorCode || lastClientErr || '').replace(/[^a-z0-9_.-]/gi, '');
    if (c === 'download_failed' || c === 'server_exhausted') {
      c = lastClientErr || 'client_cobalt_fail';
    }
    var safe = String(msg || i18n.playError);
    // Never surface the in-progress handoff line as a terminal error.
    if (/fetching via browser mirrors/i.test(safe) || /préparation/i.test(safe)) {
      if (c === 'cobalt_empty') {
        safe = 'Browser mirrors returned an empty file for this video. Try another video.';
      } else if (c === 'cobalt_unavailable') {
        safe = 'Browser mirrors say this video is unavailable.';
      } else {
        safe = 'Browser mirror fetch failed. Try again.';
      }
    }
    if (c === 'cobalt_empty' && safe.indexOf('empty') < 0) {
      safe = 'Browser mirrors returned an empty file for this video. Try another video.';
    }
    if (c && safe.indexOf('(' + c + ')') < 0) {
      safe = safe.replace(/\s+$/, '') + ' (' + c + ')';
    }
    return safe;
  }

  function showTerminalFail(msg, code) {
    if (done) return;
    // Never show Réessayer while a browser mirror fetch is still running.
    if (clientBusy) {
      pendingFail = formatFailMsg(msg, code);
      return;
    }
    done = true;
    stopSoft();
    stopClientSoft();
    if (statusEl) statusEl.innerHTML = i18n.failed;
    if (bar) bar.className = 'prepare-bar';
    if (errEl) {
      errEl.style.display = 'block';
      var safe = formatFailMsg(msg, code || lastClientErr || 'client_cobalt_fail');
      errEl.innerHTML = '<strong>' + safe + '</strong><br>'
        + '<a href="' + retryUrl + '">' + i18n.retry + '</a>'
        + ' · <a href="' + embedUrl + '">Play via YouTube</a>';
    }
  }

  function putPart(part, blob) {
    return fetch(putUrl + (putUrl.indexOf('?') >= 0 ? '&' : '?') + 'part=' + encodeURIComponent(part), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/octet-stream' },
      body: blob,
      cache: 'no-store'
    }).then(function (pr) {
      return pr.json().catch(function () { return {}; }).then(function (pj) {
        if (!pr.ok || !pj || !pj.ok) throw new Error('put_' + part);
        return pj;
      });
    });
  }

  /**
   * Many Invidious local=true proxies 403 a full GET but allow Range.
   * They also 403 after ~0.5–1MB — refreshUrl() must return a fresh stream URL
   * so we can resume at the same byte offset.
   */
  function fetchBlobProgress(url, basePct, spanPct, label, refreshUrl) {
    var mime = 'application/octet-stream';
    var activeUrl = url;
    var refreshes = 0;
    function progress(received, total) {
      var frac = total > 0
        ? Math.min(1, received / total)
        : Math.min(0.95, received / (8 * 1024 * 1024));
      setProg(Math.max(displayPct, basePct + Math.floor(spanPct * frac)), label || i18n.downloading, false);
    }
    function readStream(r, totalHint) {
      mime = r.headers.get('content-type') || mime;
      var total = totalHint || parseInt(r.headers.get('content-length') || '0', 10) || 0;
      if (!r.body || !r.body.getReader) {
        return r.blob().then(function (b) {
          progress(b.size, total || b.size);
          return b;
        });
      }
      var reader = r.body.getReader();
      var chunks = [];
      var received = 0;
      function pump() {
        return reader.read().then(function (result) {
          if (result.done) {
            return new Blob(chunks, { type: mime });
          }
          chunks.push(result.value);
          received += result.value.byteLength || result.value.length || 0;
          progress(received, total);
          return pump();
        });
      }
      return pump();
    }
    function renewUrl() {
      if (typeof refreshUrl !== 'function' || refreshes >= 24) {
        return Promise.reject(new Error('range_403'));
      }
      refreshes += 1;
      return Promise.resolve(refreshUrl()).then(function (u) {
        if (!u || String(u).indexOf('https://') !== 0) throw new Error('range_403');
        activeUrl = String(u);
        return activeUrl;
      });
    }
    function rangedDownload() {
      // Some Invidious local proxies cap each Range response (~64KiB) and omit Content-Range.
      // Learn the cap from the first chunk; stop on 416 / 0 bytes / short-final / known total.
      var CHUNK = 64 * 1024;
      var offset = 0;
      var chunks = [];
      var total = 0;
      var cap = 0;
      var gotMime = '';
      var sinceRenew = 0;
      function next() {
        // Proactively renew every ~10 chunks — proxies often hard-403 after ~768KiB/URL.
        var renewFirst = (sinceRenew >= 10 && typeof refreshUrl === 'function')
          ? renewUrl().then(function () { sinceRenew = 0; }).catch(function () { return null; })
          : Promise.resolve(null);
        return renewFirst.then(function () {
          var end = offset + CHUNK - 1;
          return fetchTimeout(activeUrl, {
            mode: 'cors',
            cache: 'no-store',
            headers: { Range: 'bytes=' + offset + '-' + end }
          }, 60000).then(function (r) {
            if (r && (r.status === 403 || r.status === 429)) {
              return Promise.resolve(r.arrayBuffer()).catch(function () { return null; }).then(function () {
                return new Promise(function (res) { setTimeout(res, 800); });
              }).then(function () {
                return renewUrl();
              }).then(function () {
                sinceRenew = 0;
                return next();
              });
            }
            if (r && r.status === 416) {
              if (chunks.length === 0) throw new Error('range_empty');
              return new Blob(chunks, { type: mime });
            }
            if (!r || (r.status !== 206 && r.status !== 200)) {
              throw new Error('range_' + (r && r.status));
            }
            var cr = r.headers.get('content-range') || '';
            var m = cr.match(/\/(\d+)/);
            if (m) total = parseInt(m[1], 10) || total;
            if (!gotMime) {
              gotMime = r.headers.get('content-type') || '';
              if (gotMime) mime = gotMime;
            }
            if (r.status === 200 && offset === 0) {
              return readStream(r, total);
            }
            return r.arrayBuffer().then(function (buf) {
              var n = buf.byteLength || 0;
              if (n === 0) {
                if (chunks.length === 0) throw new Error('range_empty');
                return new Blob(chunks, { type: mime });
              }
              if (!cap) cap = n;
              chunks.push(new Uint8Array(buf));
              offset += n;
              sinceRenew += 1;
              progress(offset, total);
              if (total > 0 && offset >= total) {
                return new Blob(chunks, { type: mime });
              }
              if (cap > 0 && n < cap) {
                return new Blob(chunks, { type: mime });
              }
              return next();
            });
          });
        });
      }
      return next();
    }
    return fetchTimeout(activeUrl, { mode: 'cors', cache: 'no-store' }, 20000).then(function (r) {
      if (!r) throw new Error('http_0');
      if (r.status === 403 || r.status === 401) {
        return rangedDownload();
      }
      if (!r.ok) throw new Error('http_' + r.status);
      return readStream(r, 0).then(function (blob) {
        var t = String(blob.type || mime || '');
        if (blob.size < 1000 || (blob.size < 12000 && t.indexOf('text/') === 0)) {
          return rangedDownload();
        }
        return blob;
      });
    }, function () {
      return rangedDownload();
    });
  }

  function pickInvStreams(j, wantH) {
    var adap = (j && j.adaptiveFormats) ? j.adaptiveFormats : [];
    var vids = [];
    var auds = [];
    for (var i = 0; i < adap.length; i++) {
      var f = adap[i];
      if (!f || !f.url) continue;
      var t = String(f.type || '');
      if (t.indexOf('video/mp4') === 0) vids.push(f);
      else if (t.indexOf('audio/') === 0) auds.push(f);
    }
    function heightOf(f) {
      var r = String(f.resolution || f.qualityLabel || '');
      var m = r.match(/(\d+)/);
      return m ? parseInt(m[1], 10) : 0;
    }
    /** Prefer original / default track — YouTube auto-dubs often label Spanish/French/etc. */
    function audioScore(f) {
      var track = f.audioTrack || {};
      var label = String(track.displayName || track.id || f.language || f.audioTrackType || '').toLowerCase();
      var score = parseInt(f.bitrate || '0', 10) || 0;
      if (track.audioIsDefault || /original|default|primary/.test(label)) score += 5000000;
      if (/\ben\b|english|anglais/.test(label)) score += 2000000;
      if (/dub|español|espanol|spanish|castellano|latino|français|francais|french|deutsch|german|portugu/.test(label)
          && !/original/.test(label)) {
        score -= 8000000;
      }
      // Prefer AAC/mp4 for merge compatibility.
      if (String(f.type || '').indexOf('audio/mp4') >= 0) score += 50000;
      return score;
    }
    vids.sort(function (a, b) {
      return Math.abs(heightOf(a) - wantH) - Math.abs(heightOf(b) - wantH);
    });
    auds.sort(function (a, b) {
      return audioScore(b) - audioScore(a);
    });
    if (!vids.length || !auds.length) return null;
    return { video: vids[0], audio: auds[0] };
  }

  function tryClientInvidious(onDone) {
    if (done || clientDone) {
      if (onDone) onDone(true);
      return;
    }
    if (!window.fetch || !invApis || !invApis.length) {
      if (onDone) onDone(false);
      return;
    }
    clientBusy = true;
    setProg(Math.max(displayPct, 46), 'Fetching via Invidious…', true);
    startClientSoft('Fetching via Invidious…');
    var ii = 0;
    function nextInv() {
      if (ii >= invApis.length) {
        lastClientErr = lastClientErr || 'invidious_fail';
        clientBusy = false;
        stopClientSoft();
        if (onDone) onDone(false);
        return;
      }
      var base = String(invApis[ii++] || '').replace(/\/$/, '');
      if (!base) return nextInv();
      fetchTimeout(base + '/api/v1/videos/' + encodeURIComponent(videoId) + '?local=true', {
        mode: 'cors',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
      }, 20000).then(function (r) {
        return r.json().then(function (j) { return { ok: r.ok, j: j }; });
      }).then(function (res) {
        if (!res.ok || !res.j) throw new Error('meta');
        var picked = pickInvStreams(res.j, quality || 360);
        if (!picked) throw new Error('no_streams');
        stopClientSoft();
        function refreshPart(kind) {
          return fetchTimeout(base + '/api/v1/videos/' + encodeURIComponent(videoId) + '?local=true', {
            mode: 'cors',
            cache: 'no-store',
            headers: { Accept: 'application/json' }
          }, 15000).then(function (rr) {
            return rr.json();
          }).then(function (jj) {
            var p2 = pickInvStreams(jj, quality || 360);
            if (!p2) return '';
            return kind === 'a' ? String(p2.audio.url || '') : String(p2.video.url || '');
          }).catch(function () { return ''; });
        }
        var vBase = Math.max(displayPct, 50);
        setProg(vBase, 'Downloading video…', false);
        return fetchBlobProgress(picked.video.url, vBase, 22, 'Downloading video…', function () {
          return refreshPart('v');
        }).then(function (vBlob) {
          if (!vBlob || vBlob.size < 12000) throw new Error('video_small');
          var aBase = Math.max(displayPct, 74);
          setProg(aBase, 'Downloading audio…', false);
          return fetchBlobProgress(picked.audio.url, aBase, 12, 'Downloading audio…', function () {
            return refreshPart('a');
          }).then(function (aBlob) {
            if (!aBlob || aBlob.size < 1000) throw new Error('audio_small');
            setProg(Math.max(displayPct, 88), 'Uploading…', false);
            return putPart('v', vBlob).then(function () {
              return putPart('a', aBlob);
            }).then(function () {
              setProg(Math.max(displayPct, 94), 'Merging…', false);
              return fetch(mergeUrl, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store'
              }).then(function (mr) {
                return mr.json().catch(function () { return {}; }).then(function (mj) {
                  if (!mr.ok || !mj || !mj.ok) throw new Error('merge');
                  goPlayWhenBuffered();
                  clientDone = true;
                  clientBusy = false;
                  pendingFail = null;
                  if (onDone) onDone(true);
                });
              });
            });
          });
        });
      }).catch(function (err) {
        var msg = err && err.message ? String(err.message) : '';
        if (msg.indexOf('put_') === 0) lastClientErr = msg;
        else if (msg.indexOf('merge') === 0) lastClientErr = 'invidious_merge';
        else if (msg.indexOf('range_') === 0 || msg.indexOf('http_403') === 0) lastClientErr = 'invidious_403';
        else if (msg.indexOf('video_small') === 0 || msg.indexOf('audio_small') === 0) lastClientErr = msg;
        else lastClientErr = 'invidious_fail';
        nextInv();
      });
    }
    nextInv();
  }

  function tryClientMirrors(onDone) {
    tryClientCobalt(function (ok) {
      if (ok || done) {
        if (onDone) onDone(!!ok);
        return;
      }
      tryClientInvidious(function (ok2) {
        if (onDone) onDone(!!ok2);
        else if (!ok2 && !done && pendingFail) {
          // Only terminal-fail when server already handed off / failed.
          pendingFail = null;
          showTerminalFail(
            'Browser mirror fetch failed. Try again.',
            lastClientErr || lastErrorCode || 'client_cobalt_fail'
          );
        }
      });
    });
  }

  function tryClientCobalt(onDone) {
    if (done || clientDone) {
      if (onDone) onDone(true);
      return;
    }
    if (clientBusy) {
      // In-flight attempt — poll/handoff will re-check; do not fake-fail.
      return;
    }
    if (!window.fetch || !cobaltApis || !cobaltApis.length) {
      if (onDone) onDone(false);
      return;
    }
    clientBusy = true;
    clientAttempts += 1;
    stopSoft();
    function finish(ok) {
      clientDone = clientDone || !!ok;
      clientBusy = false;
      stopClientSoft();
      if (ok) pendingFail = null;
      if (onDone) {
        onDone(!!ok);
        return;
      }
      // Parallel early kick (no callback): Cobalt miss → Invidious, never stop at cobalt_no_api.
      if (!ok && !done) {
        tryClientInvidious(function (ok2) {
          if (ok2 || done) return;
          if (pendingFail) {
            pendingFail = null;
            showTerminalFail(
              'Browser mirror fetch failed. Try again.',
              lastClientErr || lastErrorCode || 'client_cobalt_fail'
            );
          }
        });
      }
    }
    setProg(Math.max(displayPct, 42), 'Fetching via browser mirrors…', true);
    startClientSoft('Fetching via browser mirrors…');
    // mintCobaltUrl already downloads a verified non-empty blob (tunnels are one-shot).
    mintCobaltUrl().then(function (minted) {
      if (done) { finish(true); return; }
      var blob = minted && minted.blob ? minted.blob : null;
      if (!blob || blob.size < 12000) {
        lastClientErr = lastClientErr || 'cobalt_empty';
        finish(false);
        return;
      }
      stopClientSoft();
      setProg(Math.max(displayPct, 84), i18n.downloading, false);
      return idbPutBlob(blob).catch(function () { return false; }).then(function () {
        // Large blobs: skip PUT (OVH body limit) — play from IDB/ext.
        if (blob.size > 35 * 1024 * 1024) {
          goExtWhenBuffered('', blob);
          finish(true);
          return;
        }
        return fetch(putUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/octet-stream' },
          body: blob,
          cache: 'no-store'
        }).then(function (pr) {
          return pr.json().catch(function () { return {}; }).then(function (pj) {
            if (pr.ok && pj && pj.ok) {
              goPlayWhenBuffered();
              finish(true);
              return;
            }
            goExtWhenBuffered('', blob);
            finish(true);
          });
        }).catch(function () {
          goExtWhenBuffered('', blob);
          finish(true);
        });
      });
    }).catch(function () {
      lastClientErr = lastClientErr || 'cobalt_no_api';
      finish(false);
    });
  }

  function fail(msg, code) {
    // Last chance: browser-side Cobalt then Invidious (OVH IP is often blocked).
    if (done) return;
    if (clientDone) return;
    if (code) lastErrorCode = String(code);
    // Keep a real terminal message, not the handoff progress line.
    var safe = formatFailMsg(
      /browser mirrors/i.test(String(msg || '')) ? 'Browser mirror fetch failed. Try again.' : msg,
      code
    );
    if (clientBusy) {
      pendingFail = 'wait';
      return;
    }
    setProg(Math.max(displayPct, 42), 'Fetching via browser mirrors…', true);
    pendingFail = 'wait';
    tryClientMirrors(function (ok) {
      if (ok || done) return;
      showTerminalFail(safe, lastClientErr || code || 'client_cobalt_fail');
    });
  }

  function handoffClientCobalt(msg) {
    if (done || clientDone) return;
    if (errEl) errEl.style.display = 'none';
    if (clientBusy) {
      // In-flight browser fetch — do not park the soft progress text as a fail message.
      pendingFail = 'wait';
      // Do not clobber download progress with server 45%.
      return;
    }
    setProg(Math.max(displayPct, 42), msg || 'Fetching via browser mirrors…', true);
    pendingFail = String(msg || i18n.playError);
    tryClientMirrors(function (ok) {
      if (ok || done) return;
      showTerminalFail(msg || i18n.playError);
    });
  }

  function startSoft() {
    if (softTimer) return;
    // Soft crawl only while stuck early — NEVER lower displayPct (that caused 90%→28% loops).
    softTimer = setInterval(function () {
      if (done || clientBusy || clientDone) {
        stopSoft();
        return;
      }
      if (lastServerPct >= 20 || displayPct >= 28) {
        stopSoft();
        return;
      }
      var stalled = (new Date().getTime() - lastServerAt) > 900;
      if (!stalled && lastServerPct > 0) {
        setProg(Math.max(displayPct, lastServerPct), statusEl ? statusEl.innerHTML : i18n.downloading, true);
        return;
      }
      var next = displayPct + (displayPct < 12 ? 0.55 : 0.22);
      if (next > 28) next = 28;
      setProg(Math.max(next, lastServerPct), statusEl ? statusEl.innerHTML : i18n.downloading, true);
    }, 500);
  }

  function startDownload() {
    if (started) return;
    started = true;
    setProg(Math.max(displayPct, lastServerPct, 2), i18n.downloading, true);
    startSoft();
    try {
      if (window.fetch) {
        fetch(runUrl, { cache: 'no-store', credentials: 'same-origin' }).catch(function () {});
      } else {
        var x = new XMLHttpRequest();
        x.open('GET', runUrl, true);
        x.send(null);
      }
    } catch (e) {}
    // Parallel: Cobalt then Invidious from the visitor browser (OVH IP is blocked).
    setTimeout(function () { tryClientMirrors(null); }, 120);
  }

  function poll() {
    if (done) return;
    var xhr = new XMLHttpRequest();
    xhr.open('GET', statusUrl + (statusUrl.indexOf('?') >= 0 ? '&' : '?') + '_=' + (new Date().getTime()), true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      if (xhr.status !== 200) { setTimeout(poll, 500); return; }
      var data;
      try {
        data = window.JSON ? JSON.parse(xhr.responseText) : eval('(' + xhr.responseText + ')');
      } catch (e) { setTimeout(poll, 500); return; }
      if (data.ready) {
        goPlayWhenBuffered();
        return;
      }
      // Server handoff / error FIRST — never let server 45% overwrite an in-flight
      // browser download (that looked like a stuck bar, then false Réessayer).
      if (data.error_code) {
        lastErrorCode = String(data.error_code);
      }
      if (data.state === 'client_cobalt' || data.client_cobalt) {
        started = true;
        stopSoft();
        if (data.error_code) lastErrorCode = String(data.error_code);
        handoffClientCobalt(data.message || 'Fetching via browser mirrors…');
        setTimeout(poll, 700);
        return;
      }
      if (data.state === 'error') {
        fail(data.message || data.error || i18n.playError, data.error_code || data.error || '');
        // Keep polling — client PUT may still land ready.
        setTimeout(poll, 800);
        return;
      }
      // While browser Cobalt is working, ignore server percent (stays at 45 handoff).
      if (clientBusy || clientDone) {
        setTimeout(poll, 700);
        return;
      }
      var pct = parseInt(data.percent, 10) || 0;
      if (pct > lastServerPct) {
        lastServerPct = pct;
        lastServerAt = new Date().getTime();
      }
      var msg = data.message || i18n.downloading;
      if (lastServerPct >= 20) {
        stopSoft();
        setProg(Math.max(displayPct, lastServerPct), msg, false);
      } else if (!clientBusy) {
        startSoft();
        setProg(Math.max(displayPct, lastServerPct), msg, true);
      }
      if (!started) startDownload();
      setTimeout(poll, 350);
    };
    xhr.send(null);
  }

  lastServerAt = new Date().getTime();
  setProg(1, i18n.checking, true);
  startSoft();
  // Kick browser Cobalt→Invidious immediately (don't wait for server error).
  setTimeout(function () { tryClientMirrors(null); }, 80);
  poll();
})();
</script>
    <?php
    ytl_layout_end();
    exit;
}

// ——— Player (after prepare ready, or browser-side Cobalt ext stream) ———
// Prefer same-origin cache/mp4 over a one-shot Cobalt tunnel whenever the file exists.
if ($extMode && ytl_convert_is_ready($videoId, $quality)) {
    $extMode = false;
}
$extReady = $extMode && $playMode;
if (!$extReady && !ytl_convert_is_ready($videoId, $quality)) {
    header('Location: ' . ytl_url('watch.php', ['v' => $videoId, 'q' => $quality]));
    exit;
}

if (!$extReady && !ytl_convert_available()) {
    ytl_layout_start('Watch', '', 'all', 'videos', false);
    echo '<div class="error-box"><strong>' . ytl_h(ytl_t('unavailable')) . '</strong></div>';
    ytl_layout_end();
    exit;
}

require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/oauth.php';

ytl_session_start();
$ytUser = ytl_current_user();
$ytCsrf = ytl_oauth_csrf_token();
$ytLoggedIn = $ytUser !== null;

// Fast player: stream first; metadata (desc / likes / comments) loads async.
$video = ytl_convert_video_stub($videoId, [
    'title' => 'Video',
    'videoId' => $videoId,
    'videoThumbnails' => [[
        'url' => 'https://i.ytimg.com/vi/' . $videoId . '/mqdefault.jpg',
        'width' => 320,
        'height' => 180,
    ]],
]);
$poster = ytl_thumb_url($video);
// Ext/Cobalt: no void #src — JS (resolveExtSrc) injects blob:/https:/media.php before Play.
$playUrl = $extReady ? '' : ytl_convert_media_url($videoId, $quality);
$downloadUrl = $extReady ? '#' : ytl_convert_media_url($videoId, $quality, true);
$openUrl = $extReady ? '#' : ytl_convert_play_url($videoId, $quality);
$mime = 'video/mp4';
$metaUrl = ytl_url('watch_meta.php', ['v' => $videoId, 'part' => 'meta']);
$commentsUrl = ytl_url('watch_meta.php', ['v' => $videoId, 'part' => 'comments']);
$locale = ytl_ui_locale();

ytl_layout_start('Video', '', 'all', 'videos', false);
?>
<?php if ($extReady): ?>
<script src="assets/ytl-settings.js?v=1" type="text/javascript"></script>
<script type="text/javascript">
(function () {
  var id = <?php echo json_encode($videoId); ?>;
  var quality = <?php echo (int) $quality; ?>;
  var cobaltApis = <?php echo json_encode(array_values((function () {
      $cfg = ytl_config();
      $s = ytl_settings_get();
      $out = [];
      foreach (array_merge((array) ($s['convert'] ?? []), (array) ($cfg['cobalt_apis'] ?? [])) as $api) {
          $api = rtrim(trim((string) $api), '/');
          if ($api !== '' && str_starts_with($api, 'https://')) {
              $out[] = $api;
          }
      }
      return array_values(array_unique($out));
  })()), JSON_UNESCAPED_SLASHES); ?>;
  if (window.YtlSettings) {
    var _cs = YtlSettings.get();
    if (_cs.convert && _cs.convert.length) {
      cobaltApis = YtlSettings.mergeInto(cobaltApis, _cs.convert);
    }
  }
  var prepareUrl = <?php echo json_encode(ytl_url('watch.php', ['v' => $videoId, 'q' => $quality])); ?>;
  var localMediaUrl = <?php echo json_encode(ytl_convert_media_url($videoId, $quality)); ?>;

  function fetchTimeout(url, opts, ms) {
    var ctrl = window.AbortController ? new AbortController() : null;
    var timer = null;
    var o = {};
    var k;
    if (opts) {
      for (k in opts) {
        if (Object.prototype.hasOwnProperty.call(opts, k)) o[k] = opts[k];
      }
    }
    if (ctrl) o.signal = ctrl.signal;
    var p = fetch(url, o);
    if (ctrl) {
      timer = setTimeout(function () {
        try { ctrl.abort(); } catch (e1) {}
      }, ms || 20000);
      p = p.then(function (r) {
        if (timer) clearTimeout(timer);
        return r;
      }, function (err) {
        if (timer) clearTimeout(timer);
        throw err;
      });
    }
    return p;
  }

  function idbPutBlob(blob) {
    return new Promise(function (resolve, reject) {
      if (!window.indexedDB) { resolve(false); return; }
      var req = indexedDB.open('ytl_media', 1);
      req.onupgradeneeded = function () {
        var db = req.result;
        if (!db.objectStoreNames.contains('blobs')) db.createObjectStore('blobs');
      };
      req.onsuccess = function () {
        try {
          var tx = req.result.transaction('blobs', 'readwrite');
          tx.objectStore('blobs').put({
            blob: blob,
            type: blob.type || 'video/mp4',
            q: quality,
            t: Date.now()
          }, id);
          tx.oncomplete = function () { resolve(true); };
          tx.onerror = function () { resolve(false); };
        } catch (e) { resolve(false); }
      };
      req.onerror = function () { resolve(false); };
    });
  }

  function idbGetBlob() {
    return new Promise(function (resolve, reject) {
      if (!window.indexedDB) { reject(new Error('idb')); return; }
      var req = indexedDB.open('ytl_media', 1);
      req.onupgradeneeded = function () {
        var db = req.result;
        if (!db.objectStoreNames.contains('blobs')) db.createObjectStore('blobs');
      };
      req.onsuccess = function () {
        var db = req.result;
        try {
          var tx = db.transaction('blobs', 'readonly');
          var g = tx.objectStore('blobs').get(id);
          g.onsuccess = function () {
            var row = g.result;
            if (row && row.blob && row.blob.size > 12000) resolve(row.blob);
            else reject(new Error('empty'));
          };
          g.onerror = function () { reject(g.error || new Error('idb_get')); };
        } catch (e) { reject(e); }
      };
      req.onerror = function () { reject(req.error || new Error('idb')); };
    });
  }

  function mintFreshTunnel() {
    var qualities = [];
    var q0 = parseInt(quality, 10) || 360;
    qualities.push(q0);
    if (q0 !== 240) qualities.push(240);
    var qi = 0;
    var i = 0;
    var retried = {};
    function bodyFor(q) {
      return JSON.stringify({
        url: 'https://www.youtube.com/watch?v=' + id,
        videoQuality: String(q || 360),
        youtubeVideoCodec: 'h264',
        youtubeVideoContainer: 'mp4',
        filenameStyle: 'basic',
        downloadMode: 'auto'
      });
    }
    function tryOne(api, q) {
      var ctrl = window.AbortController ? new AbortController() : null;
      var timer = null;
      var opts = {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: bodyFor(q),
        mode: 'cors',
        cache: 'no-store'
      };
      if (ctrl) opts.signal = ctrl.signal;
      var p = fetch(api + '/', opts);
      if (ctrl) {
        timer = setTimeout(function () { try { ctrl.abort(); } catch (e1) {} }, 14000);
        p = p.then(function (r) { if (timer) clearTimeout(timer); return r; }, function (err) {
          if (timer) clearTimeout(timer); throw err;
        });
      }
      return p.then(function (r) {
        return r.text().then(function (t) {
          var j = null;
          try { j = JSON.parse(t); } catch (e2) { j = null; }
          return j;
        });
      }).then(function (j) {
        var errCode = '';
        if (j && j.error) errCode = String((j.error && j.error.code) || j.error || '');
        if (errCode.indexOf('jwt') >= 0 || errCode.indexOf('turnstile') >= 0) throw new Error('auth_skip');
        var st = j && j.status ? String(j.status) : '';
        var url = j && (j.url || j.tunnel) ? String(j.url || j.tunnel) : '';
        if ((st === 'tunnel' || st === 'redirect' || st === 'local-processing') && url.indexOf('https://') === 0) {
          return url;
        }
        throw new Error('no_tunnel');
      });
    }
    function nextApi() {
      if (i >= cobaltApis.length) {
        qi += 1;
        i = 0;
        if (qi < qualities.length) return nextApi();
        return Promise.reject(new Error('no_api'));
      }
      var api = String(cobaltApis[i] || '').replace(/\/$/, '');
      var idx = i++;
      var q = qualities[qi] || 360;
      if (!api) return nextApi();
      return tryOne(api, q).catch(function (err) {
        var msg = err && err.message ? String(err.message) : '';
        if (msg !== 'auth_skip' && !retried[api + ':' + q] && (idx === 0 || api.indexOf('rue-cobalt') >= 0)) {
          retried[api + ':' + q] = 1;
          i = idx;
          return new Promise(function (resolve) { setTimeout(resolve, 350); }).then(nextApi);
        }
        return nextApi();
      });
    }
    return nextApi();
  }

  function applySrc(url, doLoad) {
    var v = document.getElementById('watch-video');
    if (!v || !url) return;
    window.__ytlExtStream = url;
    while (v.firstChild) v.removeChild(v.firstChild);
    var s = document.createElement('source');
    s.src = url;
    s.type = 'video/mp4';
    v.appendChild(s);
    // Cobalt tunnels are one-shot: only load() when Play asks (doLoad=true).
    // blob: / media.php are safe to load early.
    if (doLoad || String(url).indexOf('blob:') === 0) {
      try { v.load(); } catch (e2) {}
    }
  }

  function resolveFromIdbOrTunnel() {
    return idbGetBlob().then(function (blob) {
      applySrc(URL.createObjectURL(blob), true);
      window.__ytlNeedTunnel = false;
      return 'idb';
    }).catch(function () {
      // Do NOT attach sessionStorage tunnel on page load — it is often already
      // burned (prefetch / prior fetch). Remint on Play instead.
      window.__ytlNeedTunnel = true;
      return 'defer_tunnel';
    });
  }

  function resolveExtSrc() {
    // 1) Same-origin file may have landed after convert PUT — prefer it.
    return fetch(localMediaUrl, { method: 'HEAD', credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
      if (r.ok) {
        window.location.replace(<?php echo json_encode(ytl_url('watch.php', ['v' => $videoId, 'q' => $quality, 'play' => '1'])); ?>);
        return 'local';
      }
      return resolveFromIdbOrTunnel();
    }).catch(function () {
      return resolveFromIdbOrTunnel().catch(function () {
        window.__ytlNeedTunnel = true;
        return 'defer_tunnel';
      });
    });
  }

  function probeTunnel(url) {
    return fetchTimeout(url, { mode: 'cors', cache: 'no-store' }, 20000).then(function (r) {
      if (!r || !r.ok) throw new Error('tunnel_http');
      var cl = parseInt(r.headers.get('content-length') || '-1', 10);
      if (cl === 0) throw new Error('tunnel_empty');
      return r.blob().then(function (b) {
        if (!b || b.size < 12000) throw new Error('tunnel_empty');
        return b;
      });
    });
  }

  // Called from Play button: prefer blob; else mint tunnel, download to blob (one-shot safe).
  window.__ytlEnsurePlayableSrc = function () {
    var v = document.getElementById('watch-video');
    var srcEl = v && v.querySelector('source');
    var cur = (srcEl && srcEl.getAttribute('src')) || (v && (v.currentSrc || v.src)) || '';
    if (cur.indexOf('blob:') === 0 || cur.indexOf('media.php') >= 0) {
      if (v && v.readyState < 1) {
        try { v.load(); } catch (e0) {}
      }
      return Promise.resolve('localish');
    }
    function mintToBlob(attempt) {
      return mintFreshTunnel().then(function (fresh) {
        return probeTunnel(fresh).then(function (blob) {
          return idbPutBlob(blob).catch(function () { return false; }).then(function () {
            applySrc(URL.createObjectURL(blob), true);
            window.__ytlNeedTunnel = false;
            return 'tunnel_blob';
          });
        });
      }).catch(function (err) {
        if (attempt < 1) return mintToBlob(attempt + 1);
        throw err;
      });
    }
    return mintToBlob(0);
  };

  window.__ytlResolveExt = resolveExtSrc;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { resolveExtSrc(); });
  } else {
    resolveExtSrc();
  }
})();
</script>
<?php endif; ?>


<table class="two-col watch-layout" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr>
<td class="main-col">

  <div class="watch-headline">
    <h1 class="watch-title" id="watch-title"><?php echo ytl_h(ytl_t('loading_meta', $locale)); ?></h1>
    <table class="watch-uploader" id="watch-uploader" cellpadding="0" cellspacing="0" border="0" style="display:none;">
      <tr>
        <td class="channel-avatar" width="48" valign="middle" id="watch-avatar-cell" style="display:none;">
          <a id="watch-avatar-link" href="#"><img id="watch-avatar" src="" alt="" width="48" height="48" border="0"></a>
        </td>
        <td valign="middle" class="watch-uploader-meta">
          <a class="channel-name" id="watch-author" href="#">Channel</a>
          <span class="muted" id="watch-subs" style="display:none;"></span>
          <div class="watch-channel-actions" id="watch-channel-actions" style="display:none;">
            <a class="yt-btn yt-btn-channel" id="watch-channel-btn" href="#">View channel</a>
            <?php if ($ytLoggedIn): ?>
              <button type="button" class="yt-btn yt-btn-subscribe" id="watch-subscribe-btn">Subscribe</button>
            <?php else: ?>
              <a class="yt-btn yt-btn-subscribe" href="tv_signin.php" title="Sign in to subscribe">Subscribe</a>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    </table>
  </div>

  <?php ytl_render_classic_player('mp4', $playUrl, $mime, $poster, 'Video', $videoId); ?>

  <div class="watch-toolbar">
    <?php if ($ytLoggedIn): ?>
      <button type="button" class="yt-btn yt-btn-like" id="btn-like" title="Like">&#9650; Like <b id="watch-likes"><?php echo ytl_h(ytl_t('loading_meta', $locale)); ?></b></button>
      <button type="button" class="yt-btn yt-btn-dislike" id="btn-dislike" title="Dislike">&#9660; <b id="watch-dislikes"></b></button>
    <?php else: ?>
      <span class="yt-btn yt-btn-like" title="Sign in to like">&#9650; Like <b id="watch-likes"><?php echo ytl_h(ytl_t('loading_meta', $locale)); ?></b></span>
      <span class="yt-btn yt-btn-dislike" title="Sign in to dislike">&#9660; <b id="watch-dislikes"></b></span>
      <a class="yt-btn" href="tv_signin.php">Sign in to like</a>
    <?php endif; ?>
    <a class="yt-btn" href="<?php echo ytl_h($downloadUrl); ?>">Download</a>
    <a class="yt-btn" href="<?php echo ytl_h($openUrl); ?>" target="_blank">Open</a>
    <span class="muted watch-quality" id="watch-views" style="display:none;"></span>
    <form method="get" action="watch.php" class="quality-form">
      <input type="hidden" name="v" value="<?php echo ytl_h($videoId); ?>">
      <input type="hidden" name="play" value="1">
      <label for="q-ready"><?php echo ytl_h(ytl_t('quality', $locale)); ?></label>
      <select name="q" id="q-ready" onchange="this.form.submit()">
        <?php foreach (ytl_convert_qualities() as $qOpt): ?>
          <option value="<?php echo (int) $qOpt; ?>"<?php echo $qOpt === $quality ? ' selected="selected"' : ''; ?>><?php echo ytl_h(ytl_convert_quality_label($qOpt)); ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <div class="watch-panel">
    <div class="watch-upload-line" id="watch-upload-line"><?php echo ytl_h(ytl_t('loading_meta', $locale)); ?></div>
    <div class="description-box muted" id="watch-desc"><?php echo ytl_h(ytl_t('loading_desc', $locale)); ?></div>
    <div class="description-box" id="watch-desc-full" style="display:none;"></div>
    <div class="watch-show-more" id="watch-more-wrap" style="display:none;"><a href="#watch-desc" id="watch-more-btn">Show more</a></div>
  </div>

  <div class="comments-header">
    <h2>All Comments <span class="muted" id="watch-comment-count"></span></h2>
  </div>
  <?php if ($ytLoggedIn): ?>
    <div class="comment-compose" id="comment-compose">
      <textarea id="comment-text" rows="3" cols="60" placeholder="Add a public comment…"></textarea>
      <div class="comment-compose-actions">
        <button type="button" class="yt-btn" id="comment-post-btn">Post</button>
        <span class="muted" id="comment-status"></span>
      </div>
    </div>
  <?php else: ?>
    <p class="muted"><a href="tv_signin.php">Sign in</a> to post a comment.</p>
  <?php endif; ?>
  <div id="watch-comments-status" class="muted"><?php echo ytl_h(ytl_t('loading_comments', $locale)); ?></div>
  <div id="comment-list" class="comment-list" style="display:none;"></div>

  <script type="text/javascript">
  (function () {
    var metaUrl = <?php echo json_encode($metaUrl); ?>;
    var commentsUrl = <?php echo json_encode($commentsUrl); ?>;
    var videoId = <?php echo json_encode($videoId); ?>;
    var loggedIn = <?php echo $ytLoggedIn ? 'true' : 'false'; ?>;
    var csrf = <?php echo json_encode($ytCsrf); ?>;
    var apiUserUrl = <?php echo json_encode(ytl_url('api_user.php')); ?>;
    var channelId = '';
    var refreshUserStatus = null;
    var i18n = <?php echo json_encode([
        'loadingDesc' => ytl_t('loading_desc', $locale),
        'loadingComments' => ytl_t('loading_comments', $locale),
        'noDesc' => ytl_t('no_description', $locale),
        'noComments' => ytl_t('no_comments', $locale),
        'commentsOff' => ytl_t('comments_off', $locale),
        'commentsUnavailable' => ytl_t('comments_unavailable', $locale),
    ], JSON_UNESCAPED_UNICODE); ?>;

    function esc(s) {
      var d = document.createElement('div');
      d.appendChild(document.createTextNode(s == null ? '' : String(s)));
      return d.innerHTML;
    }
    function text(el, s) {
      if (el) el.innerHTML = esc(s);
    }
    function show(el, on) {
      if (el) el.style.display = on ? '' : 'none';
    }

    function fillComments(data) {
      var status = document.getElementById('watch-comments-status');
      var list = document.getElementById('comment-list');
      var countEl = document.getElementById('watch-comment-count');
      if (data.commentCountLabel) {
        text(countEl, '(' + data.commentCountLabel + ')');
      }
      if (data.commentsDisabled) {
        text(status, i18n.commentsOff);
        show(list, false);
        return;
      }
      if (data.commentsError && (!data.comments || !data.comments.length)) {
        text(status, i18n.commentsUnavailable);
        show(list, false);
        return;
      }
      var rows = data.comments || [];
      if (!rows.length) {
        text(status, i18n.noComments);
        show(list, false);
        return;
      }
      status.innerHTML = '';
      show(status, false);
      var html = '';
      for (var i = 0; i < rows.length; i++) {
        var c = rows[i];
        html += '<div class="comment">';
        html += '<div class="comment-text">' + esc(c.text || '') + '</div>';
        html += '<div class="comment-meta">';
        if (c.authorUrl) {
          html += '<a href="' + esc(c.authorUrl) + '">' + esc(c.author || 'Channel') + '</a>';
        } else {
          html += '<a href="#">' + esc(c.author || '') + '</a>';
        }
        if (c.publishedText) html += '<span class="muted"> ' + esc(c.publishedText) + '</span>';
        if (c.likeLabel) html += '<span class="comment-likes"> &#9650; ' + esc(c.likeLabel) + '</span>';
        if (c.pinned) html += ' <span class="muted">· Pinned</span>';
        if (c.replyCount > 0) html += ' <span class="muted">· ' + esc(String(c.replyCount)) + ' replies</span>';
        html += '</div></div>';
      }
      list.innerHTML = html;
      show(list, true);
    }

    function fillRelated(rows) {
      var side = document.getElementById('watch-related');
      if (!side || !rows || !rows.length) return;
      var html = '<div class="shelf-title">Suggestions <span class="arrow">&raquo;</span></div>';
      html += '<table class="results watch-related" width="100%" cellpadding="4" cellspacing="0" border="0">';
      for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        html += '<tr><td width="120" valign="top">';
        html += '<a class="related-thumb" href="' + esc(r.watch) + '"><img src="' + esc(r.thumb) + '" width="120" height="68" border="0" alt=""></a>';
        html += '</td><td valign="top">';
        html += '<a href="' + esc(r.watch) + '"><b>' + esc(r.title) + '</b></a><br>';
        if (r.author) html += '<span class="muted">' + esc(r.author) + '</span><br>';
        if (r.views) html += '<span class="muted">' + esc(r.views) + ' views</span>';
        if (r.length) html += ' <span class="muted">' + esc(r.length) + '</span>';
        html += '</td></tr>';
      }
      html += '</table>';
      side.innerHTML = html;
    }

    function applyMeta(data) {
      if (!data) return;
      var title = data.title || 'Video';
      text(document.getElementById('watch-title'), title);
      try { document.title = title + ' - Pixr Lite'; } catch (e) {}

      likeCount = parseInt(data.likeCount, 10);
      if (isNaN(likeCount) || likeCount < 0) likeCount = 0;
      dislikeCount = parseInt(data.dislikeCount, 10);
      if (isNaN(dislikeCount) || dislikeCount < 0) dislikeCount = 0;
      paintCounts();

      var views = document.getElementById('watch-views');
      if (data.views) {
        text(views, data.views + ' views');
        show(views, true);
      }

      var uploader = document.getElementById('watch-uploader');
      if (data.author) {
        show(uploader, true);
        var authorEl = document.getElementById('watch-author');
        text(authorEl, data.author);
        if (data.authorId && /^UC[A-Za-z0-9_-]{20,}$/.test(String(data.authorId))) {
          channelId = String(data.authorId);
        } else if (!channelId && data.channelUrl) {
          var mUc = String(data.channelUrl).match(/[?&]id=(UC[A-Za-z0-9_-]{20,})/);
          if (mUc) channelId = mUc[1];
        }
        var channelHref = data.channelUrl || (channelId ? ('channel.php?id=' + encodeURIComponent(channelId)) : '');
        if (channelHref) {
          authorEl.href = channelHref;
          var chBtn = document.getElementById('watch-channel-btn');
          if (chBtn) chBtn.href = channelHref;
          show(document.getElementById('watch-channel-actions'), true);
        } else {
          // Still show Subscribe / Sign-in row even without a channel link yet.
          show(document.getElementById('watch-channel-actions'), true);
        }
        if (channelId && typeof refreshUserStatus === 'function') refreshUserStatus();
        var av = String(data.avatar || '').trim();
        var avAlts = [];
        if (data.avatarAlts && data.avatarAlts.length) {
          for (var ai = 0; ai < data.avatarAlts.length; ai++) {
            var au = String(data.avatarAlts[ai] || '').trim();
            if (au && au !== av) avAlts.push(au);
          }
        }
        function paintAvatar(queue, href) {
          if (!queue || !queue.length) return;
          var avImg = document.getElementById('watch-avatar');
          var avLink = document.getElementById('watch-avatar-link');
          if (!avImg || !avLink) return;
          var avIdx = 0;
          avImg.onerror = function () {
            avIdx++;
            if (avIdx < queue.length) {
              avImg.src = queue[avIdx];
              return;
            }
            show(document.getElementById('watch-avatar-cell'), false);
          };
          avImg.src = queue[0];
          avLink.href = href || '#';
          show(document.getElementById('watch-avatar-cell'), true);
        }
        // Accept absolute http(s) or relative proxy.php / path (never leave empty img).
        if (av !== '' && av.indexOf('watch.php') < 0 && av !== '#') {
          paintAvatar([av].concat(avAlts), channelHref);
        } else if (channelId) {
          // Browser-side avatar when OVH meta returned none.
          fetchChannelAvatar(channelId, function (url) {
            if (url) paintAvatar([url], channelHref);
          });
        }
        if (data.subs) {
          text(document.getElementById('watch-subs'), ' · ' + data.subs + ' subscribers');
          show(document.getElementById('watch-subs'), true);
        }
      }

      var uploadLine = document.getElementById('watch-upload-line');
      var line = '';
      if (data.author) {
        line = 'Uploaded by ';
        if (data.channelUrl) line += '<a href="' + esc(data.channelUrl) + '">' + esc(data.author) + '</a>';
        else line += esc(data.author);
      }
      if (data.published) line += (line ? ' ' : '') + 'on ' + esc(data.published);
      uploadLine.innerHTML = line;

      var descEl = document.getElementById('watch-desc');
      var fullEl = document.getElementById('watch-desc-full');
      var moreWrap = document.getElementById('watch-more-wrap');
      var desc = data.description || '';
      function descHtml(s) {
        return esc(s).replace(/\n/g, '<br>');
      }
      descEl.className = 'description-box';
      if (!desc) {
        descEl.className = 'description-box muted';
        text(descEl, i18n.noDesc);
        show(moreWrap, false);
      } else if (desc.length > 280) {
        descEl.innerHTML = descHtml(desc.substring(0, 280) + '\u2026');
        fullEl.innerHTML = descHtml(desc);
        show(moreWrap, true);
        var btn = document.getElementById('watch-more-btn');
        var open = false;
        btn.onclick = function () {
          open = !open;
          show(descEl, !open);
          show(fullEl, open);
          btn.innerHTML = open ? 'Show less' : 'Show more';
          return false;
        };
      } else {
        descEl.innerHTML = descHtml(desc);
        show(moreWrap, false);
      }

      fillRelated(data.related || []);
    }

    function fetchChannelAvatar(cid, cb) {
      if (!cid || !window.fetch) { cb(''); return; }
      var hosts = [
        'https://yt.chocolatemoo53.com',
        'https://invidious.tiekoetter.com',
        'https://yewtu.be',
        'https://inv.nadeko.net'
      ];
      try {
        if (window.YtlSettings) {
          var s = YtlSettings.get();
          if (s && s.meta && s.meta.length) hosts = s.meta.concat(hosts);
        }
      } catch (eH) {}
      var seen = {};
      var list = [];
      for (var i = 0; i < hosts.length; i++) {
        var h = String(hosts[i] || '').replace(/\/$/, '');
        if (!h || seen[h]) continue;
        seen[h] = 1;
        list.push(h);
      }
      var idx = 0;
      function next() {
        if (idx >= list.length) { cb(''); return; }
        var base = list[idx++];
        fetch(base + '/api/v1/channels/' + encodeURIComponent(cid), {
          mode: 'cors',
          cache: 'no-store',
          headers: { Accept: 'application/json' }
        }).then(function (r) { return r.json(); }).then(function (j) {
          var thumbs = (j && j.authorThumbnails) ? j.authorThumbnails : [];
          var best = '';
          for (var t = 0; t < thumbs.length; t++) {
            var u = thumbs[t] && thumbs[t].url ? String(thumbs[t].url) : '';
            if (!u) continue;
            if (u.indexOf('//') === 0) u = 'https:' + u;
            best = u;
            if ((thumbs[t].width || 0) >= 88) break;
          }
          if (!best && j && j.avatarUrl) best = String(j.avatarUrl);
          if (best) cb(best);
          else next();
        }).catch(next);
      }
      next();
    }

    function getJson(url, cb) {
      var xhr = window.XMLHttpRequest ? new XMLHttpRequest() : null;
      if (!xhr) { cb(null); return; }
      xhr.open('GET', url + (url.indexOf('?') >= 0 ? '&' : '?') + '_=' + new Date().getTime(), true);
      xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        var data = null;
        try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
        cb(data);
      };
      try { xhr.send(null); } catch (e) { cb(null); }
    }

    // Prefetch during prepare → instant meta/comments/suggestions on play.
    try {
      if (window.sessionStorage) {
        var cachedMeta = sessionStorage.getItem('ytl_watch_meta_' + videoId);
        if (cachedMeta) {
          var preMeta = JSON.parse(cachedMeta);
          var hasRelated = preMeta && preMeta.related && preMeta.related.length;
          if (preMeta && preMeta.ok !== false && (hasRelated || (preMeta.avatar || '') || (preMeta.description || '') || (preMeta.title || ''))) {
            applyMeta(preMeta);
          } else {
            sessionStorage.removeItem('ytl_watch_meta_' + videoId);
          }
        }
        var cachedCom = sessionStorage.getItem('ytl_watch_comments_' + videoId);
        if (cachedCom) {
          var preCom = JSON.parse(cachedCom);
          if (preCom) fillComments(preCom);
        }
      }
    } catch (eCache) {}

    function htmlToPlain(html) {
      try {
        var d = document.createElement('div');
        d.innerHTML = String(html || '').replace(/<br\s*\/?>/gi, '\n');
        return String(d.textContent || d.innerText || '').replace(/\n{3,}/g, '\n\n').trim();
      } catch (e) {
        return '';
      }
    }

    // OVH often cannot reach Invidious (403) — pull missing bits from the visitor browser.
    function mapRelatedFromInv(rec) {
      var out = [];
      if (!rec || !rec.length) return out;
      for (var i = 0; i < rec.length && out.length < 8; i++) {
        var rv = rec[i];
        if (!rv || !rv.videoId) continue;
        var rid = String(rv.videoId);
        var thumb = '';
        try {
          var th = rv.videoThumbnails || [];
          for (var t = 0; t < th.length; t++) {
            if (th[t] && th[t].url) { thumb = String(th[t].url); break; }
          }
        } catch (eT) {}
        if (!thumb) thumb = 'https://i.ytimg.com/vi/' + rid + '/mqdefault.jpg';
        out.push({
          videoId: rid,
          title: String(rv.title || 'Video'),
          author: String(rv.author || ''),
          thumb: thumb,
          watch: 'watch.php?v=' + encodeURIComponent(rid),
          views: '',
          length: ''
        });
      }
      return out;
    }

    function enrichMetaClient(data, done) {
      if (!data) data = { ok: true };
      var needDesc = !(data.description || '');
      var needAv = !(data.avatar || '');
      var needRel = !(data.related && data.related.length);
      if (!needDesc && !needAv && !needRel) {
        done(data);
        return;
      }
      var hosts = [
        'https://yt.chocolatemoo53.com',
        'https://invidious.tiekoetter.com',
        'https://yewtu.be',
        'https://inv.nadeko.net'
      ];
      try {
        if (window.YtlSettings) {
          var s = YtlSettings.get();
          if (s && s.meta && s.meta.length) hosts = s.meta.concat(hosts);
          if (s && s.invidious && s.invidious.length) hosts = s.invidious.concat(hosts);
        }
      } catch (e0) {}
      var seen = {};
      var list = [];
      for (var i = 0; i < hosts.length; i++) {
        var h = String(hosts[i] || '').replace(/\/$/, '');
        if (!h || seen[h]) continue;
        seen[h] = 1;
        list.push(h);
      }
      var idx = 0;
      function finished() {
        return (!(needDesc && !(data.description || '')))
          && (!(needAv && !(data.avatar || '')))
          && (!(needRel && !(data.related && data.related.length)));
      }
      function next() {
        if (idx >= list.length || finished()) {
          done(data);
          return;
        }
        var base = list[idx++];
        if (!window.fetch) {
          done(data);
          return;
        }
        var ctrl = window.AbortController ? new AbortController() : null;
        var timer = setTimeout(function () { try { if (ctrl) ctrl.abort(); } catch (e1) {} }, 3500);
        fetch(base + '/api/v1/videos/' + encodeURIComponent(videoId), {
          mode: 'cors',
          cache: 'no-store',
          signal: ctrl ? ctrl.signal : undefined,
          headers: { Accept: 'application/json' }
        }).then(function (r) {
          return r.json().then(function (j) { return { ok: r.ok, j: j }; });
        }).then(function (res) {
          clearTimeout(timer);
          var j = res && res.j ? res.j : null;
          if (!j || !j.title) throw new Error('meta');
          if (!(data.description || '')) {
            var d = String(j.description || '');
            if (!d) d = htmlToPlain(j.descriptionHtml || '');
            if (d) data.description = d;
          }
          if (!(data.avatar || '')) {
            var thumbs = j.authorThumbnails || [];
            var best = '';
            for (var t = 0; t < thumbs.length; t++) {
              var u = thumbs[t] && thumbs[t].url ? String(thumbs[t].url) : '';
              if (!u) continue;
              if (u.indexOf('//') === 0) u = 'https:' + u;
              best = u;
              if ((thumbs[t].width || 0) >= 88) break;
            }
            if (best) data.avatar = best;
          }
          if (!(data.related && data.related.length) && j.recommendedVideos) {
            var mapped = mapRelatedFromInv(j.recommendedVideos);
            if (mapped.length) {
              data.related = mapped;
              fillRelated(mapped);
            }
          }
          if (!(data.author || '') && j.author) data.author = String(j.author);
          if (!(data.authorId || '') && j.authorId && /^UC[A-Za-z0-9_-]{20,}$/.test(String(j.authorId))) {
            data.authorId = String(j.authorId);
            data.channelUrl = 'channel.php?id=' + encodeURIComponent(data.authorId);
          }
          if (!(data.subs || '') && j.subCount) {
            try {
              var sc = parseInt(j.subCount, 10);
              if (sc > 0) data.subs = (sc >= 1e6 ? (Math.round(sc / 1e5) / 10) + 'M' : (sc >= 1000 ? (Math.round(sc / 100) / 10) + 'K' : String(sc)));
            } catch (eSub) {}
          }
          next();
        }).catch(function () {
          clearTimeout(timer);
          next();
        });
      }
      next();
    }

    getJson(metaUrl, function (data) {
      if (!data || data.ok === false) {
        if (!document.getElementById('watch-title').innerHTML || document.getElementById('watch-title').innerHTML.indexOf('…') >= 0 || document.getElementById('watch-title').innerHTML === <?php echo json_encode(ytl_t('loading_meta', $locale)); ?>) {
          text(document.getElementById('watch-title'), 'Video');
        }
        enrichMetaClient({ ok: true, title: 'Video' }, function (enriched) {
          if (enriched) applyMeta(enriched);
        });
        return;
      }
      // Show suggestions immediately — do not wait for avatar/description enrich.
      applyMeta(data);
      enrichMetaClient(data, function (enriched) {
        applyMeta(enriched);
        try { if (window.sessionStorage) sessionStorage.setItem('ytl_watch_meta_' + videoId, JSON.stringify(enriched)); } catch (eS) {}
      });
    });

    getJson(commentsUrl, function (data) {
      if (!data) {
        var st = document.getElementById('watch-comments-status');
        if (st && st.style.display !== 'none' && (!st.innerHTML || st.innerHTML === i18n.loadingComments)) {
          text(st, i18n.commentsUnavailable);
        }
        return;
      }
      fillComments(data);
      try { if (window.sessionStorage) sessionStorage.setItem('ytl_watch_comments_' + videoId, JSON.stringify(data)); } catch (eC) {}
    });

    function postUserAction(payload, cb) {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', apiUserUrl, true);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.setRequestHeader('X-CSRF', csrf);
      xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        var data = null;
        try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
        cb(data);
      };
      payload.csrf = csrf;
      xhr.send(JSON.stringify(payload));
    }

    var likeCount = 0;
    var dislikeCount = 0;

    function formatCount(n) {
      n = parseInt(n, 10) || 0;
      if (n <= 0) return '';
      if (n >= 1000000000) return (Math.round(n / 100000000) / 10) + 'B';
      if (n >= 1000000) return (Math.round(n / 100000) / 10) + 'M';
      if (n >= 1000) return (Math.round(n / 100) / 10) + 'K';
      return String(n);
    }
    function paintCounts() {
      text(document.getElementById('watch-likes'), formatCount(likeCount));
      text(document.getElementById('watch-dislikes'), formatCount(dislikeCount));
    }

    if (loggedIn) {
      var likeBtn = document.getElementById('btn-like');
      var dislikeBtn = document.getElementById('btn-dislike');
      var myRating = 'none';
      var subscriptionId = '';
      var subscribed = false;

      function paintRating() {
        if (likeBtn) {
          likeBtn.className = 'yt-btn yt-btn-like' + (myRating === 'like' ? ' yt-btn-active' : '');
          likeBtn.title = myRating === 'like' ? 'Remove like' : 'Like';
        }
        if (dislikeBtn) {
          dislikeBtn.className = 'yt-btn yt-btn-dislike' + (myRating === 'dislike' ? ' yt-btn-active' : '');
          dislikeBtn.title = myRating === 'dislike' ? 'Remove dislike' : 'Dislike';
        }
      }

      // Adjust displayed totals locally (YouTube/RYD public counts don't refresh instantly).
      function applyRatingDelta(from, to) {
        if (from === to) return;
        if (from === 'like') likeCount = Math.max(0, likeCount - 1);
        if (from === 'dislike') dislikeCount = Math.max(0, dislikeCount - 1);
        if (to === 'like') likeCount += 1;
        if (to === 'dislike') dislikeCount += 1;
        paintCounts();
      }
      function paintSub() {
        var subBtn = document.getElementById('watch-subscribe-btn');
        if (!subBtn) return;
        if (subscribed) {
          subBtn.innerHTML = 'Subscribed';
          subBtn.className = 'yt-btn yt-btn-subscribe yt-btn-active';
          subBtn.disabled = false;
          subBtn.title = 'Unsubscribe';
        } else {
          subBtn.innerHTML = 'Subscribe';
          subBtn.className = 'yt-btn yt-btn-subscribe';
          subBtn.disabled = false;
          subBtn.title = 'Subscribe';
        }
      }

      refreshUserStatus = function () {
        var payload = { action: 'status', videoId: videoId };
        if (channelId) payload.channelId = channelId;
        postUserAction(payload, function (res) {
          if (!res || !res.ok) return;
          myRating = res.rating || 'none';
          subscribed = !!res.subscribed;
          subscriptionId = res.subscriptionId || '';
          paintRating();
          paintSub();
        });
      };

      if (likeBtn) {
        likeBtn.onclick = function () {
          var prev = myRating;
          var nextAction = myRating === 'like' ? 'unlike' : 'like';
          var nextRating = nextAction === 'like' ? 'like' : 'none';
          likeBtn.disabled = true;
          postUserAction({ action: nextAction, videoId: videoId }, function (res) {
            likeBtn.disabled = false;
            if (res && res.ok) {
              applyRatingDelta(prev, nextRating);
              myRating = nextRating;
              paintRating();
            } else {
              alert((res && res.error) || 'Like failed');
            }
          });
        };
      }
      if (dislikeBtn) {
        dislikeBtn.onclick = function () {
          var prev = myRating;
          var nextAction = myRating === 'dislike' ? 'undislike' : 'dislike';
          var nextRating = nextAction === 'dislike' ? 'dislike' : 'none';
          dislikeBtn.disabled = true;
          postUserAction({ action: nextAction, videoId: videoId }, function (res) {
            dislikeBtn.disabled = false;
            if (res && res.ok) {
              applyRatingDelta(prev, nextRating);
              myRating = nextRating;
              paintRating();
            } else {
              alert((res && res.error) || 'Dislike failed');
            }
          });
        };
      }
      var subBtn = document.getElementById('watch-subscribe-btn');
      if (subBtn) {
        subBtn.onclick = function () {
          if (!channelId) { alert('Channel not loaded yet'); return; }
          var next = subscribed ? 'unsubscribe' : 'subscribe';
          subBtn.disabled = true;
          postUserAction({
            action: next,
            channelId: channelId,
            subscriptionId: subscriptionId
          }, function (res) {
            subBtn.disabled = false;
            if (res && res.ok) {
              subscribed = next === 'subscribe';
              subscriptionId = res.subscriptionId || '';
              paintSub();
            } else {
              alert((res && res.error) || (subscribed ? 'Unsubscribe failed' : 'Subscribe failed'));
            }
          });
        };
      }
      // Load current rating; subscription refreshed again once channelId is known.
      refreshUserStatus();
      var postBtn = document.getElementById('comment-post-btn');
      var commentText = document.getElementById('comment-text');
      var commentStatus = document.getElementById('comment-status');
      if (postBtn && commentText) {
        postBtn.onclick = function () {
          var t = commentText.value.replace(/^\s+|\s+$/g, '');
          if (!t) { text(commentStatus, 'Write a comment first'); return; }
          postBtn.disabled = true;
          text(commentStatus, 'Posting…');
          postUserAction({ action: 'comment', videoId: videoId, text: t }, function (res) {
            postBtn.disabled = false;
            if (res && res.ok) {
              commentText.value = '';
              text(commentStatus, 'Posted');
              getJson(commentsUrl, function (data) { if (data) fillComments(data); });
            } else {
              text(commentStatus, (res && res.error) || 'Failed');
            }
          });
        };
      }
    }
  })();
  </script>

</td>
<td class="side-col" id="watch-related">
  <div class="muted"><?php echo ytl_h(ytl_t('loading_meta', $locale)); ?></div>
</td>
</tr>
</table>

<?php
ytl_layout_end();

