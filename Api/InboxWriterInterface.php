<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Api;

use DeployEcommerce\Inbox\Api\Data\MessageInterface;
use DeployEcommerce\Inbox\Model\Severity;

/**
 * Writes messages into the admin inbox.
 *
 * This is a contract to CALL, not to implement. New methods may be added in minor
 * releases, so implementing it outside this module is unsupported.
 *
 * This mechanism is deliberately independent of Magento's logging. Nothing here reads
 * from or writes to Magento's loggers, and no Monolog handler forwards log records into
 * the inbox. Messages appear here only because something called this interface.
 *
 * Implementations MUST NOT throw. Callers are typically reporting a failure they have
 * already caught; if the reporting itself throws, an observable error becomes an
 * unobservable crash. Every method returns null when the write was discarded.
 *
 * @api
 */
interface InboxWriterInterface
{
    /**
     * Record a message.
     *
     * The title should be stable across occurrences: put anything that varies per
     * occurrence (order numbers, ids, timestamps) in the body. Deduplication hashes the
     * title, so a title carrying an order number never collapses.
     *
     * @param string $title Truncated to 500 chars; the untruncated value is preserved in the body.
     * @param Severity|string|int $severity Severity::Critical, 'critical', or 500.
     * @param string|null $body Redacted and size-capped before storage.
     * @param string $source Originating integration. Name it: 'unknown' defeats filtering and dedupe.
     * @param string[] $tags Normalised to [a-z0-9_-]{1,32}, deduplicated, capped at 10.
     * @param bool $neverDelete Exempt this message from every retention rule, permanently.
     */
    public function log(
        string $title,
        Severity|string|int $severity = Severity::Info,
        ?string $body = null,
        string $source = 'unknown',
        array $tags = [],
        bool $neverDelete = false
    ): ?MessageInterface;

    public function emergency(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): ?MessageInterface;

    public function alert(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): ?MessageInterface;

    public function critical(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): ?MessageInterface;

    public function error(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): ?MessageInterface;

    public function warning(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): ?MessageInterface;

    public function notice(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): ?MessageInterface;

    public function info(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): ?MessageInterface;

    public function debug(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): ?MessageInterface;
}
