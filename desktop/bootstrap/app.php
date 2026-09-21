<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // NativePHP means to add the browser guard itself but pushes it onto the wrong Kernel instance
        // (nativephp/desktop 2.3). Without it any local browser reaches the app fully signed in.
        // It is a no-op outside NativePHP. Appended to the web group (not global) so the 403 page can render.
        $middleware->web(append: [
            \Native\Desktop\Http\Middleware\PreventRegularBrowserAccess::class,
            \App\Http\Middleware\SignInLocalUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
