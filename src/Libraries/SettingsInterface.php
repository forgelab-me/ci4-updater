<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Libraries;

/**
 * Contract for the key/value store used to persist update-server settings
 * (see Config\Updater::$settingsClass).
 *
 * The methods are deliberately named getSetting()/setSetting() rather than
 * get()/set(): CodeIgniter\Model already declares
 * set($key, $value = '', ?bool $escape = null) for the query builder, so
 * a plain get()/set() contract would make it impossible for a Model
 * subclass — the most natural place to keep app settings — to implement
 * this interface.
 */
interface SettingsInterface
{
    public function getSetting(string $key, mixed $default = null): mixed;

    public function setSetting(string $key, mixed $value): void;
}
