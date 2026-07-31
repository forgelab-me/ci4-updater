<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Libraries;

use Config\Updater;

/**
 * Handles downloading, extracting, diffing, backing up, and applying
 * a release upgrade package fetched from a remote update server (or GitHub
 * release assets).
 *
 * Generic — all per-project knobs live in Config\Updater (SCAN_DIRS, USER_AGENT).
 * Nothing here references a specific app name, version constant, or settings store.
 */
class UpgradeManager
{
    /** Metadata written inside each backup directory, describing what the update changed. */
    public const BACKUP_MANIFEST = 'backup.json';

    /** @var list<string> Default scope, used when a manifest declares no roots of its own. */
    private array $scanDirs;

    /** @var list<string> Policy: the roots a release is allowed to declare. */
    private array $allowedRoots;

    private string $userAgent;

    /** @var list<string> PEM contents or paths; empty means signatures are not enforced. */
    private array $publicKeys;

    /** How many backups to retain; 0 keeps everything. */
    private int $keepBackups;

    /**
     * @param list<string>|null $publicKeys   Overrides Config\Updater::$publicKeys (mainly for tests)
     * @param int|null          $keepBackups  Overrides Config\Updater::$keepBackups
     * @param list<string>|null $allowedRoots Overrides Config\Updater::$allowedRoots
     */
    public function __construct(?array $publicKeys = null, ?int $keepBackups = null, ?array $allowedRoots = null)
    {
        $this->scanDirs     = ReleaseScope::normalize(Updater::SCAN_DIRS);
        $this->userAgent    = Updater::USER_AGENT;
        $this->publicKeys   = $publicKeys ?? self::configuredPublicKeys();
        $this->keepBackups  = $keepBackups ?? self::configuredInt('keepBackups', 5);
        $this->allowedRoots = ReleaseScope::normalize($allowedRoots ?? self::configuredAllowedRoots());

        // An empty policy means "whatever this app builds for itself".
        if ($this->allowedRoots === []) {
            $this->allowedRoots = $this->scanDirs;
        }
    }

    /**
     * @return list<string>
     */
    private static function configuredAllowedRoots(): array
    {
        if (function_exists('config')) {
            $config = config('Updater');

            if (is_object($config) && property_exists($config, 'allowedRoots') && is_array($config->allowedRoots)) {
                return ReleaseScope::normalize($config->allowedRoots);
            }
        }

        return [];
    }

    private static function configuredInt(string $property, int $default): int
    {
        if (function_exists('config')) {
            $config = config('Updater');

            if (is_object($config) && property_exists($config, $property) && is_int($config->{$property})) {
                return $config->{$property};
            }
        }

        return $default;
    }

    /**
     * @return list<string>
     */
    private static function configuredPublicKeys(): array
    {
        if (function_exists('config')) {
            $config = config('Updater');

            if (is_object($config) && property_exists($config, 'publicKeys') && is_array($config->publicKeys)) {
                return array_values(array_filter($config->publicKeys, static fn ($key) => is_string($key) && $key !== ''));
            }
        }

        return [];
    }

