<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// UpgradeManager reads Config\Updater in its constructor. The real class is
// published into the consuming app by `php spark updater:setup`; for unit
// tests we provide a minimal stand-in so the package can be tested in
// isolation, without a full CodeIgniter bootstrap.
if (! class_exists('Config\\Updater', false)) {
    require __DIR__ . '/_support/Config/Updater.php';
}

// UpgradeManager writes relative to CodeIgniter's ROOTPATH/WRITEPATH. Pointing
// them at a scratch directory lets the file-touching parts — applying an
// update, backing up, rolling back — be exercised for real instead of mocked,
// while keeping every write inside the system temp directory.
$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ci4-updater-tests-' . getmypid();

foreach (['', 'app', 'public', 'writable', 'writable/backups', 'writable/tmp'] as $dir) {
    $path = $sandbox . ($dir === '' ? '' : DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir));
    if (! is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

defined('ROOTPATH') || define('ROOTPATH', $sandbox . DIRECTORY_SEPARATOR);
defined('WRITEPATH') || define('WRITEPATH', $sandbox . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR);

// Leave nothing behind between runs.
register_shutdown_function(static function () use ($sandbox): void {
    if (! is_dir($sandbox)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($sandbox);
});
