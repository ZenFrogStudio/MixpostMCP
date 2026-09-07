<?php

namespace OneMediaLabs\MixpostMcp\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OneMediaLabs\MixpostMcp\Enums\FacebookInsightType;

class FacebookInsight extends Model
{
    use HasFactory;

    public $table = 'mixpost_facebook_insights';

    protected $fillable = [
        'account_id',
        'type',
        'value',
        'date',
    ];

    protected $casts = [
        'type' => FacebookInsightType::class,
        'date' => 'date',
    ];

    public function scopeAccount($query, int $accountId)
    {
        $query->where('account_id', $accountId);
    }
}
