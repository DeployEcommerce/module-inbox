<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Ui\Component\Listing\Column;

use DeployEcommerce\Inbox\Model\Severity as SeverityEnum;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Adds a translated label alongside the stored severity integer.
 *
 * Only data. The colour class is derived in the cell's JavaScript from a whitelist, so no
 * markup is generated server-side.
 */
class Severity extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            $item['severity_label'] = SeverityEnum::normalize((int)($item['severity'] ?? 0))->label();
        }

        return $dataSource;
    }
}
