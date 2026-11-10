<?php
/**
 * External servers — simple primary URL per role + optional extras.
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/settings.php';

$defaults = ytl_settings_defaults();
$current = ytl_settings_get();
$saved = false;
$err = '';

/**
 * Build list: primary first, then extras (textarea), skip empties / dupes.
 *
 * @return list<string>
 */
function ytl_settings_combine_primary_extras(string $primary, string $extras): array
{
    $raw = trim($primary) . "\n" . $extras;
    return ytl_settings_parse_list($raw);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'reset') {
        ytl_settings_save_cookie($defaults);
        $current = $defaults;
        $saved = true;
    } else {
        $parsed = [
            'meta' => ytl_settings_combine_primary_extras(
                (string) ($_POST['meta_primary'] ?? ''),
                (string) ($_POST['meta_extra'] ?? '')
            ),
            'convert' => ytl_settings_combine_primary_extras(
                (string) ($_POST['convert_primary'] ?? ''),
                (string) ($_POST['convert_extra'] ?? '')
            ),
            'invidious' => ytl_settings_combine_primary_extras(
                (string) ($_POST['invidious_primary'] ?? ''),
                (string) ($_POST['invidious_extra'] ?? '')
            ),
        ];
        // Fall back to defaults per empty group so one field can stay blank.
        $parsed = ytl_settings_from_array($parsed);
        if ($parsed['meta'] === [] && $parsed['convert'] === []) {
            $err = 'Entre au moins une adresse https:// valide.';
        } else {
            ytl_settings_save_cookie($parsed);
            $current = $parsed;
            $saved = true;
        }
    }
}

$metaPrimary = $current['meta'][0] ?? '';
$metaExtra = array_slice($current['meta'], 1);
$convertPrimary = $current['convert'][0] ?? '';
$convertExtra = array_slice($current['convert'], 1);
$invPrimary = $current['invidious'][0] ?? '';
$invExtra = array_slice($current['invidious'], 1);

ytl_layout_start('Settings', '', 'all', 'videos', false);
?>
<div class="settings-wrap">
  <h2 class="settings-title">Paramètres — serveurs externes</h2>

  <div class="settings-guide">
    <p><strong>À quoi ça sert ?</strong> Pixr Lite ne parle pas directement à YouTube depuis le serveur OVH (souvent bloqué).
      Pour les titres / descriptions / likes et pour télécharger la vidéo, on passe par des miroirs publics
      (Invidious, Cobalt…). Quand un miroir tombe, tu peux en coller un autre ici — <em>une seule adresse suffit</em>.</p>
    <ol class="settings-steps">
      <li>Colle l’adresse de base, sans chemin : <code>https://exemple.com</code> (pas besoin de <code>/api</code>).</li>
      <li>Clique <strong>Tester</strong> pour vérifier que ton navigateur joigne le serveur (CORS OK).</li>
      <li>Clique <strong>Enregistrer</strong>. Recharge ensuite une page vidéo (ou rouvre Play).</li>
    </ol>
    <p class="muted" style="margin:0;">Les réglages restent dans <em>ce</em> navigateur (cookie + localStorage). Pas de compte serveur.</p>
  </div>

<?php if ($saved): ?>
  <div class="fallback-note settings-ok">Enregistré — recharge une vidéo pour appliquer.</div>
<?php endif; ?>
<?php if ($err !== ''): ?>
  <div class="error-box"><?php echo ytl_h($err); ?></div>
<?php endif; ?>

