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
                'has_queue_connection' => $this->hasRedisQueue(),
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
                    'horizon' => InstalledVersions::getVersion('laravel/horizon'),
                    'mysql' => $this->mysqlVersion(),
                    'mixpostmcp' => InstalledVersions::getVersion('onemedialabs/mixpostmcp'),
                ],
            ],
        ]);
    }

    /**
     * Horizon only works Redis queues, so the default queue connection has to be one. The old
     * check looked for a connection named after the package instead, which nothing dispatches to.
     */
    protected function hasRedisQueue(): bool
    {
        $connection = Config::get('queue.default');

        return Config::get("queue.connections.$connection.driver") === 'redis';
    }

    /**
     * Every publish is batched onto the `publish-post` queue. If no Horizon supervisor lists it,
     * everything else on this page can be green while no post ever goes out — which is exactly how
     * the dev harness ran for a week.
     */
    protected function publishQueueSupervised(): bool
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

    protected function mysqlVersion(): string
    {
        if (! Util::isMysqlDatabase()) {
            return '';
        }

        $results = DB::select('select version() as version');

        return (string) $results[0]->version;
    }
}
