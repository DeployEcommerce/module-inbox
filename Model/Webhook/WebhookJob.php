<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Webhook;

use DeployEcommerce\Inbox\Api\Data\WebhookJobInterface;
use Magento\Framework\DataObject;

/**
 * Concrete queue payload.
 *
 * Extends DataObject so Magento's ServiceInputProcessor can rebuild it from the decoded
 * queue message via the setters.
 */
class WebhookJob extends DataObject implements WebhookJobInterface
{
    public function getMessageId()
    {
        return (int)$this->getData('message_id');
    }

    public function setMessageId($messageId)
    {
        return $this->setData('message_id', (int)$messageId);
    }

    public function getOccurrenceNo()
    {
        return (int)$this->getData('occurrence_no');
    }

    public function setOccurrenceNo($occurrenceNo)
    {
        return $this->setData('occurrence_no', (int)$occurrenceNo);
    }

    public function getAttemptNo()
    {
        return (int)$this->getData('attempt_no');
    }

    public function setAttemptNo($attemptNo)
    {
        return $this->setData('attempt_no', (int)$attemptNo);
    }

    public function getDeliveryUuid()
    {
        return (string)$this->getData('delivery_uuid');
    }

    public function setDeliveryUuid($deliveryUuid)
    {
        return $this->setData('delivery_uuid', (string)$deliveryUuid);
    }

    public function getPublishedAt()
    {
        return (string)$this->getData('published_at');
    }

    public function setPublishedAt($publishedAt)
    {
        return $this->setData('published_at', (string)$publishedAt);
    }
}
