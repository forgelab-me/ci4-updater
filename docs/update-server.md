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

If `update_server_token` is set, requests carry
`Authorization: Bearer <token>`.

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
