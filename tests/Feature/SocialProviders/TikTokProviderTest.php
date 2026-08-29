<?php

use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\SocialProviders\TikTok\TikTokProvider;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();

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

    $verifier = session(config('mixpost.cache_prefix').'.tiktok_code_verifier');

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
        ->and(session(config('mixpost.cache_prefix').'.tiktok_oauth_state'))->toBe($params['state']);
});

it('rejects a text-only post without calling TikTok', function () {
    $response = $this->provider->publishPost('Just some words', collect());

    expect($response->hasError())->toBeTrue()
        ->and($response->context()[0])->toContain('must include a video');

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

    session([config('mixpost.cache_prefix').'.tiktok_code_verifier' => 'a-verifier']);
    session([config('mixpost.cache_prefix').'.tiktok_oauth_state' => 'a-state']);

    $token = $this->provider->requestAccessToken(['code' => 'a-code', 'state' => 'a-state']);

    // A provider that stored the raw 86400 seconds would compute an expiry in 1970 and refresh on
    // every scheduler tick.
    expect($token['expires_in'])->toBeGreaterThan(now()->timestamp)
        ->and($this->provider->useAccessToken($token)->tokenIsAboutToExpire())->toBeFalse();

    $expiring = ['expires_in' => now('UTC')->addMinutes(5)->timestamp];

    expect($this->provider->useAccessToken($expiring)->tokenIsAboutToExpire())->toBeTrue();
});
