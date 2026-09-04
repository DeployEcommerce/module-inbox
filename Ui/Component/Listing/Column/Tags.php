<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Splits the concatenated tag column into an array for the cell template.
 *
 * This class produces data only. It builds no markup: tags originate from integrations, so
 * the template renders each one through a text binding rather than as HTML.
 */
class Tags extends Column
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
            $raw = (string)($item['tags'] ?? '');
            $item['tags'] = $raw === '' ? [] : array_values(array_filter(explode(',', $raw)));
        }

        return $dataSource;
    }
}
