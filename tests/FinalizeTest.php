<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\ApplyLock;
use Forgelabme\Ci4Updater\Libraries\Finalize;
use Forgelabme\Ci4Updater\Libraries\MaintenanceWindow;
use Forgelabme\Ci4Updater\Libraries\PackageMigrations;
use Forgelabme\Ci4Updater\Libraries\UpgradeManager;
use PHPUnit\Framework\TestCase;

/**
 * Migrations that arrive with a release's vendor/ run after the update, in a
 * process that has the new code — and the site waits for them.
 *
 * @internal
 */
final class FinalizeTest extends TestCase
{
    private const SHIELD = 'vendor/codeigniter4/shield/src/Database/Migrations/2020-12-28-223112_create_auth_tables.php';

    private UpgradeManager $manager;
    private string $extractDir;

    protected function setUp(): void
    {
        $this->manager = new UpgradeManager();

        MaintenanceWindow::close();
        Finalize::clear();

        $this->reset(ROOTPATH . 'app');
        $this->reset(WRITEPATH . 'backups');

        $this->extractDir = WRITEPATH . 'tmp/finalize/';
        $this->reset($this->extractDir);
    }

    protected function tearDown(): void
    {
        MaintenanceWindow::close();
        Finalize::clear();
    }

    // -- Detection -------------------------------------------------------------

    public function testAMigrationInTheNewVendorTheTableDoesNotRecordIsIncoming(): void
    {
        $found = PackageMigrations::incoming($this->manifest([self::SHIELD]), []);

        self::assertSame(
            [['package' => 'codeigniter4/shield', 'version' => '2020-12-28-223112', 'path' => self::SHIELD]],
            $found,
        );
    }

    public function testARecordedMigrationIsNotIncoming(): void
    {
        self::assertSame([], PackageMigrations::incoming($this->manifest([self::SHIELD]), ['2020-12-28-223112']));
    }

    public function testTheVersionIsComparedWithoutItsSeparators(): void
    {
        self::assertSame([], PackageMigrations::incoming($this->manifest([self::SHIELD]), ['20201228223112']));
    }

    /** With the table unreadable nothing can be called new. */
    public function testNothingIsIncomingWhenTheTableCannotBeRead(): void
    {
        self::assertSame([], PackageMigrations::incoming($this->manifest([self::SHIELD]), null));
    }

    public function testOnlyMigrationFilesUnderVendorCount(): void
    {
        $found = PackageMigrations::incoming($this->manifest([
            'app/Database/Migrations/2026-01-01-000000_mine.php',
            'vendor/acme/pkg/src/Database/Migrations/README.md',
            'vendor/acme/pkg/src/Database/Seeds/2026-01-01-000000_seed.php',
            'vendor/acme/pkg/src/Models/2026-01-01-000000_model.php',
            'vendor/acme/pkg/Database/Migrations/2026-01-01-000000_a.php',
            'vendor/other/pkg/src/Database/Migrations/2026-01-02-000000_b.php',
        ]), []);

        self::assertSame(
            ['vendor/acme/pkg/Database/Migrations/2026-01-01-000000_a.php', 'vendor/other/pkg/src/Database/Migrations/2026-01-02-000000_b.php'],
            array_column($found, 'path'),
        );
    }

    /** A package's own test migrations are in the manifest but no application loads them. */
    public function testMigrationsKeptForATestSuiteAreNotIncoming(): void
    {
        self::assertSame([], PackageMigrations::incoming($this->manifest([
            'vendor/codeigniter4/framework/tests/_support/Database/Migrations/2020-02-22-222222_example_migration.php',
            'vendor/acme/pkg/tests/Database/Migrations/2026-01-01-000000_a.php',
            'vendor/acme/pkg/examples/Database/Migrations/2026-01-01-000000_b.php',
        ]), []));
    }

