<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Webhook;

use Magento\Framework\Exception\LocalizedException;

/**
 * Validates an outbound webhook URL and pins it to a vetted IP address.
 *
 * The endpoint is administrator-supplied and the server fetches it, which is textbook
 * server-side request forgery. An attacker holding an admin session would otherwise have
 * the site issue authenticated requests to cloud metadata endpoints, internal admin
 * panels, or other services reachable only from the application host.
 *
 * Magento ships nothing suitable: Magento\Framework\Url\Validator checks the scheme only,
 * and no core code uses FILTER_FLAG_NO_PRIV_RANGE. Hence this class.
 *
 * Validation runs twice: once on config save so the administrator gets an immediate error,
 * and again immediately before every request. The second is the one that counts. Config
 * can be set through env.php or config.php, which bypasses backend models entirely, and a
 * DNS answer that was safe at save time can change afterwards — that is the DNS rebinding
 * attack, and it is why this returns a pinned address rather than just a verdict.
 */
class UrlGuard
{
    /**
     * IPv4 ranges that must never be reachable from a webhook.
     *
     * 169.254.0.0/16 covers the cloud metadata address 169.254.169.254, which is the single
     * most valuable SSRF target on any hosted platform: it serves instance credentials to
     * anything that can make a plain HTTP request from the host.
     *
     * @var array<int, array{0:string,1:int}>
     */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8],          // "this network"
        ['10.0.0.0', 8],         // RFC 1918 private
        ['100.64.0.0', 10],      // RFC 6598 carrier-grade NAT
        ['127.0.0.0', 8],        // loopback
        ['169.254.0.0', 16],     // link-local, includes cloud metadata
        ['172.16.0.0', 12],      // RFC 1918 private
        ['192.0.0.0', 24],       // IETF protocol assignments
        ['192.0.2.0', 24],       // TEST-NET-1
        ['192.168.0.0', 16],     // RFC 1918 private
        ['198.18.0.0', 15],      // benchmarking
        ['198.51.100.0', 24],    // TEST-NET-2
        ['203.0.113.0', 24],     // TEST-NET-3
        ['224.0.0.0', 4],        // multicast
        ['240.0.0.0', 4],        // reserved, includes 255.255.255.255
    ];

    /**
     * IPv6 ranges that must never be reachable.
     *
     * ::ffff:0:0/96 is the one people forget. Without it, ::ffff:127.0.0.1 is an
     * IPv4-mapped address that resolves to loopback and walks straight past IPv4-only
     * range checks.
     *
     * @var array<int, array{0:string,1:int}>
     */
    private const BLOCKED_V6 = [
        ['::', 128],             // unspecified
        ['::1', 128],            // loopback
        ['::ffff:0:0', 96],      // IPv4-mapped
        ['64:ff9b::', 96],       // NAT64
        ['100::', 64],           // discard-only
        ['2001:db8::', 32],      // documentation
        ['fc00::', 7],           // unique local, supersedes fd00::/8
        ['fe80::', 10],          // link-local
        ['ff00::', 8],           // multicast
    ];

    private const ALLOWED_SCHEME = 'https';
    private const ALLOWED_PORT = 443;

    /**
     * Validate a URL and resolve it to a single vetted IP address.
     *
     * @throws LocalizedException when the URL is unusable or resolves anywhere unsafe.
     */
    public function resolve(string $url): PinnedTarget
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'], $parts['scheme'])) {
            throw new LocalizedException(__('The webhook endpoint is not a valid URL.'));
        }

        if (strtolower($parts['scheme']) !== self::ALLOWED_SCHEME) {
            throw new LocalizedException(
                __('The webhook endpoint must use https. Plain http would send message data in clear text.')
            );
        }

        // Credentials in the URL are silently forwarded and routinely leak through logs.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new LocalizedException(
                __('The webhook endpoint must not contain a username or password. Use the signing secret instead.')
            );
        }

        $port = (int)($parts['port'] ?? self::ALLOWED_PORT);

        if ($port !== self::ALLOWED_PORT) {
            throw new LocalizedException(
                __('The webhook endpoint must use port 443. Port %1 is not allowed.', $port)
            );
        }

        $host = $parts['host'];

        // A bare IP literal is never a legitimate webhook target and removes the DNS layer
        // that the pinning below depends on. Requiring a hostname kills the simplest attacks
        // outright: https://169.254.169.254/ and https://[::1]/ never get past here.
        if ($this->isIpLiteral($host)) {
            throw new LocalizedException(
                __('The webhook endpoint must use a hostname, not an IP address.')
            );
        }

        $addresses = $this->lookup($host);

        if ($addresses === []) {
            throw new LocalizedException(
                __('The webhook endpoint hostname "%1" could not be resolved.', $host)
            );
        }

        // Every record must be safe, not merely the first. An attacker who controls DNS can
        // return one public and one private address and let the resolver choose.
        foreach ($addresses as $address) {
            if ($this->isBlocked($address)) {
                throw new LocalizedException(
                    __(
                        'The webhook endpoint resolves to %1, which is a private or reserved address.',
                        $address
                    )
                );
            }
        }

        return new PinnedTarget($url, $host, $port, $addresses[0]);
    }

    /**
     * True when the URL is safe. Never throws: for callers that want a boolean.
     */
    public function isAllowed(string $url): bool
    {
        try {
            $this->resolve($url);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isIpLiteral(string $host): bool
    {
        $candidate = trim($host, '[]');

        return filter_var($candidate, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @return string[]
     */
    private function lookup(string $host): array
    {
        $addresses = [];

        // Suppressed: dns_get_record emits a warning for a host with no records of the
        // requested type, which is an expected outcome here, not an error.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        foreach ($records ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[$address] = $address;
            }
        }

        return array_values($addresses);
    }

    public function isBlocked(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return true;
        }

        $ranges = strlen($packed) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6;

        foreach ($ranges as [$network, $prefix]) {
            if ($this->inRange($packed, (string)inet_pton($network), $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function inRange(string $packed, string $network, int $prefix): bool
    {
        if (strlen($packed) !== strlen($network)) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($wholeBytes > 0 && strncmp($packed, $network, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($packed[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }
}
