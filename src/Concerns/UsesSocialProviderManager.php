<?php

namespace OneMediaLabs\MixpostMcp\Concerns;

use OneMediaLabs\MixpostMcp\Contracts\SocialProvider;
use OneMediaLabs\MixpostMcp\Facades\SocialProviderManager;
use OneMediaLabs\MixpostMcp\Models\Account;

trait UsesSocialProviderManager
{
    public function connectProvider(Account $account): SocialProvider
    {
        return SocialProviderManager::connect($account->provider, $account->values())
            ->useAccessToken($account->access_token->toArray());
    }
}
