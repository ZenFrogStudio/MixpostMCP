<?php

namespace OneMediaLabs\MixpostMcp\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use OneMediaLabs\MixpostMcp\Util;

class PostVersion extends Model
{
    public $table = 'mixpost_post_versions';

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'is_original',
        'content',
        'options',
    ];

    protected $casts = [
        'is_original' => 'boolean',
        'content' => 'array',
        'options' => 'array',
    ];

    public function scopeHasMedia(Builder $query, Media $media): Builder
    {
        if (Util::isMysqlDatabase()) {
            return $query->whereRaw("JSON_SEARCH(content, 'all', ?, NULL, '$[*].media') is not null", [(string) $media->id]);
        }

        // SQLite has no JSON_SEARCH, so walk each content entry's media array. The CAST covers ids
        // stored as numbers as well as strings.
        return $query->whereRaw(
            "EXISTS (SELECT 1 FROM json_each({$this->getTable()}.content) AS v, json_each(v.value, '$.media') AS m WHERE CAST(m.value AS TEXT) = ?)",
            [(string) $media->id]
        );
    }

    public function removeMedia(Media $media): void
    {
        $content = $this->content;

        foreach ($content as $i => $contentData) {
            $content[$i]['media'] = array_values(array_filter($contentData['media'], function ($val) use ($media) {
                return $val != (string) $media->id;
            }));
        }

        $this->content = $content;
        $this->save();
    }
}
