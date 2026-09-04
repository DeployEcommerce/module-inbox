<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\ResourceModel\Message\Grid;

use DeployEcommerce\Inbox\Model\ResourceModel\Message as MessageResource;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Grid collection for the message listing.
 *
 * A real class rather than a virtualType because three things here cannot be expressed in
 * di.xml:
 *
 *  1. The body column is excluded. SearchResult selects main_table.*, which would drag a
 *     MEDIUMTEXT through PHP and into the grid's JSON for every row on every render.
 *  2. Tags live in a separate table and are joined as a concatenated column.
 *  3. Filtering by tag has to become an EXISTS subquery, because tags is not a real column
 *     and filtering the concatenated string would neither use an index nor be correct.
 */
class Collection extends SearchResult
{
    /**
     * Every column except body.
     */
    private const COLUMNS = [
        'message_id',
        'severity',
        'is_read',
        'never_delete',
        'source',
        'title',
        'dedupe_hash',
        'occurrences',
        'read_by_admin_id',
        'read_at',
        'created_at',
        'last_seen_at',
    ];

    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        EventManager $eventManager,
        $mainTable = MessageResource::MAIN_TABLE,
        $resourceModel = MessageResource::class
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $mainTable, $resourceModel);
    }

    protected function _initSelect(): SearchResultInterface
    {
        $tagTable = $this->getTable(MessageResource::TAG_TABLE);

        $this->getSelect()
            ->from(['main_table' => $this->getMainTable()], self::COLUMNS)
            ->joinLeft(
                ['tag_table' => $tagTable],
                'tag_table.message_id = main_table.message_id',
                ['tags' => new \Zend_Db_Expr('GROUP_CONCAT(DISTINCT tag_table.tag ORDER BY tag_table.tag SEPARATOR ",")')]
            )
            ->group('main_table.message_id');

        return $this;
    }

    /**
     * Rewrite a tag filter into an EXISTS subquery.
     *
     * tags is a computed column, so addFieldToFilter would produce invalid SQL. An EXISTS
     * against the indexed (tag, message_id) key is also far cheaper than matching against
     * the concatenated string, and avoids the false positives that substring matching on a
     * comma-separated list produces.
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if ($field === 'tags') {
            $value = is_array($condition) ? reset($condition) : $condition;
            $value = is_array($value) ? reset($value) : $value;
            $value = strtolower(trim((string)$value));

            if ($value === '') {
                return $this;
            }

            $this->getSelect()->where(
                'EXISTS (SELECT 1 FROM ' . $this->getTable(MessageResource::TAG_TABLE)
                . ' t WHERE t.message_id = main_table.message_id AND t.tag = ?)',
                $value
            );

            return $this;
        }

        return parent::addFieldToFilter($field, $condition);
    }

    /**
     * The GROUP BY above makes the default count query return one row per group, which the
     * pager then reads as the total. Count distinct primary keys instead.
     */
    public function getSelectCountSql()
    {
        $this->_renderFilters();

        $select = clone $this->getSelect();
        $select->reset(\Magento\Framework\DB\Select::ORDER);
        $select->reset(\Magento\Framework\DB\Select::LIMIT_COUNT);
        $select->reset(\Magento\Framework\DB\Select::LIMIT_OFFSET);
        $select->reset(\Magento\Framework\DB\Select::COLUMNS);
        $select->reset(\Magento\Framework\DB\Select::GROUP);
        $select->columns(new \Zend_Db_Expr('COUNT(DISTINCT main_table.message_id)'));

        return $select;
    }
}