    public function testTheIncomingMigrationsAreDescribedPerPackage(): void
    {
        $found = PackageMigrations::incoming($this->manifest([
            self::SHIELD,
            'vendor/codeigniter4/shield/src/Database/Migrations/2021-01-01-000000_more.php',
            'vendor/acme/pkg/Database/Migrations/2026-01-01-000000_a.php',
        ]), []);

        self::assertSame('acme/pkg (1), codeigniter4/shield (2)', PackageMigrations::describe($found));
    }

    // -- Applying --------------------------------------------------------------

    public function testTheWindowStaysOpenAfterAnUpdateWaitingOnMigrations(): void
    {
        $result = $this->applyKeep($this->incoming());

        self::assertTrue($result['success']);
        self::assertTrue($result['finalize']);
        self::assertTrue(MaintenanceWindow::isOpen(), 'the new code must not run on the old schema');
        self::assertTrue(Finalize::isPending());
        self::assertSame(basename($result['backup_dir']), Finalize::state()['backup']);
        self::assertSame('1.4.0', Finalize::state()['version']);
        self::assertSame($this->incoming(), Finalize::state()['migrations']);
    }

    /** A person has to finish it, so the window outlasts the one an update opens. */
    public function testThePendingWindowOutlastsTheOrdinaryOne(): void
    {
        $this->applyKeep($this->incoming());

        $left = MaintenanceWindow::state()['expires_at'] - time();

        self::assertGreaterThan(MaintenanceWindow::DEFAULT_TTL, $left);
        self::assertLessThanOrEqual(Finalize::DEFAULT_TTL, $left);
    }

    public function testTheWindowClosesAsBeforeWhenNothingIsWaiting(): void
    {
        foreach ([null, []] as $finalize) {
            $result = $this->applyKeep($finalize);

            self::assertTrue($result['success']);
            self::assertArrayNotHasKey('finalize', $result);
            self::assertFalse(MaintenanceWindow::isOpen());
            self::assertFalse(Finalize::isPending());

            $this->write($this->extractDir . 'app/keep.php', 'again');
        }
    }

    public function testAFailedUpdateNeitherHoldsTheWindowNorLeavesAPendingStep(): void
    {
        $result = $this->manager->apply(
            $this->extractDir,
            ['added' => ['app/missing.php'], 'modified' => [], 'deleted' => []],
            [],
            '1.4.0',
            ['app'],
            null,
            null,
            $this->incoming(),
        );

        self::assertFalse($result['success']);
        self::assertFalse(MaintenanceWindow::isOpen());
        self::assertFalse(Finalize::isPending());
    }

    public function testAnotherUpdateIsRefusedWhileOneIsWaiting(): void
    {
        $this->applyKeep($this->incoming());

        $this->write($this->extractDir . 'app/keep.php', 'newer');
        $second = $this->applyKeep(null);

        self::assertFalse($second['success']);
        self::assertSame(Finalize::pendingMessage(), $second['error']);
        self::assertSame('new', file_get_contents(ROOTPATH . 'app/keep.php'), 'nothing was written');
        self::assertTrue(MaintenanceWindow::isOpen(), 'and the pending window is still held');

        $lock = ApplyLock::acquire();
        self::assertNotNull($lock, 'the refusal released the lock');
        $lock->release();
    }

    // -- Finishing -------------------------------------------------------------

    public function testFinishingRunsEverythingAndOpensTheSiteAgain(): void
    {
        $this->applyKeep($this->incoming());
        $runner = new FakeRunner(batch: 4, batchAfter: 5);

        $result = Finalize::run($runner, fn () => ['2020-12-28-223112']);

        self::assertTrue($result['success']);
        self::assertSame(1, $result['batches']);
        self::assertNull($runner->namespace, 'every namespace, not only the application\'s');
        self::assertSame(1, $runner->latestCalls);
        self::assertFalse(Finalize::isPending());
        self::assertFalse(MaintenanceWindow::isOpen());
    }

