<?php

namespace Inovector\Mixpost\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inovector\Mixpost\Facades\SocialProviderManager;
use Inovector\Mixpost\Support\SocialProviderResponse;
use InvalidArgumentException;

class StoreProviderEntitiesAsAccounts
{
    public function __invoke(string $provider, array $items)
    {
        $method = 'store'.Str::studly(Str::plural($provider));

        if (method_exists($this, $method)) {
            return $this->$method($items);
        }

        throw new InvalidArgumentException("Provider [$provider] not supported entities.");
    }

    private function storeFacebookPages(array $items): void
    {
        $provider = SocialProviderManager::connect('facebook_page');

        /**
         * Get entities with access token
         *
         * @var SocialProviderResponse $responseEntities
         */
        $responseEntities = $provider->getEntities(withAccessToken: true);

        $entities = Arr::where($responseEntities->context(), function ($entity) use ($items) {
            return in_array($entity['id'], $items);
        });

        foreach ($entities as $account) {
            (new UpdateOrCreateAccount)(
                providerName: 'facebook_page',
                account: $account,
                accessToken: array_merge($provider->getAccessToken(), ['page_access_token' => $account['access_token']['access_token']])
            );
        }
    }

    private function storeInstagrams(array $items): void
    {
        $provider = SocialProviderManager::connect('instagram');

        /**
         * Get entities with access token
         *
         * @var SocialProviderResponse $responseEntities
         */
        $responseEntities = $provider->getEntities(withAccessToken: true);

        $entities = Arr::where($responseEntities->context(), function ($entity) use ($items) {
            return in_array($entity['id'], $items);
        });

        foreach ($entities as $account) {
            (new UpdateOrCreateAccount)(
                providerName: 'instagram',
                account: $account,
                // Instagram Graph calls are authorized with the linked Page's token.
                accessToken: array_merge($provider->getAccessToken(), ['page_access_token' => $account['access_token']['access_token']])
            );
        }
    }

    private function storeYoutubes(array $items): void
    {
        $provider = SocialProviderManager::connect('youtube');

        /** @var SocialProviderResponse $responseEntities */
        $responseEntities = $provider->getEntities();

        $entities = Arr::where($responseEntities->context(), function ($entity) use ($items) {
            return in_array($entity['id'], $items);
        });

        foreach ($entities as $account) {
            (new UpdateOrCreateAccount)(
                providerName: 'youtube',
                account: $account,
                // One Google token covers every channel the account owns, including Brand Accounts,
                // so there is no separate per-channel token to merge in.
                accessToken: $provider->getAccessToken()
            );
        }
    }

    private function storeLinkedins(array $items): void
    {
        $provider = SocialProviderManager::connect('linkedin');

        /** @var SocialProviderResponse $responseEntities */
        $responseEntities = $provider->getEntities();

        $entities = Arr::where($responseEntities->context(), function ($entity) use ($items) {
            return in_array($entity['id'], $items);
        });

        foreach ($entities as $account) {
            (new UpdateOrCreateAccount)(
                providerName: 'linkedin',
                account: $account,
                // The member token authorises posting as the member and as any page they administer,
                // so there is no separate per-entity token to merge in.
                accessToken: $provider->getAccessToken()
            );
        }
    }
}
