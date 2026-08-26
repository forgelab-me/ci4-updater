<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Updater;
use Forgelabme\Ci4Updater\Libraries\ReleaseFeed;
use Forgelabme\Ci4Updater\Libraries\SettingsInterface;

/**
 * Usage: php spark updater:check
 *
 * Asks the configured update server what it is serving, and says whether it is
 * newer than what is installed.
 *
 * Exit codes, so a cron job or a deploy script can branch on it:
 *   0  up to date
 *   2  an update is available
 *   1  the check could not be made
 */
class CheckUpdate extends BaseCommand
{
    public const EXIT_UPDATE_AVAILABLE = 2;

    protected $group       = 'Update';
    protected $name        = 'updater:check';
    protected $description = 'Checks the update server for a newer release.';
    protected $usage       = 'updater:check [--quiet]';

    protected $options = [
        '--quiet' => 'Print nothing; report through the exit code only.',
    ];

    public function run(array $params): int
    {
        $quiet    = CLI::getOption('quiet') !== null;
        $settings = CommandHelpers::settings();

        if ($settings === null) {
            return EXIT_ERROR;
        }

        $result = (new ReleaseFeed())->latest(
            (string) $settings->getSetting(Updater::SETTING_SERVER_URL, ''),
            (string) $settings->getSetting(Updater::SETTING_SERVER_TOKEN, ''),
            Updater::VERSION,
        );

        if (! $result['ok']) {
            if (! $quiet) {
                CLI::error($result['error']);
                CommandHelpers::hintWhenUnconfigured($settings);
            }

            return EXIT_ERROR;
        }

        $release = $result['release'];
        $newer   = ReleaseFeed::isNewer($release['version'], Updater::VERSION);

        if (! $quiet) {
            CommandHelpers::describe($release, $newer);
        }

        return $newer ? self::EXIT_UPDATE_AVAILABLE : EXIT_SUCCESS;
    }
}
