<?php

namespace OneMediaLabs\MixpostMcp;

use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;

class Schedule
{
    public static function register(LaravelSchedule $schedule): void
    {
        $schedule->command('mixpostmcp:run-scheduled-posts')->everyMinute();
        // Every thirty minutes, not hourly: a YouTube token lives about an hour and
        // tokenIsAboutToExpire() only looks ten minutes ahead, so an hourly sweep leaves a window
        // where a token dies between two runs.
        $schedule->command('mixpostmcp:refresh-access-tokens')->everyThirtyMinutes();
        $schedule->command('mixpostmcp:import-account-data')->everyTwoHours();
        $schedule->command('mixpostmcp:import-account-audience')->everyThreeHours();
        $schedule->command('mixpostmcp:process-metrics')->everyThreeHours();
        $schedule->command('mixpostmcp:delete-old-data')->daily();
        $schedule->command('mixpostmcp:prune-temporary-directory')->hourly();
    }
}
