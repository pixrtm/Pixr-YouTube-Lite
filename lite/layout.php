<?php

require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/api.php';

function ytl_lite_thumb(string $videoId): string
{
    $remote = 'https://i.ytimg.com/vi/' . rawurlencode($videoId) . '/default.jpg';
    return '../' . ytl_proxy_url($remote, 'image');
}

function ytl_lite_start(string $title, string $query = ''): void
{
    header('Content-Type: text/html; charset=UTF-8');
    $heading = $title === '' ? 'Pixr Lite' : ($title . ' - Pixr Lite');
    ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html lang="en">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="application-name" content="Pixr Lite">
<title><?php echo ytl_h($heading); ?></title>
<link rel="stylesheet" type="text/css" href="style.css">
<link rel="icon" type="image/png" href="/ytlite/favicon-96x96.png?v=41" sizes="96x96">
<link rel="icon" type="image/svg+xml" href="/ytlite/favicon.svg?v=41">
<link rel="shortcut icon" href="/ytlite/favicon.ico?v=41">
<link rel="apple-touch-icon" sizes="180x180" href="/ytlite/apple-touch-icon.png?v=41">
<meta name="apple-mobile-web-app-title" content="Pixr Lite">
<link rel="manifest" href="/ytlite/site.webmanifest?v=41">
<script src="../assets/ytl-settings.js?v=1" type="text/javascript"></script>
</head>
<body>
<p>
  <a href="index.php"><img src="logo.png?v=39" alt="Pixr Lite" width="100" height="40" border="0"></a>
</p>
<form action="index.php" method="get" autocomplete="on">
  <input type="text" name="ytlite_q" value="<?php echo ytl_h($query); ?>" size="12"
    autocomplete="ytlite-search" autocapitalize="off" autocorrect="off" spellcheck="false"
    data-lpignore="true" data-1p-ignore="true" data-form-type="other">
  <input type="submit" value="Search">
</form>
<p>
  <a href="../index.php">Classic</a>
  · <a href="../settings.php">Settings</a>
</p>
<hr>
<?php
}

function ytl_lite_end(): void
{
    echo "</body>\n</html>\n";
}

function ytl_lite_rows(array $videos): void
{
    // Safety net: never list lives on lite home/search even if a caller skipped filter.
    $videos = ytl_filter_videos($videos, true);
    echo '<table width="100%" cellpadding="0" cellspacing="0" border="0">';
    $n = 0;
    foreach ($videos as $v) {
        if (!is_array($v)) {
            continue;
        }
        $id = (string) ($v['videoId'] ?? '');
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) {
            continue;
        }
        $title = (string) ($v['title'] ?? $id);
        $author = (string) ($v['author'] ?? '');
        $views = isset($v['viewCount']) ? InvidiousClient::formatViews($v['viewCount']) : '';
        $len = isset($v['lengthSeconds']) ? InvidiousClient::formatDuration((int) $v['lengthSeconds']) : '';
        $href = ytl_url('watch.php', ['v' => $id]);
        ?>
        <tr class="row">
          <td width="80">
            <a href="<?php echo ytl_h($href); ?>"><img src="<?php echo ytl_h(ytl_lite_thumb($id)); ?>" alt="" width="80" height="60" border="0"></a>
          </td>
          <td>
            <a href="<?php echo ytl_h($href); ?>"><b><?php echo ytl_h($title); ?></b></a><br>
            <?php if ($author !== ''): ?><span class="meta"><?php echo ytl_h($author); ?></span><br><?php endif; ?>
            <span class="meta"><?php
              $bits = [];
              if ($len !== '' && $len !== '0:00') {
                  $bits[] = $len;
              }
              if ($views !== '') {
                  $bits[] = $views . ' views';
              }
              echo ytl_h(implode(' · ', $bits));
            ?></span>
          </td>
        </tr>
        <?php
        $n++;
        if ($n >= 40) {
            break;
        }
    }
    echo '</table>';
}
