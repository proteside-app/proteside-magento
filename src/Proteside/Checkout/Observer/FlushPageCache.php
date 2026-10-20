<?php
/**
 * admin_system_config_changed_section_proteside: immediate status + config check for every
 * configured key, then flush the full-page cache so the storefront reflects the saved settings.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Proteside\Checkout\Model\Cache\PageCacheFlusher;
use Proteside\Checkout\Model\Revalidator;
use Proteside\Checkout\Model\StatusPresenter;
use Psr\Log\LoggerInterface;

class FlushPageCache implements ObserverInterface
{
    private Revalidator $revalidator;
    private PageCacheFlusher $pageCacheFlusher;
    private StatusPresenter $presenter;
    private MessageManager $messageManager;
    private LoggerInterface $logger;

    public function __construct(
        Revalidator $revalidator,
        PageCacheFlusher $pageCacheFlusher,
        StatusPresenter $presenter,
        MessageManager $messageManager,
        LoggerInterface $logger
    ) {
        $this->revalidator = $revalidator;
        $this->pageCacheFlusher = $pageCacheFlusher;
        $this->presenter = $presenter;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        try {
            $states = $this->revalidator->revalidateAll();
            foreach ($states as $state) {
                foreach ($this->presenter->notices($state) as $notice) {
                    $this->addMessage($notice['type'], (string)$notice['message']);
                }
            }
            if ($states !== []) {
                $this->messageManager->addSuccessMessage((string)__('Settings saved and status refreshed.'));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Proteside: status refresh after config save failed: ' . $e->getMessage());
            $this->messageManager->addWarningMessage((string)__('Proteside status check failed: %1', $e->getMessage()));
        }

        // The key, pages or channel may have changed: cached pages (Varnish) must be rebuilt.
        $this->pageCacheFlusher->flush();
    }

    private function addMessage(string $type, string $message): void
    {
        switch ($type) {
            case 'error':
                $this->messageManager->addErrorMessage($message);
                break;
            case 'warning':
                $this->messageManager->addWarningMessage($message);
                break;
            case 'success':
                $this->messageManager->addSuccessMessage($message);
                break;
            default:
                $this->messageManager->addNoticeMessage($message);
        }
    }
}
