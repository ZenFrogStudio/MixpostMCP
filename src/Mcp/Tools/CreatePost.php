<?php

namespace OneMediaLabs\MixpostMcp\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use OneMediaLabs\MixpostMcp\Actions\CreatePost as CreatePostAction;
use OneMediaLabs\MixpostMcp\Mcp\Concerns\BuildsPostPayload;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Draft a new post in MixpostMCP. It is saved as a draft and nothing is sent to any social network. Give it a date and time to make it schedulable, then call schedule_post to put it in the queue.')]
class CreatePost extends Tool
{
    use BuildsPostPayload;

    protected string $name = 'create_post';

    public function schema(JsonSchema $schema): array
    {
        return $this->postSchema($schema);
    }

    public function handle(Request $request): Response
    {
        $accountIds = $request->get('account_ids') ?? [];

        if ($unknown = $this->unknownAccountIds($accountIds)) {
            return Response::error('No account exists with id '.implode(', ', $unknown).'. Call list_accounts for the ids you can use.');
        }

        $versions = $this->buildVersions($request);

        $this->validatePayload([
            'accounts' => $accountIds,
            'tags' => $request->get('tag_ids') ?? [],
            'versions' => $versions,
            'date' => $request->get('date'),
            'time' => $request->get('time'),
        ]);

        if ($errors = $this->characterLimitErrors($accountIds, $versions)) {
            return Response::error(implode("\n", $errors));
        }

        $post = (new CreatePostAction)(
            accounts: $accountIds,
            tags: $request->get('tag_ids') ?? [],
            versions: $versions,
            localScheduledAt: $this->scheduledAt($request),
        );

        return Response::json([
            'uuid' => $post->uuid,
            'status' => 'draft',
            'scheduled_at' => $this->scheduledAt($request),
            'edit_url' => route('mixpostmcp.posts.edit', ['post' => $post->uuid]),
            'next_step' => $this->scheduledAt($request)
                ? 'Call schedule_post with this uuid to put it in the queue.'
                : 'This post has no date and time yet. Call update_post or schedule_post with a date and time before it can be queued.',
        ]);
    }
}
