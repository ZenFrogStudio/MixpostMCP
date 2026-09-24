<?php

namespace OneMediaLabs\MixpostMcp\SocialProviders\LinkedIn\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use OneMediaLabs\MixpostMcp\Concerns\Job\HasSocialProviderJobRateLimit;
use OneMediaLabs\MixpostMcp\Concerns\Job\SocialProviderException;
use OneMediaLabs\MixpostMcp\Concerns\Job\SocialProviderJobFail;
use OneMediaLabs\MixpostMcp\Concerns\UsesSocialProviderManager;
use OneMediaLabs\MixpostMcp\Models\Account;
use OneMediaLabs\MixpostMcp\Models\Metric;
use OneMediaLabs\MixpostMcp\SocialProviders\LinkedIn\LinkedInProvider;
use OneMediaLabs\MixpostMcp\Support\SocialProviderResponse;

class ProcessLinkedInMetricsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use HasSocialProviderJobRateLimit;
    use SocialProviderException;
    use SocialProviderJobFail;
    use UsesSocialProviderManager;

    public $deleteWhenMissingModels = true;

    public Account $account;

    public function __construct(Account $account)
    {
        $this->account = $account;
    }

    public function handle(): void
    {
        if ($this->account->isUnauthorized()) {
            return;
        }

        if (! $this->account->isServiceActive()) {
            return;
        }

        if ($retryAfter = $this->rateLimitExpiration()) {
            $this->release($retryAfter);

            return;
        }

        /**
         * @see LinkedInProvider
         *
         * @var SocialProviderResponse $response
         */
        $response = $this->connectProvider($this->account)->getMetrics();

        if ($response->isUnauthorized()) {
            $this->account->setUnauthorized();
            $this->captureException($response);

            return;
        }

        if ($response->hasExceededRateLimit()) {
            $this->storeRateLimitExceeded($response->retryAfter(), $response->isAppLevel());
            $this->release($response->retryAfter());

            return;
        }

        if ($response->rateLimitAboutToBeExceeded()) {
            $this->storeRateLimitExceeded($response->retryAfter(), $response->isAppLevel());
        }

        if ($response->hasError()) {
            $this->captureException($response);

            return;
        }

        // Empty for a personal profile, or a page whose token predates the statistics scope.
        if (! $response->context()) {
            return;
        }

        $rows = [];

        foreach ($response->context() as $date => $data) {
            $rows[] = [
                'account_id' => $this->account->id,
                'date' => $date,
                'data' => json_encode($data),
            ];
        }

        Metric::upsert($rows, ['account_id', 'date'], ['data']);
    }
}
