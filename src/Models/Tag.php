<?php

namespace OneMediaLabs\MixpostMcp\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OneMediaLabs\MixpostMcp\Concerns\Model\HasUuid;

class Tag extends Model
{
    use HasFactory;
    use HasUuid;

    public $table = 'mixpost_tags';

    protected $fillable = [
        'name',
        'hex_color',
    ];
}
