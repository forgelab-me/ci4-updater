# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [2.8.0] - 2026-07-31

### Added

- **The panel warns when an update steps over a release that shipped a
  directory this one doesn't.**

  Only the latest release is ever offered, so an app on 1.1 installs 1.4
  directly. Harmless while every release covers the same directories — each
  rewrites them wholesale. Not harmless once they differ: if 1.2 shipped
  `vendor/` and 1.4 doesn't, that jump leaves `vendor/` exactly where it was,
  and no later release will pick it up.

  An app cannot see this on its own; it only hears about one release. It now
  sends `?from={installed version}` when checking, and a feed that understands
  it answers with `skipped_versions` and `missed_roots`. A feed that ignores it
  — a hand-written `latest.json`, any static host — answers as before and the
  panel has nothing to warn about. Requires `ci4-update-server` 1.4 or later to
  produce the fields.

- **Required steps.** A warning depends on someone reading it. A feed can now
  answer with an intermediate release *instead of* the newest one — marked
  `required_step`, with `latest_version` saying where the app ends up. The app
  applies it, checks again, and is handed the next one, walking the path
  rather than jumping to the end.

  Nothing changes in how an update is applied: the panel downloads and installs
  whatever version it is given. It just says why it is being offered something
  that isn't the latest, so nobody wonders. In `ci4-update-server` this is a
  checkbox per release.

### Fixed

- **The `.htaccess` guarding `.updater-swap/` was missing its Apache 2.2
  branch.** It shipped a bare `Require all denied`, which is a syntax error
  without `authz_core` — Apache answers 500 for the whole subtree instead of
  simply denying it. It now carries both branches, like CodeIgniter's own
  `writable/.htaccess`. Only reachable at all on a host whose document root is
  the application root rather than `public/`, and only once a release has
  swapped a directory.

### Upgrading

Nothing to do. The extra query parameter is ignored by feeds that don't
implement it, and both the warning and the step notice appear only when the
feed says there is something to report.

## [2.7.1] - 2026-07-31

### Fixed

- **`updater:setup` crashed when a file it publishes already existed.** The
  overwrite prompt called `$this->prompt()`, which `BaseCommand` does not
  provide — it is `CLI::prompt()`. Re-running setup died with *Call to
  undefined method* instead of asking.

  Only the overwrite path was affected, which is why it survived three
  releases: a first run publishes into an empty spot and never reaches it.
  Passing `-f` also skipped it.

## [2.7.0] - 2026-07-30

Builds on 2.6, which made a release declare the directories it covers. That
made shipping `vendor/` safe to *reason* about; this release makes it safe to
*write*.

### Added

- **`vendor/` can be shipped in a release**, built on your machine from the
  lock file and installed without the target host needing Composer, network
  access to Packagist, or memory for dependency resolution:

  ```bash
  composer install --no-dev --optimize-autoloader
  php spark update:manifest --roots app,public,vendor
  ```

  The receiving app needs `Config\Updater::$allowedRoots` to list `vendor`.

- **`Config\Updater::$swapRoots`** (default `['vendor']`) — roots replaced by
  swapping the whole directory instead of writing it file by file.

  `app/` and `public/` are fine written in place. `vendor/` is not: it is
  autoloaded lazily throughout a request, so a file-by-file rewrite exposes a
  mixed tree to every concurrent request for as long as the copy takes, and a
  rewrite interrupted halfway leaves no autoloader — no application, no panel,
  no rollback to get back from it.

  A swapped root is staged beside the live one, verified file by file against
  the manifest, then put in place with two renames. The only moment the
  directory is absent is between them; POSIX offers no atomic directory
  exchange, so that window can't be closed, but it is microseconds rather than
  the seconds or minutes of an in-place rewrite.

  The setting is inert until a release actually covers one of its roots.

### Changed

