<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\MaintenanceWindow;
use Forgelabme\Ci4Updater\Libraries\UpgradeManager;
use PHPUnit\Framework\TestCase;

/**
 * The window an update holds open while it writes.
 *
 * @internal
 */
final class MaintenanceWindowTest extends TestCase
{
    private UpgradeManager $manager;
    private string $extractDir;

    protected function setUp(): void
    {
        $this->manager = new UpgradeManager();

        MaintenanceWindow::close();

        $this->reset(ROOTPATH . 'app');
        $this->reset(WRITEPATH . 'backups');

        $this->extractDir = WRITEPATH . 'tmp/maintenance/';
        $this->reset($this->extractDir);
    }

    protected function tearDown(): void
    {
        MaintenanceWindow::close();
    }

    // -- The flag itself -------------------------------------------------------

    public function testOpeningAndClosing(): void
    {
        self::assertFalse(MaintenanceWindow::isOpen());

        MaintenanceWindow::open('Applying 1.4.0');

        self::assertTrue(MaintenanceWindow::isOpen());
        self::assertSame('Applying 1.4.0', MaintenanceWindow::state()['reason']);

        MaintenanceWindow::close();

        self::assertFalse(MaintenanceWindow::isOpen());
        self::assertNull(MaintenanceWindow::state());
    }

    /**
     * An update killed halfway would otherwise leave the site answering 503
     * until somebody deleted a file by hand.
     */
    public function testAnExpiredWindowIsNotOpenAndIsCleanedUp(): void
    {
        MaintenanceWindow::open('stale', 1);

        file_put_contents(MaintenanceWindow::path(), json_encode([
            'started_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
            'expires_at' => time() - 60,
            'reason'     => 'stale',
        ]));

        self::assertFalse(MaintenanceWindow::isOpen());
        self::assertFileDoesNotExist(MaintenanceWindow::path());
    }

    public function testARubbishFlagFileIsNotAWindow(): void
    {
        file_put_contents(MaintenanceWindow::path(), 'not json');

        self::assertFalse(MaintenanceWindow::isOpen());
    }

    public function testRetryAfterCountsDownAndFallsBackTo60(): void
    {
        self::assertSame(60, MaintenanceWindow::retryAfter());

        MaintenanceWindow::open('working', 120);

        self::assertGreaterThan(60, MaintenanceWindow::retryAfter());
        self::assertLessThanOrEqual(120, MaintenanceWindow::retryAfter());
    }

    // -- Around an apply -------------------------------------------------------

    public function testTheWindowIsOpenWhileFilesAreBeingWrittenAndClosedAfter(): void
    {
        $this->write(ROOTPATH . 'app/keep.php', 'old');
        $this->write($this->extractDir . 'app/keep.php', 'new');

        $openDuringWrite = null;

        $result = $this->manager->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['app/keep.php'], 'deleted' => []],
            [],
            '1.4.0',
            ['app'],
            static function () use (&$openDuringWrite): void {
                $openDuringWrite = MaintenanceWindow::isOpen();
            },
        );

        self::assertTrue($result['success']);
        self::assertTrue($openDuringWrite, 'the window must be open while the files are being written');
        self::assertFalse(MaintenanceWindow::isOpen(), 'and closed once they are');
    }

    /** A failed apply must not leave the site answering 503. */
    public function testTheWindowIsClosedWhenApplyingFails(): void
    {
        $result = $this->manager->apply(
            $this->extractDir,
            ['added' => ['app/missing.php'], 'modified' => [], 'deleted' => []],
            [],
            '1.4.0',
            ['app'],
        );

        self::assertFalse($result['success']);
        self::assertStringContainsString('not found in archive', $result['error']);
        self::assertFalse(MaintenanceWindow::isOpen());
    }

    /** Refused before any writing, so no window was ever opened. */
    public function testARefusedApplyOpensNoWindow(): void
    {
        $result = $this->manager->apply(
            $this->extractDir,
            ['added' => ['../escape.php'], 'modified' => [], 'deleted' => []],
            [],
            '1.4.0',
            ['app'],
        );

        self::assertFalse($result['success']);
        self::assertFileDoesNotExist(MaintenanceWindow::path());
    }

    private function reset(string $dir): void
    {
        if (is_dir($dir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        } else {
            mkdir($dir, 0777, true);
        }
    }

    private function write(string $path, string $contents): void
    {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);
    }
}
