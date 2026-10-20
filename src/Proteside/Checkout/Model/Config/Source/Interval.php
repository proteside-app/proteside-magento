<?php
/**
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Interval implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => '6', 'label' => __('6 hours')],
            ['value' => '12', 'label' => __('12 hours')],
            ['value' => '24', 'label' => __('24 hours')],
        ];
    }
}
