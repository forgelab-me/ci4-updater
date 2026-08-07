<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use Forgelabme\Ci4Updater\Libraries\UpdaterSettings;
use PHPUnit\Framework\TestCase;

/**
 * The default settings store.
 *
 * Until 2.9 this file only appeared once something had written to it, so a new
 * install was told to "set update_server_url" with nothing on disk to edit and
 * no way to guess the shape short of reading the package source. `updater:setup`
 * now creates it; these tests pin the contract that makes that possible — a
 * flat JSON map at a filename the rest of the package can name.
 *
 * @internal
 */
final class SettingsStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = WRITEPATH . UpdaterSettings::FILENAME;
        @unlink($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testTheFilenameIsPubliclyNameable(): void
    {
        // setup and the panel both point users at this file by name, so it
        // cannot go on being a string buried in a constructor.
        self::assertSame('updater_settings.json', UpdaterSettings::FILENAME);
    }

    public function testAMissingFileReadsAsEmptyRatherThanFailing(): void
    {
        self::assertFileDoesNotExist($this->path);
        self::assertNull((new UpdaterSettings())->getSetting('update_server_url'));
        self::assertSame('fallback', (new UpdaterSettings())->getSetting('update_server_url', 'fallback'));
    }

    public function testWritingThenReadingBackThroughAFreshInstance(): void
    {
        (new UpdaterSettings())->setSetting('update_server_url', 'https://example.test/api/app');

        // A fresh instance: the panel and the CLI never share one.
        self::assertSame('https://example.test/api/app', (new UpdaterSettings())->getSetting('update_server_url'));
    }

    /**
     * The shape matters: it is what a user sees when told to edit the file.
     */
    public function testTheFileIsAFlatReadableJsonMap(): void
    {
        $store = new UpdaterSettings();
        $store->setSetting('update_server_url', 'https://example.test/api/app');
        $store->setSetting('update_server_token', 'secret');

        $raw = (string) file_get_contents($this->path);

        self::assertSame([
            'update_server_url'   => 'https://example.test/api/app',
            'update_server_token' => 'secret',
        ], json_decode($raw, true));

        // Pretty-printed and slashes unescaped, or a URL reads as
        // https:\/\/example.test and nobody wants to hand-edit that.
        self::assertStringContainsString('"update_server_url": "https://example.test/api/app"', $raw);
    }

    public function testSettingAKeyLeavesTheOthersAlone(): void
    {
        $store = new UpdaterSettings();
        $store->setSetting('update_server_url', 'https://example.test/api/app');
        $store->setSetting('last_update_version', '1.2.0');
        $store->setSetting('update_server_token', 'secret');

        $all = json_decode((string) file_get_contents($this->path), true);

        self::assertSame('https://example.test/api/app', $all['update_server_url']);
        self::assertSame('1.2.0', $all['last_update_version']);
    }

    /**
     * A corrupt file must not take the panel down with it — it degrades to
     * "nothing configured", which the panel already knows how to explain.
     */
    public function testAMalformedFileReadsAsEmpty(): void
    {
        file_put_contents($this->path, 'not json at all');

        self::assertNull((new UpdaterSettings())->getSetting('update_server_url'));
    }
}
