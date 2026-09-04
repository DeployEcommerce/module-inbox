<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Cron;

use DeployEcommerce\Inbox\Model\Config;
use DeployEcommerce\Inbox\Model\ResourceModel\Message as MessageResource;
use DeployEcommerce\Inbox\Model\Severity;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;

/**
 * Deletes messages that have outgrown their retention tier.
 *
 * Retention is tiered rather than a single age, because one flat window is wrong at both
 * ends: seven days is generous for routine notices and far too short for the integration
 * failure someone investigates a fortnight later while reconciling stock.
 *
 * Precedence, highest first:
 *   1. never_delete   - never purged, whatever else applies
 *   2. unread         - kept until the unread cap, because nobody has seen it yet
 *   3. severity tier  - error and above kept for the extended period
 *   4. everything else - the baseline retention
 */
class PurgeMessages
{
    private const LOCK_NAME = 'deployecommerce_inbox_purge';

    /**
     * Severity values below and at/above the configured threshold are expanded into literal
     * IN lists rather than expressed as a range. A range predicate in the middle of a
     * composite index stops the optimiser using the columns after it, which would leave
     * last_seen_at as a filter rather than a range and turn each batch into a scan.
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly LockManagerInterface $lockManager,
        private readonly TimezoneInterface $timezone,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isPurgeEnabled()) {
            return;
        }

        // Zero wait: a manual cron:run colliding with the scheduled run should skip, not
        // queue up behind it.
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            $this->logger->info('Inbox purge: another run holds the lock, skipping.');

            return;
        }

        $started = microtime(true);
        $deadline = $started + $this->config->getMaxRuntimeSeconds();
        $deleted = 0;
        $batches = 0;
        $halted = 'complete';

        try {
            foreach ($this->passes() as $label => $pass) {
                [$severities, $cutoff, $readFilter] = $pass;

                if ($severities === []) {
                    continue;
                }

                while (true) {
                    if (microtime(true) >= $deadline) {
                        $halted = 'max_runtime';
                        break 2;
                    }

                    if ($this->pastWindowEnd()) {
                        $halted = 'window_end';
                        break 2;
                    }

                    $removed = $this->deleteBatch($severities, $cutoff, $readFilter);
                    $deleted += $removed;
                    $batches++;

                    if ($removed < $this->config->getBatchSize()) {
                        break;
                    }

                    // Give replicas a moment and release lock pressure between batches.
                    usleep(50_000);
                }

                unset($label);
            }

            $this->warnOnPinnedGrowth();
        } catch (\Throwable $e) {
            $this->logger->error('Inbox purge: failed.', ['error' => $e->getMessage()]);

            throw $e;
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }

        $message = sprintf(
            'Inbox purge: deleted=%d batches=%d duration=%.1fs halted=%s',
            $deleted,
            $batches,
            microtime(true) - $started,
            $halted
        );

        if ($halted === 'complete') {
            $this->logger->info($message);
        } else {
            // A deliberate stop is not an error: throwing would mark the cron row failed and
            // in some setups trigger a retry, which is how a purge ends up running in
            // business hours.
            $this->logger->warning($message);
        }
    }

    /**
     * Build the retention passes.
     *
     * The third element is a tri-state read filter: null purges regardless of read state,
     * true purges only read messages, false only unread ones. A plain boolean cannot express
     * all three, and conflating "no filter" with "unread only" silently widens a pass.
     *
     * @return array<string, array{0: int[], 1: string, 2: bool|null}>
     */
    private function passes(): array
    {
        $threshold = $this->config->getExtendedSeverityThreshold();
        $low = [];
        $high = [];

        foreach (Severity::cases() as $case) {
            if ($case->value >= $threshold) {
                $high[] = $case->value;
            } else {
                $low[] = $case->value;
            }
        }

        $keepUnread = $this->config->shouldKeepUnreadLonger();

        // When unread messages get their own longer clock, the two severity passes must be
        // restricted to read messages, or they would delete unread ones on the short clock
        // and the unread tier would never apply.
        $readFilter = $keepUnread ? true : null;

        $passes = [
            'routine' => [$low, $this->cutoff($this->config->getRetentionDays()), $readFilter],
            'extended' => [$high, $this->cutoff($this->config->getExtendedRetentionDays()), $readFilter],
        ];

        if ($keepUnread) {
            // The cap is deliberately finite. "Never purge unread" sounds safe, but unread is
            // the default state, so an uncapped rule would make the whole table immortal.
            $passes['unread'] = [
                array_merge($low, $high),
                $this->cutoff($this->config->getUnreadMaxDays()),
                false,
            ];
        }

        return $passes;
    }

    /**
     * @param int[] $severities
     */
    private function deleteBatch(array $severities, string $cutoff, ?bool $readFilter): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(MessageResource::MAIN_TABLE);
        $batchSize = $this->config->getBatchSize();

        // Select a page of primary keys first, then delete by key. A bare
        // DELETE ... ORDER BY ... LIMIT takes gap locks across the whole scanned range under
        // REPEATABLE READ, which blocks the very integrations writing into this table.
        // Deleting by an explicit key list locks only those rows.
        $select = $connection->select()
            ->from($table, 'message_id')
            ->where('never_delete = ?', 0)
            ->where('severity IN (?)', $severities)
            ->where('COALESCE(last_seen_at, created_at) < ?', $cutoff)
            ->order('message_id ASC')
            ->limit($batchSize);

        if ($readFilter !== null) {
            $select->where('is_read = ?', $readFilter ? 1 : 0);
        }

        $ids = $connection->fetchCol($select);

        if ($ids === []) {
            return 0;
        }

        // Tag rows would cascade via the foreign key, but InnoDB performs cascades inside
        // the storage engine and they are not reliably emitted as separate binlog events,
        // so anything reading the binlog would drift. Delete them explicitly as well.
        $connection->beginTransaction();

        try {
            $connection->delete(
                $this->resource->getTableName(MessageResource::TAG_TABLE),
                ['message_id IN (?)' => $ids]
            );
            $removed = $connection->delete($table, ['message_id IN (?)' => $ids]);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        return (int)$removed;
    }

    private function warnOnPinnedGrowth(): void
    {
        $threshold = $this->config->getPinnedWarnThreshold();

        if ($threshold === 0) {
            return;
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(MessageResource::MAIN_TABLE), 'COUNT(*)')
            ->where('never_delete = ?', 1);

        $pinned = (int)$connection->fetchOne($select);

        if ($pinned > $threshold) {
            $this->logger->warning(sprintf(
                'Inbox purge: %d pinned messages exceed the warning threshold of %d. Pinned '
                . 'messages are never purged, so they grow without bound.',
                $pinned,
                $threshold
            ));
        }
    }

    private function cutoff(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - ($days * 86400));
    }

    /**
     * The wall-clock guard, compared in the store's own timezone.
     *
     * The runtime budget alone does not keep the purge out of business hours: a job that
     * starts four hours late still gets its full budget. Comparing in UTC would also be an
     * hour out for half the year in the UK.
     */
    private function pastWindowEnd(): bool
    {
        $window = $this->config->getWindowEnd();

        if ($window === null) {
            return false;
        }

        [$hour, $minute] = $window;
        $now = $this->timezone->date();
        $end = (clone $now)->setTime($hour, $minute);

        return $now > $end;
    }
}
