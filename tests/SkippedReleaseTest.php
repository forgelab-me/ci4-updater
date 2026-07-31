<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Controllers\UpdateController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The panel warns when the release it is about to install steps over one that
 * covered a directory this one doesn't — a jump that would leave that
 * directory behind with nothing to bring it up to date.
 *
 * The comparison itself belongs to the update server, which is the only side
 * that sees the releases in between. What is tested here is the package's end:
 * the lists arrive from that server and get rendered in the panel, so they are
 * treated as untrusted input.
 *
 * @internal
 */
final class SkippedReleaseTest extends TestCase
{
    public function testKeepsAnOrdinaryList(): void
    {
        self::assertSame(['vendor'], UpdateController::stringList(['vendor']));
        self::assertSame(['1.2.0', '1.3.0'], UpdateController::stringList(['1.2.0', '1.3.0']));
    }

    /**
     * A feed that doesn't compute these — a hand-written latest.json, or a
     * server older than this feature — simply omits them, and the panel has
     * nothing to warn about.
     */
    #[DataProvider('provideAbsentOrMalformed')]
    public function testYieldsAnEmptyListWhenThereIsNothingUsable(mixed $value): void
    {
        self::assertSame([], UpdateController::stringList($value));
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function provideAbsentOrMalformed(): iterable
    {
        yield 'absent'       => [null];
        yield 'empty array'  => [[]];
        yield 'a string'     => ['vendor'];
        yield 'a number'     => [42];
        yield 'a bool'       => [true];
        yield 'nested only'  => [[['vendor']]];
        yield 'objects only' => [[new \stdClass()]];
    }

    /**
     * These end up inside the warning, so anything that isn't a plain short
     * string is dropped rather than passed through.
     */
    public function testDropsEntriesThatArentUsableStrings(): void
    {
        $value = UpdateController::stringList([
            'vendor',
            '',
            42,
            null,
            ['app'],
            str_repeat('a', 65),
            'public',
        ]);

        self::assertSame(['vendor', 'public'], $value);
    }

    public function testReturnsAListWithoutHolesAfterFiltering(): void
    {
        // array_filter preserves keys; a gap would serialise as a JSON object
        // instead of an array, and the panel's Array.isArray() check would
        // silently drop the warning.
        $value = UpdateController::stringList([42, 'vendor', 42, 'public']);

        self::assertSame([0, 1], array_keys($value));
        self::assertSame('[["vendor","public"]]', json_encode([$value]));
    }
}
