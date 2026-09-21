<?php

namespace OneMediaLabs\MixpostMcp;

use OneMediaLabs\MixpostMcp\Abstracts\SocialProviderManager as SocialProviderManagerAbstract;
use OneMediaLabs\MixpostMcp\Facades\ServiceManager;
use OneMediaLabs\MixpostMcp\SocialProviders\LinkedIn\LinkedInProvider;
use OneMediaLabs\MixpostMcp\SocialProviders\Mastodon\MastodonProvider;
use OneMediaLabs\MixpostMcp\SocialProviders\Meta\FacebookPageProvider;
use OneMediaLabs\MixpostMcp\SocialProviders\Meta\InstagramProvider;
use OneMediaLabs\MixpostMcp\SocialProviders\TikTok\TikTokProvider;
use OneMediaLabs\MixpostMcp\SocialProviders\Twitter\TwitterProvider;
use OneMediaLabs\MixpostMcp\SocialProviders\YouTube\YouTubeProvider;

class SocialProviderManager extends SocialProviderManagerAbstract
{
    protected array $providers = [];

    public function providers(): array
    {
        if (! empty($this->providers)) {
            return $this->providers;
        }

        return $this->providers = [
            'twitter' => TwitterProvider::class,
            'facebook_page' => FacebookPageProvider::class,
            'mastodon' => MastodonProvider::class,
            'instagram' => InstagramProvider::class,
            'linkedin' => LinkedInProvider::class,
            'tiktok' => TikTokProvider::class,
            'youtube' => YouTubeProvider::class,
        ];
    }

    /**
     * The URL a network sends the user back to after sign-in.
     *
     * Normally this install's own callback route. With `oauth_callback_base` set it is a fixed HTTPS
     * address that relays to the app instead — the desktop app cannot be reached by the networks.
     * The trailing slash is deliberate: GitHub Pages serves `callback/<provider>/index.html` at exactly
     * that URL, without a redirect that might drop the query string.
     */
    protected function callbackUrl(string $provider): string
    {
        if ($base = $this->config->get('mixpostmcp.oauth_callback_base')) {
            return rtrim($base, '/')."/$provider/";
        }

        return route('mixpostmcp.callbackSocialProvider', ['provider' => $provider]);
    }

    protected function connectTwitterProvider()
    {
        $config = ServiceManager::get('twitter', 'configuration');

        $config['redirect'] = $this->callbackUrl('twitter');

        return $this->buildConnectionProvider(TwitterProvider::class, $config);
    }

    protected function connectFacebookPageProvider()
    {
        $config = ServiceManager::get('facebook', 'configuration');

        $config['redirect'] = $this->callbackUrl('facebook_page');

        return $this->buildConnectionProvider(FacebookPageProvider::class, $config);
    }

    protected function connectInstagramProvider()
    {
        // Instagram authenticates through the same Meta app as Facebook Pages,
        // so it reads the `facebook` service configuration rather than its own.
        $config = ServiceManager::get('facebook', 'configuration');

        $config['redirect'] = $this->callbackUrl('instagram');

        return $this->buildConnectionProvider(InstagramProvider::class, $config);
    }

    protected function connectLinkedinProvider()
    {
        $config = ServiceManager::get('linkedin', 'configuration');

        $config['redirect'] = $this->callbackUrl('linkedin');

        return $this->buildConnectionProvider(LinkedInProvider::class, $config);
    }

    protected function connectTiktokProvider()
    {
        $config = ServiceManager::get('tiktok', 'configuration');

        $config['redirect'] = $this->callbackUrl('tiktok');

        return $this->buildConnectionProvider(TikTokProvider::class, $config);
    }

    protected function connectYoutubeProvider()
    {
        $config = ServiceManager::get('youtube', 'configuration');

        $config['redirect'] = $this->callbackUrl('youtube');

        return $this->buildConnectionProvider(YouTubeProvider::class, $config);
    }

    protected function connectMastodonProvider()
    {
        $request = $this->container->request;
        $sessionServerKey = "{$this->config->get('mixpostmcp.cache_prefix')}.mastodon_server";

        if ($request->route() && $request->route()->getName() === 'mixpostmcp.accounts.add') {
            $serverName = $this->container->request->input('server');
            $request->session()->put($sessionServerKey, $serverName); // We keep the server name in the session. We'll need it in the callback
        } elseif ($request->route() && $request->route()->getName() === 'mixpostmcp.callbackSocialProvider') {
            $serverName = $request->session()->get($sessionServerKey);
        } else {
            $serverName = $this->values['data']['server']; // Get the server value that have been set on SocialProviderManager::connect($provider, array $values = [])
        }

        $config = ServiceManager::get("mastodon.$serverName", 'configuration');

        $config['redirect'] = $this->callbackUrl('mastodon');
        $config['values'] = [
            'data' => ['server' => $serverName],
        ];

        return $this->buildConnectionProvider(MastodonProvider::class, $config);
    }
}
