<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Libraries;

/**
 * Validation for the top-level directories a release covers.
 *
 * Until 2.6 the scope of an update came from the installing application's own
 * configuration, which made it trusted input needing no checks. A manifest now
 * declares its own roots, so the same values arrive from the update server —
 * validation that was unnecessary when the list was local is mandatory now.
 * A release declaring `..` as a root would otherwise write anywhere.
 */
final class ReleaseScope
{
    /**
     * Refused whatever the installation's policy allows.
     *
     * writable/ holds the backups: a release able to write there could destroy
     * the very copies a rollback depends on.
     */
    public const DENIED = ['writable'];

    private const MAX_LENGTH = 64;

    /**
     * A root is a single directory name directly under ROOTPATH — never a
     * path, never a traversal, never a dot-directory.
     */
    public static function isValidRootName(string $root): bool
    {
        if ($root === '' || strlen($root) > self::MAX_LENGTH) {
            return false;
        }

        if (str_contains($root, '..') || in_array($root, self::DENIED, true)) {
            return false;
        }

        // Must open with a letter, digit or underscore. That rules out '.',
        // '..' and dot-directories such as .git, while still allowing a name
        // to contain dots. Slashes and backslashes are excluded by the class.
        return preg_match('/\A[A-Za-z0-9_][A-Za-z0-9._-]*\z/', $root) === 1;
    }

    /**
     * The entries that cannot be used as a root, for reporting back.
     *
     * @param list<mixed> $roots
     *
     * @return list<string>
     */
    public static function invalidRoots(array $roots): array
    {
        $invalid = [];

        foreach ($roots as $root) {
            if (is_string($root)) {
                if (! self::isValidRootName($root)) {
                    $invalid[] = $root;
                }

                continue;
            }

            $invalid[] = '(' . gettype($root) . ')';
        }

        return $invalid;
    }

    /**
     * Drops non-strings, empties and duplicates without judging validity —
     * isValidRootName() is what decides whether a name is acceptable.
     *
     * @param list<mixed> $roots
     *
     * @return list<string>
     */
    public static function normalize(array $roots): array
    {
        $clean = [];

        foreach ($roots as $root) {
            if (is_string($root) && $root !== '' && ! in_array($root, $clean, true)) {
                $clean[] = $root;
            }
        }

        return $clean;
    }
}
