<?php
declare(strict_types=1);

use DeployEcommerce\Inbox\Model\Webhook\UrlGuard;
use Magento\Framework\Exception\LocalizedException;

/**
 * The endpoint is administrator-supplied and the server fetches it, so this is the
 * module's server-side request forgery boundary. Each blocked range gets its own case: a
 * range check that silently fails to match looks identical to one that works until it is
 * used to reach something.
 */
function urlGuard(): UrlGuard
{
    return new UrlGuard();
}

it('blocks addresses that must never be reachable', function (string $address) {
    expect(urlGuard()->isBlocked($address))->toBeTrue();
})->with([
    // The cloud metadata endpoint: the highest-value target on any hosted platform.
    ['169.254.169.254'],
    ['169.254.0.1'],
    ['127.0.0.1'],
    ['127.1.2.3'],
    ['10.0.0.1'],
    ['172.16.0.1'],
    ['172.31.255.254'],
    ['192.168.1.1'],
    ['100.64.0.1'],
    ['0.0.0.0'],
    ['192.0.2.1'],
    ['198.18.0.1'],
    ['224.0.0.1'],
    ['255.255.255.255'],
    ['::1'],
    ['::'],
    ['fe80::1'],
    ['fc00::1'],
    ['fd00::1'],
    ['2001:db8::1'],
    ['ff00::1'],
    // IPv4-mapped IPv6: the case that walks past an IPv4-only range check.
    ['::ffff:127.0.0.1'],
    ['::ffff:169.254.169.254'],
    ['not-an-address'],
]);

it('allows public addresses', function (string $address) {
    expect(urlGuard()->isBlocked($address))->toBeFalse();
})->with([
    ['8.8.8.8'],
    ['1.1.1.1'],
    // Just outside 172.16.0.0/12: proves the mask arithmetic is exact, not over-broad.
    ['172.32.5.4'],
    ['172.15.255.255'],
    ['2606:4700:4700::1111'],
]);

it('rejects URLs that are unsafe by construction', function (string $url) {
    expect(fn () => urlGuard()->resolve($url))->toThrow(LocalizedException::class);
})->with([
    ['http://example.com/hook'],
    ['ftp://example.com/hook'],
    ['file:///etc/passwd'],
    // An IP literal removes the DNS layer that pinning depends on.
    ['https://169.254.169.254/'],
    ['https://[::1]/hook'],
    ['https://127.0.0.1/hook'],
    // Credentials in a URL leak through logs and proxies.
    ['https://user:pass@example.com/hook'],
    ['https://example.com:8080/hook'],
    ['https://example.com:22/hook'],
    ['not a url at all'],
    [''],
]);

it('reports unsafe URLs without throwing when asked for a boolean', function () {
    expect(urlGuard()->isAllowed('http://example.com'))->toBeFalse();
});
