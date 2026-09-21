<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Composer\InstalledVersions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use OneMediaLabs\MixpostMcp\Support\HorizonStatus;
use OneMediaLabs\MixpostMcp\Util;

class SystemStatusController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('System/Status', [
            'health' => [
                'env' => App::environment(),
                'debug' => Config::get('app.debug'),
                'horizon_status' => resolve(HorizonStatus::class)->get(),
                'queue_driver' => $this->queueDriver(),
                'has_queue_connection' => $this->hasQueueConnection(),
                'publish_queue_supervised' => $this->publishQueueSupervised(),
                'last_scheduled_run' => $this->getLastScheduleRun(),
            ],
            'tech' => [
                'cache_driver' => Config::get('cache.default'),
                'base_path' => base_path(),
                'disk' => Config::get('mixpostmcp.disk'),
                'log_channel' => Config::get('mixpostmcp.log_channel') ? Config::get('mixpostmcp.log_channel') : Config::get('logging.default'),
                'user_agent' => $request->userAgent(),
                'ffmpeg_status' => Util::isFFmpegInstalled() ? 'Installed' : 'Not Installed',
                'versions' => [
                    'php' => PHP_VERSION,
                    'laravel' => App::version(),
                    'horizon' => InstalledVersions::isInstalled('laravel/horizon') ? InstalledVersions::getVersion('laravel/horizon') : null,
                    'database' => $this->databaseVersion(),
                    'mixpostmcp' => InstalledVersions::getVersion('onemedialabs/mixpostmcp'),
                ],
            ],
        ]);
    }

    protected function queueDriver(): ?string
    {
        $connection = Config::get('queue.default');

        return Config::get("queue.connections.$connection.driver");
    }

    /**
     * Two setups can run jobs: Redis under Horizon (server), or the `database` driver worked by a
     * built-in worker (desktop). Anything else — `sync`, `null` — silently drops scheduled posts.
     */
    protected function hasQueueConnection(): bool
    {
        return in_array($this->queueDriver(), ['redis', 'database'], true);
    }

    /**
     * Every publish is batched onto the `publish-post` queue. If nothing is working that queue,
     * everything else on this page can be green while no post ever goes out — which is exactly how
     * the dev harness ran for a week.
     *
     * Returns null when there is no way to tell (the `database` driver without NativePHP's worker
     * config), so the page can say "cannot verify" instead of a false red or green.
     */
    protected function publishQueueSupervised(): ?bool
    {
        return match ($this->queueDriver()) {
            'redis' => $this->horizonWorksPublishQueue(),
            'database' => $this->nativeWorkerWorksPublishQueue(),
            default => false,
        };
    }

    protected function horizonWorksPublishQueue(): bool
    {
        $supervisors = array_merge(
            Config::get('horizon.defaults', []),
            Config::get('horizon.environments.'.App::environment(), [])
        );

        foreach ($supervisors as $name => $settings) {
            $queues = $settings['queue'] ?? Config::get("horizon.defaults.$name.queue", []);

            foreach ((array) $queues as $queue) {
                if ($queue === 'publish-post' || $queue === '*') {
                    return true;
                }
            }
        }

        return false;
    }

    protected function nativeWorkerWorksPublishQueue(): ?bool
    {
        if (! Config::has('nativephp.queue_workers')) {
            return null;
        }

        foreach (Config::get('nativephp.queue_workers', []) as $worker) {
            if (in_array('publish-post', (array) ($worker['queues'] ?? []), true)) {
                return true;
            }
        }

        return false;
    }

    protected function getLastScheduleRun(): array
    {
        $lastScheduleRun = Cache::get('mixpostmcp-last-schedule-run');

        if (! $lastScheduleRun) {
            return [
                'variant' => 'error',
                'message' => 'It never started',
            ];
        }

        $diff = (int) abs(Carbon::now('UTC')->diffInMinutes($lastScheduleRun));

        if ($diff < 10) {
            return [
                'variant' => 'success',
                'message' => "Ran $diff minute(s) ago",
            ];
        }

        return [
            'variant' => 'warning',
            'message' => "Ran $diff minute(s) ago",
        ];
    }

    /**
     * "<driver> <version>", e.g. "mysql 8.0.36" or "sqlite 3.45.1". Empty for any other driver.
     */
    protected function databaseVersion(): string
    {
        $driver = Util::getDatabaseDriver();

        $sql = match ($driver) {
            'mysql' => 'select version() as version',
            'sqlite' => 'select sqlite_version() as version',
            default => null,
        };

        if ($sql === null) {
            return '';
        }

        return $driver.' '.DB::select($sql)[0]->version;
    }
}
