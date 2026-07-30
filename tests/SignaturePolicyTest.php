<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\ReleaseSignature;
use Forgelabme\Ci4Updater\Libraries\UpgradeManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The policy, as opposed to the cryptography: no configured key means
 * signatures are ignored (the behaviour every existing install has today);
 * one configured key makes a valid signature mandatory.
 *
 * That asymmetry is the whole security property — "verify only if a signature
 * happens to be there" would let anyone who can tamper with a release simply
 * drop the signature.
 *
 * @internal
 */
final class SignaturePolicyTest extends TestCase
{
    /** @var array{private: string, public: string} */
    private static array $keys;

    public static function setUpBeforeClass(): void
    {
        self::$keys = ReleaseSignature::generateKeyPair(2048);
    }

    /**
     * @param list<string> $publicKeys
     */
    private function check(array $publicKeys, string $manifest, ?string $signature): ?string
    {
        // Private methods have been reflection-accessible without
        // setAccessible() since PHP 8.1, and that call is deprecated in 8.5.
        $method = new ReflectionMethod(UpgradeManager::class, 'checkSignature');

        return $method->invoke(new UpgradeManager($publicKeys), $manifest, $signature);
    }

    // -- No keys configured: unchanged behaviour ------------------------------

    public function testUnsignedReleaseIsAcceptedWhenNoKeyIsConfigured(): void
    {
        self::assertNull($this->check([], '{"version":"1.0.0"}', null));
    }

    public function testSignatureIsIgnoredEntirelyWhenNoKeyIsConfigured(): void
    {
        // Even nonsense in the signature slot must not break an install that
        // hasn't opted in.
        self::assertNull($this->check([], '{"version":"1.0.0"}', 'garbage'));
    }

    // -- A key is configured: signature required ------------------------------

    public function testUnsignedReleaseIsRefusedOnceAKeyIsConfigured(): void
    {
        $error = $this->check([self::$keys['public']], '{"version":"1.0.0"}', null);

        self::assertNotNull($error);
        self::assertStringContainsString('not signed', $error);
    }

    public function testEmptySignatureIsRefused(): void
    {
        self::assertNotNull($this->check([self::$keys['public']], '{"version":"1.0.0"}', "  \n "));
    }

    public function testSignatureOverDifferentBytesIsRefused(): void
    {
        $signature = ReleaseSignature::sign('{"version":"1.0.0"}', self::$keys['private']);

        $error = $this->check([self::$keys['public']], '{"version":"6.6.6"}', $signature);

        self::assertNotNull($error);
        self::assertStringContainsString('invalid', $error);
    }

    public function testSignatureFromAnUntrustedKeyIsRefused(): void
    {
        $rogue     = ReleaseSignature::generateKeyPair(2048);
        $manifest  = '{"version":"1.0.0"}';
        $signature = ReleaseSignature::sign($manifest, $rogue['private']);

        self::assertNotNull($this->check([self::$keys['public']], $manifest, $signature));
    }

    public function testCorrectlySignedReleaseIsAccepted(): void
    {
        $manifest  = '{"version":"1.0.0"}';
        $signature = ReleaseSignature::sign($manifest, self::$keys['private']);

        self::assertNull($this->check([self::$keys['public']], $manifest, $signature));
    }
}
