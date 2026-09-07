<?php

namespace OneMediaLabs\MixpostMcp\Actions;

use Illuminate\Support\Facades\DB;
use OneMediaLabs\MixpostMcp\Models\Post;
use OneMediaLabs\MixpostMcp\Util;

class SavePost
{
    /**
     * The update counterpart of CreatePost. Extracted from the UpdatePost form request for the
     * same reason: the MCP server has no HTTP request to build a form request from.
     *
     * $localScheduledAt is a "Y-m-d H:i" string in the timezone from MixpostMCP's settings, not UTC.
     */
    public function __invoke(Post $post, array $accounts, array $tags, array $versions, ?string $localScheduledAt = null): void
    {
        DB::transaction(function () use ($post, $accounts, $tags, $versions, $localScheduledAt) {
            if (empty($accounts) || ! $localScheduledAt) {
                $post->setDraft();
            }

            $post->accounts()->sync($accounts);
            $post->tags()->sync($tags);

            $post->versions()->delete();
            $post->versions()->createMany($versions);

            $post->setScheduled(
                datetime: $localScheduledAt ? Util::convertTimeToUTC($localScheduledAt) : null,
                status: null,
            );
        });
    }
}
