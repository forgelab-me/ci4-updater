<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\DownloadPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a release URL may be, and what it may be told.
 */
final class DownloadPolicyTest extends TestCase
{
    private const SERVER = 'https://updates.example.com/api/my-app';

    // -- What may be fetched ---------------------------------------------------

    #[DataProvider('provideNonHttpUrls')]
    public function testRefusesAnythingThatIsNotHttp(string $url): void
    {
        $verdict = DownloadPolicy::forUrl($url, self::SERVER);

        self::assertFalse($verdict['allowed'], "Expected {$url} to be refused");
        self::assertFalse($verdict['sendToken']);
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function provideNonHttpUrls(): iterable
    {
        yield 'local file'      => ['file:///etc/passwd'];
        yield 'php wrapper'     => ['php://filter/read=convert.base64-encode/resource=env'];
        yield 'data uri'        => ['data:text/plain;base64,SGVsbG8='];
        yield 'ftp'             => ['ftp://example.com/release.zip'];
        yield 'gopher'          => ['gopher://example.com:70/'];
        yield 'no scheme'       => ['updates.example.com/release.zip'];
        yield 'scheme only'     => ['https://'];
        yield 'empty'           => [''];
        yield 'whitespace only' => ['   '];
    }

    public function testRefusesPlainHttpFromSomewhereElse(): void
    {
        $verdict = DownloadPolicy::forUrl('http://cdn.example.net/release.zip', self::SERVER);

        self::assertFalse($verdict['allowed']);
        self::assertStringContainsString('plain HTTP', $verdict['error']);
    }

    /** The panel says what plain HTTP costs; it does not block it. */
    public function testAllowsPlainHttpBackToAnHttpServer(): void
    {
        $verdict = DownloadPolicy::forUrl('http://updates.internal/files/1.0/release.zip', 'http://updates.internal/api/app');

        self::assertTrue($verdict['allowed']);
        self::assertTrue($verdict['sendToken']);
    }

    public function testAllowsHttpsFromAnywhere(): void
    {
        self::assertTrue(DownloadPolicy::forUrl('https://github.com/o/r/releases/download/v1/r.zip', self::SERVER)['allowed']);
    }

    // -- What may see the token ------------------------------------------------

    public function testTheTokenNeverLeavesTheConfiguredServer(): void
    {
        foreach ([
            'https://github.com/o/r/releases/download/v1/release.zip',
            'https://updates.example.com.evil.test/release.zip',
            'https://evil.test/?x=https://updates.example.com/',
            'https://updates.example.com:8443/release.zip',
        ] as $url) {
            self::assertFalse(
                DownloadPolicy::forUrl($url, self::SERVER)['sendToken'],
                "Expected no token for {$url}"
            );
        }
    }

    public function testTheTokenGoesToTheConfiguredServer(): void
    {
        $verdict = DownloadPolicy::forUrl('https://updates.example.com/api/my-app/files/1.2.0/release.zip', self::SERVER);

        self::assertTrue($verdict['allowed']);
        self::assertTrue($verdict['sendToken']);
    }

    public function testWithNoServerConfiguredNothingGetsTheToken(): void
    {
        $verdict = DownloadPolicy::forUrl('https://updates.example.com/release.zip', '');

        self::assertTrue($verdict['allowed'], 'https is still fetchable');
        self::assertFalse($verdict['sendToken']);
    }

    // -- Origins ---------------------------------------------------------------

    public function testOriginIgnoresCaseAndDefaultPorts(): void
    {
        self::assertSame('https://updates.example.com', DownloadPolicy::origin('https://Updates.Example.COM/api/x'));
        self::assertSame('https://updates.example.com', DownloadPolicy::origin('https://updates.example.com:443/api/x'));
        self::assertSame('http://updates.example.com', DownloadPolicy::origin('http://updates.example.com:80/'));
        self::assertSame('https://updates.example.com:8443', DownloadPolicy::origin('https://updates.example.com:8443/'));
    }

    /** '' must never compare equal to a real origin. */
    public function testUnparseableUrlsHaveNoOrigin(): void
    {
        foreach (['', 'not a url', '/relative/path', 'file:///etc/passwd'] as $url) {
            self::assertSame('', DownloadPolicy::origin($url), $url);
        }
    }

    public function testHttpAndHttpsAreDifferentOrigins(): void
    {
        self::assertNotSame(
            DownloadPolicy::origin('https://updates.example.com/'),
            DownloadPolicy::origin('http://updates.example.com/'),
        );
    }

    // -- Redirect targets ------------------------------------------------------

    public function testAbsoluteRedirectsAreKeptWhenTheySpeakHttp(): void
    {
        self::assertSame(
            'https://cdn.example.net/asset.zip',
            DownloadPolicy::resolveRedirect('https://cdn.example.net/asset.zip', self::SERVER),
        );
    }

    public function testRelativeRedirectsResolveAgainstTheOrigin(): void
    {
        self::assertSame(
            'https://updates.example.com/files/1.2.0/release.zip',
            DownloadPolicy::resolveRedirect('/files/1.2.0/release.zip', self::SERVER . '/latest.json'),
        );
    }

    public function testRedirectsIntoAnotherSchemeAreRefused(): void
    {
        foreach (['file:///etc/passwd', 'php://input', 'data:text/plain,x', '', 'release.zip'] as $location) {
            self::assertSame('', DownloadPolicy::resolveRedirect($location, self::SERVER), $location);
        }
    }
}
