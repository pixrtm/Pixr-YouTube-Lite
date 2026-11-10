<?php
/**
 * Copy to config.php and fill in secrets locally.
 * Never commit real client_secret / setup_key / proxy passwords.
 */
return [
    'backends' => ['piped', 'invidious'],
    'watch_backends' => ['piped', 'invidious', 'innertube'],

    'piped_instances' => [
        'https://pipedapi.tokhmi.xyz',
        'https://api.piped.projectsegfau.lt',
        'https://pipedapi.kavin.rocks',
    ],

    'invidious_instances' => [
        'https://yt.chocolatemoo53.com',
        'https://invidious.tiekoetter.com',
        'https://yewtu.be',
        'https://inv.nadeko.net',
    ],

    'http_timeout' => 20,
    'ssl_verify' => false,
    'proxy_streams' => true,
    'proxy_images' => true,
    'default_quality' => 360,
    'cobalt_apis' => [
        'https://rue-cobalt.xenon.zone',
        'https://cobaltapi.cjs.nz',
    ],
    'site_name' => 'Pixr Lite',
    'hide_live' => true,

    'youtube_cookie' => '',
    'youtube_cookie_file' => __DIR__ . '/data/youtube.cookies.txt',
    'youtube_cookie_expect' => [
        'allow' => ['pixryoutubelite', 'pixr youtube lite', 'youtube lite'],
        'deny' => ['pixrpro', 'pixr_community', 'pixr pro'],
    ],
    'setup_key' => 'CHANGE_ME',

    'google_oauth' => [
        'client_id' => 'YOUR_GOOGLE_OAUTH_CLIENT_ID.apps.googleusercontent.com',
        'client_secret' => 'YOUR_GOOGLE_OAUTH_CLIENT_SECRET',
        'redirect_uri' => 'https://example.com/ytlite/oauth_callback.php',
    ],

    'http_proxy' => '',

    'convert_enabled' => true,
    'python_path' => 'python3',
    'converter_path' => __DIR__ . '/scripts/converter.py',
    'video_cache_dir' => __DIR__ . '/data/videos',
];
