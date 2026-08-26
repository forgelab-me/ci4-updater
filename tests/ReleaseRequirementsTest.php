<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\ReleaseRequirements;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a release needs from the machine that installs it.
 */
final class ReleaseRequirementsTest extends TestCase
{
    // -- PHP constraints -------------------------------------------------------

    #[DataProvider('provideConstraints')]
    public function testWhetherAVersionSatisfiesAConstraint(string $constraint, string $version, ?bool $expected): void
    {
        self::assertSame($expected, ReleaseRequirements::satisfiesPhp($constraint, $version));
    }

    /**
     * @return iterable<string, array{string, string, bool|null}>
     */
    public static function provideConstraints(): iterable
    {
        yield 'caret, ahead'        => ['^8.2', '8.5.3', true];
        yield 'caret, exact'        => ['^8.2', '8.2.0', true];
        yield 'caret, behind'       => ['^8.2', '8.1.30', false];
        yield 'caret, next major'   => ['^8.2', '9.0.0', false];
        yield 'tilde, within minor' => ['~8.2.0', '8.2.9', true];
        yield 'tilde, two digits'   => ['~8.2', '8.5.0', true];
        yield 'tilde, next major'   => ['~8.2', '9.0.0', false];
        yield 'tilde, next minor'   => ['~8.2.0', '8.3.0', false];
        yield 'at least'            => ['>=8.2', '8.2.0', true];
        yield 'at least, behind'    => ['>=8.2', '8.1.9', false];
        yield 'above'               => ['>8.2', '8.2.0', false];
        yield 'range, inside'       => ['>=8.2 <9.0', '8.5.3', true];
        yield 'range, above'        => ['>=8.2 <9.0', '9.0.1', false];
        yield 'range, comma'        => ['>=8.2, <9.0', '8.4.0', true];
        yield 'bare version'        => ['8.2', '8.5.0', true];
        yield 'with a v'            => ['>=v8.2', '8.5.0', true];
        yield 'unparseable'         => ['gimme php', '8.5.0', null];
        yield 'composer or-clause'  => ['^8.1 || ^9.0', '8.5.0', null];
        yield 'empty'               => ['   ', '8.5.0', null];
    }

    /** PHP_VERSION carries suffixes that version_compare reads as older. */
    public function testAPreReleaseRuntimeIsReadAsItsOwnVersion(): void
    {
        self::assertTrue(ReleaseRequirements::satisfiesPhp('>=8.5', '8.5.3-dev'));
        self::assertTrue(ReleaseRequirements::satisfiesPhp('^8.2', '8.4.0RC1'));
    }

    // -- The check as a whole --------------------------------------------------

    /** A manifest from before this existed installs exactly as it did. */
    public function testAManifestWithoutRequirementsIsAccepted(): void
    {
        self::assertNull(ReleaseRequirements::check(['version' => '1.2.0']));
    }

    public function testARunnableReleaseIsAccepted(): void
    {
        self::assertNull(ReleaseRequirements::check([
            'requires' => ['php' => '>=8.0', 'extensions' => ['json']],
        ]));
    }

    public function testAReleaseNeedingANewerPhpIsRefused(): void
    {
        $error = ReleaseRequirements::check(['requires' => ['php' => '^9.9']], '8.5.3');

        self::assertNotNull($error);
        self::assertStringContainsString('^9.9', $error);
        self::assertStringContainsString('8.5.3', $error);
    }

    public function testAReleaseNeedingAMissingExtensionIsRefused(): void
    {
        $error = ReleaseRequirements::check([
            'requires' => ['extensions' => ['json', 'no-such-extension']],
        ]);

        self::assertNotNull($error);
        self::assertStringContainsString('no-such-extension', $error);
        self::assertStringNotContainsString('json', $error);
    }

    /** Composer spells them "ext-intl"; a manifest may repeat that. */
    public function testAnExtensionIsRecognisedWithOrWithoutItsComposerPrefix(): void
    {
        self::assertSame([], ReleaseRequirements::missingExtensions(['ext-json', 'JSON']));
    }

    /**
     * Refusing beats guessing: a constraint this installer cannot read is not
     * a constraint it can honour.
     */
    public function testAnUnreadableConstraintIsRefusedRatherThanIgnored(): void
    {
        $error = ReleaseRequirements::check(['requires' => ['php' => '^8.1 || ^9.0']]);

        self::assertNotNull($error);
        self::assertStringContainsString('cannot interpret', $error);
    }

    public function testAMalformedRequiresEntryIsRefused(): void
    {
        self::assertNotNull(ReleaseRequirements::check(['requires' => 'php 8.2']));
    }

    public function testAnEmptyRequiresEntryDemandsNothing(): void
    {
        self::assertNull(ReleaseRequirements::check(['requires' => []]));
        self::assertNull(ReleaseRequirements::check(['requires' => ['php' => '', 'extensions' => []]]));
    }

    public function testDescribeReadsBackWhatWasDeclared(): void
    {
        self::assertSame('PHP ^8.2 · intl, zip', ReleaseRequirements::describe([
            'php' => '^8.2', 'extensions' => ['intl', 'zip'],
        ]));
        self::assertSame('PHP ^8.2', ReleaseRequirements::describe(['php' => '^8.2']));
        self::assertSame('', ReleaseRequirements::describe([]));
    }
}
