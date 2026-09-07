<?php

namespace OneMediaLabs\MixpostMcp\Builders\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use OneMediaLabs\MixpostMcp\Contracts\Filter;

class PostTags implements Filter
{
    public static function apply(Builder $builder, $value): Builder
    {
        return $builder->whereHas('tags', function ($query) use ($value) {
            $query->whereIn('tag_id', Arr::wrap($value));
        });
    }
}
