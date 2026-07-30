<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\UpgradeManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real filesystem: ROOTPATH and WRITEPATH point at a scratch
 * directory (see tests/bootstrap.php), so applying an update and rolling it
 * back are run for real rather than mocked.
 *
 * @internal
 */
final class BackupRestoreTest extends TestCase
{
    private UpgradeManager $manager;
    private string $extractDir;

    protected function setUp(): void
    {
        $this->manager = new UpgradeManager();

        $this->reset(ROOTPATH . 'app');
        $this->reset(ROOTPATH . 'public');
        $this->reset(WRITEPATH . 'backups');

        $this->extractDir = WRITEPATH . 'tmp/extracted/';
        $this->reset($this->extractDir);
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

    // -- Backup name validation (the value arrives from a form post) ----------

    #[DataProvider('provideBadBackupNames')]
    public function testRejectsBackupNamesThatArentBackupNames(string $name): void
    {
        self::assertFalse($this->manager->isValidBackupName($name));

        $result = $this->manager->restoreBackup($name);
        self::assertFalse($result['success']);
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function provideBadBackupNames(): iterable
    {
        yield 'traversal'      => ['../../../etc'];
        yield 'traversal deep' => ['backup-2026-07-30-120000/../../..'];
        yield 'absolute'       => ['/etc'];
        yield 'empty'          => [''];
        yield 'wrong shape'    => ['backup-yesterday'];
        yield 'suffix'         => ['backup-2026-07-30-120000-evil'];
        yield 'nul byte'       => ["backup-2026-07-30-120000\0"];
        yield 'other dir'      => ['keys'];
    }

    public function testAcceptsAGeneratedBackupName(): void
    {
        self::assertTrue($this->manager->isValidBackupName('backup-2026-07-30-141530'));
    }

    // -- The full round trip --------------------------------------------------

    public function testRollingBackRestoresModifiedAndDeletedFilesAndRemovesAddedOnes(): void
    {
        // Installed state
        $this->write(ROOTPATH . 'app/Keep.php', 'untouched');
        $this->write(ROOTPATH . 'app/Changed.php', 'original contents');
        $this->write(ROOTPATH . 'public/Gone.php', 'about to be removed');

        // Incoming release
        $this->write($this->extractDir . 'app/Changed.php', 'new contents');
        $this->write($this->extractDir . 'app/Brand/New.php', 'added by the update');

        $diff = [
            'added'    => ['app/Brand/New.php'],
            'modified' => ['app/Changed.php'],
            'deleted'  => ['public/Gone.php'],
        ];

        $applied = $this->manager->apply($this->extractDir, $diff, [], '2.0.0');
        self::assertTrue($applied['success'], $applied['error'] ?? '');

        // The update landed
        self::assertSame('new contents', file_get_contents(ROOTPATH . 'app/Changed.php'));
        self::assertFileExists(ROOTPATH . 'app/Brand/New.php');
        self::assertFileDoesNotExist(ROOTPATH . 'public/Gone.php');

        $backups = $this->manager->listBackups();
        self::assertCount(1, $backups);
        self::assertSame('2.0.0', $backups[0]['to_version']);

        $restored = $this->manager->restoreBackup($backups[0]['name']);
        self::assertTrue($restored['success'], $restored['error'] ?? '');

        // Modified file is back, deleted file is back…
        self::assertSame('original contents', file_get_contents(ROOTPATH . 'app/Changed.php'));
        self::assertFileExists(ROOTPATH . 'public/Gone.php');
        self::assertSame('about to be removed', file_get_contents(ROOTPATH . 'public/Gone.php'));

        // …and the file the update introduced is gone, rather than left behind.
        self::assertFileDoesNotExist(ROOTPATH . 'app/Brand/New.php');
        self::assertSame(1, $restored['removed']);

        // Files the update never touched are left alone.
        self::assertSame('untouched', file_get_contents(ROOTPATH . 'app/Keep.php'));
    }

    public function testBackupRecordsWhatTheUpdateChanged(): void
    {
        $this->write(ROOTPATH . 'app/Changed.php', 'original');
        $this->write($this->extractDir . 'app/Changed.php', 'updated');

        $applied = $this->manager->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['app/Changed.php'], 'deleted' => []],
            [],
            '3.1.0'
        );

        $manifest = json_decode(
            (string) file_get_contents($applied['backup_dir'] . UpgradeManager::BACKUP_MANIFEST),
            true
        );

        self::assertSame('3.1.0', $manifest['to_version']);
        self::assertSame(['app/Changed.php'], $manifest['diff']['modified']);
        self::assertNotEmpty($manifest['created_at']);
    }

