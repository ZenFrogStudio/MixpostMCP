<?php

namespace OneMediaLabs\MixpostMcp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use OneMediaLabs\MixpostMcp\Models\Tag;
use OneMediaLabs\MixpostMcp\Rules\HexRule;

class StoreTag extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'hex_color' => ['required', new HexRule],
        ];
    }

    public function handle()
    {
        return Tag::create([
            'name' => $this->input('name'),
            'hex_color' => Str::after($this->input('hex_color'), '#'),
        ]);
    }
}
