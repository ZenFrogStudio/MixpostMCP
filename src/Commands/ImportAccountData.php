<?php

namespace OneMediaLabs\MixpostMcp\Commands;

use Illuminate\Console\Command;
use OneMediaLabs\MixpostMcp\Concerns\AccountsOption;
use OneMediaLabs\MixpostMcp\SocialProviders\Mastodon\Jobs\ImportMastodonPostsJob;
use OneMediaLabs\MixpostMcp\SocialProviders\Meta\Jobs\ImportFacebookInsightsJob;
use OneMediaLabs\MixpostMcp\SocialProviders\Twitter\Jobs\ImportTwitterPostsJob;

class ImportAccountData extends Command
{
    use AccountsOption;

    public $signature = 'mixpostmcp:import-account-data {--accounts=}';

    public $description = 'Import data from social service providers';

    public function handle(): int
    {
        $this->accounts()->each(function ($account) {
            $job = match ($account->provider) {
                'twitter' => ImportTwitterPostsJob::class,
                'facebook_page' => ImportFacebookInsightsJob::class,
                'mastodon' => ImportMastodonPostsJob::class,
                default => null,
            };

            if ($job) {
                $job::dispatch($account);
            }
        });

        return self::SUCCESS;
    }
}
