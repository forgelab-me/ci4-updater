<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Commands;

use CodeIgniter\CLI\CLI;
use Config\Updater;
use Forgelabme\Ci4Updater\Libraries\SettingsInterface;

/**
* What `updater:check` and `updater:apply` have in common: reaching the
 * settings store, and saying what the server is offering.
 */
final class CommandHelpers
{
    public static function settings(): ?SettingsInterface
    {
        /** @var Updater $config */
        $config = config('Updater');
        $class  = $config->settingsClass;

        if (! class_exists($class)) {
            CLI::error("Config\\Updater::\$settingsClass points at {$class}, which does not exist.");

            return null;
        }

        $store = new $class();

        if (! $store instanceof SettingsInterface) {
            CLI::error("{$class} does not implement SettingsInterface.");

            return null;
        }

        return $store;
    }

    public static function hintWhenUnconfigured(SettingsInterface $settings): void
    {
        if ((string) $settings->getSetting(Updater::SETTING_SERVER_URL, '') !== '') {
            return;
        }

        CLI::newLine();
        CLI::write('Point it at a server with:', 'yellow');
        CLI::write('  php spark updater:config --url https://updates.example.com/api/my-app', 'white');
    }

    /**
     * @param array<string, mixed> $release
     */
    public static function describe(array $release, bool $newer): void
    {
        CLI::write('Installed : v' . Updater::VERSION, 'white');
        CLI::write('Available : v' . $release['version']
            . ($release['date'] !== '' ? '  (' . $release['date'] . ')' : ''), $newer ? 'green' : 'white');

        if (! $newer) {
            CLI::newLine();
            CLI::write('Up to date.', 'green');

            return;
        }

        if ($release['required_step']) {
            CLI::newLine();
            CLI::write('This release is served on its own because it must not be skipped.'
                . ($release['latest_version'] !== ''
                    ? ' v' . $release['latest_version'] . ' follows once it is applied.'
                    : ' Check again once it is applied.'), 'blue');
        }

        if ($release['missed_roots'] !== []) {
            CLI::newLine();
            CLI::write('Warning: v' . $release['version'] . ' does not cover '
                . implode(', ', $release['missed_roots']) . '.', 'yellow');

            if ($release['skipped_versions'] !== []) {
                $skipped = $release['skipped_versions'];

                CLI::write('  ' . implode(', ', $skipped) . ' did, and installing this one skips '
                    . (count($skipped) > 1 ? 'them.' : 'it.'), 'yellow');
            }

            CLI::write('  Those directories stay exactly as they are.', 'yellow');
        }

        if ($release['changelog'] !== '') {
            CLI::newLine();
            CLI::write('Changelog:', 'yellow');
            foreach (explode("\n", trim($release['changelog'])) as $line) {
                CLI::write('  ' . rtrim($line), 'white');
            }
        }
    }
}
