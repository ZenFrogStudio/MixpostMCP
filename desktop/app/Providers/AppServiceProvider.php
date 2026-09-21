<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ffmpeg ships inside the app bundle (extras/ffmpeg), not on the user's PATH.
        $ffmpegDir = (env('NATIVEPHP_EXTRAS_PATH') ?: base_path('extras')).'/ffmpeg/';
        $exe = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';

        config([
            'mixpostmcp.ffmpeg_path' => $ffmpegDir.'ffmpeg'.$exe,
            'mixpostmcp.ffprobe_path' => $ffmpegDir.'ffprobe'.$exe,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
