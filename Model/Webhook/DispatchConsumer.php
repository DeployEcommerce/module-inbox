<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Webhook;

use DeployEcommerce\Inbox\Api\Data\WebhookJobInterface;
use DeployEcommerce\Inbox\Api\MessageRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Delivers one queued message to the configured endpoint.
 *
 * Nothing in this class, or anywhere else under Model/Webhook/, may depend on
 * InboxWriterInterface. A webhook failure that wrote an inbox message would trigger
 * another webhook, which would fail, which would write another message: an amplifying loop
 * that fills the queue tables and hammers an already-failing endpoint. Failures are
 * recorded in the outbox table, this module's own log channel, and an admin system
 * message. A unit test asserts this constructor never gains an inbox dependency.
 *
 * This handler never throws. Magento's queue consumer rejects a failed message with
 * requeue hardcoded to false, so any exception escaping here would move the message
 * straight to a permanent error state with no retry and no dead-letter queue.
 */
class DispatchConsumer
{
    public function __construct(
        private readonly MessageRepositoryInterface $messageRepository,
        private readonly AttemptRepository $attempts,
        private readonly WebhookConfig $config,
        private readonly UrlGuard $urlGuard,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly Transport $transport,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(WebhookJobInterface $job): void
    {
        $messageId = $job->getMessageId();

        try {
            if (!$this->config->isEnabled()) {
                $this->attempts->markTerminal(
                    $messageId,
                    0,
                    null,
                    'Forwarding disabled',
                    AttemptRepository::STATUS_ABANDONED
                );

                return;
            }

            // Another consumer already has this, or it is already finished. MySQL MQ can
            // deliver the same message more than once, so this is the idempotency guard.
            if (!$this->attempts->claim($messageId)) {
                return;
            }

            $attempt = $this->attempts->get($messageId);
            $attemptNo = (int)($attempt['attempts'] ?? 0) + 1;
            $deliveryUuid = (string)($attempt['delivery_uuid'] ?? $job->getDeliveryUuid());

            try {
                $message = $this->messageRepository->getById($messageId);
            } catch (NoSuchEntityException) {
                // Purged between publish and delivery. Losing it is correct: the message no
                // longer exists, so there is nothing to forward.
                $this->logger->info(
                    'Inbox: webhook target message no longer exists, abandoning delivery.',
                    ['message_id' => $messageId]
                );
                $this->attempts->markTerminal(
                    $messageId,
                    $attemptNo,
                    null,
                    'Message purged before delivery',
                    AttemptRepository::STATUS_ABANDONED
                );

                return;
            }

            try {
                $target = $this->urlGuard->resolve($this->config->getEndpointUrl());
            } catch (\Throwable $e) {
                // A rejected endpoint is a configuration problem, not a transient one.
                $this->attempts->markTerminal($messageId, $attemptNo, null, $e->getMessage());
                $this->logger->error(
                    'Inbox: webhook endpoint rejected by the URL guard.',
                    ['message_id' => $messageId, 'error' => $e->getMessage()]
                );

                return;
            }

            $payload = $this->payloadBuilder->build(
                $message,
                $deliveryUuid,
                $attemptNo,
                (int)($attempt['occurrence_no'] ?? $job->getOccurrenceNo())
            );
            $rawBody = $this->payloadBuilder->encode($payload);

            $result = $this->transport->send($target, $rawBody, $deliveryUuid, $attemptNo);

            if ($result->isSuccess()) {
                $this->attempts->markDelivered($messageId, $attemptNo, $result->getHttpStatus());

                return;
            }

            if ($result->isRetryable() && $attemptNo < $this->config->getMaxAttempts()) {
                $this->attempts->scheduleRetry(
                    $messageId,
                    $attemptNo,
                    $this->config->getRetryBaseDelay(),
                    $result->getHttpStatus(),
                    $result->getError()
                );
            } else {
                $this->attempts->markTerminal(
                    $messageId,
                    $attemptNo,
                    $result->getHttpStatus(),
                    $result->getError()
                );
            }

            $this->logger->warning('Inbox: webhook delivery failed.', [
                'message_id' => $messageId,
                'attempt' => $attemptNo,
                'http_status' => $result->getHttpStatus(),
                'retryable' => $result->isRetryable(),
            ]);
        } catch (\Throwable $e) {
            // Last resort. An exception escaping this method would be rejected with requeue
            // false and never retried, so swallow it and leave the outbox row to the sweeper.
            $this->logger->error('Inbox: unexpected error dispatching webhook.', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
