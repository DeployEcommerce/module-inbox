<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Config\Backend;

use Magento\Cron\Model\ScheduleFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Validates the purge schedule on save.
 *
 * Magento ships no validator for a cron expression, and an invalid one does not surface an
 * error anywhere: the job simply never runs again. Validating here turns a silent,
 * permanent failure into an immediate message on the configuration form.
 */
class CronExpression extends Value
{
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly ScheduleFactory $scheduleFactory,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave(): self
    {
        $expression = trim((string)$this->getValue());

        // Empty is allowed and meaningful: the job falls back to the schedule declared in
        // crontab.xml rather than stopping.
        if ($expression === '') {
            return parent::beforeSave();
        }

        $parts = preg_split('/\s+/', $expression) ?: [];

        if (count($parts) !== 5) {
            throw new LocalizedException(
                __('The purge schedule must be a standard five-field cron expression, for example "0 3 * * *".')
            );
        }

        try {
            $schedule = $this->scheduleFactory->create();
            $schedule->setCronExpr($expression);
            // Force the expression to be parsed: setCronExpr alone only stores it.
            $schedule->trySchedule();
        } catch (\Throwable $e) {
            throw new LocalizedException(
                __('"%1" is not a valid cron expression.', $expression)
            );
        }

        return parent::beforeSave();
    }
}
