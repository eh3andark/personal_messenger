# Contributing / راهنمای مشارکت

First read [README-FA.md](README-FA.md) and [SECURITY.md](SECURITY.md). Keep the core Persian RTL messenger self-hosted, JSON-only, usable on ordinary PHP 7.4+ cPanel **without a database, background daemon, runtime CDN or remote fonts**. Optional web/AI/Telegram tools must degrade honestly and must never become a login prerequisite.

## Required quick checks

Install PHP 7.4+ with `openssl`, `mbstring`, `fileinfo`, `json` (also `dom`/`curl` for optional public preview), Python 3 with `requests`, and Node.js for JS syntax. In the source directory:

```bash
find . -name '*.php' -not -path './data/*' -print0 | xargs -0 -n1 php -l
node --check assets/js/app.js
node --check sw.js
node tests/service_worker.cjs    # checks static icon precache and private-URL bypass
node --check tests/browser.cjs
node --check tests/browser_features.cjs
node --check tests/browser_review.cjs
node --check tests/browser_activity.cjs
python3 -m py_compile tests/integration.py tests/new_features.py tests/review_features.py tests/account_activity.py
php tests/telegram_preview.php      # deterministic offline parser/security fixtures
```

**The HTTP test suites ERASE the data directory of their disposable fixture. Never point them at a live site or working installation.** From the project source directory (Linux example):

```bash
mkdir -p /home/user/.cache/hamrah-test-site/data
tar --exclude='./data' --exclude='./.git' --exclude='./tests' -cf - . | tar -xf - -C /home/user/.cache/hamrah-test-site
cp data/.htaccess data/index.php /home/user/.cache/hamrah-test-site/data/
# Another terminal: cd /home/user/.cache/hamrah-test-site && php -S 0.0.0.0:8790 router.php
python3 -m pip install requests
python3 tests/integration.py http://127.0.0.1:8790 /home/user/.cache/hamrah-test-site
# If running browsers: run browser.cjs NOW, before the next HTTP suite resets data.
python3 tests/new_features.py http://127.0.0.1:8790 /home/user/.cache/hamrah-test-site
# If running browsers: run browser_features.cjs NOW.
python3 tests/review_features.py http://127.0.0.1:8790 /home/user/.cache/hamrah-test-site
# If running browsers: run browser_review.cjs NOW.
python3 tests/account_activity.py http://127.0.0.1:8790 /home/user/.cache/hamrah-test-site
# If running browsers: run browser_activity.cjs NOW, before another suite resets data.
```

The baseline verifies setup/authentication, exact-ID privacy, 36 concurrent writes, sharded pagination, search, deletion scopes, own-account reset, permissions, media ACL, authenticated Range/tampering and admin flows. Default stress is **172 sequential messages**; for the release stress check use `HAMRAH_STRESS_MESSAGES=3000 python3 tests/integration.py http://127.0.0.1:8790 /home/user/.cache/hamrah-test-site` **only on the disposable fixture**. `tests/new_features.py` covers ID reuse/duplicate errors, private/public rooms and invitations, quota cutoff, cross-chat bulk scopes, sticker access, configurable GitHub URL and Telegram import safeguards; it also writes randomized disposable browser credentials. Run baseline, then new-feature, review-feature and account-activity HTTP tests, because **each resets fixture data**; run the matching browser suite between HTTP resets. The review suite verifies mandatory announcements including migration, protected main admin, explicit read, blocking, public-name privacy, per-tab presence and honest offline TGJU parser/fallback. The separate `account_activity.py` suite verifies primary-only API access, server-generated creation/login times, online/offline transitions, 7/30-day inactivity, legacy unknown dates, safe ID reuse/rename, protected file access and corruption fail-closed behavior. It resets **only** the disposable fixture.

