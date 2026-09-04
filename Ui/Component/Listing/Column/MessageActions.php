<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Row actions.
 *
 * The view action carries a callback rather than an href, so the row opens in the modal
 * instead of navigating. The row-click binding in the listing targets this same action, so
 * clicking a row and choosing View follow one code path.
 */
class MessageActions extends Column
{
    private const LISTING = 'deployecommerce_inbox_message_listing.deployecommerce_inbox_message_listing';

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
            $messageId = (int)($item['message_id'] ?? 0);

            if ($messageId === 0) {
                continue;
            }

            $item[$this->getData('name')] = [
                'view' => [
                    'label' => __('View'),
                    // No href alongside the callback: the browser would navigate away before
                    // the callback ran.
                    'callback' => [[
                        'provider' => self::LISTING . '.message_modal.message_view',
                        'target' => 'openMessage',
                        'params' => [$messageId],
                    ]],
                ],
            ];
        }

        return $dataSource;
    }
}
