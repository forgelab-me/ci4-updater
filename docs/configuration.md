# Configuration

Everything below assumes you've run `php spark updater:setup` once — see the
[README](../README.md) for installation.

## `app/Config/Updater.php`

This file is published into your app, so `composer update` never overwrites
it. It extends the package's base config, so you only override what you need.

| Setting | What it does |
|---|---|
| `VERSION` | Current app version. Shown in the admin panel, written into the manifest, and compared against the update server's `latest.json`. Bump before every release. |
| `DATE` | Release date of the current version (`Y-m-d`). |
| `USER_AGENT` | Sent when contacting the update server, e.g. `'MyGameUpdater/1.0'`. |
| `SCAN_DIRS` | Directories making up a release — hashed, zipped, and replaced on update. `['app', 'public']` is correct for a standard CI4 layout. |
| `$settingsClass` | Where update-server settings are persisted. See [Custom settings storage](#custom-settings-storage). |
| `$keepBackups` | How many backups to keep (default 5; `0` keeps every one). See [Backups and rollback](#backups-and-rollback). |
| `$publicKeys` | Public keys trusted to sign releases. Empty by default; see [Signing releases](signing.md). |

If your project already tracks its version somewhere else, point `VERSION`
and `DATE` at that source instead of maintaining them twice.

## Adapting the admin view

`updater:setup` copies the panel to `app/Views/admin/updates.php` — it's
yours to edit, and it's never touched again.

- It `extend`s `layout/main`. That layout must provide a `content` section,
  optionally `head` and `scripts` sections, and should render flash messages
  (`session()->getFlashdata('success')` / `'error'`).
- Replace `"Your App"` with your app's name.
- Remove or replace the commented-out `admin_subnav` include if you don't
  have one.

The view uses Bootstrap 5 classes and Bootstrap Icons. If your admin area
uses something else, the markup is plain HTML — restyle it freely.

## Routes

`service('updater')->routes($routes)` defaults to prefix `admin` and filter
`admin`, registering:

```
GET  admin/updates
GET  admin/updates/check-remote
POST admin/updates/download
POST admin/updates/apply
POST admin/updates/cancel
POST admin/updates/migrate
POST admin/updates/clear-cache
POST admin/updates/rollback
POST admin/updates/backups/delete
```

Both are overridable:

```php
service('updater')->routes($routes, [
    'prefix' => 'admin',
    'filter' => 'my-admin-filter',
]);
```

A filter alias with that name must exist in `app/Config/Filters.php`. Read
[Security](security.md) before choosing it — this is the only thing guarding
these routes.

## Update-server settings

Two keys drive the client side:

| Key | Purpose |
|---|---|
| `update_server_url` | Base URL that resolves `{url}/latest.json`. See [Update server](update-server.md). |
| `update_server_token` | Optional bearer token, sent as `Authorization: Bearer <token>` when the feed is protected. |

By default they live in `writable/updater_settings.json`. Set them directly,
wire your own admin settings UI to `setSetting()`, or swap the whole store —
see below.

## Custom settings storage

The bundled `UpdaterSettings` (JSON file in `writable/`) means zero setup.
If your project already has a settings system, implement
`Forgelabme\Ci4Updater\Libraries\SettingsInterface` on it:

```php
use CodeIgniter\Model;
use Forgelabme\Ci4Updater\Libraries\SettingsInterface;

class AppSettingModel extends Model implements SettingsInterface
{
    protected $table         = 'app_settings';
    protected $primaryKey    = 'key';
    protected $allowedFields = ['key', 'value'];

    public function getSetting(string $key, mixed $default = null): mixed
    {
        $row = $this->find($key);

        return $row ? $row['value'] : $default;
    }

    public function setSetting(string $key, mixed $value): void
    {
        $this->find($key)
            ? $this->update($key, ['value' => $value])
            : $this->insert(['key' => $key, 'value' => $value]);
    }
}
```

Then point the config at it:

```php
public string $settingsClass = \App\Models\AppSettingModel::class;
```

`UpdateController` resolves this class from config on every request, so
nothing else needs to change.

> The interface methods are named `getSetting()`/`setSetting()` rather than
> `get()`/`set()` on purpose: `CodeIgniter\Model` already declares
> `set($key, $value = '', ?bool $escape = null)` for the query builder, so a
> `set()` contract would make Model subclasses — the most natural place for
> app settings — unable to implement the interface at all.

## Filesystem permissions

The web server user must be able to write to `app/`, `public/`, and
`writable/`. `UpgradeManager::checkPermissions()` verifies this before
anything is downloaded, and the panel surfaces any problem up front rather
than failing halfway through an apply.

## Backups and rollback

Every update writes a backup to `writable/backups/backup-<timestamp>/` before
touching anything, together with a `backup.json` recording the versions and the
exact diff. The **Backups** section of the update panel lists them and restores
any of them: saved files are put back, and files the update added are removed.

A restore reverts **code only**. Migrations applied by the update are not rolled
back, so the schema stays ahead of the restored code — the panel marks backups
whose update contained migration files so the choice is an informed one.

### Retention

Backups pile up otherwise, so `$keepBackups` (default 5) caps how many are
kept. Pruning runs **after an update is applied successfully** — never on a
page view — and always removes the oldest first, so the backup written by the
update you just ran is never the one deleted. Set it to `0` to keep everything
and manage the directory yourself.

Individual backups can also be deleted from the panel.
