<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\UpgradeManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Manifest keys come from the update server and end up as destination paths
 * under ROOTPATH, so they are validated before anything is written.
 */
final class ManifestPathTest extends TestCase
{
    private UpgradeManager $manager;

    protected function setUp(): void
    {
        $this->manager = new UpgradeManager();
    }

    #[DataProvider('provideUnsafePaths')]
    public function testRejectsUnsafePaths(string $path): void
    {
        self::assertFalse(
            $this->manager->isSafeManifestPath($path),
            "Expected '{$path}' to be rejected"
        );
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function provideUnsafePaths(): iterable
    {
        yield 'traversal up'          => ['../outside.php'];
        yield 'traversal mid-path'    => ['app/../../outside.php'];
        yield 'traversal at end'      => ['app/..'];
        yield 'absolute posix'        => ['/etc/passwd'];
        yield 'windows drive'         => ['C:/Windows/system.ini'];
        yield 'backslash separator'   => ['app\\Config\\App.php'];
        yield 'nul byte'              => ["app/Config/App.php\0.txt"];
        yield 'empty'                 => [''];
        yield 'current dir'           => ['./app/x.php'];
        yield 'double slash'          => ['app//x.php'];
        yield 'outside scan dirs'     => ['writable/logs/log.php'];
        yield 'vendor'                => ['vendor/autoload.php'];
        yield 'env file at root'      => ['.env'];
        yield 'bare scan dir'         => ['app'];
    }

    #[DataProvider('provideSafePaths')]
    public function testAcceptsPathsInsideScannedDirectories(string $path): void
    {
        self::assertTrue(
            $this->manager->isSafeManifestPath($path),
            "Expected '{$path}' to be accepted"
        );
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function provideSafePaths(): iterable
    {
        yield 'app file'        => ['app/Config/App.php'];
        yield 'public file'     => ['public/index.php'];
        yield 'nested'          => ['app/Views/admin/updates.php'];
        yield 'dotfile nested'  => ['app/.htaccess'];
        yield 'dashes and dots' => ['public/assets/app-1.2.min.js'];
    }

    public function testComputeDiffDropsUnsafeEntriesInsteadOfReportingThem(): void
    {
        $diff = $this->manager->computeDiff([], [
            'app/good.php'        => 'hash-1',
            '../evil.php'         => 'hash-2',
            'vendor/autoload.php' => 'hash-3',
        ]);

        self::assertSame(['app/good.php'], $diff['added']);
        self::assertSame(['../evil.php', 'vendor/autoload.php'], $diff['rejected']);
    }

    /**
     * The diff crosses a request boundary in the session, so apply() must not
     * trust it either.
     */
    public function testApplyRefusesAnUnsafePathEvenWhenHandedOneDirectly(): void
    {
        $result = $this->manager->apply('/tmp/whatever/', [
            'added'    => ['../../evil.php'],
            'modified' => [],
            'deleted'  => [],
        ]);

        self::assertFalse($result['success']);
        self::assertStringContainsString('unsafe path', $result['error']);
    }

    public function testApplyRefusesAnUnsafeDeletion(): void
    {
        $result = $this->manager->apply('/tmp/whatever/', [
            'added'    => [],
            'modified' => [],
            'deleted'  => ['/etc/passwd'],
        ]);

        self::assertFalse($result['success']);
        self::assertStringContainsString('unsafe path', $result['error']);
    }
}