<form method="post" action="settings.php" id="ytl-settings-form" class="settings-form">
  <input type="hidden" name="action" value="save" id="settings-action">

  <section class="settings-card">
    <h3 class="settings-h">Titres, description, likes, commentaires</h3>
    <p class="settings-hint">Serveur <strong>Invidious</strong> (API <code>/api/v1/…</code>). Sert aussi d’avatar / description quand OVH est bloqué.</p>
    <ul class="settings-tips">
      <li>Exemple : <code>https://yt.chocolatemoo53.com</code></li>
      <li>Doit répondre en JSON sur <code>/api/v1/stats</code> ou <code>/api/v1/videos/…</code></li>
      <li>CORS ouvert → le navigateur peut lire la description même si le serveur Pixr ne peut pas.</li>
    </ul>
    <label class="settings-label" for="meta_primary">Adresse principale</label>
    <div class="settings-row">
      <input type="url" class="settings-input" name="meta_primary" id="meta_primary"
        value="<?php echo ytl_h($metaPrimary); ?>" placeholder="https://ton-invidious.exemple"
        spellcheck="false" autocomplete="off">
      <button type="button" class="lite-btn settings-test" data-kind="meta" data-input="meta_primary">Tester</button>
    </div>
    <span class="settings-test-out muted" id="meta_primary-out"></span>
    <details class="settings-more">
      <summary>Autres miroirs (optionnel, une ligne = une adresse)</summary>
      <textarea name="meta_extra" id="meta_extra" rows="3" class="settings-ta" spellcheck="false"><?php
        echo ytl_h(implode("\n", $metaExtra));
      ?></textarea>
    </details>
  </section>

  <section class="settings-card">
    <h3 class="settings-h">Téléchargement vidéo (Play / MP4)</h3>
    <p class="settings-hint">API <strong>Cobalt</strong> (ou compatible). Au clic Play, ton navigateur demande le MP4 à ce serveur.</p>
    <ul class="settings-tips">
      <li>Exemple : <code>https://rue-cobalt.xenon.zone</code></li>
      <li>Sans Turnstile / JWT (sinon le navigateur est refusé).</li>
      <li>Colle seulement l’origine : <code>https://hôte</code> — pas <code>/tunnel</code>.</li>
      <li>Si Cobalt échoue, Pixr tente Invidious (section suivante).</li>
    </ul>
    <label class="settings-label" for="convert_primary">Adresse principale</label>
    <div class="settings-row">
      <input type="url" class="settings-input" name="convert_primary" id="convert_primary"
        value="<?php echo ytl_h($convertPrimary); ?>" placeholder="https://ton-cobalt.exemple"
        spellcheck="false" autocomplete="off">
      <button type="button" class="lite-btn settings-test" data-kind="cobalt" data-input="convert_primary">Tester</button>
    </div>
    <span class="settings-test-out muted" id="convert_primary-out"></span>
    <details class="settings-more">
      <summary>Autres Cobalt (optionnel)</summary>
      <textarea name="convert_extra" id="convert_extra" rows="3" class="settings-ta" spellcheck="false"><?php
        echo ytl_h(implode("\n", $convertExtra));
      ?></textarea>
    </details>
  </section>

  <section class="settings-card">
    <h3 class="settings-h">Secours vidéo (Invidious local)</h3>
    <p class="settings-hint">Quand Cobalt échoue, le navigateur télécharge vidéo + audio via Invidious (<code>?local=true</code>) puis Pixr fusionne.</p>
    <ul class="settings-tips">
      <li>Souvent le <em>même</em> hôte que « titres / description ».</li>
      <li>Doit proxyer <code>videoplayback</code> (CORS + Range).</li>
      <li>Si tu n’as qu’un Invidious, mets-le ici <em>et</em> dans la 1ʳᵉ section.</li>
    </ul>
    <label class="settings-label" for="invidious_primary">Adresse principale</label>
    <div class="settings-row">
      <input type="url" class="settings-input" name="invidious_primary" id="invidious_primary"
        value="<?php echo ytl_h($invPrimary); ?>" placeholder="https://ton-invidious.exemple"
        spellcheck="false" autocomplete="off">
      <button type="button" class="lite-btn settings-test" data-kind="meta" data-input="invidious_primary">Tester</button>
    </div>
    <span class="settings-test-out muted" id="invidious_primary-out"></span>
    <details class="settings-more">
      <summary>Autres Invidious (optionnel)</summary>
      <textarea name="invidious_extra" id="invidious_extra" rows="3" class="settings-ta" spellcheck="false"><?php
        echo ytl_h(implode("\n", $invExtra));
      ?></textarea>
    </details>
  </section>

  <p class="settings-actions">
    <button type="submit" class="lite-btn" id="settings-save">Enregistrer</button>
    <button type="submit" class="lite-btn" id="settings-reset" onclick="document.getElementById('settings-action').value='reset';">Remettre les défauts</button>
  </p>
</form>

<details class="fallback-note" style="margin-top:16px;">
  <summary>Défauts du site (si tu remets à zéro)</summary>
  <pre class="settings-pre"><?php
    echo ytl_h(
        "Meta / Invidious:\n" . implode("\n", $defaults['meta'])
        . "\n\nConvert (Cobalt):\n" . implode("\n", $defaults['convert'])
        . "\n\nInvidious (secours):\n" . implode("\n", $defaults['invidious'])
    );
  ?></pre>
