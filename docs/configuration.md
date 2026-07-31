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
| `SCAN_DIRS` | Directories making up a release you *build* — hashed, zipped, and recorded in the manifest as that release's scope. `['app', 'public']` is correct for a standard CI4 layout. |
| `$allowedRoots` | Directories a release you *receive* may cover. Empty means `SCAN_DIRS`. See [Release scope](#release-scope). |
| `$swapRoots` | Directories replaced as a whole rather than file by file (default `['vendor']`). Inert until a release covers one. See [Shipping vendor/](#shipping-vendor). |
| `$layout` | Layout the panel extends (default `layout/main`). |
| `$appName` | Name shown beside the version in the panel. |
| `$viewPath` | Pin a specific view. Null resolves automatically — see [The admin panel view](#the-admin-panel-view). |
| `$settingsClass` | Where update-server settings are persisted. See [Custom settings storage](#custom-settings-storage). |
| `$keepBackups` | How many backups to keep (default 5; `0` keeps every one). See [Backups and rollback](#backups-and-rollback). |
| `$publicKeys` | Public keys trusted to sign releases. Empty by default; see [Signing releases](signing.md). |

If your project already tracks its version somewhere else, point `VERSION`
and `DATE` at that source instead of maintaining them twice.

## Release scope

A release declares which top-level directories it covers, in its manifest:

```json
{ "version": "1.2.0", "roots": ["app", "public"], "files": { … } }
```

`update:manifest` writes it from `SCAN_DIRS`, so you normally never think
about it. It matters because of what a *missing* manifest entry means: a file
present locally but absent from the manifest is a **deletion**. That
subtraction is only meaningful if both sides are talking about the same
directories — which is why the scope travels with the release instead of
being read from each installation's own configuration.

Two consequences worth knowing:

- A directory outside the release's roots is never scanned, so it can never be
  seen as deleted. Shipping `vendor/` in one release and not the next is
  therefore safe: the second one leaves it alone.
- `$allowedRoots` is the receiving side. A release covering a directory you
  haven't listed is refused **whole**, never partially applied — an install
  that took half a release would report a version it isn't running.

```php
// app/Config/Updater.php — accept releases that ship dependencies
public array $allowedRoots = ['app', 'public', 'vendor'];
```

`writable` is refused whatever you list: it holds the backups a rollback
needs, and a release able to write there could destroy its own way back.

`$allowedRoots` guards against a misbuilt release, not against a hostile
update server — that server chooses the roots, so it can simply declare ones
you accept. [Signing](signing.md) is what defends against a compromised
server.

Manifests generated before 2.6 carry no `roots` and are read with the local
`SCAN_DIRS`, exactly as they were.

## Shipping vendor/

Leaving `vendor/` out of releases is a good default: dependencies change
rarely, and including them makes every archive tens of megabytes heavier.
When they *do* change, you can ship them:

```bash
php spark update:manifest --roots app,public,vendor
```

```php
// app/Config/Updater.php, on the installed side
public array $allowedRoots = ['app', 'public', 'vendor'];
```

The receiving app builds the dependency tree from its manifest, verifies every
file, and then puts it in place with two renames — it does not rewrite
`vendor/` file by file. That distinction is the whole feature:

- `vendor/` is autoloaded lazily throughout a request. A file-by-file rewrite
  leaves a mixed tree visible to every concurrent request for as long as the
  copy takes.
- A rewrite interrupted halfway leaves no working autoloader — which means no
  application, no admin panel, and no rollback to get back from it.

The only moment the directory does not exist is between the two renames.
POSIX has no atomic directory exchange, so that window can't be closed, but it
is microseconds against the seconds or minutes of an in-place rewrite.

The previous tree is not copied into `writable/backups/`; it is renamed aside
into `.updater-swap/<backup-name>/`, next to the application. Instant, and it
doesn't need twice the disk on a host where quota is what bites first. A
rollback renames it back, and deleting or pruning the backup removes it.

Add `/.updater-swap/` to your app's `.gitignore`.

Migrations run **between** the two halves of an update: after the new
application code is written, before any directory is swapped. That is the only
moment where the code on disk is new and the dependency tree in memory still
matches the one on disk.

Running them after the swap would load classes through autoload maps that
describe a tree that moved. Deferring them to a later request would be worse:
the whole boot — filters, user model — would run with new code against the old
schema, and a 500 there takes the panel and the rollback with it.

`$swapRoots` defaults to `['vendor']` and does nothing until a release
actually covers it. Set it to `[]` to write everything file by file.

### Releases are not cumulative for a directory

Worth understanding before shipping `vendor/` selectively. The panel offers
the latest release and nothing else, so an app on 1.1 installs 1.4 directly.
If 1.2 shipped `vendor/` and 1.4 doesn't, that jump leaves `vendor/` where it
was — 1.4 never touches it, and no later release will either.

The panel warns about it when the feed can tell:

> **v1.4.0 does not cover vendor.** Versions 1.2.0, 1.3.0 did, and installing
> v1.4.0 skips them. vendor will stay exactly as it is — apply the
> intermediate releases first if that matters.

That warning needs a feed that answers `?from=`; see
[Skipped releases](update-server.md#skipped-releases). Against a static
`latest.json` it can't be computed, and keeping track is on you.

A warning still depends on someone reading it. A feed can go further and serve
the release that must not be skipped **instead of** the newest one, so the app
walks through it — see [Required steps](update-server.md#required-steps). In
`ci4-update-server` that is a checkbox per release, suggested automatically
when the covered directories call for it.

## The admin panel view

The panel is rendered **from the package**, so interface changes arrive with
`composer update` rather than needing the view to be re-published by hand.
Two settings are usually all it takes to fit it into an app:

```php
public string $layout  = 'layouts/main';  // whatever your admin layout is called
public ?string $appName = 'My App';
```

The layout must provide a `content` section, ideally `head` and `scripts`
sections, and should render `success`/`error` flash messages. The markup uses
Bootstrap 5 and Bootstrap Icons.

### Taking the view over

If you need to change the markup itself:

```bash
php spark updater:setup --views
```

That copies it to `app/Views/admin/updates.php`, which then **wins over the
package's** — the trade-off being that later improvements to the panel won't
reach you unless you port them. Delete the file to go back to the packaged
version.

Resolution order: `$viewPath` if set → `app/Views/admin/updates.php` if it
exists → the package's view. Apps that published a view before this became
configurable keep working untouched.

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
