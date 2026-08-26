<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\ReleaseScope;
use Forgelabme\Ci4Updater\Libraries\UpgradeManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Files a release writes beside app/ and public/ rather than inside them.
 *
 * @internal
 */
final class RootFilesTest extends TestCase
{
    private string $extractDir;

    protected function setUp(): void
    {
        $this->reset(ROOTPATH . 'app');
        $this->reset(WRITEPATH . 'backups');

        $this->extractDir = WRITEPATH . 'tmp/root-files/';
        $this->reset($this->extractDir);

        @unlink(ROOTPATH . 'composer.json');
        @unlink(ROOTPATH . 'composer.lock');
    }

    /** Allowing nothing is the default, and nothing is what a release may write. */
    private function manager(array $allowedFiles = ['composer.json', 'composer.lock']): UpgradeManager
    {
        $manager = new UpgradeManager();

        (function () use ($allowedFiles): void {
            $this->allowedFiles = $allowedFiles;
        })->call($manager);

        return $manager;
    }

    // -- What a name may be ----------------------------------------------------

    #[DataProvider('provideUnusableNames')]
    public function testRefusesNamesThatAreNotRootFileNames(string $name): void
    {
        self::assertFalse(ReleaseScope::isValidFileName($name), "Expected '{$name}' to be refused");
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function provideUnusableNames(): iterable
    {
        yield 'traversal'      => ['../composer.json'];
        yield 'traversal only' => ['..'];
        yield 'a path'         => ['app/Config/Updater.php'];
        yield 'backslash path' => ['app\\x.php'];
        yield 'absolute'       => ['/etc/passwd'];
        yield 'empty'          => [''];
        yield 'dot'            => ['.'];
        yield 'the env file'   => ['.env'];
        yield 'the htaccess'   => ['.htaccess'];
        yield 'gitignore'      => ['.gitignore'];
        yield 'too long'       => [str_repeat('a', 65)];
    }

    public function testAcceptsTheNamesAReleaseActuallyNeeds(): void
    {
        foreach (['composer.json', 'composer.lock', 'spark', '.env.example', 'LICENSE'] as $name) {
            self::assertTrue(ReleaseScope::isValidFileName($name), $name);
        }
    }

    // -- The policy ------------------------------------------------------------

    /** Empty $allowedFiles is the default: a release writes inside its roots and nowhere else. */
    public function testARootFileIsRefusedWhenTheInstallationAllowsNone(): void
    {
        $error = $this->manager([])->checkFiles([
            'root_files' => ['composer.json'],
            'files'      => ['composer.json' => 'hash'],
        ]);

        self::assertNotNull($error);
        self::assertStringContainsString('does not accept', $error);
    }

    public function testARootFileTheInstallationAllowsIsAccepted(): void
    {
        self::assertNull($this->manager()->checkFiles([
            'root_files' => ['composer.json', 'composer.lock'],
            'files'      => ['composer.json' => 'a', 'composer.lock' => 'b'],
        ]));
    }

    public function testOnlyTheAllowedOnesGetThrough(): void
    {
        $error = $this->manager(['composer.json'])->checkFiles([
            'root_files' => ['composer.json', 'spark'],
            'files'      => ['composer.json' => 'a', 'spark' => 'b'],
        ]);

        self::assertNotNull($error);
        self::assertStringContainsString('spark', $error);
        self::assertStringNotContainsString('composer.json,', $error);
    }

    /** The allow-list is not the last word: these are refused whatever it says. */
    public function testTheDeniedNamesAreRefusedEvenWhenAllowed(): void
    {
        $error = $this->manager(['.env'])->checkFiles([
            'root_files' => ['.env'],
            'files'      => ['.env' => 'hash'],
        ]);

        self::assertNotNull($error);
        self::assertStringContainsString('unusable root files', $error);
    }

    public function testADeclaredFileMustBeListedInTheManifest(): void
    {
        $error = $this->manager()->checkFiles(['root_files' => ['composer.json'], 'files' => []]);

        self::assertNotNull($error);
        self::assertStringContainsString('does not list it', $error);
    }

    public function testAManifestDeclaringNoRootFileIsUnaffected(): void
    {
        self::assertNull($this->manager([])->checkFiles(['files' => ['app/x.php' => 'hash']]));
    }

    // -- Paths -----------------------------------------------------------------

    public function testARootPathIsOnlySafeWhenDeclaredAndAllowed(): void
    {
        $manager = $this->manager();

        self::assertTrue($manager->isSafeManifestPath('composer.json', ['app'], ['composer.json']));
        self::assertFalse($manager->isSafeManifestPath('composer.json', ['app'], []), 'not declared');
        self::assertFalse(
            $this->manager([])->isSafeManifestPath('composer.json', ['app'], ['composer.json']),
            'declared, but not allowed here',
        );
    }

    public function testADeclaredNameStillHasToBeAName(): void
    {
        self::assertFalse($this->manager(['../evil.php'])->isSafeManifestPath('../evil.php', ['app'], ['../evil.php']));
    }

    // -- Applying --------------------------------------------------------------

    public function testARootFileIsWrittenAndRecordedWithTheBackup(): void
    {
        $this->write(ROOTPATH . 'composer.json', '{"old":true}');
        $this->write($this->extractDir . 'composer.json', '{"new":true}');

        $result = $this->manager()->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['composer.json'], 'deleted' => []],
            ['composer.json' => hash('sha256', '{"new":true}')],
            '1.4.0',
            ['app'],
            null,
            ['composer.json'],
        );

