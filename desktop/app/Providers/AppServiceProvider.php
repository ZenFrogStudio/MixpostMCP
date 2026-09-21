<?php

namespace App\Providers;

use Illuminate\Encryption\Encrypter;
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

        // Each install gets its own encryption key. The installer's .env carries none (see
        // cleanup_env_keys in config/nativephp.php), so one user's saved tokens cannot be read with
        // another user's copy of the app. A dev checkout (`php artisan`) keeps using .env.
        if (config('nativephp-internal.running')) {
            config(['app.key' => $this->installKey()]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Read the per-install key from app-data, creating it on first run.
     */
    private function installKey(): string
    {
        // storage_path() is the per-user app-data folder when NativePHP is running.
        $file = storage_path('app/app.key');

        if (! is_file($file)) {
            @mkdir(dirname($file), 0700, true);
            file_put_contents($file, 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher'))));
            @chmod($file, 0600);
        }

        return trim((string) file_get_contents($file));
    }
}
