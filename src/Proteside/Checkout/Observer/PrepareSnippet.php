<?php
/**
 * controller_action_predispatch (frontend): decide and pre-render the snippet before the layout is
 * rendered, so the inline hash reaches Magento_Csp's dynamic collector ahead of the CSP header.
 * On a built-in FPC hit the action is never dispatched; InjectSnippet then prepares on its own.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Observer;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Proteside\Checkout\Model\Injector;
use Psr\Log\LoggerInterface;

class PrepareSnippet implements ObserverInterface
{
    private Injector $injector;
    private LoggerInterface $logger;

    public function __construct(Injector $injector, LoggerInterface $logger)
    {
        $this->injector = $injector;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        $request = $observer->getEvent()->getData('request');
        if (!$request instanceof HttpRequest) {
            return;
        }
        try {
            $this->injector->prepare($request);
        } catch (\Throwable $e) {
            $this->logger->error('Proteside: PrepareSnippet failed: ' . $e->getMessage());
        }
    }
}
