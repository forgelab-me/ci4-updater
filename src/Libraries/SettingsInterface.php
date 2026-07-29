<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Libraries;

/**
 * Contract for the key/value store used to persist update-server settings
 * (see Config\Updater::$settingsClass).
 */
interface SettingsInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;
}
