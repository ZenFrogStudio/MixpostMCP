<?php

namespace OneMediaLabs\MixpostMcp\SocialProviders\LinkedIn;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OneMediaLabs\MixpostMcp\Abstracts\SocialProvider;
use OneMediaLabs\MixpostMcp\Http\Resources\AccountResource;
use OneMediaLabs\MixpostMcp\Services\LinkedInService;
use OneMediaLabs\MixpostMcp\SocialProviders\LinkedIn\Concerns\ManagesLinkedInMedia;
use OneMediaLabs\MixpostMcp\SocialProviders\LinkedIn\Concerns\ManagesOAuth;
use OneMediaLabs\MixpostMcp\SocialProviders\LinkedIn\Concerns\ManagesRateLimit;
use OneMediaLabs\MixpostMcp\SocialProviders\LinkedIn\Concerns\ManagesResources;
use OneMediaLabs\MixpostMcp\Support\SocialProviderPostConfigs;
use OneMediaLabs\MixpostMcp\Util;

class LinkedInProvider extends SocialProvider
{
    use ManagesLinkedInMedia;
    use ManagesOAuth;
    use ManagesRateLimit;
    use ManagesResources;

    /**
     * LinkedIn's REST API is versioned by month and the version travels in a request header.
     * Pinning it here means an upstream release never quietly changes how publishing behaves on a
     * running install — bump it deliberately after reading LinkedIn's migration notes.
     *
     * @see https://learn.microsoft.com/en-us/linkedin/marketing/versioning
     */
    const API_VERSION = '202608';

    // `state` is here so it reaches requestAccessToken(), which is where the CSRF check runs.
    // Both the direct callback and the entity picker route the callback response through that method.
    public array $callbackResponseKeys = ['code', 'state'];

    // A member can post as themselves and as any company page they administer, so the callback
    // has to go through the entity picker rather than straight to account creation.
    public bool $onlyUserAccount = false;

    protected string $apiUrl = 'https://api.linkedin.com';

    public static function name(): string
    {
        return 'LinkedIn';
    }

    public static function service(): string
    {
        return LinkedInService::class;
    }

    public static function postConfigs(): SocialProviderPostConfigs
    {
        return SocialProviderPostConfigs::make()
            ->simultaneousPosting(Util::config('social_provider_options.linkedin.simultaneous_posting_on_multiple_accounts'))
            ->minTextChar(1)
            ->maxTextChar(Util::config('social_provider_options.linkedin.post_character_limit'))
            ->minPhotos(1)
            ->minVideos(1)
            ->minGifs(1)
            ->maxPhotos(Util::config('social_provider_options.linkedin.media_limit.photos'))
            ->maxVideos(Util::config('social_provider_options.linkedin.media_limit.videos'))
            ->maxGifs(Util::config('social_provider_options.linkedin.media_limit.gifs'))
            ->allowMixingMediaTypes(Util::config('social_provider_options.linkedin.media_limit.allow_mixing'));
    }

    public static function postOptions(): array
    {
        return [
            [
                'key' => 'visibility',
                // A company page can only post publicly, so the choice only bites for personal
                // profiles. publishPost() coerces it for pages rather than the composer hiding the
                // field, because options are declared once per provider, not per account.
                'label' => 'Visibility (company pages always post publicly)',
                'type' => 'select',
                'choices' => [
                    'PUBLIC' => 'Anyone',
                    'CONNECTIONS' => 'Connections only',
                ],
                'default' => 'PUBLIC',
            ],
        ];
    }

    public static function externalPostUrl(AccountResource $accountResource): string
    {
        $id = $accountResource->pivot->provider_post_id;

        if (! static::isPostUrn($id)) {
            return '#';
        }

        // isPostUrn() is what makes this safe to interpolate: only a fixed URN shape reaches the
        // URL. LinkedIn's own canonical links keep the colons literal, so we do too.
        return "https://www.linkedin.com/feed/update/$id";
    }

    /**
     * Publishing returns a URN such as `urn:li:share:7012345678901234567`. Anything else is not
     * something LinkedIn can resolve, so it must never reach a URL we build.
     */
    protected static function isPostUrn(mixed $id): bool
    {
        return is_string($id) && (bool) preg_match('/^urn:li:(share|ugcPost|activity):\d+$/', $id);
    }

    // Every versioned REST call carries the same three headers, so a version bump stays one edit.
    protected function restRequest(): PendingRequest
    {
        return Http::withToken($this->getAccessToken()['access_token'])
            ->withHeaders([
                'LinkedIn-Version' => self::API_VERSION,
                'X-Restli-Protocol-Version' => '2.0.0',
            ]);
    }

    // The author of every post is the URN stored when the account was connected. It reads exactly
    // the same whether the account is a member or a company page.
    protected function authorUrn(): string
    {
        return (string) Arr::get($this->values, 'provider_id', '');
    }

    // The account id we store is the URN. LinkedIn's REST endpoints want the bare id, so unwrap it.
    protected function entityId(): string
    {
        return (string) Str::afterLast($this->authorUrn(), ':');
    }

    protected function isOrganization(): bool
    {
        return Arr::get($this->values, 'data.type') === 'organization';
    }
}
