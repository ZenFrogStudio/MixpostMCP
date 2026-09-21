<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::redirect('/', '/mixpostmcp');

// The package's auth middleware redirects to a route named 'login'; SignInLocalUser makes that a no-op.
Route::redirect('/login', '/mixpostmcp')->name('login');

Route::post('/logout', function () {
    Auth::logout();

    return redirect('/');
})->name('logout');

// Serves the 'mixpostmcp' disk. The 'public' disk needs a public/storage symlink, which the bundled app cannot have.
Route::get('/mixpostmcp-media/{path}', function (string $path) {
    $disk = Storage::disk('mixpostmcp');

    abort_if(str_contains($path, '..') || ! $disk->exists($path), 404);

    return $disk->response($path);
})->where('path', '.*');
