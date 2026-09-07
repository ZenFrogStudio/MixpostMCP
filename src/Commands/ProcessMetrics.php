<?php

namespace OneMediaLabs\MixpostMcp\Commands;

use Illuminate\Console\Command;
use OneMediaLabs\MixpostMcp\Concerns\AccountsOption;
use OneMediaLabs\MixpostMcp\SocialProviders\Mastodon\Jobs\ProcessMastodonMetricsJob;
use OneMediaLabs\MixpostMcp\SocialProviders\Twitter\Jobs\ProcessTwitterMetricsJob;

class ProcessMetrics extends Command
{
    use AccountsOption;

    public $signature = 'mixpostmcp:process-metrics {--accounts=}';

    public $description = 'Process metrics for the social providers';

    public function handle(): int
    {
        $this->accounts()->each(function ($account) {
            $job = match ($account->provider) {
                'twitter' => ProcessTwitterMetricsJob::class,
                'mastodon' => ProcessMastodonMetricsJob::class,
                default => null,
            };

            if ($job) {
                $job::dispatch($account);
            }
        });

        return self::SUCCESS;
    }
}
