<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Forgelabme\Ci4Updater\Libraries\Finalize;
use Forgelabme\Ci4Updater\Libraries\PackageMigrations;

/**
 * Usage: php spark updater:finalize [--accept-missing]
 *
 * Runs the migrations an applied update's vendor/ brought with it, then closes
 * the maintenance window. `updater:apply` calls this in a new process; run it
 * by hand when that did not finish, or when the panel cannot be reached.
 *
 * Exit codes:
 *   0  done, or nothing was waiting
 *   1  the migrations failed; the update stays pending
 */
class FinalizeUpdate extends BaseCommand
{
    protected $group       = 'Update';
    protected $name        = 'updater:finalize';
    protected $description = 'Runs the package migrations an applied update is waiting on.';
    protected $usage       = 'updater:finalize [--accept-missing]';

    protected $options = [
        '--accept-missing' => 'Finish even if some migrations the release brought are not recorded afterwards.',
    ];

    public function run(array $params): int
    {
        $state = Finalize::state();

        if ($state === null) {
            CLI::write('Nothing to finish.', 'green');

            return EXIT_SUCCESS;
        }

        CLI::write('Running: ' . PackageMigrations::describe($state['migrations']), 'yellow');

        $result = Finalize::run(null, null, CLI::getOption('accept-missing') !== null);

        if (! $result['success']) {
            CLI::error($result['error'] ?? 'Could not finish the update.');
            CLI::write('The update stays pending and the site in maintenance. Fix the cause and run this again,', 'yellow');
            CLI::write('or restore writable/backups/' . $state['backup'] . ' from the panel.', 'yellow');

            return EXIT_ERROR;
        }

        service('cache')->clean();

        CLI::write('Done: the package migrations have run and the site is back.', 'green');

        return EXIT_SUCCESS;
    }
}