    /**
     * Enforces the signature policy: no configured key means signatures are
     * ignored entirely; one or more keys makes a valid signature mandatory.
     *
     * @return string|null An error message, or null when the release is acceptable
     */
    private function checkSignature(string $manifestBytes, ?string $signature): ?string
    {
        if ($this->publicKeys === []) {
            return null; // signing not in use for this install
        }

        if (! ReleaseSignature::isAvailable()) {
            return 'This install requires signed releases, but the openssl extension is not available to verify them.';
        }

        if ($signature === null || trim($signature) === '') {
            return 'This release is not signed, and this install only accepts signed releases. '
                . 'Publish it with « php spark update:manifest --sign », or clear Config\\Updater::$publicKeys.';
        }

        if (! ReleaseSignature::verify($manifestBytes, $signature, $this->publicKeys)) {
            return 'The release signature is invalid: it does not match the manifest, or it was made with a key this install does not trust.';
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Builds a SHA-256 manifest of all currently installed files.
     * Returns ['relative/path' => 'sha256hex', ...]
     */
    /**
     * @param list<string>|null $roots Defaults to the configured SCAN_DIRS
     */
    public function buildCurrentManifest(?array $roots = null): array
    {
        $base  = ROOTPATH;
        $files = [];

        foreach ($roots ?? $this->scanDirs as $dir) {
            $path = $base . $dir;
            if (! is_dir($path)) {
                continue;
            }
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iter as $f) {
                if (! $f->isFile()) {
                    continue;
                }
                $rel         = str_replace('\\', '/', substr($f->getPathname(), strlen($base)));
                $files[$rel] = hash_file('sha256', $f->getPathname());
            }
        }

        return $files;
    }

    /**
     * Checks that PHP can write to all scanned directories.
     * Returns a list of human-readable problems (empty = all OK).
     */
    public function checkPermissions(): array
    {
        $issues = [];
        foreach ($this->scanDirs as $dir) {
            if (! is_writable(ROOTPATH . $dir)) {
                $issues[] = "{$dir}/ is not writable";
            }
        }
        if (! is_writable(WRITEPATH)) {
            $issues[] = 'writable/ is not writable';
        }
        return $issues;
    }

    /**
     * Downloads the release zip, extracts it, finds the manifest, and diffs
     * the new manifest against the currently installed files.
     *
     * @return array{
     *   success: bool,
     *   version?: string,
     *   tmpDir?: string,
     *   extractDir?: string,
     *   diff?: array,
     *   manifest?: array,
     *   error?: string
     * }
     */
    public function prepare(string $version, string $zipUrl, ?string $manifestAssetUrl, string $token = ''): array
    {
        @set_time_limit(120);

        $safe       = preg_replace('/[^a-zA-Z0-9._-]/', '', $version);
        $tmpDir     = WRITEPATH . 'tmp/update-' . $safe . '-' . time() . '/';
        $extractDir = $tmpDir . 'extracted/';

        try {
            @mkdir($tmpDir, 0755, true);
            @mkdir($extractDir, 0755, true);

            // Download the release zip
            $zipData = $this->httpGet($zipUrl, $token);
            if ($zipData === false || $zipData === '') {
                return ['success' => false, 'error' => 'Could not download the release archive.'];
            }

            $zipFile = $tmpDir . 'release.zip';
            file_put_contents($zipFile, $zipData);
            unset($zipData); // free memory early

            // Extract (strips GitHub's root prefix)
            if (! $this->extractZip($zipFile, $extractDir)) {
                return ['success' => false, 'error' => 'Could not extract the ZIP archive.'];
            }
            unlink($zipFile);

            // Find manifest.json — bundled in zip takes priority.
            // The raw bytes are kept as-is: a signature covers exactly what was
            // published, so re-encoding the decoded array would break it.
            $newManifest   = null;
            $manifestBytes = '';
            $signature     = null;

            $manifestPath = $extractDir . 'manifest.json';
            if (file_exists($manifestPath)) {
                $manifestBytes = (string) file_get_contents($manifestPath);
                $newManifest   = json_decode($manifestBytes, true);

                if (file_exists($manifestPath . '.sig')) {
                    $signature = (string) file_get_contents($manifestPath . '.sig');
                }
            }

            // Fallback: download the manifest release asset
            if ((! $newManifest || empty($newManifest['files'])) && $manifestAssetUrl) {
                $raw = $this->httpGet($manifestAssetUrl, $token);
                if ($raw !== false) {
                    $manifestBytes = $raw;
                    $newManifest   = json_decode($raw, true);

                    $rawSignature = $this->httpGet($manifestAssetUrl . '.sig', $token);
                    $signature    = $rawSignature === false ? null : $rawSignature;
                }
            }

            if (! $newManifest || empty($newManifest['files'])) {
                $this->cleanup($tmpDir);
                return [
                    'success' => false,
                    'error'   => "Manifest not found in the release.\n"
                        . "Generate it with « php spark update:manifest » and attach manifest.json to your GitHub release.",
                ];
            }

            // Checked before anything else is inspected: an unverified release
            // shouldn't get as far as being diffed against the live install.
            $signatureError = $this->checkSignature($manifestBytes, $signature);
            if ($signatureError !== null) {
                $this->cleanup($tmpDir);

                return ['success' => false, 'error' => $signatureError];
            }

            // The scope comes from the release, so it is validated before it is
            // used to decide what gets scanned — and therefore what a missing
            // manifest entry means.
            $scopeError = $this->checkScope($newManifest);
            if ($scopeError !== null) {
                $this->cleanup($tmpDir);

                return ['success' => false, 'error' => $scopeError];
            }

            $roots = $this->releaseRoots($newManifest);
            $diff  = $this->computeDiff($this->buildCurrentManifest($roots), $newManifest['files'], $roots);

            // A manifest listing paths outside the release's own roots is not
            // something to work around — refuse the release entirely rather
            // than apply the part of it that happens to look sane.
            if ($diff['rejected'] !== []) {
                $this->cleanup($tmpDir);

                return [
                    'success' => false,
                    'error'   => 'The release manifest contains unsafe file paths and was rejected: '
                        . implode(', ', array_slice($diff['rejected'], 0, 5))
                        . (count($diff['rejected']) > 5 ? ' (…)' : ''),
                ];
            }

            return [
                'success'    => true,
                'version'    => $newManifest['version'] ?? $version,
                'tmpDir'     => $tmpDir,
                'extractDir' => $extractDir,
                'diff'       => $diff,
                'manifest'   => $newManifest['files'],
                'roots'      => $roots,
                'signed'     => $this->publicKeys !== [] && $signature !== null,
            ];
        } catch (\Throwable $e) {
            $this->cleanup($tmpDir);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * The top-level directories a release covers.
     *
     * Taken from the manifest, so the same list describes what is scanned on
     * disk and what the release contains. That symmetry is the point: the
     * deletion side of a diff is "present locally, absent from the manifest",
     * which is only meaningful if both sides speak about the same directories.
     *
     * A manifest without `roots` predates 2.6 and is read with the local
     * SCAN_DIRS, exactly as before.
     *
     * @return list<string>
     */
    public function releaseRoots(array $manifest): array
    {
        $declared = $manifest['roots'] ?? null;

        if (! is_array($declared)) {
            return $this->scanDirs;
        }

        $declared = ReleaseScope::normalize($declared);

        return $declared === [] ? $this->scanDirs : $declared;
    }

    /**
     * Validates a release's declared scope, before any of it is acted on.
     *
     * @return string|null An error message, or null when the scope is usable
     */
    public function checkScope(array $manifest): ?string
    {
        if (! isset($manifest['roots'])) {
            return null; // Pre-2.6 manifest: the local SCAN_DIRS apply.
        }

        if (! is_array($manifest['roots'])) {
            return 'The release manifest declares a malformed "roots" entry.';
        }

        $roots = ReleaseScope::normalize($manifest['roots']);

        if ($roots === []) {
            return 'The release manifest declares an empty "roots" entry.';
        }

        // These names arrive from the update server and become directories
        // written under ROOTPATH, so they are checked before anything else.
        $invalid = ReleaseScope::invalidRoots($roots);
        if ($invalid !== []) {
            return 'The release manifest declares unusable directories: ' . implode(', ', $invalid);
        }

        $refused = array_values(array_diff($roots, $this->allowedRoots));
        if ($refused !== []) {
            return 'This release covers ' . implode(', ', $refused)
                . ', which this installation does not accept. Add it to Config\Updater::$allowedRoots'
                . ' if the release is meant to manage that directory, or decline the update.';
        }

        // A declared root with nothing under it means a truncated or misbuilt
        // manifest. Left alone it would read as "every file here was deleted".
        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        foreach ($roots as $root) {
            $found = false;

            foreach (array_keys($files) as $path) {
                if (str_starts_with((string) $path, $root . '/')) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                return "The release manifest declares \"{$root}\" but lists no file under it."
                    . ' Refusing it rather than treating the whole directory as deleted.';
            }
        }

        return null;
    }

    /**
     * Whether a manifest key is a safe relative path to write under ROOTPATH.
     *
     * Manifest keys come from the update server, and apply() turns them into
     * destination paths, so they are validated before being trusted: no
     * traversal, no absolute paths, no drive letters, no NUL bytes, and the
     * first segment must be one of the release's roots.
     *
     * @param list<string>|null $roots Defaults to the configured SCAN_DIRS
     */
    public function isSafeManifestPath(string $path, ?array $roots = null): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }

        // Absolute (POSIX or Windows drive letter / UNC).
        if (str_starts_with($path, '/') || preg_match('#\A[A-Za-z]:#', $path) === 1) {
            return false;
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return in_array($segments[0], $roots ?? $this->scanDirs, true) && count($segments) > 1;
    }

    /**
     * Computes a diff between two manifest file maps.
     *
     * Entries whose path isn't a safe relative path under one of the release's
     * roots are collected in `rejected`, and the caller refuses the release.
     *
     * @param list<string>|null $roots Defaults to the configured SCAN_DIRS
     */
    public function computeDiff(array $current, array $new, ?array $roots = null): array
    {
        $diff = ['added' => [], 'modified' => [], 'deleted' => [], 'unchanged' => 0, 'rejected' => []];

        foreach ($new as $file => $newHash) {
            if (! $this->isSafeManifestPath((string) $file, $roots)) {
                $diff['rejected'][] = (string) $file;
                continue;
            }

            if (! isset($current[$file])) {
                $diff['added'][] = $file;
            } elseif ($current[$file] !== $newHash) {
                $diff['modified'][] = $file;
            } else {
                $diff['unchanged']++;
            }
        }
        foreach ($current as $file => $hash) {
            if (! isset($new[$file])) {
                $diff['deleted'][] = $file;
            }
        }

        return $diff;
    }

    /**
     * Backs up modified/deleted files, then copies added/modified files from
     * extractDir into the live application root, and removes deleted ones.
     *
     * @param array             $newManifest Optional manifest with expected SHA-256 hashes for validation
     * @param string|null       $version     Version being applied, recorded in the backup manifest
     * @param list<string>|null $roots       The release's scope; defaults to the configured SCAN_DIRS
     *
     * @return array{success: bool, backup_dir?: string, updated?: int, added?: int, modified?: int, deleted?: int, error?: string}
     */
    public function apply(string $extractDir, array $diff, array $newManifest = [], ?string $version = null, ?array $roots = null): array
    {
        $roots = $roots === null ? $this->scanDirs : ReleaseScope::normalize($roots);

        // prepare() validated all of this, but apply() is public and its
        // arguments travel through the session between requests — so the scope
        // and every path are re-checked before anything is written or deleted.
        $invalid = ReleaseScope::invalidRoots($roots);
        if ($invalid !== []) {
            return [
                'success' => false,
                'error'   => 'Refusing to apply an unusable scope: ' . implode(', ', $invalid),
            ];
        }

        $refused = array_values(array_diff($roots, $this->allowedRoots));
        if ($refused !== []) {
            return [
                'success' => false,
                'error'   => 'Refusing to apply a release covering ' . implode(', ', $refused)
                    . ', which this installation does not accept.',
            ];
        }

        foreach (['added', 'modified', 'deleted'] as $group) {
            foreach ($diff[$group] ?? [] as $file) {
                if (! $this->isSafeManifestPath((string) $file, $roots)) {
                    return [
                        'success' => false,
                        'error'   => "Refusing to apply an unsafe path: {$file}",
                    ];
                }
            }
        }

        $base      = ROOTPATH;
        $backupDir = WRITEPATH . 'backups/backup-' . date('Y-m-d-His') . '/';
        @mkdir($backupDir, 0755, true);

        // Record what this update did. Without it a rollback can only put back
        // the files it saved — it would have no way of knowing which files the
        // update *added*, and would leave them behind.
        //
        // The scope is recorded too: a restore has to work in the same
        // perimeter as the update it undoes, which is not necessarily the one
        // configured today.
        file_put_contents($backupDir . self::BACKUP_MANIFEST, json_encode([
            'created_at'   => gmdate('Y-m-d\TH:i:s\Z'),
            'from_version' => Updater::VERSION,
            'to_version'   => $version,
            'roots'        => $roots,
            'diff'         => [
                'added'    => array_values($diff['added']),
                'modified' => array_values($diff['modified']),
                'deleted'  => array_values($diff['deleted']),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        // Backup each file that will be overwritten or deleted
        foreach (array_merge($diff['modified'], $diff['deleted']) as $file) {
            $src = $base . $file;
            if (file_exists($src)) {
                $dst = $backupDir . $file;
                @mkdir(dirname($dst), 0755, true);
                copy($src, $dst);
            }
        }

        // Copy added + modified files
        $toUpdate = array_merge($diff['added'], $diff['modified']);
        $updated  = 0;

        foreach ($toUpdate as $file) {
            $src = $extractDir . $file;
            if (! file_exists($src)) {
                return [
                    'success'    => false,
                    'error'      => "File not found in archive: {$file}",
                    'backup_dir' => $backupDir,
                ];
            }

            // Validate hash before copying if manifest provided
            if (! empty($newManifest[$file])) {
                $actualHash = hash_file('sha256', $src);
                if ($actualHash !== $newManifest[$file]) {
                    return [
                        'success'    => false,
                        'error'      => "Invalid hash for {$file} (expected: {$newManifest[$file]}, got: {$actualHash}). Archive corrupted or incomplete.",
                        'backup_dir' => $backupDir,
                    ];
                }
            }

            $dst = $base . $file;
            @mkdir(dirname($dst), 0755, true);
            if (! copy($src, $dst)) {
                return [
                    'success'    => false,
                    'error'      => "Could not write: {$file}",
                    'backup_dir' => $backupDir,
                ];
            }
            $updated++;
        }

        // Delete files removed from the release
        foreach ($diff['deleted'] as $file) {
            $dst = $base . $file;
            if (file_exists($dst) && ! @unlink($dst)) {
                return [
                    'success'    => false,
                    'error'      => "Could not delete: {$file}",
                    'backup_dir' => $backupDir,
                ];
            }
        }

        // Clear opcache to ensure new code is loaded
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return [
            'success'    => true,
            'backup_dir' => $backupDir,
            'updated'    => $updated,
            'added'      => count($diff['added']),
            'modified'   => count($diff['modified']),
            'deleted'    => count($diff['deleted']),
        ];
    }

    /**
     * A backup is addressed by name, never by path: the name comes from a form
     * post, and this pattern is what keeps it from pointing anywhere else.
     */
    public function isValidBackupName(string $name): bool
    {
        return preg_match('/\Abackup-\d{4}-\d{2}-\d{2}-\d{6}\z/', $name) === 1;
    }

    /**
     * Lists the available backups, newest first.
     *
     * @return list<array{name: string, created_at: string|null, from_version: string|null, to_version: string|null, files: int, size: int, restorable: bool}>
     */
    public function listBackups(): array
    {
        $dir = WRITEPATH . 'backups/';
        if (! is_dir($dir)) {
            return [];
        }

        $backups = [];

        foreach ((array) scandir($dir) as $entry) {
            if (! is_string($entry) || ! $this->isValidBackupName($entry) || ! is_dir($dir . $entry)) {
                continue;
            }

            $meta  = $this->readBackupManifest($dir . $entry . '/');
            $files = 0;
            $size  = 0;

            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir . $entry, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if ($file->isFile() && $file->getFilename() !== self::BACKUP_MANIFEST) {
                    $files++;
                    $size += $file->getSize();
                }
            }

            $backups[] = [
                'name'         => $entry,
                'created_at'   => $meta['created_at'] ?? null,
                'from_version' => $meta['from_version'] ?? null,
                'to_version'   => $meta['to_version'] ?? null,
                'files'        => $files,
                'size'         => $size,
                // Restoring reverts files, never the database. Knowing whether
                // the update shipped migrations turns a vague caveat into a
                // specific warning at the point of decision.
                'migrations'   => $this->countMigrations($meta),
                // Backups taken before this metadata existed can still be
                // restored, they just can't undo added files.
                'restorable'   => true,
                'has_manifest' => $meta !== [],
            ];
        }

        usort($backups, static fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $backups;
    }

    /**
     * Restores a backup: puts back every file it saved and, when the backup
     * records what the update added, removes those files too — otherwise a
     * rollback would leave the new files sitting in the install.
     *
     * @return array{success: bool, restored?: int, removed?: int, error?: string}
     */
    public function restoreBackup(string $name): array
    {
        if (! $this->isValidBackupName($name)) {
            return ['success' => false, 'error' => 'Invalid backup name.'];
        }

        $backupDir = WRITEPATH . 'backups/' . $name . '/';
        if (! is_dir($backupDir)) {
            return ['success' => false, 'error' => "Backup not found: {$name}"];
        }

        $base     = ROOTPATH;
        $restored = 0;
        $removed  = 0;

        // The perimeter this update ran in, which is not necessarily the one
        // configured now: a release may have covered directories the current
        // SCAN_DIRS no longer mention. Backups written before 2.6 carry no
        // roots and fall back to it.
        $meta  = $this->readBackupManifest($backupDir);
        $roots = $this->releaseRoots($meta);

        $invalid = ReleaseScope::invalidRoots($roots);
        if ($invalid !== []) {
            return ['success' => false, 'error' => 'This backup records an unusable scope: ' . implode(', ', $invalid)];
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backupDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iter as $file) {
            if (! $file->isFile() || $file->getFilename() === self::BACKUP_MANIFEST) {
                continue;
            }

            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($backupDir)));

            // The backup was written from manifest paths, so the same rule
            // applies on the way back.
            if (! $this->isSafeManifestPath($rel, $roots)) {
                return ['success' => false, 'error' => "Refusing to restore an unsafe path: {$rel}"];
            }

            $dst = $base . $rel;
            @mkdir(dirname($dst), 0755, true);
            if (! copy($file->getPathname(), $dst)) {
                return ['success' => false, 'error' => "Could not restore: {$rel}"];
            }
            $restored++;
        }

        // Undo the files the update introduced. A path that no longer passes
        // validation is fatal rather than skipped: silently leaving a file
        // behind is the exact half-restore backup.json exists to prevent.
        foreach ($meta['diff']['added'] ?? [] as $file) {
            if (! is_string($file) || ! $this->isSafeManifestPath($file, $roots)) {
                return [
                    'success' => false,
                    'error'   => 'This backup records a file outside its own scope, so restoring it '
                        . 'would leave the update partly in place: ' . (is_string($file) ? $file : gettype($file)),
                ];
            }

            $path = $base . $file;
            if (is_file($path) && @unlink($path)) {
                $removed++;
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return ['success' => true, 'restored' => $restored, 'removed' => $removed];
    }

    /**
     * Deletes a single backup.
     *
     * @return array{success: bool, freed?: int, error?: string}
     */
    public function deleteBackup(string $name): array
    {
        if (! $this->isValidBackupName($name)) {
            return ['success' => false, 'error' => 'Invalid backup name.'];
        }

        $dir = WRITEPATH . 'backups/' . $name;
        if (! is_dir($dir)) {
            return ['success' => false, 'error' => "Backup not found: {$name}"];
        }

        $freed = $this->directorySize($dir);
        $this->cleanup($dir . '/');

        if (is_dir($dir)) {
            return ['success' => false, 'error' => "Could not fully delete {$name}."];
        }

        return ['success' => true, 'freed' => $freed];
    }

    /**
     * Removes the oldest backups beyond the configured retention.
     *
     * Deliberately called after an update has been applied, not on page views:
     * pruning destroys data, so it happens at a moment the admin chose and
     * where a fresh backup has just been written.
     *
     * @param int|null $keep Overrides Config\Updater::$keepBackups; 0 keeps everything
     *
     * @return array{deleted: list<string>, freed: int}
     */
    public function pruneBackups(?int $keep = null): array
    {
        $keep = $keep ?? $this->keepBackups;

        if ($keep <= 0) {
            return ['deleted' => [], 'freed' => 0];
        }

        // listBackups() is newest-first, so everything past the limit is older
        // than what we keep — the backup from the update just applied is safe.
        $stale   = array_slice($this->listBackups(), $keep);
        $deleted = [];
        $freed   = 0;

        foreach ($stale as $backup) {
            $result = $this->deleteBackup($backup['name']);
            if ($result['success']) {
                $deleted[] = $backup['name'];
                $freed += $result['freed'];
            }
        }

        return ['deleted' => $deleted, 'freed' => $freed];
    }

    private function directorySize(string $dir): int
    {
        $size = 0;
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iter as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Restores files from a backup directory created by apply().
     *
     * @deprecated since 2.3.0 — use restoreBackup(), which also undoes added
     *             files and reports what it did. Kept for existing integrations.
     */
    public function rollback(string $backupDir): bool
    {
        $name = basename(rtrim($backupDir, '/\\'));

        return $this->restoreBackup($name)['success'];
    }

    /**
     * How many migration files the backed-up update brought in. Rolling back
     * restores code but never touches the database, so this is what tells an
     * admin whether the schema will be left ahead of the restored code.
     *
     * @param array<string, mixed> $meta
     */
    private function countMigrations(array $meta): int
    {
        $files = array_merge(
            $meta['diff']['added'] ?? [],
            $meta['diff']['modified'] ?? []
        );

        return count(array_filter(
            $files,
            static fn ($file) => is_string($file) && preg_match('#/Database/Migrations/[^/]+\.php\z#i', $file) === 1
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function readBackupManifest(string $backupDir): array
    {
        $path = $backupDir . self::BACKUP_MANIFEST;
        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Recursively deletes a temporary directory.
     */
    public function cleanup(string $tmpDir): void
    {
        if (! is_dir($tmpDir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($tmpDir);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Extracts a zip file, stripping GitHub's automatic root prefix
     * (e.g. "owner-repo-sha1234/").
     */
    private function extractZip(string $zipFile, string $destDir): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return false;
        }

        $prefix = $this->detectZipRootPrefix($zip);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false) {
                continue;
            }
            $entry = str_replace('\\', '/', $entry);
            $rel   = ($prefix !== '') ? substr($entry, strlen($prefix)) : $entry;
            if ($rel === '' || $rel === false) {
                continue;
            }

            if (strpos($rel, '..') !== false || str_starts_with($rel, '/')) {
                continue;
            }

            $target = $destDir . $rel;
            if (str_ends_with($entry, '/')) {
                @mkdir($target, 0755, true);
            } else {
                @mkdir(dirname($target), 0755, true);
                $content = $zip->getFromIndex($i);
                if ($content === false || file_put_contents($target, $content) === false) {
                    $zip->close();
                    return false;
                }
            }
        }

        $zip->close();
        return true;
    }

    private function detectZipRootPrefix(\ZipArchive $zip): string
    {
        $commonPrefix = null;
        $count        = $zip->numFiles;

        for ($i = 0; $i < $count; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false || $entry === '') {
                continue;
            }

            $entry = str_replace('\\', '/', $entry);
            if ($entry === '' || $entry === '.' || $entry === './') {
                continue;
            }

            if (strpos($entry, '/') === false) {
                return '';
            }

            $firstSegment = substr($entry, 0, strpos($entry, '/') + 1);
            if ($commonPrefix === null) {
                $commonPrefix = $firstSegment;
            } elseif ($commonPrefix !== $firstSegment) {
                return '';
            }
        }

        if ($commonPrefix === null) {
            return '';
        }

        $trimmed = rtrim($commonPrefix, '/');
        if (in_array($trimmed, $this->scanDirs, true)) {
            return '';
        }

        return $commonPrefix;
    }

    private function httpGet(string $url, string $token = ''): string|false
    {
        $headers = "User-Agent: {$this->userAgent}\r\nAccept: */*\r\n";
        if ($token !== '') {
            $headers .= "Authorization: Bearer {$token}\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method'          => 'GET',
            'header'          => $headers,
            'timeout'         => 30,
            'follow_location' => 1,
        ]]);
        return @file_get_contents($url, false, $ctx);
    }
}
