<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Libraries;

/**
 * The window during which an update is writing files.
 *
 * A flag file in writable/, so any process serving the application can see it
 * — including one that knows nothing about the request doing the update. The
 * filter that reads it is opt-in; opening the window costs nothing when
 * nobody does.
 *
 * A window expires on its own. An update killed halfway would otherwise leave
 * the site answering 503 until somebody deleted a file by hand.
 */
final class MaintenanceWindow
{
    public const FILENAME = 'updater-maintenance.json';

    public const DEFAULT_TTL = 600;

    public static function path(): string
    {
        return WRITEPATH . self::FILENAME;
    }

    public static function open(string $reason = '', ?int $ttl = null): bool
    {
        $ttl = $ttl ?? self::configuredTtl();

        return file_put_contents(self::path(), json_encode([
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'expires_at' => time() + max(1, $ttl),
            'reason'     => $reason,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false;
    }

    public static function close(): void
    {
        if (is_file(self::path())) {
            @unlink(self::path());
        }
    }

    public static function isOpen(): bool
    {
        $state = self::state();

        if ($state === null) {
            return false;
        }

        if (time() >= (int) ($state['expires_at'] ?? 0)) {
            self::close();

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function state(): ?array
    {
        if (! is_file(self::path())) {
            return null;
        }

        $data = json_decode((string) file_get_contents(self::path()), true);

        return is_array($data) ? $data : null;
    }

    /** Seconds until the window expires, or 60 when that cannot be read. */
    public static function retryAfter(): int
    {
        $state = self::state();
        $left  = $state === null ? 0 : (int) ($state['expires_at'] ?? 0) - time();

        return $left > 0 ? min($left, self::DEFAULT_TTL) : 60;
    }

    private static function configuredTtl(): int
    {
        if (function_exists('config')) {
            $config = config('Updater');

            if (is_object($config) && property_exists($config, 'maintenanceTtl') && is_int($config->maintenanceTtl)) {
                return $config->maintenanceTtl;
            }
        }

        return self::DEFAULT_TTL;
    }
}