- **The previous tree of a swapped root is renamed aside, not copied.** It
  lands in `.updater-swap/<backup-name>/` next to the application rather than
  in `writable/backups/`: renaming is instant and needs no second copy of a
  dependency tree, which matters most on exactly the hosts this package
  targets. `rename()` only works within one filesystem, and `writable/` is a
  separate mount often enough — a Docker volume, for one — that putting it
  there would have been a trap.

  Rollback renames it back. Deleting or pruning a backup removes it, and the
  panel counts it in that backup's size.

  Add `/.updater-swap/` to your app's `.gitignore`.

- **Migrations now run between the two halves of an update** — after the new
  application code is written, before any directory is swapped. It is the only
  moment where the code on disk is new and the dependency tree in memory still
  matches the one on disk.

  Running them after a swap would resolve classes through autoload maps
  describing a tree that moved. Deferring them to a later request would be
  worse: the full boot, filters and user model included, would run new code
  against the old schema, and a 500 there would take the panel — and the
  rollback with it. `apply()` takes a `$beforeSwap` callback for this;
  everything still happens in one request.

- A swap is refused outright without a manifest, or when the manifest lists no
  file under a declared root — staging an empty directory and swapping it in
  would delete the dependency tree in one move.

- The swapped tree contains exactly what the manifest declares, not whatever
  the archive happens to hold, which is the rule the per-file path already
  followed.

### Upgrading

Nothing to do. `$swapRoots` defaults to `['vendor']` but does nothing until a
release covers `vendor/`, and no release does unless you build one with
`--roots`.

## [2.6.0] - 2026-07-30

### Fixed

- **A release now declares the directories it covers, and a diff is computed
  only within them.**

  The scope of an update used to live in two places that nothing kept in step:
  the `SCAN_DIRS` of the machine building the release, and the `SCAN_DIRS` of
  the machine installing it. `computeDiff()` subtracted a manifest from a live
  scan of the disk while assuming both described the same directories.

  When they didn't, one direction was already safe — a release covering a
  directory the installation doesn't scan was refused outright. The other was
  not: a directory the installation scanned but the release didn't ship read
  as *every file in it deleted*. Widening `SCAN_DIRS` to `['app', 'public',
  'vendor']` and then applying any release built without `vendor/` would have
  removed the autoloader, and with it the application, the admin panel, and
  the rollback that lives in it.

  `update:manifest` now records `roots` in the manifest, and the installing
  side scans exactly those. A directory outside a release's scope is never
  looked at, so it can never be seen as deleted.

  Manifests without `roots` predate this release and are read with the local
  `SCAN_DIRS`, exactly as before. Nothing to do when upgrading.

### Added

- `Config\Updater::$allowedRoots` — the directories a release is allowed to
  cover. Empty (the default) means `SCAN_DIRS`. A release naming anything else
  is refused **whole** rather than partly applied, since an install that took
  half a release would report a version it isn't running.

  It guards against a misbuilt release, not against a hostile update server:
  that server picks the roots, so it can declare ones you accept. Signing is
  what defends against a compromised server.

- `update:manifest --roots app,public,vendor` — cover something other than
  `SCAN_DIRS` for one release. Because scope now travels with each release,
  the following one can go back to `app,public` without the previous one
  appearing deleted. This is what makes "ship `vendor/` only when dependencies
  changed" a safe workflow.

- Backups record the scope of the update they undo, so a restore works in that
  perimeter rather than in whatever is configured later. Without it, a release
  that added files outside today's `SCAN_DIRS` would leave them behind — the
  half-restore `backup.json` exists to prevent.

### Security

- Roots arrive from the update server now, where before the list was local and
  trusted. They are validated as a result: a single directory name, no
  traversal, no dot-directories, and `writable` refused whatever the policy
  says — it holds the backups a rollback depends on.
- A release declaring a directory but listing no file under it is refused. It
  cannot be told apart from a truncated manifest, and read literally it means
  "delete everything in there".
- A restore that meets a recorded path outside its own scope now fails instead
  of skipping the file silently.

