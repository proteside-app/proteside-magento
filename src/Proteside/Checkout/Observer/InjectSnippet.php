<?php
/**
 * controller_front_send_response_before (frontend): inject the snippet right after <head …>.
 *
 * This event fires after the built-in full-page cache has stored (miss) or served (hit) the page,
 * therefore the snippet is never part of the cached entry and always reflects the current key,
 * channel, inline rules and entitlement decision. Any failure is logged and the response is left
 * untouched — the storefront must never break because of this module.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Observer;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Proteside\Checkout\Model\Injector;
use Psr\Log\LoggerInterface;

class InjectSnippet implements ObserverInterface
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
        $event = $observer->getEvent();
        $request = $event->getData('request');
        $response = $event->getData('response');
        if (!$request instanceof HttpRequest || !$response instanceof HttpResponse) {
            return;
        }
        try {
            $this->injector->inject($request, $response);
        } catch (\Throwable $e) {
            $this->logger->error('Proteside: InjectSnippet failed: ' . $e->getMessage());
        }
    }
}
