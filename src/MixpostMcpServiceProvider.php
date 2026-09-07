<?php

namespace OneMediaLabs\MixpostMcp;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use OneMediaLabs\MixpostMcp\Commands\ClearServicesCache;
use OneMediaLabs\MixpostMcp\Commands\ClearSettingsCache;
use OneMediaLabs\MixpostMcp\Commands\CreateMastodonApp;
use OneMediaLabs\MixpostMcp\Commands\DeleteOldData;
use OneMediaLabs\MixpostMcp\Commands\ImportAccountAudience;
use OneMediaLabs\MixpostMcp\Commands\ImportAccountData;
use OneMediaLabs\MixpostMcp\Commands\ProcessMetrics;
use OneMediaLabs\MixpostMcp\Commands\PruneTemporaryDirectory;
use OneMediaLabs\MixpostMcp\Commands\PublishAssetsCommand;
use OneMediaLabs\MixpostMcp\Commands\RefreshAccessTokens;
use OneMediaLabs\MixpostMcp\Commands\RunScheduledPosts;
use OneMediaLabs\MixpostMcp\Events\AccountAdded;
use OneMediaLabs\MixpostMcp\Events\AccountUnauthorized;
use OneMediaLabs\MixpostMcp\Exceptions\MixpostMcpExceptionHandler;
use OneMediaLabs\MixpostMcp\Listeners\HandleAccountImports;
use OneMediaLabs\MixpostMcp\Listeners\SendAccountUnauthorizedNotification;
use OneMediaLabs\MixpostMcp\Mcp\MixpostServer;
use Laravel\Mcp\Facades\Mcp;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class MixpostMcpServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('mixpostmcp')
            ->hasConfigFile()
            ->hasViews()
            ->hasRoute('web')
            ->hasMigrations([
                'create_mixpost_tables',
                // Named to sort after 'create_mixpost_tables' so it never runs before the table exists.
                'update_mixpost_post_versions_add_options',
            ])
            ->hasCommands([
                PublishAssetsCommand::class,
                CreateMastodonApp::class,
                ClearSettingsCache::class,
                ClearServicesCache::class,
                RunScheduledPosts::class,
                RefreshAccessTokens::class,
                ImportAccountAudience::class,
                ImportAccountData::class,
                ProcessMetrics::class,
                DeleteOldData::class,
                PruneTemporaryDirectory::class,
            ])->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->startWith(function (InstallCommand $command) {
                        $this->writeSeparationLine($command);
                        $command->line('MixpostMCP Installation. Self-hosted social media management software.');
                        $command->line('Laravel version: '.app()->version());
                        $command->line('PHP version: '.trim(phpversion()));
                        $command->line(' ');
                        $command->line('Github: https://github.com/onemedialabs/mixpostmcp');
                        $this->writeSeparationLine($command);
                        $command->line('');

                        $command->comment('Publishing assets');
                        $command->call('mixpostmcp:publish-assets');
                    })
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('onemedialabs/mixpostmcp')
                    ->endWith(function (InstallCommand $command) {
                        $appUrl = config('app.url');

                        $command->line("Visit the MixpostMCP UI at $appUrl/mixpostmcp");
                    });
            });
    }

    public function packageRegistered()
    {
        $this->app->singleton('MixpostMcpSocialProviderManager', function ($app) {
            return new SocialProviderManager($app);
        });

        $this->app->singleton('MixpostMcpSettings', function ($app) {
            return new Settings($app);
        });

        $this->app->singleton('MixpostMcpServiceManager', function ($app) {
            return new ServiceManager($app);
        });
    }

    public function packageBooted()
    {
        $this->bootEvents();

        $this->registerExceptionHandler();

        $this->registerMcpServer();

        Gate::define('viewMixpostMcp', function () {
            return true;
        });
    }

    /**
     * laravel/mcp is optional: it needs Laravel 12.41+, while Mixpost still supports 10.47 and 11.
     * Hosts without it simply do not get the MCP server.
     */
    protected function registerMcpServer(): void
    {
        if (! class_exists(Mcp::class)) {
            return;
        }

        Mcp::local('mixpostmcp', MixpostServer::class);
    }

    protected function bootEvents(): void
    {
        Event::listen(AccountAdded::class, HandleAccountImports::class);
        Event::listen(AccountUnauthorized::class, SendAccountUnauthorizedNotification::class);
    }

    protected function registerExceptionHandler(): void
    {
        app()->bind(ExceptionHandler::class, MixpostMcpExceptionHandler::class);
    }

    protected function writeSeparationLine(InstallCommand $command): void
    {
        $command->info('*---------------------------------------------------------------------------*');
    }
}
