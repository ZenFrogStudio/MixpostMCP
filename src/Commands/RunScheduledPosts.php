<?php

namespace OneMediaLabs\MixpostMcp\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use OneMediaLabs\MixpostMcp\Actions\PublishPost;
use OneMediaLabs\MixpostMcp\Enums\PostScheduleStatus;
use OneMediaLabs\MixpostMcp\Enums\PostStatus;
use OneMediaLabs\MixpostMcp\Models\Post;

class RunScheduledPosts extends Command
{
    public $signature = 'mixpostmcp:run-scheduled-posts';

    public $description = 'Scan & run scheduled posts';

    public function handle(): int
    {
        Cache::put('mixpostmcp-last-schedule-run', Carbon::now('utc'));

        Post::with('accounts')
            ->where('status', PostStatus::SCHEDULED->value)
            ->where('schedule_status', PostScheduleStatus::PENDING->value)
            ->where('scheduled_at', '<=', Carbon::now()->utc())
            ->each(function (Post $post) {
                (new PublishPost)($post);
            });

        return self::SUCCESS;
    }
}
