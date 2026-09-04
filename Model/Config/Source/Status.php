<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Status implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 0, 'label' => __('Unread')],
            ['value' => 1, 'label' => __('Read')],
        ];
    }
}
