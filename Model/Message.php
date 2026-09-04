<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model;

use DeployEcommerce\Inbox\Api\Data\MessageExtensionInterface;
use DeployEcommerce\Inbox\Api\Data\MessageInterface;
use DeployEcommerce\Inbox\Model\ResourceModel\Message as MessageResource;
use Magento\Framework\Model\AbstractExtensibleModel;

class Message extends AbstractExtensibleModel implements MessageInterface
{
    protected function _construct(): void
    {
        $this->_init(MessageResource::class);
    }

    public function getMessageId(): ?int
    {
        $value = $this->getData(self::MESSAGE_ID);

        return $value === null ? null : (int)$value;
    }

    public function setMessageId(?int $messageId): MessageInterface
    {
        return $this->setData(self::MESSAGE_ID, $messageId);
    }

    public function getSeverity(): int
    {
        return (int)$this->getData(self::SEVERITY);
    }

    public function setSeverity(int $severity): MessageInterface
    {
        return $this->setData(self::SEVERITY, $severity);
    }

    public function getIsRead(): bool
    {
        return (bool)$this->getData(self::IS_READ);
    }

    public function setIsRead(bool $isRead): MessageInterface
    {
        return $this->setData(self::IS_READ, $isRead ? 1 : 0);
    }

    public function getNeverDelete(): bool
    {
        return (bool)$this->getData(self::NEVER_DELETE);
    }

    public function setNeverDelete(bool $neverDelete): MessageInterface
    {
        return $this->setData(self::NEVER_DELETE, $neverDelete ? 1 : 0);
    }

    public function getSource(): string
    {
        return (string)$this->getData(self::SOURCE);
    }

    public function setSource(string $source): MessageInterface
    {
        return $this->setData(self::SOURCE, $source);
    }

    public function getTitle(): string
    {
        return (string)$this->getData(self::TITLE);
    }

    public function setTitle(string $title): MessageInterface
    {
        return $this->setData(self::TITLE, $title);
    }

    public function getBody(): ?string
    {
        $value = $this->getData(self::BODY);

        return $value === null ? null : (string)$value;
    }

    public function setBody(?string $body): MessageInterface
    {
        return $this->setData(self::BODY, $body);
    }

    public function getDedupeHash(): ?string
    {
        $value = $this->getData(self::DEDUPE_HASH);

        return $value === null ? null : (string)$value;
    }

    public function setDedupeHash(?string $dedupeHash): MessageInterface
    {
        return $this->setData(self::DEDUPE_HASH, $dedupeHash);
    }

    public function getOccurrences(): int
    {
        return (int)$this->getData(self::OCCURRENCES);
    }

    public function setOccurrences(int $occurrences): MessageInterface
    {
        return $this->setData(self::OCCURRENCES, $occurrences);
    }

    public function getReadByAdminId(): ?int
    {
        $value = $this->getData(self::READ_BY_ADMIN_ID);

        return $value === null ? null : (int)$value;
    }

    public function setReadByAdminId(?int $adminId): MessageInterface
    {
        return $this->setData(self::READ_BY_ADMIN_ID, $adminId);
    }

    public function getReadAt(): ?string
    {
        $value = $this->getData(self::READ_AT);

        return $value === null ? null : (string)$value;
    }

    public function setReadAt(?string $readAt): MessageInterface
    {
        return $this->setData(self::READ_AT, $readAt);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);

        return $value === null ? null : (string)$value;
    }

    public function setCreatedAt(?string $createdAt): MessageInterface
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    public function getLastSeenAt(): ?string
    {
        $value = $this->getData(self::LAST_SEEN_AT);

        return $value === null ? null : (string)$value;
    }

    public function setLastSeenAt(?string $lastSeenAt): MessageInterface
    {
        return $this->setData(self::LAST_SEEN_AT, $lastSeenAt);
    }

    public function getTags(): array
    {
        $tags = $this->getData('tags');

        if (is_string($tags)) {
            $tags = $tags === '' ? [] : explode(',', $tags);
        }

        return array_values(array_filter((array)$tags, static fn ($tag): bool => $tag !== null && $tag !== ''));
    }

    public function setTags(array $tags): MessageInterface
    {
        return $this->setData('tags', array_values($tags));
    }

    public function getExtensionAttributes(): ?MessageExtensionInterface
    {
        return $this->_getExtensionAttributes();
    }

    public function setExtensionAttributes(MessageExtensionInterface $extensionAttributes): MessageInterface
    {
        return $this->_setExtensionAttributes($extensionAttributes);
    }
}
