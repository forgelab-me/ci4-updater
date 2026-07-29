<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Self-update system configuration — package defaults.
 *
 * Don't edit this file: it lives in vendor/ and will be overwritten on
 * `composer update`. Run `php spark updater:setup` to publish an editable
 * copy to app/Config/Updater.php, then adjust VERSION / DATE / USER_AGENT
 * there for your app.
 */
class Updater extends BaseConfig
{
    /** Current application version (shown in the admin panel & sent to the update server). */
    public const VERSION = '1.0.0';

    /** Release date of the current version, format Y-m-d. */
    public const DATE = '2026-01-01';

    /** User-Agent string sent when contacting the update server / GitHub. Override per app. */
    public const USER_AGENT = 'MyAppUpdater/1.0';

    /**
     * Directories (relative to ROOTPATH) that are scanned for the manifest,
     * zipped into the release package, and replaced on update.
     * Standard CodeIgniter 4 layout → ['app', 'public'] is almost always correct.
     */
    public const SCAN_DIRS = ['app', 'public'];

    /**
     * Keys used to persist update-server settings & last-update info.
     * The default UpdaterSettings store is a simple JSON file in writable/ —
     * see Libraries/UpdaterSettings.php if you'd rather plug your own
     * (e.g. an existing AppSettingModel / app_settings table).
     */
    public const SETTING_SERVER_URL   = 'update_server_url';
    public const SETTING_SERVER_TOKEN = 'update_server_token';
    public const SETTING_LAST_VERSION = 'last_update_version';
    public const SETTING_LAST_DATE    = 'last_update_date';
}
