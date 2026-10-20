<?php
/**
 * Read-only "Connection status" row in system configuration (frontend_model of proteside/status/info).
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use Proteside\Checkout\Model\Bootstrapper;
use Proteside\Checkout\Model\Config;
use Proteside\Checkout\Model\Entitlement;
use Proteside\Checkout\Model\Snippet;
use Proteside\Checkout\Model\StatusPresenter;

class Status extends Field
{
    /** @var string */
    protected $_template = 'Proteside_Checkout::system/config/status.phtml';

    private Config $config;
    private Entitlement $entitlement;
    private Bootstrapper $bootstrapper;
    private StatusPresenter $presenter;
    private Snippet $snippet;
    private StoreManagerInterface $storeManager;

    /** @var array<string, mixed>|null */
    private ?array $state = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Config $config,
        Entitlement $entitlement,
        Bootstrapper $bootstrapper,
        StatusPresenter $presenter,
        Snippet $snippet,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->entitlement = $entitlement;
        $this->bootstrapper = $bootstrapper;
        $this->presenter = $presenter;
        $this->snippet = $snippet;
        $this->storeManager = $storeManager;
    }

    /**
     * Render full width, without scope checkboxes.
     */
    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    /**
     * Store view whose configuration is being edited (null = default scope).
     */
    public function getScopeStoreId(): ?int
    {
        $request = $this->getRequest();
        $store = $request->getParam('store');
        if ($store !== null && $store !== '') {
            try {
                return (int)$this->storeManager->getStore($store)->getId();
            } catch (\Throwable $e) {
                return null;
            }
        }
        $website = $request->getParam('website');
        if ($website !== null && $website !== '') {
            try {
                $default = $this->storeManager->getWebsite($website)->getDefaultStore();
                return $default ? (int)$default->getId() : null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    public function getSdkKey(): string
    {
        return $this->config->getSdkKey($this->getScopeStoreId());
    }

    public function hasValidKey(): bool
    {
        return $this->snippet->isValidKey($this->getSdkKey());
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled($this->getScopeStoreId());
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        if ($this->state === null) {
            $this->state = $this->entitlement->load($this->getSdkKey());
        }
        return $this->state;
    }

    public function getStatusLabel(): Phrase
    {
        return $this->presenter->statusLabel($this->getState());
    }

    public function getSeverityClass(): string
    {
        return $this->presenter->severityClass($this->getState());
    }

    public function getDecisionLabel(): Phrase
    {
        return $this->presenter->decisionLabel($this->getState());
    }

    public function isEntitled(): bool
    {
        return Entitlement::decide($this->getState());
    }

    /**
     * @return array<int, array{type: string, message: Phrase}>
     */
    public function getNotices(): array
    {
        return $this->presenter->notices($this->getState());
    }

    public function getCheckedAgo(): Phrase
    {
        return $this->presenter->checkedAgo((int)($this->getState()['checked_at'] ?? 0));
    }

    public function getLastSuccessAgo(): Phrase
    {
        return $this->presenter->checkedAgo((int)($this->getState()['last_success_at'] ?? 0));
    }

    public function getPlan(): string
    {
        $plan = $this->getState()['plan'] ?? null;
        return is_string($plan) && $plan !== '' ? $plan : '-';
    }

    public function getEffectiveChannel(): string
    {
        return $this->entitlement->effectiveChannel($this->getState(), $this->config->getChannel($this->getScopeStoreId()));
    }

    public function getConfiguredChannel(): string
    {
        return $this->config->getChannel($this->getScopeStoreId());
    }

    public function getBootstrapperVersion(): string
    {
        return $this->bootstrapper->getVersion();
    }

    public function getBootstrapperHash(): string
    {
        return $this->bootstrapper->getHash();
    }

    public function getInlineRulesCount(): int
    {
        return count($this->getState()['inline_rules'] ?? []);
    }

    public function getInlineGtmCount(): int
    {
        return count($this->getState()['inline_gtm'] ?? []);
    }

    public function isInlineRulesEnabled(): bool
    {
        return $this->config->isInlineRulesEnabled($this->getScopeStoreId());
    }

    public function getCheckUrl(): string
    {
        return $this->getUrl('proteside/status/check');
    }

    public function getDashboardUrl(): string
    {
        return Config::DASHBOARD_URL;
    }

    public function getPlansUrl(): string
    {
        return Config::PLANS_URL;
    }

    public function getDocsUrl(): string
    {
        return Config::DOCS_URL;
    }

    public function getModuleVersion(): string
    {
        return Config::MODULE_VERSION;
    }
}
