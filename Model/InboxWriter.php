<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model;

use DeployEcommerce\Inbox\Api\Data\MessageInterface;
use DeployEcommerce\Inbox\Api\InboxWriterInterface;
use DeployEcommerce\Inbox\Model\Webhook\AttemptRepository;
use DeployEcommerce\Inbox\Model\Webhook\Publisher;
use DeployEcommerce\Inbox\Model\Webhook\WebhookConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * The single implementation behind both the injected interface and the static facade.
 *
 * Nothing here may throw. Callers are reporting a failure they already caught, so an
 * exception raised while recording it would turn a visible problem into an invisible one.
 * Every path is wrapped, and every message is mirrored to this module's own log file so
 * the inbox is never the only copy.
 */
class InboxWriter implements InboxWriterInterface
{
    /**
     * Guards against re-entry. If persisting a message fails and that failure is itself
     * reported through this class, the second call must not attempt another write.
     */
    private bool $writing = false;

    /**
     * Messages captured while a caller's transaction was open, flushed once it has ended.
     *
     * @var array<int, array{row: array<string, mixed>, tags: string[]}>
     */
    private array $buffer = [];

    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly Redactor $redactor,
        private readonly TagNormalizer $tagNormalizer,
        private readonly TitleNormalizer $titleNormalizer,
        private readonly WebhookConfig $webhookConfig,
        private readonly AttemptRepository $attempts,
        private readonly Publisher $publisher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function log(
        string $title,
        Severity|string|int $severity = Severity::Info,
        ?string $body = null,
        string $source = 'unknown',
        array $tags = [],
        bool $neverDelete = false
    ): ?MessageInterface {
        if ($this->writing) {
            return null;
        }

        $this->writing = true;

        try {
            return $this->write($title, $severity, $body, $source, $tags, $neverDelete);
        } catch (\Throwable $e) {
            // Deliberately swallowed. See the class docblock.
            $this->logger->error('Inbox: failed to record a message.', [
                'title' => $title,
                'source' => $source,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            $this->writing = false;
        }
    }

    public function emergency(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): ?MessageInterface
    {
        return $this->log($title, Severity::Emergency, $body, $source, $tags, $neverDelete);
    }

    public function alert(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): ?MessageInterface
    {
        return $this->log($title, Severity::Alert, $body, $source, $tags, $neverDelete);
    }

    public function critical(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): ?MessageInterface
    {
        return $this->log($title, Severity::Critical, $body, $source, $tags, $neverDelete);
    }

    public function error(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): ?MessageInterface
    {
        return $this->log($title, Severity::Error, $body, $source, $tags, $neverDelete);
    }

    public function warning(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): ?MessageInterface
    {
        return $this->log($title, Severity::Warning, $body, $source, $tags, $neverDelete);
    }

    public function notice(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): ?MessageInterface
    {
        return $this->log($title, Severity::Notice, $body, $source, $tags, $neverDelete);
    }

    public function info(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): ?MessageInterface
    {
        return $this->log($title, Severity::Info, $body, $source, $tags, $neverDelete);
    }

    public function debug(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): ?MessageInterface
    {
        return $this->log($title, Severity::Debug, $body, $source, $tags, $neverDelete);
    }

    private function write(
        string $title,
        Severity|string|int $severity,
        ?string $body,
        string $source,
        array $tags,
        bool $neverDelete
    ): ?MessageInterface {
        if (!$this->config->isEnabled()) {
            $this->mirrorToLog($title, Severity::normalize($severity), $source, 'inbox disabled');

            return null;
        }

        if (!Severity::isRecognised($severity)) {
            $this->logger->warning('Inbox: unrecognised severity, recording as Error.', [
                'severity' => is_scalar($severity) ? (string)$severity : get_debug_type($severity),
                'source' => $source,
            ]);
        }

        $level = Severity::normalize($severity);
        $source = $this->normalizeSource($source);
        $tags = $this->tagNormalizer->normalize($tags, $source);

        $title = $this->redactor->sanitizeTitle($title);
        $fullTitle = $title;

        if (mb_strlen($title) > MessageInterface::TITLE_MAX_LENGTH) {
            // Truncate rather than reject: a message that cannot be stored is a message
            // lost. The database runs in strict mode, so an over-length value would throw
            // inside the caller's error handler.
            $title = mb_substr($title, 0, MessageInterface::TITLE_MAX_LENGTH - 1) . '…';
            $body = "Full title: " . $fullTitle . "\n\n" . (string)$body;
        }

        $body = $this->redactor->redact($body, $this->config->getSecretValues());
        $body = $this->capBody($body);

        $neverDelete = $neverDelete && $this->config->isPinFromApiAllowed();

        $row = [
            MessageInterface::SEVERITY => $level->value,
            MessageInterface::IS_READ => 0,
            MessageInterface::NEVER_DELETE => $neverDelete ? 1 : 0,
            MessageInterface::SOURCE => $source,
            MessageInterface::TITLE => $title,
            MessageInterface::BODY => $body,
            MessageInterface::OCCURRENCES => 1,
            MessageInterface::DEDUPE_HASH => $this->config->isDedupeEnabled()
                ? hash('sha256', $source . '|' . $level->value . '|' . $this->titleNormalizer->normalize($title))
                : null,
            MessageInterface::LAST_SEEN_AT => new \Zend_Db_Expr('CURRENT_TIMESTAMP'),
        ];

        $this->mirrorToLog($title, $level, $source, 'stored');

        // A write inside someone else's open transaction would be rolled back along with
        // the failure it describes, which is precisely when the record matters most. Defer
        // it until the transaction has ended instead.
        if ($this->connection()->getTransactionLevel() > 0) {
            $this->buffer[] = ['row' => $row, 'tags' => $tags];
            $this->registerShutdownFlush();

            return null;
        }

        return $this->persist($row, $tags);
    }

    private function persist(array $row, array $tags): ?MessageInterface
    {
        $connection = $this->connection();
        $table = $this->resource->getTableName(ResourceModel\Message::MAIN_TABLE);

        $messageId = null;

        if ($row[MessageInterface::DEDUPE_HASH] !== null) {
            $messageId = $this->collapseDuplicate($row);
        }

        if ($messageId === null) {
            $connection->insert($table, $row);
            $messageId = (int)$connection->lastInsertId($table);
            $this->insertTags($messageId, $tags);
        }

        $this->queueForwarding($messageId, $row, $tags);

        return null;
    }

    /**
     * Increment an existing row when an identical message was seen inside the window.
     *
     * The unique-key trick is deliberately avoided: a unique index on the hash would
     * collapse an incident in January into one in June forever. The window is applied as a
     * range instead, and a race that produces two rows rather than one is accepted — that
     * is a far cheaper outcome than a constraint that merges unrelated incidents.
     */
    private function collapseDuplicate(array $row): ?int
    {
        $connection = $this->connection();
        $table = $this->resource->getTableName(ResourceModel\Message::MAIN_TABLE);
        $windowHours = $this->config->getDedupeWindowHours();

        if ($windowHours <= 0) {
            return null;
        }

        $select = $connection->select()
            ->from($table, MessageInterface::MESSAGE_ID)
            ->where('dedupe_hash = ?', $row[MessageInterface::DEDUPE_HASH])
            ->where(
                sprintf('created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL %d HOUR)', $windowHours)
            )
            ->order('created_at DESC')
            ->limit(1);

        $existingId = $connection->fetchOne($select);

        if ($existingId === false || $existingId === null || $existingId === '') {
            return null;
        }

        $existingId = (int)$existingId;

        $connection->update(
            $table,
            [
                MessageInterface::OCCURRENCES => new \Zend_Db_Expr('occurrences + 1'),
                MessageInterface::LAST_SEEN_AT => new \Zend_Db_Expr('CURRENT_TIMESTAMP'),
                // Resurface it: a recurring fault should not stay marked read.
                MessageInterface::IS_READ => 0,
                MessageInterface::BODY => $row[MessageInterface::BODY],
            ],
            [MessageInterface::MESSAGE_ID . ' = ?' => $existingId]
        );

        return $existingId;
    }

    private function insertTags(int $messageId, array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $rows = [];

        foreach ($tags as $tag) {
            $rows[] = ['message_id' => $messageId, 'tag' => $tag];
        }

        $this->connection()->insertOnDuplicate(
            $this->resource->getTableName(ResourceModel\Message::TAG_TABLE),
            $rows,
            ['tag']
        );
    }

    /**
     * Write the outbox row, then publish. The outbox row is what guarantees delivery; the
     * publish is a fast path the sweeper cron makes unnecessary if it fails.
     */
    private function queueForwarding(int $messageId, array $row, array $tags): void
    {
        try {
            if (!$this->webhookConfig->shouldForward(
                (int)$row[MessageInterface::SEVERITY],
                (string)$row[MessageInterface::SOURCE],
                $tags
            )) {
                return;
            }

            $deliveryUuid = $this->uuid();
            $this->attempts->enqueue($messageId, $deliveryUuid, 1);
            $this->publisher->publish($messageId, $deliveryUuid, 1, 1);
        } catch (\Throwable $e) {
            $this->logger->warning('Inbox: could not queue a message for forwarding.', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function registerShutdownFlush(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;

        register_shutdown_function(function (): void {
            $this->flush();
        });
    }

    /**
     * Persist anything buffered while a transaction was open.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $buffered = $this->buffer;
        $this->buffer = [];

        foreach ($buffered as $entry) {
            try {
                if ($this->connection()->getTransactionLevel() > 0) {
                    // Still inside a transaction at shutdown. Persisting now would join it
                    // and could be rolled back; the log mirror already holds the content.
                    $this->logger->warning(
                        'Inbox: a buffered message could not be persisted because a transaction '
                        . 'was still open at shutdown.',
                        ['title' => $entry['row'][MessageInterface::TITLE] ?? '']
                    );
                    continue;
                }

                $this->persist($entry['row'], $entry['tags']);
            } catch (\Throwable $e) {
                $this->logger->error('Inbox: failed to flush a buffered message.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function capBody(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return $body;
        }

        $max = $this->config->getBodyMaxBytes();

        if (strlen($body) <= $max) {
            return $body;
        }

        return mb_strcut($body, 0, $max) . "\n\n[truncated: body exceeded " . $max . " bytes]";
    }

    private function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));
        $source = (string)preg_replace('/[^a-z0-9_\-.\/]/', '', $source);
        $source = mb_substr($source, 0, 128);

        if ($source === '') {
            $source = 'unknown';
        }

        if ($source === 'unknown') {
            $this->logger->warning(
                'Inbox: a message was recorded without a source. Naming the integration '
                . 'enables grid filtering, retention rules and deduplication.'
            );
        }

        return $source;
    }

    private function mirrorToLog(string $title, Severity $level, string $source, string $outcome): void
    {
        $this->logger->log(
            strtolower($level->name) === 'emergency' ? 'emergency' : strtolower($level->name),
            sprintf('[inbox:%s] %s', $source, $title),
            ['outcome' => $outcome]
        );
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
