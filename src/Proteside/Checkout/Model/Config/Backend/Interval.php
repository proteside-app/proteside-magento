<?php
/**
 * Writes the cron expression used by crontab.xml when the admin changes "Re-check status every".
 * Same approach as Magento\Cron\Model\Config\Backend\Sitemap.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\App\Config\ValueFactory;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Proteside\Checkout\Model\Config;

class Interval extends Value
{
    private ValueFactory $configValueFactory;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        ValueFactory $configValueFactory,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->configValueFactory = $configValueFactory;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @return $this
     * @throws CouldNotSaveException
     */
    public function afterSave()
    {
        $hours = (int)$this->getValue();
        $expression = Config::INTERVALS[$hours] ?? Config::INTERVALS[Config::DEFAULT_INTERVAL];
        try {
            $this->configValueFactory->create()
                ->load(Config::XML_CRON_EXPR, 'path')
                ->setValue($expression)
                ->setPath(Config::XML_CRON_EXPR)
                ->save();
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('We can\'t save the Proteside cron expression.'), $e);
        }
        return parent::afterSave();
    }
}
