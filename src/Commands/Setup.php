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
        '-f'      => 'Overwrite files that already exist without prompting.',
        '--views' => 'Also copy the panel into app/Views so you can edit it. Without this, it is rendered from the package and follows its updates.',
    ];

    private string $sourcePath;

    public function run(array $params): void
    {
        $this->sourcePath = __DIR__ . '/../';

        $this->publishConfig();

        // The view is no longer copied by default: rendering it from the
        // package is what lets interface changes arrive with `composer update`.
        if (CLI::getOption('views') !== null) {
            $this->publishView();
        }

        $this->wireRoutes();

        CLI::newLine();
        CLI::write('Next steps:', 'yellow');
        CLI::write('  1. Edit app/Config/Updater.php (VERSION, DATE, USER_AGENT).', 'white');
        CLI::write('  2. Set $layout to the layout the panel should extend, and $appName.', 'white');
        CLI::write('  3. Set update_server_url (and optional token), e.g. via UpdaterSettings.', 'white');
        CLI::write('  4. Make sure the "admin" filter (or whatever you pass to routes()) protects these routes.', 'white');
        CLI::write('  5. Run `php spark update:manifest` before cutting each release.', 'white');

        if (CLI::getOption('views') === null) {
            CLI::newLine();
            CLI::write('The panel is rendered from the package, so it stays up to date on its own.', 'white');
            CLI::write('Run this command with --views only if you need to edit the markup itself.', 'white');
        }
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

                // The layout the update panel extends, and the name shown beside the
                // version. The panel itself comes from the package unless you publish
                // it with `php spark updater:setup --views`.
                public string $layout = 'layout/main';

                public ?string $appName = 'My App';

                // Already have a settings system (e.g. AppSettingModel)? Point this at
                // your own class implementing Forgelabme\Ci4Updater\Libraries\SettingsInterface
                // instead of the default JSON-file-in-writable/ store:
                // public string $settingsClass = \App\Libraries\MySettingsAdapter::class;

                // List a public key here to require signed releases: from then on an
                // unsigned release is refused. Generate a pair with
                // `php spark updater:keygen` — see the package's docs/signing.md.
                // public array $publicKeys = [WRITEPATH . 'keys/release-signing.pub'];
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
            $overwrite = CLI::prompt("  {$relativePath} already exists. Overwrite?", ['n', 'y']);
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
