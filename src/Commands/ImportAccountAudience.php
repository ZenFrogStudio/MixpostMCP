<?php

namespace OneMediaLabs\MixpostMcp\Commands;

use Illuminate\Console\Command;
use OneMediaLabs\MixpostMcp\Concerns\AccountsOption;
use OneMediaLabs\MixpostMcp\SocialProviders\Mastodon\Jobs\ImportMastodonFollowersJob;
use OneMediaLabs\MixpostMcp\SocialProviders\Meta\Jobs\ImportFacebookPageFollowersJob;
use OneMediaLabs\MixpostMcp\SocialProviders\Meta\Jobs\ImportInstagramFollowersJob;
use OneMediaLabs\MixpostMcp\SocialProviders\Twitter\Jobs\ImportTwitterFollowersJob;

class ImportAccountAudience extends Command
{
    use AccountsOption;

    public $signature = 'mixpostmcp:import-account-audience {--accounts=}';

    public $description = 'Import audience(count of followers, fans...etc.) for the social providers';

    public function handle(): int
    {
        $this->accounts()->each(function ($account) {
            $job = match ($account->provider) {
                'twitter' => ImportTwitterFollowersJob::class,
                'facebook_page' => ImportFacebookPageFollowersJob::class,
                'instagram' => ImportInstagramFollowersJob::class,
                'mastodon' => ImportMastodonFollowersJob::class,
                default => null,
            };

            if ($job) {
                $job::dispatch($account);
            }
        });

        return self::SUCCESS;
    }
}
