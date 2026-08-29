<?php

namespace Inovector\Mixpost\SocialProviders\Meta;

use Inovector\Mixpost\Http\Resources\AccountResource;
use Inovector\Mixpost\Services\FacebookService;
use Inovector\Mixpost\SocialProviders\Meta\Concerns\ManagesFacebookOAuth;
use Inovector\Mixpost\SocialProviders\Meta\Concerns\ManagesInstagramResources;
use Inovector\Mixpost\Support\SocialProviderPostConfigs;
use Inovector\Mixpost\Util;

class InstagramProvider extends MetaProvider
{
    use ManagesFacebookOAuth;
    use ManagesInstagramResources;

    public bool $onlyUserAccount = false;

    public static function name(): string
    {
        return 'Instagram';
    }

    // Instagram is authorized through the Facebook app, so it has no service of its own.
    public static function service(): string
    {
        return FacebookService::class;
    }

    // Without this the provider would inherit MetaProvider's Facebook Page limits, which allow
    // 5000 characters and a GIF. Instagram accepts neither.
    public static function postConfigs(): SocialProviderPostConfigs
    {
        return SocialProviderPostConfigs::make()
            ->simultaneousPosting(Util::config('social_provider_options.instagram.simultaneous_posting_on_multiple_accounts'))
            ->minTextChar(0) // Instagram requires media, not text.
            ->minPhotos(1)
            ->minVideos(1)
            ->minGifs(1)
            ->maxTextChar(Util::config('social_provider_options.instagram.post_character_limit'))
            ->maxPhotos(Util::config('social_provider_options.instagram.media_limit.photos'))
            ->maxVideos(Util::config('social_provider_options.instagram.media_limit.videos'))
            ->maxGifs(Util::config('social_provider_options.instagram.media_limit.gifs'))
            ->allowMixingMediaTypes(Util::config('social_provider_options.instagram.media_limit.allow_mixing'));
    }

    public static function postOptions(): array
    {
        return [
            [
                'key' => 'share_to_feed',
                'label' => 'Also show Reels in the profile feed',
                'type' => 'checkbox',
                'default' => true,
            ],
        ];
    }

    public static function externalPostUrl(AccountResource $accountResource): string
    {
        // Publishing returns a media id, and a permalink can only be fetched with an extra
        // Graph call, so there is no reliable URL to build from what we store.
        return '#';
    }
}
