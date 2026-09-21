<?php

namespace OneMediaLabs\MixpostMcp\Builders\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use OneMediaLabs\MixpostMcp\Contracts\Filter;
use OneMediaLabs\MixpostMcp\Util;

class PostKeyword implements Filter
{
    public static function apply(Builder $builder, $value): Builder
    {
        return $builder->whereHas('versions', function ($query) use ($value) {
            $keyword = '%'.Str::lower($value).'%';

            // MySQL's `$[*]` wildcard pulls every body out in one go. SQLite has no wildcard, so it
            // walks the content array with json_each() and checks each body instead.
            if (Util::isMysqlDatabase()) {
                return $query->whereRaw("LOWER(JSON_EXTRACT(content, '$[*].body')) LIKE ?", [$keyword]);
            }

            $table = $query->getModel()->getTable();

            return $query->whereRaw(
                "EXISTS (SELECT 1 FROM json_each($table.content) AS v WHERE LOWER(json_extract(v.value, '$.body')) LIKE ?)",
                [$keyword]
            );
        });
    }
}
