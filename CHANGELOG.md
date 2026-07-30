# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
