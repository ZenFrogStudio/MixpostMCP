<?php

namespace OneMediaLabs\MixpostMcp\Builders\Filters;

use Illuminate\Database\Eloquent\Builder;
use OneMediaLabs\MixpostMcp\Contracts\Filter;
use OneMediaLabs\MixpostMcp\Enums\PostStatus as PostStatusEnum;

class PostStatus implements Filter
{
    public static function apply(Builder $builder, $value): Builder
    {
        $status = match ($value) {
            'draft' => PostStatusEnum::DRAFT->value,
            'scheduled' => PostStatusEnum::SCHEDULED->value,
            'published' => PostStatusEnum::PUBLISHED->value,
            'failed' => PostStatusEnum::FAILED->value,
            default => null
        };

        if ($status === null) {
            return $builder;
        }

        return $builder->where('status', $status);
    }
}
