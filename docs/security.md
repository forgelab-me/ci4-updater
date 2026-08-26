# Security

These routes let whoever can reach them overwrite files anywhere under the
directories a release is allowed to cover — `app/` and `public/` by default,
plus anything added to `Config\Updater::$allowedRoots` — and run pending DB
migrations. Treat access to them as equivalent to deploy or shell access to
the server, not as "just another admin page."

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

## CSRF is added for you

`routes()` puts the `csrf` filter on its own group, alongside whatever filter
you passed. This is not left to the application on purpose: a stock CodeIgniter
install ships the global csrf filter commented out, and these routes write PHP
into `app/` — an admin who merely visits a hostile page would otherwise be
enough to apply a release somebody else chose.

CodeIgniter de-duplicates filters, so enabling csrf globally as well is
harmless. If your own code posts to `/admin/updates/*`, it needs a token like
any other form; the panel's own JavaScript reads it from the `csrf-field` and
`csrf-hash` meta tags the view renders.

## The update server is a trust root

Everything downloaded and written to disk is only as trustworthy as that
connection:

- Always use `https://` for `update_server_url`. The panel warns when it is
  not: everything that server answers is written into this application, so a
  cleartext connection is a stranger's write access to `app/`.
- Treat `update_server_token` as a secret. With the default store that means
  making sure `writable/updater_settings.json` isn't web-accessible or
  world-readable; with a [custom store](configuration.md#custom-settings-storage),
  wherever you route it.

### What may be downloaded, and what sees the token

A release URL reaches this app through the panel's form, filled from whatever
the update server answered — so it is checked before anything is fetched:

| | |
|---|---|
| Scheme | `https://` anywhere; `http://` only back to a server configured that way. Anything else — `file://`, `php://`, `ftp://` — is refused, so a release URL can never become a local-file read. |
| Token | Sent only to the configured server's own origin. A release hosted elsewhere (GitHub assets, an object store) is fetched without it. |

Redirects are followed by hand for the same reason: PHP's `follow_location`
replays the `Authorization` header at every hop whatever the new host is, so
one 302 would hand the token to a stranger. The token is dropped as soon as the
origin changes.

An archive served from another origin therefore has to be reachable without the
token. Signing releases is what makes that safe to arrange.

### The scope of an update comes from the server too

A release declares which top-level directories it covers, and that
declaration is read from the manifest — so it arrives from the update server
like everything else. Two things follow.

Those names become directories written under `ROOTPATH`, so they are
validated before use: a single directory name, no traversal, no
dot-directories, and `writable` refused whatever the configuration says —
it holds the backups a rollback needs.

`Config\Updater::$allowedRoots` caps what a release may declare, but it is a
guard against a **misbuilt release, not a hostile server**: that server picks
the roots, so it can simply declare ones you accept. Widening it to include
`vendor` widens what a compromised server could overwrite. Signing is what
actually defends against that.

## What the hash check does and doesn't do

`apply()` validates each file's SHA-256 against the manifest before writing
it. That protects against a **corrupted or truncated download** — not against
a **malicious update server**, because the manifest comes from that same
server.

The real security boundary is:

1. who can configure `update_server_url` / `update_server_token`, and
2. who can reach `/admin/updates/*`.

Not the hash check.

To stop trusting the server itself, sign your releases: with
`Config\Updater::$publicKeys` set, a release is only applied if its manifest
carries a signature made by a key you trust, so a compromised update server
can no longer push code. The signature covers the manifest's bytes, which
include the directories the release declares — so the scope is as trustworthy
as the file list. See [Signing releases](signing.md).

## Recovery

Every overwritten or deleted file is copied to
`writable/backups/backup-<timestamp>/` before the apply step touches
anything, along with a `backup.json` recording what the update changed.
The **Backups** section of the update panel restores any of them: files are
put back as they were, and files the update added are removed.

A root replaced by a whole-directory swap is the exception: its previous tree
is renamed aside into `.updater-swap/<backup-name>/` next to the application
rather than copied into `writable/`, and a restore renames it back. That
directory holds a full copy of a previous `vendor/`, so it gets the same
`.htaccess` guard `writable/` does — worth checking if your document root is
the application root rather than `public/`.

Backups are addressed by name, never by path, and the name is matched
against `backup-YYYY-MM-DD-HHMMSS` before use — the value arrives from a
form post.

Database migrations are reverted only if you ask: a restore shows a *revert
migrations* box when the update recorded the batch it ran, and ticking it runs
that batch's `down()` before the files go back. Left unticked — the default —
rolling back restores code only, and the schema stays ahead of it.

Only the last `Config\Updater::$keepBackups` backups are kept (5 by default);
older ones are pruned after a successful update. Raise it if you need to be
able to reach further back.
