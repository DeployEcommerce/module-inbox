<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Message extends AbstractDb
{
    public const MAIN_TABLE = 'deployecommerce_inbox_message';
    public const TAG_TABLE = 'deployecommerce_inbox_message_tag';
    public const ID_FIELD = 'message_id';

    protected function _construct(): void
    {
        $this->_init(self::MAIN_TABLE, self::ID_FIELD);
    }
}
