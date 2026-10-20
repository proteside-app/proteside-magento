<?php
/**
 * Typed access to the module configuration (Stores > Configuration > Security > Proteside).
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Config
{
    public const MODULE_VERSION = '1.0.2';
    public const PLATFORM = 'magento';
    public const DASHBOARD_URL = 'https://app.proteside.com';
    public const DOCS_URL = 'https://docs.proteside.com';
    public const PLANS_URL = 'https://app.proteside.com/plans';

    public const XML_ENABLED = 'proteside/general/enabled';
    public const XML_SDK_KEY = 'proteside/general/sdk_key';
    public const XML_INTERVAL = 'proteside/status/interval';
    public const XML_PAGE_CHECKOUT = 'proteside/pages/checkout';
    public const XML_PAGE_ORDER_SUCCESS = 'proteside/pages/order_success';
    public const XML_PAGE_CART = 'proteside/pages/cart';
    public const XML_PAGE_ALL_STORE = 'proteside/pages/all_store';
    public const XML_PAGE_CUSTOM = 'proteside/pages/custom_paths';
    public const XML_CHANNEL = 'proteside/options/channel';
    public const XML_CALIBRATION = 'proteside/options/calibration';
    public const XML_INLINE_RULES = 'proteside/options/inline_rules';
    public const XML_CRON_EXPR = 'crontab/default/jobs/proteside_checkout_revalidate/schedule/cron_expr';

    public const CHANNEL_AUTO = 'auto';

    /** Allowed re-check intervals (hours) and their cron expressions. */
    public const INTERVALS = [
        6 => '0 */6 * * *',
        12 => '0 */12 * * *',
        24 => '0 3 * * *',
    ];
    public const DEFAULT_INTERVAL = 6;

    private ScopeConfigInterface $scopeConfig;
    private StoreManagerInterface $storeManager;
    private Snippet $snippet;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        Snippet $snippet
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->snippet = $snippet;
    }

    public function isEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getSdkKey($storeId = null): string
    {
        $value = $this->scopeConfig->getValue(self::XML_SDK_KEY, ScopeInterface::SCOPE_STORE, $storeId);
        return is_string($value) ? trim($value) : '';
    }

    public function hasValidSdkKey($storeId = null): bool
    {
        return $this->snippet->isValidKey($this->getSdkKey($storeId));
    }

    /**
     * "auto" (follow the dashboard), "stable" or "latest".
     */
    public function getChannel($storeId = null): string
    {
        $value = (string)$this->scopeConfig->getValue(self::XML_CHANNEL, ScopeInterface::SCOPE_STORE, $storeId);
        if ($value === Snippet::CHANNEL_STABLE || $value === Snippet::CHANNEL_LATEST) {
            return $value;
        }
        return self::CHANNEL_AUTO;
    }

    public function getCalibration($storeId = null): ?int
    {
        return $this->snippet->normalizeCalibration(
            $this->scopeConfig->getValue(self::XML_CALIBRATION, ScopeInterface::SCOPE_STORE, $storeId)
        );
    }

    public function isInlineRulesEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_INLINE_RULES, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Page toggles as consumed by PageMatcher::evaluate().
     *
     * @return array{checkout: bool, order_success: bool, cart: bool, all_store: bool, custom: array<int, string>}
     */
    public function getPageSettings($storeId = null): array
    {
        return [
            'checkout' => $this->scopeConfig->isSetFlag(self::XML_PAGE_CHECKOUT, ScopeInterface::SCOPE_STORE, $storeId),
            'order_success' => $this->scopeConfig->isSetFlag(self::XML_PAGE_ORDER_SUCCESS, ScopeInterface::SCOPE_STORE, $storeId),
            'cart' => $this->scopeConfig->isSetFlag(self::XML_PAGE_CART, ScopeInterface::SCOPE_STORE, $storeId),
            'all_store' => $this->scopeConfig->isSetFlag(self::XML_PAGE_ALL_STORE, ScopeInterface::SCOPE_STORE, $storeId),
            'custom' => $this->getCustomPatterns($storeId),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getCustomPatterns($storeId = null): array
    {
        $raw = (string)$this->scopeConfig->getValue(self::XML_PAGE_CUSTOM, ScopeInterface::SCOPE_STORE, $storeId);
        $lines = preg_split('/[\r\n]+/', $raw) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * Re-check interval in hours (6, 12 or 24). Global scope.
     */
    public function getRevalidateInterval(): int
    {
        $value = (int)$this->scopeConfig->getValue(self::XML_INTERVAL);
        return isset(self::INTERVALS[$value]) ? $value : self::DEFAULT_INTERVAL;
    }

    public function getCronExpression(): string
    {
        return (string)($this->scopeConfig->getValue(self::XML_CRON_EXPR) ?: self::INTERVALS[self::DEFAULT_INTERVAL]);
    }

    /**
     * Store front URL sent as `site` to /api/sdk/status and used in the User-Agent.
     */
    public function getSiteUrl($storeId = null): string
    {
        try {
            return (string)$this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_WEB);
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function getUserAgent(string $siteUrl = ''): string
    {
        $ua = 'Proteside-Magento/' . self::MODULE_VERSION;
        return $siteUrl !== '' ? $ua . '; ' . $siteUrl : $ua;
    }
}
