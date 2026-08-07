<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Updater;
use Forgelabme\Ci4Updater\Libraries\SettingsInterface;

/**
 * Usage: php spark updater:config [--url <url>] [--token <token>] [--clear-token]
 *
 * Reads and writes the two settings the client side needs. Without options it
 * prints what is currently configured, which is the quickest way to answer
 * "why does the panel say no update server is configured?".
 *
 * It writes through Config\Updater::$settingsClass, so it works the same
 * whether settings live in the default JSON file or in a project's own store.
 */
class Configure extends BaseCommand
{
    protected $group       = 'Update';
    protected $name        = 'updater:config';
    protected $description = 'Shows or sets the update server URL and token.';
    protected $usage       = 'updater:config [--url <url>] [--token <token>] [--clear-token]';

    protected $options = [
        '--url'          => 'Base URL that resolves {url}/latest.json.',
        '--token'        => 'Bearer token, when the feed is protected.',
        '--clear-token'  => 'Remove the stored token.',
    ];

    public function run(array $params): void
    {
        $settings = $this->settings();
        if ($settings === null) {
            return;
        }

        $url         = CLI::getOption('url');
        $token       = CLI::getOption('token');
        $clearToken  = CLI::getOption('clear-token') !== null;
        $wrote       = false;

        if (is_string($url) && $url !== '') {
            if (! $this->isUsableUrl($url)) {
                CLI::error('The URL must be absolute and http(s), e.g. https://updates.example.com/api/my-app');

                return;
            }

            // Stored without a trailing slash: the client appends
            // "/latest.json" itself, and a double slash breaks some feeds.
            $settings->setSetting(Updater::SETTING_SERVER_URL, rtrim(trim($url), '/'));
            $wrote = true;
        }

        if ($clearToken) {
            $settings->setSetting(Updater::SETTING_SERVER_TOKEN, '');
            $wrote = true;
        } elseif (is_string($token) && $token !== '') {
            $settings->setSetting(Updater::SETTING_SERVER_TOKEN, trim($token));
            $wrote = true;
        }

        $this->report($settings, $wrote);
    }

    private function report(SettingsInterface $settings, bool $wrote): void
    {
        $url   = (string) $settings->getSetting(Updater::SETTING_SERVER_URL, '');
        $token = (string) $settings->getSetting(Updater::SETTING_SERVER_TOKEN, '');

        CLI::write($wrote ? 'Saved.' : 'Current settings:', $wrote ? 'green' : 'yellow');
        CLI::write('  ' . Updater::SETTING_SERVER_URL . '   : ' . ($url !== '' ? $url : '(not set)'),
            $url !== '' ? 'white' : 'red');

        // Never echo the token: this output ends up in deploy logs.
        CLI::write('  ' . Updater::SETTING_SERVER_TOKEN . ' : '
            . ($token !== '' ? 'set (' . strlen($token) . ' chars)' : '(none — the feed must be public)'), 'white');

        if ($url === '') {
            CLI::newLine();
            CLI::write('Set one with:', 'yellow');
            CLI::write('  php spark updater:config --url https://updates.example.com/api/my-app', 'white');
        }
    }

    private function isUsableUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function settings(): ?SettingsInterface
    {
        /** @var Updater $config */
        $config = config('Updater');
        $class  = $config->settingsClass;

        if (! class_exists($class)) {
            CLI::error("Config\\Updater::\$settingsClass points at {$class}, which does not exist.");

            return null;
        }

        $store = new $class();

        if (! $store instanceof SettingsInterface) {
            CLI::error("{$class} does not implement SettingsInterface.");

            return null;
        }

        return $store;
    }
}
