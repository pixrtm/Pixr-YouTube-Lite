<?php
/**
 * Page de test : une commande Python visible, puis lecture du MP4.
 * Vidéo fixe, pas d’entrée utilisateur dans le shell.
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/convert.php';

// Me at the zoo — courte, stable. Chaque lancement efface le MP4 pour forcer un vrai test.
const YTL_TEST_ID = 'jNQXAC9IVRw';
const YTL_TEST_URL = 'https://www.youtube.com/watch?v=jNQXAC9IVRw';

function ytl_test_command(): string
{
    return ytl_convert_build_cmd(YTL_TEST_ID, 360);
}

/** Efface le cache du test (sinon run_blocking sort tout de suite : « déjà prêt »). */
function ytl_test_wipe(): void
{
    $id = YTL_TEST_ID;
    $dir = ytl_convert_dir();
    $paths = [
        $dir . '/' . $id . '.mp4',
        $dir . '/' . $id . '_360.mp4',
        $dir . '/' . $id . '.status.json',
        $dir . '/' . $id . '_360.status.json',
        $dir . '/' . $id . '.lock',
        $dir . '/' . $id . '_360.lock',
        $dir . '/' . $id . '.log',
        $dir . '/' . $id . '_360.log',
        $dir . '/' . $id . '.part',
        $dir . '/' . $id . '_360.part',
    ];
    foreach ($paths as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

if (isset($_GET['ready'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $path = ytl_convert_mp4_path(YTL_TEST_ID);
    $ready = ytl_convert_is_ready(YTL_TEST_ID);
    echo json_encode([
        'ready' => $ready,
        'bytes' => ($ready && is_file($path)) ? (int) filesize($path) : 0,
    ]);
    exit;
}

if (isset($_GET['stream'])) {
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');
    ignore_user_abort(true);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-store');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    $cmd = ytl_test_command();
    if ($cmd === '') {
        echo "python introuvable\n";
        exit;
    }

    ytl_test_wipe();
    echo "cache effacé (relance à froid)\n";
    echo "\$ $cmd\n\n";
    flush();

    $code = ytl_convert_run_blocking(YTL_TEST_ID, 360, static function (string $line): void {
        echo $line;
        flush();
    });
    $ready = ytl_convert_is_ready(YTL_TEST_ID);
    echo "\n-- fin, code $code" . ($ready ? ', mp4 prêt' : '') . " --\n";
    flush();
    exit;
}

$cmd = ytl_test_command();
$ready = ytl_convert_is_ready(YTL_TEST_ID);
$bytes = $ready ? (int) filesize(ytl_convert_mp4_path(YTL_TEST_ID)) : 0;
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Test Python — 360p</title>
<style>
  :root { color-scheme: light dark; }
  body { margin: 0; font: 16px/1.45 system-ui, sans-serif; background: #111; color: #eee; }
  main { max-width: 880px; margin: 0 auto; padding: 28px 20px 48px; }
  h1 { font-size: 1.35rem; font-weight: 650; margin: 0 0 8px; }
  p { margin: 0 0 16px; color: #bbb; }
  a { color: #9cf; }
  .cmd, .out { font: 13px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; white-space: pre-wrap; word-break: break-all; border-radius: 10px; padding: 14px; }
  .cmd { background: #1c1c1c; border: 1px solid #333; }
  .out { background: #000; border: 1px solid #333; min-height: 220px; max-height: 420px; overflow: auto; margin-top: 14px; }
  .row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
  button { font: 15px/1 system-ui, sans-serif; padding: 12px 16px; border-radius: 8px; border: 0; cursor: pointer; }
  button:disabled { opacity: .45; cursor: default; }
  #go { background: #fff; color: #111; }
  #play { background: #e11; color: #fff; }
  video { width: 100%; margin-top: 16px; background: #000; border-radius: 10px; }
</style>
</head>
<body>
<main>
  <h1>Test — Me at the zoo, 360p</h1>
  <p>Vidéo : <a href="<?php echo ytl_h(YTL_TEST_URL); ?>"><?php echo ytl_h(YTL_TEST_URL); ?></a> — chaque lancement efface le MP4 puis retélécharge.</p>
  <div class="cmd" id="cmd"><?php echo $cmd !== '' ? ytl_h('$ ' . $cmd) : 'python introuvable'; ?></div>
  <div class="row">
    <button type="button" id="go"<?php echo $cmd === '' ? ' disabled' : ''; ?>>Lancer la commande</button>
    <button type="button" id="play"<?php echo $ready ? '' : ' disabled'; ?>>Lire la vidéo</button>
  </div>
  <pre class="out" id="out"><?php echo $ready ? ytl_h('Dernier MP4 encore là (' . $bytes . " octets) — Lancer l’efface et retélécharge.\n") : ''; ?></pre>
  <video id="player" controls playsinline hidden></video>
</main>
<script>
const go = document.getElementById('go');
const play = document.getElementById('play');
const out = document.getElementById('out');
const player = document.getElementById('player');

async function refreshReady() {
  const res = await fetch('test.php?ready=1', { cache: 'no-store' });
  const data = await res.json();
  play.disabled = !data.ready;
  return data;
}

go.addEventListener('click', async () => {
  go.disabled = true;
  play.disabled = true;
  player.hidden = true;
  out.textContent = '';
  try {
    const res = await fetch('test.php?stream=1', { cache: 'no-store' });
    const reader = res.body.getReader();
    const dec = new TextDecoder();
    while (true) {
      const step = await reader.read();
      if (step.done) break;
      out.textContent += dec.decode(step.value, { stream: true });
      out.scrollTop = out.scrollHeight;
    }
  } catch (err) {
    out.textContent += '\n' + err + '\n';
  }
  await refreshReady();
  go.disabled = false;
});

play.addEventListener('click', () => {
  player.hidden = false;
  player.src = 'media.php?v=<?php echo ytl_h(YTL_TEST_ID); ?>';
  player.play();
});
</script>
</body>
</html>
