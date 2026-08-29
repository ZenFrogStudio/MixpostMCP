<?php

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
        ->toHaveKeys(['twitter', 'facebook_page', 'mastodon', 'instagram', 'linkedin', 'tiktok']);
});
