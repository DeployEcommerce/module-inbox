<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Webhook;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Reads and writes the webhook outbox.
 *
 * Deliberately raw SQL rather than a model and repository pair. This sits on the message
 * write path, where an AbstractModel save costs several times a plain insert for no
 * benefit, and the claim in claim() has to be a single conditional UPDATE to be atomic.
 */
class AttemptRepository
{
    public const TABLE = 'deployecommerce_inbox_webhook_attempt';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ABANDONED = 'abandoned';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Queue a message for delivery.
     *
     * Called inside the caller's unit of work on purpose: if their transaction rolls back,
     * this row disappears with the message it refers to, so the outbox can never point at a
     * row that does not exist.
     */
    public function enqueue(int $messageId, string $deliveryUuid, int $occurrenceNo): void
    {
        $connection = $this->connection();

        $connection->insertOnDuplicate(
            $this->table(),
            [
                'message_id' => $messageId,
                'status' => self::STATUS_PENDING,
                'attempts' => 0,
                'delivery_uuid' => $deliveryUuid,
                'occurrence_no' => $occurrenceNo,
                'next_attempt_at' => new \Zend_Db_Expr('CURRENT_TIMESTAMP'),
            ],
            // A recurrence of a deduplicated message re-arms delivery, but keeps the original
            // delivery_uuid so a receiver can still correlate the whole incident.
            ['status', 'attempts', 'occurrence_no', 'next_attempt_at']
        );
    }

    /**
     * Atomically take ownership of an attempt.
     *
     * Returns false when another consumer already has it, or when it has reached a terminal
     * state. MySQL MQ can deliver the same message twice (a crashed consumer's in-progress
     * rows are re-queued automatically after 24 hours), so this guard is what makes the
     * consumer safe to run more than once for the same message.
     */
    public function claim(int $messageId): bool
    {
        $connection = $this->connection();

        $affected = $connection->update(
            $this->table(),
            [
                'status' => self::STATUS_SENDING,
                'last_attempt_at' => new \Zend_Db_Expr('CURRENT_TIMESTAMP'),
            ],
            [
                'message_id = ?' => $messageId,
                'status IN (?)' => [self::STATUS_PENDING, self::STATUS_FAILED],
            ]
        );

        return $affected > 0;
    }

    public function get(int $messageId): ?array
    {
        $connection = $this->connection();
        $select = $connection->select()->from($this->table())->where('message_id = ?', $messageId);
        $row = $connection->fetchRow($select);

        return $row === false ? null : $row;
    }

    public function markDelivered(int $messageId, int $attempts, ?int $httpStatus): void
    {
        $this->connection()->update(
            $this->table(),
            [
                'status' => self::STATUS_DELIVERED,
                'attempts' => $attempts,
                'last_http_status' => $httpStatus,
                'last_error' => null,
                'next_attempt_at' => null,
            ],
            ['message_id = ?' => $messageId]
        );
    }

    /**
     * Schedule another attempt.
     *
     * Backoff is exponential with full jitter: a random delay between zero and the computed
     * ceiling. Equal jitter still leaves a burst when a dead endpoint recovers and every
     * queued message retries at once; full jitter spreads them properly.
     */
    public function scheduleRetry(
        int $messageId,
        int $attempts,
        int $baseDelaySeconds,
        ?int $httpStatus,
        string $error,
        ?int $retryAfterSeconds = null
    ): void {
        $ceiling = $baseDelaySeconds * (3 ** max(0, $attempts - 1));
        $ceiling = min($ceiling, 6 * 3600);
        $delay = $retryAfterSeconds !== null
            ? min($retryAfterSeconds, $ceiling)
            : random_int(0, $ceiling);

        $this->connection()->update(
            $this->table(),
            [
                'status' => self::STATUS_PENDING,
                'attempts' => $attempts,
                'last_http_status' => $httpStatus,
                'last_error' => $error === '' ? null : $error,
                'next_attempt_at' => new \Zend_Db_Expr(
                    sprintf('DATE_ADD(CURRENT_TIMESTAMP, INTERVAL %d SECOND)', max(1, $delay))
                ),
            ],
            ['message_id = ?' => $messageId]
        );
    }

    public function markTerminal(
        int $messageId,
        int $attempts,
        ?int $httpStatus,
        string $error,
        string $status = self::STATUS_FAILED
    ): void {
        $this->connection()->update(
            $this->table(),
            [
                'status' => $status,
                'attempts' => $attempts,
                'last_http_status' => $httpStatus,
                'last_error' => $error === '' ? null : $error,
                'next_attempt_at' => null,
            ],
            ['message_id = ?' => $messageId]
        );
    }

    /**
     * Rows the sweeper should publish: due, and not already in flight.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findDue(int $limit): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table())
            ->where('status = ?', self::STATUS_PENDING)
            ->where('next_attempt_at IS NOT NULL')
            ->where('next_attempt_at <= CURRENT_TIMESTAMP')
            ->order('next_attempt_at ASC')
            ->limit($limit);

        return $connection->fetchAll($select);
    }

    /**
     * Rows stuck pending for longer than the given minutes.
     *
     * A non-zero count means messages are being queued but not delivered, which is what a
     * missing or excluded consumer looks like from the outside.
     */
    public function countStalePending(int $olderThanMinutes): int
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table(), 'COUNT(*)')
            ->where('status = ?', self::STATUS_PENDING)
            ->where(
                sprintf('created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL %d MINUTE)', $olderThanMinutes)
            );

        return (int)$connection->fetchOne($select);
    }

    public function countFailedSince(int $hours): int
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table(), 'COUNT(*)')
            ->where('status = ?', self::STATUS_FAILED)
            ->where(
                sprintf('last_attempt_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL %d HOUR)', $hours)
            );

        return (int)$connection->fetchOne($select);
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
