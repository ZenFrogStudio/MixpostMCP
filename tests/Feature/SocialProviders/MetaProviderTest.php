<?php

use Carbon\Carbon;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Http;
use OneMediaLabs\MixpostMcp\Models\Account;
use OneMediaLabs\MixpostMcp\Models\Audience;
use OneMediaLabs\MixpostMcp\Models\Metric;
use OneMediaLabs\MixpostMcp\Models\Service;
use OneMediaLabs\MixpostMcp\SocialProviders\Meta\FacebookPageProvider;
use OneMediaLabs\MixpostMcp\SocialProviders\Meta\InstagramProvider;
use OneMediaLabs\MixpostMcp\SocialProviders\Meta\Jobs\ImportInstagramFollowersJob;
use OneMediaLabs\MixpostMcp\SocialProviders\Meta\Jobs\ImportInstagramInsightsJob;

// No catch-all Http::fake() here: stubs answer in the order they were registered, so a catch-all
// set up first would shadow every fake a test registers for itself. Each test fakes what it calls.
beforeEach(function () {
    Http::preventStrayRequests();
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

/*
|--------------------------------------------------------------------------
| Instagram audience and insights
|--------------------------------------------------------------------------
*/

function instagramProvider(): InstagramProvider
{
    return makeProvider(InstagramProvider::class, 'instagram', ['provider_id' => '17841400000'])
        ->useAccessToken(['access_token' => 'user-token', 'page_access_token' => 'page-token']);
}

function instagramAccount(): Account
{
    Service::factory()->create([
        'name' => 'facebook',
        'configuration' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret', 'api_version' => 'v25.0'],
        'active' => true,
    ]);

    return Account::factory()->create([
        'provider' => 'instagram',
        'provider_id' => '17841400000',
        'access_token' => ['access_token' => 'user-token', 'page_access_token' => 'page-token'],
    ]);
}

/**
 * captureException() reads the queued job's payload, which a job run straight from handle() does
 * not have. This is the same stand-in Laravel's own dispatchSync() gives a job.
 */
function runInstagramJob(object $job): void
{
    $job->job = new SyncJob(app(), json_encode(['displayName' => $job::class]), 'sync', 'sync');
    $job->handle();
}

function graphError(int $code, string $message = 'Something went wrong'): PromiseInterface
{
    return Http::response(['error' => ['code' => $code, 'message' => $message, 'type' => 'OAuthException']], 400);
}

function graphDailySeries(string $metric, array $valuesByDate): PromiseInterface
{
    $values = [];

    foreach ($valuesByDate as $date => $value) {
        $values[] = ['value' => $value, 'end_time' => "{$date}T07:00:00+0000"];
    }

    return Http::response(['data' => [['name' => $metric, 'period' => 'day', 'values' => $values]]]);
}

it('requests Instagram followers with the Page token', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['followers_count' => 321, 'media_count' => 12, 'id' => '17841400000'])]);

    $response = instagramProvider()->getAudience();

    expect($response->isOk())->toBeTrue()
        ->and($response->context()['followers_count'])->toBe(321);

    Http::assertSent(function (Request $request) {
        $params = queryParams($request->url());

        return str_contains($request->url(), '/17841400000?')
            && $params['fields'] === 'followers_count,media_count'
            && $params['access_token'] === 'page-token';
    });
});

it('drops a retired metric and keeps the rest', function () {
    Http::fake(function (Request $request) {
        return match (queryParams($request->url())['metric']) {
            'reach' => graphDailySeries('reach', ['2026-09-20' => 40, '2026-09-21' => 55]),
            'profile_views' => graphError(100, '(#100) The metric profile_views is no longer supported'),
        };
    });

    $response = instagramProvider()->getInsights();

    expect($response->isOk())->toBeTrue()
        ->and($response->context())->toBe(['reach' => ['2026-09-20' => 40, '2026-09-21' => 55]])
        ->and($response->context())->not->toHaveKey('profile_views');

    Http::assertSentCount(2);
});

it('falls back to the total value for a metric Meta now reports as a total only', function () {
    Http::fake(function (Request $request) {
        $params = queryParams($request->url());

        if ($params['metric'] === 'reach') {
            return graphDailySeries('reach', ['2026-09-21' => 55]);
        }

        if (isset($params['metric_type'])) {
            return Http::response(['data' => [['name' => 'profile_views', 'period' => 'day', 'total_value' => ['value' => 9]]]]);
        }

        return graphError(100, '(#100) profile_views metric requires a metric_type of total_value');
    });

    $response = instagramProvider()->getInsights();
    $yesterday = Carbon::yesterday('UTC')->toDateString();

    expect($response->isOk())->toBeTrue()
        ->and($response->context()['profile_views'])->toBe([$yesterday => 9])
        ->and($response->context()['reach'])->toBe(['2026-09-21' => 55]);

    Http::assertSent(function (Request $request) use ($yesterday) {
        $params = queryParams($request->url());

        return ($params['metric_type'] ?? null) === 'total_value'
            && $params['since'] === $yesterday
            && $params['until'] === Carbon::today('UTC')->toDateString();
    });
});

it('reports an error when every Instagram metric fails', function () {
    Http::fake(['graph.facebook.com/*' => graphError(100)]);

    $response = instagramProvider()->getInsights();

    expect($response->hasError())->toBeTrue();
});

it('imports Instagram followers once per day', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['followers_count' => 321, 'media_count' => 12])]);

    $account = instagramAccount();

    (new ImportInstagramFollowersJob($account))->handle();
    (new ImportInstagramFollowersJob($account))->handle();

    $rows = Audience::account($account->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->total)->toBe(321)
        ->and($rows->first()->date->toDateString())->toBe(Carbon::today('UTC')->toDateString());
});

it('imports Instagram insights as one metric row per date', function () {
    Http::fake(function (Request $request) {
        return match (queryParams($request->url())['metric']) {
            'reach' => graphDailySeries('reach', ['2026-09-20' => 40, '2026-09-21' => 55]),
            'profile_views' => graphDailySeries('profile_views', ['2026-09-21' => 7]),
        };
    });

    $account = instagramAccount();

    (new ImportInstagramInsightsJob($account))->handle();
    (new ImportInstagramInsightsJob($account))->handle();

    $rows = Metric::account($account->id)->orderBy('date')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->date->toDateString())->toBe('2026-09-20')
        ->and($rows[0]->data)->toBe(['reach' => 40])
        ->and($rows[1]->date->toDateString())->toBe('2026-09-21')
        ->and($rows[1]->data)->toBe(['reach' => 55, 'profile_views' => 7]);
});

it('marks an Instagram account unauthorized on an expired token', function () {
    Http::fake(['graph.facebook.com/*' => graphError(190, 'Error validating access token')]);

    $account = instagramAccount();

    runInstagramJob(new ImportInstagramFollowersJob($account));

    expect($account->fresh()->authorized)->toBeFalse()
        ->and(Audience::account($account->id)->count())->toBe(0);
});

it('keeps an Instagram account authorized on any other Graph error', function () {
    Http::fake(['graph.facebook.com/*' => graphError(100)]);

    $account = instagramAccount();

    runInstagramJob(new ImportInstagramFollowersJob($account));

    expect($account->fresh()->authorized)->toBeTrue()
        ->and(Audience::account($account->id)->count())->toBe(0);
});
