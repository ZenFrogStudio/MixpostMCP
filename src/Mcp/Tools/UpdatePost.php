<?php

namespace Inovector\Mixpost\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Inovector\Mixpost\Actions\SavePost;
use Inovector\Mixpost\Mcp\Concerns\BuildsPostPayload;
use Inovector\Mixpost\Models\Post;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Rewrite a draft or scheduled post in Mixpost. This replaces the whole post — accounts, body, media and tags — so send the complete content, not just the parts that change. Posts that have already been published or failed cannot be edited.')]
class UpdatePost extends Tool
{
    use BuildsPostPayload;

    protected string $name = 'update_post';

    public function schema(JsonSchema $schema): array
    {
        return array_merge([
            'uuid' => $schema->string()
                ->required()
                ->description('The post uuid, as returned by list_posts or create_post.'),
        ], $this->postSchema($schema));
    }

    public function handle(Request $request): Response
    {
        $uuid = (string) $request->get('uuid');

        if (! $post = Post::findByUuid($uuid)) {
            return Response::error("No post found with uuid [$uuid].");
        }

        // The same two guards the composer applies: once a post has gone out, or is mid-flight,
        // editing it locally would only put the two out of sync.
        if ($post->isInHistory()) {
            return Response::error('That post has already been published or failed, so it can no longer be edited. Create a new one instead.');
        }

        if ($post->isScheduleProcessing()) {
            return Response::error('That post is being published right now and cannot be edited.');
        }

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

        (new SavePost)(
            post: $post,
            accounts: $accountIds,
            tags: $request->get('tag_ids') ?? [],
            versions: $versions,
            localScheduledAt: $this->scheduledAt($request),
        );

        return Response::json([
            'uuid' => $post->uuid,
            'scheduled_at' => $this->scheduledAt($request),
            'edit_url' => route('mixpost.posts.edit', ['post' => $post->uuid]),
        ]);
    }
}