For optional real-browser checks, install a local Chromium executable at `/usr/bin/chromium` and `playwright-core` as **development-only dependencies** (never ship them in the ZIPs). `HAMRAH_TEST_URL` defaults to `http://127.0.0.1:8788` for `tests/browser.cjs` and `:8790` for `tests/browser_features.cjs`. Set `HAMRAH_TEST_URL=http://127.0.0.1:8790` as needed; use `NODE_PATH=/your/dev/node_modules` to locate `playwright-core`. The baseline browser suite uses `.browser-test-credentials.json` left by `integration.py`; the feature browser suite uses `.browser-feature-credentials.json` left by `new_features.py`; the current-release `tests/browser_review.cjs` suite uses `.browser-review-credentials.json` left by `review_features.py`. `HAMRAH_TEST_FIXTURE` can override the default disposable `/home/user/.cache/hamrah-test-site` path for the feature suite. They test desktop/tablet/phone widths down to **320 px**, local fonts/themes, group join/leave, sticker creation, inventory, prominent 80%-quota warning and uncaught JS errors. Run **each** browser test after its respective HTTP setup, **before another HTTP test resets the fixture**: `HAMRAH_TEST_URL=http://127.0.0.1:8790 NODE_PATH=/your/dev/node_modules node tests/browser.cjs`, then after new_features `node tests/browser_features.cjs`, then after review_features `node tests/browser_review.cjs`, and after account_activity.py `node tests/browser_activity.cjs` with the same env. The review browser suite covers install/refresh, real message font-size, Enter-to-send, emoji tabs, location/camera input, room-name search/join, channel/group sender labels, close signaling and composer geometry. `browser_activity.cjs` checks primary-only visibility, Tehran/Persian full timestamps, filters and manual refresh, XSS escaping, role isolation and responsive cards down to 320 px.

A live Telegram download test is **optional**, needs `dom`, `curl` and outbound access, and must use a disposable user/fixture. Offline parser fixtures and bounded-import error handling belong in deterministic automated tests; never make CI depend on third-party CDN availability or promise retrieval of every public post.

## Packaging and review

Run `python3 scripts/build_cpanel_zip.py` for the fresh-install cPanel ZIP and `python3 scripts/build_source_zip.py` for the GitHub-ready source ZIP. Both use explicit allowlists; only the source ZIP includes CI, issue templates, `router.php`, tests and packaging scripts. Both include the main documentation and attribution files, all ten local fonts **and their own OFL license texts**, the local Lucide sprite and ISC/MIT notice, all needed PHP core modules, and only the safe `data/` skeleton. Inspect every archive listing and scan it for secrets before sharing; test actual fresh-install and upgrade paths on a disposable site. Neither script publishes to GitHub.

For every change: explain user-facing behavior and migration/deletion effects; test access control, XSS escaping, concurrent file writes and cPanel/PHP compatibility; keep UI Persian/RTL and keyboard/reduced-motion accessible. Enforce server-side checks even when the client hides a button. Update security/limitations docs and add a regression test for changed claims. Avoid long synchronous full-history scans in ordinary polling endpoints.

**Never commit** `data/users.json`, `data/user_activity.json`, `data/presence.json`, `data/config.json`, contacts, sessions, `.master_secret.php`, `.setup_token.php`, media/upload chunks, Telegram caches, logs, tokens, AI keys or a copy of real chats. Check `git status` and staged files before pushing. Preserve the bundled font and icon licenses (see [NOTICE.md](NOTICE.md)). Do not imply official Telegram affiliation or guarantee third-party preview availability. Sensitive vulnerabilities go to the private reporting channel in [SECURITY.md](SECURITY.md), not a public issue.

Before publishing publicly, consult [GITHUB-PUBLISH-FA.md](GITHUB-PUBLISH-FA.md): the existing repository `eh3andark/personal_messenger` must receive the extracted source tree, followed by a Release asset for cPanel. Original application code is © 2026 eh3andark under MIT; MIT allows copying/commercial use with notice preservation, not a no-copy restriction. Keep independent font/icon notices intact.
