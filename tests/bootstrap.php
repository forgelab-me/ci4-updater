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
