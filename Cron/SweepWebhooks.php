<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Cron;

use DeployEcommerce\Inbox\Model\Webhook\AttemptRepository;
use DeployEcommerce\Inbox\Model\Webhook\Publisher;
use DeployEcommerce\Inbox\Model\Webhook\WebhookConfig;
use Psr\Log\LoggerInterface;

/**
 * Publishes outbox rows that are due for delivery.
 *
 * This, not the write-time publish, is the mechanism that actually guarantees delivery. It
 * covers every case where a queue message does not exist or never arrived: the publish
 * failed, the publish was rolled back with the caller's transaction, the queue connection
 * was reconfigured, or a previous attempt failed and is now due to be retried.
 *
 * Keeping one mechanism for all four is deliberate. Separate recovery paths for each would
 * be more code and more ways to double-deliver; the consumer's atomic claim makes a
 * duplicate publish harmless.
 */
class SweepWebhooks
{
    private const BATCH = 200;

    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly WebhookConfig $config,
        private readonly Publisher $publisher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            $due = $this->attempts->findDue(self::BATCH);

            if ($due === []) {
                return;
            }

            $published = 0;

            foreach ($due as $row) {
                $ok = $this->publisher->publish(
                    (int)$row['message_id'],
                    (string)$row['delivery_uuid'],
                    (int)$row['occurrence_no'],
                    (int)$row['attempts'] + 1
                );

                if ($ok) {
                    $published++;
                }
            }

            $this->logger->info(sprintf(
                'Inbox webhook sweep: published=%d due=%d',
                $published,
                count($due)
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Inbox webhook sweep: failed.', ['error' => $e->getMessage()]);
        }
    }
}
