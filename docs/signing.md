# Signing releases

Signing is **optional and off by default**. Without it, an update is trusted
because it came from the configured update server over HTTPS — which means
whoever controls that server controls the code running in every app pointed at
it. The SHA-256 manifest doesn't change this: it catches a corrupted download,
not a hostile one, because the manifest comes from the same place as the files.

Signing moves that boundary. You sign the manifest with a private key that
never leaves your machine; each app verifies it against a public key baked into
its own config. The update server becomes a courier: compromising it lets an
attacker withhold or replay releases, but no longer publish code.

## The rule that makes it work

- `Config\Updater::$publicKeys` empty (default) → signatures are ignored
  entirely. Behaviour is exactly what it was before this feature existed.
- One or more keys listed → **a valid signature becomes mandatory**. An
  unsigned release, or one signed by an unknown key, is refused.

The asymmetry is deliberate. A "verify the signature if there is one" policy
would be worthless: anyone able to tamper with a release can also delete the
signature. Opting in is what closes that door.

## Setting it up

### 1. Generate a key pair

```bash
php spark updater:keygen
```

Writes `writable/keys/release-signing.key` (private) and
`release-signing.pub` (public). Use `--out` for another directory, `--bits` for
another size (4096 by default).

**Move the private key somewhere safe and out of the project.** It must not be
committed, and above all it must not sit on the update server — putting it
there gives back exactly the power signing was meant to remove. The command
refuses to overwrite existing keys, since that would invalidate every release
already signed with them.

### 2. Trust the public key in each app

In the app's published `app/Config/Updater.php`:

```php
public array $publicKeys = [
    WRITEPATH . 'keys/release-signing.pub',
];
```

Each entry is either a path to a PEM file (relative paths resolve from
`ROOTPATH`) or the PEM contents inline. From this point on, that install only
accepts signed releases.

### 3. Sign each release

```bash
php spark update:manifest --sign /secure/path/release-signing.key
```

Add `--passphrase` if the key is protected by one. This writes
`manifest.json.sig` next to `manifest.json` and embeds both in the release ZIP,
so the signature travels with the release and needs no change on the server
side — a plain file server or GitHub Releases works unchanged.

## Rotating a key

List both keys while the changeover happens:

```php
public array $publicKeys = [
    WRITEPATH . 'keys/release-signing.pub',      // new
    WRITEPATH . 'keys/release-signing-old.pub',  // retiring
];
```

A release verifies if *any* listed key matches, so installs can move over at
their own pace. Drop the old entry once every release in circulation is signed
with the new key.

## What is actually signed

The exact bytes of `manifest.json`, not the ZIP. The manifest holds the
SHA-256 of every file in the release, and `apply()` re-checks each file against
it before writing, so signing the manifest covers the whole payload. Because
the signature is over the literal bytes, even a whitespace change invalidates
it — never regenerate or reformat a manifest after signing it.

Where the signature is stored has no bearing on security: its value comes from
the key that produced it. Inside the ZIP is simply the most convenient place.

## Requirements and limits

- Needs `ext-openssl` on both sides. If an install requires signatures and
  OpenSSL isn't available, updates are refused rather than silently accepted.
- The algorithm is RS256 (RSA + SHA-256). Ed25519 was considered and rejected:
  `ext-sodium` is often absent from shared hosting, and PHP's OpenSSL bindings
  don't sign Ed25519 reliably. The signature envelope records its algorithm, so
  another one can be added later without invalidating existing releases.
- Signing proves *who built the release*, not that the release is good. A
  compromised build machine signs malicious code just as happily.
