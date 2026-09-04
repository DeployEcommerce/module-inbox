<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Webhook;

use DeployEcommerce\Inbox\Api\Data\MessageInterface;
use DeployEcommerce\Inbox\Model\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;

/**
 * Builds the JSON body of a webhook delivery.
 *
 * The body of a message is omitted by default. Message bodies carry third-party API error
 * payloads, which routinely contain customer personal data and occasionally credentials;
 * forwarding them sends that data to a third party under a retention policy this site does
 * not control, which is a data-processing decision rather than a technical one. Metadata
 * alone is enough for the common case of routing alerts to Slack or PagerDuty.
 */
class PayloadBuilder
{
    /**
     * Incremented only for a breaking change: a renamed or removed key, a changed type or
     * unit, or a changed meaning. Adding an optional key is not breaking, and receivers are
     * required to ignore keys they do not recognise.
     */
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly WebhookConfig $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly State $appState
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(MessageInterface $message, string $deliveryUuid, int $attempt, int $occurrenceNo): array
    {
        return [
            // First key in the object so a receiver can sniff the version cheaply.
            'schema_version' => self::SCHEMA_VERSION,
            'delivery_id' => $deliveryUuid,
            'attempt' => $attempt,
            'sent_at' => gmdate('c'),
            'site' => [
                'base_url' => (string)$this->scopeConfig->getValue(
                    Store::XML_PATH_SECURE_BASE_URL,
                    ScopeInterface::SCOPE_STORE
                ),
                'environment' => $this->environment(),
            ],
            'message' => [
                'id' => $message->getMessageId(),
                // Receivers should key on the integer, not the label: the integers are fixed
                // to Monolog's values and will not move, whereas labels are presentational.
                'severity' => $message->getSeverity(),
                'severity_label' => Severity::normalize($message->getSeverity())->label(),
                'source' => $message->getSource(),
                'title' => $message->getTitle(),
                'tags' => $message->getTags(),
                'occurrences' => $message->getOccurrences(),
                // The count at the moment forwarding was triggered, which may be lower than
                // occurrences above if more arrived while this sat in the queue.
                'occurrence_no' => $occurrenceNo,
                'never_delete' => $message->getNeverDelete(),
                'created_at' => $this->toIso8601($message->getCreatedAt()),
                'last_seen_at' => $this->toIso8601($message->getLastSeenAt()),
                'body' => $this->body($message),
                // Explicit, so a receiver never mistakes a cut-off body for a complete one.
                'body_truncated' => $this->isTruncated($message),
            ],
        ];
    }

    public function encode(array $payload): string
    {
        return (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function body(MessageInterface $message): ?string
    {
        $policy = $this->config->getIncludeBodyPolicy();

        if ($policy === WebhookConfig::BODY_NONE) {
            return null;
        }

        $body = $message->getBody();

        if ($body === null || $body === '') {
            return $body;
        }

        // Already redacted at write time. Redaction deliberately happens once, on the way
        // into storage, so the stored copy and the forwarded copy can never disagree.
        if ($policy === WebhookConfig::BODY_FULL) {
            return $body;
        }

        return mb_strcut($body, 0, $this->config->getBodyMaxBytes());
    }

    private function isTruncated(MessageInterface $message): bool
    {
        if ($this->config->getIncludeBodyPolicy() !== WebhookConfig::BODY_TRUNCATED) {
            return false;
        }

        $body = (string)$message->getBody();

        return strlen($body) > $this->config->getBodyMaxBytes();
    }

    private function toIso8601(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('c');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function environment(): string
    {
        try {
            return $this->appState->getMode();
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
