<?php

namespace OneMediaLabs\MixpostMcp\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use OneMediaLabs\MixpostMcp\Models\Tag;

class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition()
    {
        return [
            'uuid' => $this->faker->uuid(),
            'name' => $this->faker->domainName,
            'hex_color' => Str::after($this->faker->hexColor, '#'),
        ];
    }
}
