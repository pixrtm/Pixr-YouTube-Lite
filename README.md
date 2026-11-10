# Pixr YouTube Lite

Official repository for **Pixr YouTube Lite** — a lightweight YouTube front-end (PHP) for Pixr Lite: browse, watch, and convert to local MP4.

## Setup

1. Copy `config.example.php` → `config.php` and fill Google OAuth + `setup_key`.
2. Ensure `data/` is writable (`videos/`, optional cookie jars).
3. Point the web root at this folder (or `/ytlite` on your host).

Do **not** commit `config.php`, Netscape cookies, or OAuth token files.

## Deploy notes

See `DEPLOY.txt` and `PROXY.txt` for OVH / converter / residential proxy tips.