## [2.5.0] - 2026-07-30

### Changed

- **The panel is now rendered from the package instead of being copied into
  every app.** Publishing it made each interface change a manual merge, and —
  since the view lives under `app/`, which updates replace — an update could
  even overwrite the panel with an older copy of itself, silently removing
  features.

  Nothing breaks: an `app/Views/admin/updates.php` published by an earlier
  `updater:setup` still wins. Resolution order is `$viewPath` if set, then the
  app's view if present, then the package's.

- `updater:setup` no longer copies the view by default. Pass `--views` to take
  it over, at the cost of porting later changes yourself.

### Added

- `Config\Updater::$layout` (default `layout/main`) and `$appName`: the two
  things that used to force a copy of the view are now configuration, so most
  apps never need one.
- `Config\Updater::$viewPath` to pin a specific view.

### Upgrading

Nothing to do. To start following the packaged panel instead of your copy,
delete `app/Views/admin/updates.php` and set `$layout` and `$appName` in
`app/Config/Updater.php` — worth doing if you never customised the markup,
since it is what brings the Backups section and later additions in
automatically.

## [2.4.0] - 2026-07-30

### Added

- **Backup retention.** Every applied update left a backup behind and nothing
  ever removed them, so `writable/backups/` grew for the lifetime of the
  install. `Config\Updater::$keepBackups` (default **5**) caps how many are
  kept; set it to `0` to keep everything and manage the directory yourself.
- Backups can be deleted individually from the panel, which also shows how much
  disk the whole set occupies and states the retention in force.
- `pruneBackups()` and `deleteBackup()`.

### Upgrading

**Pruning deletes data, so it is deliberately narrow:** it runs only after an
update has been applied successfully — never on a page view — and always
removes the oldest first, so the backup written by the update you just ran is
never the one deleted.

The default of 5 does mean that the first update applied after upgrading will
prune older backups on an install that has accumulated more than that. Set
`$keepBackups = 0` in `app/Config/Updater.php` beforehand if you would rather
keep them all.

The disk total, the retention notice and the delete buttons live in the
published view, so refresh it (`php spark updater:setup -f`, re-applying any
customisations) to see them. Pruning itself works regardless of the view.

## [2.3.0] - 2026-07-30

### Added

- **Rollback from the update panel.** `UpgradeManager::rollback()` existed but
  was never reachable: the panel reported where the backup had been written and
  left you to restore it over SSH. A **Backups** section now lists the backups
  with what each one preceded, and restores any of them in one click.
- `apply()` writes a `backup.json` alongside each backup, recording the version
  it moved from and to and the exact diff it applied.
- `listBackups()`, `restoreBackup()` and `isValidBackupName()`.
- Backups whose update shipped migration files are flagged as such, in the
  listing and in the confirmation prompt. Restoring reverts code but never the
  database, so the panel now says how many migrations that specific update
  brought in instead of leaving a generic caveat in a footnote.

### Fixed

- **A rollback used to leave the files an update had added.** It only put back
  what it had saved — the modified and deleted files — so anything the update
  introduced stayed behind. With the diff now recorded in `backup.json`, those
  files are removed as well. Backups taken before this release still restore
  what they hold; they simply can't undo additions.

### Deprecated

- `rollback(string $backupDir)` in favour of `restoreBackup(string $name)`,
  which takes a backup name rather than a path and reports what it did. The old
  method still works and delegates to the new one.

### Upgrading

The **Backups** section lives in the published view, so an app that ran
`updater:setup` before this release won't show it until the view is refreshed:
re-run `php spark updater:setup -f` (it overwrites `app/Views/admin/updates.php`,
so re-apply any customisations) or copy the section across by hand. Everything
else works without changes.

Restoring a backup reverts **code only** — migrations already applied are not
undone.

## [2.2.0] - 2026-07-30

### Added

