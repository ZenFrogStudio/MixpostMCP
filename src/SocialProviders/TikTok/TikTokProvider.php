<?php

namespace Inovector\Mixpost\SocialProviders\TikTok;

use Inovector\Mixpost\Abstracts\SocialProvider;
use Inovector\Mixpost\Http\Resources\AccountResource;
use Inovector\Mixpost\Services\TikTokService;
use Inovector\Mixpost\SocialProviders\TikTok\Concerns\ManagesOAuth;
use Inovector\Mixpost\SocialProviders\TikTok\Concerns\ManagesRateLimit;
use Inovector\Mixpost\SocialProviders\TikTok\Concerns\ManagesResources;
use Inovector\Mixpost\SocialProviders\TikTok\Concerns\ManagesTikTokVideoUpload;
use Inovector\Mixpost\Support\SocialProviderPostConfigs;
use Inovector\Mixpost\Util;

/**
 * Connects a single TikTok creator account through OAuth 2.0 with PKCE and publishes videos to it
 * with the Content Posting API.
 *
 * The publish path is: query the creator's own posting constraints, validate the post against them,
 * initialise an upload, PUT the file in chunks, then poll until TikTok reports PUBLISH_COMPLETE.
 * Each step lives in a concern: ManagesOAuth (connect), ManagesResources (account, creator info,
 * publish) and ManagesTikTokVideoUpload (init, chunked transfer, polling).
 *
 * This integration is video-only. TikTok's photo endpoint is a separate product with its own
 * approval, and the `tiktok` media limits in config allow 0 photos to match.
 */
class TikTokProvider extends SocialProvider
{
    use ManagesOAuth;
    use ManagesRateLimit;
    use ManagesResources;
    use ManagesTikTokVideoUpload;

    const API_URL = 'https://open.tiktokapis.com/v2';

    /**
     * `state` travels with the code so requestAccessToken() can run the CSRF check. Without it a
     * crafted callback URL could attach a stranger's TikTok account to this install.
     */
    public array $callbackResponseKeys = ['code', 'state'];

    // One authorization grants one creator account, so the entity picker is never involved.
    public bool $onlyUserAccount = true;

    public static function name(): string
    {
        return 'TikTok';
    }

    public static function service(): string
    {
        return TikTokService::class;
    }

    public static function postConfigs(): SocialProviderPostConfigs
    {
        return SocialProviderPostConfigs::make()
            ->simultaneousPosting(Util::config('social_provider_options.tiktok.simultaneous_posting_on_multiple_accounts'))
            ->minTextChar(0) // TikTok requires a video, not text. An untitled video is still publishable.
            ->maxTextChar(Util::config('social_provider_options.tiktok.post_character_limit'))
            ->minPhotos(1)
            ->minVideos(1)
            ->minGifs(1)
            ->maxPhotos(Util::config('social_provider_options.tiktok.media_limit.photos'))
            ->maxVideos(Util::config('social_provider_options.tiktok.media_limit.videos'))
            ->maxGifs(Util::config('social_provider_options.tiktok.media_limit.gifs'))
            ->allowMixingMediaTypes(Util::config('social_provider_options.tiktok.media_limit.allow_mixing'));
    }

    /**
     * The privacy choices here are only a fallback for when creator_info/query cannot be reached.
     * The composer replaces them with the creator's real `privacy_level_options`, because sending a
     * level TikTok did not list for that creator is rejected at publish time.
     *
     * `privacy_level` deliberately has no usable default: TikTok's content-sharing guidelines
     * require the creator to pick the audience themselves, and publishPost() refuses an empty value
     * rather than choosing one for them.
     *
     * @see https://developers.tiktok.com/doc/content-sharing-guidelines
     */
    public static function postOptions(): array
    {
        return [
            [
                'key' => 'privacy_level',
                'label' => 'Who can see this video (required by TikTok)',
                'type' => 'select',
                'choices' => [
                    '' => 'Select a privacy level',
                    'PUBLIC_TO_EVERYONE' => 'Everyone',
                    'MUTUAL_FOLLOW_FRIENDS' => 'Friends',
                    'FOLLOWER_OF_CREATOR' => 'Followers',
                    'SELF_ONLY' => 'Only me',
                ],
                'default' => '',
            ],
            [
                'key' => 'disable_comment',
                'label' => 'Turn off comments',
                'type' => 'checkbox',
                'default' => false,
            ],
            [
                'key' => 'disable_duet',
                'label' => 'Turn off Duet',
                'type' => 'checkbox',
                'default' => false,
            ],
            [
                'key' => 'disable_stitch',
                'label' => 'Turn off Stitch',
                'type' => 'checkbox',
                'default' => false,
            ],
        ];
    }

    public static function externalPostUrl(AccountResource $accountResource): string
    {
        // /v2/user/info/ does not reliably return a handle, so an account connected without one
        // has no URL that resolves. Better a dead link than one pointing at the wrong profile.
        if (! $accountResource->username) {
            return '#';
        }

        return "https://www.tiktok.com/@$accountResource->username/video/{$accountResource->pivot->provider_post_id}";
    }
}
