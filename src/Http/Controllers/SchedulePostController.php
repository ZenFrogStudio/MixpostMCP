<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use OneMediaLabs\MixpostMcp\Facades\Settings;
use OneMediaLabs\MixpostMcp\Http\Requests\SchedulePost;
use OneMediaLabs\MixpostMcp\Util;

class SchedulePostController extends Controller
{
    public function __invoke(SchedulePost $schedulePost): JsonResponse
    {
        $schedulePost->handle();

        $scheduledAt = $schedulePost->getDateTime()->tz(Settings::get('timezone'))->format('D, M j, '.Util::timeFormat());

        return response()->json("The post has been scheduled.\n$scheduledAt");
    }
}
