<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\ReleaseFeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the update server answered, normalised for the panel and the CLI.
 */
final class ReleaseFeedTest extends TestCase
{
    public function testAMinimalFeedIsReadWithEveryFieldPresent(): void
    {
        $release = ReleaseFeed::parse('{"version":"1.2.0"}');

        self::assertSame('1.2.0', $release['version']);
        self::assertSame('', $release['changelog']);
        self::assertSame('', $release['zip_url']);
        self::assertSame([], $release['missed_roots']);
        self::assertSame([], $release['skipped_versions']);
        self::assertFalse($release['required_step']);
        self::assertSame('', $release['latest_version']);
    }

    public function testAFullFeedIsReadThrough(): void
    {
        $release = ReleaseFeed::parse(json_encode([
            'version'          => '1.4.0',
            'changelog'        => 'Fixed things',
            'date'             => '2026-08-18',
            'zip_url'          => 'https://updates.example.com/files/1.4.0/release.zip',
            'manifest_url'     => 'https://updates.example.com/files/1.4.0/manifest.json',
            'missed_roots'     => ['vendor'],
            'skipped_versions' => ['1.2.0', '1.3.0'],
            'required_step'    => true,
            'latest_version'   => '1.5.0',
        ]));

        self::assertSame('1.4.0', $release['version']);
        self::assertSame(['vendor'], $release['missed_roots']);
        self::assertSame(['1.2.0', '1.3.0'], $release['skipped_versions']);
        self::assertTrue($release['required_step']);
        self::assertSame('1.5.0', $release['latest_version']);
    }

    #[DataProvider('provideUnusableResponses')]
    public function testAResponseWithoutAVersionIsRefused(string $json): void
    {
        self::assertNull(ReleaseFeed::parse($json));
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function provideUnusableResponses(): iterable
    {
        yield 'empty body'      => [''];
        yield 'not json'        => ['<html>502</html>'];
        yield 'json array'      => ['["1.2.0"]'];
        yield 'no version'      => ['{"changelog":"x"}'];
        yield 'empty version'   => ['{"version":""}'];
        yield 'numeric version' => ['{"version":120}'];
    }

    /** These come from the server and end up rendered, so only plain strings survive. */
    public function testOnlyPlainStringsSurviveInTheLists(): void
    {
        $release = ReleaseFeed::parse(json_encode([
            'version'      => '1.2.0',
            'missed_roots' => ['vendor', 42, ['nested'], '', str_repeat('a', 65), 'public'],
        ]));

        self::assertSame(['vendor', 'public'], $release['missed_roots']);
    }

    public function testAFeedThatSendsTheWrongTypesIsReadDefensively(): void
    {
        $release = ReleaseFeed::parse(json_encode([
            'version'        => '1.2.0',
            'changelog'      => ['not', 'a', 'string'],
            'zip_url'        => 42,
            'latest_version' => false,
        ]));

        self::assertSame('', $release['changelog']);
        self::assertSame('', $release['zip_url']);
        self::assertSame('', $release['latest_version']);
    }

    #[DataProvider('provideVersionPairs')]
    public function testWhichVersionIsNewer(string $candidate, string $current, bool $expected): void
    {
        self::assertSame($expected, ReleaseFeed::isNewer($candidate, $current));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideVersionPairs(): iterable
    {
        yield 'patch ahead'        => ['1.2.1', '1.2.0', true];
        yield 'same'               => ['1.2.0', '1.2.0', false];
        yield 'behind'             => ['1.1.9', '1.2.0', false];
        yield 'two digits'         => ['1.10.0', '1.9.0', true];
        yield 'two digits behind'  => ['1.9.0', '1.10.0', false];
        yield 'major ahead'        => ['2.0.0', '1.99.99', true];
        yield 'shorter but ahead'  => ['1.3', '1.2.9', true];
        yield 'prerelease behind'  => ['1.2.0-beta1', '1.2.0', false];
    }
}
