<?php

use Illuminate\Support\Str;
use Inovector\Mixpost\Facades\SocialProviderManager;

it('registers only providers whose class exists', function () {
    // A registered name with no class behind it fatals the Accounts page rather than simply not
    // appearing, which is what the class_exists filter in SocialProviderManager guards against.
    foreach (SocialProviderManager::providers() as $name => $class) {
        expect(class_exists($class))->toBeTrue("Provider [$name] points at a missing class [$class].");
    }
});

it('no longer registers facebook_group', function () {
    // There has never been a FacebookGroupProvider; the config block and UI were dead weight.
    expect(SocialProviderManager::providers())->not->toHaveKey('facebook_group')
        ->and(config('mixpost.social_provider_options'))->not->toHaveKey('facebook_group');
});

it('registers the networks that have shipped', function () {
    expect(SocialProviderManager::providers())
        ->toHaveKeys(['twitter', 'facebook_page', 'mastodon', 'instagram', 'linkedin', 'tiktok', 'youtube']);
});

it('has a connect method for every registered provider', function () {
    // The half of the contract that keeps a network from being offered and then failing:
    // anything the accounts UI lists must be reachable through connect().
    foreach (array_keys(SocialProviderManager::providers()) as $name) {
        $method = 'connect'.Str::studly($name).'Provider';

        expect(method_exists(SocialProviderManager::getFacadeRoot(), $method))
            ->toBeTrue("Provider [$name] is registered but has no $method().");
    }
});

it('refuses to connect a provider that is not registered', function () {
    // The other half. A connect method names its provider class directly, so calling one for an
    // unregistered provider instantiates a class that may not exist — a fatal, not an exception.
    // This asserts the registry check runs *before* the method dispatch.
    expect(fn () => SocialProviderManager::connect('not_a_network'))
        ->toThrow(InvalidArgumentException::class);

    // Any connect method whose provider is not in the registry must fail the same way. Every
    // shipped network is registered, so this loop has nothing to iterate today — it is here to
    // catch the next connect method that lands ahead of its registry entry.
    $registered = array_keys(SocialProviderManager::providers());

    // Reflection rather than get_class_methods(), which from outside the class would only see the
    // public methods — every connect*Provider() is protected.
    $methods = (new ReflectionClass(SocialProviderManager::getFacadeRoot()))->getMethods();

    foreach ($methods as $method) {
        if (! preg_match('/^connect(.+)Provider$/', $method->getName(), $matches)) {
            continue;
        }

        $name = Str::snake($matches[1]);

        if (in_array($name, $registered, true)) {
            continue;
        }

        expect(fn () => SocialProviderManager::connect($name))
            ->toThrow(InvalidArgumentException::class, "Provider [$name] is not available.");
    }
});
