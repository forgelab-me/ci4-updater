<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\ReleaseVerification;
use Forgelabme\Ci4Updater\Libraries\UpgradeManager;
use PHPUnit\Framework\TestCase;

/**
 * What an update wrote, read back and compared to the manifest.
 *
 * @internal
 */
final class ReleaseVerificationTest extends TestCase
{
    private UpgradeManager $manager;
    private string $extractDir;

    protected function setUp(): void
    {
        $this->manager = new UpgradeManager();

        $this->reset(ROOTPATH . 'app');
        $this->reset(WRITEPATH . 'backups');

        $this->extractDir = WRITEPATH . 'tmp/verification/';
        $this->reset($this->extractDir);
    }

    // -- The comparison --------------------------------------------------------

    public function testFilesThatMatchTheManifestDoNotDrift(): void
    {
        $this->write(ROOTPATH . 'app/a.php', 'one');
        $this->write(ROOTPATH . 'app/b.php', 'two');

        $result = ReleaseVerification::check(ROOTPATH, [
            'app/a.php' => hash('sha256', 'one'),
            'app/b.php' => hash('sha256', 'two'),
        ], ['app/a.php', 'app/b.php']);

        self::assertSame(2, $result['checked']);
        self::assertSame([], $result['drift']);
    }

    public function testAFileWrittenWithTheWrongContentsDrifts(): void
    {
        $this->write(ROOTPATH . 'app/a.php', 'truncated');

        $result = ReleaseVerification::check(ROOTPATH, ['app/a.php' => hash('sha256', 'whole')], ['app/a.php']);

        self::assertSame([['path' => 'app/a.php', 'problem' => 'contents differ']], $result['drift']);
    }

    public function testAFileThatNeverArrivedDrifts(): void
    {
        $result = ReleaseVerification::check(ROOTPATH, ['app/a.php' => hash('sha256', 'x')], ['app/a.php']);

        self::assertSame([['path' => 'app/a.php', 'problem' => 'missing']], $result['drift']);
    }

    public function testAFileThatShouldHaveGoneButStayedDrifts(): void
    {
        $this->write(ROOTPATH . 'app/old.php', 'still here');

        $result = ReleaseVerification::check(ROOTPATH, [], [], ['app/old.php']);

        self::assertSame([['path' => 'app/old.php', 'problem' => 'still present']], $result['drift']);
    }

    /** A manifest entry may be absent; existence is then all that can be said. */
    public function testAPathWithNoHashIsOnlyCheckedForExistence(): void
    {
        $this->write(ROOTPATH . 'app/a.php', 'anything');

        self::assertSame([], ReleaseVerification::check(ROOTPATH, [], ['app/a.php'])['drift']);
    }

    public function testDescribeNamesTheFirstFewAndCountsTheRest(): void
    {
        $drift = array_map(
            static fn (int $i): array => ['path' => "app/{$i}.php", 'problem' => 'missing'],
            range(1, 8),
        );

        $described = ReleaseVerification::describe($drift, 2);

        self::assertStringContainsString('app/1.php (missing)', $described);
        self::assertStringContainsString('and 6 more', $described);
    }

    // -- Around an apply -------------------------------------------------------

    public function testAGoodApplyReportsWhatItChecked(): void
    {
        $this->write(ROOTPATH . 'app/keep.php', 'old');
        $this->write($this->extractDir . 'app/keep.php', 'new');
        $this->write(ROOTPATH . 'app/gone.php', 'remove me');

        $result = $this->manager->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['app/keep.php'], 'deleted' => ['app/gone.php']],
            ['app/keep.php' => hash('sha256', 'new')],
            '1.4.0',
            ['app'],
        );

        self::assertTrue($result['success']);
        self::assertSame(2, $result['verified']['checked']);
        self::assertSame([], $result['verified']['drift']);
    }

    /**
     * The hashes checked before writing say the archive was intact; these say
     * the files on disk are.
     */
    public function testAnApplyWhoseFilesEndUpWrongReportsTheDrift(): void
    {
        $this->write(ROOTPATH . 'app/keep.php', 'old');
        $this->write($this->extractDir . 'app/keep.php', 'new');

        // Something else rewrites the file while the update is mid-flight; the
        // migration step is the one hook that runs between write and verify.
        $result = $this->manager->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['app/keep.php'], 'deleted' => []],
            ['app/keep.php' => hash('sha256', 'new')],
            '1.4.0',
            ['app'],
            static function (): void {
                file_put_contents(ROOTPATH . 'app/keep.php', 'clobbered');
            },
        );

        self::assertTrue($result['success'], 'the files were written; what follows is a report, not a failure');
        self::assertSame(
            [['path' => 'app/keep.php', 'problem' => 'contents differ']],
            $result['verified']['drift'],
        );
    }

    /** Releases built before manifests carried hashes have nothing to check. */
    public function testAnApplyWithoutAManifestChecksNothing(): void
    {
        $this->write(ROOTPATH . 'app/keep.php', 'old');
        $this->write($this->extractDir . 'app/keep.php', 'new');

        $result = $this->manager->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['app/keep.php'], 'deleted' => []],
            [],
            '1.4.0',
            ['app'],
        );

        self::assertTrue($result['success']);
        self::assertSame(['checked' => 0, 'drift' => []], $result['verified']);
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
