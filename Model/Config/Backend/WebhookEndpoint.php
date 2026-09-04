<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Config\Backend;

use DeployEcommerce\Inbox\Model\Webhook\UrlGuard;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Validates the webhook endpoint when it is saved.
 *
 * This is the convenience check, not the security boundary. Configuration can be set
 * through env.php or config.php, which bypasses backend models entirely, and a hostname
 * that resolved safely at save time can resolve elsewhere later. The authoritative check
 * runs in the consumer immediately before every request.
 */
class WebhookEndpoint extends Value
{
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly UrlGuard $urlGuard,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave(): self
    {
        $url = trim((string)$this->getValue());

        if ($url === '') {
            return parent::beforeSave();
        }

        // Throws LocalizedException, which the configuration form renders directly.
        $this->urlGuard->resolve($url);

        return parent::beforeSave();
    }
}
