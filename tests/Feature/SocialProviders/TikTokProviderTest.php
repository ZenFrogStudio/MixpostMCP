<?php

use Illuminate\Support\Facades\Http;
use OneMediaLabs\MixpostMcp\SocialProviders\TikTok\TikTokProvider;

beforeEach(function () {
    Http::preventStrayRequests();

    // An empty array turns recording on without registering a catch-all stub. A catch-all would be
    // matched ahead of the specific stubs the tests below register, since the first stub to answer
    // wins.
    Http::fake([]);

    $this->provider = makeProvider(TikTokProvider::class, 'tiktok');
});

it('builds an authorization URL against TikTok', function () {
    $url = $this->provider->getAuthUrl();

    expect($url)->toStartWith('https://www.tiktok.com/v2/auth/authorize/');
});

it('sends the PKCE challenge as S256', function () {
    // The single most breakable parameter in the whole connection: without it TikTok falls back to
    // a plain challenge and the token exchange fails with a bare `invalid_request`.
    $params = queryParams($this->provider->getAuthUrl());

    expect($params['code_challenge_method'])->toBe('S256')
        ->and($params)->toHaveKey('code_challenge')
        ->and($params['code_challenge'])->not->toBeEmpty();
});

it('hex-encodes the PKCE challenge rather than base64url-encoding it', function () {
    // TikTok departs from RFC 7636 here. A base64url challenge is silently rejected later, so pin
    // the encoding: 64 lowercase hex characters, matching the stored verifier's SHA-256 digest.
    $url = $this->provider->getAuthUrl();

    $verifier = session(config('mixpostmcp.cache_prefix').'.tiktok_code_verifier');

    expect(queryParams($url)['code_challenge'])
        ->toMatch('/^[0-9a-f]{64}$/')
        ->toBe(hash('sha256', $verifier));
});

it('names the client parameter client_key, not client_id', function () {
    $params = queryParams($this->provider->getAuthUrl());

    expect($params)->toHaveKey('client_key')
        ->and($params['client_key'])->toBe('test-client-id')
        ->and($params)->not->toHaveKey('client_id');
});

it('requests the scopes needed to publish', function () {
    $scopes = explode(',', queryParams($this->provider->getAuthUrl())['scope']);

    expect($scopes)->toContain('user.info.basic', 'video.publish', 'video.upload');
});

it('carries a state parameter and stores it for the callback check', function () {
    $params = queryParams($this->provider->getAuthUrl());

    expect($params['state'])->not->toBeEmpty()
        ->and(session(config('mixpostmcp.cache_prefix').'.tiktok_oauth_state'))->toBe($params['state']);
});

it('rejects a text-only post without calling TikTok', function () {
    $response = $this->provider->publishPost('Just some words', collect());

    expect($response->hasError())->toBeTrue()
        ->and($response->context()[0])->toContain('must include a video');

    Http::assertNothingSent();
});

it('rejects a video under three seconds and names the minimum', function () {
    $response = $this->provider->publishPost('A quick clip', collect([
        mediaWithProbe('video/mp4', ['duration' => 1.0]),
    ]));

    expect($response->hasError())->toBeTrue()
        ->and($response->context()[0])->toContain('The video is 1s long')
        ->toContain('at least 3s');

    // The floor is TikTok's own, so it costs nothing to find out — not even the creator info call.
    Http::assertNothingSent();
});

it('reads the maximum duration from creator info rather than a constant', function () {
    // The ceiling is per-creator: a verified account may be allowed ten minutes where a new one is
    // allowed one. A hard-coded maximum would reject a video this creator is allowed to post.
    Http::fake([
        '*/post/publish/creator_info/query/' => Http::response([
            'data' => [
                'privacy_level_options' => ['PUBLIC_TO_EVERYONE'],
                'max_video_post_duration_sec' => 60,
            ],
        ]),
    ]);

    $response = $this->provider
        ->useAccessToken(['access_token' => 'a-token'])
        ->publishPost('A long clip', collect([
            mediaWithProbe('video/mp4', ['duration' => 120.0]),
        ]), ['privacy_level' => 'PUBLIC_TO_EVERYONE']);

    expect($response->hasError())->toBeTrue()
        ->and($response->context()[0])->toContain('120 seconds long')
        ->toContain('at most 60 seconds');
});

it('lets a video this creator is allowed to post through the duration checks', function () {
    // The regression that matters: validation that is too strict blocks work that used to succeed.
    Http::fake([
        '*/post/publish/creator_info/query/' => Http::response([
            'data' => [
                'privacy_level_options' => ['PUBLIC_TO_EVERYONE'],
                'max_video_post_duration_sec' => 600,
            ],
        ]),
        '*/post/publish/video/init/' => Http::response(['data' => []]),
    ]);

    $response = $this->provider
        ->useAccessToken(['access_token' => 'a-token'])
        ->publishPost('A normal clip', collect([
            mediaWithProbe('video/mp4', ['duration' => 30.0]),
        ]), ['privacy_level' => 'PUBLIC_TO_EVERYONE']);

    // It got past validation and reached the upload, which is the only claim being made here.
    expect($response->context()[0])->toContain('did not return an upload URL');
});

it('rejects a video format TikTok does not accept', function () {
    $response = $this->provider->publishPost('A clip', collect([
        mediaWithProbe('video/x-msvideo', ['duration' => 10.0], ['name' => 'clip.avi']),
    ]));

    expect($response->hasError())->toBeTrue()
        ->and($response->context()[0])->toContain('MP4, MOV and WEBM');

    Http::assertNothingSent();
});

it('returns a well-formed post options schema', function () {
    assertWellFormedPostOptions(TikTokProvider::postOptions());
});

it('leaves the privacy level unset so the creator has to choose one', function () {
    // TikTok's content-sharing guidelines require the creator to pick the audience themselves.
    $privacy = collect(TikTokProvider::postOptions())->firstWhere('key', 'privacy_level');

    expect($privacy['default'])->toBe('');
});

it('stores token expiry as an absolute timestamp that tokenIsAboutToExpire can read', function () {
    Http::fake([
        '*/oauth/token/*' => Http::response([
            'access_token' => 'a-token',
            'refresh_token' => 'a-refresh-token',
            'expires_in' => 86400,
            'refresh_expires_in' => 31536000,
            'open_id' => 'open-id',
            'scope' => 'video.publish',
        ]),
    ]);

    session([config('mixpostmcp.cache_prefix').'.tiktok_code_verifier' => 'a-verifier']);
    session([config('mixpostmcp.cache_prefix').'.tiktok_oauth_state' => 'a-state']);

    $token = $this->provider->requestAccessToken(['code' => 'a-code', 'state' => 'a-state']);

    // A provider that stored the raw 86400 seconds would compute an expiry in 1970 and refresh on
    // every scheduler tick.
    expect($token['expires_in'])->toBeGreaterThan(now()->timestamp)
        ->and($this->provider->useAccessToken($token)->tokenIsAboutToExpire())->toBeFalse();

    $expiring = ['expires_in' => now('UTC')->addMinutes(5)->timestamp];

    expect($this->provider->useAccessToken($expiring)->tokenIsAboutToExpire())->toBeTrue();
});
