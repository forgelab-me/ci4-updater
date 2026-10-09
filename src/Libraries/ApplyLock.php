<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Libraries;

/**
 * Held while an update or a restore writes files, so only one runs at a time.
 *
 * An OS-level lock on a file in writable/: it is released when the process
 * ends, however it ends, so a crashed update cannot leave the next one refused.
 */
final class ApplyLock
{
    public const FILENAME = 'updater-apply.lock';

    /** @var resource */
    private $handle;

    /** @param resource $handle */
    private function __construct($handle)
    {
        $this->handle = $handle;
    }

    /** The lock, or null when another update holds it or the file cannot be opened. */
    public static function acquire(): ?self
    {
        $handle = @fopen(WRITEPATH . self::FILENAME, 'c');

        if ($handle === false) {
            return null;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return new self($handle);
    }

    public function release(): void
    {
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
    }

    public static function busyMessage(): string
    {
        return 'Another update or restore is already running. Wait for it to finish, then try again.';
    }
}
