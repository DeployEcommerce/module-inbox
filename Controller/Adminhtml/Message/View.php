<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Controller\Adminhtml\Message;

use DeployEcommerce\Inbox\Api\MessageRepositoryInterface;
use DeployEcommerce\Inbox\Model\Severity;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Returns one message, including its body, for the grid modal.
 *
 * The body is fetched here rather than carried in the listing payload: bodies are
 * MEDIUMTEXT and putting twenty of them into every grid render would bloat the response
 * and the client-side cache for content that is usually never opened.
 *
 * The body is returned as a raw JSON string and is never escaped here. Escaping happens
 * exactly once, at render, where the template binds it as text. Escaping in both places
 * would double-encode every stack trace and JSON payload.
 */
class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'DeployEcommerce_Inbox::message';

    /**
     * Beyond this the modal is unusable and the browser struggles, so the response is cut
     * short and the client offers a download instead.
     */
    private const MAX_BODY_BYTES = 262144;

    public function __construct(
        Action\Context $context,
        private readonly MessageRepositoryInterface $messageRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $rawId = $this->getRequest()->getParam('id');

        // Reject a non-scalar id rather than casting it. Casting an array to int yields 1,
        // which would silently look up an unrelated message instead of reporting a bad
        // request.
        if (!is_scalar($rawId) || !ctype_digit((string)$rawId)) {
            return $result->setHttpResponseCode(400)
                ->setData(['error' => (string)__('A valid message ID is required.')]);
        }

        $messageId = (int)$rawId;

        try {
            $message = $this->messageRepository->getById($messageId);
        } catch (NoSuchEntityException) {
            return $result->setHttpResponseCode(404)
                ->setData(['error' => (string)__('That message no longer exists.')]);
        }

        $body = (string)$message->getBody();
        $truncated = strlen($body) > self::MAX_BODY_BYTES;

        return $result->setData([
            'message_id' => $message->getMessageId(),
            'title' => $message->getTitle(),
            'severity' => $message->getSeverity(),
            'severity_label' => Severity::normalize($message->getSeverity())->label(),
            'source' => $message->getSource(),
            'tags' => $message->getTags(),
            'occurrences' => $message->getOccurrences(),
            'never_delete' => $message->getNeverDelete(),
            'is_read' => $message->getIsRead(),
            'created_at' => $message->getCreatedAt(),
            'last_seen_at' => $message->getLastSeenAt(),
            'body' => $truncated ? mb_strcut($body, 0, self::MAX_BODY_BYTES) : $body,
            'truncated' => $truncated,
            'size' => strlen($body),
        ]);
    }
}
