<?php

namespace OneMediaLabs\MixpostMcp\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use OneMediaLabs\MixpostMcp\Concerns\AccountsOption;
use OneMediaLabs\MixpostMcp\Concerns\UsesSocialProviderManager;
use OneMediaLabs\MixpostMcp\Models\Account;
use OneMediaLabs\MixpostMcp\Support\Log;
use Throwable;

/**
 * Renews access tokens before they expire.
 *
 * A TikTok token lives 24 hours and a YouTube one about an hour, so a post scheduled today fires
 * against a dead token tomorrow unless something renews it in the background. That something is
 * this command, run every thirty minutes by Schedule.
 */
class RefreshAccessTokens extends Command
{
    use AccountsOption;
    use UsesSocialProviderManager;

    public $signature = 'mixpostmcp:refresh-access-tokens {--accounts=}';

    public $description = 'Refresh access tokens that are about to expire';

    public function handle(): int
    {
        $this->accounts()->each(function (Account $account) {
            // Each account gets its own try/catch: one network being down must not abort the sweep
            // and leave every other account's token to expire.
            try {
                $this->refresh($account);
            } catch (Throwable $exception) {
                Log::error("[$account->provider] Failed to refresh the access token", [
                    'account_id' => $account->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        });

        return self::SUCCESS;
    }

    protected function refresh(Account $account): void
    {
        // An account already marked unauthorized has a dead refresh token. Retrying it every half
        // hour achieves nothing but rate limit consumption — it has to be reconnected by hand.
        if ($account->isUnauthorized()) {
            return;
        }

        $providerClass = $account->getProviderClass();

        // Mastodon tokens do not expire and Meta's are exchanged for long-lived ones at connect
        // time, so those providers have no refreshAccessToken() to call. Asking the class rather
        // than checking a hard-coded list means a future network is picked up on its own — and it
        // skips them before the connection is built, so no service lookup happens for nothing.
        if (! $providerClass || ! method_exists($providerClass, 'refreshAccessToken')) {
            return;
        }

        $provider = $this->connectProvider($account);

        if (! $provider->tokenIsAboutToExpire()) {
            return;
        }

        $token = $provider->refreshAccessToken();

        if ($error = Arr::get($token, 'error')) {
            // The same signal AccountPublishPostJob uses, so the existing "Unauthorized" badge on
            // the accounts page lights up and the user knows to reconnect.
            $account->setUnauthorized();

            Log::error("[$account->provider] The access token could not be refreshed", [
                'account_id' => $account->id,
                'message' => $error,
            ]);

            return;
        }

        // Providers persist the new token themselves, so this is normally a no-op merge. It stays
        // because the command must not depend on that — a provider that only returned its token
        // would otherwise refresh forever and never save the result.
        $provider->updateToken($token);

        Log::info("[$account->provider] Access token refreshed", ['account_id' => $account->id]);
    }
}
