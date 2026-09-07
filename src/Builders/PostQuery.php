<?php

namespace OneMediaLabs\MixpostMcp\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use OneMediaLabs\MixpostMcp\Builders\Filters\ExcludePostStatus;
use OneMediaLabs\MixpostMcp\Builders\Filters\PostAccounts;
use OneMediaLabs\MixpostMcp\Builders\Filters\PostKeyword;
use OneMediaLabs\MixpostMcp\Builders\Filters\PostScheduledAt;
use OneMediaLabs\MixpostMcp\Builders\Filters\PostStatus;
use OneMediaLabs\MixpostMcp\Builders\Filters\PostTags;
use OneMediaLabs\MixpostMcp\Contracts\Query;
use OneMediaLabs\MixpostMcp\Models\Post;

class PostQuery implements Query
{
    public static function apply(Request $request): Builder
    {
        $query = Post::with('accounts', 'versions', 'tags');

        if ($request->has('status') && $request->get('status') !== null) {
            $query = PostStatus::apply($query, $request->get('status'));
        }

        if ($request->has('exclude_status') && $request->get('exclude_status')) {
            $query = ExcludePostStatus::apply($query, $request->get('exclude_status'));
        }

        if ($request->has('keyword') && $request->get('keyword')) {
            $query = PostKeyword::apply($query, $request->get('keyword'));
        }

        if ($request->has('accounts') && ! empty($request->get('accounts'))) {
            $query = PostAccounts::apply($query, $request->get('accounts', []));
        }

        if ($request->has('tags') && ! empty($request->get('tags'))) {
            $query = PostTags::apply($query, $request->get('tags', []));
        }

        if ($request->has('date') && ! empty($request->get('date')) && preg_match('/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $request->get('date'))) {
            $query = PostScheduledAt::apply($query, $request->only('calendar_type', 'date'));
        }

        return $query;
    }
}
