<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

/**
 * @api
 */
interface MessageSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return MessageInterface[]
     */
    public function getItems();

    /**
     * @param MessageInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