</details>
</div>

<script src="assets/ytl-settings.js?v=2" type="text/javascript"></script>
<script type="text/javascript">
(function () {
  if (!window.YtlSettings) return;
  var form = document.getElementById('ytl-settings-form');
  if (!form) return;
  var defaults = <?php echo json_encode($defaults, JSON_UNESCAPED_SLASHES); ?>;

  // Prefer fresher localStorage when opening the page.
  var local = YtlSettings.get();
  function setPrimary(id, list) {
    var el = document.getElementById(id);
    if (el && list && list[0]) el.value = list[0];
  }
  function setExtra(id, list) {
    var el = document.getElementById(id);
    if (el && list && list.length > 1) el.value = list.slice(1).join('\n');
  }
  setPrimary('meta_primary', local.meta);
  setExtra('meta_extra', local.meta);
  setPrimary('convert_primary', local.convert);
  setExtra('convert_extra', local.convert);
  setPrimary('invidious_primary', local.invidious);
  setExtra('invidious_extra', local.invidious);

  form.addEventListener('submit', function () {
    var action = document.getElementById('settings-action');
    if (action && action.value === 'reset') {
      YtlSettings.save(defaults);
      return;
    }
    YtlSettings.save({
      meta: [document.getElementById('meta_primary').value, document.getElementById('meta_extra').value].join('\n'),
      convert: [document.getElementById('convert_primary').value, document.getElementById('convert_extra').value].join('\n'),
      invidious: [document.getElementById('invidious_primary').value, document.getElementById('invidious_extra').value].join('\n')
    });
  });

  function setOut(id, msg, ok) {
    var el = document.getElementById(id + '-out');
    if (!el) return;
    el.textContent = msg;
    el.style.color = ok ? '#060' : '#900';
  }

  document.querySelectorAll('.settings-test').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var inputId = btn.getAttribute('data-input');
      var kind = btn.getAttribute('data-kind') || 'meta';
      var input = document.getElementById(inputId);
      if (!input) return;
      var base = YtlSettings.normalizeUrl(input.value);
      if (!base) {
        setOut(inputId, 'Adresse invalide — utilise https://…', false);
        return;
      }
      input.value = base;
      setOut(inputId, 'Test en cours…', true);
      btn.disabled = true;
      var p;
      if (kind === 'cobalt') {
        p = fetch(base + '/', {
          method: 'POST',
          mode: 'cors',
          cache: 'no-store',
          headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
          body: JSON.stringify({ url: 'https://www.youtube.com/watch?v=jNQXAC9IVRw' })
        }).then(function (r) {
          return r.text().then(function (t) {
            var j = null;
            try { j = JSON.parse(t); } catch (e) {}
            if (j && j.error && String((j.error && j.error.code) || j.error).indexOf('jwt') >= 0) {
              throw new Error('JWT / Turnstile requis — choisis un Cobalt sans login');
            }
            if (j && j.error && String((j.error && j.error.code) || j.error).indexOf('turnstile') >= 0) {
              throw new Error('Turnstile requis — pas utilisable depuis Pixr');
            }
            // Any JSON reply (tunnel/error/redirect) proves CORS + API reachability.
            if (r.status === 404) throw new Error('HTTP 404 — mauvaise base URL ?');
            return 'OK — Cobalt joignable depuis ton navigateur';
          });
        });
      } else {
        p = fetch(base + '/api/v1/stats', { mode: 'cors', cache: 'no-store' })
          .then(function (r) {
            if (r.ok) return r.json().then(function () { return 'OK — Invidious joignable'; });
            return fetch(base + '/api/v1/videos/jNQXAC9IVRw', { mode: 'cors', cache: 'no-store' })
              .then(function (r2) {
                if (!r2.ok) throw new Error('HTTP ' + r2.status);
                return r2.json().then(function (j) {
                  if (!j || !j.title) throw new Error('Réponse sans titre');
                  return 'OK — API vidéos joignable';
                });
              });
          });
      }
      p.then(function (msg) {
        setOut(inputId, msg, true);
      }).catch(function (err) {
        var m = (err && err.message) ? String(err.message) : 'Échec';
        if (/Failed to fetch|NetworkError|CORS/i.test(m)) {
          m = 'Échec réseau / CORS — ce miroir refuse ton navigateur';
        }
        setOut(inputId, m, false);
      }).then(function () {
        btn.disabled = false;
      });
    });
  });
})();
</script>
<?php
ytl_layout_end();
