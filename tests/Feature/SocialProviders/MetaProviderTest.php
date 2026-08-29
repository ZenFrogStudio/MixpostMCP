<?php

use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\SocialProviders\Meta\FacebookPageProvider;
use Inovector\Mixpost\SocialProviders\Meta\InstagramProvider;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
});

it('builds a Facebook authorization URL', function () {
    $url = makeProvider(FacebookPageProvider::class, 'facebook_page')->getAuthUrl();

    expect($url)->toStartWith('https://www.facebook.com/')
        ->and($url)->toContain('/dialog/oauth');
});

it('requests the Page permissions needed to publish', function () {
    $scopes = explode(',', queryParams(makeProvider(FacebookPageProvider::class, 'facebook_page')->getAuthUrl())['scope']);

    expect($scopes)->toContain('pages_show_list', 'pages_manage_posts', 'pages_read_engagement');
});

it('requests the Instagram permissions on the same Facebook app', function () {
    // Instagram has no app of its own — it authorizes through the Facebook app, so the Instagram
    // permissions have to be on the Facebook consent screen.
    $scopes = explode(',', queryParams(makeProvider(InstagramProvider::class, 'instagram')->getAuthUrl())['scope']);

    expect($scopes)->toContain('instagram_basic', 'instagram_content_publish');
});

it('sends the callback URL Instagram was configured with', function () {
    $params = queryParams(makeProvider(InstagramProvider::class, 'instagram')->getAuthUrl());

    expect($params['response_type'])->toBe('code')
        ->and($params['client_id'])->toBe('test-client-id')
        ->and($params['redirect_uri'])->toBe('https://mixpost.test/mixpost/callback/instagram');
});

it('rejects a text-only Instagram post without calling the Graph API', function () {
    $response = makeProvider(InstagramProvider::class, 'instagram')->publishPost('Just some words', collect());

    expect($response->hasError())->toBeTrue()
        ->and($response->context()[0])->toContain('at least one photo or video');

    Http::assertNothingSent();
});

it('returns a well-formed Instagram post options schema', function () {
    assertWellFormedPostOptions(InstagramProvider::postOptions());
});

it('does not inherit the Facebook Page composer limits', function () {
    // Instagram used to inherit MetaProvider's postConfigs, which allowed 5000 characters and a GIF.
    $instagram = InstagramProvider::postConfigs()->jsonSerialize();
    $facebook = FacebookPageProvider::postConfigs()->jsonSerialize();

    expect($instagram['text_char_limit']['max']['default'])->toBe(2200)
        ->and($instagram['media_limit']['max']['gifs']['default'])->toBe(0)
        ->and($instagram['text_char_limit']['max']['default'])
        ->not->toBe($facebook['text_char_limit']['max']['default']);
});
