<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\UpgradeManager;
use PHPUnit\Framework\TestCase;

final class UpgradeManagerTest extends TestCase
{
    public function testComputeDiffDetectsAddedModifiedDeletedAndUnchanged(): void
    {
        $manager = new UpgradeManager();

        $current = [
            'app/a.php' => 'hash-a',
            'app/b.php' => 'hash-b',
            'app/c.php' => 'hash-c',
        ];

        $new = [
            'app/a.php' => 'hash-a',       // unchanged
            'app/b.php' => 'hash-b-2',     // modified
            'app/d.php' => 'hash-d',       // added
            // app/c.php is missing → deleted
        ];

        $diff = $manager->computeDiff($current, $new);

        self::assertSame(['app/d.php'], $diff['added']);
        self::assertSame(['app/b.php'], $diff['modified']);
        self::assertSame(['app/c.php'], $diff['deleted']);
        self::assertSame(1, $diff['unchanged']);
    }

    public function testComputeDiffWithEmptyCurrentManifestMarksEverythingAsAdded(): void
    {
        $manager = new UpgradeManager();

        $diff = $manager->computeDiff([], ['app/a.php' => 'hash-a', 'app/b.php' => 'hash-b']);

        self::assertSame(['app/a.php', 'app/b.php'], $diff['added']);
        self::assertSame([], $diff['modified']);
        self::assertSame([], $diff['deleted']);
        self::assertSame(0, $diff['unchanged']);
    }
}
