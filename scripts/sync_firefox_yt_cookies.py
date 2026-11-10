#!/usr/bin/env python3
"""
Background sync: Firefox youtube.com cookies → pixr.dev/ytlite/cookie_sync.php

No UI. Run once per login session, or via launchd/cron every N minutes.

Requires macOS Full Disk Access for the terminal/python that runs this
(Firefox Profiles are TCC-protected).

Usage:
  export YTL_SETUP_KEY='CHANGE_ME'
  export YTL_SYNC_URL='https://pixr.dev/ytlite/cookie_sync.php'
  python3 scripts/sync_firefox_yt_cookies.py

  # loop every 15 min while logged into Firefox:
  python3 scripts/sync_firefox_yt_cookies.py --loop 900
"""
from __future__ import annotations

import argparse
import os
import shutil
import sqlite3
import sys
import tempfile
import time
import urllib.request
from pathlib import Path

HOME = Path.home()
FF_PROFILES = HOME / "Library/Application Support/Firefox/Profiles"


def find_cookies_db() -> Path:
    if not FF_PROFILES.is_dir():
        raise SystemExit(f"Firefox profiles not found: {FF_PROFILES}")
    cands = sorted(
        FF_PROFILES.glob("*/cookies.sqlite"),
        key=lambda p: p.stat().st_mtime,
        reverse=True,
    )
    if not cands:
        raise SystemExit("No cookies.sqlite under Firefox Profiles")
    return cands[0]


def export_netscape(db: Path) -> str:
    tmpdir = Path(tempfile.mkdtemp(prefix="ytl-ff-cookies-"))
    try:
        copy = tmpdir / "cookies.sqlite"
        shutil.copy2(db, copy)
        for suf in ("-wal", "-shm"):
            src = Path(str(db) + suf)
            if src.exists():
                shutil.copy2(src, Path(str(copy) + suf))
        con = sqlite3.connect(str(copy))
        cur = con.cursor()
        rows = cur.execute(
            """
            SELECT host, path, isSecure, expiry, name, value
            FROM moz_cookies
            WHERE host LIKE '%youtube.com'
            """
        ).fetchall()
        con.close()
        lines = [
            "# Netscape HTTP Cookie File",
            "# synced from Firefox by sync_firefox_yt_cookies.py",
        ]
        for host, path, secure, expiry, name, value in rows:
            domain = host if str(host).startswith(".") else host
            flag = "TRUE" if str(domain).startswith(".") else "FALSE"
            secure_s = "TRUE" if int(secure or 0) else "FALSE"
            exp = int(expiry or 0)
            lines.append(
                f"{domain}\t{flag}\t{path or '/'}\t{secure_s}\t{exp}\t{name}\t{value}"
            )
        return "\n".join(lines) + "\n"
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)


def post_cookies(url: str, key: str, netscape: str, sub: str = "") -> str:
    boundary = "----ytlCookieSync7f3a"
    parts = []
    def add(name: str, body: str, filename: str | None = None):
        parts.append(f"--{boundary}")
        if filename:
            parts.append(
                f'Content-Disposition: form-data; name="{name}"; filename="{filename}"'
            )
            parts.append("Content-Type: application/octet-stream")
        else:
            parts.append(f'Content-Disposition: form-data; name="{name}"')
        parts.append("")
        parts.append(body)

    add("key", key)
    if sub:
        add("sub", sub)
    add("cookies", netscape, "youtube.cookies.txt")
    parts.append(f"--{boundary}--")
    parts.append("")
    body = "\r\n".join(parts).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=body,
        method="POST",
        headers={
            "Content-Type": f"multipart/form-data; boundary={boundary}",
            "Content-Length": str(len(body)),
        },
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        return resp.read().decode("utf-8", "replace")


def once(url: str, key: str, sub: str) -> None:
    db = find_cookies_db()
    netscape = export_netscape(db)
    if "LOGIN_INFO" not in netscape and "__Secure-3PSID" not in netscape:
        raise SystemExit("Firefox jar missing LOGIN_INFO / Secure-PSID — sign into YouTube in Firefox first")
    out = post_cookies(url, key, netscape, sub=sub)
    print(out)


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", default=os.environ.get("YTL_SYNC_URL", "https://pixr.dev/ytlite/cookie_sync.php"))
    ap.add_argument("--key", default=os.environ.get("YTL_SETUP_KEY", "CHANGE_ME"))
    ap.add_argument("--sub", default=os.environ.get("YTL_USER_SUB", ""), help="optional visitor sub")
    ap.add_argument("--loop", type=int, default=0, help="seconds between syncs (0 = once)")
    args = ap.parse_args()
    if args.loop > 0:
        while True:
            try:
                once(args.url, args.key, args.sub)
            except Exception as e:
                print("sync error:", e, file=sys.stderr)
            time.sleep(args.loop)
    else:
        once(args.url, args.key, args.sub)


if __name__ == "__main__":
    main()
