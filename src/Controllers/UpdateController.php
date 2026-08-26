<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Controllers;

use CodeIgniter\Controller;
use Config\Updater;
use Forgelabme\Ci4Updater\Libraries\DownloadPolicy;
use Forgelabme\Ci4Updater\Libraries\ReleaseFeed;
use Forgelabme\Ci4Updater\Libraries\ReleaseVerification;
use Forgelabme\Ci4Updater\Libraries\SettingsInterface;
use Forgelabme\Ci4Updater\Libraries\UpdaterSettings;
use Forgelabme\Ci4Updater\Libraries\UpgradeManager;

/**
 * Self-update admin panel: shows pending DB migrations, lets the admin check
 * a remote update server for new releases, download/diff/apply them, and
 * clear caches.
 *
 * Routes are wired via Forgelabme\Ci4Updater\Updater::routes() — see
 * `php spark updater:setup`.
 */
class UpdateController extends Controller
{
    public function index(): string
    {
        $db = db_connect();

        // Applied migrations from the migrations table
        $appliedVersions = [];
        try {
            foreach ($db->table('migrations')->get()->getResultArray() as $row) {
                $appliedVersions[$row['version']] = $row;
            }
        } catch (\Throwable $e) {
            // Table may not exist on a fresh install
        }

        // All migration files, newest first
        $files = glob(APPPATH . 'Database/Migrations/*.php') ?: [];
        rsort($files);

        $migrations = [];
        foreach ($files as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);
            preg_match('/^(\d{4}-\d{2}-\d{2}-\d+)_(.+)$/', $filename, $m);
            $version    = $m[1] ?? $filename;
            $name       = $m[2] ?? $filename;
            $isApplied  = isset($appliedVersions[$version]);
            $migrations[] = [
                'version' => $version,
                'name'    => $name,
                'applied' => $isApplied,
                'batch'   => $isApplied ? $appliedVersions[$version]['batch'] : null,
                'ran_at'  => $isApplied ? date('d/m/Y H:i', (int) $appliedVersions[$version]['time']) : null,
            ];
        }

        $pendingCount = count(array_filter($migrations, static fn ($m) => ! $m['applied']));

