<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\UpgradeManager;
use PHPUnit\Framework\TestCase;

/**
 * The migration batch an update created, recorded when it is applied and
 * offered back at rollback.
 *
 * @internal
 */
final class RollbackMigrationsTest extends TestCase
{
    private UpgradeManager $manager;
    private string $extractDir;

    protected function setUp(): void
    {
        $this->manager = new UpgradeManager();

        $this->reset(ROOTPATH . 'app');
        $this->reset(WRITEPATH . 'backups');

        $this->extractDir = WRITEPATH . 'tmp/rollback-migrations/';
        $this->reset($this->extractDir);
    }

    // -- Recording -------------------------------------------------------------

    public function testWhatTheMigrationStepReportsIsKeptWithTheBackup(): void
    {
        $name = $this->applyWith(static fn (): array => ['migrations' => ['batch_before' => 7, 'batch_after' => 8]]);

        self::assertSame(['batch_before' => 7, 'batch_after' => 8], $this->manager->backupMigrations($name));
    }

    /** An update that ran no migration has no batch to offer back. */
    public function testAnUpdateThatRanNothingRecordsNoBatch(): void
    {
        $name = $this->applyWith(static fn (): array => []);

        self::assertNull($this->manager->backupMigrations($name));
    }

    public function testABatchThatDidNotMoveIsNotOfferedBack(): void
    {
        $name = $this->applyWith(static fn (): array => ['migrations' => ['batch_before' => 7, 'batch_after' => 7]]);

        self::assertNull($this->manager->backupMigrations($name));
    }

    /** Backups written before this was recorded restore as they always did. */
    public function testABackupFromBeforeThisExistedHasNoBatch(): void
    {
        $name = $this->applyWith(null);

        self::assertNull($this->manager->backupMigrations($name));
        self::assertTrue($this->manager->restoreBackup($name)['success']);
    }

    public function testRecordingDoesNotLoseTheRestOfTheBackupManifest(): void
    {
        $name = $this->applyWith(static fn (): array => ['migrations' => ['batch_before' => 1, 'batch_after' => 2]]);

        $meta = json_decode((string) file_get_contents(
            WRITEPATH . 'backups/' . $name . '/' . UpgradeManager::BACKUP_MANIFEST
        ), true);

        self::assertSame('1.4.0', $meta['to_version']);
        self::assertSame(['app'], $meta['roots']);
        self::assertSame(['app/keep.php'], $meta['diff']['modified']);
    }

    public function testTheBackupListingSaysWhichOnesCanBeReverted(): void
    {
        $withBatch = $this->applyWith(static fn (): array => ['migrations' => ['batch_before' => 2, 'batch_after' => 3]]);

        $listed = array_column($this->manager->listBackups(), 'batch', 'name');

        self::assertSame(['batch_before' => 2, 'batch_after' => 3], $listed[$withBatch]);
    }

    // -- Reverting -------------------------------------------------------------

    /**
     * Restoring deletes the update's migration files, and a down() cannot run
     * from a file that is no longer there.
     */
    public function testTheHookRunsBeforeAnyFileIsPutBack(): void
    {
        $name = $this->applyWith(static fn (): array => ['migrations' => ['batch_before' => 4, 'batch_after' => 5]]);

        $seen        = null;
        $contentThen = null;

        $this->manager->restoreBackup($name, static function (?array $batch) use (&$seen, &$contentThen): ?string {
            $seen        = $batch;
            $contentThen = file_get_contents(ROOTPATH . 'app/keep.php');

            return null;
        });

        self::assertSame(['batch_before' => 4, 'batch_after' => 5], $seen);
        self::assertSame('new', $contentThen, 'the updated file is still in place when the hook runs');
        self::assertSame('old', file_get_contents(ROOTPATH . 'app/keep.php'), 'and restored afterwards');
    }

    public function testAHookThatReportsAProblemStopsTheRestore(): void
    {
        $name = $this->applyWith(static fn (): array => ['migrations' => ['batch_before' => 4, 'batch_after' => 5]]);

        $result = $this->manager->restoreBackup($name, static fn (): string => 'the migrations could not be reverted.');

        self::assertFalse($result['success']);
        self::assertStringContainsString('could not be reverted', $result['error']);
        self::assertSame('new', file_get_contents(ROOTPATH . 'app/keep.php'), 'nothing was restored');
    }

    public function testTheHookIsToldWhenThereIsNoBatch(): void
    {
        $name = $this->applyWith(static fn (): array => []);

        $seen = 'untouched';

        $this->manager->restoreBackup($name, static function (?array $batch) use (&$seen): ?string {
            $seen = $batch;

            return null;
        });

        self::assertNull($seen);
    }

    /**
     * @param callable|null $beforeSwap
     */
    private function applyWith(?callable $beforeSwap): string
    {
        $this->write(ROOTPATH . 'app/keep.php', 'old');
        $this->write($this->extractDir . 'app/keep.php', 'new');

        $result = $this->manager->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['app/keep.php'], 'deleted' => []],
            [],
            '1.4.0',
            ['app'],
            $beforeSwap,
        );

        self::assertTrue($result['success'], $result['error'] ?? '');

        return basename($result['backup_dir']);
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
