# Keira (B/S Edition) Deployment & Operations Guide

This project is a **B/S deployment wrapper** based on Keira3 (an AzerothCore database editor), designed for an Apache + PHP environment:

- Frontend: a static site built from Keira3 (Nx + Angular)
- Backend: a lightweight PHP wrapper (login, session, API routing, SQLite helper queries)
- Base path: mounted under `/keira/` by default (configurable)

Example URL: `https://example.com/keira/`

## Overview

Keira3 is primarily a desktop (Electron) app. This wrapper enables browser usage via “static frontend + PHP API”, including:

- Single-user login protection (PHP session cookie)
- MySQL query/write (connects to AzerothCore world database)
- SQLite query (offline data such as icons/spell names)
- Chinese i18n governance on the frontend (avoid raw keys / English leakage as much as possible)

## What Was Changed From Keira3

Frontend (Keira3) side:

- Path adaptation: supports `/keira/` as `baseHref`, static assets served from `/keira/web/`
- i18n governance:
  - Table headers / field labels use translation keys
  - Selectors (bitmask, single-value enum) support an `i18nKey` namespace
  - Missing translations fall back to the original text; token-style strings are “humanized” under Chinese locale

Backend (PHP wrapper) side:

- Provides `/keira/api/*` JSON APIs (auth/mysql/sqlite)
- Single-user login (username + `password_hash`)
- Session hardening: `session_regenerate_id(true)` on login, `HttpOnly` cookies, configurable `SameSite`
- Idle timeout: configurable `idle_timeout_seconds`
- Login rate limiting: simple IP + time-window limiter; returns 429 to slow brute force attempts
- Access protection: blocks direct access to `config/` and `lib/`

## Directory Layout

Runtime layout (required after deployment):

```text
keira/
  index.php                 # PHP wrapper: login page + SPA deep-link entry
  .htaccess                 # routing + protection rules
  api/
    index.php               # /keira/api/* entry
    .htaccess               # API routing
  lib/
    bootstrap.php           # session, JSON helpers, rate limiting, etc.
    auth.php                # login/logout
    db_mysql.php            # MySQL query helper
    db_sqlite.php           # SQLite query helper
  config/
    config.php              # production config (do not expose / do not overwrite with frontend build)
    config.example.php      # config template
  web/
    index.html              # Keira3 web build output
    assets/
      i18n/
        zh.json
        en.json
      sqlite.db             # optional: offline SQLite database (as needed)
      item_display.db       # optional: item/3D display SQLite database (as needed)
```

Note: the server does **not** need the Keira3 source code to run; source code is only needed to rebuild the frontend.

## Server Requirements

### Web Server

- Apache 2.4+
- `mod_rewrite` enabled
- `.htaccess` allowed for this directory (`AllowOverride All`)

### PHP

- PHP 8.1+ (a TS build compatible with Apache, loaded via mod_php is recommended)
- Required extensions:
  - `mysqli`
- Optional but strongly recommended (offline queries):
  - `sqlite3`
  - `pdo_sqlite`

Windows tip: if you enabled SQLite extensions but still see “Unable to load dynamic library”, it is usually because a dependent DLL is not in Apache’s DLL search path. Place the required dependency (e.g. `libsqlite3.dll`) somewhere Apache can load from (for example `Apache/bin`) or adjust the environment appropriately.

### HTTPS (Strongly Recommended)

- Use HTTPS whenever possible
- Set `app.session.cookie_secure` to `true` only when HTTPS is enabled

## Configuration

1) Copy the config template:

- `config/config.example.php` → `config/config.php`

2) Generate a password hash:

```bash
php -r "echo password_hash('yourPassword', PASSWORD_DEFAULT), PHP_EOL;"
```

3) Edit `config/config.php`:

- `app.base_path` (default `/keira`)
- `app.auth.username` / `app.auth.password_hash`
- `mysql.*` (AzerothCore MySQL connection)
- `sqlite.sqlite_path` / `sqlite.sqlite_item3d_path` (optional)
- `app.session.*` (cookie policy, idle timeout)
- `app.rate_limit.login.*` (login rate limit thresholds)

## Deployment (First Time)

1) Put this project (PHP wrapper) under a subdirectory of your web root, e.g. `/keira/`.

2) Ensure Apache + PHP are working and meet the “Server Requirements” above.

3) Build and publish Keira3 frontend assets into `keira/web/`.

On the build machine, install Node.js + npm and obtain the Keira3 source repository. In the Keira3 source directory:

```bash
# Output goes to dist/browser (example; follow your repo scripts if different)
npm run build -- -c dev-web
```

Then copy/sync the build output (`dist/browser/*`) into the runtime directory `keira/web/`.

4) Visit `/keira/` in the browser and log in with the single-user account.

## Updating

### Update Frontend (i18n/pages/components)

1) Rebuild Keira3 on the build machine
2) Update only `keira/web/` (overwrite static assets)
3) Force-refresh browser cache (Ctrl+F5)

Note: do not overwrite `keira/config/config.php` with frontend build artifacts.

### Update Backend (PHP wrapper)

1) Update `keira/index.php`, `keira/api/`, `keira/lib/`, `.htaccess`
2) If OPcache is enabled or you need immediate effect, restart Apache

## API Contract

- Auth:
  - `GET /keira/api/auth/me`
  - `POST /keira/api/auth/login` (JSON: `{username,password}`)
  - `POST /keira/api/auth/logout`
- MySQL:
  - `POST /keira/api/mysql/connect`
  - `POST /keira/api/mysql/query` (JSON: `{queryString}`)
- SQLite (optional):
  - `POST /keira/api/sqlite/query` (JSON: `{queryString, db: 'main'|'item3d'}`)

All non-auth endpoints require login (returns 401 if not logged in).
