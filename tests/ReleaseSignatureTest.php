<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\ReleaseSignature;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ReleaseSignatureTest extends TestCase
{
    /** @var array{private: string, public: string} */
    private static array $keys;

    /** @var array{private: string, public: string} */
    private static array $otherKeys;

    public static function setUpBeforeClass(): void
    {
        // 2048 bits keeps the suite fast; production keys default to 4096.
        self::$keys      = ReleaseSignature::generateKeyPair(2048);
        self::$otherKeys = ReleaseSignature::generateKeyPair(2048);
    }

    private function manifest(): string
    {
        return json_encode([
            'version' => '1.2.0',
            'files'   => ['app/Config/App.php' => str_repeat('a', 64)],
        ], JSON_PRETTY_PRINT) . "\n";
    }

    public function testAcceptsASignatureMadeWithTheMatchingKey(): void
    {
        $payload   = $this->manifest();
        $signature = ReleaseSignature::sign($payload, self::$keys['private']);

        self::assertTrue(ReleaseSignature::verify($payload, $signature, [self::$keys['public']]));
    }

    public function testRejectsATamperedManifest(): void
    {
        $payload   = $this->manifest();
        $signature = ReleaseSignature::sign($payload, self::$keys['private']);

        // A single flipped hash must invalidate the whole release.
        $tampered = str_replace(str_repeat('a', 64), str_repeat('b', 64), $payload);

        self::assertNotSame($payload, $tampered);
        self::assertFalse(ReleaseSignature::verify($tampered, $signature, [self::$keys['public']]));
    }

    public function testRejectsEvenAWhitespaceChange(): void
    {
        $payload   = $this->manifest();
        $signature = ReleaseSignature::sign($payload, self::$keys['private']);

        self::assertFalse(ReleaseSignature::verify($payload . ' ', $signature, [self::$keys['public']]));
    }

    public function testRejectsASignatureFromAnUntrustedKey(): void
    {
        $payload   = $this->manifest();
        $signature = ReleaseSignature::sign($payload, self::$otherKeys['private']);

        self::assertFalse(ReleaseSignature::verify($payload, $signature, [self::$keys['public']]));
    }

    /**
     * Listing several keys is what makes rotation possible without a flag day.
     */
    public function testAcceptsAnyOfTheTrustedKeys(): void
    {
        $payload   = $this->manifest();
        $signature = ReleaseSignature::sign($payload, self::$otherKeys['private']);

        self::assertTrue(ReleaseSignature::verify(
            $payload,
            $signature,
            [self::$keys['public'], self::$otherKeys['public']]
        ));
    }

    public function testRejectsWhenNoKeyIsTrusted(): void
    {
        $payload   = $this->manifest();
        $signature = ReleaseSignature::sign($payload, self::$keys['private']);

        self::assertFalse(ReleaseSignature::verify($payload, $signature, []));
    }

    public function testRejectsMalformedEnvelopes(): void
    {
        $payload = $this->manifest();
        $keys    = [self::$keys['public']];

        foreach (['', 'not json', '{}', '{"alg":"RS256"}', '{"signature":"AAAA"}', '[]'] as $envelope) {
            self::assertFalse(
                ReleaseSignature::verify($payload, $envelope, $keys),
                "Expected the envelope " . var_export($envelope, true) . ' to be rejected'
            );
        }
    }

    public function testRejectsAnUnknownAlgorithm(): void
    {
        $payload   = $this->manifest();
        $signature = ReleaseSignature::sign($payload, self::$keys['private']);

        $downgraded = str_replace('RS256', 'none', $signature);

        self::assertFalse(ReleaseSignature::verify($payload, $downgraded, [self::$keys['public']]));
    }

    public function testAcceptsAKeyGivenAsAFilePath(): void
    {
        $payload   = $this->manifest();
        $signature = ReleaseSignature::sign($payload, self::$keys['private']);

        $path = tempnam(sys_get_temp_dir(), 'pub');
        file_put_contents($path, self::$keys['public']);

        try {
            self::assertTrue(ReleaseSignature::verify($payload, $signature, [$path]));
        } finally {
            unlink($path);
        }
    }

    public function testSignsFromAPrivateKeyFilePath(): void
    {
        $payload = $this->manifest();

        $path = tempnam(sys_get_temp_dir(), 'key');
        file_put_contents($path, self::$keys['private']);

        try {
            $signature = ReleaseSignature::sign($payload, $path);
            self::assertTrue(ReleaseSignature::verify($payload, $signature, [self::$keys['public']]));
        } finally {
            unlink($path);
        }
    }
}
