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

        return view('admin/updates', [
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
        $serverUrl = rtrim(trim((string) $settings->get(Updater::SETTING_SERVER_URL, '')), '/');
        $token     = trim((string) $settings->get(Updater::SETTING_SERVER_TOKEN, ''));

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
        $token    = trim((string) $settings->get(Updater::SETTING_SERVER_TOKEN, ''));

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
        ]);

        return redirect()->to('/admin/updates');
    }

    public function upgradeApply(): \CodeIgniter\HTTP\RedirectResponse
    {
        $state = session()->get('upgrade_pending');
        if (! $state) {
            return redirect()->to('/admin/updates')->with('error', 'No pending update in session. Start over.');
        }

        $manager = new UpgradeManager();
        $result  = $manager->apply($state['extractDir'], $state['diff'], $state['manifest'] ?? []);

        if (! $result['success']) {
            return redirect()->to('/admin/updates')->with('error', 'Apply failed: ' . ($result['error'] ?? ''));
        }

        // Run pending DB migrations
        $migrationError = null;
        try {
            service('migrations')->latest();
        } catch (\Throwable $e) {
            $migrationError = $e->getMessage();
        }

        // Clear caches
        service('cache')->clean();

        // Cleanup temp
        $manager->cleanup($state['tmpDir']);
        session()->remove('upgrade_pending');

        // Record the event
        $settings = $this->settings();
        $settings->set(Updater::SETTING_LAST_VERSION, $state['version']);
        $settings->set(Updater::SETTING_LAST_DATE, date('Y-m-d H:i:s'));

        $msg = sprintf(
            'v%s applied: %d added, %d modified. Backup: writable/backups/%s',
            $state['version'],
            $result['added'],
            $result['modified'],
            basename($result['backup_dir'])
        );
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
}
