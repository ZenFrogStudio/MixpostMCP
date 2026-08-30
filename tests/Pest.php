<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Inovector\Mixpost\Abstracts\SocialProvider;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * A Request with a live session attached.
 *
 * Providers that guard the OAuth redirect — LinkedIn's `state`, TikTok's `state` plus PKCE verifier
 * — write to the session inside getAuthUrl(). A bare Request has no session and throws instead.
 */
function requestWithSession(): Request
{
    $request = Request::create('https://mixpost.test/mixpost/accounts');

    $request->setLaravelSession(app('session.store'));

    return $request;
}

/**
 * Build a provider without going through SocialProviderManager, which would need real credentials
 * stored on the Services page.
 */
function makeProvider(string $providerClass, string $provider = 'test', array $values = []): SocialProvider
{
    return new $providerClass(
        requestWithSession(),
        'test-client-id',
        'test-client-secret',
        "https://mixpost.test/mixpost/callback/$provider",
        $values
    );
}

/**
 * A real Illuminate HTTP client response, for calling a provider's buildResponse() directly.
 *
 * Http::response() returns a promise for the fake handler to resolve, not a response, so it cannot
 * be handed to buildResponse().
 */
function httpClientResponse(array $body, int $status = 200, array $headers = []): Response
{
    return new Response(new Psr7Response($status, $headers, json_encode($body)));
}

/**
 * The query string of a URL, parsed into an array.
 */
function queryParams(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

    return $params;
}

/**
 * A saved Media row whose probe result is already cached, so a rule that reads a duration or a frame
 * size can be tested without ffprobe and without a real file on disk. MediaProbe reads its cache
 * before it looks for anything to measure, which is exactly the path a second network publishing the
 * same file takes.
 *
 * $probe takes any of `duration` (seconds), `width` and `height`.
 */
function mediaWithProbe(string $mimeType, array $probe, array $attributes = []): Media
{
    return Media::factory()->create(array_merge([
        'name' => 'clip.mp4',
        'mime_type' => $mimeType,
        'size' => 1024,
        'data' => ['probe' => $probe],
    ], $attributes));
}

/**
 * Every entry a provider's postOptions() returns must be renderable by the composer, which means a
 * key, a label, a known type, a default, and — for a select — the choices to populate it.
 */
function assertWellFormedPostOptions(array $options): void
{
    foreach ($options as $option) {
        expect($option)->toHaveKeys(['key', 'label', 'type', 'default'])
            ->and($option['key'])->toBeString()->not->toBeEmpty()
            ->and($option['label'])->toBeString()->not->toBeEmpty()
            ->and($option['type'])->toBeIn(['text', 'textarea', 'select', 'checkbox']);

        if ($option['type'] === 'select') {
            expect($option)->toHaveKey('choices')
                ->and($option['choices'])->toBeArray()->not->toBeEmpty()
                ->and($option['default'])->toBeIn(array_keys($option['choices']));
        }

        if ($option['type'] === 'checkbox') {
            expect($option['default'])->toBeBool();
        }
    }
}
