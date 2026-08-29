<?php

use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\SocialProviders\LinkedIn\Concerns\ManagesOAuth;
use Inovector\Mixpost\SocialProviders\LinkedIn\LinkedInProvider;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();

    $this->provider = makeProvider(LinkedInProvider::class, 'linkedin');
});

it('builds an authorization URL against LinkedIn', function () {
    $url = $this->provider->getAuthUrl();

    expect($url)->toStartWith('https://www.linkedin.com/oauth/v2/authorization');
});

it('requests the member and organization scopes', function () {
    $scopes = explode(' ', queryParams($this->provider->getAuthUrl())['scope']);

    expect($scopes)->toContain('openid', 'profile', 'email', 'w_member_social')
        // These two need the Community Management API approved on the app; LinkedIn refuses the
        // whole authorization rather than dropping them, which is why README calls it out.
        ->toContain('r_organization_admin', 'w_organization_social');
});

it('sends the callback URL and client id LinkedIn was configured with', function () {
    $params = queryParams($this->provider->getAuthUrl());

    expect($params['response_type'])->toBe('code')
        ->and($params['client_id'])->toBe('test-client-id')
        ->and($params['redirect_uri'])->toBe('https://mixpost.test/mixpost/callback/linkedin');
});

it('carries a state parameter and stores it for the callback check', function () {
    $params = queryParams($this->provider->getAuthUrl());

    expect($params['state'])->not->toBeEmpty()
        ->and(session(ManagesOAuth::STATE_SESSION_NAME))->toBe($params['state']);
});

it('refuses a callback whose state does not match', function () {
    $this->provider->getAuthUrl();

    $result = $this->provider->requestAccessToken(['code' => 'a-code', 'state' => 'not-the-state']);

    expect($result)->toHaveKey('error');

    Http::assertNothingSent();
});

it('returns a well-formed post options schema', function () {
    assertWellFormedPostOptions(LinkedInProvider::postOptions());
});

it('stores token expiry as an absolute timestamp that tokenIsAboutToExpire can read', function () {
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'a-token',
            'expires_in' => 5184000, // 60 days
        ]),
    ]);

    $state = 'a-state';
    session([ManagesOAuth::STATE_SESSION_NAME => $state]);

    $token = $this->provider->requestAccessToken(['code' => 'a-code', 'state' => $state]);

    expect($token['expires_in'])->toBeGreaterThan(now()->timestamp)
        ->and($this->provider->useAccessToken($token)->tokenIsAboutToExpire())->toBeFalse();

    $expiring = ['expires_in' => now('UTC')->addMinutes(5)->timestamp];

    expect($this->provider->useAccessToken($expiring)->tokenIsAboutToExpire())->toBeTrue();
});

it('only stores a refresh token when LinkedIn issues one', function () {
    // Refresh tokens are only granted to apps approved for programmatic refresh, so most installs
    // get a token array with no `refresh_token` key at all.
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'a-token',
            'expires_in' => 5184000,
        ]),
    ]);

    session([ManagesOAuth::STATE_SESSION_NAME => 'a-state']);

    $token = $this->provider->requestAccessToken(['code' => 'a-code', 'state' => 'a-state']);

    expect($token)->not->toHaveKey('refresh_token');
});
