<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Libraries;

/**
 * Decides what may be downloaded, and what the update-server token is shown to.
 *
 * Fetchable: `https://` anywhere, `http://` only back to a server configured
 * that way. The token goes to the configured server's origin and nowhere else,
 * so a release hosted on GitHub or an object store is fetched without it.
 */
final class DownloadPolicy
{
    /** @var list<string> */
    public const SCHEMES = ['https', 'http'];

    /**
     * @return array{allowed: bool, sendToken: bool, error: string}
     */
    public static function forUrl(string $url, string $serverUrl): array
    {
        $url = trim($url);

        if ($url === '') {
            return self::refuse('No download URL was given.');
        }

        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        // Judged before the host, which `file:///etc/passwd` does not have.
        if ($scheme !== '' && ! in_array($scheme, self::SCHEMES, true)) {
            return self::refuse(
                "Refusing to download over \"{$scheme}\": a release can only come from an http(s) URL."
            );
        }

        if ($parts === false || $scheme === '' || ($parts['host'] ?? '') === '') {
            return self::refuse("Not a usable download URL: {$url}");
        }

        $sameOrigin = $serverUrl !== '' && self::origin($url) === self::origin($serverUrl);

        if ($scheme === 'http' && ! $sameOrigin) {
            return self::refuse(
                "Refusing to download over plain HTTP from {$parts['host']}: a release must come over https."
            );
        }

        return ['allowed' => true, 'sendToken' => $sameOrigin, 'error' => ''];
    }

    /**
     * scheme://host[:port], default port dropped. '' when unparseable, which
     * never compares equal to a real origin.
     */
    public static function origin(string $url): string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ($parts['scheme'] ?? '') === '' || ($parts['host'] ?? '') === '') {
            return '';
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host   = strtolower((string) $parts['host']);
        $port   = $parts['port'] ?? null;

        $default = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null);

        if ($port !== null && $port !== $default) {
            return "{$scheme}://{$host}:{$port}";
        }

        return "{$scheme}://{$host}";
    }

    /**
     * Where a Location header points, as an absolute URL, or '' when it points
     * somewhere a download must not follow.
     */
    public static function resolveRedirect(string $location, string $from): string
    {
        $location = trim($location);

        if ($location === '') {
            return '';
        }

        if (preg_match('#\A[A-Za-z][A-Za-z0-9+.-]*://#', $location) === 1) {
            $scheme = strtolower((string) (parse_url($location, PHP_URL_SCHEME) ?: ''));

            return in_array($scheme, self::SCHEMES, true) ? $location : '';
        }

        $origin = self::origin($from);

        // Only root-relative locations are resolved; anything else is refused.
        if ($origin === '' || ! str_starts_with($location, '/')) {
            return '';
        }

        return $origin . $location;
    }

    /**
     * What is wrong with the configured update server, for the panel to show,
     * or null when nothing is.
     */
    public static function serverWarning(string $serverUrl): ?string
    {
        $serverUrl = trim($serverUrl);

        if ($serverUrl === '') {
            return null;
        }

        $scheme = strtolower((string) (parse_url($serverUrl, PHP_URL_SCHEME) ?: ''));

        if ($scheme === 'https') {
            return null;
        }

        if ($scheme !== 'http') {
            return "The update server URL is not usable: {$serverUrl}";
        }

        return "The update server is configured over plain HTTP ({$serverUrl}). Everything it sends is written "
            . 'into this application, so anyone on the network between the two can replace it — and with '
            . 'Config\\Updater::$publicKeys unset, nothing here would notice. Use https, or sign your releases.';
    }

    /**
     * @return array{allowed: false, sendToken: false, error: string}
     */
    private static function refuse(string $error): array
    {
        return ['allowed' => false, 'sendToken' => false, 'error' => $error];
    }
}
