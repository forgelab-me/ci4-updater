<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Tests;

use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Router\RouteCollectionInterface;
use Forgelabme\Ci4Updater\Updater;
use PHPUnit\Framework\TestCase;

/**
 * What the panel's routes are wired behind.
 */
final class PanelRoutesTest extends TestCase
{
    public function testTheCsrfFilterIsAlwaysAskedFor(): void
    {
        self::assertContains('csrf', $this->filtersFor([]));
    }

    public function testTheAppsOwnFilterIsKept(): void
    {
        $filters = $this->filtersFor(['filter' => 'admin:superadmin']);

        self::assertContains('admin:superadmin', $filters, 'the auth filter is the whole boundary');
        self::assertContains('csrf', $filters);
    }

    public function testSeveralFiltersAreKept(): void
    {
        $filters = $this->filtersFor(['filter' => ['admin', 'rate-limit']]);

        self::assertContains('admin', $filters);
        self::assertContains('rate-limit', $filters);
        self::assertContains('csrf', $filters);
    }

    /** An app gating elsewhere may pass none; csrf still applies. */
    public function testCsrfSurvivesAnExplicitlyEmptyFilter(): void
    {
        self::assertSame(['csrf'], $this->filtersFor(['filter' => null]));
        self::assertSame(['csrf'], $this->filtersFor(['filter' => '']));
    }

    public function testEveryStateChangingRouteIsAPost(): void
    {
        $recorder = new RecordingRouteCollection();
        (new Updater())->routes($recorder);

        foreach (['updates/migrate', 'updates/clear-cache', 'updates/download', 'updates/apply', 'updates/cancel', 'updates/rollback', 'updates/backups/delete'] as $route) {
            self::assertContains($route, $recorder->posts, "{$route} must be a POST — CSRF only protects those");
        }

        // CodeIgniter's CSRF check skips safe methods; these two change nothing.
        self::assertSame(['updates', 'updates/check-remote'], $recorder->gets);
    }

    /**
     * @param array{prefix?: string, filter?: string|list<string>|null} $config
     *
     * @return list<string>
     */
    private function filtersFor(array $config): array
    {
        $recorder = new RecordingRouteCollection();
        (new Updater())->routes($recorder, $config);

        self::assertNotNull($recorder->options, 'routes() must open a group');

        return (array) ($recorder->options['filter'] ?? []);
    }
}

/**
 * Records what routes() asks for. The parent constructor is not called on
 * purpose — a real RouteCollection needs the locator and two config objects.
 */
final class RecordingRouteCollection extends RouteCollection
{
    /** @var array<string, mixed>|null */
    public ?array $options = null;

    /** @var list<string> */
    public array $gets = [];

    /** @var list<string> */
    public array $posts = [];

    public function __construct()
    {
    }

    public function group(string $name, ...$params)
    {
        $this->options = is_array($params[0] ?? null) ? $params[0] : [];

        $callback = end($params);

        if ($callback instanceof \Closure) {
            $callback($this);
        }

        return $this;
    }

    public function get(string $from, $to, ?array $options = null): RouteCollectionInterface
    {
        $this->gets[] = $from;

        return $this;
    }

    public function post(string $from, $to, ?array $options = null): RouteCollectionInterface
    {
        $this->posts[] = $from;

        return $this;
    }
}
