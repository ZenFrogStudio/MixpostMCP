<?php

use Illuminate\Http\Request;
use Inovector\Mixpost\Abstracts\SocialProvider;
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
function makeProvider(string $providerClass, string $provider = 'test'): SocialProvider
{
    return new $providerClass(
        requestWithSession(),
        'test-client-id',
        'test-client-secret',
        "https://mixpost.test/mixpost/callback/$provider"
    );
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
