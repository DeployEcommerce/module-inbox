<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Webhook\Message;

use DeployEcommerce\Inbox\Model\Webhook\HealthState;
use Magento\Framework\Notification\MessageInterface;

/**
 * Admin notification raised when webhook forwarding is not working.
 *
 * Uses Magento's own system message channel rather than the inbox, which keeps the report
 * of a broken webhook outside the path that the broken webhook would take.
 */
class Failing implements MessageInterface
{
    private const IDENTITY = 'deployecommerce_inbox_webhook_failing';

    public function __construct(private readonly HealthState $healthState)
    {
    }

    public function getIdentity(): string
    {
        return hash('sha256', self::IDENTITY);
    }

    public function isDisplayed(): bool
    {
        return $this->healthState->get() !== [];
    }

    public function getText(): string
    {
        $problems = $this->healthState->get();

        return (string)__(
            'Inbox forwarding needs attention: %1',
            implode(' ', $problems)
        );
    }

    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }
}
