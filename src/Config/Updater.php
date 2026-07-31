<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Config;

use CodeIgniter\Config\BaseConfig;
use Forgelabme\Ci4Updater\Libraries\UpdaterSettings;

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
     * Class used to persist update-server settings & last-update info.
     * Must implement Forgelabme\Ci4Updater\Libraries\SettingsInterface.
     * Override in your published app/Config/Updater.php to plug your own
     * storage (e.g. an existing AppSettingModel / app_settings table)
     * instead of the default JSON-file-in-writable/ implementation.
     *
     * @var class-string<\Forgelabme\Ci4Updater\Libraries\SettingsInterface>
     */
    public string $settingsClass = UpdaterSettings::class;

    /**
     * Directories (relative to ROOTPATH) that are scanned for the manifest,
     * zipped into the release package, and replaced on update.
     * Standard CodeIgniter 4 layout → ['app', 'public'] is almost always correct.
     *
     * This is the *building* side: it decides what `update:manifest` puts in a
     * release, and it is recorded in the manifest as that release's `roots`.
     * What an installation accepts on the receiving end is $allowedRoots.
     */
    public const SCAN_DIRS = ['app', 'public'];

    /**
     * The complete list of top-level directories a release is allowed to
     * declare. Empty means SCAN_DIRS — the default, and what every release
     * built by this package has used so far.
     *
     * This is a policy, not a scope: the scope of an update is whatever its
     * manifest declares, and a release naming a root that isn't listed here is
     * refused whole rather than partially applied. Widen it deliberately, for
     * instance to ['app', 'public', 'vendor'] if you ship dependencies.
     *
     * It guards against a misbuilt release, not against a hostile update
     * server — that server picks the roots, so it can simply declare ones you
     * allow. Signing is what defends against a compromised server.
     *
     * `writable` is refused whatever is listed here: it holds the backups a
     * rollback needs.
     *
     * @var list<string>
     */
    public array $allowedRoots = [];

    /**
     * Roots replaced by swapping the whole directory rather than file by file.
     *
     * `app/` and `public/` are fine written in place: they are small, and the
     * classes in them are already loaded by the time an update runs.
     * `vendor/` is not. It is autoloaded lazily throughout a request, so a
     * file-by-file rewrite leaves a mixed tree visible to every concurrent
     * request for as long as the copy takes, and a rewrite interrupted halfway
     * leaves no autoloader at all — no application, no panel, no rollback.
     *
     * A swapped root is staged next to the live one, verified in full, and
     * then put in place with two renames. The old tree is not copied into the
     * backup, it is renamed aside — instant, and it doesn't need twice the
     * disk on a host where quota is the binding constraint.
     *
     * Listing a root here does nothing until a release actually covers it.
     *
     * @var list<string>
     */
    public array $swapRoots = ['vendor'];

    /**
     * The layout the update panel extends.
     *
     * It must provide a `content` section, ideally `head` and `scripts`
     * sections, and should render `success`/`error` flash messages.
     */
    public string $layout = 'layout/main';

    /**
     * Name shown next to the version in the panel. Null falls back to a
     * generic label.
     */
    public ?string $appName = null;

    /**
     * View rendered by the panel.
     *
     * Null resolves automatically: an `app/Views/admin/updates.php` published
     * by an earlier `updater:setup` wins, otherwise the package's own view is
     * used — which is what lets interface changes arrive with `composer update`
     * instead of needing the view to be re-published by hand.
     *
     * Set it explicitly to pin a view of your own.
     */
    public ?string $viewPath = null;

    /**
     * How many backups to keep in writable/backups.
     *
     * Each applied update leaves one behind, so without a cap they accumulate
     * for the lifetime of the install. Pruning runs after an update is applied
     * successfully — never on a plain page view — and always keeps the newest
     * ones, so the backup for the update you just ran is never the one deleted.
     *
     * Set to 0 to keep every backup and prune them yourself.
     */
    public int $keepBackups = 5;

    /**
     * Public keys trusted to sign releases, as PEM contents or paths to PEM
     * files (a relative path is resolved from ROOTPATH).
     *
     * Empty — the default — means signatures are ignored entirely and updates
     * are trusted on the strength of the connection to the update server, as
     * they were before this option existed.
     *
     * As soon as one key is listed, a valid signature becomes **mandatory**:
     * an unsigned release, or one signed by an unknown key, is refused. That
     * asymmetry is deliberate — a "verify only if a signature is present"
     * policy would buy nothing, since whoever can tamper with a release can
     * also drop the signature.
     *
     * Generate a pair with `php spark updater:keygen`, keep the private key
     * offline (never on the update server), and list the public one here.
     * Several keys may be listed at once to rotate without downtime.
     *
     * @var list<string>
     */
    public array $publicKeys = [];

    /**
     * Keys used with $settingsClass to persist update-server settings &
     * last-update info.
     */
    public const SETTING_SERVER_URL   = 'update_server_url';
    public const SETTING_SERVER_TOKEN = 'update_server_token';
    public const SETTING_LAST_VERSION = 'last_update_version';
    public const SETTING_LAST_DATE    = 'last_update_date';
}
