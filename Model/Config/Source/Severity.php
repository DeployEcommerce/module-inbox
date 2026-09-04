<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Config\Source;

use DeployEcommerce\Inbox\Model\Severity as SeverityEnum;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Severity options for grid filters and system configuration.
 *
 * One class serves both because OptionSourceInterface is what the UI component filter and
 * system.xml source_model each expect.
 */
class Severity implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        $options = [];

        foreach (SeverityEnum::cases() as $case) {
            $options[] = ['value' => $case->value, 'label' => __($case->label())];
        }

        return $options;
    }
}
