<?php

namespace OneMediaLabs\MixpostMcp\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use OneMediaLabs\MixpostMcp\MixpostMcpServiceProvider;
use Laravel\Horizon\HorizonServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'OneMediaLabs\\MixpostMcp\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app): array
    {
        $providers = [
            HorizonServiceProvider::class,
            MixpostMcpServiceProvider::class,
        ];

        // Testbench does not run Laravel's package auto-discovery, so laravel/mcp's provider has
        // to be named here. Without it nothing binds the tool arguments and every MCP tool sees
        // an empty request. It is optional, hence the check.
        if (class_exists(McpServiceProvider::class)) {
            array_unshift($providers, McpServiceProvider::class);
        }

        return $providers;
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // ServiceManager caches credentials, and an array cache that survives between tests would
        // leak one test's service configuration into the next.
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');

        foreach (glob(__DIR__.'/../database/migrations/*.php') as $migration) {
            (include $migration)->up();
        }
    }
}
