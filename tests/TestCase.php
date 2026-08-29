<?php

namespace Inovector\Mixpost\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Inovector\Mixpost\MixpostServiceProvider;
use Laravel\Horizon\HorizonServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Inovector\\Mixpost\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            HorizonServiceProvider::class,
            MixpostServiceProvider::class,
        ];
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
