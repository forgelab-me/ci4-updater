<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Libraries;

/**
 * What a release needs from the machine that installs it.
 *
 * A manifest may declare:
 *
 *   "requires": { "php": "^8.2", "extensions": ["intl", "zip"] }
 *
 * A manifest without it is installed as before.
 */
final class ReleaseRequirements
{
    /**
     * @param array<string, mixed> $manifest
     *
     * @return string|null An error message, or null when this machine qualifies
     */
    public static function check(array $manifest, ?string $phpVersion = null): ?string
    {
        $requires = $manifest['requires'] ?? null;

        if ($requires === null) {
            return null;
        }

        if (! is_array($requires)) {
            return 'The release manifest declares a malformed "requires" entry.';
        }

        $php = $requires['php'] ?? null;

        if (is_string($php) && trim($php) !== '') {
            $running = $phpVersion ?? PHP_VERSION;
            $ok      = self::satisfiesPhp($php, $running);

            if ($ok === null) {
                return "The release requires PHP \"{$php}\", which this installer cannot interpret."
                    . ' Use a form like ">=8.2", "^8.2" or ">=8.2 <9.0".';
            }

            if (! $ok) {
                return "This release requires PHP {$php}, and this server runs " . self::shortVersion($running) . '.'
                    . ' Applying it would leave the application unable to boot.';
            }
        }

        $missing = self::missingExtensions($requires['extensions'] ?? null);

        if ($missing !== []) {
            return 'This release requires PHP extension' . (count($missing) > 1 ? 's' : '') . ' '
                . implode(', ', $missing) . ', which this server does not have.';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function missingExtensions(mixed $declared): array
    {
        if (! is_array($declared)) {
            return [];
        }

        $missing = [];

        foreach ($declared as $extension) {
            if (! is_string($extension) || trim($extension) === '') {
                continue;
            }

            $name = strtolower(preg_replace('/\Aext-/i', '', trim($extension)));

            if (! extension_loaded($name)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * Whether $version satisfies $constraint, or null when the constraint is
     * not one of the supported forms.
     *
     * Supported: a space or comma separated list of clauses, all of which must
     * hold — `^8.2`, `~8.2.0`, `>=8.2`, `>8.2`, `<=8.4`, `<9.0`, `!=8.3`,
     * `=8.2` and a bare `8.2`.
     */
    public static function satisfiesPhp(string $constraint, string $version): ?bool
    {
        $version = self::shortVersion($version);
        $clauses = preg_split('/[\s,]+/', trim($constraint), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($clauses === []) {
            return null;
        }

        foreach ($clauses as $clause) {
            $result = self::satisfiesClause($clause, $version);

            if ($result === null) {
                return null;
            }

            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $requires
     */
    public static function describe(array $requires): string
    {
        $parts = [];

        if (is_string($requires['php'] ?? null) && trim($requires['php']) !== '') {
            $parts[] = 'PHP ' . trim($requires['php']);
        }

        $extensions = array_filter((array) ($requires['extensions'] ?? []), 'is_string');

        if ($extensions !== []) {
            $parts[] = implode(', ', $extensions);
        }

        return implode(' · ', $parts);
    }

    private static function satisfiesClause(string $clause, string $version): ?bool
    {
        if (preg_match('/\A(\^|~|>=|<=|!=|<>|>|<|=)?\s*v?(\d+(?:\.\d+){0,2})\z/', $clause, $m) !== 1) {
            return null;
        }

        $operator = $m[1] ?? '';
        $raw      = $m[2];
        $bound    = self::pad($raw);
        $version  = self::pad($version);

        return match ($operator) {
            '^'          => version_compare($version, $bound, '>=') && version_compare($version, self::nextMajor($bound), '<'),
            '~'          => version_compare($version, $bound, '>=') && version_compare($version, self::nextSignificant($raw), '<'),
            '>=' , ''    => version_compare($version, $bound, '>='),
            '>'          => version_compare($version, $bound, '>'),
            '<='         => version_compare($version, $bound, '<='),
            '<'          => version_compare($version, $bound, '<'),
            '!=', '<>'   => version_compare($version, $bound, '!='),
            '='          => version_compare($version, $bound, '=='),
            default      => null,
        };
    }

    /** Three components either side, so ">8.2" does not accept 8.2.0. */
    private static function pad(string $version): string
    {
        $parts = explode('.', $version);

        return implode('.', array_pad($parts, 3, '0'));
    }

    private static function nextMajor(string $version): string
    {
        return ((int) explode('.', $version)[0] + 1) . '.0.0';
    }

    /** ~8.2 allows 8.x, ~8.2.1 allows 8.2.x — the last component given moves. */
    private static function nextSignificant(string $version): string
    {
        $parts = explode('.', $version);

        return count($parts) >= 3
            ? $parts[0] . '.' . ((int) $parts[1] + 1) . '.0'
            : self::nextMajor($version);
    }

    /**
     * PHP_VERSION carries suffixes such as "8.5.3-dev" that version_compare
     * reads as older than the release they belong to.
     */
    private static function shortVersion(string $version): string
    {
        return preg_match('/\A(\d+(?:\.\d+){0,2})/', $version, $m) === 1 ? $m[1] : $version;
    }
}
