# ci4-updater

[![Tests](https://github.com/forgelab-me/ci4-updater/actions/workflows/tests.yml/badge.svg)](https://github.com/forgelab-me/ci4-updater/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/forgelab-me/ci4-updater.svg)](https://packagist.org/packages/forgelab-me/ci4-updater)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A drop-in self-update system for CodeIgniter 4 apps: an admin panel that
checks a remote update server (or GitHub releases) for new versions,
downloads the release ZIP, diffs it against the live install (SHA-256
manifest), backs up changed files, applies the update, and runs pending DB
migrations — all from the browser, no SSH/git pull required.

Pairs naturally with [`ci4-update-server`](https://github.com/forgelab-me/ci4-update-server),
a reference update server this package can talk to out of the box — but any
server (or GitHub Releases) that serves the expected `latest.json` shape
works too, see [Update server](#update-server).

## Table of contents

- [Screenshots](#screenshots)
- [Requirements](#requirements)
- [Install](#install)
- [After setup](#after-setup)
- [Security](#security)
- [How it works](#how-it-works)
- [Update server](#update-server)
- [Custom settings storage](#custom-settings-storage)
- [Releasing a new version](#releasing-a-new-version)
- [Notes / gotchas](#notes--gotchas)
- [Contributing](#contributing)
- [Changelog](#changelog)
- [License](#license)

## Screenshots

| | |
|---|---|
| ![Dashboard](docs/screenshots/01-dashboard.jpg) *Dashboard — current version, PHP/CI/DB info, migration status* | ![Update available](docs/screenshots/02-update-available.jpg) *"Check for updates" found a new release* |
| ![Diff review](docs/screenshots/03-diff-review.jpg) *Downloaded — file-level diff before applying anything* | ![Update applied](docs/screenshots/04-update-applied.jpg) *Applied — files updated, DB migration ran automatically* |

## Requirements

- PHP 8.2+
- `ext-zip`
- CodeIgniter 4.4+

## Install

```bash
composer require forgelab-me/ci4-updater
php spark updater:setup
```

`updater:setup` is a one-time step per app. It:

- publishes an editable `app/Config/Updater.php` (extends the package's
  base config — safe from `composer update`)
- publishes `app/Views/admin/updates.php` so you can adapt it to your layout
- adds `service('updater')->routes($routes);` to `app/Config/Routes.php`

Re-running it is safe: existing files are only touched with confirmation
(or `-f` to force), and the routes line is only added once.

## After setup

1. **Edit `app/Config/Updater.php`**:
   - `VERSION` / `DATE` — bump before every release (or point these at
     wherever your project already tracks its version).
   - `USER_AGENT` — identifies your app to the update server, e.g.
     `'MyGameUpdater/1.0'`.
   - `SCAN_DIRS` — directories that make up a release; `['app', 'public']`
     is correct for a standard CI4 layout.

2. **Adapt `app/Views/admin/updates.php`** to your layout:
   - It `extend`s `layout/main` — make sure that layout provides a
     `content` section, optionally `head`/`scripts` sections, and renders
     flash messages (`session()->getFlashdata('success'/'error')`).
   - Replace "Your App" with your app's name.
   - Remove/replace the commented-out `admin_subnav` include if you don't
     have one.

3. **Protect the routes** — `service('updater')->routes($routes)` defaults
   to prefix `admin` and filter `admin`. Make sure a filter alias named
   `admin` exists in `app/Config/Filters.php`, or pass your own:
   ```php
   service('updater')->routes($routes, ['prefix' => 'admin', 'filter' => 'my-admin-filter']);
   ```

4. **Set the update server** — `update_server_url` and `update_server_token`
   (see "Update server" below for the expected format and options). The
   default storage is a JSON file in `writable/` via `UpdaterSettings` — set
   keys directly, or wire your own admin settings UI to call
   `(new UpdaterSettings())->set(...)`.

5. **Permissions** — the web server user must be able to write to `app/`,
   `public/`, and `writable/` for the apply step to work.
   `UpgradeManager::checkPermissions()` verifies this up front and the
   controller surfaces any issue before downloading anything.

## Security

These routes let whoever can reach them overwrite files anywhere under
`app/`/`public/` and run pending DB migrations — treat access to them as
equivalent to deploy/shell access to the server, not as "just another admin
page."

- **The `filter` you pass to `routes()` is the only thing standing between
  an authenticated user and arbitrary file writes.** Don't protect these
  routes with "logged in" alone — gate them behind an admin-only
  group/permission check.
- This package doesn't require or assume any particular auth system on
  purpose, so it stays usable in any CI4 app. If you're already on
  [`codeigniter4/shield`](https://github.com/codeigniter4/shield) (listed as
  a `suggest`, not a hard dependency), scope the filter to an admin group or
  a dedicated permission instead of the generic `session` filter, e.g.:
  ```php
  // app/Config/Filters.php
  public array $aliases = [
      // ...
      'admin' => \CodeIgniter\Shield\Filters\GroupFilter::class,
  ];

  // app/Config/Routes.php
  service('updater')->routes($routes, ['filter' => 'admin:superadmin']);
  ```
  If you're not on Shield, write a small filter that checks the current
  user's role/permission the same way the rest of your admin area does, and
  pass its alias to `routes()`.
- **`update_server_url` is a trust root** — everything downloaded and
  written to disk is only as trustworthy as that connection. Always use
  `https://`, and treat `update_server_token` as a secret (it's stored via
  `UpdaterSettings`, so make sure `writable/updater_settings.json` — or
  wherever you route it — isn't web-accessible or world-readable).
- `apply()` does validate each file's SHA-256 against the manifest before
  writing it, which protects against a corrupted/truncated download — but
  it does **not** protect against a malicious update server, since the
  manifest itself comes from that same server. The security boundary is
  "who can configure `update_server_url`/token" and "who can reach
  `/admin/updates/*`", not the hash check.

## How it works

1. **You** run `php spark update:manifest` before cutting a release. It
   scans `app/` + `public/` (or whatever `SCAN_DIRS` says), hashes every
   file (SHA-256), writes `manifest.json`, and bundles everything into a
   ready-to-upload `release_X.Y.Z_*.zip` (the manifest is embedded in the
   zip).
2. You publish that ZIP somewhere reachable over HTTP(S) — see "Update
   server" below.
3. **The admin panel** (`/admin/updates` by default) lets an admin: see
   current version/PHP/CI/DB info, check the remote server, download + diff
   the new release, review the file-level diff, apply it (with automatic
   backup to `writable/backups/` + automatic `migrate:latest` + cache
   clear), or roll back by restoring from the backup folder.

## Update server

`update_server_url` just needs to resolve `{url}/latest.json` to a JSON
document with this exact shape — the client is a plain HTTP GET, nothing
provider-specific:

```json
{
  "version": "1.2.0",
  "date": "2026-06-01",
  "changelog": "…",
  "zip_url": "https://.../release_1.2.0.zip",
  "manifest_url": "https://.../manifest.json"
}
```

There are two common ways to serve that:

- **Your own update server** (a tiny endpoint, or a full app) — generate
  `latest.json` dynamically from whatever you just published. This is the
  friction-free option: nothing to hand-author per release. See
  [`ci4-update-server`](https://github.com/forgelab-me/ci4-update-server) for
  a reference implementation.
- **GitHub Releases** — GitHub has no API endpoint that returns this exact
  shape, so there's no automatic integration; what does work is GitHub's
  static-asset URL, which always redirects to the latest published release's
  asset of a given name: `https://github.com/{owner}/{repo}/releases/latest/download/{file}`.
  To use it:
  1. Attach the ZIP from `update:manifest` to the GitHub release.
  2. Also hand-write and attach a `latest.json` asset (the JSON above) to
     that same release — GitHub won't generate it for you.
  3. Set `update_server_url` to
     `https://github.com/{owner}/{repo}/releases/latest/download`.

  The ZIP extractor strips GitHub's automatic `owner-repo-<sha>/` root
  prefix, so GitHub's own auto-generated source archives work as `zip_url`
  too if you'd rather skip attaching a custom ZIP — but you're still
  responsible for producing `latest.json` yourself either way, e.g. from a
  small script/CI step run at release time.

## Custom settings storage

The package ships with `UpdaterSettings` (a JSON file in `writable/`) so it
works with zero setup. If your project already has a settings system (e.g.
an `AppSettingModel` / `app_settings` table), write your own class
implementing `Forgelabme\Ci4Updater\Libraries\SettingsInterface`
(`get(string $key, $default = null)` / `set(string $key, $value)`), then
point `Config\Updater::$settingsClass` at it in your published
`app/Config/Updater.php`:

```php
public string $settingsClass = \App\Libraries\MySettingsAdapter::class;
```

`UpdateController` resolves the settings class from config on every request
— it never hardcodes `UpdaterSettings`, so nothing else needs to change.

## Releasing a new version

```bash
# bump Config\Updater::VERSION / DATE first, then:
php spark update:manifest
# → creates manifest.json + release_X.Y.Z_<timestamp>.zip at project root
# publish the zip (your update server, or a GitHub release asset), then
# make sure latest.json points at it — see "Update server" above
```

## Notes / gotchas

- Every overwritten/deleted file is backed up to
  `writable/backups/backup-<timestamp>/` before the apply step touches
  anything — `UpgradeManager::rollback($backupDir)` restores from there.
- `opcache_reset()` is called after applying, so changes take effect
  immediately without a server restart (if OPcache is enabled).

## Contributing

Issues and PRs are welcome.

```bash
composer install
composer test        # run the test suite
composer validate --strict
```

Keep changes focused, add/update tests for behavior changes, and update
[CHANGELOG.md](CHANGELOG.md) for anything user-facing.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

## License

MIT — see [LICENSE](LICENSE).
