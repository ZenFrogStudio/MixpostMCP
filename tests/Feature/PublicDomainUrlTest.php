<?php

use OneMediaLabs\MixpostMcp\Util;

/*
 * Util::isPublicDomainUrl() sits in front of every server-side fetch of a URL a user or an AI
 * agent supplied. Each case here is a way an attacker would try to point that fetch at something
 * inside the network.
 */

it('refuses a literal private IPv4 address', function () {
    expect(Util::isPublicDomainUrl('http://192.168.1.10/payload.png'))->toBeFalse()
        ->and(Util::isPublicDomainUrl('http://10.0.0.1/x'))->toBeFalse()
        ->and(Util::isPublicDomainUrl('http://127.0.0.1/x'))->toBeFalse();
});

it('refuses a literal public IPv4 address too', function () {
    // A bare IP is never what a media URL looks like; refusing them keeps the rule simple.
    expect(Util::isPublicDomainUrl('http://93.184.216.34/x.jpg'))->toBeFalse();
});

it('refuses a bracketed IPv6 loopback, which a plain IP check misses', function () {
    // parse_url() returns the host as "[::1]", brackets included, so FILTER_VALIDATE_IP on it
    // fails and the old check waved it through.
    expect(Util::isPublicDomainUrl('http://[::1]/x'))->toBeFalse()
        ->and(Util::isPublicDomainUrl('http://[fd00::1]/x'))->toBeFalse();
});

it('refuses localhost in any casing and any subdomain', function () {
    expect(Util::isPublicDomainUrl('http://localhost/x'))->toBeFalse()
        ->and(Util::isPublicDomainUrl('http://LOCALHOST:8000/x'))->toBeFalse()
        ->and(Util::isPublicDomainUrl('http://app.localhost/x'))->toBeFalse();
});

it('refuses the cloud metadata address', function () {
    expect(Util::isPublicDomainUrl('http://169.254.169.254/latest/meta-data/'))->toBeFalse();
});

it('refuses anything that is not http or https', function () {
    expect(Util::isPublicDomainUrl('file:///etc/passwd'))->toBeFalse()
        ->and(Util::isPublicDomainUrl('ftp://example.com/x'))->toBeFalse()
        ->and(Util::isPublicDomainUrl('not a url'))->toBeFalse();
});

it('refuses a hostname that does not resolve', function () {
    // .invalid is reserved and guaranteed never to resolve, so this is deterministic offline.
    expect(Util::isPublicDomainUrl('https://media.example.invalid/x.jpg'))->toBeFalse();
});

it('accepts an ordinary public hostname', function () {
    // Needs DNS. example.com is guaranteed to resolve to a public address.
    expect(Util::isPublicDomainUrl('https://example.com/photo.jpg'))->toBeTrue();
});
