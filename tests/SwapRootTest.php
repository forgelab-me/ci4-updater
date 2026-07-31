<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\UpgradeManager;
use PHPUnit\Framework\TestCase;

/**
 * Roots replaced as a whole directory rather than file by file.
 *
 * vendor/ is autoloaded lazily all through a request, so a per-file rewrite
 * exposes a mixed tree and an interrupted one leaves no autoloader at all.
 * These tests run on the real filesystem — see tests/bootstrap.php.
 *
 * @internal
 */
final class SwapRootTest extends TestCase
{
    private string $extractDir;

    protected function setUp(): void
    {
        foreach (['app', 'public', 'vendor', '.updater-swap'] as $dir) {
            $this->reset(ROOTPATH . $dir);
        }

        @rmdir(ROOTPATH . '.updater-swap');
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

            return;
        }

        mkdir($dir, 0777, true);
    }

    private function write(string $path, string $contents): void
    {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);
    }

    /**
     * A manager that accepts and swaps vendor/.
     */
    private function manager(): UpgradeManager
    {
        return new UpgradeManager(null, null, ['app', 'public', 'vendor'], ['vendor']);
    }

    /**
     * @param array<string, string> $files path => contents, staged in the archive
     *
     * @return array<string, string> the matching manifest
     */
    private function stage(array $files): array
    {
        $manifest = [];

        foreach ($files as $path => $contents) {
            $this->write($this->extractDir . $path, $contents);
            $manifest[$path] = hash('sha256', $contents);
        }

        return $manifest;
    }

    // -- The swap itself -------------------------------------------------------

    public function testSwappingReplacesTheWholeTreeAndNotJustTheChangedFiles(): void
    {
        // Installed: a dependency tree with a file the new release drops.
        $this->write(ROOTPATH . 'vendor/autoload.php', 'old autoload');
        $this->write(ROOTPATH . 'vendor/acme/Old.php', 'a package that goes away');
        $this->write(ROOTPATH . 'app/A.php', 'v1');

        $manifest = $this->stage([
            'app/A.php'              => 'v2',
            'vendor/autoload.php'    => 'new autoload',
            'vendor/acme/New.php'    => 'a package that arrives',
        ]);

        $result = $this->manager()->apply(
            $this->extractDir,
            ['added' => ['vendor/acme/New.php'], 'modified' => ['app/A.php', 'vendor/autoload.php'], 'deleted' => ['vendor/acme/Old.php']],
            $manifest,
            '2.0.0',
            ['app', 'vendor'],
        );

        self::assertTrue($result['success'], $result['error'] ?? '');
        self::assertSame(['vendor'], $result['swapped']);

        self::assertSame('v2', file_get_contents(ROOTPATH . 'app/A.php'));
        self::assertSame('new autoload', file_get_contents(ROOTPATH . 'vendor/autoload.php'));
        self::assertFileExists(ROOTPATH . 'vendor/acme/New.php');

        // The point of a swap: what the release doesn't list is gone, without
        // anyone having had to compute a deletion for it.
        self::assertFileDoesNotExist(ROOTPATH . 'vendor/acme/Old.php');
    }

    public function testTheSwappedTreeContainsExactlyWhatTheManifestDeclares(): void
    {
        $this->write(ROOTPATH . 'vendor/autoload.php', 'old');

        $manifest = $this->stage(['vendor/autoload.php' => 'new']);

        // Present in the archive but absent from the manifest: it must not be
        // installed, the same rule the per-file path follows.
        $this->write($this->extractDir . 'vendor/stowaway.php', 'not declared');

        $result = $this->manager()->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['vendor/autoload.php'], 'deleted' => []],
            $manifest,
            '2.0.0',
            ['vendor'],
        );

        self::assertTrue($result['success'], $result['error'] ?? '');
        self::assertFileExists(ROOTPATH . 'vendor/autoload.php');
        self::assertFileDoesNotExist(ROOTPATH . 'vendor/stowaway.php');
    }

    public function testNoStagingDirectoryIsLeftBehind(): void
    {
        $this->write(ROOTPATH . 'vendor/autoload.php', 'old');
        $manifest = $this->stage(['vendor/autoload.php' => 'new']);

        $this->manager()->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['vendor/autoload.php'], 'deleted' => []],
            $manifest,
            '2.0.0',
            ['vendor'],
        );

        self::assertDirectoryDoesNotExist(ROOTPATH . 'vendor.updater-new');
    }

    /**
     * Migrations run through this hook, and where it fires is the whole point.
     *
     * After the swap, the autoload maps in memory would describe a tree that
     * has moved. Deferred to a later request, the entire boot — filters, user
     * model — would run against the new code and the old schema, and a 500
     * there takes the panel and the rollback with it. Between the two is the
     * only place where the application code is new and the dependency tree in
     * memory still matches the one on disk.
     */
    public function testTheHookRunsAfterTheInPlaceFilesAndBeforeAnySwap(): void
    {
        $this->write(ROOTPATH . 'app/A.php', 'v1');
        $this->write(ROOTPATH . 'vendor/autoload.php', 'old autoload');

        $manifest = $this->stage([
            'app/A.php'           => 'v2',
            'vendor/autoload.php' => 'new autoload',
        ]);

        $seen = [];

        $result = $this->manager()->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['app/A.php', 'vendor/autoload.php'], 'deleted' => []],
            $manifest,
            '2.0.0',
            ['app', 'vendor'],
            static function () use (&$seen): void {
                $seen = [
                    'app'    => file_get_contents(ROOTPATH . 'app/A.php'),
                    'vendor' => file_get_contents(ROOTPATH . 'vendor/autoload.php'),
                ];
            },
        );

        self::assertTrue($result['success'], $result['error'] ?? '');

        self::assertSame('v2', $seen['app'], 'the new application code must already be on disk');
        self::assertSame('old autoload', $seen['vendor'], 'the dependency tree must not have been swapped yet');

        self::assertSame('new autoload', file_get_contents(ROOTPATH . 'vendor/autoload.php'));
    }

    // -- Failure modes ---------------------------------------------------------

    /**
     * The one failure that must never happen: the application left without the
     * directory, which would take the panel and the rollback with it.
     */
    public function testACorruptArchiveLeavesTheLiveTreeUntouched(): void
    {
        $this->write(ROOTPATH . 'vendor/autoload.php', 'the tree that must survive');

        // Order matters: the good file is staged first, so the staging tree
        // exists by the time the bad one is reached. A corrupt first file
        // would leave nothing on disk and the cleanup would go untested.
        $manifest = $this->stage([
            'vendor/autoload.php' => 'new autoload',
            'vendor/zz-corrupt.php' => 'new package',
        ]);
        $manifest['vendor/zz-corrupt.php'] = str_repeat('0', 64);

        $result = $this->manager()->apply(
            $this->extractDir,
            ['added' => ['vendor/zz-corrupt.php'], 'modified' => ['vendor/autoload.php'], 'deleted' => []],
            $manifest,
            '2.0.0',
            ['vendor'],
        );

        self::assertFalse($result['success']);
        self::assertStringContainsString('Invalid hash', $result['error']);
        self::assertSame('the tree that must survive', file_get_contents(ROOTPATH . 'vendor/autoload.php'));

        // Nothing half-built left beside the live tree, where the next update
        // would inherit it.
        self::assertDirectoryDoesNotExist(ROOTPATH . 'vendor.updater-new');
    }

    public function testAMissingFileInTheArchiveAbortsBeforeAnythingIsSwapped(): void
    {
        $this->write(ROOTPATH . 'vendor/autoload.php', 'the tree that must survive');

        $manifest = $this->stage(['vendor/autoload.php' => 'new']);
        $manifest['vendor/missing.php'] = hash('sha256', 'never staged');

        $result = $this->manager()->apply(
            $this->extractDir,
            ['added' => ['vendor/missing.php'], 'modified' => ['vendor/autoload.php'], 'deleted' => []],
            $manifest,
            '2.0.0',
            ['vendor'],
        );

        self::assertFalse($result['success']);
        self::assertSame('the tree that must survive', file_get_contents(ROOTPATH . 'vendor/autoload.php'));
    }

    /**
     * Without a manifest there is no way to know what belongs in the new tree,
     * and a swap installs a whole directory — so it is refused rather than
     * guessed at.
     */
    public function testASwapWithoutAManifestIsRefused(): void
    {
        $this->write(ROOTPATH . 'vendor/autoload.php', 'old');

        $result = $this->manager()->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['vendor/autoload.php'], 'deleted' => []],
            [],
            '2.0.0',
            ['vendor'],
        );

        self::assertFalse($result['success']);
        self::assertStringContainsString('without a manifest', $result['error']);
        self::assertSame('old', file_get_contents(ROOTPATH . 'vendor/autoload.php'));
    }

    /**
     * A scope naming vendor/ while the manifest lists nothing under it would
     * stage an empty directory and swap it in — deleting the dependency tree
     * in one move. prepare() refuses such a release, but apply() is public and
     * its arguments come back from the session, so it has to hold the line
     * itself.
     */
    public function testASwapIsRefusedWhenTheManifestListsNothingUnderTheRoot(): void
    {
        $this->write(ROOTPATH . 'vendor/autoload.php', 'the tree that must survive');
        $this->write(ROOTPATH . 'app/A.php', 'v1');

        $manifest = $this->stage(['app/A.php' => 'v2']);

        $result = $this->manager()->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['app/A.php'], 'deleted' => []],
            $manifest,
            '2.0.0',
            ['app', 'vendor'],
        );

        self::assertFalse($result['success']);
        // The specific reason matters: the two renames would also fail here,
        // and asserting only "it failed" would pass with this guard removed.
        self::assertStringContainsString('ships no file', $result['error']);
        self::assertSame('the tree that must survive', file_get_contents(ROOTPATH . 'vendor/autoload.php'));
        self::assertDirectoryDoesNotExist(ROOTPATH . 'vendor.updater-new');
    }

    // -- Backups ---------------------------------------------------------------

    /**
     * The old tree is renamed aside, not copied: on a host where disk quota is
     * the binding constraint, copying a vendor tree into writable/ before
     * replacing it would be the thing that breaks the install.
     */
    public function testThePreviousTreeIsParkedRatherThanCopiedIntoTheBackup(): void
    {
        $this->write(ROOTPATH . 'vendor/autoload.php', 'old');
        $manifest = $this->stage(['vendor/autoload.php' => 'new']);

        $result = $this->manager()->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['vendor/autoload.php'], 'deleted' => []],
            $manifest,
            '2.0.0',
            ['vendor'],
        );

        $name = basename(rtrim($result['backup_dir'], '/\\'));

        self::assertFileDoesNotExist($result['backup_dir'] . 'vendor/autoload.php');
        self::assertSame('old', file_get_contents(ROOTPATH . '.updater-swap/' . $name . '/vendor/autoload.php'));

        $meta = json_decode((string) file_get_contents($result['backup_dir'] . UpgradeManager::BACKUP_MANIFEST), true);
        self::assertSame(['vendor'], $meta['swapped']);
    }

    public function testTheParkedTreeIsNotWebReachable(): void
    {
        $this->write(ROOTPATH . 'vendor/autoload.php', 'old');
        $manifest = $this->stage(['vendor/autoload.php' => 'new']);

        $this->manager()->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['vendor/autoload.php'], 'deleted' => []],
            $manifest,
            '2.0.0',
            ['vendor'],
        );

        self::assertFileExists(ROOTPATH . '.updater-swap/.htaccess');
        self::assertFileExists(ROOTPATH . '.updater-swap/index.html');
    }

    public function testABackupReportsTheSizeOfItsParkedTree(): void
    {
        $this->write(ROOTPATH . 'vendor/big.php', str_repeat('x', 5000));
        $manifest = $this->stage(['vendor/big.php' => 'small now']);

        $this->manager()->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['vendor/big.php'], 'deleted' => []],
            $manifest,
            '2.0.0',
            ['vendor'],
        );

        $backup = $this->manager()->listBackups()[0];

        // Counting only writable/backups/ would report 0 files and 0 bytes for
        // a backup that is holding an entire dependency tree.
        self::assertSame(1, $backup['files']);
        self::assertGreaterThanOrEqual(5000, $backup['size']);
    }

    public function testDeletingABackupAlsoRemovesItsParkedTree(): void
    {
        $this->write(ROOTPATH . 'vendor/big.php', str_repeat('x', 5000));
        $manifest = $this->stage(['vendor/big.php' => 'small now']);

        $result = $this->manager()->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['vendor/big.php'], 'deleted' => []],
            $manifest,
            '2.0.0',
            ['vendor'],
        );

        $name    = basename(rtrim($result['backup_dir'], '/\\'));
        $deleted = $this->manager()->deleteBackup($name);

        self::assertTrue($deleted['success'], $deleted['error'] ?? '');
        self::assertDirectoryDoesNotExist(ROOTPATH . '.updater-swap/' . $name);
        self::assertGreaterThanOrEqual(5000, $deleted['freed']);
    }

    // -- Rollback --------------------------------------------------------------

    public function testRollingBackPutsThePreviousTreeBackWhole(): void
    {
        $this->write(ROOTPATH . 'app/A.php', 'v1');
        $this->write(ROOTPATH . 'vendor/autoload.php', 'old autoload');
        $this->write(ROOTPATH . 'vendor/acme/Old.php', 'dropped by the update');

        $manifest = $this->stage([
            'app/A.php'           => 'v2',
            'vendor/autoload.php' => 'new autoload',
            'vendor/acme/New.php' => 'added by the update',
        ]);

        $applied = $this->manager()->apply(
            $this->extractDir,
            ['added' => ['vendor/acme/New.php'], 'modified' => ['app/A.php', 'vendor/autoload.php'], 'deleted' => ['vendor/acme/Old.php']],
            $manifest,
            '2.0.0',
            ['app', 'vendor'],
        );

        self::assertTrue($applied['success'], $applied['error'] ?? '');

        $restored = $this->manager()->restoreBackup(basename(rtrim($applied['backup_dir'], '/\\')));

        self::assertTrue($restored['success'], $restored['error'] ?? '');
        self::assertSame('v1', file_get_contents(ROOTPATH . 'app/A.php'));
        self::assertSame('old autoload', file_get_contents(ROOTPATH . 'vendor/autoload.php'));

        // Both directions: what the update removed is back, what it added is gone.
        self::assertSame('dropped by the update', file_get_contents(ROOTPATH . 'vendor/acme/Old.php'));
        self::assertFileDoesNotExist(ROOTPATH . 'vendor/acme/New.php');
        self::assertDirectoryDoesNotExist(ROOTPATH . 'vendor.updater-discard');
    }

    public function testRollingBackFailsLoudlyWhenTheParkedTreeIsGone(): void
    {
        $this->write(ROOTPATH . 'vendor/autoload.php', 'old');
        $manifest = $this->stage(['vendor/autoload.php' => 'new']);

        $applied = $this->manager()->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['vendor/autoload.php'], 'deleted' => []],
            $manifest,
            '2.0.0',
            ['vendor'],
        );

        $name = basename(rtrim($applied['backup_dir'], '/\\'));
        $this->reset(ROOTPATH . '.updater-swap/' . $name);
        @rmdir(ROOTPATH . '.updater-swap/' . $name);

        $restored = $this->manager()->restoreBackup($name);

        // A rollback that quietly did half the job is what backup.json exists
        // to prevent, so a missing parked tree has to be an error.
        self::assertFalse($restored['success']);
        self::assertStringContainsString('.updater-swap', $restored['error']);
        self::assertSame('new', file_get_contents(ROOTPATH . 'vendor/autoload.php'));
    }

    // -- Off by default --------------------------------------------------------

    /**
     * An installation that hasn't opted in keeps the per-file behaviour, even
     * for a release that covers vendor/.
     */
    public function testARootThatIsNotConfiguredForSwappingIsWrittenInPlace(): void
    {
        $this->write(ROOTPATH . 'vendor/autoload.php', 'old');
        $this->write(ROOTPATH . 'vendor/keep.php', 'not in the release');

        $manifest = $this->stage(['vendor/autoload.php' => 'new']);

        $result = (new UpgradeManager(null, null, ['app', 'public', 'vendor'], []))->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['vendor/autoload.php'], 'deleted' => []],
            $manifest,
            '2.0.0',
            ['vendor'],
        );

        self::assertTrue($result['success'], $result['error'] ?? '');
        self::assertSame([], $result['swapped']);
        self::assertSame('new', file_get_contents(ROOTPATH . 'vendor/autoload.php'));

        // Written in place means untouched files stay, and the backup holds
        // the individual file rather than a parked tree.
        self::assertFileExists(ROOTPATH . 'vendor/keep.php');
        self::assertFileExists($result['backup_dir'] . 'vendor/autoload.php');
    }

    public function testSwapRootsAreInertUntilAReleaseCoversThem(): void
    {
        $this->write(ROOTPATH . 'app/A.php', 'v1');
        $manifest = $this->stage(['app/A.php' => 'v2']);

        $result = $this->manager()->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['app/A.php'], 'deleted' => []],
            $manifest,
            '2.0.0',
            ['app'],
        );

        self::assertTrue($result['success'], $result['error'] ?? '');
        self::assertSame([], $result['swapped']);
        self::assertDirectoryDoesNotExist(ROOTPATH . '.updater-swap');
    }
}