    /** The batch the package migrations created joins the one the update recorded. */
    public function testTheBatchesAreRecordedSoARollbackCanRevertThemTogether(): void
    {
        $name = basename($this->applyKeep($this->incoming())['backup_dir']);
        $this->manager->recordMigrations($name, 2, 3);

        Finalize::run(new FakeRunner(batch: 3, batchAfter: 4), fn () => ['2020-12-28-223112']);

        self::assertSame(['batch_before' => 2, 'batch_after' => 4], $this->manager->backupMigrations($name));
    }

    public function testWithoutAnEarlierRecordTheStartIsTheBatchBeforeThePackageMigrations(): void
    {
        $name = basename($this->applyKeep($this->incoming())['backup_dir']);

        Finalize::run(new FakeRunner(batch: 3, batchAfter: 4), fn () => ['2020-12-28-223112']);

        self::assertSame(['batch_before' => 3, 'batch_after' => 4], $this->manager->backupMigrations($name));
    }

    public function testFailingMigrationsKeepTheUpdatePendingAndTheWindowOpen(): void
    {
        $this->applyKeep($this->incoming());
        MaintenanceWindow::close();

        $result = Finalize::run(new FakeRunner(batch: 3, batchAfter: 3, fail: 'no such table'), fn () => []);

        self::assertFalse($result['success']);
        self::assertStringContainsString('no such table', $result['error']);
        self::assertTrue(Finalize::isPending());
        self::assertTrue(MaintenanceWindow::isOpen(), 'a retry still has the time it needs');
    }

    /** A runner that says it is done is not taken at its word. */
    public function testMigrationsTheTableDoesNotRecordAreNotCalledDone(): void
    {
        $this->applyKeep($this->incoming());

        $result = Finalize::run(new FakeRunner(batch: 3, batchAfter: 3), fn () => []);

        self::assertFalse($result['success']);
        self::assertStringContainsString('codeigniter4/shield 2020-12-28-223112', $result['error']);
        self::assertTrue(Finalize::isPending());
        self::assertTrue(MaintenanceWindow::isOpen());
    }

    public function testMissingMigrationsCanBeAcceptedExplicitly(): void
    {
        $this->applyKeep($this->incoming());

        $result = Finalize::run(new FakeRunner(batch: 3, batchAfter: 3), fn () => [], acceptMissing: true);

        self::assertTrue($result['success']);
        self::assertFalse(Finalize::isPending());
        self::assertFalse(MaintenanceWindow::isOpen());
    }

    public function testNothingToFinishIsSaidSo(): void
    {
        $result = Finalize::run(new FakeRunner(batch: 1, batchAfter: 1), fn () => []);

        self::assertFalse($result['success']);
        self::assertSame('There is nothing to finish.', $result['error']);
    }

    public function testFinishingIsRefusedWhileAnotherHoldsTheLock(): void
    {
        $this->applyKeep($this->incoming());
        $runner = new FakeRunner(batch: 3, batchAfter: 4);
        $lock   = ApplyLock::acquire();

        try {
            $result = Finalize::run($runner, fn () => ['2020-12-28-223112']);
        } finally {
            $lock->release();
        }

        self::assertFalse($result['success']);
        self::assertSame(ApplyLock::busyMessage(), $result['error']);
        self::assertSame(0, $runner->latestCalls);
        self::assertTrue(Finalize::isPending());
    }

    // -- Restoring -------------------------------------------------------------

    public function testRestoringAnUpdateWaitingOnMigrationsOpensTheSiteAgain(): void
    {
        $name = basename($this->applyKeep($this->incoming())['backup_dir']);

        self::assertTrue($this->manager->restoreBackup($name)['success']);

        self::assertFalse(Finalize::isPending());
        self::assertFalse(MaintenanceWindow::isOpen());
        self::assertSame('old', file_get_contents(ROOTPATH . 'app/keep.php'));
    }

