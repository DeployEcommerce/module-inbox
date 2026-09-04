<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Config\Source;

use DeployEcommerce\Inbox\Model\Webhook\WebhookConfig;
use Magento\Framework\Data\OptionSourceInterface;

class BodyPolicy implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => WebhookConfig::BODY_NONE, 'label' => __('Do not send message bodies')],
            ['value' => WebhookConfig::BODY_TRUNCATED, 'label' => __('Send a truncated body')],
            ['value' => WebhookConfig::BODY_FULL, 'label' => __('Send the full body')],
        ];
    }
}
