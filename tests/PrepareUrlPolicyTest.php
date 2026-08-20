<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\UpgradeManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * prepare() refuses a release URL, or strips the token from it, before
 * anything is fetched. The URLs used here answer nothing: a request that got
 * made fails later with a different message, which is what tells them apart.
 */
final class PrepareUrlPolicyTest extends TestCase
{
    private const SERVER = 'https://updates.example.com/api/my-app';

    private UpgradeManager $manager;

    protected function setUp(): void
    {
        $this->manager = new UpgradeManager();
    }

    #[DataProvider('provideRefusedUrls')]
    public function testTheArchiveUrlIsRefusedBeforeAnythingIsFetched(string $url, string $expected): void
    {
        $result = $this->manager->prepare('1.2.0', $url, null, 'secret-token', self::SERVER);

        self::assertFalse($result['success']);
        self::assertStringContainsString($expected, $result['error']);
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function provideRefusedUrls(): iterable
    {
        yield 'local file'   => ['file:///etc/passwd', 'Refusing to download over "file"'];
        yield 'php wrapper'  => ['php://filter/resource=env', 'Refusing to download over "php"'];
        yield 'ftp'          => ['ftp://example.com/r.zip', 'Refusing to download over "ftp"'];
        yield 'cleartext'    => ['http://cdn.example.net/r.zip', 'plain HTTP'];
        yield 'not a url'    => ['updates.example.com/r.zip', 'Not a usable download URL'];
        yield 'nothing'      => ['', 'No download URL'];
    }

    /** The manifest URL is fetched too, so it is held to the same rule. */
    public function testTheManifestUrlIsHeldToTheSameRule(): void
    {
        $result = $this->manager->prepare(
            '1.2.0',
            'https://updates.example.com/api/my-app/files/1.2.0/release.zip',
            'file:///etc/passwd',
            'secret-token',
            self::SERVER,
        );

        self::assertFalse($result['success']);
        self::assertStringContainsString('Refusing to download over "file"', $result['error']);
    }

    public function testARefusedUrlLeavesNoTemporaryDirectoryBehind(): void
    {
        $before = glob(WRITEPATH . 'tmp/update-*') ?: [];

        $this->manager->prepare('1.2.0', 'file:///etc/passwd', null, '', self::SERVER);

        self::assertSame($before, glob(WRITEPATH . 'tmp/update-*') ?: []);
    }

    /** A release hosted elsewhere stays fetchable — without the token. */
    public function testAThirdPartyHttpsUrlIsAllowedThrough(): void
    {
        $result = $this->manager->prepare(
            '1.2.0',
            'https://127.0.0.1:1/release.zip',
            null,
            'secret-token',
            self::SERVER,
        );

        self::assertFalse($result['success']);
        self::assertStringContainsString('Could not download', $result['error'], 'the policy allowed it; the network did not');
    }
}
