<?php
/**
 * Proteside checkout protection for Magento 2 / Adobe Commerce.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Proteside_Checkout', __DIR__);
