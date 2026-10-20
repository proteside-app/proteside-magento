<?php
/**
 * Full-page cache helper.
 *
 * flush() does what Admin > Cache Management > "Refresh" on Full Page Cache does: cleans the
 * built-in FPC storage and dispatches `adminhtml_cache_refresh_type`, which Magento_CacheInvalidate
 * turns into a Varnish purge-all when Varnish is the configured caching application.
 *
 * With the built-in FPC the snippet is injected after the cache (see Observer\InjectSnippet), so a
 * flush is only needed for Varnish/other reverse proxies — doing it in both cases keeps things simple.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Model\Cache;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\PageCache\Model\Cache\Type as PageCacheType;
use Psr\Log\LoggerInterface;

class PageCacheFlusher
{
    private TypeListInterface $typeList;
    private EventManager $eventManager;
    private LoggerInterface $logger;

    public function __construct(TypeListInterface $typeList, EventManager $eventManager, LoggerInterface $logger)
    {
        $this->typeList = $typeList;
        $this->eventManager = $eventManager;
        $this->logger = $logger;
    }

    public function flush(): void
    {
        try {
            $this->typeList->cleanType(PageCacheType::TYPE_IDENTIFIER);
            $this->eventManager->dispatch('adminhtml_cache_refresh_type', ['type' => PageCacheType::TYPE_IDENTIFIER]);
        } catch (\Throwable $e) {
            $this->logger->warning('Proteside: full-page cache flush failed: ' . $e->getMessage());
        }
    }

    public function invalidate(): void
    {
        try {
            $this->typeList->invalidate(PageCacheType::TYPE_IDENTIFIER);
        } catch (\Throwable $e) {
            $this->logger->warning('Proteside: full-page cache invalidation failed: ' . $e->getMessage());
        }
    }
}
