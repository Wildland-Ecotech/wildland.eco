<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run unless the application is connected to the test database.
     *
     * Runs before RefreshDatabase and the other database traits, so a cached
     * configuration (`php artisan optimize`) or a missing phpunit.xml setting
     * can never wipe the development database.
     */
    protected function setUpTraits()
    {
        $connection = $this->app['config']->get('database.default');
        $driver = $this->app['config']->get("database.connections.{$connection}.driver");
        $host = $this->app['config']->get("database.connections.{$connection}.host");

        if ($driver !== 'pgsql' || $host !== 'db-test') {
            throw new RuntimeException(
                "Tests must run against the db-test PostgreSQL service, not {$driver} on " . ($host ?? 'no host') . '. '
                . 'Run `php artisan config:clear` if the configuration is cached, and start the test database with '
                . '`docker compose --profile testing up -d db-test`.'
            );
        }

        return parent::setUpTraits();
    }

    /**
     * A page's HTML without the menu overlay, which every page includes, so
     * assertions can check the page's own content. The overlay holds no
     * other sections, so it ends at the first </section> after it starts.
     */
    protected function withoutMenuOverlay(string $html): string
    {
        $start = strpos($html, '<section class="menu-overlay"');

        if ($start === false) {
            return $html;
        }

        $end = strpos($html, '</section>', $start) + strlen('</section>');

        return substr($html, 0, $start) . substr($html, $end);
    }
}
