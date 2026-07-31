<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\ReleaseScope;
use Forgelabme\Ci4Updater\Libraries\UpgradeManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The scope of an update is declared by the release, not by the installation.
 *
 * These tests pin the failure this replaced: when both sides kept their own
 * idea of which directories were in play, a release that didn't ship a
 * directory the installation scanned made every file in it look deleted.
 *
 * @internal
 */
final class ReleaseScopeTest extends TestCase
{
    /** SCAN_DIRS in the test config; the default policy is the same list. */
    private const CONFIGURED = ['app', 'public'];

    protected function setUp(): void
    {
        foreach (['app', 'public', 'vendor'] as $dir) {
            $this->reset(ROOTPATH . $dir);
        }

        $this->reset(WRITEPATH . 'backups');
        $this->reset(WRITEPATH . 'tmp/extracted');
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
     * @param list<string> $paths
     *
     * @return array<string, string>
     */
    private function manifestFor(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $files[$path] = hash('sha256', $path);
        }

        return $files;
    }

    // -- Root names arrive from the update server, so they are validated ------

    #[DataProvider('provideUnusableRoots')]
    public function testRejectsRootNamesThatArentDirectoryNames(string $root): void
    {
        self::assertFalse(ReleaseScope::isValidRootName($root));
        self::assertSame([$root], ReleaseScope::invalidRoots([$root]));
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function provideUnusableRoots(): iterable
    {
        yield 'traversal'       => ['..'];
        yield 'traversal deep'  => ['../../etc'];
        yield 'embedded parent' => ['app/../vendor'];
        yield 'nested path'     => ['app/Config'];
        yield 'backslash'       => ['app\\Config'];
        yield 'absolute'        => ['/etc'];
        yield 'drive letter'    => ['C:'];
        yield 'current dir'     => ['.'];
        yield 'dot directory'   => ['.git'];
        yield 'empty'           => [''];
        yield 'nul byte'        => ["app\0"];
        yield 'too long'        => [str_repeat('a', 65)];

        // Never writable by a release: it holds the backups a rollback needs.
        yield 'writable' => ['writable'];
    }

    #[DataProvider('provideUsableRoots')]
    public function testAcceptsOrdinaryDirectoryNames(string $root): void
    {
        self::assertTrue(ReleaseScope::isValidRootName($root));
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function provideUsableRoots(): iterable
    {
        yield 'app'          => ['app'];
        yield 'public'       => ['public'];
        yield 'vendor'       => ['vendor'];
        yield 'underscore'   => ['_private'];
        yield 'inner dot'    => ['my.module'];
        yield 'inner hyphen' => ['my-module'];
        yield 'digits'       => ['app2'];
    }

    public function testReportsNonStringEntriesWithoutCrashing(): void
    {
        self::assertSame(['(integer)', '(array)'], ReleaseScope::invalidRoots([42, ['app']]));
    }

    // -- A manifest that predates 2.6 keeps behaving exactly as it did --------

    public function testAManifestWithoutRootsFallsBackToTheConfiguredScanDirs(): void
    {
        $manager = new UpgradeManager();

        self::assertNull($manager->checkScope(['files' => $this->manifestFor(['app/A.php'])]));
        self::assertSame(self::CONFIGURED, $manager->releaseRoots(['files' => []]));
    }

    // -- Policy: a release covering something unexpected is refused whole -----

    public function testRefusesARootTheInstallationDoesNotAllow(): void
    {
        $manager = new UpgradeManager();

        $error = $manager->checkScope([
            'roots' => ['app', 'public', 'vendor'],
            'files' => $this->manifestFor(['app/A.php', 'public/b.css', 'vendor/autoload.php']),
        ]);

        self::assertNotNull($error);
        self::assertStringContainsString('vendor', $error);
        self::assertStringContainsString('allowedRoots', $error);
    }

    public function testAcceptsThatSameReleaseOnceTheRootIsAllowed(): void
    {
        $manager = new UpgradeManager(null, null, ['app', 'public', 'vendor']);

        self::assertNull($manager->checkScope([
            'roots' => ['app', 'public', 'vendor'],
            'files' => $this->manifestFor(['app/A.php', 'public/b.css', 'vendor/autoload.php']),
        ]));
    }

    public function testRefusesWritableEvenWhenThePolicyListsIt(): void
    {
        $manager = new UpgradeManager(null, null, ['app', 'writable']);

        $error = $manager->checkScope([
            'roots' => ['writable'],
            'files' => $this->manifestFor(['writable/backups/backup-2026-01-01-000000/app/A.php']),
        ]);

        self::assertNotNull($error);
        self::assertStringContainsString('writable', $error);
    }

    #[DataProvider('provideMalformedScopes')]
    public function testRefusesAMalformedScope(mixed $roots): void
    {
        $manager = new UpgradeManager();

        self::assertNotNull($manager->checkScope([
            'roots' => $roots,
            'files' => $this->manifestFor(['app/A.php']),
        ]));
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function provideMalformedScopes(): iterable
    {
        yield 'not an array' => ['app,public'];
        yield 'empty'        => [[]];
        yield 'traversal'    => [['..']];
        yield 'blank entry'  => [['']];
    }

    /**
     * A root declared with nothing under it can't be told apart from a
     * truncated manifest — and read literally, it means "delete everything
     * in this directory".
     */
    public function testRefusesADeclaredRootThatListsNoFile(): void
    {
        $manager = new UpgradeManager(null, null, ['app', 'public', 'vendor']);

        $error = $manager->checkScope([
            'roots' => ['app', 'vendor'],
            'files' => $this->manifestFor(['app/A.php']),
        ]);

        self::assertNotNull($error);
        self::assertStringContainsString('vendor', $error);
    }

    // -- The regression this exists for ---------------------------------------

    /**
     * The catastrophic case: vendor/ is on disk and the installation is willing
     * to manage it, but this release doesn't cover it. Scoping the scan to the
     * release's own roots is what keeps the diff from reading the whole tree as
     * deleted — which would take out the autoloader, the app, and with it the
     * panel the rollback lives in.
     */
    public function testADirectoryOutsideTheReleaseScopeIsNeverSeenAsDeleted(): void
    {
        $this->write(ROOTPATH . 'app/A.php', 'a');
        $this->write(ROOTPATH . 'vendor/autoload.php', 'autoload');
        $this->write(ROOTPATH . 'vendor/composer/ClassLoader.php', 'loader');

        // public/ is in SCAN_DIRS but outside this release's roots. It is the
        // discriminating case: a scan driven by the configuration instead of
        // the manifest would report this file as deleted.
        $this->write(ROOTPATH . 'public/index.php', 'front controller');

        $manager = new UpgradeManager(null, null, ['app', 'public', 'vendor']);
        $roots   = ['app'];

        $diff = $manager->computeDiff(
            $manager->buildCurrentManifest($roots),
            $this->manifestFor(['app/A.php']),
            $roots,
        );

        self::assertSame([], $diff['deleted']);
        self::assertSame([], $diff['rejected']);
        self::assertFileExists(ROOTPATH . 'vendor/autoload.php');
        self::assertFileExists(ROOTPATH . 'public/index.php');
    }

    /**
     * And the flip side: inside the scope, a file missing from the manifest is
     * still a deletion. Bounding the blast radius must not disarm the feature.
     */
    public function testAFileMissingInsideTheScopeIsStillADeletion(): void
    {
        $this->write(ROOTPATH . 'app/Keep.php', 'keep');
        $this->write(ROOTPATH . 'app/Gone.php', 'gone');

        $manager = new UpgradeManager();
        $roots   = ['app'];

        $diff = $manager->computeDiff(
            $manager->buildCurrentManifest($roots),
            $this->manifestFor(['app/Keep.php']),
            $roots,
        );

        self::assertSame(['app/Gone.php'], $diff['deleted']);
    }

    public function testPathsOutsideTheDeclaredRootsAreRejectedEvenWhenAllowedElsewhere(): void
    {
        $manager = new UpgradeManager(null, null, ['app', 'public', 'vendor']);
        $roots   = ['app'];

        $diff = $manager->computeDiff([], $this->manifestFor(['app/A.php', 'vendor/autoload.php']), $roots);

        self::assertSame(['vendor/autoload.php'], $diff['rejected']);
    }

    // -- apply() re-validates: the scope travels through the session ----------

    public function testApplyRefusesAScopeThePolicyDoesNotAllow(): void
    {
        $extract = WRITEPATH . 'tmp/extracted/';

        // The payload is present and valid, so the policy is the only thing
        // standing between it and ROOTPATH. Without the file, apply() would
        // refuse for the wrong reason and the test would pass either way.
        $this->write($extract . 'vendor/evil.php', 'payload');

        $result = (new UpgradeManager())->apply(
            $extract,
            ['added' => ['vendor/evil.php'], 'modified' => [], 'deleted' => []],
            [],
            '1.1.0',
            ['vendor'],
        );

        self::assertFalse($result['success']);
        self::assertStringContainsString('does not accept', $result['error']);
        self::assertFileDoesNotExist(ROOTPATH . 'vendor/evil.php');
    }

    /**
     * writable/ passes the policy here — it is deliberately listed — so the
     * only thing that can stop it is the name being denied outright. Which is
     * the point: a release that could write into writable/ could overwrite the
     * backups a rollback needs.
     */
    public function testApplyRefusesWritableEvenWhenTheScopeSaysOtherwise(): void
    {
        $extract = WRITEPATH . 'tmp/extracted/';
        $this->write($extract . 'writable/evil.php', 'payload');

        $result = (new UpgradeManager(null, null, ['app', 'public', 'writable']))->apply(
            $extract,
            ['added' => ['writable/evil.php'], 'modified' => [], 'deleted' => []],
            [],
            '1.1.0',
            ['writable'],
        );

        self::assertFalse($result['success']);
        self::assertFileDoesNotExist(ROOTPATH . 'writable/evil.php');
    }

    /**
     * A traversal in the scope is caught twice — by the root name check and by
     * the per-path check. Both are asserted here, deliberately: the second is
     * what makes the first a defence in depth rather than the only barrier.
     */
    public function testApplyRefusesATraversalScope(): void
    {
        $result = (new UpgradeManager(null, null, ['app', 'public', '..']))->apply(
            WRITEPATH . 'tmp/extracted/',
            ['added' => ['../evil.php'], 'modified' => [], 'deleted' => []],
            [],
            '1.1.0',
            ['..'],
        );

        self::assertFalse($result['success']);
        self::assertFileDoesNotExist(ROOTPATH . '../evil.php');
    }

    // -- End to end: roots may vary from one release to the next --------------

    /**
     * The workflow the old design made unsafe. A release ships vendor/, the
     * next one doesn't — and the second must leave it strictly alone rather
     * than delete it.
     */
    public function testAReleaseThatDropsARootLeavesItUntouched(): void
    {
        $extract = WRITEPATH . 'tmp/extracted/';
        $manager = new UpgradeManager(null, null, ['app', 'public', 'vendor']);

        $this->write(ROOTPATH . 'app/A.php', 'v1');

        // 1.1.0 covers app + vendor.
        $this->write($extract . 'app/A.php', 'v1.1');
        $this->write($extract . 'vendor/autoload.php', 'autoload v1.1');

        $first = $manager->apply(
            $extract,
            ['added' => ['vendor/autoload.php'], 'modified' => ['app/A.php'], 'deleted' => []],
            [],
            '1.1.0',
            ['app', 'vendor'],
        );

        self::assertTrue($first['success'], $first['error'] ?? '');
        self::assertSame('autoload v1.1', file_get_contents(ROOTPATH . 'vendor/autoload.php'));

        // 1.2.0 covers app only. vendor/ isn't scanned, so it can't be deleted.
        $roots = ['app'];
        $diff  = $manager->computeDiff(
            $manager->buildCurrentManifest($roots),
            $this->manifestFor(['app/A.php']),
            $roots,
        );

        self::assertSame([], $diff['deleted']);
        self::assertFileExists(ROOTPATH . 'vendor/autoload.php');
    }

    /**
     * A restore has to work in the perimeter of the update it undoes, not in
     * whatever the app is configured for today — otherwise the files that
     * update added outside SCAN_DIRS stay behind.
     */
    public function testRollingBackRemovesFilesAddedOutsideTheConfiguredScanDirs(): void
    {
        $extract = WRITEPATH . 'tmp/extracted/';
        $manager = new UpgradeManager(null, null, ['app', 'public', 'vendor']);

        $this->write(ROOTPATH . 'app/A.php', 'original');
        $this->write($extract . 'app/A.php', 'updated');
        $this->write($extract . 'vendor/autoload.php', 'added by the update');

        $applied = $manager->apply(
            $extract,
            ['added' => ['vendor/autoload.php'], 'modified' => ['app/A.php'], 'deleted' => []],
            [],
            '1.1.0',
            ['app', 'vendor'],
        );

        self::assertTrue($applied['success'], $applied['error'] ?? '');

        $backup = basename(rtrim($applied['backup_dir'], '/\\'));
        $meta   = json_decode((string) file_get_contents($applied['backup_dir'] . UpgradeManager::BACKUP_MANIFEST), true);

        self::assertSame(['app', 'vendor'], $meta['roots']);

        // A manager with the default policy — vendor/ is not in SCAN_DIRS.
        $restored = (new UpgradeManager())->restoreBackup($backup);

        self::assertTrue($restored['success'], $restored['error'] ?? '');
        self::assertSame('original', file_get_contents(ROOTPATH . 'app/A.php'));
        self::assertFileDoesNotExist(ROOTPATH . 'vendor/autoload.php');
    }
}
