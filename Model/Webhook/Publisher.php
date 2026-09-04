<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Webhook;

use DeployEcommerce\Inbox\Api\Data\WebhookJobInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;

/**
 * Publishes a delivery job onto the queue.
 *
 * Publishing is best effort. The outbox row is the real record, and the sweeper cron will
 * pick up anything that was never published, so a failure here delays delivery rather than
 * losing it. That is why nothing in this class is allowed to propagate an exception.
 */
class Publisher
{
    public const TOPIC = 'deployecommerce.inbox.webhook.dispatch';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly WebhookJobFactory $jobFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function publish(int $messageId, string $deliveryUuid, int $occurrenceNo, int $attemptNo): bool
    {
        try {
            /** @var WebhookJobInterface $job */
            $job = $this->jobFactory->create();
            $job->setMessageId($messageId)
                ->setDeliveryUuid($deliveryUuid)
                ->setOccurrenceNo($occurrenceNo)
                ->setAttemptNo($attemptNo)
                ->setPublishedAt(gmdate('c'));

            $this->publisher->publish(self::TOPIC, $job);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Inbox: could not publish webhook job, the sweeper will retry.',
                ['message_id' => $messageId, 'error' => $e->getMessage()]
            );

            return false;
        }
    }
}
