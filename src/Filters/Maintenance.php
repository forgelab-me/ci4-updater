<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Forgelabme\Ci4Updater\Libraries\MaintenanceWindow;

/**
 * Answers 503 while an update is writing files.
 *
 * Register it globally, and exempt the update panel so an admin can still
 * reach it:
 *
 *     // app/Config/Filters.php
 *     public array $aliases = [
 *         'maintenance' => \Forgelabme\Ci4Updater\Filters\Maintenance::class,
 *     ];
 *
 *     public array $globals = [
 *         'before' => [
 *             'maintenance' => ['except' => ['admin/updates', 'admin/updates/*']],
 *         ],
 *     ];
 */
class Maintenance implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! MaintenanceWindow::isOpen()) {
            return null;
        }

        $retry = MaintenanceWindow::retryAfter();

        return service('response')
            ->setStatusCode(503, 'Service Unavailable')
            ->setHeader('Retry-After', (string) $retry)
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($this->body($retry));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private function body(int $retry): string
    {
        $view = config('Updater')->maintenanceView ?? null;

        if (is_string($view) && $view !== '') {
            return view($view, ['retryAfter' => $retry, 'state' => MaintenanceWindow::state()]);
        }

        return '<!doctype html><meta charset="utf-8"><title>Updating</title>'
            . '<div style="font:16px/1.5 system-ui,sans-serif;max-width:32rem;margin:20vh auto;text-align:center">'
            . '<h1 style="font-size:1.25rem">Updating</h1>'
            . '<p>This application is being updated and will be back shortly.</p>'
            . '</div>';
    }
}
