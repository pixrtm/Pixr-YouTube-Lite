#!/usr/bin/env python3
"""
YouTube Lite — conversion MP4 via yt-dlp (bin/yt-dlp.pyz).

Usage:
  python3 converter.py <cookie> <dossier> 360 <url>
  Le lien YouTube est toujours le dernier argument.
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ID_RE = re.compile(r"^[A-Za-z0-9_-]{6,20}$")


def resolve_python() -> str:
    """sys.executable est parfois vide sous PHP/CGI → Permission denied: ''."""
    exe = (sys.executable or "").strip()
    if exe and os.path.isfile(exe) and os.access(exe, os.X_OK):
        return exe
    for name in (
        os.environ.get("YT_PYTHON", "").strip(),
        "python3",
        "python3.7",
        "python3.8",
        "python3.9",
        "python3.10",
        "python3.11",
        "/usr/bin/python3",
        "python",
    ):
        if not name:
            continue
        if os.path.sep in name:
            if os.path.isfile(name) and os.access(name, os.X_OK):
                return name
            continue
        found = shutil.which(name)
        if found:
            return found
    raise SystemExit("python3 introuvable (sys.executable vide et PATH sans python3)")


def _python_runs(path: str) -> bool:
    if not path or not os.path.isfile(path):
        return False
    try:
        os.chmod(path, 0o755)
    except OSError:
        pass
    try:
        probe = subprocess.run(
            [path, "-c", "import sys; raise SystemExit(0 if sys.version_info >= (3, 11) else 2)"],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=30,
        )
    except Exception:
        return False
    return probe.returncode == 0


def bundled_python() -> str:
    """CPython 3.12 livré avec le site (requis pour yt-dlp 2026)."""
    dest = os.path.join(ROOT, "bin", "python", "bin", "python3")
    if _python_runs(dest):
        return dest

    tarpath = os.path.join(ROOT, "bin", "cpython.tar.gz")
    if not os.path.isfile(tarpath):
        return ""
    import tarfile
    print("extract cpython", flush=True)
    try:
        with tarfile.open(tarpath, "r:gz") as tf:
            tf.extractall(os.path.join(ROOT, "bin"))
    except Exception as exc:
        print("extract " + type(exc).__name__ + " " + str(exc), flush=True)
        return ""
    if _python_runs(dest):
        return dest
    print("extract cpython: python3 absent ou non exécutable", flush=True)
    return ""


def find_yt_dlp_cmd() -> list:
    """yt-dlp 2026 + CPython 3.12 sur Linux ; brew sur Mac ; pas l’ancien pyz YouTube."""
    py = resolve_python()
    env = os.environ.get("YTDLP_BIN", "").strip()
    if env:
        if env.endswith(".pyz") or env.endswith(".py"):
            return [py, env]
        return [env]

    modern_pyz = os.path.join(ROOT, "bin", "yt-dlp-2026.pyz")
    py312 = bundled_python()
    if py312 and os.path.isfile(modern_pyz):
        return [py312, modern_pyz]

    # Mac / brew
    for path in ("/opt/homebrew/bin/yt-dlp", "/usr/local/bin/yt-dlp"):
        if os.path.isfile(path) and os.access(path, os.X_OK):
            return [path]

    which = shutil.which("yt-dlp")
    if which and not which.endswith(".pyz"):
        return [which]

    # Ancien zipapp 2023 : inutilisable pour YouTube (plus que des storyboards).
    missing = []
    if not os.path.isfile(modern_pyz):
        missing.append("bin/yt-dlp-2026.pyz")
    if not py312:
        missing.append("bin/cpython.tar.gz (ou bin/python/ déjà extrait)")
    raise SystemExit(
        "Moteur YouTube incomplet — manque : "
        + ", ".join(missing if missing else ["toolchain 2026"])
        + ". L’ancien yt-dlp.pyz (Python 3.7) ne suffit plus."
    )


def video_id_from(url_or_id: str) -> str:
    s = url_or_id.strip()
    if ID_RE.match(s):
        return s
    m = re.search(r"(?:v=|/shorts/|/embed/|youtu\.be/)([A-Za-z0-9_-]{6,20})", s)
    if m:
        return m.group(1)
    raise SystemExit("URL / id vidéo invalide")


def write_status(path, state, **extra):
    payload = {"state": state, "updated": int(time.time()), **extra}
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(payload, f)
    os.replace(tmp, path)


def _deno_usable(path: str) -> bool:
    if not path or not os.path.isfile(path):
        return False
    # Sur Mac, bin/deno est un ELF Linux (OVH) — ne pas le lancer.
    try:
        with open(path, "rb") as fh:
            magic = fh.read(4)
        if magic == b"\x7fELF" and sys.platform == "darwin":
            return False
        if magic == b"\xcf\xfa\xed\xfe" and sys.platform.startswith("linux"):
            return False
    except OSError:
        return False
    try:
        os.chmod(path, 0o755)
    except OSError:
        pass
    try:
        probe = subprocess.run(
            [path, "--version"],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=25,
        )
    except Exception:
        return False
    out = probe.stdout or b""
    return probe.returncode == 0 and b"deno" in out


def ensure_deno() -> str:
    """Deno pour résoudre le challenge JS YouTube (EJS). bin/deno = Linux OVH."""
    bundled = os.path.join(ROOT, "bin", "deno")
    gz = bundled + ".gz"
    candidates = [
        bundled,
        shutil.which("deno") or "",
        "/opt/homebrew/bin/deno",
        "/usr/local/bin/deno",
        os.path.expanduser("~/.deno/bin/deno"),
    ]
    for path in candidates:
        if _deno_usable(path):
            return path

    # Sur Linux uniquement : reconstruire depuis deno.gz si le binaire est cassé.
    if sys.platform.startswith("linux") and os.path.isfile(gz):
        import gzip
        print("restore deno", flush=True)
        try:
            with gzip.open(gz, "rb") as src, open(bundled, "wb") as dst:
                shutil.copyfileobj(src, dst)
            os.chmod(bundled, 0o755)
        except Exception as exc:
            print("restore deno " + type(exc).__name__ + " " + str(exc), flush=True)
            return ""
        if _deno_usable(bundled):
            return bundled
    return ""


def find_ffmpeg() -> str:
    bundled = os.path.join(ROOT, "bin", "ffmpeg")
    gz = bundled + ".gz"

    def usable(path: str) -> bool:
        if not path or not os.path.isfile(path):
            return False
        try:
            with open(path, "rb") as fh:
                magic = fh.read(4)
            if magic == b"\x7fELF" and sys.platform == "darwin":
                return False
        except OSError:
            return False
        try:
            os.chmod(path, 0o755)
        except OSError:
            pass
        try:
            probe = subprocess.run(
                [path, "-version"],
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                timeout=20,
            )
        except Exception:
            return False
        return probe.returncode == 0 and b"ffmpeg" in (probe.stdout or b"").lower()

    for path in (
        bundled,
        shutil.which("ffmpeg") or "",
        "/opt/homebrew/bin/ffmpeg",
        "/usr/local/bin/ffmpeg",
        "/usr/bin/ffmpeg",
    ):
        if usable(path):
            return path

    if sys.platform.startswith("linux") and os.path.isfile(gz):
        import gzip
        print("restore ffmpeg", flush=True)
        try:
            with gzip.open(gz, "rb") as src, open(bundled, "wb") as dst:
                shutil.copyfileobj(src, dst)
            os.chmod(bundled, 0o755)
        except Exception as exc:
            print("restore ffmpeg " + type(exc).__name__ + " " + str(exc), flush=True)
            return ""
        if usable(bundled):
            return bundled
    return ""


def mp4_boxes_complete(path: str) -> bool:
    """True si les boîtes de 1er niveau couvrent tout le fichier (pas de mdat tronqué)."""
    try:
        size = os.path.getsize(path)
    except OSError:
        return False
    if size < 20000:
        return False
    try:
        with open(path, "rb") as fh:
            offset = 0
            saw_ftyp = False
            saw_media = False
            while offset + 8 <= size:
                fh.seek(offset)
                hdr = fh.read(16)
                if len(hdr) < 8:
                    return False
                box = int.from_bytes(hdr[0:4], "big")
                typ = hdr[4:8]
                if typ == b"ftyp":
                    saw_ftyp = True
                if typ in (b"mdat", b"moof", b"moov"):
                    saw_media = True
                if box == 0:
                    return saw_ftyp and saw_media
                if box == 1:
                    if len(hdr) < 16:
                        return False
                    box = int.from_bytes(hdr[8:16], "big")
                if box < 8 or offset + box > size:
                    return False
                offset += box
            return offset == size and saw_ftyp and saw_media
    except OSError:
        return False


def telecharger(url_or_id, hauteur=360, dossier=".", cookie_file="", media="mp4"):
    yt_cmd = find_yt_dlp_cmd()
    vid = video_id_from(url_or_id)
    url = "https://www.youtube.com/watch?v=" + vid
    os.makedirs(dossier, exist_ok=True)
    oauth_token = (os.environ.get("YT_OAUTH_ACCESS_TOKEN") or "").strip()
    # Cloud OAuth Bearer must never reach yt-dlp (HTTP 400). Ignore env for auth;
    # only use it as a hint to prefer android clients. Durable auth = Netscape jar
    # (data/ytdlp_oauth/cookies.txt) passed as cookie_file from PHP.
    if oauth_token:
        print("note: ignoring YT_OAUTH_ACCESS_TOKEN for yt-dlp (Cloud Bearer unsupported)", flush=True)
    # Prefer durable ytdlp session path if PHP passed '-' but file exists on disk.
    session_cookie = os.path.join(ROOT, "data", "ytdlp_oauth", "cookies.txt")
    if (not cookie_file or cookie_file in ("-", "none", "nocookie")) and os.path.isfile(session_cookie) and os.path.getsize(session_cookie) > 20:
        cookie_file = session_cookie
        print("auth: ytdlp_oauth session cookies", flush=True)
    media = "mp3" if str(media).lower().strip() == "mp3" else "mp4"
    hauteur = hauteur if hauteur > 0 else 360
    if media == "mp3":
        out_base = vid + "_mp3"
        out_file = os.path.join(dossier, vid + ".mp3")
        out_tpl = os.path.join(dossier, vid + ".%(ext)s")
    else:
        out_base = vid if hauteur == 360 else (vid + "_" + str(hauteur))
        out_file = os.path.join(dossier, out_base + ".mp4")
        out_tpl = os.path.join(dossier, out_base + ".%(ext)s")
    status_path = os.path.join(dossier, out_base + ".status.json")

    h = str(hauteur)
    ffmpeg = find_ffmpeg()
    has_ffmpeg = bool(ffmpeg)
    if ffmpeg:
        os.environ["PATH"] = os.path.dirname(ffmpeg) + os.pathsep + os.environ.get("PATH", "")

    if media == "mp3":
        if not has_ffmpeg:
            write_status(
                status_path,
                "error",
                videoId=vid,
                quality=hauteur,
                format="mp3",
                message="ffmpeg manquant (bin/ffmpeg.gz) — requis pour MP3",
            )
            print("error: ffmpeg introuvable pour mp3", flush=True)
            return 1
        fmt = "ba/bestaudio/best"
    else:
        # Progressive first (itag 18 / muxed MP4). DASH+ffmpeg often SIGSEGV (-11) on OVH shared hosts.
        progressive = (
            "18/"
            "best[height<=" + h + "][ext=mp4][acodec!=none][vcodec!=none]/"
            "best[height<=" + h + "][ext=mp4][acodec!=none]/"
            "best[height<=" + h + "][ext=mp4]/"
            "b[height<=" + h + "][ext=mp4]"
        )
        fmt = progressive
        if has_ffmpeg:
            print("ffmpeg ok — DASH merge only as last resort", flush=True)
        else:
            print("warning: ffmpeg absent — progressive only", flush=True)

    uses_2026_pyz = any(
        os.path.basename(part) == "yt-dlp-2026.pyz" or part.endswith("yt-dlp-2026.pyz")
        for part in yt_cmd
    )
    brew_like = any(os.path.basename(part) == "yt-dlp" for part in yt_cmd)
    modern = uses_2026_pyz or brew_like

    cache = os.path.join(dossier, ".cache")
    os.makedirs(cache, exist_ok=True)
    os.environ["TMPDIR"] = cache
    os.environ["XDG_CACHE_HOME"] = cache
    os.environ["DENO_DIR"] = os.path.join(cache, "deno")
    os.makedirs(os.environ["DENO_DIR"], exist_ok=True)
    # OVH mutualisé : Deno se fait souvent SIGKILL (-9) si trop de RAM.
    os.environ["DENO_NO_PROMPT"] = "1"
    os.environ.setdefault("DENO_V8_FLAGS", "--max-old-space-size=192")
    # Prefer residential proxy from PHP (YT_HTTP_PROXY). Strip empty junk only.
    yt_proxy = (os.environ.get("YT_HTTP_PROXY") or "").strip()
    if yt_proxy:
        os.environ["http_proxy"] = yt_proxy
        os.environ["https_proxy"] = yt_proxy
        os.environ["HTTP_PROXY"] = yt_proxy
        os.environ["HTTPS_PROXY"] = yt_proxy
        os.environ["ALL_PROXY"] = yt_proxy
        os.environ["all_proxy"] = yt_proxy
        print("proxy: " + yt_proxy.split("@")[-1], flush=True)
    else:
        for proxy_key in (
            "http_proxy", "https_proxy", "HTTP_PROXY", "HTTPS_PROXY",
            "ALL_PROXY", "all_proxy",
        ):
            os.environ.pop(proxy_key, None)

    deno = ""
    if modern:
        deno = ensure_deno()
        if not deno:
            write_status(
                status_path,
                "error",
                videoId=vid,
                quality=hauteur,
                format=media,
                message="Deno manquant (bin/deno.gz) — YouTube refuse les formats",
            )
            print("error: deno introuvable — upload bin/deno.gz", flush=True)
            return 1
        os.environ["PATH"] = os.path.dirname(deno) + os.pathsep + os.environ.get("PATH", "")

    def build_cmd(format_str: str, use_deno: bool = True, clients: str = "") -> list:
        c = yt_cmd + [
            "--no-playlist",
            "--newline",
            "--no-cache-dir",
            "--no-continue",
            "--no-part",
            "-f",
            format_str,
            "-o",
            out_tpl,
        ]
        if media == "mp3":
            c.extend(["-x", "--audio-format", "mp3", "--audio-quality", "0"])
        elif has_ffmpeg and ("+" in format_str):
            # Only request merge when the format string actually needs DASH joining.
            c.extend(["--merge-output-format", "mp4"])
        if modern and use_deno and deno:
            if uses_2026_pyz:
                c.extend(["--no-js-runtimes", "--js-runtimes", "deno:" + deno])
            else:
                c.extend(["--js-runtimes", "deno:" + deno])
            c.extend(["--remote-components", "ejs:github"])
        if clients:
            c.extend(["--extractor-args", "youtube:player_client=" + clients])
        elif not modern:
            c.extend([
                "--no-check-certificates",
                "--extractor-args",
                "youtube:player_client=web",
            ])
        # Refuse live / premiere HLS — ffmpeg keepalive on googlevideo live fails on OVH.
        c.extend(["--match-filter", "!is_live & live_status!=is_live & live_status!=is_upcoming"])
        if oauth_token:
            # Do NOT send Authorization: Bearer to yt-dlp — YouTube player API
            # returns HTTP 400 "invalid argument". Cookies (if any) still help.
            print("auth: oauth env present (Bearer NOT passed to yt-dlp)", flush=True)
        if cookie_file and os.path.isfile(cookie_file) and os.path.getsize(cookie_file) > 10:
            runtime_cookie = os.path.join(cache, "cookies.txt")
            shutil.copyfile(cookie_file, runtime_cookie)
            c.extend(["--cookies", runtime_cookie])
        elif not oauth_token:
            print("warning: no cookie file", flush=True)
        c.append(url)
        return c

    def out_file_complete() -> bool:
        """True when a playable MP4/MP3 already exists (e.g. browser Cobalt PUT)."""
        if not os.path.isfile(out_file):
            return False
        try:
            size = os.path.getsize(out_file)
        except OSError:
            return False
        if media == "mp3":
            return size >= 4000
        return size >= 20000 and mp4_boxes_complete(out_file)

    def safe_unlink_out(reason: str) -> None:
        """Never destroy a complete file landed by browser Cobalt / another path."""
        if not os.path.isfile(out_file):
            return
        if out_file_complete():
            print("keep complete out_file (" + reason + ")", flush=True)
            return
        print("supprime incomplete out_file (" + reason + ")", flush=True)
        try:
            os.unlink(out_file)
        except OSError:
            pass

    def cleanup_junk() -> None:
        junk_exts = (".mhtml", ".webm", ".mkv", ".webp", ".jpg", ".ytdl", ".part", ".m4a", ".opus")
        if media == "mp3":
            junk_exts = (".mhtml", ".webm", ".mkv", ".webp", ".jpg", ".ytdl", ".part", ".m4a", ".opus", ".mp4")
        for junk_ext in junk_exts:
            for base in (out_base, vid, vid + "_mp3"):
                junk = os.path.join(dossier, base + junk_ext)
                if os.path.isfile(junk):
                    # Never delete the final playable out_file as "junk".
                    if os.path.abspath(junk) == os.path.abspath(out_file) and out_file_complete():
                        continue
                    print("supprime " + junk, flush=True)
                    try:
                        os.unlink(junk)
                    except OSError:
                        pass
        try:
            for name in os.listdir(dossier):
                if name.startswith(out_base + ".f") or name.startswith(vid + ".f"):
                    try:
                        os.unlink(os.path.join(dossier, name))
                    except OSError:
                        pass
        except OSError:
            pass

    def run_once(format_str: str, use_deno: bool = True, clients: str = "") -> tuple:
        """Stream stdout vers PHP (barre de %) + garde les lignes pour le retry."""
        print(
            "format " + format_str
            + (" deno" if use_deno else " no-deno")
            + ((" clients=" + clients) if clients else ""),
            flush=True,
        )
        lines = []
        proc = subprocess.Popen(
            build_cmd(format_str, use_deno=use_deno, clients=clients),
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            bufsize=0,
        )
        assert proc.stdout is not None
        while True:
            raw = proc.stdout.readline()
            if not raw:
                break
            try:
                line = raw.decode("utf-8", "replace")
            except Exception:
                line = raw.decode("latin-1", "replace")
            lines.append(line)
            sys.stdout.write(line)
            sys.stdout.flush()
        code_local = proc.wait()
        cleanup_junk()
        return code_local, "".join(lines)

    write_status(
        status_path,
        "downloading",
        videoId=vid,
        engine="converter.py",
        quality=hauteur,
        format=media,
        percent=2,
        message="Connexion à YouTube…",
    )
    label = "mp3" if media == "mp3" else (str(hauteur) + "p")
    print("→ " + label + " " + url, flush=True)
    print("engine " + " ".join(yt_cmd), flush=True)
    # Live cookie: tv/web + Deno. No cookie: android first, then web+deno (SABR).
    # NEVER delete durable jars (data/ytdlp_oauth/cookies.txt / youtube.cookies.txt) —
    # only drop the runtime copy under cache. Bot-wall ≠ proof the jar file is gone.
    has_cookie = bool(cookie_file and os.path.isfile(cookie_file) and os.path.getsize(cookie_file) > 10)
    if has_cookie:
        code, out = run_once(fmt, use_deno=True, clients="tv,tv_embedded,web_safari,web")
        jar_flagged = "cookies are no longer valid" in (out or "")
        bot_wall = "Sign in to confirm you’re not a bot" in (out or "")
        if jar_flagged or bot_wall:
            print(
                "cookie auth failed ("
                + ("rotated" if jar_flagged else "bot-wall")
                + ") — retry without cookies; durable jar kept",
                flush=True,
            )
            runtime_cookie = os.path.join(cache, "cookies.txt")
            if os.path.isfile(runtime_cookie):
                try:
                    os.unlink(runtime_cookie)
                except OSError:
                    pass
            has_cookie = False
            cookie_file = ""
            # Do not wipe a complete MP4 from browser Cobalt while retrying.
            if out_file_complete():
                print("complete out_file present after cookie miss — keep", flush=True)
                code = 0
            else:
                safe_unlink_out("cookie-retry")
                code, out = run_once(fmt, use_deno=False, clients="android,android_vr,tv")
    else:
        code, out = run_once(fmt, use_deno=False, clients="android,android_vr,tv")

    def need_more(out_text: str, exit_code: int) -> bool:
        # Browser Cobalt / parallel put already produced a playable file.
        if out_file_complete():
            return False
        if "No video formats found" in out_text:
            return True
        if "returncode: -9" in out_text or "Error running deno" in out_text:
            return True
        # ffmpeg SIGSEGV / crash on OVH — retry with another strategy
        if "ffmpeg exited with code -11" in out_text or "exited with code -11" in out_text:
            return True
        if "Requested format is not available" in out_text or "Only images are available" in out_text:
            return True
        if media == "mp4" and os.path.isfile(out_file) and not mp4_boxes_complete(out_file):
            return True
        if exit_code != 0 and not os.path.isfile(out_file):
            return True
        return False

    if need_more(out, code):
        safe_unlink_out("retry-android")
        write_status(
            status_path,
            "downloading",
            videoId=vid,
            quality=hauteur,
            format=media,
            percent=8,
            message="Nouvel essai (sans Deno)…",
        )
        print("retry android/mweb sans deno…", flush=True)
        # 2e essai : clients mobiles — pas iOS (refuse les cookies → warning inutile).
        code, out = run_once(fmt, use_deno=False, clients="android,mweb")

    if media == "mp4" and need_more(out, code):
        safe_unlink_out("retry-progressive")
        # Prefer progressive again (avoid ffmpeg -11). DASH merge only if progressive empty.
        ffmpeg_crashed = ("ffmpeg exited with code -11" in (out or "")) or ("exited with code -11" in (out or ""))
        if ffmpeg_crashed or not has_ffmpeg:
            fmt2 = (
                "18/"
                "best[height<=" + h + "][ext=mp4][acodec!=none][vcodec!=none]/"
                "best[ext=mp4][height<=" + h + "][acodec!=none]/"
                "best[ext=mp4][height<=" + h + "]/"
                "b[ext=mp4][height<=" + h + "]"
            )
        else:
            fmt2 = (
                progressive + "/"
                "bv*[height<=" + h + "][ext=mp4]+ba[ext=m4a]/"
                "b[height<=" + h + "]/"
                "best[height<=" + h + "]"
            )
        write_status(
            status_path,
            "downloading",
            videoId=vid,
            quality=hauteur,
            format=media,
            percent=12,
            message="Dernier essai…",
        )
        print("retry progressive" + (" (ffmpeg crashed)" if ffmpeg_crashed else "") + "…", flush=True)
        code, out = run_once(fmt2, use_deno=True, clients="web,web_safari,tv,android,mweb")

        if (not os.path.isfile(out_file)) and os.path.isfile(os.path.join(dossier, vid + ".webm")):
            print("fichier non mp4", flush=True)
            code = 1

        if os.path.isfile(out_file) and not mp4_boxes_complete(out_file):
            print("mp4 incomplet — suppression", flush=True)
            safe_unlink_out("incomplete-mp4")

        ok_file = (
            os.path.isfile(out_file)
            and os.path.getsize(out_file) >= 20000
            and mp4_boxes_complete(out_file)
        )
    else:
        # yt-dlp peut laisser vid.mp3 après -x
        if not os.path.isfile(out_file):
            for name in (vid + ".mp3", out_base + ".mp3"):
                cand = os.path.join(dossier, name)
                if os.path.isfile(cand):
                    if cand != out_file:
                        try:
                            os.replace(cand, out_file)
                        except OSError:
                            out_file = cand
                    break
        ok_file = os.path.isfile(out_file) and os.path.getsize(out_file) >= 4000

    if code != 0 or not ok_file:
        msg = "Téléchargement MP3 impossible" if media == "mp3" else "Téléchargement MP4 impossible"
        blob = out or ""
        for line in reversed(blob.splitlines()):
            if "ERROR:" in line or "Only images" in line or "format is not available" in line:
                msg = line.strip()
                break
        low = blob.lower()
        if "live event has ended" in low or "this live event has ended" in low:
            msg = "This live stream has ended — it can't be played."
        elif (
            "yt_live_broadcast" in low
            or "is_live" in low
            or "keepalive request failed" in low
            or "[hls @" in low
            or "live event will begin" in low
            or "premier" in low and "live" in low
            or "did not match any filter" in low and "live" in low
        ):
            msg = "Live streams aren't supported — open a regular (non-live) video."
        elif "ffmpeg exited with code -11" in low:
            msg = "Converter failed (ffmpeg). Try another video."
        elif "no video formats found" in low:
            msg = "No playable format found for this video."
        elif "did not match any filter" in low:
            msg = "This video can't be converted (live or restricted)."
        # Shared Google session dead — flag for cookies.php / cookie_cron.php
        # (skip when convert used the visitor OAuth token instead of Pixr cookies)
        cookie_dead = (not oauth_token) and any(
            s in low
            for s in (
                "cookies are no longer valid",
                "sign in to confirm you",
                "please sign in",
                "login required",
            )
        )
        if cookie_dead:
            msg = "YouTube cookie expired — re-export Pixr YouTube Lite session (cookies.php)."
            health_path = os.path.join(ROOT, "data", "cookie_health.json")
            try:
                os.makedirs(os.path.dirname(health_path), exist_ok=True)
                with open(health_path, "w", encoding="utf-8") as hf:
                    json.dump(
                        {
                            "ok": False,
                            "state": "invalid",
                            "checked_at": int(time.time()),
                            "logged_in": False,
                            "account": "",
                            "missing": [],
                            "reason": msg,
                            "source": "converter.py",
                            "alert": True,
                        },
                        hf,
                        ensure_ascii=False,
                        indent=2,
                    )
                    hf.write("\n")
            except OSError:
                pass
        elif oauth_token and any(
            s in low
            for s in (
                "sign in to confirm you",
                "please sign in",
                "login required",
                "unauthorized",
            )
        ):
            msg = "Your Google sign-in could not unlock this video. Try signing in again."
        # Strip noisy yt-dlp prefixes for the UI
        if msg.startswith("ERROR:"):
            msg = msg[6:].strip()
            if msg.startswith("[youtube]"):
                msg = msg.split(":", 1)[-1].strip() if ":" in msg else msg
        write_status(
            status_path,
            "error",
            videoId=vid,
            code=code,
            quality=hauteur,
            format=media,
            message=msg[:220],
            cookie_expired=bool(cookie_dead),
        )
        return code if code != 0 else 1

    write_status(
        status_path,
        "ready",
        outfile=out_file,
        bytes=os.path.getsize(out_file),
        quality=hauteur,
        format=media,
    )
    print("OK " + out_file, flush=True)
    return 0


if __name__ == "__main__":
    args = sys.argv[1:]
    if len(args) < 1:
        sys.stderr.write(
            "Usage : python3 converter.py <cookie|-> <dossier> 360 [mp3|mp4] <url>\n"
            "  Pass '-' as cookie when using YT_OAUTH_ACCESS_TOKEN (visitor account).\n"
        )
        sys.exit(1)

    url = args[-1]
    hauteur = 360
    dossier = "."
    cookie = ""
    media = "mp4"
    for arg in args[:-1]:
        low = arg.lower()
        if low in ("mp3", "mp4"):
            media = low
        elif arg.isdigit():
            hauteur = int(arg)
        elif low in ("-", "none", "nocookie"):
            cookie = ""
        elif os.path.isfile(arg):
            cookie = arg
        else:
            dossier = arg
    raise SystemExit(telecharger(url, hauteur, dossier, cookie, media))
