<?php

namespace OneMediaLabs\MixpostMcp\SocialProviders\Meta\Jobs;

use Carbon\Carbon;
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
use OneMediaLabs\MixpostMcp\Models\Audience;
use OneMediaLabs\MixpostMcp\SocialProviders\Meta\InstagramProvider;
use OneMediaLabs\MixpostMcp\Support\SocialProviderResponse;

class ImportInstagramFollowersJob implements ShouldQueue
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
        $response = $this->connectProvider($this->account)->getAudience();

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

        // A Carbon date here, not a `Y-m-d` string: the `date` cast saves `Y-m-d 00:00:00`, and on
        // SQLite a bare `Y-m-d` lookup never matches it, so every run would add a fresh row.
        Audience::updateOrCreate([
            'account_id' => $this->account->id,
            'date' => Carbon::today('UTC'),
        ], [
            'total' => $response->context()['followers_count'] ?? 0,
        ]);
    }
}
