<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Libraries;

/**
 * Minimal key/value settings store for the update system, backed by a JSON
 * file in writable/ — zero DB dependency, so the package works in any CI4
 * project out of the box.
 *
 * If your project already has something like an `AppSettingModel` /
 * `app_settings` table, write your own class implementing
 * SettingsInterface and point `Config\Updater::$settingsClass` at it —
 * see the "Custom settings storage" section of the README.
 */
class UpdaterSettings implements SettingsInterface
{
    /** Named so `updater:setup` and the panel can point at it by name. */
    public const FILENAME = 'updater_settings.json';

    private string $path;

    public function __construct()
    {
        $this->path = WRITEPATH . self::FILENAME;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        $data = $this->readAll();
        return $data[$key] ?? $default;
    }

    public function setSetting(string $key, mixed $value): void
    {
        $data       = $this->readAll();
        $data[$key] = $value;
        file_put_contents($this->path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function readAll(): array
    {
        if (! is_file($this->path)) {
            return [];
        }
        $raw = file_get_contents($this->path);
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : [];
    }
}
