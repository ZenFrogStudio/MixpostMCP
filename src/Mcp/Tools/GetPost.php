<?php

namespace Inovector\Mixpost\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inovector\Mixpost\Facades\Settings;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Models\PostVersion;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Read one Mixpost post in full: every version, the attached media, the per-network options and any errors the networks returned.')]
class GetPost extends Tool
{
    protected string $name = 'get_post';

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()
                ->required()
                ->description('The post uuid, as returned by list_posts or create_post.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $uuid = (string) $request->get('uuid');

        if (! $post = Post::findByUuid($uuid)) {
            return Response::error("No post found with uuid [$uuid].");
        }

        $post->load('accounts', 'versions', 'tags');

        return Response::json([
            'uuid' => $post->uuid,
            'status' => Str::lower($post->status->name),
            'scheduled_at' => $post->scheduled_at?->tz(Settings::get('timezone'))->format('Y-m-d H:i'),
            'published_at' => $post->published_at?->tz(Settings::get('timezone'))->format('Y-m-d H:i'),
            'tags' => $post->tags->map(fn ($tag): array => ['id' => $tag->id, 'name' => $tag->name]),
            'accounts' => $post->accounts->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'provider' => $account->provider,
                'provider_post_id' => $account->pivot->provider_post_id,
                'errors' => $account->pivot->errors ? json_decode($account->pivot->errors) : [],
            ]),
            'versions' => $post->versions->map(fn (PostVersion $version): array => [
                // account_id 0 is the shared version every account falls back to. Any other value
                // is an override that replaces it for that one account.
                'account_id' => $version->account_id,
                'is_original' => $version->is_original,
                'content' => $version->content,
                'options' => (object) ($version->options ?? []),
            ]),
            'media' => $this->media($post),
        ]);
    }

    protected function media(Post $post)
    {
        $ids = collect($post->versions)
            ->flatMap(fn (PostVersion $version): array => Arr::flatten(Arr::pluck($version->content, 'media')))
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Media::whereIn('id', $ids)->get()->map(fn (Media $item): array => [
            'id' => $item->id,
            'name' => $item->name,
            'type' => $item->type(),
            'url' => $item->getUrl(),
        ]);
    }
}
