<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Service;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();

    // connect() reads the stored credentials for the provider, so an account with no configured
    // service never reaches its refresh method.
    foreach (['tiktok', 'youtube', 'linkedin'] as $service) {
        Service::factory()->create([
            'name' => $service,
            'configuration' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret'],
            'active' => true,
        ]);
    }
});

/**
 * An account whose token dies in five minutes — inside the ten-minute window
 * tokenIsAboutToExpire() looks ahead by, so the sweep should renew it.
 */
function expiringAccount(string $provider, array $overrides = []): Account
{
    return Account::factory()->create(array_merge([
        'provider' => $provider,
        'authorized' => true,
        'access_token' => [
            'access_token' => 'an-old-token',
            'refresh_token' => 'an-old-refresh-token',
            'expires_in' => now('UTC')->addMinutes(5)->timestamp,
        ],
    ], $overrides));
}

it('renews a token that is about to expire', function () {
    Http::fake([
        'open.tiktokapis.com/*' => Http::response([
            'access_token' => 'a-fresh-token',
            'expires_in' => 86400,
            'refresh_token' => 'a-new-refresh-token',
            'refresh_expires_in' => 31536000,
        ]),
    ]);

    $account = expiringAccount('tiktok');

    $this->artisan('mixpost:refresh-access-tokens')->assertSuccessful();

    expect($account->fresh()->access_token['access_token'])->toBe('a-fresh-token')
        ->and($account->fresh()->isAuthorized())->toBeTrue();
});

it('stores the renewed token so the next publish uses it', function () {
    // The refresh is worthless if it is not persisted: the account would keep presenting the dead
    // token and the sweep would refresh it again every thirty minutes forever.
    Http::fake([
        'open.tiktokapis.com/*' => Http::response([
            'access_token' => 'a-fresh-token',
            'expires_in' => 86400,
            'refresh_token' => 'a-new-refresh-token',
            'refresh_expires_in' => 31536000,
        ]),
    ]);

    $account = expiringAccount('tiktok');

    $this->artisan('mixpost:refresh-access-tokens');

    $token = $account->fresh()->access_token;

    expect($token['refresh_token'])->toBe('a-new-refresh-token')
        ->and($token['expires_in'])->toBeGreaterThan(now('UTC')->addHours(20)->timestamp);
});

it('keeps the stored refresh token when the provider omits one from its response', function () {
    // Google sends no refresh token on a refresh. updateToken() merges rather than replaces, and
    // this is the assertion that catches it if that ever changes — the account would then lose the
    // only refresh token it will ever be issued.
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response([
            'access_token' => 'a-fresh-token',
            'expires_in' => 3599,
        ]),
    ]);

    $account = expiringAccount('youtube', [
        'access_token' => [
            'access_token' => 'an-old-token',
            'refresh_token' => 'the-only-refresh-token',
            'expires_in' => now('UTC')->addMinutes(5)->timestamp,
        ],
    ]);

    $this->artisan('mixpost:refresh-access-tokens');

    $token = $account->fresh()->access_token;

    expect($token['access_token'])->toBe('a-fresh-token')
        ->and($token['refresh_token'])->toBe('the-only-refresh-token');
});

it('leaves a healthy token alone', function () {
    expiringAccount('tiktok', [
        'access_token' => [
            'access_token' => 'a-healthy-token',
            'refresh_token' => 'a-refresh-token',
            'expires_in' => now('UTC')->addHours(12)->timestamp,
        ],
    ]);

    $this->artisan('mixpost:refresh-access-tokens');

    Http::assertNothingSent();
});

it('marks the account unauthorized when the provider refuses the refresh', function () {
    Http::fake([
        'open.tiktokapis.com/*' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Refresh token is invalid or expired',
        ], 400),
    ]);

    $account = expiringAccount('tiktok');

    $this->artisan('mixpost:refresh-access-tokens');

    // The same signal a failed publish raises, so the accounts page shows the Unauthorized badge
    // and the user knows to reconnect.
    expect($account->fresh()->isUnauthorized())->toBeTrue();
});

it('skips accounts already marked unauthorized', function () {
    // Their refresh token is dead. Retrying every thirty minutes spends rate limit and fixes
    // nothing — only reconnecting by hand does.
    expiringAccount('tiktok', ['authorized' => false]);

    $this->artisan('mixpost:refresh-access-tokens');

    Http::assertNothingSent();
});

it('ignores providers that have no refresh flow', function () {
    // Mastodon tokens do not expire and Meta's are exchanged for long-lived ones at connect time,
    // so neither provider has a refreshAccessToken() to call.
    expiringAccount('mastodon');
    expiringAccount('facebook_page');

    $this->artisan('mixpost:refresh-access-tokens')->assertSuccessful();

    Http::assertNothingSent();
});

it('carries on with the sweep when one network is down', function () {
    // The failure this guards against: one provider being unreachable aborting the whole run and
    // letting every other account's token expire.
    Http::fake([
        'oauth2.googleapis.com/*' => fn () => throw new ConnectionException('YouTube is unreachable'),
        'open.tiktokapis.com/*' => Http::response([
            'access_token' => 'a-fresh-token',
            'expires_in' => 86400,
            'refresh_token' => 'a-new-refresh-token',
            'refresh_expires_in' => 31536000,
        ]),
    ]);

    $youtube = expiringAccount('youtube');
    $tiktok = expiringAccount('tiktok');

    $this->artisan('mixpost:refresh-access-tokens')->assertSuccessful();

    expect($tiktok->fresh()->access_token['access_token'])->toBe('a-fresh-token')
        // A network being down is not a dead refresh token, so the account keeps its authorization
        // and is simply retried on the next sweep.
        ->and($youtube->fresh()->isAuthorized())->toBeTrue();
});

it('is registered on the schedule every thirty minutes', function () {
    // Schedule::register() is called by the host application's console kernel, so the test calls
    // it the same way rather than expecting the package to have done it.
    $schedule = app(Illuminate\Console\Scheduling\Schedule::class);

    Inovector\Mixpost\Schedule::register($schedule);

    $events = collect($schedule->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'mixpost:refresh-access-tokens'));

    // Hourly would leave a window where a YouTube token — about an hour long against a ten-minute
    // lookahead — expires between two runs.
    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/30 * * * *');
});
