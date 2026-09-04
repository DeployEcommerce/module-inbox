<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Api;

use DeployEcommerce\Inbox\Api\Data\MessageInterface;
use DeployEcommerce\Inbox\Api\Data\MessageSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Read and administrative write access to inbox messages.
 *
 * This is the only supported way to read inbox data. The table names are private
 * implementation detail and may change; do not query them directly.
 *
 * Unlike InboxWriterInterface, these methods DO throw: they serve admin controllers and
 * integrations that need to know whether the operation succeeded. High-volume logging
 * belongs on InboxWriterInterface.
 *
 * @api
 */
interface MessageRepositoryInterface
{
    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $messageId): MessageInterface;

    /**
     * @throws CouldNotSaveException
     */
    public function save(MessageInterface $message): MessageInterface;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(MessageInterface $message): bool;

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotDeleteException
     */
    public function deleteById(int $messageId): bool;

    public function getList(SearchCriteriaInterface $searchCriteria): MessageSearchResultsInterface;

    /**
     * Mark a message read or unread, recording which admin user did it.
     *
     * @throws NoSuchEntityException
     * @throws CouldNotSaveException
     */
    public function setReadStatus(int $messageId, bool $isRead, ?int $adminUserId = null): MessageInterface;

    /**
     * Pin or unpin a message, exempting it from retention.
     *
     * @throws NoSuchEntityException
     * @throws CouldNotSaveException
     */
    public function setNeverDelete(int $messageId, bool $neverDelete): MessageInterface;
}
