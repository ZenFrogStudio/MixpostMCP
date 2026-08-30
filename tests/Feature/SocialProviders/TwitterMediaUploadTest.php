<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\SocialProviders\Twitter\TwitterProvider;

/**
 * Media upload against X's v2 chunked endpoint.
 *
 * These fake the HTTP layer, which is only possible because uploads no longer go through the
 * twitteroauth SDK — the SDK calls cURL directly and Http::fake() cannot see it. Everything here
 * asserts on the wire format, since a wrong field name is exactly how the v1.1 path broke.
 */
beforeEach(fn () => Storage::fake('public'));

function twitterProvider(): TwitterProvider
{
    return makeProvider(TwitterProvider::class, 'twitter')
        ->useAccessToken(['oauth_token' => 'a-token', 'oauth_token_secret' => 'a-token-secret']);
}

function fakeMedia(string $contents = 'file contents', string $mimeType = 'image/jpeg'): Media
{
    // Hashed so two items in one post do not overwrite each other's file.
    $path = 'media/'.md5($contents.$mimeType);

    Storage::disk('public')->put($path, $contents);

    return Media::factory()->make([
        'name' => 'upload-me',
        'mime_type' => $mimeType,
        'disk' => 'public',
        'path' => $path,
        'size' => strlen($contents),
    ]);
}

/**
 * The value of one field in a multipart request body.
 */
function multipartField(Request $request, string $name): ?string
{
    $pattern = '/name="'.preg_quote($name, '/').'".*?\r\n\r\n(.*?)\r\n--/s';

    return preg_match($pattern, $request->body(), $matches) ? $matches[1] : null;
}

/**
 * INIT, one APPEND, then FINALIZE — the shortest complete upload.
 */
function fakeSuccessfulUpload(string $mediaId = '1880028106020515840'): void
{
    Http::fake([
        'api.x.com/*' => Http::sequence()
            ->push(['data' => ['id' => $mediaId]])
            ->push('', 200)
            ->push(['data' => ['id' => $mediaId]]),
    ]);
}

it('uploads a photo through INIT, APPEND and FINALIZE', function () {
    fakeSuccessfulUpload();

    $result = twitterProvider()->uploadMedia(collect([fakeMedia('a photo')]));

    expect($result)->toBe(['ids' => ['1880028106020515840'], 'errors' => []]);

    Http::assertSentCount(3);
});

it('posts every command to the single v2 upload URL, each signed with OAuth 1.0a', function () {
    fakeSuccessfulUpload();

    twitterProvider()->uploadMedia(collect([fakeMedia('a photo')]));

    foreach (Http::recorded() as [$request]) {
        $authorization = $request->header('Authorization')[0];

        expect($request->url())->toBe('https://api.x.com/2/media/upload')
            ->and($request->method())->toBe('POST')
            ->and($authorization)->toStartWith('OAuth ')
            ->and($authorization)->toContain('oauth_consumer_key="test-client-id"')
            ->and($authorization)->toContain('oauth_token="a-token"')
            ->and($authorization)->toContain('oauth_signature_method="HMAC-SHA1"')
            ->and($authorization)->toContain('oauth_signature=');
    }
});

it('sends the INIT fields X expects', function () {
    fakeSuccessfulUpload();

    twitterProvider()->uploadMedia(collect([fakeMedia('a photo', 'image/jpeg')]));

    Http::assertSent(function (Request $request) {
        return multipartField($request, 'command') === 'INIT'
            && multipartField($request, 'media_type') === 'image/jpeg'
            && multipartField($request, 'media_category') === 'tweet_image'
            && multipartField($request, 'total_bytes') === '7';
    });
});

it('sends the file as a `media` part on APPEND, numbered from zero', function () {
    fakeSuccessfulUpload();

    twitterProvider()->uploadMedia(collect([fakeMedia('a photo')]));

    Http::assertSent(function (Request $request) {
        return multipartField($request, 'command') === 'APPEND'
            && multipartField($request, 'segment_index') === '0'
            && multipartField($request, 'media') === 'a photo';
    });
});

it('labels a gif as tweet_gif and a video as amplify_video', function (string $mimeType, string $category) {
    fakeSuccessfulUpload();

    twitterProvider()->uploadMedia(collect([fakeMedia('a file', $mimeType)]));

    Http::assertSent(fn (Request $request) => multipartField($request, 'media_category') === $category);
})->with([
    ['image/gif', 'tweet_gif'],
    ['video/mp4', 'amplify_video'],
]);

it('splits a file larger than the chunk size across several APPEND requests', function () {
    // The chunk cap is 4 MiB, so 4 MiB plus a byte is the smallest file that needs two APPENDs.
    $contents = str_repeat('v', 4 * 1024 * 1024 + 1);

    Http::fake([
        'api.x.com/*' => Http::sequence()
            ->push(['data' => ['id' => '99']])
            ->push('', 200)
            ->push('', 200)
            ->push(['data' => ['id' => '99']]),
    ]);

    $result = twitterProvider()->uploadMedia(collect([fakeMedia($contents, 'video/mp4')]));

    expect($result['ids'])->toBe(['99']);

    Http::assertSentCount(4);

    Http::assertSent(function (Request $request) {
        return multipartField($request, 'command') === 'APPEND'
            && multipartField($request, 'segment_index') === '1';
    });
});