    public function testListBackupsIsNewestFirstAndCountsOnlySavedFiles(): void
    {
        $this->write(WRITEPATH . 'backups/backup-2026-01-01-000000/app/A.php', 'a');
        $this->write(WRITEPATH . 'backups/backup-2026-06-01-000000/app/B.php', 'b');
        // Not a backup directory — must be ignored entirely.
        $this->write(WRITEPATH . 'backups/notes.txt', 'x');

        $names = array_column($this->manager->listBackups(), 'name');

        self::assertSame(
            ['backup-2026-06-01-000000', 'backup-2026-01-01-000000'],
            $names
        );
        self::assertSame(1, $this->manager->listBackups()[0]['files']);
    }

    public function testRestoringAMissingBackupFails(): void
    {
        $result = $this->manager->restoreBackup('backup-2026-07-30-120000');

        self::assertFalse($result['success']);
        self::assertStringContainsString('not found', $result['error']);
    }

    /**
     * Backups taken before backup.json existed still restore what they hold.
     */
    public function testRestoresALegacyBackupWithoutMetadata(): void
    {
        $this->write(ROOTPATH . 'app/Changed.php', 'current');
        $this->write(WRITEPATH . 'backups/backup-2026-05-05-101010/app/Changed.php', 'previous');

        $result = $this->manager->restoreBackup('backup-2026-05-05-101010');

        self::assertTrue($result['success']);
        self::assertSame(1, $result['restored']);
        self::assertSame(0, $result['removed']);
        self::assertSame('previous', file_get_contents(ROOTPATH . 'app/Changed.php'));
    }

    public function testDeprecatedRollbackStillWorks(): void
    {
        $this->write(ROOTPATH . 'app/Changed.php', 'current');
        $this->write(WRITEPATH . 'backups/backup-2026-05-05-101010/app/Changed.php', 'previous');

        self::assertTrue($this->manager->rollback(WRITEPATH . 'backups/backup-2026-05-05-101010/'));
        self::assertSame('previous', file_get_contents(ROOTPATH . 'app/Changed.php'));
    }

    /**
     * A rollback never touches the database, so the panel has to be able to say
     * whether the update being undone brought migrations with it.
     */
    public function testCountsMigrationsShippedByTheUpdate(): void
    {
        $this->write(ROOTPATH . 'app/Changed.php', 'original');
        $this->write($this->extractDir . 'app/Changed.php', 'updated');
        $this->write($this->extractDir . 'app/Database/Migrations/2026-07-30-000001_AddThing.php', '<?php');
        $this->write($this->extractDir . 'app/Database/Migrations/2026-07-30-000002_AddOther.php', '<?php');

        $this->manager->apply(
            $this->extractDir,
            [
                'added' => [
                    'app/Database/Migrations/2026-07-30-000001_AddThing.php',
                    'app/Database/Migrations/2026-07-30-000002_AddOther.php',
                ],
                'modified' => ['app/Changed.php'],
                'deleted'  => [],
            ],
            [],
            '2.0.0'
        );

        self::assertSame(2, $this->manager->listBackups()[0]['migrations']);
    }

    public function testReportsNoMigrationsForAPlainCodeUpdate(): void
    {
        $this->write(ROOTPATH . 'app/Changed.php', 'original');
        $this->write($this->extractDir . 'app/Changed.php', 'updated');

        $this->manager->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['app/Changed.php'], 'deleted' => []],
            [],
            '2.0.0'
        );

        self::assertSame(0, $this->manager->listBackups()[0]['migrations']);
    }
}
