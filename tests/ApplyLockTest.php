<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\ApplyLock;
use Forgelabme\Ci4Updater\Libraries\MaintenanceWindow;
use Forgelabme\Ci4Updater\Libraries\UpgradeManager;
use PHPUnit\Framework\TestCase;

/**
 * Only one update or restore writes at a time.
 *
 * @internal
 */
final class ApplyLockTest extends TestCase
{
    private UpgradeManager $manager;
    private string $extractDir;

    protected function setUp(): void
    {
        $this->manager = new UpgradeManager();

        MaintenanceWindow::close();

        $this->reset(ROOTPATH . 'app');
        $this->reset(WRITEPATH . 'backups');

        $this->extractDir = WRITEPATH . 'tmp/apply-lock/';
        $this->reset($this->extractDir);
    }

    protected function tearDown(): void
    {
        MaintenanceWindow::close();
    }

    // -- The lock --------------------------------------------------------------

    public function testTheSecondAcquisitionIsRefusedUntilTheFirstReleases(): void
    {
        $first = ApplyLock::acquire();
        self::assertNotNull($first);

        self::assertNull(ApplyLock::acquire());

        $first->release();

        $again = ApplyLock::acquire();
        self::assertNotNull($again);
        $again->release();
    }

    // -- Applying --------------------------------------------------------------

    public function testApplyIsRefusedWhileAnotherHoldsTheLock(): void
    {
        $this->write(ROOTPATH . 'app/keep.php', 'old');
        $this->write($this->extractDir . 'app/keep.php', 'new');

        $lock = ApplyLock::acquire();

        try {
            $result = $this->applyKeep();
        } finally {
            $lock->release();
        }

        self::assertFalse($result['success']);
        self::assertSame(ApplyLock::busyMessage(), $result['error']);
        self::assertSame('old', file_get_contents(ROOTPATH . 'app/keep.php'), 'nothing was written');
        self::assertSame([], glob(WRITEPATH . 'backups/*') ?: [], 'and no backup was started');
    }

    /** The refused one must not close a window the running one opened. */
    public function testARefusedApplyLeavesTheRunningOnesWindowOpen(): void
    {
        $this->write(ROOTPATH . 'app/keep.php', 'old');
        $this->write($this->extractDir . 'app/keep.php', 'new');

        MaintenanceWindow::open('the running update');
        $lock = ApplyLock::acquire();

        try {
            $this->applyKeep();
        } finally {
            $lock->release();
        }

        self::assertTrue(MaintenanceWindow::isOpen());
        self::assertSame('the running update', MaintenanceWindow::state()['reason']);
    }

    public function testTheLockIsReleasedAfterASuccessfulApply(): void
    {
        $this->write(ROOTPATH . 'app/keep.php', 'old');
        $this->write($this->extractDir . 'app/keep.php', 'new');

        self::assertTrue($this->applyKeep()['success']);

        $lock = ApplyLock::acquire();
        self::assertNotNull($lock);
        $lock->release();
    }

    /** A failed apply must not leave the next one refused. */
    public function testTheLockIsReleasedAfterAFailedApply(): void
    {
        $result = $this->manager->apply(
            $this->extractDir,
            ['added' => ['app/missing.php'], 'modified' => [], 'deleted' => []],
            [],
            '1.4.0',
            ['app'],
        );

        self::assertFalse($result['success']);

        $lock = ApplyLock::acquire();
        self::assertNotNull($lock);
        $lock->release();
    }

    // -- Restoring -------------------------------------------------------------

    public function testRestoreIsRefusedWhileAnotherHoldsTheLock(): void
    {
        $this->write(ROOTPATH . 'app/keep.php', 'old');
        $this->write($this->extractDir . 'app/keep.php', 'new');

        $name = basename($this->applyKeep()['backup_dir']);

        $lock = ApplyLock::acquire();

        try {
            $result = $this->manager->restoreBackup($name);
        } finally {
            $lock->release();
        }

        self::assertFalse($result['success']);
        self::assertSame(ApplyLock::busyMessage(), $result['error']);
        self::assertSame('new', file_get_contents(ROOTPATH . 'app/keep.php'), 'nothing was restored');
    }

    public function testTheLockIsReleasedAfterARestore(): void
    {
        $this->write(ROOTPATH . 'app/keep.php', 'old');
        $this->write($this->extractDir . 'app/keep.php', 'new');

        $name = basename($this->applyKeep()['backup_dir']);

        self::assertTrue($this->manager->restoreBackup($name)['success']);

        $lock = ApplyLock::acquire();
        self::assertNotNull($lock);
        $lock->release();
    }

    // -- Backup names ----------------------------------------------------------

    /**
     * Names have one-second resolution, so two updates in the same second used
     * to share a directory and overwrite each other's backup.json.
     */
    public function testTwoUpdatesInTheSameSecondKeepSeparateBackups(): void
    {
        $this->write(ROOTPATH . 'app/keep.php', 'old');

        $this->write($this->extractDir . 'app/keep.php', 'new');
        $first = $this->applyKeep();

        $this->write($this->extractDir . 'app/keep.php', 'newer');
        $second = $this->applyKeep();

        self::assertTrue($first['success'] && $second['success']);
        self::assertNotSame($first['backup_dir'], $second['backup_dir']);
        self::assertCount(2, $this->manager->listBackups());

        // Each backup holds what its own update replaced.
        self::assertSame('old', file_get_contents($first['backup_dir'] . 'app/keep.php'));
        self::assertSame('new', file_get_contents($second['backup_dir'] . 'app/keep.php'));

        self::assertTrue($this->manager->restoreBackup(basename($second['backup_dir']))['success']);
        self::assertSame('new', file_get_contents(ROOTPATH . 'app/keep.php'));
    }

    /**
     * @return array<string, mixed>
     */
    private function applyKeep(): array
    {
        return $this->manager->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['app/keep.php'], 'deleted' => []],
            [],
            '1.4.0',
            ['app'],
        );
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
