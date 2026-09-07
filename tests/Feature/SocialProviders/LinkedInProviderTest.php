<?php

use Illuminate\Support\Facades\Http;
use OneMediaLabs\MixpostMcp\SocialProviders\LinkedIn\LinkedInProvider;

// No catch-all Http::fake() here: stubs match in the order they are registered, so a catch-all set
// up in beforeEach would answer every request before a test's own fake was ever consulted — and it
// would defeat preventStrayRequests() into the bargain. Each test fakes what it calls.
beforeEach(function () {
    Http::preventStrayRequests();

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
        ->and($params['redirect_uri'])->toBe('https://mixpostmcp.test/mixpostmcp/callback/linkedin');
});

it('carries a state parameter and stores it for the callback check', function () {
    $params = queryParams($this->provider->getAuthUrl());

    expect($params['state'])->not->toBeEmpty()
        ->and(session(LinkedInProvider::STATE_SESSION_NAME))->toBe($params['state']);
});

it('refuses a callback whose state does not match', function () {
    $this->provider->getAuthUrl();

    $result = $this->provider->requestAccessToken(['code' => 'a-code', 'state' => 'not-the-state']);

    expect($result)->toHaveKey('error');

    Http::assertNothingSent();
});

it('rejects a video longer than LinkedIn\'s thirty minute maximum', function () {
    $response = $this->provider->publishPost('A long video', collect([
        mediaWithProbe('video/mp4', ['duration' => 2700.0]),
    ]));

    expect($response->hasError())->toBeTrue()
        ->and($response->context()[0])->toContain('45 minutes long')
        ->toContain('at most 30 minutes');

    Http::assertNothingSent();
});

it('rejects a video shorter than LinkedIn\'s three second minimum', function () {
    $response = $this->provider->publishPost('A quick clip', collect([
        mediaWithProbe('video/mp4', ['duration' => 1.5]),
    ]));

    expect($response->hasError())->toBeTrue()
        ->and($response->context()[0])->toContain('1.5s long')
        ->toContain('at least 3s');

    Http::assertNothingSent();
});

it('lets a video inside the bounds reach the upload', function () {
    // An empty body from initializeUpload: enough to prove the request was made, without standing up
    // the whole upload flow.
    Http::fake(['https://api.linkedin.com/rest/videos*' => Http::response()]);

    // The regression that matters: validation that is too strict blocks work that used to succeed.
    $provider = makeProvider(LinkedInProvider::class, 'linkedin', ['provider_id' => 'urn:li:person:abc'])
        ->useAccessToken(['access_token' => 'a-token']);

    $response = $provider->publishPost('A normal video', collect([
        mediaWithProbe('video/mp4', ['duration' => 60.0]),
    ]));

    // The faked initializeUpload answers with an empty body, so the flow stops one step past
    // validation. Getting there is the claim: the video itself was accepted.
    expect($response->context()[0])->toContain('did not return a usable upload URL');
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
    session([LinkedInProvider::STATE_SESSION_NAME => $state]);

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

    session([LinkedInProvider::STATE_SESSION_NAME => 'a-state']);

    $token = $this->provider->requestAccessToken(['code' => 'a-code', 'state' => 'a-state']);

    expect($token)->not->toHaveKey('refresh_token');
});

it('refuses to refresh an account that was never issued a refresh token', function () {
    // Only apps approved for programmatic refresh get one, so this is the common case. Sending a
    // null refresh token would fail on every sweep with nothing telling the user why.
    $this->provider->useAccessToken([
        'access_token' => 'a-token',
        'expires_in' => now('UTC')->timestamp,
    ]);

    $result = $this->provider->refreshAccessToken();

    expect($result['error'])->toContain('Reconnect the account');

    Http::assertNothingSent();
});

it('exchanges a refresh token for a fresh access token', function () {
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'a-fresh-token',
            'expires_in' => 5184000,
            'refresh_token' => 'a-new-refresh-token',
            'refresh_token_expires_in' => 31536000,
        ]),
    ]);

    // updateToken() looks the account up to persist the new token, so the id has to be present.
    $provider = makeProvider(LinkedInProvider::class, 'linkedin', ['account_id' => 0]);

    $provider->useAccessToken([
        'access_token' => 'an-old-token',
        'refresh_token' => 'an-old-refresh-token',
        'expires_in' => now('UTC')->timestamp,
    ]);

    $token = $provider->refreshAccessToken();

    expect($token['access_token'])->toBe('a-fresh-token')
        ->and($token['refresh_token'])->toBe('a-new-refresh-token')
        // Stored as an absolute timestamp, which is the shape tokenIsAboutToExpire() reads.
        ->and($token['expires_in'])->toBeGreaterThan(now()->timestamp);

    Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'an-old-refresh-token'
        && $request['client_id'] === 'test-client-id'
        && $request['client_secret'] === 'test-client-secret');
});

it('keeps the stored refresh token when LinkedIn omits one from a refresh response', function () {
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'a-fresh-token',
            'expires_in' => 5184000,
        ]),
    ]);

    $provider = makeProvider(LinkedInProvider::class, 'linkedin', ['account_id' => 0]);

    $provider->useAccessToken([
        'access_token' => 'an-old-token',
        'refresh_token' => 'the-only-refresh-token',
        'expires_in' => now('UTC')->timestamp,
    ]);

    $token = $provider->refreshAccessToken();

    // buildToken() must leave the key out rather than write a null over it, because updateToken()
    // merges — an overwrite here would break the account on its next refresh.
    expect($token)->not->toHaveKey('refresh_token')
        ->and($provider->getAccessToken()['refresh_token'])->toBe('the-only-refresh-token');
});

it('reports a rejected refresh rather than throwing', function () {
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'The refresh token is expired',
        ], 400),
    ]);

    $provider = makeProvider(LinkedInProvider::class, 'linkedin', ['account_id' => 0]);

    $provider->useAccessToken([
        'access_token' => 'an-old-token',
        'refresh_token' => 'a-dead-refresh-token',
        'expires_in' => now('UTC')->timestamp,
    ]);

    $result = $provider->refreshAccessToken();

    expect($result['error'])->toBe('The refresh token is expired')
        // The old token must survive a failed refresh, so nothing overwrites it with a null.
        ->and($provider->getAccessToken()['access_token'])->toBe('an-old-token');
});
