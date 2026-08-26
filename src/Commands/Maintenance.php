<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Forgelabme\Ci4Updater\Libraries\MaintenanceWindow;

/**
 * Usage: php spark updater:maintenance [--on] [--off] [--for <seconds>]
 *
 * An update holds this window open on its own. Opening it by hand covers the
 * work around one — a manual `composer install`, a schema change, a restore.
 *
 * Exit codes:
 *   0  the window is closed (or was just closed)
 *   2  the window is open
 */
class Maintenance extends BaseCommand
{
    public const EXIT_WINDOW_OPEN = 2;

    protected $group       = 'Update';
    protected $name        = 'updater:maintenance';
    protected $description = 'Opens or closes the maintenance window, or reports it.';
    protected $usage       = 'updater:maintenance [--on] [--off] [--for <seconds>]';

    protected $options = [
        '--on'  => 'Open the window.',
        '--off' => 'Close it.',
        '--for' => 'Seconds before it expires on its own (default: 600).',
    ];

    public function run(array $params): int
    {
        if (CLI::getOption('off') !== null) {
            MaintenanceWindow::close();
            CLI::write('Maintenance window closed.', 'green');

            return EXIT_SUCCESS;
        }

        if (CLI::getOption('on') !== null) {
            $ttl = (int) (CLI::getOption('for') ?: 0);

            if (! MaintenanceWindow::open('Opened from the command line', $ttl > 0 ? $ttl : null)) {
                CLI::error('Could not write ' . MaintenanceWindow::path());

                return EXIT_ERROR;
            }

            CLI::write('Maintenance window open. Requests are answered 503 while the filter is registered.', 'yellow');
            CLI::write('Expires in ' . MaintenanceWindow::retryAfter() . 's, or on `updater:maintenance --off`.', 'white');

            return self::EXIT_WINDOW_OPEN;
        }

        return $this->report();
    }

    private function report(): int
    {
        if (! MaintenanceWindow::isOpen()) {
            CLI::write('Maintenance window: closed.', 'green');

            return EXIT_SUCCESS;
        }

        $state = MaintenanceWindow::state() ?? [];

        CLI::write('Maintenance window: OPEN', 'yellow');
        CLI::write('  Since   : ' . ($state['started_at'] ?? '?'), 'white');
        CLI::write('  Reason  : ' . (($state['reason'] ?? '') !== '' ? $state['reason'] : '(none given)'), 'white');
        CLI::write('  Expires : in ' . MaintenanceWindow::retryAfter() . 's', 'white');

        return self::EXIT_WINDOW_OPEN;
    }
}
