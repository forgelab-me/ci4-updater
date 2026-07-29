# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
