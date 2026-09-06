<?php

namespace Inovector\Mixpost\Actions;

use Illuminate\Support\Facades\DB;
use Inovector\Mixpost\Enums\PostStatus;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Util;

class CreatePost
{
    /**
     * Lives here rather than in the StorePost form request so callers without an HTTP request —
     * the MCP server runs in a console process — can create posts through the same path.
     *
     * $localScheduledAt is a "Y-m-d H:i" string in the timezone from Mixpost's settings, not UTC.
     */
    public function __invoke(array $accounts, array $tags, array $versions, ?string $localScheduledAt = null): Post
    {
        return DB::transaction(function () use ($accounts, $tags, $versions, $localScheduledAt) {
            $record = Post::create([
                'status' => PostStatus::DRAFT,
                'scheduled_at' => $localScheduledAt ? Util::convertTimeToUTC($localScheduledAt) : null,
            ]);

            $record->accounts()->attach($accounts);
            $record->tags()->attach($tags);
            $record->versions()->createMany($versions);

            return $record;
        });
    }
}
