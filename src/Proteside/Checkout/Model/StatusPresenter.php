<?php
/**
 * Turns an entitlement state array into translated labels and notices. Shared by the admin status
 * block, the admin controller, the config-save observer and the CLI so wording is identical.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Model;

use Magento\Framework\Phrase;
use Proteside\Checkout\Model\Api\Client;

class StatusPresenter
{
    public const STALE_SECONDS = Entitlement::TOLERANCE_SECONDS;

    /**
     * @param array<string, mixed> $state
     */
    public function statusLabel(array $state): Phrase
    {
        switch ((string)($state['status'] ?? Entitlement::STATUS_UNKNOWN)) {
            case Client::STATUS_ACTIVE:
                return __('Active');
            case Client::STATUS_GRACE:
                return __('Grace period');
            case Client::STATUS_SUSPENDED:
                return __('Suspended');
            case Client::STATUS_INACTIVE:
                return __('Inactive');
            case Client::STATUS_INVALID:
                return __('Invalid key');
            case Client::STATUS_CONNECTION_ERROR:
                return __('Connection error');
            default:
                return __('Not verified yet');
        }
    }

    /**
     * Admin grid severity class for the pill.
     *
     * @param array<string, mixed> $state
     */
    public function severityClass(array $state): string
    {
        $decision = Entitlement::decide($state);
        $status = (string)($state['status'] ?? Entitlement::STATUS_UNKNOWN);
        if ($status === Client::STATUS_ACTIVE) {
            return 'grid-severity-notice';
        }
        if ($status === Client::STATUS_GRACE || ($status === Client::STATUS_CONNECTION_ERROR && $decision)) {
            return 'grid-severity-minor';
        }
        if ($status === Entitlement::STATUS_UNKNOWN) {
            return 'grid-severity-minor';
        }
        return 'grid-severity-critical';
    }

    /**
     * @param array<string, mixed> $state
     */
    public function decisionLabel(array $state): Phrase
    {
        return Entitlement::decide($state)
            ? __('active — the protection loads on the selected pages.')
            : __('inactive — the protection does NOT load.');
    }

    /**
     * Notices to show the merchant: list of ['type' => success|warning|error|notice, 'message' => Phrase].
     *
     * @param array<string, mixed> $state
     * @return array<int, array{type: string, message: Phrase}>
     */
    public function notices(array $state, ?int $now = null): array
    {
        $now = $now ?? time();
        $out = [];
        $status = (string)($state['status'] ?? Entitlement::STATUS_UNKNOWN);

        switch ($status) {
            case Client::STATUS_GRACE:
                $out[] = ['type' => 'warning', 'message' => __('Your Proteside subscription is in its grace period. Update your billing to keep the protection active.')];
                break;
            case Client::STATUS_INVALID:
                $out[] = ['type' => 'error', 'message' => __('Your Proteside SDK key is invalid, so the protection is not being loaded.')];
                break;
            case Client::STATUS_SUSPENDED:
            case Client::STATUS_INACTIVE:
                $out[] = ['type' => 'error', 'message' => __('Your Proteside subscription is not active, so the protection is not being loaded. Check your billing in the dashboard.')];
                break;
            case Client::STATUS_CONNECTION_ERROR:
                $detail = (string)($state['message'] ?? '');
                if ($detail === '') {
                    $detail = (int)($state['http_code'] ?? 0) > 0 ? 'HTTP ' . (int)$state['http_code'] : __('network error')->render();
                }
                $out[] = ['type' => 'warning', 'message' => __('Proteside could not be reached (%1). The last known status is kept for up to 48 hours.', $detail)];
                break;
            case Entitlement::STATUS_UNKNOWN:
                $out[] = ['type' => 'notice', 'message' => __('The SDK key has not been verified yet. Save the configuration or click "Check status".')];
                break;
            default:
                break;
        }

        if (!empty($state['domain_mismatch'])) {
            $out[] = ['type' => 'notice', 'message' => __('This key belongs to a store registered under a different domain. Protection still loads, but check the store in your dashboard.')];
        }

        $checkedAt = (int)($state['checked_at'] ?? 0);
        if ($checkedAt > 0 && ($now - $checkedAt) > self::STALE_SECONDS && $status !== Entitlement::STATUS_UNKNOWN) {
            $out[] = ['type' => 'warning', 'message' => __('The last status check ran more than 48 hours ago. Make sure Magento cron is running (bin/magento cron:run).')];
        }

        return $out;
    }

    public function checkedAgo(int $timestamp, ?int $now = null): Phrase
    {
        if ($timestamp <= 0) {
            return __('never');
        }
        return __('Checked %1 ago', $this->humanDuration(($now ?? time()) - $timestamp));
    }

    public function humanDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        if ($seconds < 3600) {
            return (int)floor($seconds / 60) . ' min';
        }
        if ($seconds < 86400) {
            return (int)floor($seconds / 3600) . ' h';
        }
        return (int)floor($seconds / 86400) . ' d';
    }
}
