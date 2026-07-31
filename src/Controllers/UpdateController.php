<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Controllers;

use CodeIgniter\Controller;
use Config\Updater;
use Forgelabme\Ci4Updater\Libraries\SettingsInterface;
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
            'migrations'     => $migrations,
            'pendingCount'   => $pendingCount,
            'cacheSize'      => $cacheSize,
            'cacheAdapter'   => basename(str_replace('\\', '/', get_class(service('cache')))),
            'upgradePending' => session()->get('upgrade_pending'),
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

    public function checkRemoteVersion(): \CodeIgniter\HTTP\ResponseInterface
    {
        $settings  = $this->settings();
        $serverUrl = rtrim(trim((string) $settings->getSetting(Updater::SETTING_SERVER_URL, '')), '/');
        $token     = trim((string) $settings->getSetting(Updater::SETTING_SERVER_TOKEN, ''));

        if ($serverUrl === '') {
            return $this->response->setJSON([
                'error' => 'No update server configured. Set ' . Updater::SETTING_SERVER_URL . ' in settings.',
            ]);
        }

        $latestUrl = $serverUrl . '/latest.json';
        $headers   = 'User-Agent: ' . Updater::USER_AGENT . "\r\nAccept: application/json\r\n";
        if ($token !== '') {
            $headers .= "Authorization: Bearer {$token}\r\n";
        }
        $ctx  = stream_context_create(['http' => [
            'method'          => 'GET',
            'header'          => $headers,
            'timeout'         => 6,
            'follow_location' => 1,
        ]]);
        $json = @file_get_contents($latestUrl, false, $ctx);

        if ($json === false) {
            return $this->response->setJSON(['error' => "Could not reach the update server ({$latestUrl})."]);
        }

        $data = json_decode($json, true);
        if (! is_array($data) || empty($data['version'])) {
            return $this->response->setJSON(['error' => 'Invalid response from the update server.']);
        }

        return $this->response->setJSON([
            'version'      => $data['version'],
            'changelog'    => $data['changelog'] ?? '',
            'date'         => $data['date'] ?? '',
            'zip_url'      => $data['zip_url'] ?? '',
            'manifest_url' => $data['manifest_url'] ?? '',
        ]);
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

        $settings = $this->settings();
        $token    = trim((string) $settings->getSetting(Updater::SETTING_SERVER_TOKEN, ''));

        $manager    = new UpgradeManager();
        $permIssues = $manager->checkPermissions();
        if (! empty($permIssues)) {
            return redirect()->to('/admin/updates')->with('error', 'Insufficient permissions: ' . implode(', ', $permIssues));
        }

        $result = $manager->prepare($version, $zipUrl, $manifestUrl, $token);
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
        $migrate        = static function () use (&$migrationError): void {
            try {
                service('migrations')->latest();
            } catch (\Throwable $e) {
                $migrationError = $e->getMessage();
            }
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
     * Restores a backup taken before an update was applied.
     */
    public function rollback(): \CodeIgniter\HTTP\RedirectResponse
    {
        $name = trim((string) ($this->request->getPost('backup') ?? ''));

        $manager = new UpgradeManager();
        $result  = $manager->restoreBackup($name);

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

        if ($migrationError !== null) {
            $message .= ' — Warning: migrations: ' . $migrationError;
        }

        return redirect()->to('/admin/updates')->with('success', $message);
    }
}
