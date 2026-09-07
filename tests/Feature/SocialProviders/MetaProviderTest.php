<?php

use Illuminate\Support\Facades\Http;
use OneMediaLabs\MixpostMcp\SocialProviders\Meta\FacebookPageProvider;
use OneMediaLabs\MixpostMcp\SocialProviders\Meta\InstagramProvider;

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
        ->and($params['redirect_uri'])->toBe('https://mixpostmcp.test/mixpostmcp/callback/instagram');
});

it('rejects a text-only Instagram post without calling the Graph API', function () {
    $response = makeProvider(InstagramProvider::class, 'instagram')->publishPost('Just some words', collect());

    expect($response->hasError())->toBeTrue()
        ->and($response->context()[0])->toContain('at least one photo or video');

    Http::assertNothingSent();
});

it('rejects a feed image outside Instagram\'s aspect ratio bounds and names the bound', function () {
    $response = makeProvider(InstagramProvider::class, 'instagram')->publishPost('A tall photo', collect([
        mediaWithProbe('image/jpeg', ['width' => 600, 'height' => 1200], ['name' => 'tall.jpg']),
    ]));

    expect($response->hasError())->toBeTrue()
        ->and($response->context()[0])->toContain('600×1200')
        ->toContain('0.5:1')
        ->toContain('0.8:1 (4:5 portrait)');

    Http::assertNothingSent();
});

it('rejects a video longer than a Reel can be', function () {
    $response = makeProvider(InstagramProvider::class, 'instagram')->publishPost('A long video', collect([
        mediaWithProbe('video/mp4', ['duration' => 1200.0], ['name' => 'long.mp4']),
    ]));

    expect($response->hasError())->toBeTrue()
        ->and($response->context()[0])->toContain('runs 20 minutes')
        ->toContain('capped at 15 minutes');

    Http::assertNothingSent();
});

it('rejects a file format Instagram does not accept', function () {
    $response = makeProvider(InstagramProvider::class, 'instagram')->publishPost('An animation', collect([
        mediaWithProbe('image/gif', ['width' => 1080, 'height' => 1080], ['name' => 'loop.gif']),
    ]));

    expect($response->hasError())->toBeTrue()
        ->and($response->context()[0])->toContain('JPEG and PNG images');

    Http::assertNothingSent();
});

it('lets a square feed image past the media rules', function () {
    // The regression that matters: validation that is too strict blocks work that used to succeed.
    $response = makeProvider(InstagramProvider::class, 'instagram')->publishPost('A photo', collect([
        mediaWithProbe('image/jpeg', ['width' => 1080, 'height' => 1080], ['name' => 'square.jpg', 'path' => 'square.jpg']),
    ]));

    // Instagram downloads media by URL and a test disk has no public host, so the next check along
    // is the one that stops it. Reaching that check is the claim here: the file itself was accepted.
    expect($response->context()[0])->toContain('reachable from the public internet');
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
