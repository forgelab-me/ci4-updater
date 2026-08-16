<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Libraries;

use RuntimeException;

/**
 * Detached signatures over a release manifest.
 *
 * The manifest already carries the SHA-256 of every file in the release, so
 * signing its exact bytes covers the whole payload transitively: apply() will
 * refuse any file whose hash doesn't match the manifest it verified.
 *
 * The private key never belongs on the update server — that is the entire
 * point. The server only stores and serves the signature, so compromising it
 * lets an attacker withhold or replay releases, but not forge one.
 *
 * RS256 (RSA + SHA-256 via ext-openssl) is used rather than Ed25519 because
 * ext-sodium is frequently missing on shared hosting, and PHP's OpenSSL
 * bindings don't sign Ed25519 reliably. The envelope carries the algorithm so
 * another one can be added later without breaking existing releases.
 */
final class ReleaseSignature
{
    public const ALGORITHM = 'RS256';

    private const DEFAULT_KEY_BITS = 4096;

    /**
     * @return array{private: string, public: string} PEM-encoded key pair
     */
    public static function generateKeyPair(int $bits = self::DEFAULT_KEY_BITS): array
    {
        self::assertOpenSsl();

        $key = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            throw new RuntimeException('Could not generate a key pair: ' . self::opensslErrors());
        }

        if (! openssl_pkey_export($key, $private)) {
            throw new RuntimeException('Could not export the private key: ' . self::opensslErrors());
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || ! isset($details['key'])) {
            throw new RuntimeException('Could not export the public key: ' . self::opensslErrors());
        }

        return ['private' => $private, 'public' => $details['key']];
    }

    /**
     * Signs the exact bytes given and returns the signature envelope (JSON).
     *
     * @param string $payload    Raw manifest.json bytes — never a re-encoded copy
     * @param string $privateKey PEM contents, or a path to a PEM file
     */
    public static function sign(string $payload, string $privateKey, string $passphrase = ''): string
    {
        self::assertOpenSsl();

        $pem = self::readKey($privateKey);
        $key = openssl_pkey_get_private($pem, $passphrase);

        if ($key === false) {
            throw new RuntimeException('Could not read the private key: ' . self::opensslErrors());
        }

        if (! openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signing failed: ' . self::opensslErrors());
        }

        return json_encode([
            'alg'       => self::ALGORITHM,
            'signature' => base64_encode($signature),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Verifies an envelope against the payload, accepting any of the trusted
     * keys so a key can be rotated without a flag day.
     *
     * @param list<string> $publicKeys PEM contents, or paths to PEM files
     */
    public static function verify(string $payload, string $envelope, array $publicKeys): bool
    {
        if ($publicKeys === [] || ! self::isAvailable()) {
            return false;
        }

        $decoded = json_decode(trim($envelope), true);
        if (! is_array($decoded) || ! isset($decoded['alg'], $decoded['signature'])) {
            return false;
        }

        if ($decoded['alg'] !== self::ALGORITHM) {
            return false;
        }

        $signature = base64_decode((string) $decoded['signature'], true);
        if ($signature === false || $signature === '') {
            return false;
        }

        foreach ($publicKeys as $publicKey) {
            $pem = self::readKey((string) $publicKey);
            if ($pem === '') {
                continue;
            }

            $key = openssl_pkey_get_public($pem);
            if ($key === false) {
                continue;
            }

            if (openssl_verify($payload, $signature, $key, OPENSSL_ALGO_SHA256) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function isAvailable(): bool
    {
        return function_exists('openssl_verify');
    }

    /**
     * Accepts either PEM contents or a path to a PEM file, so a deployment can
     * keep the key outside the document root instead of inlining it in config.
     */
    /**
     *
     * @param list<string> $publicKeys
     *
     * @return array{usable: list<string>, unusable: list<string>}
     */
    public static function inspectKeys(array $publicKeys): array
    {
        $usable   = [];
        $unusable = [];

        foreach ($publicKeys as $key) {
            $key = (string) $key;
            $pem = self::readKey($key);

            // Unreadable file, or readable but not a public key.
            if ($pem === '' || ! self::isAvailable() || openssl_pkey_get_public($pem) === false) {
                $unusable[] = $key;
                continue;
            }

            $usable[] = $key;
        }

        return ['usable' => $usable, 'unusable' => $unusable];
    }

    private static function readKey(string $keyOrPath): string
    {
        if (str_contains($keyOrPath, '-----BEGIN')) {
            return $keyOrPath;
        }

        $path = $keyOrPath;
        if (! is_file($path) && defined('ROOTPATH')) {
            $path = ROOTPATH . ltrim($keyOrPath, '/\\');
        }

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private static function assertOpenSsl(): void
    {
        if (! self::isAvailable()) {
            throw new RuntimeException('The openssl extension is required to sign or verify releases.');
        }
    }

    private static function opensslErrors(): string
    {
        $messages = [];
        while (($error = openssl_error_string()) !== false) {
            $messages[] = $error;
        }

        return $messages === [] ? 'unknown error' : implode('; ', $messages);
    }
}
