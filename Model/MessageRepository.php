<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model;

use DeployEcommerce\Inbox\Api\Data\MessageInterface;
use DeployEcommerce\Inbox\Api\Data\MessageSearchResultsInterface;
use DeployEcommerce\Inbox\Api\Data\MessageSearchResultsInterfaceFactory;
use DeployEcommerce\Inbox\Api\MessageRepositoryInterface;
use DeployEcommerce\Inbox\Model\ResourceModel\Message as MessageResource;
use DeployEcommerce\Inbox\Model\ResourceModel\Message\CollectionFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

class MessageRepository implements MessageRepositoryInterface
{
    public function __construct(
        private readonly MessageResource $resource,
        private readonly MessageFactory $messageFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly MessageSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly ResourceConnection $connection
    ) {
    }

    public function getById(int $messageId): MessageInterface
    {
        $message = $this->messageFactory->create();
        $this->resource->load($message, $messageId);

        if (!$message->getMessageId()) {
            throw new NoSuchEntityException(
                __('Inbox message with ID "%1" does not exist.', $messageId)
            );
        }

        $message->setTags($this->loadTags($messageId));

        return $message;
    }

    public function save(MessageInterface $message): MessageInterface
    {
        try {
            /** @var Message $message */
            $this->resource->save($message);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(__('Could not save the inbox message: %1', $e->getMessage()), $e);
        }

        return $message;
    }

    public function delete(MessageInterface $message): bool
    {
        try {
            /** @var Message $message */
            $this->resource->delete($message);
        } catch (\Throwable $e) {
            throw new CouldNotDeleteException(__('Could not delete the inbox message: %1', $e->getMessage()), $e);
        }

        return true;
    }

    public function deleteById(int $messageId): bool
    {
        return $this->delete($this->getById($messageId));
    }

    public function getList(SearchCriteriaInterface $searchCriteria): MessageSearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        /** @var MessageSearchResultsInterface $results */
        $results = $this->searchResultsFactory->create();
        $results->setSearchCriteria($searchCriteria);
        $results->setItems($collection->getItems());
        $results->setTotalCount($collection->getSize());

        return $results;
    }

    public function setReadStatus(int $messageId, bool $isRead, ?int $adminUserId = null): MessageInterface
    {
        $message = $this->getById($messageId);
        $message->setIsRead($isRead);

        // Read state is global rather than per admin user: a shared operations queue wants
        // one person triaging a message to take it off everyone's list. These two columns
        // still record who did it, which is the audit question without the row fan-out.
        $message->setReadByAdminId($isRead ? $adminUserId : null);
        $message->setReadAt($isRead ? gmdate('Y-m-d H:i:s') : null);

        return $this->save($message);
    }

    public function setNeverDelete(int $messageId, bool $neverDelete): MessageInterface
    {
        $message = $this->getById($messageId);
        $message->setNeverDelete($neverDelete);

        return $this->save($message);
    }

    /**
     * @return string[]
     */
    private function loadTags(int $messageId): array
    {
        $connection = $this->connection->getConnection();
        $select = $connection->select()
            ->from($this->connection->getTableName(MessageResource::TAG_TABLE), 'tag')
            ->where('message_id = ?', $messageId)
            ->order('tag ASC');

        return array_map('strval', $connection->fetchCol($select));
    }
}
