<?php

namespace davidhellmann\primer\tests;

use CraftCms\Aliases\AliasesServiceProvider;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\Support\Json;
use CraftCms\DependencyAwareCache\CacheServiceProvider;
use CraftCms\RulesetValidation\RulesetValidationServiceProvider;
use Illuminate\Routing\Events\Routing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Inertia\ServiceProvider as InertiaServiceProvider;

/**
 * A Craft app with Primer installed, on an in-memory SQLite database that each test starts afresh.
 */
abstract class TestCase extends PluginTestCase
{
    /**
     * Craft's install leaves SQLite's foreign keys off. MySQL and Postgres enforce them, and Primer's table relies on
     * them to go with its elements, sections and entry types.
     */
    public function beginDatabaseTransaction(): void
    {
        // Has to be set outside the transaction each test runs in.
        Schema::enableForeignKeyConstraints();

        parent::beginDatabaseTransaction();
    }

    /**
     * Each request starts with fresh scoped instances and controllers, as a request does in production, where it's a
     * process of its own. Craft's `ElementRequest` is scoped, and its controllers take it in their constructor: they
     * would otherwise keep the first request's input.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(function(Routing $event) {
            $this->app->forgetScopedInstances();
            foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
                $route->flushController();
            }
        });
    }

    /**
     * A Craft project discovers these from Craft's own dependencies. Testbench doesn't, so they're listed here.
     */
    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            AliasesServiceProvider::class,
            CacheServiceProvider::class,
            RulesetValidationServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    /**
     * The facades Craft declares for a project to discover, which its control panel templates use.
     */
    protected function getPackageAliases($app): array
    {
        $composer = Json::decode((string)file_get_contents(dirname(__DIR__) . '/vendor/craftcms/cms/composer.json'));

        return $composer['extra']['laravel']['aliases'] ?? [];
    }
}
