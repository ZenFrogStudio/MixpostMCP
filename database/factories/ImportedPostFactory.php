<?php

namespace OneMediaLabs\MixpostMcp\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use OneMediaLabs\MixpostMcp\Models\Account;
use OneMediaLabs\MixpostMcp\Models\ImportedPost;

class ImportedPostFactory extends Factory
{
    protected $model = ImportedPost::class;

    public function definition()
    {
        return [
            'account_id' => Account::factory()->state([
                'provider' => 'twitter',
            ]),
            'provider_post_id' => Str::random(),
            'content' => ['text' => $this->faker->paragraph()],
            'metrics' => [
                'likes' => $this->faker->randomDigit(),
                'retweets' => $this->faker->randomDigit(),
                'impressions' => $this->faker->randomDigit(),
            ],
            'created_at' => $this->faker->dateTimeBetween('-90 days'),
        ];
    }
}
