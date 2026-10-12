<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Libraries;

/**
 * The step after an update whose `vendor/` brings migrations of its own.
 *
 * Those migrations cannot run during the update: the process still has the old
 * dependency tree loaded, and the new packages are only usable from a fresh
 * one. The update therefore stops with the files in place and the maintenance
 * window open, and this finishes it — from `updater:finalize`, or from the
 * panel — in a process that loads the new code.
 *
 * What is waiting is a file in writable/, so any process can see it and
 * neither a closed browser tab nor a dropped CLI session loses it.
 */
final class Finalize
{
    public const FILENAME = 'updater-finalize.json';

    public const DEFAULT_TTL = 3600;

    public static function path(): string
    {
        return WRITEPATH . self::FILENAME;
    }

    /**
     * Records the migrations waiting and keeps the window open for them.
     *
     * @param list<array{package: string, version: string, path: string}> $migrations
     */
    public static function begin(?string $version, string $backup, array $migrations): void
    {
        file_put_contents(self::path(), json_encode([
            'version'    => $version,
            'backup'     => $backup,
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'migrations' => $migrations,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        self::holdWindow($version);
    }

    /**
     * @return array{version: ?string, backup: string, started_at: string, migrations: list<array{package: string, version: string, path: string}>}|null
     */
    public static function state(): ?array
    {
        if (! is_file(self::path())) {
            return null;
        }

        $data = json_decode((string) file_get_contents(self::path()), true);

        if (! is_array($data) || ! is_string($data['backup'] ?? null) || ! is_array($data['migrations'] ?? null)) {
            return null;
        }

        return $data;
    }

    public static function isPending(): bool
    {
        return self::state() !== null;
    }

    public static function clear(): void
    {
        if (is_file(self::path())) {
            @unlink(self::path());
        }
    }

    public static function pendingMessage(): string
    {
        return 'A previous update is still waiting for its package migrations. '
            . 'Finish it (`php spark updater:finalize`, or the update panel) or restore its backup first.';
    }

    /**
     * Runs every pending migration, in whichever process calls it.
     *
     * @param object|null   $runner  the migration runner; the framework's by default
     * @param callable|null $applied returns the versions the migrations table records
     * @param bool          $acceptMissing finish even when some migrations the release brought are not recorded
     *
     * @return array{success: bool, batches?: int, error?: string}
     */
    public static function run(?object $runner = null, ?callable $applied = null, bool $acceptMissing = false): array
    {
        $state = self::state();

        if ($state === null) {
            return ['success' => false, 'error' => 'There is nothing to finish.'];
        }

        $lock = ApplyLock::acquire();

        if ($lock === null) {
            return ['success' => false, 'error' => ApplyLock::busyMessage()];
        }

        try {
            $runner ??= service('migrations');
            $before   = self::lastBatch($runner);

            try {
                $runner->setNamespace(null);
                $runner->latest();
            } catch (\Throwable $e) {
                self::holdWindow($state['version'] ?? null);

                return ['success' => false, 'error' => 'The migrations failed: ' . $e->getMessage()];
            }

            $missing = self::missing($state['migrations'], ($applied ?? PackageMigrations::applied(...))());

            if ($missing !== [] && ! $acceptMissing) {
                self::holdWindow($state['version'] ?? null);

                return [
                    'success' => false,
                    'error'   => 'The migrations ran but these are not recorded: ' . implode(', ', $missing)
                        . '. No namespace this application loads owns them. Fix that and try again, '
                        . 'or finish anyway with `php spark updater:finalize --accept-missing`.',
                ];
            }

            $after = self::lastBatch($runner);

            if ($after > $before) {
                (new UpgradeManager())->recordMigrations($state['backup'], $before, $after);
            }

            self::clear();
            MaintenanceWindow::close();

            return ['success' => true, 'batches' => max(0, $after - $before)];
        } finally {
            $lock->release();
        }
    }

    /**
     * Runs `updater:finalize` in a new process and returns its exit code, or
     * null when no process can be started here.
     *
     * @param list<string>|null $command what to run; `php spark updater:finalize` by default
     */
    public static function spawn(?array $command = null): ?int
    {
        $command ??= self::command();

        if ($command === null) {
            return null;
        }

        $process = @proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, ROOTPATH);

        return is_resource($process) ? proc_close($process) : null;
    }

    /**
     * @return list<string>|null
     */
    private static function command(): ?array
    {
        if (! function_exists('proc_open') || ! is_file(ROOTPATH . 'spark')) {
            return null;
        }

        return [PHP_BINARY, ROOTPATH . 'spark', 'updater:finalize'];
    }

    private static function holdWindow(?string $version): void
    {
        MaintenanceWindow::open(
            'Finishing ' . ($version ?? 'an update') . ': package migrations pending',
            self::ttl(),
        );
    }

    /**
     * @param list<array{package: string, version: string, path: string}> $expected
     * @param list<string>|null                                           $applied
     *
     * @return list<string> the versions still missing; everything when the table cannot be read
     */
    private static function missing(array $expected, ?array $applied): array
    {
        $known = array_flip(array_map(static fn (string $v): string => (string) preg_replace('/\D/', '', $v), $applied ?? []));
        $gone  = [];

        foreach ($expected as $migration) {
            if (! isset($known[(string) preg_replace('/\D/', '', $migration['version'])])) {
                $gone[] = $migration['package'] . ' ' . $migration['version'];
            }
        }

        return $gone;
    }

    private static function lastBatch(object $runner): int
    {
        try {
            return method_exists($runner, 'getLastBatch') ? (int) $runner->getLastBatch() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function ttl(): int
    {
        if (function_exists('config')) {
            $config = config('Updater');

            if (is_object($config) && property_exists($config, 'finalizeTtl') && is_int($config->finalizeTtl)) {
                return $config->finalizeTtl;
            }
        }

        return self::DEFAULT_TTL;
    }
}
