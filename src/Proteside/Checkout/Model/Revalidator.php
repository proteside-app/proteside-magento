<?php
/**
 * Runs the status + remote-config refresh for every SDK key configured on any store view and
 * flushes the full-page cache when the result changes what the storefront emits.
 *
 * Triggered by: cron (Cron\Revalidate), config save (Observer\FlushPageCache), the admin
 * "Check status" button (Controller\Adminhtml\Status\Check) and bin/magento proteside:status --refresh.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Model;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Proteside\Checkout\Model\Api\Client;
use Proteside\Checkout\Model\Cache\PageCacheFlusher;
use Psr\Log\LoggerInterface;

class Revalidator
{
    private Config $config;
    private Client $client;
    private Entitlement $entitlement;
    private Snippet $snippet;
    private StoreManagerInterface $storeManager;
    private PageCacheFlusher $pageCacheFlusher;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        Client $client,
        Entitlement $entitlement,
        Snippet $snippet,
        StoreManagerInterface $storeManager,
        PageCacheFlusher $pageCacheFlusher,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->client = $client;
        $this->entitlement = $entitlement;
        $this->snippet = $snippet;
        $this->storeManager = $storeManager;
        $this->pageCacheFlusher = $pageCacheFlusher;
        $this->logger = $logger;
    }

    /**
     * Distinct valid keys across all store views (enabled or not), with the store views using them.
     *
     * @return array<string, array{site: string, stores: array<int, string>}>
     */
    public function collectTargets(): array
    {
        $targets = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            $key = $this->config->getSdkKey($storeId);
            if (!$this->snippet->isValidKey($key)) {
                continue;
            }
            if (!isset($targets[$key])) {
                $targets[$key] = [
                    'site' => (string)$store->getBaseUrl(UrlInterface::URL_TYPE_WEB),
                    'stores' => [],
                ];
            }
            $targets[$key]['stores'][] = (string)$store->getCode();
        }
        return $targets;
    }

    /**
     * @return array<string, array<string, mixed>> key => state
     */
    public function revalidateAll(): array
    {
        $states = [];
        $flush = false;
        foreach ($this->collectTargets() as $key => $target) {
            $before = $this->entitlement->load($key);
            $states[$key] = $this->revalidateKey($key, $target['site'], false);
            if ($this->outputChanged($before, $states[$key])) {
                $flush = true;
            }
        }
        if ($flush) {
            $this->pageCacheFlusher->flush();
        }
        return $states;
    }

    /**
     * Status check, then remote config refresh (rules, GTM containers, channel).
     *
     * @return array<string, mixed> new state
     */
    public function revalidateKey(string $key, string $siteUrl, bool $flushOnChange = true): array
    {
        $before = $this->entitlement->load($key);

        $status = $this->client->status($key, $siteUrl);
        $state = $this->entitlement->applyStatus($key, $status);

        $config = $this->client->config($key);
        $state = $this->entitlement->applyConfig($key, $config);

        $this->logger->info(sprintf(
            'Proteside: status for key %s… = %s (http %d), config http %d, %d rule(s), %d GTM container(s)',
            substr($key, 0, 12),
            $state['status'],
            (int)$state['http_code'],
            (int)$state['config_http_code'],
            count($state['inline_rules']),
            count($state['inline_gtm'])
        ));

        if ($flushOnChange && $this->outputChanged($before, $state)) {
            $this->pageCacheFlusher->flush();
        }
        return $state;
    }

    /**
     * Would the storefront output differ between the two states? (decision, channel, inline config, CDN base)
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function outputChanged(array $before, array $after): bool
    {
        if (Entitlement::decide($before) !== Entitlement::decide($after)) {
            return true;
        }
        if (($before['channel'] ?? null) !== ($after['channel'] ?? null)) {
            return true;
        }
        if (($before['cdn_base'] ?? null) !== ($after['cdn_base'] ?? null)) {
            return true;
        }
        return json_encode([$before['inline_rules'] ?? [], $before['inline_gtm'] ?? []])
            !== json_encode([$after['inline_rules'] ?? [], $after['inline_gtm'] ?? []]);
    }
}
