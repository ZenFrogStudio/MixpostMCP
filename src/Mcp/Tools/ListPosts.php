<?php

namespace OneMediaLabs\MixpostMcp\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Str;
use OneMediaLabs\MixpostMcp\Builders\PostQuery;
use OneMediaLabs\MixpostMcp\Facades\Settings;
use OneMediaLabs\MixpostMcp\Http\Resources\AccountResource;
use OneMediaLabs\MixpostMcp\Models\Account;
use OneMediaLabs\MixpostMcp\Models\Post;
use OneMediaLabs\MixpostMcp\Util;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Browse posts in MixpostMCP, newest first. Filter by status, account or keyword. Published posts come back with a link to the post on the network.')]
class ListPosts extends Tool
{
    protected string $name = 'list_posts';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['draft', 'scheduled', 'published', 'failed'])
                ->description('Only return posts in this state.'),
            'account_id' => $schema->integer()
                ->description('Only return posts targeting this account. Ids come from list_accounts.'),
            'keyword' => $schema->string()
                ->description('Only return posts whose body contains this text.'),
            'limit' => $schema->integer()
                ->min(1)
                ->default(15)
                ->description('How many posts to return.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $limit = min(
            (int) $request->get('limit', 15),
            (int) config('mixpostmcp.mcp.max_results', 50)
        );

        // Reuse the same filters the posts index uses, so an agent and the UI agree on what
        // "scheduled" or a keyword match means. PostQuery reads from an HTTP request.
        $filters = new HttpRequest(array_filter([
            'status' => $request->get('status'),
            'accounts' => $request->get('account_id') ? [$request->get('account_id')] : null,
            'keyword' => $request->get('keyword'),
        ]));

        $posts = PostQuery::apply($filters)
            ->latest()
            ->latest('id')
            ->limit($limit)
            ->get();

        return Response::json($posts->map(fn (Post $post): array => [
            'uuid' => $post->uuid,
            'status' => Str::lower($post->status->name),
            'scheduled_at' => $this->localDateTime($post),
            // The shared version, not whichever row happens to load first — an account override
            // could otherwise show up as the excerpt for the whole post.
            'excerpt' => Str::limit(Util::removeHtmlTags($post->versions->firstWhere('is_original', true)?->content[0]['body'] ?? ''), 150),
            'tags' => $post->tags->pluck('name'),
            'accounts' => $post->accounts->map(fn (Account $account): array => array_filter([
                'id' => $account->id,
                'name' => $account->name,
                'provider' => $account->provider,
                'provider_post_id' => $account->pivot->provider_post_id,
                'url' => $this->externalUrl($account),
                'errors' => $account->pivot->errors ? json_decode($account->pivot->errors) : null,
            ], fn ($value): bool => $value !== null)),
        ]));
    }

    protected function localDateTime(Post $post): ?string
    {
        return $post->scheduled_at?->tz(Settings::get('timezone'))->format('Y-m-d H:i');
    }

    /**
     * Each provider formats its own permalink. AccountResource is what those methods expect —
     * it is a thin proxy over the model, and it carries the pivot the URL is built from.
     */
    protected function externalUrl(Account $account): ?string
    {
        if (! $account->pivot->provider_post_id) {
            return null;
        }

        if (! $provider = $account->getProviderClass()) {
            return null;
        }

        return $provider::externalPostUrl(new AccountResource($account));
    }
}
