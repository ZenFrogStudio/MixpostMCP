<?php

namespace OneMediaLabs\MixpostMcp\SocialProviders\Meta\Jobs;

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
use OneMediaLabs\MixpostMcp\SocialProviders\Meta\InstagramProvider;
use OneMediaLabs\MixpostMcp\Support\SocialProviderResponse;

class ImportInstagramInsightsJob implements ShouldQueue
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
         * @see InstagramProvider
         *
         * @var SocialProviderResponse $response
         */
        $response = $this->connectProvider($this->account)->getInsights();

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

        // The provider returns one date map per metric; a Metric row holds every metric for one date.
        $byDate = [];

        foreach ($response->context() as $metric => $values) {
            foreach ($values as $date => $value) {
                $byDate[$date][$metric] = $value;
            }
        }

        if (! $byDate) {
            return;
        }

        // A metric that only comes as a total covers yesterday alone, while the others cover 30 days.
        // Merging into what is already stored keeps yesterday's total from being wiped tomorrow.
        $existing = Metric::account($this->account->id)
            ->whereIn('date', array_keys($byDate))
            ->get()
            ->keyBy(fn (Metric $metric) => $metric->date->toDateString());

        $rows = [];

        foreach ($byDate as $date => $data) {
            $rows[] = [
                'account_id' => $this->account->id,
                'date' => $date,
                'data' => json_encode(array_merge($existing[$date]->data ?? [], $data)),
            ];
        }

        Metric::upsert($rows, ['account_id', 'date'], ['data']);
    }
}
