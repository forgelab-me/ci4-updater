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

To cover something other than `SCAN_DIRS` for one release — shipping `vendor/`
when dependencies changed, for instance:

```bash
php spark update:manifest --roots app,public,vendor
```

The directories are recorded in the manifest, so the next release can go back
to `app,public` without the one before it looking deleted. The receiving app
must list any extra directory in `Config\Updater::$allowedRoots` or it refuses
the release — see [Release scope](configuration.md#release-scope).

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
2. **Download & diff** — the ZIP is fetched to `writable/tmp/`, extracted,
   and its manifest diffed against a freshly computed manifest of the live
   install. The admin sees added / modified / deleted / unchanged counts and
   the full file list before anything is written.
3. **Apply** — every file about to be overwritten or deleted is copied to
   `writable/backups/backup-<timestamp>/` first. Then added and modified
   files are written (each SHA-256-verified against the manifest first),
   removed files deleted, pending migrations run via `migrate:latest`, and
   the cache cleared.
4. **Cleanup** — temp files removed, `opcache_reset()` called so the new code
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
  whose `down()` actually works is what makes that possible.
- Migrations run after files are in place, so a release can safely ship a
  migration that depends on new code.
