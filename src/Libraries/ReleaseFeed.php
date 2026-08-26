<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Libraries;

use Config\Updater;

/**
 * What the update server currently offers, in the shape the panel and the CLI
 * both consume.
 */
final class ReleaseFeed
{
    private UpgradeManager $manager;

    public function __construct(?UpgradeManager $manager = null)
    {
        $this->manager = $manager ?? new UpgradeManager();
    }

    /**
     * @param string $from The version asking, so the server can report what
     *                     going straight to the latest release would step over
     *
     * @return array{ok: bool, error?: string, release?: array<string, mixed>}
     */
    public function latest(string $serverUrl, string $token = '', string $from = ''): array
    {
        $serverUrl = rtrim(trim($serverUrl), '/');

        if ($serverUrl === '') {
            return ['ok' => false, 'error' => 'No update server configured.'];
        }

        $url  = $serverUrl . '/latest.json' . ($from !== '' ? '?from=' . rawurlencode($from) : '');
        $json = $this->manager->fetchFromServer($url, $token, $serverUrl);

        if ($json === false) {
            return ['ok' => false, 'error' => "Could not reach the update server ({$url})."];
        }

        $release = self::parse($json);

        if ($release === null) {
            return ['ok' => false, 'error' => 'Invalid response from the update server.'];
        }

        return ['ok' => true, 'release' => $release];
    }

    /**
     * @return array<string, mixed>|null null when the response carries no version
     */
    public static function parse(string $json): ?array
    {
        $data = json_decode($json, true);

        if (! is_array($data) || empty($data['version']) || ! is_string($data['version'])) {
            return null;
        }

        return [
            'version'      => $data['version'],
            'changelog'    => is_string($data['changelog'] ?? null) ? $data['changelog'] : '',
            'date'         => is_string($data['date'] ?? null) ? $data['date'] : '',
            'zip_url'      => is_string($data['zip_url'] ?? null) ? $data['zip_url'] : '',
            'manifest_url' => is_string($data['manifest_url'] ?? null) ? $data['manifest_url'] : '',
            // Absent from feeds that don't compute them; there is then simply
            // nothing to warn about.
            'missed_roots'     => self::stringList($data['missed_roots'] ?? null),
            'skipped_versions' => self::stringList($data['skipped_versions'] ?? null),
            // The server may hand back an intermediate release rather than the
            // newest one, when that release must not be jumped over.
            'required_step'  => ! empty($data['required_step']),
            'latest_version' => is_string($data['latest_version'] ?? null) ? $data['latest_version'] : '',
        ];
    }

    /**
     * Keeps only plain strings: these come from the update server and end up
     * rendered in the panel.
     *
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn ($item) => is_string($item) && $item !== '' && strlen($item) <= 64,
        ));
    }

    public static function isNewer(string $candidate, string $current): bool
    {
        return version_compare($candidate, $current, '>');
    }

    public static function currentVersion(): string
    {
        return (string) Updater::VERSION;
    }
}
