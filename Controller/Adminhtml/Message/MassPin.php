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
 * Pins or unpins the selected messages.
 *
 * Pinning exempts a message from every retention rule permanently, so it has its own ACL
 * resource separate from viewing: an operator who may read the inbox is not necessarily
 * the person who should be making indefinite retention decisions.
 *
 * Unpinning is reported with a count of rows that become immediately purgeable, because a
 * message that is already older than its retention age will disappear the same night and
 * that surprises people.
 */
class MassPin extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'DeployEcommerce_Inbox::message_pin';

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
        $pin = (bool)$this->getRequest()->getParam('pin');

        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $ids = $collection->getAllIds();

            if ($ids === []) {
                $this->messageManager->addNoticeMessage(__('No messages were selected.'));

                return $redirect->setPath('*/*/index');
            }

            $connection = $this->resource->getConnection();
            $updated = $connection->update(
                $this->resource->getTableName(MessageResource::MAIN_TABLE),
                ['never_delete' => $pin ? 1 : 0],
                ['message_id IN (?)' => $ids]
            );

            if ($pin) {
                $this->messageManager->addSuccessMessage(
                    __('%1 message(s) will now be kept indefinitely.', $updated)
                );
            } else {
                $this->messageManager->addSuccessMessage(
                    __(
                        '%1 message(s) are no longer pinned and will be removed once they pass '
                        . 'their retention age.',
                        $updated
                    )
                );
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                __('The messages could not be updated: %1', $e->getMessage())
            );
        }

        return $redirect->setPath('*/*/index');
    }
}
