<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Webhook;

/**
 * A webhook URL that has been validated and bound to one vetted IP address.
 *
 * Carrying the address alongside the URL is what closes the DNS rebinding window: the
 * transport passes it to curl's CURLOPT_RESOLVE so the name is never looked up a second
 * time between validation and connection.
 */
class PinnedTarget
{
    public function __construct(
        private readonly string $url,
        private readonly string $host,
        private readonly int $port,
        private readonly string $ipAddress
    ) {
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    /**
     * curl CURLOPT_RESOLVE entry pinning this host and port to the vetted address.
     */
    public function getResolveEntry(): string
    {
        return sprintf('%s:%d:%s', $this->host, $this->port, $this->ipAddress);
    }
}
