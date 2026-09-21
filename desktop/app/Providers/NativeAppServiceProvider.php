<?php

namespace App\Providers;

use App\Support\McpLauncher;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted. One window only.
     */
    public function boot(): void
    {
        // Rewritten every start so it always points at the current install (see App\Listeners\HelpMenu).
        McpLauncher::write();

        Menu::create(
            // Windows labels the app menu with the package slug ("mixpostmcp") unless told otherwise.
            Menu::app()->label(config('app.name')),
            Menu::edit(),
            Menu::view(),
            Menu::window(),
            Menu::make(
                Menu::label('Copy Claude Desktop config')->id('copy-claude-config'),
                Menu::label('Open data folder')->id('open-data-folder'),
            )->label('Help'),
        );

        Window::open()
            ->title('MixpostMCP')
            ->url(url('/mixpostmcp'))
            ->width(1280)
            ->height(800)
            ->minWidth(1024)
            ->minHeight(640);
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
            'memory_limit' => '512M',
            'upload_max_filesize' => '256M',
            'post_max_size' => '256M',
        ];
    }
}
