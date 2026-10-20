<?php
/**
 * POST proteside/status/check — the "Check status" button in Stores > Configuration > Security > Proteside.
 * Requires the Proteside_Checkout::config ACL resource and a valid admin form key.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Controller\Adminhtml\Status;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Proteside\Checkout\Model\Entitlement;
use Proteside\Checkout\Model\Revalidator;
use Proteside\Checkout\Model\StatusPresenter;
use Psr\Log\LoggerInterface;

class Check extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Proteside_Checkout::config';

    private JsonFactory $jsonFactory;
    private Revalidator $revalidator;
    private StatusPresenter $presenter;
    private LoggerInterface $logger;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        Revalidator $revalidator,
        StatusPresenter $presenter,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->revalidator = $revalidator;
        $this->presenter = $presenter;
        $this->logger = $logger;
    }

    public function execute(): JsonResult
    {
        $result = $this->jsonFactory->create();
        try {
            $states = $this->revalidator->revalidateAll();
            if ($states === []) {
                $this->messageManager->addNoticeMessage((string)__('No SDK key is configured yet.'));
            }
            $summary = [];
            foreach ($states as $key => $state) {
                foreach ($this->presenter->notices($state) as $notice) {
                    $this->addMessage($notice['type'], (string)$notice['message']);
                }
                if (Entitlement::decide($state)) {
                    $this->messageManager->addSuccessMessage((string)__('Proteside status: %1. Plan: %2.', $this->presenter->statusLabel($state), $state['plan'] ?? '-'));
                }
                $summary[] = [
                    'key' => $key,
                    'status' => $state['status'],
                    'decision' => Entitlement::decide($state),
                    'plan' => $state['plan'],
                    'checked_at' => (int)$state['checked_at'],
                ];
            }
            return $result->setData(['ok' => true, 'keys' => $summary]);
        } catch (\Throwable $e) {
            $this->logger->error('Proteside: admin status check failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage((string)__('Proteside status check failed: %1', $e->getMessage()));
            return $result->setHttpResponseCode(500)->setData(['ok' => false, 'error' => $e->getMessage()]);
        }
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
