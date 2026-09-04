<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Controller\Adminhtml\Message;

use DeployEcommerce\Inbox\Api\MessageRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;

/**
 * Marks a single message read or unread.
 *
 * POST only, so Magento's admin form key validation applies. This deliberately does not
 * implement CsrfAwareActionInterface: opting out of the form key is how a state-changing
 * admin endpoint becomes a cross-site request forgery hole.
 */
class SetStatus extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'DeployEcommerce_Inbox::message_status';

    public function __construct(
        Action\Context $context,
        private readonly MessageRepositoryInterface $messageRepository,
        private readonly Session $authSession
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        $messageId = (int)$this->getRequest()->getParam('id');
        $status = (string)$this->getRequest()->getParam('status');

        if (!in_array($status, ['read', 'unread'], true)) {
            return $result->setHttpResponseCode(400)
                ->setData(['error' => (string)__('Unknown status.')]);
        }

        try {
            $this->messageRepository->setReadStatus(
                $messageId,
                $status === 'read',
                (int)$this->authSession->getUser()?->getId() ?: null
            );
        } catch (\Throwable $e) {
            return $result->setHttpResponseCode(400)->setData(['error' => $e->getMessage()]);
        }

        return $result->setData(['success' => true, 'is_read' => $status === 'read']);
    }
}
