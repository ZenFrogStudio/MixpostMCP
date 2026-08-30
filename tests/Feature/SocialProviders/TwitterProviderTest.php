<?php

use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\SocialProviders\Twitter\TwitterProvider;

/**
 * There is no getAuthUrl() test here on purpose. TwitterProvider drives the twitteroauth SDK, which
 * makes a live `oauth/request_token` call over cURL — Http::fake() cannot intercept it, so the test
 * would need real X credentials. Everything reachable without the SDK is covered below.
 */
it('reports its display name as X', function () {
    expect(TwitterProvider::name())->toBe('X');
});

it('returns a well-formed post options schema', function () {
    assertWellFormedPostOptions(TwitterProvider::postOptions());
});

it('rejects a GIF over X\'s fifteen megabyte limit, naming the limit', function () {
    // X only says so after the whole file has been sent chunk by chunk, and `max_file_size.gif` is
    // one operator setting covering every network — a GIF can be in the library and still be too
    // big for X.
    $gif = Media::factory()->create([
        'name' => 'loop.gif',
        'mime_type' => 'image/gif',
        'size' => 20 * 1024 * 1024,
    ]);

    $response = makeProvider(TwitterProvider::class, 'twitter')->publishPost('A big loop', collect([$gif]));

    expect($response->hasError())->toBeTrue()
        ->and($response->context()[0])->toContain('is 20 MB')
        ->toContain("X's limit is 15 MB");
});

it('applies the configured composer limits', function () {
    $configs = TwitterProvider::postConfigs()->jsonSerialize();

    expect($configs['text_char_limit']['max']['default'])->toBe(280)
        ->and($configs['media_limit']['max']['photos']['default'])->toBe(4)
        ->and($configs['media_limit']['max']['videos']['default'])->toBe(1)
        ->and($configs['media_limit']['max']['gifs']['default'])->toBe(1);
});

it('reads allow_mixing from the media_limit block it is declared in', function () {
    // The path used to be `social_provider_options.twitter.allow_mixing`, which does not exist —
    // the value silently fell back to the built-in default, so editing the published config did
    // nothing.
    config()->set('mixpost.social_provider_options.twitter.media_limit.allow_mixing', true);

    expect(TwitterProvider::postConfigs()->jsonSerialize()['media_limit']['max']['allow_mixing']['default'])
        ->toBeTrue();
});

it('defaults to the pay-as-you-go tier', function () {
    // The tier decides whether publishing goes through API v1.1 or v2, so a wrong default breaks
    // posting rather than degrading it.
    expect(makeProvider(TwitterProvider::class, 'twitter')->getTier())->toBe('pay_as_you_go');
});
