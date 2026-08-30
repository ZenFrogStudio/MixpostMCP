<?php

use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Http\Resources\AccountResource;
use Inovector\Mixpost\SocialProviders\YouTube\YouTubeProvider;

beforeEach(function () {
    Http::preventStrayRequests();

    // An empty array turns recording on without registering a catch-all stub. A catch-all would be
    // matched ahead of the specific stubs the token tests register, since the first stub to answer
    // wins.
    Http::fake([]);

    $this->provider = makeProvider(YouTubeProvider::class, 'youtube');
});

it('builds an authorization URL against Google', function () {
    $url = $this->provider->getAuthUrl();

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth');
});

it('asks for offline access so Google issues a refresh token', function () {
    // Without access_type=offline Google issues an access token and no refresh token, so the
    // connection dies about an hour later with nothing to renew it from.
    $params = queryParams($this->provider->getAuthUrl());

    expect($params['access_type'])->toBe('offline');
});

it('forces the consent screen so a reconnect also gets a refresh token', function () {
    // Google only sends a refresh token on the *first* consent. Without prompt=consent a user who
    // has authorised before reconnects with no refresh token — a bug that appears only on the
    // second connection and therefore survives casual testing.
    $params = queryParams($this->provider->getAuthUrl());

    expect($params['prompt'])->toBe('consent');
});

it('requests the scopes needed to upload and to list channels', function () {
    $scopes = explode(' ', queryParams($this->provider->getAuthUrl())['scope']);

    expect($scopes)->toContain(
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.readonly'
    );
});

it('carries a state parameter and stores it for the callback check', function () {
    $params = queryParams($this->provider->getAuthUrl());

    expect($params['state'])->not->toBeEmpty()
        ->and(session('mixpost_youtube_oauth_state'))->toBe($params['state']);
});

it('sends the state back through the callback so it can be verified', function () {
    // getCallbackResponse() only forwards the keys named here, so dropping `state` would mean the
    // CSRF check never receives it and every connection is refused.
    expect($this->provider->callbackResponseKeys)->toContain('code', 'state');
});

it('rejects a text-only post without calling YouTube', function () {
    $response = $this->provider->publishPost('Just some words', collect());

    expect($response->hasError())->toBeTrue()
        ->and($response->context()[0])->toContain('must include a video');

    Http::assertNothingSent();
});

it('returns a well-formed post options schema', function () {
    assertWellFormedPostOptions(YouTubeProvider::postOptions());
});

it('defaults privacy to private', function () {
    // An unattended scheduler publishing publicly to the wrong channel is not a recoverable
    // mistake. Making a private video public afterwards is one click.
    $privacy = collect(YouTubeProvider::postOptions())->firstWhere('key', 'privacy_status');

    expect($privacy['default'])->toBe('private');
});

it('splits the first line into the title and the rest into the description', function () {
    [$title, $description] = YouTubeProvider::deriveTitleAndDescription("How I built a shed\nIt took a weekend.\nHere is how.");

    expect($title)->toBe('How I built a shed')
        ->and($description)->toBe("It took a weekend.\nHere is how.");
});

it('lets an explicit title win and keeps the whole body as the description', function () {
    [$title, $description] = YouTubeProvider::deriveTitleAndDescription(
        "How I built a shed\nIt took a weekend.",
        ['title' => 'Shed build, start to finish']
    );

    expect($title)->toBe('Shed build, start to finish')
        ->and($description)->toBe("How I built a shed\nIt took a weekend.");
});

it('truncates a long title on a word boundary', function () {
    $firstLine = str_repeat('shed ', 40); // Well past YouTube's 100 character title limit.

    [$title] = YouTubeProvider::deriveTitleAndDescription($firstLine);

    expect(mb_strlen($title))->toBeLessThanOrEqual(100)
        ->and($title)->toEndWith('shed');
});

it('strips angle brackets from the title and description', function () {
    // YouTube rejects `<` and `>` outright rather than escaping them, and only after the file has
    // already been uploaded.
    [$title, $description] = YouTubeProvider::deriveTitleAndDescription("A <b>bold</b> title\nAnd a <i>body</i>");

    expect($title)->toBe('A bbold/b title')
        ->and($description)->toBe('And a ibody/i');
});

