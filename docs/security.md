# Security

These routes let whoever can reach them overwrite files anywhere under
`app/`/`public/` and run pending DB migrations. Treat access to them as
equivalent to deploy or shell access to the server — not as "just another
admin page."

## The filter is the whole boundary

**The `filter` you pass to `routes()` is the only thing standing between an
authenticated user and arbitrary file writes.** Don't gate these routes on
"is logged in" alone; require an admin-only group or permission.

The package deliberately doesn't require or assume any auth system, so it
stays usable in any CI4 app. If you're on
[`codeigniter4/shield`](https://github.com/codeigniter4/shield) (a `suggest`,
not a hard dependency), scope the filter to a group or permission rather than
the generic `session` filter:

```php
// app/Config/Filters.php
public array $aliases = [
    // ...
    'admin' => \CodeIgniter\Shield\Filters\GroupFilter::class,
];

// app/Config/Routes.php
service('updater')->routes($routes, ['filter' => 'admin:superadmin']);
```

Not on Shield? Write a small filter that checks the current user's
role/permission the same way the rest of your admin area does, and pass its
alias to `routes()`.

## The update server is a trust root

Everything downloaded and written to disk is only as trustworthy as that
connection:

- Always use `https://` for `update_server_url`.
- Treat `update_server_token` as a secret. With the default store that means
  making sure `writable/updater_settings.json` isn't web-accessible or
  world-readable; with a [custom store](configuration.md#custom-settings-storage),
  wherever you route it.

## What the hash check does and doesn't do

`apply()` validates each file's SHA-256 against the manifest before writing
it. That protects against a **corrupted or truncated download** — not against
a **malicious update server**, because the manifest comes from that same
server.

The real security boundary is:

1. who can configure `update_server_url` / `update_server_token`, and
2. who can reach `/admin/updates/*`.

Not the hash check.

## Recovery

Every overwritten or deleted file is copied to
`writable/backups/backup-<timestamp>/` before the apply step touches
anything, and the panel reports the backup path on success or failure.
`UpgradeManager::rollback($backupDir)` restores from there.
