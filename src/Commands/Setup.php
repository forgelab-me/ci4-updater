<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Usage: php spark updater:setup
 *
 * One-time setup for a new app:
 *  - publishes an editable Config\Updater to app/Config/Updater.php
 *  - publishes the admin view to app/Views/admin/updates.php so it can be
 *    adapted to your layout
 *  - wires `service('updater')->routes($routes);` into app/Config/Routes.php
 *
 * Safe to re-run: existing files are only overwritten with -f or after
 * confirmation, and the routes line is only added once.
 */
class Setup extends BaseCommand
{
    protected $group       = 'Update';
    protected $name        = 'updater:setup';
    protected $description = 'Publishes the config, admin view, and routes needed to use ci4-updater in this app.';

    protected $options = [
        '-f' => 'Overwrite files that already exist without prompting.',
    ];

    private string $sourcePath;

    public function run(array $params): void
    {
        $this->sourcePath = __DIR__ . '/../';

        $this->publishConfig();
        $this->publishView();
        $this->wireRoutes();

        CLI::newLine();
        CLI::write('Next steps:', 'yellow');
        CLI::write('  1. Edit app/Config/Updater.php (VERSION, DATE, USER_AGENT).', 'white');
        CLI::write('  2. Adapt app/Views/admin/updates.php to your layout.', 'white');
        CLI::write('  3. Set update_server_url (and optional token), e.g. via UpdaterSettings.', 'white');
        CLI::write('  4. Make sure the "admin" filter (or whatever you pass to routes()) protects these routes.', 'white');
        CLI::write('  5. Run `php spark update:manifest` before cutting each release.', 'white');
    }

    private function publishConfig(): void
    {
        $content = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Config;

            use Forgelabme\Ci4Updater\Config\Updater as BaseUpdater;

            /**
             * Self-update system configuration for this app.
             * Bump VERSION / DATE before each release, then run `php spark update:manifest`.
             */
            class Updater extends BaseUpdater
            {
                public const VERSION = '1.0.0';

                public const DATE = '__DATE__';

                public const USER_AGENT = 'MyAppUpdater/1.0';

                // Already have a settings system (e.g. AppSettingModel)? Point this at
                // your own class implementing Forgelabme\Ci4Updater\Libraries\SettingsInterface
                // instead of the default JSON-file-in-writable/ store:
                // public string $settingsClass = \App\Libraries\MySettingsAdapter::class;
            }

            PHP;

        $content = str_replace('__DATE__', date('Y-m-d'), $content);

        $this->writeFile('Config/Updater.php', $content);
    }

    private function publishView(): void
    {
        $content = file_get_contents($this->sourcePath . 'Views/admin/updates.php');

        $this->writeFile('Views/admin/updates.php', $content);
    }

    private function wireRoutes(): void
    {
        $file = APPPATH . 'Config/Routes.php';

        if (! is_file($file)) {
            CLI::error("  Could not find {$file}.");
            return;
        }

        $content = file_get_contents($file);

        if (str_contains($content, "service('updater')->routes(")) {
            CLI::write('  Routes: already wired, skipping.', 'green');
            return;
        }

        $addition = "\nservice('updater')->routes(\$routes);\n";

        file_put_contents($file, rtrim($content) . "\n" . $addition);
        CLI::write('  Updated: app/Config/Routes.php', 'green');
    }

    private function writeFile(string $relativePath, string $content): void
    {
        $dest = APPPATH . $relativePath;

        if (is_file($dest) && CLI::getOption('f') === null) {
            $overwrite = $this->prompt("  {$relativePath} already exists. Overwrite?", ['n', 'y']);
            if ($overwrite !== 'y') {
                CLI::error("  Skipped {$relativePath}. Use -f to force overwrite.");
                return;
            }
        }

        @mkdir(dirname($dest), 0755, true);
        file_put_contents($dest, $content);
        CLI::write("  Created: {$relativePath}", 'green');
    }
}
