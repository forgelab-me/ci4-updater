<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater;

use CodeIgniter\Router\RouteCollection;

/**
 * Entry point for wiring the package's admin routes into your app.
 *
 * Usage (in app/Config/Routes.php):
 *      service('updater')->routes($routes);
 *      service('updater')->routes($routes, ['prefix' => 'admin', 'filter' => 'admin']);
 *
 * `php spark updater:setup` adds this call for you automatically.
 */
class Updater
{
    /**
     * @param array{prefix?: string, filter?: string|null} $config
     */
    public function routes(RouteCollection &$routes, array $config = []): void
    {
        $prefix = trim($config['prefix'] ?? 'admin', '/');
        $filter = array_key_exists('filter', $config) ? $config['filter'] : 'admin';

        // csrf always applies, on top of whatever the app asked for.
        $filters = array_values(array_filter(
            array_merge((array) $filter, ['csrf']),
            static fn ($alias): bool => is_string($alias) && $alias !== '',
        ));

        $groupOptions = [
            'namespace' => 'Forgelabme\\Ci4Updater\\Controllers',
            'filter'    => $filters,
        ];

        $routes->group($prefix, $groupOptions, static function (RouteCollection $routes): void {
            $routes->get('updates', 'UpdateController::index');
            $routes->post('updates/migrate', 'UpdateController::applyMigrations');
            $routes->post('updates/clear-cache', 'UpdateController::clearCache');
            $routes->get('updates/check-remote', 'UpdateController::checkRemoteVersion');
            $routes->post('updates/download', 'UpdateController::upgradeDownload');
            $routes->post('updates/apply', 'UpdateController::upgradeApply');
            $routes->post('updates/cancel', 'UpdateController::upgradeCancel');
            $routes->post('updates/rollback', 'UpdateController::rollback');
            $routes->post('updates/backups/delete', 'UpdateController::deleteBackup');
        });
    }
}
