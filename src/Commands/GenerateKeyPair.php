<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Forgelabme\Ci4Updater\Libraries\ReleaseSignature;

/**
 * Usage: php spark updater:keygen [--out <dir>] [--bits 4096]
 *
 * Creates the key pair used to sign releases. The private key must stay with
 * whoever cuts releases — putting it on the update server would defeat the
 * purpose, since the whole point is that compromising the server isn't enough
 * to publish code.
 */
class GenerateKeyPair extends BaseCommand
{
    protected $group       = 'Update';
    protected $name        = 'updater:keygen';
    protected $description = 'Generates the RSA key pair used to sign release manifests.';
    protected $usage       = 'updater:keygen [--out <directory>] [--bits <size>]';

    protected $options = [
        '--out'  => 'Directory to write the keys to (default: writable/keys).',
        '--bits' => 'RSA key size in bits (default: 4096).',
    ];

    public function run(array $params): void
    {
        if (! ReleaseSignature::isAvailable()) {
            CLI::error('The openssl extension is required to generate keys.');
            return;
        }

        $dir  = rtrim((string) (CLI::getOption('out') ?: WRITEPATH . 'keys'), '/\\');
        $bits = (int) (CLI::getOption('bits') ?: 4096);

        $privatePath = $dir . DIRECTORY_SEPARATOR . 'release-signing.key';
        $publicPath  = $dir . DIRECTORY_SEPARATOR . 'release-signing.pub';

        foreach ([$privatePath, $publicPath] as $path) {
            if (is_file($path)) {
                CLI::error("{$path} already exists. Move it aside first — overwriting it would invalidate every release signed with it.");
                return;
            }
        }

        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            CLI::error("Could not create {$dir}.");
            return;
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
        CLI::write('Public key : ' . $publicPath, 'green');
        CLI::newLine();

        CLI::write('Next steps:', 'yellow');
        CLI::write('  1. Move the private key somewhere safe and out of this project.', 'white');
        CLI::write('     It signs releases; it never belongs on the update server or in git.', 'white');
        CLI::write('  2. In each app that should require signed updates, add the public key', 'white');
        CLI::write('     to app/Config/Updater.php:', 'white');
        CLI::newLine();
        CLI::write("     public array \$publicKeys = [", 'cyan');
        CLI::write("         WRITEPATH . 'keys/release-signing.pub',", 'cyan');
        CLI::write('     ];', 'cyan');
        CLI::newLine();
        CLI::write('     Once set, unsigned releases are refused by that app.', 'white');
        CLI::write('  3. Sign each release with: php spark update:manifest --sign <private-key>', 'white');
    }
}