    public function testAFailedRestoreLeavesTheUpdatePending(): void
    {
        $name = basename($this->applyKeep($this->incoming())['backup_dir']);

        $result = $this->manager->restoreBackup($name, static fn (): string => 'a reason');

        self::assertFalse($result['success']);
        self::assertTrue(Finalize::isPending());
        self::assertTrue(MaintenanceWindow::isOpen());
    }

    // -- A new process ---------------------------------------------------------

    public function testTheNewProcessExitCodeIsReturned(): void
    {
        self::assertSame(0, Finalize::spawn([PHP_BINARY, '-r', 'exit(0);']));
        self::assertSame(3, Finalize::spawn([PHP_BINARY, '-r', 'exit(3);']));
    }

    public function testNoProcessIsStartedWhenThereIsNoSparkToRun(): void
    {
        self::assertNull(Finalize::spawn());
    }

    // -- The filter ------------------------------------------------------------

    public function testTheFilterIsRegisteredWhenItRunsBeforeEveryRequest(): void
    {
        $maintenance = \Forgelabme\Ci4Updater\Filters\Maintenance::class;

        self::assertTrue(MaintenanceWindow::filterRegistered((object) [
            'aliases' => ['maintenance' => $maintenance],
            'globals' => ['before' => ['maintenance' => ['except' => ['admin/updates/*']]]],
        ]));
        self::assertTrue(MaintenanceWindow::filterRegistered((object) [
            'aliases'  => ['down' => $maintenance],
            'globals'  => ['before' => ['csrf']],
            'required' => ['before' => ['down']],
        ]));
    }

    public function testTheFilterIsNotRegisteredWhenItIsOnlyAnAlias(): void
    {
        $maintenance = \Forgelabme\Ci4Updater\Filters\Maintenance::class;

        self::assertFalse(MaintenanceWindow::filterRegistered((object) [
            'aliases' => ['maintenance' => $maintenance],
            'globals' => ['before' => ['csrf']],
        ]));
        self::assertFalse(MaintenanceWindow::filterRegistered((object) [
            'aliases' => ['maintenance' => 'App\Filters\Other'],
            'globals' => ['before' => ['maintenance']],
        ]));
        self::assertFalse(MaintenanceWindow::filterRegistered((object) []));
    }

    // -- Support ---------------------------------------------------------------

    /**
     * @return list<array{package: string, version: string, path: string}>
     */
    private function incoming(): array
    {
        return [['package' => 'codeigniter4/shield', 'version' => '2020-12-28-223112', 'path' => self::SHIELD]];
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, string>
     */
    private function manifest(array $paths): array
    {
        return array_fill_keys($paths, str_repeat('a', 64));
    }

    /**
     * @param list<array{package: string, version: string, path: string}>|null $finalize
     *
     * @return array<string, mixed>
     */
    private function applyKeep(?array $finalize): array
    {
        if (! is_file(ROOTPATH . 'app/keep.php')) {
            $this->write(ROOTPATH . 'app/keep.php', 'old');
            $this->write($this->extractDir . 'app/keep.php', 'new');
        }

        return $this->manager->apply(
            $this->extractDir,
            ['added' => [], 'modified' => ['app/keep.php'], 'deleted' => []],
            [],
            '1.4.0',
            ['app'],
            null,
            null,
            $finalize,
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

/**
 * The slice of the migration runner Finalize::run() uses.
 */
final class FakeRunner
{
    public ?string $namespace = 'App';
    public int $latestCalls   = 0;
    private int $current;

    public function __construct(int $batch, private readonly int $batchAfter, private readonly ?string $fail = null)
    {
        $this->current = $batch;
    }

    public function setNamespace(?string $namespace): self
    {
        $this->namespace = $namespace;

        return $this;
    }

    public function latest(): void
    {
        $this->latestCalls++;

        if ($this->fail !== null) {
            throw new \RuntimeException($this->fail);
        }

        $this->current = $this->batchAfter;
    }

    public function getLastBatch(): int
    {
        return $this->current;
    }
}
