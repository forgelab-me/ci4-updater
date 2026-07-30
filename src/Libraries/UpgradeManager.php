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
    private array $scanDirs;
    private string $userAgent;

    /** @var list<string> PEM contents or paths; empty means signatures are not enforced. */
    private array $publicKeys;

    /**
     * @param list<string>|null $publicKeys Overrides Config\Updater::$publicKeys (mainly for tests)
     */
    public function __construct(?array $publicKeys = null)
    {
        $this->scanDirs   = Updater::SCAN_DIRS;
        $this->userAgent  = Updater::USER_AGENT;
        $this->publicKeys = $publicKeys ?? self::configuredPublicKeys();
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
    public function buildCurrentManifest(): array
    {
        $base  = ROOTPATH;
        $files = [];

        foreach ($this->scanDirs as $dir) {
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

            $diff = $this->computeDiff($this->buildCurrentManifest(), $newManifest['files']);

            // A manifest listing paths outside the scanned directories is not
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
                'signed'     => $this->publicKeys !== [] && $signature !== null,
            ];
        } catch (\Throwable $e) {
            $this->cleanup($tmpDir);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Whether a manifest key is a safe relative path to write under ROOTPATH.
     *
     * Manifest keys come from the update server, and apply() turns them into
     * destination paths, so they are validated before being trusted: no
     * traversal, no absolute paths, no drive letters, no NUL bytes, and the
     * first segment must be one of the configured SCAN_DIRS.
     */
    public function isSafeManifestPath(string $path): bool
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

        return in_array($segments[0], $this->scanDirs, true) && count($segments) > 1;
    }

    /**
     * Computes a diff between two manifest file maps.
     *
     * Entries whose path isn't a safe relative path under a scanned directory
     * are dropped rather than reported, so nothing downstream can act on them.
     */
    public function computeDiff(array $current, array $new): array
    {
        $diff = ['added' => [], 'modified' => [], 'deleted' => [], 'unchanged' => 0, 'rejected' => []];

        foreach ($new as $file => $newHash) {
            if (! $this->isSafeManifestPath((string) $file)) {
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
     * @param array $newManifest Optional manifest with expected SHA-256 hashes for validation
     *
     * @return array{success: bool, backup_dir?: string, updated?: int, added?: int, modified?: int, deleted?: int, error?: string}
     */
    public function apply(string $extractDir, array $diff, array $newManifest = []): array
    {
        // computeDiff() already filters these out, but apply() is public and the
        // diff travels through the session between requests — so every path is
        // re-checked here before anything is created, written or deleted.
        foreach (['added', 'modified', 'deleted'] as $group) {
            foreach ($diff[$group] ?? [] as $file) {
                if (! $this->isSafeManifestPath((string) $file)) {
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
     * Restores files from a backup directory created by apply().
     */
    public function rollback(string $backupDir): bool
    {
        if (! is_dir($backupDir)) {
            return false;
        }
        $base = ROOTPATH;
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backupDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iter as $f) {
            if (! $f->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($backupDir)));
            $dst = $base . $rel;
            @mkdir(dirname($dst), 0755, true);
            copy($f->getPathname(), $dst);
        }
        return true;
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
