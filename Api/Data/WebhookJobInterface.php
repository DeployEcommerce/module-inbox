<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Api\Data;

/**
 * Queue payload for one webhook delivery.
 *
 * Deliberately carries an id rather than the message itself. A full message body in the
 * payload would duplicate potentially personal data into the queue tables, where it lives
 * under MySQL MQ's own seven-day retention rather than the inbox's configured retention —
 * a second copy no administrator knows about. It would also multiply queue table growth by
 * roughly the body size for every attempt.
 *
 * The consumer re-loads the row, so it always sends current truth. The one value that
 * cannot be re-derived is the occurrence count at the moment forwarding was triggered,
 * which is why it is carried here.
 *
 * @api
 */
interface WebhookJobInterface
{
    /**
     * @return int
     */
    public function getMessageId();

    /**
     * @param int $messageId
     * @return $this
     */
    public function setMessageId($messageId);

    /**
     * @return int
     */
    public function getOccurrenceNo();

    /**
     * @param int $occurrenceNo
     * @return $this
     */
    public function setOccurrenceNo($occurrenceNo);

    /**
     * @return int
     */
    public function getAttemptNo();

    /**
     * @param int $attemptNo
     * @return $this
     */
    public function setAttemptNo($attemptNo);

    /**
     * @return string
     */
    public function getDeliveryUuid();

    /**
     * @param string $deliveryUuid
     * @return $this
     */
    public function setDeliveryUuid($deliveryUuid);

    /**
     * @return string
     */
    public function getPublishedAt();

    /**
     * @param string $publishedAt
     * @return $this
     */
    public function setPublishedAt($publishedAt);
}
