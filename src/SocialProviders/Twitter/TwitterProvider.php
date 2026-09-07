<?php

namespace OneMediaLabs\MixpostMcp\SocialProviders\Twitter;

use Abraham\TwitterOAuth\TwitterOAuth;
use Illuminate\Http\Request;
use OneMediaLabs\MixpostMcp\Abstracts\SocialProvider;
use OneMediaLabs\MixpostMcp\Http\Resources\AccountResource;
use OneMediaLabs\MixpostMcp\Services\TwitterService;
use OneMediaLabs\MixpostMcp\SocialProviders\Twitter\Concerns\ManagesOAuth;
use OneMediaLabs\MixpostMcp\SocialProviders\Twitter\Concerns\ManagesRateLimit;
use OneMediaLabs\MixpostMcp\SocialProviders\Twitter\Concerns\ManagesResources;
use OneMediaLabs\MixpostMcp\Support\SocialProviderPostConfigs;
use OneMediaLabs\MixpostMcp\Util;

class TwitterProvider extends SocialProvider
{
    use ManagesOAuth;
    use ManagesRateLimit;
    use ManagesResources;

    public array $callbackResponseKeys = ['oauth_token', 'oauth_verifier'];

    protected string $apiVersion = '2';

    public TwitterOAuth $connection;

    // Overwrite __construct to use Twitter SDK
    public function __construct(Request $request, string $clientId, string $clientSecret, string $redirectUrl, array $values = [])
    {
        $this->connection = new TwitterOAuth($clientId, $clientSecret);
        $this->connection->setApiVersion($this->apiVersion);
        $this->connection->setTimeouts(10, 60);

        parent::__construct($request, $clientId, $clientSecret, $redirectUrl, $values);
    }

    public static function name(): string
    {
        return 'X';
    }

    public static function service(): string
    {
        return TwitterService::class;
    }

    public function getTier(): string
    {
        return self::service()::getConfiguration('tier');
    }

    public static function postConfigs(): SocialProviderPostConfigs
    {
        return SocialProviderPostConfigs::make()
            ->simultaneousPosting(Util::config('social_provider_options.twitter.simultaneous_posting_on_multiple_accounts'))
            ->minTextChar(1)
            ->maxTextChar(Util::config('social_provider_options.twitter.post_character_limit'))
            ->minPhotos(1)
            ->minVideos(1)
            ->minGifs(1)
            ->maxPhotos(Util::config('social_provider_options.twitter.media_limit.photos'))
            ->maxVideos(Util::config('social_provider_options.twitter.media_limit.videos'))
            ->maxGifs(Util::config('social_provider_options.twitter.media_limit.gifs'))
            ->allowMixingMediaTypes(Util::config('social_provider_options.twitter.media_limit.allow_mixing'));
    }

    public static function externalPostUrl(AccountResource $accountResource): string
    {
        return "https://twitter.com/$accountResource->username/status/{$accountResource->pivot->provider_post_id}";
    }
}