it('stores token expiry as an absolute timestamp that tokenIsAboutToExpire can read', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'a-token',
            'refresh_token' => 'a-refresh-token',
            'expires_in' => 3599,
            'scope' => 'https://www.googleapis.com/auth/youtube.upload',
        ]),
    ]);

    session(['mixpost_youtube_oauth_state' => 'a-state']);

    $token = $this->provider->requestAccessToken(['code' => 'a-code', 'state' => 'a-state']);

    // A provider that stored the raw 3599 seconds would compute an expiry in 1970 and refresh on
    // every scheduler tick.
    expect($token['expires_in'])->toBeGreaterThan(now()->timestamp)
        ->and($token['refresh_token'])->toBe('a-refresh-token')
        ->and($this->provider->useAccessToken($token)->tokenIsAboutToExpire())->toBeFalse();

    $expiring = ['expires_in' => now('UTC')->addMinutes(5)->timestamp];

    expect($this->provider->useAccessToken($expiring)->tokenIsAboutToExpire())->toBeTrue();
});

it('refuses a callback whose state does not match the one it stored', function () {
    session(['mixpost_youtube_oauth_state' => 'the-real-state']);

    $result = $this->provider->requestAccessToken(['code' => 'a-code', 'state' => 'a-forged-state']);

    expect($result)->toHaveKey('error');

    Http::assertNothingSent();
});

it('keeps the stored refresh token when Google omits one from a refresh response', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'a-fresh-token',
            'expires_in' => 3599,
        ]),
    ]);

    // updateToken() looks the account up to persist the new token, so the id has to be present.
    $provider = makeProvider(YouTubeProvider::class, 'youtube', ['account_id' => 0]);

    $provider->useAccessToken([
        'access_token' => 'an-old-token',
        'refresh_token' => 'the-only-refresh-token',
        'expires_in' => now('UTC')->timestamp,
    ]);

    $token = $provider->refreshAccessToken();

    // buildToken() must leave the key out rather than write a null over it, because updateToken()
    // merges — an overwrite here would break the account on its second refresh.
    expect($token)->not->toHaveKey('refresh_token')
        ->and($provider->getAccessToken()['refresh_token'])->toBe('the-only-refresh-token');
});

it('treats a quota breach as a rate limit that retries after the Pacific reset', function () {
    $response = $this->provider->buildResponse(
        httpClientResponse([
            'error' => [
                'code' => 403,
                'message' => 'The request cannot be completed because you have exceeded your quota.',
                'errors' => [['reason' => 'quotaExceeded', 'domain' => 'youtube.quota']],
            ],
        ], 403)
    );

    // Retrying before midnight Pacific only spends the next attempt on the same 403.
    expect($response->hasExceededRateLimit())->toBeTrue()
        ->and($response->retryAfter())->toBeGreaterThan(0)
        ->and($response->retryAfter())->toBeLessThanOrEqual(24 * 60 * 60)
        ->and($response->context()['message'])->toContain('daily API quota');
});

it('reports an expired token as unauthorized rather than a generic error', function () {
    $response = $this->provider->buildResponse(
        httpClientResponse([
            'error' => [
                'code' => 401,
                'message' => 'Invalid Credentials',
                'errors' => [['reason' => 'authError']],
            ],
        ], 401)
    );

    expect($response->isUnauthorized())->toBeTrue();
});

it('builds a watch URL only from something shaped like a video id', function () {
    // The post id is stored data reaching a URL, so anything that is not a video id must not be
    // interpolated into one. JsonResource forwards property reads to whatever it wraps, so a bare
    // object stands in for an account here.
    $withId = fn ($id) => new AccountResource((object) ['pivot' => (object) ['provider_post_id' => $id]]);

    expect(YouTubeProvider::externalPostUrl($withId('dQw4w9WgXcQ')))
        ->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
        ->and(YouTubeProvider::externalPostUrl($withId('not a video id/../etc')))->toBe('#')
        ->and(YouTubeProvider::externalPostUrl($withId(null)))->toBe('#');
});
