<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/oauth.php';

ytl_session_start();
$client = ytl_client();
$user = ytl_current_user();
$ytLoggedIn = $user !== null;
$ytCsrf = $ytLoggedIn ? ytl_oauth_csrf_token() : '';

$id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$continuation = isset($_GET['continuation']) ? trim((string) $_GET['continuation']) : null;

if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{10,64}$/', $id)) {
    ytl_layout_start('Channel', '', 'all', 'videos', true);
    echo '<div class="error-box">Missing or invalid channel id.</div>';
    ytl_layout_end();
    exit;
}

$channel = $client->channel($id);
$bundle = $client->channelVideos($id, $continuation !== '' ? $continuation : null);
$videos = is_array($bundle['videos'] ?? null) ? $bundle['videos'] : [];
$hideLive = !empty(ytl_config()['hide_live']);
$videos = ytl_filter_videos($videos, $hideLive);
$next = $bundle['continuation'] ?? null;

$name = is_array($channel) ? (string) ($channel['author'] ?? $channel['name'] ?? $id) : $id;
$desc = is_array($channel) ? (string) ($channel['description'] ?? '') : '';
$subs = is_array($channel) && isset($channel['subCount'])
    ? InvidiousClient::formatViews($channel['subCount']) . ' subscribers'
    : '';
$avatar = is_array($channel) ? ytl_channel_avatar($channel) : '';
$verified = is_array($channel) && !empty($channel['verified']);

ytl_layout_start($name, '', 'all', 'videos', true);

?>
<div class="channel-header">
  <table cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td width="56" valign="top" id="channel-avatar-cell" style="padding-right:10px;<?php echo $avatar === '' ? 'display:none;' : ''; ?>">
        <img id="channel-avatar-img" src="<?php echo $avatar !== '' ? ytl_h($avatar) : ''; ?>" alt="" width="48" height="48" border="0"
          style="width:48px;height:48px;border-radius:50%;object-fit:cover;background:#ddd;">
      </td>
      <td valign="top">
        <div class="channel-name"><?php echo ytl_h($name); ?></div>
        <?php if ($verified): ?><div class="muted">Verified channel</div><?php endif; ?>
        <?php if ($subs !== ''): ?>
          <div class="muted" id="channel-subs"><?php echo ytl_h($subs); ?></div>
        <?php else: ?>
          <div class="muted" id="channel-subs" style="display:none;"></div>
        <?php endif; ?>
        <div class="watch-channel-actions" style="margin-top:8px;">
          <?php if ($ytLoggedIn): ?>
            <button type="button" class="yt-btn yt-btn-subscribe" id="channel-subscribe-btn">Subscribe</button>
          <?php else: ?>
            <a class="yt-btn yt-btn-subscribe" href="tv_signin.php" title="Sign in to subscribe">Subscribe</a>
          <?php endif; ?>
        </div>
      </td>
    </tr>
  </table>
  <?php if ($desc !== ''): ?>
    <div class="description-box" style="margin-top:8px;"><?php echo ytl_h($desc); ?></div>
  <?php endif; ?>
</div>

<script src="assets/ytl-settings.js?v=1" type="text/javascript"></script>
<script type="text/javascript">
(function () {
  var channelId = <?php echo json_encode($id); ?>;
  var loggedIn = <?php echo $ytLoggedIn ? 'true' : 'false'; ?>;
  var csrf = <?php echo json_encode($ytCsrf); ?>;
  var apiUserUrl = <?php echo json_encode(ytl_url('api_user.php')); ?>;
  var needAvatar = <?php echo $avatar === '' ? 'true' : 'false'; ?>;

  function fetchChannelMeta(cb) {
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
    } catch (e0) {}
    var seen = {}, list = [], i;
    for (i = 0; i < hosts.length; i++) {
      var h = String(hosts[i] || '').replace(/\/$/, '');
      if (!h || seen[h]) continue;
      seen[h] = 1;
      list.push(h);
    }
    var idx = 0;
    function next() {
      if (idx >= list.length) { cb(null); return; }
      var base = list[idx++];
      if (!window.fetch) { cb(null); return; }
      fetch(base + '/api/v1/channels/' + encodeURIComponent(channelId), {
        mode: 'cors',
        cache: 'no-store',
        headers: { Accept: 'application/json' }
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (!j || !(j.author || j.authorId)) throw new Error('meta');
        cb(j);
      }).catch(next);
    }
    next();
  }

  if (needAvatar || !document.getElementById('channel-subs').innerHTML) {
    fetchChannelMeta(function (j) {
      if (!j) return;
      var cell = document.getElementById('channel-avatar-cell');
      var img = document.getElementById('channel-avatar-img');
      var thumbs = j.authorThumbnails || [];
      var best = '';
      for (var t = 0; t < thumbs.length; t++) {
        var u = thumbs[t] && thumbs[t].url ? String(thumbs[t].url) : '';
        if (!u) continue;
        if (u.indexOf('//') === 0) u = 'https:' + u;
        best = u;
        if ((thumbs[t].width || 0) >= 88) break;
      }
      if (!best && j.avatarUrl) best = String(j.avatarUrl);
      if (best && img && cell) {
        img.src = best;
        cell.style.display = '';
      }
      if (j.subCount && document.getElementById('channel-subs')) {
        var sc = parseInt(j.subCount, 10) || 0;
        if (sc > 0) {
          var label = sc >= 1e6 ? ((Math.round(sc / 1e5) / 10) + 'M') : (sc >= 1000 ? ((Math.round(sc / 100) / 10) + 'K') : String(sc));
          var el = document.getElementById('channel-subs');
          el.innerHTML = label + ' subscribers';
          el.style.display = '';
        }
      }
    });
  }

  if (!loggedIn) return;
  var subBtn = document.getElementById('channel-subscribe-btn');
  if (!subBtn) return;
  var subscribed = false;
  var subscriptionId = '';

  function paintSub() {
    if (subscribed) {
      subBtn.innerHTML = 'Subscribed';
      subBtn.className = 'yt-btn yt-btn-subscribe yt-btn-active';
      subBtn.title = 'Unsubscribe';
    } else {
      subBtn.innerHTML = 'Subscribe';
      subBtn.className = 'yt-btn yt-btn-subscribe';
      subBtn.title = 'Subscribe';
    }
  }

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
    try { xhr.send(JSON.stringify(payload)); } catch (e2) { cb(null); }
  }

  postUserAction({ action: 'status', channelId: channelId }, function (res) {
    if (res && res.ok) {
      subscribed = !!res.subscribed;
      subscriptionId = res.subscriptionId || '';
      paintSub();
    }
  });

  subBtn.onclick = function () {
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
        alert((res && res.error) || 'Subscribe failed');
      }
    });
  };
})();
</script>

<h2>Videos</h2>
<?php
if (count($videos) === 0) {
    echo '<div class="error-box">No videos found for this channel.</div>';
} else {
    ytl_render_video_table($videos);
}

if ($next) {
    echo '<div class="pager"><a href="' . ytl_h(ytl_url('channel.php', [
        'id' => $id,
        'continuation' => $next,
    ])) . '">More videos &raquo;</a></div>';
}

ytl_layout_end_from_client($client);
