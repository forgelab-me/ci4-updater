<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Updater;
use Forgelabme\Ci4Updater\Libraries\ReleaseFeed;
use Forgelabme\Ci4Updater\Libraries\ReleaseRequirements;
use Forgelabme\Ci4Updater\Libraries\UpgradeManager;

/**
 * Usage: php spark updater:apply [--yes] [--dry-run]
 *
 * Downloads what the update server is serving, shows what it changes, and
 * applies it — the same pipeline as the panel, from a shell.
 *
 * Exit codes:
 *   0  applied, or already up to date
 *   1  something went wrong; nothing was applied, or the failure is named
 */
class ApplyUpdate extends BaseCommand
{
    protected $group       = 'Update';
    protected $name        = 'updater:apply';
    protected $description = 'Downloads and applies the release the update server is serving.';
    protected $usage       = 'updater:apply [--yes] [--dry-run]';

    protected $options = [
        '--yes'     => 'Apply without asking for confirmation.',
        '--dry-run' => 'Download and report what would change, then stop.',
    ];

    public function run(array $params): int
    {
        $assumeYes = CLI::getOption('yes') !== null;
        $dryRun    = CLI::getOption('dry-run') !== null;

        $settings = CommandHelpers::settings();

        if ($settings === null) {
            return EXIT_ERROR;
        }

        $serverUrl = (string) $settings->getSetting(Updater::SETTING_SERVER_URL, '');
        $token     = (string) $settings->getSetting(Updater::SETTING_SERVER_TOKEN, '');
        $manager   = new UpgradeManager();

        $keyProblem = $manager->publicKeyProblem();
        if ($keyProblem !== null) {
            CLI::error($keyProblem);

            return EXIT_ERROR;
        }

        $found = (new ReleaseFeed($manager))->latest($serverUrl, $token, Updater::VERSION);

        if (! $found['ok']) {
            CLI::error($found['error']);
            CommandHelpers::hintWhenUnconfigured($settings);

            return EXIT_ERROR;
        }

        $release = $found['release'];

        if (! ReleaseFeed::isNewer($release['version'], Updater::VERSION)) {
            CLI::write('Already on v' . Updater::VERSION . '; nothing to apply.', 'green');

            return EXIT_SUCCESS;
        }

        CommandHelpers::describe($release, true);

        $issues = $manager->checkPermissions();
        if ($issues !== []) {
            CLI::newLine();
            CLI::error('Insufficient permissions: ' . implode(', ', $issues));

            return EXIT_ERROR;
        }

        CLI::newLine();
        CLI::write('Downloading v' . $release['version'] . '…', 'yellow');

        $prepared = $manager->prepare(
            $release['version'],
            $release['zip_url'],
            $release['manifest_url'] !== '' ? $release['manifest_url'] : null,
            $token,
            $serverUrl,
        );

        if (! $prepared['success']) {
            CLI::error($prepared['error']);

            return EXIT_ERROR;
        }

        $this->summarise($prepared);

        if ($dryRun) {
            $manager->cleanup($prepared['tmpDir']);
            CLI::newLine();
            CLI::write('Dry run: nothing was applied, temporary files removed.', 'yellow');

            return EXIT_SUCCESS;
        }

        if (! $assumeYes && ! $this->confirm($prepared['version'])) {
            $manager->cleanup($prepared['tmpDir']);
            CLI::write('Cancelled, temporary files removed.', 'yellow');

            return EXIT_SUCCESS;
        }

        return $this->apply($manager, $prepared, $settings);
    }

    /**
     * @param array<string, mixed> $prepared
     */
    private function summarise(array $prepared): void
    {
        $diff = $prepared['diff'];

        CLI::newLine();
        CLI::write('Covers  : ' . implode(', ', $prepared['roots'] ?? []), 'white');
        CLI::write('Signed  : ' . ($prepared['signed'] ? 'yes' : 'no'), $prepared['signed'] ? 'green' : 'yellow');

        if (($prepared['requires'] ?? []) !== []) {
            CLI::write('Requires: ' . ReleaseRequirements::describe($prepared['requires']), 'white');
        }

        CLI::write(sprintf(
            'Changes : %d added, %d modified, %d deleted, %d unchanged',
            count($diff['added']),
            count($diff['modified']),
            count($diff['deleted']),
            $diff['unchanged'],
        ), 'white');

        foreach (['added' => 'green', 'modified' => 'yellow', 'deleted' => 'red'] as $group => $colour) {
            foreach (array_slice($diff[$group], 0, 10) as $file) {
                CLI::write('  ' . substr($group, 0, 1) . ' ' . $file, $colour);
            }

            if (count($diff[$group]) > 10) {
                CLI::write('  … and ' . (count($diff[$group]) - 10) . ' more ' . $group, $colour);
            }
        }
    }

    private function confirm(string $version): bool
    {
        CLI::newLine();
        CLI::write('Modified files are backed up before being overwritten, and pending', 'yellow');
        CLI::write('migrations run as part of this.', 'yellow');

        return strtolower(CLI::prompt('Apply v' . $version . '?', ['y', 'n'])) === 'y';
    }

    /**
     * @param array<string, mixed> $prepared
     */
    private function apply(UpgradeManager $manager, array $prepared, $settings): int
    {
        $migrationError = null;
        $migrate        = static function () use (&$migrationError): void {
            try {
                service('migrations')->latest();
            } catch (\Throwable $e) {
                $migrationError = $e->getMessage();
            }
        };

        $result = $manager->apply(
            $prepared['extractDir'],
            $prepared['diff'],
            $prepared['manifest'] ?? [],
            $prepared['version'],
            $prepared['roots'] ?? null,
            $migrate,
        );

        if (! $result['success']) {
            CLI::error('Apply failed: ' . ($result['error'] ?? ''));

            if (isset($result['backup_dir'])) {
                CLI::write('The backup taken before this attempt: writable/backups/'
                    . basename($result['backup_dir']), 'yellow');
                CLI::write('Restore it with the update panel if the install is left inconsistent.', 'yellow');
            }

            return EXIT_ERROR;
        }

        service('cache')->clean();
        $manager->cleanup($prepared['tmpDir']);

        $settings->setSetting(Updater::SETTING_LAST_VERSION, $prepared['version']);
        $settings->setSetting(Updater::SETTING_LAST_DATE, date('Y-m-d H:i:s'));

        $pruned = $manager->pruneBackups();

        CLI::newLine();
        CLI::write(sprintf(
            'v%s applied: %d added, %d modified, %d deleted.',
            $prepared['version'],
            $result['added'],
            $result['modified'],
            $result['deleted'],
        ), 'green');
        CLI::write('Backup  : writable/backups/' . basename($result['backup_dir']), 'white');

        if ($pruned['deleted'] !== []) {
            CLI::write('Pruned  : ' . count($pruned['deleted']) . ' old backup(s)', 'white');
        }

        if ($migrationError !== null) {
            CLI::newLine();
            CLI::error('Migrations reported: ' . $migrationError);

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