        // Cache directory size
        $cacheDir  = WRITEPATH . 'cache/';
        $cacheSize = 0;
        if (is_dir($cacheDir)) {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iter as $f) {
                if ($f->isFile()) {
                    $cacheSize += $f->getSize();
                }
            }
        }

        /** @var Updater $config */
        $config = config('Updater');

        return view($this->resolveView($config), [
            'layout'         => $config->layout,
            'appName'        => $config->appName ?? 'Application',
            'title'          => 'Admin — Updates',
            'appVersion'     => Updater::VERSION,
            'appDate'        => Updater::DATE,
            'phpVersion'     => PHP_VERSION,
            'ciVersion'      => \CodeIgniter\CodeIgniter::CI_VERSION,
            'dbVersion'      => $db->getVersion(),
            'dbDriver'       => $db->getPlatform(),
            'migrations'     => $migrations,
            'pendingCount'   => $pendingCount,
            'cacheSize'      => $cacheSize,
            'cacheAdapter'   => basename(str_replace('\\', '/', get_class(service('cache')))),
            'upgradePending' => session()->get('upgrade_pending'),
            'keyProblem'     => (new UpgradeManager())->publicKeyProblem(),
            'serverProblem'  => $this->serverProblem(),
            'backups'        => (new UpgradeManager())->listBackups(),
            'keepBackups'    => config('Updater')->keepBackups ?? 0,
        ]);
    }

    public function applyMigrations(): \CodeIgniter\HTTP\RedirectResponse
    {
        try {
            service('migrations')->latest();
            return redirect()->to('/admin/updates')->with('success', 'All migrations have been applied.');
        } catch (\Throwable $e) {
            return redirect()->to('/admin/updates')->with('error', 'Migration error: ' . $e->getMessage());
        }
    }

    public function clearCache(): \CodeIgniter\HTTP\RedirectResponse
    {
        service('cache')->clean();
        return redirect()->to('/admin/updates')->with('success', 'Cache cleared.');
    }

    /**
     * Which view the panel renders.
     *
     * A view published into the app by an earlier `updater:setup` keeps
     * winning, so upgrading the package never overrides someone's customised
     * panel. Everyone else gets the package's own view, and with it the
     * interface changes that ship in new releases.
     */
    private function resolveView(Updater $config): string
    {
        if (is_string($config->viewPath) && $config->viewPath !== '') {
            return $config->viewPath;
        }

        if (is_file(APPPATH . 'Views/admin/updates.php')) {
            return 'admin/updates';
        }

        return '\Forgelabme\Ci4Updater\Views\admin\updates';
    }

    /**
     * Resolves the settings store configured via Config\Updater::$settingsClass.
     */
    private function settings(): SettingsInterface
    {
        /** @var Updater $config */
        $config = config('Updater');
        $class  = $config->settingsClass;

        return new $class();
    }

    /**
     * Where the update-server settings actually live, in words.
     *
     * The default store is a JSON file; a project that swapped
     * Config\Updater::$settingsClass has its own, and telling that user to
     * edit a file they don't use would be worse than saying nothing.
     */
    private function settingsLocation(): string
    {
        /** @var Updater $config */
        $config = config('Updater');

        return $config->settingsClass === UpdaterSettings::class
            ? 'writable/' . UpdaterSettings::FILENAME
            : 'your ' . (new \ReflectionClass($config->settingsClass))->getShortName() . ' store';
    }

    /**
     * @return string|null What is wrong with the configured update server, or
     *                     null when nothing is
     */
    public function serverProblem(): ?string
    {
        return DownloadPolicy::serverWarning(
            (string) $this->settings()->getSetting(Updater::SETTING_SERVER_URL, '')
        );
    }

    public function checkRemoteVersion(): \CodeIgniter\HTTP\ResponseInterface
    {
        $settings  = $this->settings();
        $serverUrl = rtrim(trim((string) $settings->getSetting(Updater::SETTING_SERVER_URL, '')), '/');
        $token     = trim((string) $settings->getSetting(Updater::SETTING_SERVER_TOKEN, ''));

        if ($serverUrl === '') {
            // Naming the place, not just the key: this is exactly where a new
            // install gets stuck, so the message has to be actionable.
            return $this->response->setJSON([
                'error' => 'No update server configured. Run '
                    . '`php spark updater:config --url https://updates.example.com/api/my-app`, '
                    . 'or set ' . Updater::SETTING_SERVER_URL . ' in ' . $this->settingsLocation() . '.',
            ]);
        }

        // Telling the server where we are lets it report what going straight
        // to the latest release would step over — it is the only side that can
        // see the releases in between. A feed that ignores the parameter (a
        // static latest.json, say) simply answers without those fields.
        $result = (new ReleaseFeed())->latest($serverUrl, $token, Updater::VERSION);

        return $this->response->setJSON(
            $result['ok'] ? $result['release'] : ['error' => $result['error']]
        );
    }

    /**
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        return ReleaseFeed::stringList($value);
    }

    // ── Upgrade pipeline ─────────────────────────────────────────────────────

    public function upgradeDownload(): \CodeIgniter\HTTP\RedirectResponse
    {
        $version     = trim((string) ($this->request->getPost('version') ?? ''));
        $zipUrl      = trim((string) ($this->request->getPost('zip_url') ?? ''));
        $manifestUrl = trim((string) ($this->request->getPost('manifest_url') ?? '')) ?: null;

        if ($version === '' || $zipUrl === '') {
            return redirect()->to('/admin/updates')->with('error', 'Missing release data.');
        }

        $settings  = $this->settings();
        $token     = trim((string) $settings->getSetting(Updater::SETTING_SERVER_TOKEN, ''));
        $serverUrl = trim((string) $settings->getSetting(Updater::SETTING_SERVER_URL, ''));

        $manager    = new UpgradeManager();
        $permIssues = $manager->checkPermissions();
        if (! empty($permIssues)) {
            return redirect()->to('/admin/updates')->with('error', 'Insufficient permissions: ' . implode(', ', $permIssues));
        }

        // The configured server decides what may be fetched, and what sees
        // the token.
        $result = $manager->prepare($version, $zipUrl, $manifestUrl, $token, $serverUrl);
        if (! $result['success']) {
            return redirect()->to('/admin/updates')->with('error', 'Preparation failed: ' . $result['error']);
        }

        session()->set('upgrade_pending', [
            'version'    => $result['version'],
            'tmpDir'     => $result['tmpDir'],
            'extractDir' => $result['extractDir'],
            'diff'       => $result['diff'],
            'manifest'   => $result['manifest'] ?? [],
            // The scope this release declared. apply() re-validates it, but it
            // has to travel: it is a property of the release, not of the app.
            'roots'      => $result['roots'] ?? null,
        ]);

        return redirect()->to('/admin/updates');
    }

    public function upgradeApply(): \CodeIgniter\HTTP\RedirectResponse
    {
        $state = session()->get('upgrade_pending');
        if (! $state) {
            return redirect()->to('/admin/updates')->with('error', 'No pending update in session. Start over.');
        }

        // Migrations run inside apply(), after the new application code is
        // written and before any directory is swapped. Everything the request
        // needs is loaded by then, so the window where code and schema
        // disagree closes before the response is sent — a later request would
        // boot filters and models against the old schema and could 500 on the
        // very page that was meant to migrate it.
        $migrationError = null;
        $migrate        = static function () use (&$migrationError): array {
            $runner = service('migrations');
            $before = self::lastBatch($runner);

            try {
                $runner->latest();
            } catch (\Throwable $e) {
                $migrationError = $e->getMessage();
            }

            $after = self::lastBatch($runner);

            // Recorded so a rollback can offer to run the down() side of
            // exactly the batch this update created.
            return $after > $before
                ? ['migrations' => ['batch_before' => $before, 'batch_after' => $after]]
                : [];
        };

        $manager = new UpgradeManager();
        $result  = $manager->apply(
            $state['extractDir'],
            $state['diff'],
            $state['manifest'] ?? [],
            $state['version'],
            $state['roots'] ?? null,
            $migrate,
        );

        if (! $result['success']) {
            return redirect()->to('/admin/updates')->with('error', 'Apply failed: ' . ($result['error'] ?? ''));
        }

        // Clear caches
        service('cache')->clean();

        // Cleanup temp
        $manager->cleanup($state['tmpDir']);
        session()->remove('upgrade_pending');

        // Record the event
        $settings = $this->settings();
        $settings->setSetting(Updater::SETTING_LAST_VERSION, $state['version']);
        $settings->setSetting(Updater::SETTING_LAST_DATE, date('Y-m-d H:i:s'));

        // Only ever pruned here: an update has just succeeded and left a fresh
        // backup, so the oldest ones can go without losing the useful one.
        $pruned = $manager->pruneBackups();

        $msg = sprintf(
            'v%s applied: %d added, %d modified. Backup: writable/backups/%s',
            $state['version'],
            $result['added'],
            $result['modified'],
            basename($result['backup_dir'])
        );

        if ($pruned['deleted'] !== []) {
            $msg .= sprintf(
                ' — %d old backup(s) removed (%s freed)',
                count($pruned['deleted']),
                $this->formatBytes($pruned['freed'])
            );
        }
        if ($migrationError) {
            $msg .= ' — Warning: migrations: ' . $migrationError;
        }

        $drift = $result['verified']['drift'] ?? [];

        if ($drift !== []) {
            return redirect()->to('/admin/updates')->with('error', $msg . ' — ' . count($drift)
                . ' file(s) do not match the manifest after writing: '
                . ReleaseVerification::describe($drift)
                . '. Restore the backup above if the install is unusable.');
        }

        return redirect()->to('/admin/updates')->with('success', $msg);
    }

    public function upgradeCancel(): \CodeIgniter\HTTP\RedirectResponse
    {
        $state = session()->get('upgrade_pending');
        if ($state) {
            (new UpgradeManager())->cleanup($state['tmpDir']);
            session()->remove('upgrade_pending');
        }
        return redirect()->to('/admin/updates')->with('success', 'Update cancelled, temporary files removed.');
    }

    /**
     * Deletes a single backup.
     */
    public function deleteBackup(): \CodeIgniter\HTTP\RedirectResponse
    {
        $name   = trim((string) ($this->request->getPost('backup') ?? ''));
        $result = (new UpgradeManager())->deleteBackup($name);

        if (! $result['success']) {
            return redirect()->to('/admin/updates')->with('error', 'Could not delete backup: ' . ($result['error'] ?? ''));
        }

        return redirect()->to('/admin/updates')->with(
            'success',
            sprintf('Backup %s deleted (%s freed).', $name, $this->formatBytes($result['freed']))
        );
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 1) . ' MB';
        }

        if ($bytes >= 1_024) {
            return number_format($bytes / 1_024, 1) . ' KB';
        }

        return $bytes . ' B';
    }

    /**
     * The batch number the migration runner is on, or 0 when it cannot say.
     */
    private static function lastBatch(object $runner): int
    {
        try {
            return method_exists($runner, 'getLastBatch') ? (int) $runner->getLastBatch() : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Restores a backup taken before an update was applied.
     */
    public function rollback(): \CodeIgniter\HTTP\RedirectResponse
    {
        $name     = trim((string) ($this->request->getPost('backup') ?? ''));
        $regress  = (bool) $this->request->getPost('revert_migrations');
        $reverted = 0;

        $manager = new UpgradeManager();

        $revert = static function (?array $batch) use ($regress, &$reverted): ?string {
            if (! $regress || $batch === null) {
                return null;
            }

            try {
                service('migrations')->regress($batch['batch_before']);
                $reverted = $batch['batch_after'] - $batch['batch_before'];
            } catch (\Throwable $e) {
                // Refusing here leaves the files as they are: a schema that
                // would not come back is worse undone by halves.
                return 'the migrations could not be reverted (' . $e->getMessage() . '), so nothing was restored.';
            }

            return null;
        };

        $result = $manager->restoreBackup($name, $revert);

        if (! $result['success']) {
            return redirect()->to('/admin/updates')->with('error', 'Rollback failed: ' . ($result['error'] ?? ''));
        }

        // Bring the database back in step with the restored code where possible.
        $migrationError = null;
        try {
            service('migrations')->latest();
        } catch (\Throwable $e) {
            $migrationError = $e->getMessage();
        }

        service('cache')->clean();

        $message = sprintf(
            'Rolled back from %s: %d file(s) restored, %d added file(s) removed.',
            $name,
            $result['restored'],
            $result['removed']
        );

        if ($reverted > 0) {
            $message .= sprintf(' %d migration batch(es) reverted.', $reverted);
        }

        if ($migrationError !== null) {
            $message .= ' — Warning: migrations: ' . $migrationError;
        }

        return redirect()->to('/admin/updates')->with('success', $message);
    }
}
