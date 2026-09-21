<?php

namespace App\Providers;

use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted. One window only.
     */
    public function boot(): void
    {
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
