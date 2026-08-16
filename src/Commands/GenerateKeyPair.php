<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Forgelabme\Ci4Updater\Libraries\ReleaseSignature;

/**
 * Usage: php spark updater:keygen [--out <dir>] [--pub-out <dir>] [--bits 4096]
 *
 * Creates the key pair used to sign releases. The two halves have opposite
 * deployment requirements, so they are written to different places:
 *
 *  - the private key signs releases and must never leave the machine that cuts
 *    them — not the update server, not git, not a release archive;
 *  - the public key only verifies, has nothing to hide, and has to reach every
 *    app that requires signatures.
 *
 * Writing both to writable/ — as this command used to — put the public key in
 * the one directory that is in no release (SCAN_DIRS covers app/ and public/)
 * and in no git checkout. Deploying then left the app with no key at all, and
 * every update was refused.
 */
class GenerateKeyPair extends BaseCommand
{
    protected $group       = 'Update';
    protected $name        = 'updater:keygen';
    protected $description = 'Generates the RSA key pair used to sign release manifests.';
    protected $usage       = 'updater:keygen [--out <directory>] [--pub-out <directory>] [--bits <size>]';

    protected $options = [
        '--out'     => 'Where to write the private key (default: writable/keys). Never deployed.',
        '--pub-out' => 'Where to write the public key (default: app/Config/keys, which ships with releases).',
        '--bits'    => 'RSA key size in bits (default: 4096).',
    ];

    public function run(array $params): void
    {
        if (! ReleaseSignature::isAvailable()) {
            CLI::error('The openssl extension is required to generate keys.');

            return;
        }

        $privateDir = rtrim((string) (CLI::getOption('out') ?: WRITEPATH . 'keys'), '/\\');
        $publicDir  = rtrim((string) (CLI::getOption('pub-out') ?: APPPATH . 'Config' . DIRECTORY_SEPARATOR . 'keys'), '/\\');
        $bits       = (int) (CLI::getOption('bits') ?: 4096);

        $privatePath = $privateDir . DIRECTORY_SEPARATOR . 'release-signing.key';
        $publicPath  = $publicDir . DIRECTORY_SEPARATOR . 'release-signing.pub';

        foreach ([$privatePath, $publicPath] as $path) {
            if (is_file($path)) {
                CLI::error("{$path} already exists. Move it aside first — overwriting it would invalidate every release signed with it.");

                return;
            }
        }

        // 0700 on the private directory, the default on the public one: it
        // ships with the app and has to stay readable by the web server.
        foreach ([[$privateDir, 0700], [$publicDir, 0755]] as [$dir, $mode]) {
            if (! is_dir($dir) && ! mkdir($dir, $mode, true) && ! is_dir($dir)) {
                CLI::error("Could not create {$dir}.");

                return;
            }
        }

        CLI::write("Generating a {$bits}-bit key pair…", 'yellow');

        try {
            $pair = ReleaseSignature::generateKeyPair($bits);
        } catch (\Throwable $e) {
            CLI::error($e->getMessage());

            return;
        }

        file_put_contents($privatePath, $pair['private']);
        @chmod($privatePath, 0600);
        file_put_contents($publicPath, $pair['public']);

        CLI::newLine();
        CLI::write('Private key: ' . $privatePath, 'green');
        CLI::write('             signs releases — keep it off the server and out of git', 'white');
        CLI::write('Public key : ' . $publicPath, 'green');
        CLI::write('             verifies them — must reach every app that requires signatures', 'white');
        CLI::newLine();

        CLI::write('Next steps:', 'yellow');
        CLI::write('  1. Move the private key somewhere safe and out of this project.', 'white');
        CLI::write('  2. In each app that should require signed updates, add the public key', 'white');
        CLI::write('     to app/Config/Updater.php:', 'white');
        CLI::newLine();
        CLI::write('     public array $publicKeys = [', 'cyan');
        CLI::write("         APPPATH . 'Config/keys/release-signing.pub',", 'cyan');
        CLI::write('     ];', 'cyan');
        CLI::newLine();
        CLI::write('     app/ is in SCAN_DIRS, so the key travels with every release.', 'white');
        CLI::write('     Or paste the PEM inline and have no file to deploy at all:', 'white');
        CLI::newLine();
        CLI::write($this->inlineSnippet($pair['public']), 'cyan');
        CLI::newLine();
        CLI::write('     Once set, unsigned releases are refused by that app.', 'white');
        CLI::write('  3. Sign each release with: php spark update:manifest --sign <private-key>', 'white');
    }

    /**
     * The public key as a PHP string literal, ready to paste into the config.
     *
     * Printing it costs nothing — a public key has no secret to keep — and it
     * removes the deployment question entirely for anyone who takes it.
     */
    private function inlineSnippet(string $pem): string
    {
        $escaped = str_replace(["\r\n", "\n"], '\n', trim($pem));

        return "     public array \$publicKeys = [\n         \"{$escaped}\\n\",\n     ];";
    }
}
