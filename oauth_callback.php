<?php
require_once __DIR__ . '/lib/oauth.php';

ytl_session_start();

$error = isset($_GET['error']) ? trim((string) $_GET['error']) : '';
$setupKey = (string) ($_SESSION['ytl_oauth_setup_key'] ?? '');

if ($error !== '') {
    unset($_SESSION['ytl_oauth_setup_key']);
    if ($setupKey !== '') {
        header('Location: ' . ytl_url('cookies.php', ['key' => $setupKey, 'service' => 'error', 'msg' => $error]));
        exit;
    }
    header('Location: ' . ytl_url('index.php', ['oauth' => 'error', 'msg' => $error]));
    exit;
}

$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
$state = isset($_GET['state']) ? trim((string) $_GET['state']) : '';
if ($code === '' || $state === '') {
    unset($_SESSION['ytl_oauth_setup_key']);
    if ($setupKey !== '') {
        header('Location: ' . ytl_url('cookies.php', ['key' => $setupKey, 'service' => 'error', 'msg' => 'missing_code']));
        exit;
    }
    header('Location: ' . ytl_url('index.php', ['oauth' => 'error', 'msg' => 'missing_code']));
    exit;
}

$result = ytl_oauth_handle_callback($code, $state);
$mode = (string) ($result['mode'] ?? 'visitor');
unset($_SESSION['ytl_oauth_setup_key']);

if (empty($result['ok'])) {
    $msg = (string) ($result['error'] ?? 'callback_failed');
    if ($mode === 'service' || $setupKey !== '') {
        header('Location: ' . ytl_url('cookies.php', [
            'key' => $setupKey !== '' ? $setupKey : (string) (ytl_config()['setup_key'] ?? ''),
            'service' => 'error',
            'msg' => $msg,
        ]));
        exit;
    }
    header('Location: ' . ytl_url('index.php', ['oauth' => 'error', 'msg' => $msg]));
    exit;
}

if ($mode === 'service') {
    header('Location: ' . ytl_url('cookies.php', [
        'key' => $setupKey !== '' ? $setupKey : (string) (ytl_config()['setup_key'] ?? ''),
        'service' => 'ok',
    ]));
    exit;
}

// Visitor Cloud Web OAuth cannot download (player HTTP 400). Convert needs TV Bearer.
// Drop any Cloud visitor session this callback may have set and continue to Sign in.
ytl_session_start();
unset($_SESSION['ytl_user'], $_SESSION['ytl_oauth_state'], $_SESSION['ytl_oauth_mode']);
header('Location: ' . ytl_url('tv_signin.php', ['from' => 'oauth']), true, 302);
exit;
