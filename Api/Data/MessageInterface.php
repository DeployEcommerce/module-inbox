<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Api\Data;

use Magento\Framework\Api\ExtensibleDataInterface;

/**
 * A single inbox message.
 *
 * @api
 */
interface MessageInterface extends ExtensibleDataInterface
{
    public const MESSAGE_ID = 'message_id';
    public const SEVERITY = 'severity';
    public const IS_READ = 'is_read';
    public const NEVER_DELETE = 'never_delete';
    public const SOURCE = 'source';
    public const TITLE = 'title';
    public const BODY = 'body';
    public const DEDUPE_HASH = 'dedupe_hash';
    public const OCCURRENCES = 'occurrences';
    public const READ_BY_ADMIN_ID = 'read_by_admin_id';
    public const READ_AT = 'read_at';
    public const CREATED_AT = 'created_at';
    public const LAST_SEEN_AT = 'last_seen_at';

    /**
     * Monolog level values. Hard-coded rather than derived from Monolog\Level so that a
     * Monolog major version bump cannot silently change the meaning of stored rows.
     */
    public const SEVERITY_DEBUG = 100;
    public const SEVERITY_INFO = 200;
    public const SEVERITY_NOTICE = 250;
    public const SEVERITY_WARNING = 300;
    public const SEVERITY_ERROR = 400;
    public const SEVERITY_CRITICAL = 500;
    public const SEVERITY_ALERT = 550;
    public const SEVERITY_EMERGENCY = 600;

    public const TITLE_MAX_LENGTH = 500;

    /**
     * Get message ID.
     *
     * @return int|null
     */
    public function getMessageId(): ?int;

    /**
     * Set message ID.
     *
     * @param int|null $messageId
     * @return $this
     */
    public function setMessageId(?int $messageId): self;

    /**
     * Get the Monolog severity level value.
     *
     * @return int
     */
    public function getSeverity(): int;

    /**
     * Set the Monolog severity level value.
     *
     * @param int $severity
     * @return $this
     */
    public function setSeverity(int $severity): self;

    /**
     * Check whether the message has been read.
     *
     * @return bool
     */
    public function getIsRead(): bool;

    /**
     * Set the read state.
     *
     * @param bool $isRead
     * @return $this
     */
    public function setIsRead(bool $isRead): self;

    /**
     * Check whether the message is exempt from retention.
     *
     * @return bool
     */
    public function getNeverDelete(): bool;

    /**
     * Set the retention exemption.
     *
     * @param bool $neverDelete
     * @return $this
     */
    public function setNeverDelete(bool $neverDelete): self;

    /**
     * Get the originating integration.
     *
     * @return string
     */
    public function getSource(): string;

    /**
     * Set the originating integration.
     *
     * @param string $source
     * @return $this
     */
    public function setSource(string $source): self;

    /**
     * Get the short message.
     *
     * @return string
     */
    public function getTitle(): string;

    /**
     * Set the short message.
     *
     * @param string $title
     * @return $this
     */
    public function setTitle(string $title): self;

    /**
     * Get the full detail.
     *
     * @return string|null
     */
    public function getBody(): ?string;

    /**
     * Set the full detail.
     *
     * @param string|null $body
     * @return $this
     */
    public function setBody(?string $body): self;

    /**
     * Get the deduplication hash.
     *
     * @return string|null
     */
    public function getDedupeHash(): ?string;

    /**
     * Set the deduplication hash.
     *
     * @param string|null $dedupeHash
     * @return $this
     */
    public function setDedupeHash(?string $dedupeHash): self;

    /**
     * Get how many times this message has been seen.
     *
     * @return int
     */
    public function getOccurrences(): int;

    /**
     * Set the occurrence count.
     *
     * @param int $occurrences
     * @return $this
     */
    public function setOccurrences(int $occurrences): self;

    /**
     * Get the admin user that last marked this read.
     *
     * @return int|null
     */
    public function getReadByAdminId(): ?int;

    /**
     * Set the admin user that last marked this read.
     *
     * @param int|null $adminId
     * @return $this
     */
    public function setReadByAdminId(?int $adminId): self;

    /**
     * Get when the message was marked read.
     *
     * @return string|null
     */
    public function getReadAt(): ?string;

    /**
     * Set when the message was marked read.
     *
     * @param string|null $readAt
     * @return $this
     */
    public function setReadAt(?string $readAt): self;

    /**
     * Get the creation time.
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * Set the creation time.
     *
     * @param string|null $createdAt
     * @return $this
     */
    public function setCreatedAt(?string $createdAt): self;

    /**
     * Get the most recent occurrence time.
     *
     * @return string|null
     */
    public function getLastSeenAt(): ?string;

    /**
     * Set the most recent occurrence time.
     *
     * @param string|null $lastSeenAt
     * @return $this
     */
    public function setLastSeenAt(?string $lastSeenAt): self;

    /**
     * @return string[]
     */
    public function getTags(): array;

    /**
     * @param string[] $tags
     */
    public function setTags(array $tags): self;

    /**
     * Get extension attributes.
     *
     * @return \DeployEcommerce\Inbox\Api\Data\MessageExtensionInterface|null
     */
    public function getExtensionAttributes(): ?MessageExtensionInterface;

    /**
     * Set extension attributes.
     *
     * @param \DeployEcommerce\Inbox\Api\Data\MessageExtensionInterface $extensionAttributes
     * @return $this
     */
    public function setExtensionAttributes(MessageExtensionInterface $extensionAttributes): self;
}
