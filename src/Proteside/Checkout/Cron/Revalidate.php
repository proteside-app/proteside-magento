<?php
/**
 * Cron job proteside_checkout_revalidate (default every 6 h; 12 h / 24 h configurable).
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Cron;

use Proteside\Checkout\Model\Revalidator;
use Psr\Log\LoggerInterface;

class Revalidate
{
    private Revalidator $revalidator;
    private LoggerInterface $logger;

    public function __construct(Revalidator $revalidator, LoggerInterface $logger)
    {
        $this->revalidator = $revalidator;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        try {
            $states = $this->revalidator->revalidateAll();
            $this->logger->info(sprintf('Proteside: cron revalidation finished for %d key(s).', count($states)));
        } catch (\Throwable $e) {
            $this->logger->error('Proteside: cron revalidation failed: ' . $e->getMessage());
        }
    }
}
