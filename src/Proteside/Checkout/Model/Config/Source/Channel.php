<?php
/**
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Proteside\Checkout\Model\Config;
use Proteside\Checkout\Model\Snippet;

class Channel implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::CHANNEL_AUTO, 'label' => __('Follow the dashboard (recommended)')],
            ['value' => Snippet::CHANNEL_STABLE, 'label' => __('stable')],
            ['value' => Snippet::CHANNEL_LATEST, 'label' => __('latest')],
        ];
    }
}
