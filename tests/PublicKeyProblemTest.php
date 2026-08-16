<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\ReleaseSignature;
use Forgelabme\Ci4Updater\Libraries\UpgradeManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Telling "the key isn't there" apart from "the signature is wrong".
 *
 * verify() skips an unreadable key and moves on, which is right — one bad
 * entry shouldn't disable the others. But it made both failures come out as
 * "the release signature is invalid", and they call for opposite fixes:
 * deploy a file, versus rebuild a release. The usual cause is $publicKeys
 * pointing into writable/, which is in no release and in no git checkout.
 *
 * @internal
 */
final class PublicKeyProblemTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (! ReleaseSignature::isAvailable()) {
            self::markTestSkipped('ext-openssl is required.');
        }

        $this->dir = WRITEPATH . 'keys-test/';
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function publicKeyFile(): string
    {
        $pair = ReleaseSignature::generateKeyPair();
        $path = $this->dir . 'release-signing.pub';
        file_put_contents($path, $pair['public']);

        return $path;
    }

    // -- inspectKeys ----------------------------------------------------------

    public function testAReadableKeyIsUsable(): void
    {
        $keys = ReleaseSignature::inspectKeys([$this->publicKeyFile()]);

        self::assertCount(1, $keys['usable']);
        self::assertSame([], $keys['unusable']);
    }

    public function testAnInlinePemIsUsable(): void
    {
        $pair = ReleaseSignature::generateKeyPair();
        $keys = ReleaseSignature::inspectKeys([$pair['public']]);

        self::assertCount(1, $keys['usable']);
    }

    /**
     * The reported case: the path is configured, the file never arrived.
     */
    public function testAMissingFileIsUnusable(): void
    {
        $keys = ReleaseSignature::inspectKeys([$this->dir . 'never-deployed.pub']);

        self::assertSame([], $keys['usable']);
        self::assertCount(1, $keys['unusable']);
    }

    public function testAFileThatIsNotAKeyIsUnusable(): void
    {
        $path = $this->dir . 'garbage.pub';
        file_put_contents($path, 'this is not a key');

        self::assertSame([], ReleaseSignature::inspectKeys([$path])['usable']);
    }

    /**
     * One bad entry must not disable a good one — that is why verify() skips
     * rather than fails, and key rotation depends on it.
     */
    public function testOneGoodKeyAmongBadOnesStillCounts(): void
    {
        $keys = ReleaseSignature::inspectKeys([
            $this->dir . 'missing.pub',
            $this->publicKeyFile(),
        ]);

        self::assertCount(1, $keys['usable']);
        self::assertCount(1, $keys['unusable']);
    }

    // -- What the panel reports -----------------------------------------------

    public function testNoProblemWhenSigningIsNotInUse(): void
    {
        self::assertNull((new UpgradeManager([]))->publicKeyProblem());
    }

    public function testNoProblemWhenTheKeyIsReadable(): void
    {
        self::assertNull((new UpgradeManager([$this->publicKeyFile()]))->publicKeyProblem());
    }

    public function testNoProblemWhileAtLeastOneKeyWorks(): void
    {
        $manager = new UpgradeManager([$this->dir . 'missing.pub', $this->publicKeyFile()]);

        self::assertNull($manager->publicKeyProblem());
    }

    /**
     * The message has to name the path and the cause, or it sends you looking
     * at your signing process instead of your deployment.
     */
    public function testTheMessageNamesThePathAndTheReason(): void
    {
        $missing = $this->dir . 'never-deployed.pub';
        $problem = (new UpgradeManager([$missing]))->publicKeyProblem();

        self::assertNotNull($problem);
        self::assertStringContainsString($missing, $problem);
        self::assertStringContainsString('writable/', $problem);
        self::assertStringNotContainsString('signature is invalid', $problem);
    }

    // -- What an actual update attempt reports --------------------------------

    /**
     * The reported bug, end to end: the update path itself must blame the
     * missing key, not the signature. Checking publicKeyProblem() alone would
     * pass even if checkSignature() never consulted it.
     */
    public function testAnUpdateAttemptBlamesTheKeyNotTheSignature(): void
    {
        $pair      = ReleaseSignature::generateKeyPair();
        $manifest  = '{"version":"1.0.0"}';
        $signature = ReleaseSignature::sign($manifest, $pair['private']);

        // A genuine signature, but the key that would check it never arrived.
        $method  = new ReflectionMethod(UpgradeManager::class, 'checkSignature');
        $problem = $method->invoke(new UpgradeManager([$this->dir . 'never-deployed.pub']), $manifest, $signature);

        self::assertNotNull($problem);
        self::assertStringContainsString('never-deployed.pub', $problem);
        self::assertStringNotContainsString('signature is invalid', $problem);
    }

    /**
     * And the opposite case still reports what it should: a readable key with
     * a signature that doesn't match is a signature problem.
     */
    public function testAWrongSignatureIsStillReportedAsASignatureProblem(): void
    {
        $other    = ReleaseSignature::generateKeyPair();
        $manifest = '{"version":"1.0.0"}';

        $method  = new ReflectionMethod(UpgradeManager::class, 'checkSignature');
        $problem = $method->invoke(
            new UpgradeManager([$this->publicKeyFile()]),
            $manifest,
            ReleaseSignature::sign($manifest, $other['private'])
        );

        self::assertNotNull($problem);
        self::assertStringContainsString('signature is invalid', $problem);
    }

    /**
     * An inline PEM must not be echoed back into a web page, even though it is
     * public — a wall of base64 in an alert helps nobody.
     */
    public function testAnInlineKeyIsNotDumpedIntoTheMessage(): void
    {
        $pem     = "-----BEGIN PUBLIC KEY-----\nnot actually valid\n-----END PUBLIC KEY-----";
        $problem = (new UpgradeManager([$pem]))->publicKeyProblem();

        self::assertNotNull($problem);
        self::assertStringContainsString('(inline key)', $problem);
        self::assertStringNotContainsString('not actually valid', $problem);
    }
}
