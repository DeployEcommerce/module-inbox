<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Webhook;

use DeployEcommerce\Inbox\Model\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Typed reader for deployecommerce_inbox/webhook/*.
 */
class WebhookConfig
{
    public const BODY_NONE = 'none';
    public const BODY_TRUNCATED = 'truncated';
    public const BODY_FULL = 'full';

    /**
     * Never forwarded, whatever the configured allowlist says.
     *
     * This is the second of two independent guards against an amplifying loop: a failing
     * webhook must not produce a message that triggers another webhook. The first guard is
     * architectural (nothing under Model/Webhook/ may depend on InboxWriterInterface); this
     * one contains the blast radius if that rule is ever broken.
     */
    public const EXCLUDED_SOURCE = 'inbox_webhook';

    public const XML_ENABLED = 'deployecommerce_inbox/webhook/enabled';
    public const XML_ENDPOINT_URL = 'deployecommerce_inbox/webhook/endpoint_url';
    public const XML_CONNECT_TIMEOUT = 'deployecommerce_inbox/webhook/connect_timeout';
    public const XML_TIMEOUT = 'deployecommerce_inbox/webhook/timeout';
    public const XML_SEVERITY_THRESHOLD = 'deployecommerce_inbox/webhook/severity_threshold';
    public const XML_SOURCE_ALLOWLIST = 'deployecommerce_inbox/webhook/source_allowlist';
    public const XML_TAG_ALLOWLIST = 'deployecommerce_inbox/webhook/tag_allowlist';
    public const XML_INCLUDE_BODY = 'deployecommerce_inbox/webhook/include_body';
    public const XML_BODY_MAX_BYTES = 'deployecommerce_inbox/webhook/body_max_bytes';
    public const XML_SIGNING_SECRET = 'deployecommerce_inbox/webhook/signing_secret';
    public const XML_AUTH_HEADER_NAME = 'deployecommerce_inbox/webhook/auth_header_name';
    public const XML_AUTH_HEADER_VALUE = 'deployecommerce_inbox/webhook/auth_header_value';
    public const XML_MAX_ATTEMPTS = 'deployecommerce_inbox/webhook/max_attempts';
    public const XML_RETRY_BASE_DELAY = 'deployecommerce_inbox/webhook/retry_base_delay';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED)
            && $this->getEndpointUrl() !== ''
            && $this->getSigningSecret() !== '';
    }

    public function getEndpointUrl(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_ENDPOINT_URL));
    }

    public function getConnectTimeout(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_CONNECT_TIMEOUT));
    }

    public function getTimeout(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_TIMEOUT));
    }

    public function getSeverityThreshold(): int
    {
        $value = (int)$this->scopeConfig->getValue(self::XML_SEVERITY_THRESHOLD);

        return $value > 0 ? $value : Severity::Critical->value;
    }

    /**
     * Values are already decrypted. Magento runs the backend model's processValue() over
     * every value carrying one before ScopeConfig caches it, so calling decrypt() here would
     * corrupt the secret rather than reveal it.
     */
    public function getSigningSecret(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_SIGNING_SECRET);
    }

    public function getAuthHeaderName(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_AUTH_HEADER_NAME));
    }

    public function getAuthHeaderValue(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_AUTH_HEADER_VALUE);
    }

    public function getIncludeBodyPolicy(): string
    {
        $value = (string)$this->scopeConfig->getValue(self::XML_INCLUDE_BODY);

        return in_array($value, [self::BODY_NONE, self::BODY_TRUNCATED, self::BODY_FULL], true)
            ? $value
            : self::BODY_NONE;
    }

    public function getBodyMaxBytes(): int
    {
        return max(256, (int)$this->scopeConfig->getValue(self::XML_BODY_MAX_BYTES));
    }

    public function getMaxAttempts(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_MAX_ATTEMPTS));
    }

    public function getRetryBaseDelay(): int
    {
        return max(30, (int)$this->scopeConfig->getValue(self::XML_RETRY_BASE_DELAY));
    }

    /**
     * @return string[]
     */
    public function getSourceAllowlist(): array
    {
        return $this->splitLines((string)$this->scopeConfig->getValue(self::XML_SOURCE_ALLOWLIST));
    }

    /**
     * @return string[]
     */
    public function getTagAllowlist(): array
    {
        return $this->splitLines((string)$this->scopeConfig->getValue(self::XML_TAG_ALLOWLIST));
    }

    /**
     * Decide whether a message qualifies for forwarding.
     *
     * @param string[] $tags
     */
    public function shouldForward(int $severity, string $source, array $tags): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if ($source === self::EXCLUDED_SOURCE) {
            return false;
        }

        if ($severity < $this->getSeverityThreshold()) {
            return false;
        }

        $sourceAllowlist = $this->getSourceAllowlist();

        if ($sourceAllowlist !== [] && !in_array($source, $sourceAllowlist, true)) {
            return false;
        }

        $tagAllowlist = $this->getTagAllowlist();

        if ($tagAllowlist !== [] && array_intersect($tagAllowlist, $tags) === []) {
            return false;
        }

        return true;
    }

    /**
     * @return string[]
     */
    private function splitLines(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $values = preg_split('/[\r\n,]+/', $raw) ?: [];
        $values = array_map(static fn ($v): string => strtolower(trim($v)), $values);

        return array_values(array_filter($values, static fn ($v): bool => $v !== ''));
    }
}
