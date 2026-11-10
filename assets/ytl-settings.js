/**
 * Browser-side server overrides (localStorage + cookie ytl_servers).
 * Used by Settings page and watch.php convert/meta lists.
 */
(function (global) {
  var COOKIE = 'ytl_servers';
  var LS = 'ytl_servers';

  function parseList(raw) {
    if (Array.isArray(raw)) {
      return raw.map(function (u) { return String(u || '').trim(); }).filter(Boolean);
    }
    return String(raw || '')
      .split(/[\s,;]+/)
      .map(function (u) { return u.trim(); })
      .filter(Boolean);
  }

  function normalizeUrl(u) {
    u = String(u || '').trim();
    if (!u) return '';
    // Allow pasting full paths — strip common API suffixes to a host base.
    if (!/^https?:\/\//i.test(u)) u = 'https://' + u.replace(/^\/+/, '');
    u = u.replace(/\/+$/, '');
    u = u.replace(/\/(api\/v1|api|youtubei)(\/.*)?$/i, '');
    u = u.replace(/\/tunnel.*$/i, '');
    u = u.replace(/\/+$/, '');
    if (!/^https?:\/\/[A-Za-z0-9._:-]+/i.test(u)) return '';
    return u;
  }

  function normalize(data) {
    data = data || {};
    var meta = parseList(data.meta || data.meta_servers).map(normalizeUrl).filter(Boolean);
    var convert = parseList(data.convert || data.convert_servers).map(normalizeUrl).filter(Boolean);
    var inv = parseList(data.invidious || data.inv_servers).map(normalizeUrl).filter(Boolean);
    // Dedupe, cap length for cookie size.
    function uniq(arr) {
      var out = [];
      var seen = {};
      for (var i = 0; i < arr.length && out.length < 12; i++) {
        if (seen[arr[i]]) continue;
        seen[arr[i]] = 1;
        out.push(arr[i]);
      }
      return out;
    }
    return {
      meta: uniq(meta),
      convert: uniq(convert),
      invidious: uniq(inv.length ? inv : meta.slice(0, 6))
    };
  }

  function readCookie() {
    try {
      var m = document.cookie.match(/(?:^|;\s*)ytl_servers=([^;]*)/);
      if (!m) return null;
      return JSON.parse(decodeURIComponent(m[1]));
    } catch (e) {
      return null;
    }
  }

  function readLocal() {
    try {
      if (!global.localStorage) return null;
      var raw = localStorage.getItem(LS);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  function get() {
    return normalize(readLocal() || readCookie() || {});
  }

  function save(data) {
    var s = normalize(data);
    var payload = JSON.stringify(s);
    try {
      if (global.localStorage) localStorage.setItem(LS, payload);
    } catch (e) {}
    try {
      var maxAge = 86400 * 400;
      var secure = location.protocol === 'https:' ? ';Secure' : '';
      document.cookie = COOKIE + '=' + encodeURIComponent(payload)
        + ';Path=/;Max-Age=' + maxAge + ';SameSite=Lax' + secure;
    } catch (e2) {}
    return s;
  }

  function mergeInto(list, preferred) {
    preferred = preferred || [];
    list = list || [];
    var out = [];
    var seen = {};
    function add(u) {
      u = normalizeUrl(u);
      if (!u || seen[u]) return;
      seen[u] = 1;
      out.push(u);
    }
    for (var i = 0; i < preferred.length; i++) add(preferred[i]);
    for (var j = 0; j < list.length; j++) add(list[j]);
    return out;
  }

  global.YtlSettings = {
    get: get,
    save: save,
    normalize: normalize,
    mergeInto: mergeInto,
    normalizeUrl: normalizeUrl
  };
})(window);
