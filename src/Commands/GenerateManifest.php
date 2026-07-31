<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Updater;
use Forgelabme\Ci4Updater\Libraries\ReleaseScope;
use Forgelabme\Ci4Updater\Libraries\ReleaseSignature;

/**
 * Usage: php spark update:manifest
 *
 * Scans the configured directories (Config\Updater::SCAN_DIRS) and writes
 * manifest.json at the project root, plus a ready-to-upload release ZIP.
 * Run this before creating a GitHub release, then attach both files
 * (or just the ZIP, which embeds the manifest) as release assets.
 */
class GenerateManifest extends BaseCommand
{
    protected $group       = 'Update';
    protected $name        = 'update:manifest';
    protected $description = 'Generates manifest.json (SHA-256 hashes) and a release ZIP for the self-update system.';
    protected $usage       = 'update:manifest [--roots <a,b>] [--sign <private-key>] [--passphrase <passphrase>]';

    protected $options = [
        '--roots'      => 'Comma-separated top-level directories this release covers. Defaults to Config\Updater::SCAN_DIRS.',
        '--sign'       => 'Sign the manifest with this private key (PEM path). Required by apps that set Config\Updater::$publicKeys.',
        '--passphrase' => 'Passphrase protecting the private key, if any.',
    ];

    public function run(array $params): void
    {
        $base     = ROOTPATH;
        $scanDirs = $this->resolveRoots();
        $files    = [];

        if ($scanDirs === []) {
            return;
        }

        // Only directories that actually yield files end up declared. A root
        // named in the manifest with nothing under it is refused on the
        // installing side — rightly, since it can't be told apart from a
        // truncated manifest — so it must not be declared in the first place.
        $covered = [];

        foreach ($scanDirs as $dir) {
            $path = $base . $dir;
            if (! is_dir($path)) {
                CLI::write("Skipping missing directory: $dir", 'yellow');
                continue;
            }

            $found = 0;
            $iter  = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iter as $f) {
                if (! $f->isFile()) {
                    continue;
                }
                $rel         = str_replace('\\', '/', substr($f->getPathname(), strlen($base)));
                $files[$rel] = hash_file('sha256', $f->getPathname());
                $found++;
            }

            if ($found === 0) {
                CLI::write("Skipping empty directory: $dir", 'yellow');
                continue;
            }

            $covered[] = $dir;
        }

        if ($covered === []) {
            CLI::error('Nothing to release: none of the scanned directories contain any file.');

            return;
        }

        $scanDirs = $covered;

        ksort($files);

        // The scope travels with the release. Without it the installing app
        // would fall back to its own configuration to decide what to scan, and
        // a directory it scans but this release doesn't ship would read as
        // entirely deleted.
        $manifest = [
            'version'      => Updater::VERSION,
            'date'         => Updater::DATE,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'roots'        => $scanDirs,
            'files'        => $files,
        ];

        $manifestPath = $base . 'manifest.json';
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($manifestPath, $manifestJson);

        // The signature covers the bytes written above, so it has to be made
        // from those exact bytes — not from a re-encoded copy of $manifest.
        $signaturePath = null;
        if (($privateKey = (string) (CLI::getOption('sign') ?: '')) !== '') {
            try {
                $envelope = ReleaseSignature::sign(
                    $manifestJson,
                    $privateKey,
                    (string) (CLI::getOption('passphrase') ?: '')
                );
            } catch (\Throwable $e) {
                CLI::error('Signing failed: ' . $e->getMessage());
                CLI::error('manifest.json was written, but no release archive was created.');
                return;
            }

            $signaturePath = $manifestPath . '.sig';
            file_put_contents($signaturePath, $envelope);
        }

        $zipPath = $base . 'release_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $manifest['version']) . '_' . date('Ymd_His') . '.zip';
        $this->createReleaseZip($zipPath, $manifestPath, $scanDirs, $signaturePath);

        CLI::write('manifest.json generated successfully.', 'green');
        CLI::write('Version : ' . $manifest['version'], 'white');
        CLI::write('Covers  : ' . implode(', ', $scanDirs), 'white');
        CLI::write('Files   : ' . count($files), 'white');
        CLI::write('Manifest: ' . $manifestPath, 'white');
        CLI::write('Release : ' . $zipPath, 'white');

        if ($signaturePath !== null) {
            CLI::write('Signed  : ' . $signaturePath, 'green');
        } else {
            CLI::write('Signed  : no (pass --sign <private-key> to sign this release)', 'yellow');
        }

        CLI::newLine();
        CLI::write('Next steps:', 'yellow');
        CLI::write('  1. Create a GitHub release for v' . $manifest['version'], 'white');
        CLI::write('  2. Attach ' . basename($zipPath) . ' as a release asset (it embeds manifest.json'
            . ($signaturePath !== null ? ' and its signature)' : ')'), 'white');
    }

    /**
     * The directories this release covers: --roots when given, SCAN_DIRS
     * otherwise. Returns an empty list — and reports why — when the request
     * can't be honoured, so nothing half-scoped ever gets written.
     *
     * @return list<string>
     */
    private function resolveRoots(): array
    {
        $option = (string) (CLI::getOption('roots') ?: '');

        if ($option === '') {
            return ReleaseScope::normalize(Updater::SCAN_DIRS);
        }

        $roots   = ReleaseScope::normalize(array_map('trim', explode(',', $option)));
        $invalid = ReleaseScope::invalidRoots($roots);

        if ($roots === []) {
            CLI::error('--roots is empty.');

            return [];
        }

        if ($invalid !== []) {
            CLI::error('Not usable as top-level directories: ' . implode(', ', $invalid));

            return [];
        }

        foreach ($roots as $root) {
            if (! is_dir(ROOTPATH . $root)) {
                CLI::error("No such directory: {$root}");

                return [];
            }
        }

        return $roots;
    }

    private function createReleaseZip(string $zipPath, string $manifestPath, array $scanDirs, ?string $signaturePath = null): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            CLI::write('Unable to create ZIP archive: ' . $zipPath, 'red');
            return;
        }

        $zip->addFile($manifestPath, 'manifest.json');

        // Travelling inside the archive is enough: the signature's value comes
        // from the key that made it, not from where it is stored.
        if ($signaturePath !== null && is_file($signaturePath)) {
            $zip->addFile($signaturePath, 'manifest.json.sig');
        }

        foreach ($scanDirs as $dir) {
            $path = ROOTPATH . $dir;
            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $filePath  = $file->getPathname();
                $localName = str_replace('\\', '/', substr($filePath, strlen(ROOTPATH)));
                $zip->addFile($filePath, $localName);
            }
        }

        $zip->close();
    }
}