it('polls STATUS until processing succeeds', function () {
    // check_after_secs is 0 so the test does not actually sleep; X sends 1 or more.
    Http::fake([
        'api.x.com/*' => Http::sequence()
            ->push(['data' => ['id' => '42']])
            ->push('', 200)
            ->push(['data' => ['id' => '42', 'processing_info' => ['state' => 'pending', 'check_after_secs' => 0]]])
            ->push(['data' => ['id' => '42', 'processing_info' => ['state' => 'in_progress', 'check_after_secs' => 0]]])
            ->push(['data' => ['id' => '42', 'processing_info' => ['state' => 'succeeded']]]),
    ]);

    $result = twitterProvider()->uploadMedia(collect([fakeMedia('a video', 'video/mp4')]));

    expect($result)->toBe(['ids' => ['42'], 'errors' => []]);

    Http::assertSent(function (Request $request) {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.x.com/2/media/upload?command=STATUS&media_id=42';
    });
});

it('does not poll when FINALIZE is already terminal', function () {
    fakeSuccessfulUpload();

    twitterProvider()->uploadMedia(collect([fakeMedia('a photo')]));

    // INIT, APPEND, FINALIZE and nothing more — a photo carries no processing_info.
    Http::assertSentCount(3);
});

it('reports a failed transcode as an error rather than a media id', function () {
    Http::fake([
        'api.x.com/*' => Http::sequence()
            ->push(['data' => ['id' => '42']])
            ->push('', 200)
            ->push(['data' => ['id' => '42', 'processing_info' => ['state' => 'pending', 'check_after_secs' => 0]]])
            ->push(['data' => ['id' => '42', 'processing_info' => ['state' => 'failed']]]),
    ]);

    $result = twitterProvider()->uploadMedia(collect([fakeMedia('a video', 'video/mp4')]));

    expect($result['ids'])->toBe([])
        ->and($result['errors'][0])->toBe('Failed to upload `upload-me` file.');
});

it('names the media.write scope when X answers 403', function () {
    Http::fake(['api.x.com/*' => Http::response(['title' => 'Forbidden'], 403)]);

    $result = twitterProvider()->uploadMedia(collect([fakeMedia('a photo')]));

    expect($result['ids'])->toBe([])
        ->and($result['errors'][0])->toContain('media.write')
        ->and($result['errors'][0])->toContain('reconnect the account');
});

it('turns a 403 during publishing into an error carrying the scope', function () {
    Http::fake(['api.x.com/*' => Http::response(['title' => 'Forbidden'], 403)]);

    $response = twitterProvider()->publishPost('Hello', collect([fakeMedia('a photo')]));

    expect($response->status())->toBe(SocialProviderResponseStatus::ERROR)
        ->and($response->context()[0])->toContain('media.write');
});

it('releases the job instead of failing the post when X rate limits an upload', function () {
    Http::fake([
        'api.x.com/*' => Http::response(['title' => 'Too Many Requests'], 429, [
            'x-rate-limit-reset' => (string) now('UTC')->addSeconds(600)->timestamp,
        ]),
    ]);

    $response = twitterProvider()->publishPost('Hello', collect([fakeMedia('a photo')]));

    expect($response->status())->toBe(SocialProviderResponseStatus::EXCEEDED_RATE_LIMIT)
        ->and($response->retryAfter())->toBeGreaterThan(590)
        ->and($response->context()['rate_limit_exceed'])->toBeTrue();
});

it('falls back to a five minute wait when a 429 carries no reset header', function () {
    Http::fake(['api.x.com/*' => Http::response(['title' => 'Too Many Requests'], 429)]);

    $response = twitterProvider()->publishPost('Hello', collect([fakeMedia('a photo')]));

    expect($response->retryAfter())->toBe(300);
});

it('uploads the media it can and reports the ones it cannot', function () {
    Http::fake([
        'api.x.com/*' => Http::sequence()
            ->push(['data' => ['id' => '1']])
            ->push('', 200)
            ->push(['data' => ['id' => '1']])
            ->push(['title' => 'Payload too large'], 413),
    ]);

    $result = twitterProvider()->uploadMedia(collect([fakeMedia('one'), fakeMedia('two')]));

    expect($result['ids'])->toBe(['1'])
        ->and($result['errors'][0])->toContain('X rejected the media upload (413)');
});

it('sends nothing at all for a post with no media', function () {
    Http::fake();

    expect(twitterProvider()->uploadMedia(collect()))->toBe(['ids' => [], 'errors' => []]);

    Http::assertNothingSent();
});
