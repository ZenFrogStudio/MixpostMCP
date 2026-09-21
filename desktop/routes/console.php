<?php

use Illuminate\Console\Scheduling\Schedule;
use OneMediaLabs\MixpostMcp\Schedule as MixpostMcpSchedule;

/*
 * MixpostMCP ships its scheduled work as one registrar. Without this the NativePHP scheduler
 * ticks happily but nothing is ever published: a scheduled post would simply sit there.
 */
MixpostMcpSchedule::register(app(Schedule::class));
