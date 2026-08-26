# Releasing an update

How to cut a new release of **your app** so installs running ci4-updater can
pick it up. (For releasing the package itself, see
[CONTRIBUTING](../README.md#contributing).)

## 1. Bump the version

In `app/Config/Updater.php`:

```php
public const VERSION = '1.2.0';
public const DATE    = '2026-06-01';
```

Version comparison is semver-ordered, so `1.10.0` correctly beats `1.9.0`.

## 2. Build the manifest and archive

```bash
php spark update:manifest
```

If the apps you're updating require signed releases, pass the private key:

```bash
php spark update:manifest --sign /secure/path/release-signing.key
```

That adds `manifest.json.sig` to the archive. See
[Signing releases](signing.md).

### What the release needs to run

The manifest records the PHP version and extensions the release requires, read
from your `composer.json` — its `php` constraint and every `ext-*` in
`require`:

```json
"requires": { "php": "^8.2", "extensions": ["intl", "zip"] }
```

An installation that does not qualify refuses the release before touching
anything, instead of installing code its PHP cannot run. Override or drop it
per release:

```bash
php spark update:manifest --requires-php ">=8.2 <9.0" --requires-ext intl,zip
php spark update:manifest --no-requires
```

Constraints are read as `^8.2`, `~8.2`, `>=8.2`, `>=8.2 <9.0` or a bare `8.2`.
Composer's `||` is not supported — a constraint this installer cannot read is
refused rather than ignored, so keep to those forms.

To cover something other than `SCAN_DIRS` for one release — shipping `vendor/`
when dependencies changed, for instance:

```bash
php spark update:manifest --roots app,public,vendor
```

The directories are recorded in the manifest, so the next release can go back
to `app,public` without the one before it looking deleted. The receiving app
must list any extra directory in `Config\Updater::$allowedRoots` or it refuses
the release — see [Release scope](configuration.md#release-scope).

Build `vendor/` from the lock file before generating the manifest, so what you
ship is what you tested:

```bash
composer install --no-dev --optimize-autoloader
php spark update:manifest --roots app,public,vendor
```

The installed app replaces `vendor/` by swapping the directory rather than
rewriting it file by file — see [Shipping vendor/](configuration.md#shipping-vendor)
for why that matters and what it costs.

Shipping a directory in some releases and not others has one consequence worth
keeping in mind: an app that jumps from 1.1 straight to 1.4 never applies what
1.2 shipped. See
[Releases are not cumulative for a directory](configuration.md#releases-are-not-cumulative-for-a-directory).

This scans those directories, hashes every file with SHA-256, and writes:

- `manifest.json` at the project root
- `release_<version>_<timestamp>.zip`, with `manifest.json` embedded at the
  archive root

Both land at the project root — worth adding to `.gitignore`.

## 3. Publish

Upload the ZIP wherever your feed points, then make sure `latest.json`
advertises the new version and URLs. See [Update server](update-server.md).

## What happens on the client

1. **Check** — the panel fetches `{update_server_url}/latest.json` and
   compares `version` against the installed one.
2. **Download & diff** — the ZIP is fetched to `writable/tmp/`, extracted, its
   signature checked, its requirements checked against the running PHP, and its
   manifest diffed against a freshly computed manifest of the live install. The admin sees added / modified / deleted / unchanged counts and
   the full file list before anything is written.
3. **Apply** — a maintenance window opens (see the README), and every file
   about to be overwritten or deleted is copied to
   `writable/backups/backup-<timestamp>/` first. Then added and modified
   files are written (each SHA-256-verified against the manifest first),
   removed files deleted, pending migrations run via `migrate:latest`, and
   the cache cleared.
4. **Cleanup** — the maintenance window closes, temp files removed, `opcache_reset()` called so the new code
   takes effect without a server restart (when OPcache is enabled).

Cancelling at step 2 removes the temp directory and touches nothing.

## Notes

- Files present on the install but absent from the new manifest are
  **deleted** — within the release's own roots, and nowhere else. Anything
  user-generated living under one of them (uploads written into `public/`,
  for instance) would be wiped: keep that content outside, e.g. under
  `writable/`.
- A failed apply stops at the first error and reports the backup directory;
  it does not roll back on its own. Restore it from the **Backups** section
  of the update panel, which also removes the files the update added.
- **A restore reverts code, never the database.** Migrations run by an
  update stay applied, so a restored install can end up running older code
  against a newer schema. The panel flags backups whose update shipped
  migration files; revert those yourself when it matters. Writing migrations
  whose `down()` actually works is what makes that possible, and
  [how you shape them](#writing-migrations-you-can-roll-back-from) decides
  whether a rollback is usable at all.
- Migrations run after the new files are in place and before any swapped
  directory goes in, so a release can safely ship a migration that depends on
  new application code. A migration that needs a brand-new *dependency* is the
  exception — `vendor/` is swapped after — so avoid that pairing in one
  release.

## Writing migrations you can roll back from

A restore puts the code back and never the database, so the shape of a
migration decides whether the rollback is a real safety net or a broken app.

**Additive** — a new column, a new table, an index. Roll back and the old code
runs against a schema carrying something extra. Harmless in nearly every case:
the rollback works.

**Destructive** — dropping a column or table the previous code still reads.
Roll back and that code needs something that is gone. It doesn't boot. The
rollback, the very thing you were counting on, hands you a broken install.

Keep each release reversible by separating the two, one version apart:

- **v2** adds `users.email_verified_at`, stops reading `users.verified`, and
  leaves the old column alone.
- **v3** drops `users.verified`.

Rolling back v3 restores v2's code, which no longer reads that column.
Rolling back v2 restores v1's code, and the column it needs is still there.
Both are safe, because no single release both removes a column and is the
release that stopped using it.

Two caveats worth stating plainly:

- It only holds one step at a time. Going straight from v1 to v3 and then
  rolling back leaves v1's code without a column v3 removed.
- The panel counts the migration files an update shipped; it does not tell an
  `addColumn` from a `dropColumn`. Working that out would mean reading the
  migrations themselves — Forge calls, raw SQL, conditionals — and a wrong
  answer there is worse than none. Knowing which of your own migrations are
  destructive stays your call.
