<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Libraries;

/**
 * Migrations a release's `vendor/` brings with it.
 *
 * They are read from the signed manifest, not from the extracted tree: the
 * manifest lists every file the release writes, so nothing is scanned.
 */
final class PackageMigrations
{
    /** Where a package keeps migrations for its own tests or samples, which no application runs. */
    private const NOT_RUN = '#/(?:tests?|_support|fixtures?|examples?|docs?)/#i';

    private const PATTERN = '#^vendor/([^/]+/[^/]+)/(?:[^/]+/)*Database/Migrations/(\d{4}[-_]?\d{2}[-_]?\d{2}[-_]?\d{6})_[^/]+\.php$#';

    /**
     * Migration files in the release that the migrations table does not record.
     *
     * @param array<string, string> $manifest path => sha256
     * @param list<string>|null     $applied  versions already run; null when unknown
     *
     * @return list<array{package: string, version: string, path: string}>
     */
    public static function incoming(array $manifest, ?array $applied): array
    {
        if ($applied === null) {
            return [];
        }

        $known = array_flip(array_map(self::normalise(...), $applied));
        $found = [];

        foreach (array_keys($manifest) as $path) {
            if (preg_match(self::PATTERN, (string) $path, $m) !== 1) {
                continue;
            }

            if (preg_match(self::NOT_RUN, '/' . $path) === 1 || isset($known[self::normalise($m[2])])) {
                continue;
            }

            $found[] = ['package' => $m[1], 'version' => $m[2], 'path' => (string) $path];
        }

        usort($found, static fn (array $a, array $b): int => [$a['package'], $a['version']] <=> [$b['package'], $b['version']]);

        return $found;
    }

    /**
     * @return list<string>|null the versions in the migrations table, or null
     *                           when it cannot be read
     */
    public static function applied(): ?array
    {
        if (! function_exists('db_connect')) {
            return null;
        }

        try {
            $db = db_connect();

            if (! $db->tableExists('migrations')) {
                return [];
            }

            return array_map(
                static fn (array $row): string => (string) $row['version'],
                $db->table('migrations')->select('version')->get()->getResultArray(),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<array{package: string, version: string, path: string}> $incoming
     *
     * @return array<string, int> package => number of migrations
     */
    public static function byPackage(array $incoming): array
    {
        $counts = [];

        foreach ($incoming as $migration) {
            $counts[$migration['package']] = ($counts[$migration['package']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param list<array{package: string, version: string, path: string}> $incoming
     */
    public static function describe(array $incoming): string
    {
        $parts = [];

        foreach (self::byPackage($incoming) as $package => $count) {
            $parts[] = $package . ' (' . $count . ')';
        }

        return implode(', ', $parts);
    }

    private static function normalise(string $version): string
    {
        return (string) preg_replace('/\D/', '', $version);
    }
}
