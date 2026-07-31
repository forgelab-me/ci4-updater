# Update server

The client is a plain HTTP GET — nothing provider-specific. All
`update_server_url` has to do is resolve `{url}/latest.json` to a JSON
document with this shape:

```json
{
  "version": "1.2.0",
  "date": "2026-06-01",
  "changelog": "…",
  "zip_url": "https://.../release_1.2.0.zip",
  "manifest_url": "https://.../manifest.json"
}
```

| Field | Required | Notes |
|---|---|---|
| `version` | yes | Compared against `Config\Updater::VERSION` with semver ordering. |
| `zip_url` | yes | The release archive. Must contain `manifest.json` (the ZIP from `update:manifest` already does). |
| `manifest_url` | no | Fallback if the ZIP has no embedded manifest. |
| `date` | no | Shown in the panel. |
| `changelog` | no | Shown in the panel, collapsed. |
| `missed_roots` | no | Directories a skipped release covered and this one doesn't. See below. |
| `skipped_versions` | no | The versions being stepped over, for the same warning. |
| `required_step` | no | This response is an intermediate release, served ahead of the newest one. |
| `latest_version` | no | Where the app ends up once the step is applied. |

If `update_server_token` is set, requests carry
`Authorization: Bearer <token>`.

## Skipped releases

The panel only ever offers the latest version, so an app on 1.1 goes straight
to 1.4. That is fine as long as every release covers the same directories —
each one rewrites them wholesale. It stops being fine the moment releases
differ: if 1.2 shipped `vendor/` and 1.4 doesn't, installing 1.4 leaves
`vendor/` exactly where it was, and nothing will ever bring it up to date.

The app cannot see this on its own; it only ever hears about one release. So
it says where it is:

```
GET {update_server_url}/latest.json?from=1.1.0
```

A feed that ignores the parameter — a hand-written `latest.json`, or any
static host — answers as it always did, and the panel simply has nothing to
warn about. A feed that understands it answers with two extra fields:

```json
{
  "version": "1.4.0",
  "skipped_versions": ["1.2.0", "1.3.0"],
  "missed_roots": ["vendor"]
}
```

`missed_roots` is what the skipped releases covered **minus** what this
release covers — the directories that would be left behind. When it is empty
there is nothing to say, which is the normal case.

[`ci4-update-server`](https://github.com/forgelab-me/ci4-update-server)
computes this. If you write your own feed and only ever ship the same
directories, you can ignore the whole thing.

## Required steps

A warning depends on someone reading it. When a release genuinely must not be
jumped over, a feed can answer with **that** release instead of the newest one:

```json
{
  "version": "1.2.0",
  "zip_url": "…/1.2.0/release.zip",
  "required_step": true,
  "latest_version": "1.4.0"
}
```

The app installs 1.2.0, checks again, and is handed the next thing — walking
the path one release at a time instead of jumping to the end. Nothing changes
on the client side: it downloads and applies whatever version it is given. The
panel just says why it is being offered something that isn't the latest.

In `ci4-update-server` this is a checkbox on each release, and the project page
flags releases that look like they need it — one covering a directory the
newest release doesn't. It only ever suggests: whether skipping a release
actually matters is a judgement about your application.

## Option 1 — your own update server

Generate `latest.json` dynamically from whatever you just published. This is
the friction-free option: nothing to hand-author per release.

[`ci4-update-server`](https://github.com/forgelab-me/ci4-update-server) is a
reference implementation — multi-project, per-project tokens, release history,
upload through an admin panel. Point `update_server_url` at
`https://your-server/api/{project-slug}` and you're done.

## Option 2 — GitHub Releases

GitHub has no API endpoint returning the shape above, so there's **no
automatic integration**. What does work is GitHub's static-asset URL, which
always redirects to the latest published release's asset of a given name:

```
https://github.com/{owner}/{repo}/releases/latest/download/{file}
```

To use it:

1. Attach the ZIP produced by `php spark update:manifest` to the GitHub
   release.
2. Hand-write a `latest.json` (the JSON above) and attach it to that same
   release — GitHub won't generate it for you.
3. Set `update_server_url` to
   `https://github.com/{owner}/{repo}/releases/latest/download`.

Worth automating step 2 with a small script or CI job at release time,
otherwise it's easy to publish a release whose `latest.json` still points at
the previous one.

The ZIP extractor strips GitHub's automatic `owner-repo-<sha>/` root prefix,
so GitHub's own auto-generated source archives also work as `zip_url` if
you'd rather not attach a custom ZIP — but you still have to produce
`latest.json` yourself either way.

## Security

`update_server_url` is a trust root: everything downloaded from it gets
written over your application files. Always use `https://`. See
[Security](security.md).