- **Optional release signing.** `Config\Updater::$publicKeys` is empty by
  default and nothing changes: releases are trusted on the strength of the
  connection to the update server, exactly as before. List one or more trusted
  public keys and a valid signature becomes *mandatory* — an unsigned release,
  or one signed by an unknown key, is refused.

  The asymmetry is the point: verifying a signature only when one happens to be
  present would buy nothing, since whoever can tamper with a release can also
  drop the signature. Opting in is what closes the gap left by the SHA-256
  manifest, which comes from the same server as the files it describes.

  What is signed is the exact bytes of `manifest.json`; the manifest hashes
  every file, so it covers the release transitively. The private key stays with
  whoever cuts releases and never touches the update server, which becomes a
  courier: compromising it allows withholding or replaying releases, not
  publishing code.

- `php spark updater:keygen` to generate the key pair, and
  `php spark update:manifest --sign <key>` to sign a release. The signature
  travels inside the archive as `manifest.json.sig`, so no change is needed on
  the server side — a plain file server or GitHub Releases works unchanged.
- `ReleaseSignature` (RS256 via `ext-openssl`; the envelope records its
  algorithm so another can be added later). Several keys may be trusted at once
  to allow rotation.
- `prepare()` now reports `signed` in its result.

See [docs/signing.md](docs/signing.md).

## [2.1.0] - 2026-07-30

### Security

- Manifest paths are now validated before use. Keys in a release manifest come
  from the update server and become destination paths under `ROOTPATH`, so they
  are checked to be relative paths inside a configured `SCAN_DIRS` entry —
  rejecting traversal, absolute paths, Windows drive letters, backslashes and
  NUL bytes. A release whose manifest contains such a path is refused as a
  whole rather than partially applied, and `apply()` re-checks every path
  because the diff crosses a request boundary in the session.

  This closes a gap rather than a known exploit: the ZIP extractor already
  skipped unsafe entries, so an unsafe manifest key had no extracted file to
  copy. The guard no longer depends on that coincidence.

### Added

- `UpgradeManager::isSafeManifestPath()` — public, so integrators can apply the
  same rule to their own tooling.
- `computeDiff()` now returns a `rejected` key listing the entries it dropped.

## [2.0.0] - 2026-07-29

### Changed

- **BREAKING** — `SettingsInterface` methods renamed from `get()`/`set()` to
  `getSetting()`/`setSetting()`, and `UpdaterSettings` follows suit.
  `CodeIgniter\Model` already declares
  `set($key, $value = '', ?bool $escape = null)` for the query builder, so
  the old `set()` contract made it impossible for a Model subclass — the
  most natural place to keep app settings — to implement the interface
  without a wrapper class. Custom stores implementing the 1.1.0 interface
  must rename their two methods; the default JSON store and its on-disk
  format are unchanged.

## [1.1.0] - 2026-07-29

### Added

- `Forgelabme\Ci4Updater\Libraries\SettingsInterface` and
  `Config\Updater::$settingsClass`, so apps with their own settings system
  (e.g. an `AppSettingModel` / `app_settings` table) can plug it in instead
  of the default JSON-file store — see "Custom settings storage" in the
  README.

### Fixed

- `UpdateController` no longer hardcodes `new UpdaterSettings()`; it
  resolves the configured `$settingsClass` instead. Previously, the README
  documented custom settings storage as supported, but there was no actual
  way to override it.

## [1.0.0] - 2026-07-29

### Added

- Initial release.
- `php spark updater:setup` publishes an editable `app/Config/Updater.php`,
  publishes `app/Views/admin/updates.php`, and wires the admin routes into
  `app/Config/Routes.php` automatically.
- `php spark update:manifest` generates a SHA-256 manifest and a release ZIP.
- Admin panel: check a remote update server, download/diff/apply releases,
  automatic backups, automatic DB migrations, cache clearing.
- Auto-discovered `service('updater')` and Spark commands, following
  CodeIgniter 4's Composer package conventions (as used by e.g.
  `codeigniter4/shield`): no manual wiring beyond `updater:setup`.
