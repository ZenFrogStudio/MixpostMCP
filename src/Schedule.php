<?php

namespace Inovector\Mixpost;

use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;

class Schedule
{
    public static function register(LaravelSchedule $schedule): void
    {
        $schedule->command('mixpost:run-scheduled-posts')->everyMinute();
        // Every thirty minutes, not hourly: a YouTube token lives about an hour and
        // tokenIsAboutToExpire() only looks ten minutes ahead, so an hourly sweep leaves a window
        // where a token dies between two runs.
        $schedule->command('mixpost:refresh-access-tokens')->everyThirtyMinutes();
        $schedule->command('mixpost:import-account-data')->everyTwoHours();
        $schedule->command('mixpost:import-account-audience')->everyThreeHours();
        $schedule->command('mixpost:process-metrics')->everyThreeHours();
        $schedule->command('mixpost:delete-old-data')->daily();
        $schedule->command('mixpost:prune-temporary-directory')->hourly();
    }
}
