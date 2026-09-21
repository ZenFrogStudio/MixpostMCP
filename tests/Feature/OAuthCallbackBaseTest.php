<?php

use OneMediaLabs\MixpostMcp\Facades\SocialProviderManager;
use OneMediaLabs\MixpostMcp\Models\Service;

// Facebook Page is the provider used here because its getAuthUrl() needs no session — the redirect
// is read straight out of the authorization URL's `redirect_uri`.
beforeEach(function () {
    Service::factory()->create([
        'name' => 'facebook',
        'configuration' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret'],
        'active' => true,
    ]);
});

it('uses its own callback route when no callback base is configured', function () {
    config(['mixpostmcp.oauth_callback_base' => null]);

    $params = queryParams(SocialProviderManager::connect('facebook_page')->getAuthUrl());

    expect($params['redirect_uri'])
        ->toBe(route('mixpostmcp.callbackSocialProvider', ['provider' => 'facebook_page']));
});

it('redirects to <base>/<provider>/ when a callback base is configured', function () {
    // A trailing slash on the base must not double up; the one on the provider is deliberate.
    config(['mixpostmcp.oauth_callback_base' => 'https://example.github.io/MixpostMCP/callback/']);

    $params = queryParams(SocialProviderManager::connect('facebook_page')->getAuthUrl());

    expect($params['redirect_uri'])->toBe('https://example.github.io/MixpostMCP/callback/facebook_page/');
});
