# ci4-updater

[![Tests](https://github.com/forgelab-me/ci4-updater/actions/workflows/tests.yml/badge.svg)](https://github.com/forgelab-me/ci4-updater/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/forgelab-me/ci4-updater.svg)](https://packagist.org/packages/forgelab-me/ci4-updater)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A drop-in self-update system for CodeIgniter 4 apps: an admin panel that
checks a remote update server (or GitHub releases) for new versions,
downloads the release ZIP, diffs it against the live install (SHA-256
manifest), backs up changed files, applies the update, and runs pending DB
migrations — all from the browser, no SSH/git pull required.

| | |
|---|---|
| ![Dashboard](docs/screenshots/01-dashboard.jpg) *Current version, PHP/CI/DB info, migration status* | ![Update available](docs/screenshots/02-update-available.jpg) *A new release was found* |
| ![Diff review](docs/screenshots/03-diff-review.jpg) *File-level diff, before anything is written* | ![Update applied](docs/screenshots/04-update-applied.jpg) *Applied, with the DB migration run automatically* |

## Requirements

PHP 8.2+ · `ext-zip` · CodeIgniter 4.4+

## Install

```bash
composer require forgelab-me/ci4-updater
php spark updater:setup
```

`updater:setup` is a one-time step per app. It publishes an editable
`app/Config/Updater.php` and adds `service('updater')->routes($routes);` to
`app/Config/Routes.php`. Re-running it is safe: existing files are only
replaced after confirmation (or with `-f`), and the routes line is added once.

The panel itself is rendered from the package, so its improvements arrive with
`composer update`; point `$layout` at your admin layout and set `$appName`.
Pass `--views` if you'd rather own the markup.

Then, at minimum:

1. Set `VERSION`, `DATE` and `USER_AGENT` in `app/Config/Updater.php`.
2. Set `$layout` to your admin layout and `$appName` in the same file.
3. Make sure the route filter really restricts access — **read
   [Security](docs/security.md) first**.
4. Point `update_server_url` at a feed — see
   [Update server](docs/update-server.md).

Full details: [Configuration](docs/configuration.md).

## Security

These routes can overwrite any file under `app/`/`public/` and run DB
migrations. Treat them as deploy access, not as an ordinary admin page: the
filter you pass to `routes()` is the entire boundary, so gate it on an
admin-only group or permission rather than "is logged in", and serve
`update_server_url` over HTTPS only — everything it returns gets written over
your application files.

The manifest's SHA-256 check catches a corrupted download, **not** a
malicious server — it comes from that same server. To close that gap, sign
your releases: set `Config\Updater::$publicKeys` and unsigned releases are
refused from then on. See [Signing releases](docs/signing.md) and
[Security](docs/security.md).

## How it works

1. Before cutting a release you run `php spark update:manifest`. It hashes
   every file in `SCAN_DIRS` (SHA-256), writes `manifest.json`, and bundles a
   `release_X.Y.Z_*.zip` with the manifest embedded.
2. You publish that ZIP and a `latest.json` describing it —
   [`ci4-update-server`](https://github.com/forgelab-me/ci4-update-server) is
   a ready-made server for this, or use GitHub Releases.
3. In the app, `/admin/updates` checks the feed, downloads and diffs the
   release, and applies it on confirmation — backing up every changed file to
   `writable/backups/` and running pending migrations.

Step by step: [Releasing an update](docs/releasing.md).

## Documentation

- [Configuration](docs/configuration.md) — config reference, routes, custom
  settings storage, permissions
- [Update server](docs/update-server.md) — the `latest.json` contract, your
  own server or GitHub Releases
- [Security](docs/security.md) — trust model, filters, recovery
- [Signing releases](docs/signing.md) — optional signature verification,
  off by default
- [Releasing an update](docs/releasing.md) — release workflow and the full
  update pipeline

## Contributing

Issues and PRs are welcome.

```bash
composer install
composer test
composer validate --strict
```

Keep changes focused, add or update tests for behavior changes, and note
anything user-facing in [CHANGELOG.md](CHANGELOG.md).

## License

MIT — see [LICENSE](LICENSE).
