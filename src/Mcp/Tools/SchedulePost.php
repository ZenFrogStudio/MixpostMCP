<?php

namespace Inovector\Mixpost\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Inovector\Mixpost\Facades\Settings;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Util;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Put a drafted Mixpost post into the publishing queue at a given date and time. There is no way to publish immediately: the time has to be far enough ahead for a human to review it in the calendar first.')]
class SchedulePost extends Tool
{
    protected string $name = 'schedule_post';

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()
                ->required()
                ->description('The post uuid, as returned by list_posts or create_post.'),
            'date' => $schema->string()
                ->description('Date to publish on, YYYY-MM-DD, in your local timezone. Leave out to keep the date already on the post.'),
            'time' => $schema->string()
                ->description('Time to publish at, HH:MM 24-hour, in your local timezone.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'uuid' => ['required', 'string'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
        ]);

        $uuid = (string) $request->get('uuid');

        if (! $post = Post::findByUuid($uuid)) {
            return Response::error("No post found with uuid [$uuid].");
        }

        if ($post->isInHistory()) {
            return Response::error('That post has already been published or failed, so it cannot be scheduled again.');
        }

        if ($post->isScheduleProcessing()) {
            return Response::error('That post is already being published.');
        }

        $scheduledAt = $this->resolveScheduledAt($request, $post);

        if (! $scheduledAt) {
            return Response::error('That post has no date and time yet. Pass `date` and `time` to set one.');
        }

        $lead = (int) config('mixpost.mcp.min_schedule_lead_minutes', 10);

        if ($scheduledAt->lt(Carbon::now()->utc()->addMinutes($lead))) {
            return Response::error("Posts scheduled through MCP have to be at least $lead minutes ahead, so there is time to review them before they go out. Pick a later time.");
        }

        $post->setAttribute('scheduled_at', $scheduledAt);

        if (! $post->canSchedule()) {
            return Response::error('That post cannot be scheduled. It needs at least one account attached and a time in the future.');
        }

        $post->setScheduled($scheduledAt);

        return Response::json([
            'uuid' => $post->uuid,
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt->tz(Settings::get('timezone'))->format('Y-m-d H:i'),
            'edit_url' => route('mixpost.posts.edit', ['post' => $post->uuid]),
        ]);
    }

    protected function resolveScheduledAt(Request $request, Post $post): ?Carbon
    {
        if ($request->get('date') && $request->get('time')) {
            return Util::convertTimeToUTC($request->get('date').' '.$request->get('time'));
        }

        return $post->scheduled_at;
    }
}