        self::assertTrue($result['success'], $result['error'] ?? '');
        self::assertSame('{"new":true}', file_get_contents(ROOTPATH . 'composer.json'));
        self::assertSame([], $result['verified']['drift']);

        $meta = json_decode((string) file_get_contents(
            $result['backup_dir'] . UpgradeManager::BACKUP_MANIFEST
        ), true);

        self::assertSame(['composer.json'], $meta['root_files']);
    }

    public function testApplyingRefusesARootFileTheInstallationDoesNotAllow(): void
    {
        $this->write($this->extractDir . 'composer.json', '{}');

        $result = $this->manager([])->apply(
            $this->extractDir,
            ['added' => ['composer.json'], 'modified' => [], 'deleted' => []],
            [],
            '1.4.0',
            ['app'],
            null,
            ['composer.json'],
        );

        self::assertFalse($result['success']);
        self::assertStringContainsString('does not accept', $result['error']);
        self::assertFileDoesNotExist(ROOTPATH . 'composer.json');
    }

    /**
     * A release that stops shipping composer.json has stopped managing it,
     * which is not the same as asking for it to go.
     */
    public function testARootFileIsNeverDeleted(): void
    {
        $this->write(ROOTPATH . 'composer.json', '{"keep":true}');

        $result = $this->manager()->apply(
            $this->extractDir,
            ['added' => [], 'modified' => [], 'deleted' => ['composer.json']],
            [],
            '1.4.0',
            ['app'],
            null,
            ['composer.json'],
        );

        self::assertFalse($result['success']);
        self::assertStringContainsString('Refusing to delete a root file', $result['error']);
        self::assertFileExists(ROOTPATH . 'composer.json');
    }

    /** The diff is built by scanning directories, so a root file cannot land on the deleted side. */
    public function testDiffingNeverProposesDeletingARootFile(): void
    {
        $manager = $this->manager();

        $diff = $manager->computeDiff(
            $manager->buildCurrentManifest(['app']),
            ['app/x.php' => 'hash'],
            ['app'],
            ['composer.json'],
        );

        self::assertSame([], $diff['deleted']);
    }

    // -- Restoring -------------------------------------------------------------

    public function testARootFileGoesBackOnRestore(): void
    {
        $this->write(ROOTPATH . 'composer.json', '{"old":true}');
        $this->write($this->extractDir . 'composer.json', '{"new":true}');

        $manager = $this->manager();

        $result = $manager->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['composer.json'], 'deleted' => []],
            ['composer.json' => hash('sha256', '{"new":true}')],
            '1.4.0',
            ['app'],
            null,
            ['composer.json'],
        );

        $restored = $manager->restoreBackup(basename($result['backup_dir']));

        self::assertTrue($restored['success'], $restored['error'] ?? '');
        self::assertSame('{"old":true}', file_get_contents(ROOTPATH . 'composer.json'));
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
