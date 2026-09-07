<?php

namespace OneMediaLabs\MixpostMcp\SocialProviders\Meta;

use OneMediaLabs\MixpostMcp\Http\Resources\AccountResource;
use OneMediaLabs\MixpostMcp\SocialProviders\Meta\Concerns\ManagesFacebookOAuth;
use OneMediaLabs\MixpostMcp\SocialProviders\Meta\Concerns\ManagesFacebookPageResources;

class FacebookPageProvider extends MetaProvider
{
    use ManagesFacebookOAuth;
    use ManagesFacebookPageResources;

    public bool $onlyUserAccount = false;

    public static function name(): string
    {
        return 'Facebook';
    }

    protected function accessToken(): string
    {
        return $this->getAccessToken()['page_access_token'];
    }

    public static function externalPostUrl(AccountResource $accountResource): string
    {
        return "https://www.facebook.com/{$accountResource->pivot->provider_post_id}";
    }
}
