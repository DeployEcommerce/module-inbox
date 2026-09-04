<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Controller\Adminhtml\Message;

use DeployEcommerce\Inbox\Model\ResourceModel\Message as MessageResource;
use DeployEcommerce\Inbox\Model\ResourceModel\Message\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Deletes the selected messages.
 *
 * A manual delete removes pinned messages too. never_delete governs automatic retention,
 * not an administrator's explicit decision.
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'DeployEcommerce_Inbox::message_delete';

    public function __construct(
        Action\Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $ids = $collection->getAllIds();

            if ($ids === []) {
                $this->messageManager->addNoticeMessage(__('No messages were selected.'));

                return $redirect->setPath('*/*/index');
            }

            $connection = $this->resource->getConnection();
            // Tag rows cascade, but delete them explicitly so the removal is visible to
            // anything reading the binary log.
            $connection->delete(
                $this->resource->getTableName(MessageResource::TAG_TABLE),
                ['message_id IN (?)' => $ids]
            );
            $deleted = $connection->delete(
                $this->resource->getTableName(MessageResource::MAIN_TABLE),
                ['message_id IN (?)' => $ids]
            );

            $this->messageManager->addSuccessMessage(__('%1 message(s) were deleted.', $deleted));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                __('The messages could not be deleted: %1', $e->getMessage())
            );
        }

        return $redirect->setPath('*/*/index');
    }
}
