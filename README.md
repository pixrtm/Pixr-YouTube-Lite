<div align="center">

# Pixr YouTube Lite

**An open-source project by Pixr.**

Explore the source code, follow development, and contribute to the future of Pixr YouTube Lite.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![GitHub](https://img.shields.io/badge/Hosted%20on-GitHub-181717?logo=github)](https://github.com/pixrtm/Pixr-YouTube-Lite/)

</div>

---

## About

**Pixr YouTube Lite** is part of the Pixr project ecosystem. This repository contains its source code and serves as a place to explore the project and follow its development.

The project is released under the **MIT License**, allowing others to use, modify, and redistribute the code under the terms of that license.

Pixr YouTube Lite is a lightweight YouTube front-end (PHP): browse, search, watch, and convert videos to local MP4. It is designed to run next to other Pixr sites on the same host.

## Repository

You can use this repository to:

- Browse the Pixr YouTube Lite source code.
- Review changes and follow development.
- Report bugs or suggest improvements through [GitHub Issues](https://github.com/pixrtm/Pixr-YouTube-Lite/issues).
- Fork the project and build on it in accordance with the license.

Live site: [https://pixr.dev/ytlite/](https://pixr.dev/ytlite/)

## What this repository does not contain

No API keys, passwords, OAuth client secrets, Netscape cookies, or account tokens are stored here. Do not add them.

Copy `config.example.php` to `config.php` locally and fill in your own values. Cookie jars and OAuth token files belong under `data/` on the server only, never in git.

## Getting started

Requirements: PHP 8.1+ with `curl`, and write access to `data/` (for the video cache). Optional: Python 3 + the converter tooling described in `DEPLOY.txt` for local MP4 conversion.

```bash
git clone https://github.com/pixrtm/Pixr-YouTube-Lite.git
cd Pixr-YouTube-Lite
cp config.example.php config.php
# edit config.php (OAuth, setup_key, optional proxy)
```

Point your web server document root at this folder (or serve it under `/ytlite`). See `DEPLOY.txt` and `PROXY.txt` for OVH / residential proxy notes.

## Contributing

Suggestions, bug reports, and improvements are welcome. Before opening a pull request, check the repository's existing issues and documentation.

## License

Distributed under the **MIT License**. See [LICENSE](LICENSE) for the full terms.

Copyright © 2026 **pixr44**.

---

<div align="center">

**Pixr YouTube Lite** · Open source under the MIT License

</div>
