<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\ResourceModel\Message;

use DeployEcommerce\Inbox\Model\Message;
use DeployEcommerce\Inbox\Model\ResourceModel\Message as MessageResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = MessageResource::ID_FIELD;

    protected function _construct(): void
    {
        $this->_init(Message::class, MessageResource::class);
    }
}
