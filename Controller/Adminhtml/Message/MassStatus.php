<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Controller\Adminhtml\Message;

use DeployEcommerce\Inbox\Model\ResourceModel\Message as MessageResource;
use DeployEcommerce\Inbox\Model\ResourceModel\Message\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Marks the selected messages read or unread.
 *
 * Uses one bulk UPDATE rather than loading and saving each row. There is no per-row
 * business logic here, and this action has to survive "Select All" across a filtered set
 * that can run to thousands of rows after an integration failure.
 */
class MassStatus extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'DeployEcommerce_Inbox::message_status';

    public function __construct(
        Action\Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly ResourceConnection $resource,
        private readonly Session $authSession
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $status = (string)$this->getRequest()->getParam('status');

        if (!in_array($status, ['read', 'unread'], true)) {
            $this->messageManager->addErrorMessage(__('Unknown status.'));

            return $redirect->setPath('*/*/index');
        }

        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $ids = $collection->getAllIds();

            if ($ids === []) {
                $this->messageManager->addNoticeMessage(__('No messages were selected.'));

                return $redirect->setPath('*/*/index');
            }

            $isRead = $status === 'read';
            $connection = $this->resource->getConnection();
            $updated = $connection->update(
                $this->resource->getTableName(MessageResource::MAIN_TABLE),
                [
                    'is_read' => $isRead ? 1 : 0,
                    'read_by_admin_id' => $isRead ? (int)$this->authSession->getUser()?->getId() : null,
                    'read_at' => $isRead ? gmdate('Y-m-d H:i:s') : null,
                ],
                ['message_id IN (?)' => $ids]
            );

            $this->messageManager->addSuccessMessage(
                __('%1 message(s) were marked as %2.', $updated, $status)
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                __('The messages could not be updated: %1', $e->getMessage())
            );
        }

        return $redirect->setPath('*/*/index');
    }
}
