<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Libraries;

/**
 * Reads back what an update just wrote and compares it to the manifest.
 *
 * The hashes checked before writing say the archive was intact; these say the
 * files on disk are. Between the two sit a disk that filled up, a copy that
 * was interrupted, a permission that refused silently, and an opcache serving
 * something else.
 */
final class ReleaseVerification
{
    /**
     * @param array<string, string> $manifest path => expected SHA-256
     * @param list<string>          $written  paths the update wrote
     * @param list<string>          $deleted  paths the update removed
     *
     * @return array{checked: int, drift: list<array{path: string, problem: string}>}
     */
    public static function check(string $base, array $manifest, array $written, array $deleted = []): array
    {
        $drift   = [];
        $checked = 0;

        foreach (array_unique($written) as $path) {
            $checked++;
            $full = $base . $path;

            if (! is_file($full)) {
                $drift[] = ['path' => $path, 'problem' => 'missing'];
                continue;
            }

            $expected = $manifest[$path] ?? null;

            if (! is_string($expected) || $expected === '') {
                continue;
            }

            if (! hash_equals($expected, (string) hash_file('sha256', $full))) {
                $drift[] = ['path' => $path, 'problem' => 'contents differ'];
            }
        }

        foreach (array_unique($deleted) as $path) {
            $checked++;

            if (file_exists($base . $path)) {
                $drift[] = ['path' => $path, 'problem' => 'still present'];
            }
        }

        return ['checked' => $checked, 'drift' => $drift];
    }

    /**
     * @param list<array{path: string, problem: string}> $drift
     */
    public static function describe(array $drift, int $limit = 5): string
    {
        $shown = array_slice($drift, 0, $limit);
        $lines = array_map(static fn (array $d): string => $d['path'] . ' (' . $d['problem'] . ')', $shown);

        return implode(', ', $lines) . (count($drift) > $limit ? ' … and ' . (count($drift) - $limit) . ' more' : '');
    }
}
