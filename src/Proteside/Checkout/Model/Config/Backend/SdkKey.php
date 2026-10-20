<?php
/**
 * Validates the SDK key format on save (^pk_(live|test)_[0-9a-f]{32}$). Empty is allowed (not configured).
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Proteside\Checkout\Model\Snippet;

class SdkKey extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        $this->setValue($value);
        if ($value !== '' && preg_match(Snippet::KEY_PATTERN, $value) !== 1) {
            throw new LocalizedException(
                __('The SDK key format is invalid. Expected pk_live_ or pk_test_ followed by 32 hex characters.')
            );
        }
        return parent::beforeSave();
    }
}
