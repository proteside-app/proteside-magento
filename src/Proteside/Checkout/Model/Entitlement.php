<?php
/**
 * Cached entitlement state per SDK key, persisted in the `flag` table through FlagManager.
 *
 * Decision table (only HTTP 200 and 401 change the decision; 429/5xx/timeouts keep the previous state, with a 48-hour tolerance after the last success):
 *
 *  | Last definitive answer    | API now      | Inject?                              |
 *  | active / grace            | OK           | yes                                  |
 *  | suspended/inactive/invalid| OK           | no                                   |
 *  | never validated           | unavailable  | no (fail-closed)                     |
 *  | active/grace, ≤ 48 h ago  | unavailable  | yes (tolerance)                      |
 *  | active/grace, > 48 h ago  | unavailable  | no                                   |
 *
 * Only HTTP 200 and 401 change the decision; 429/5xx/network errors keep it and mark connection_error.
 * This class never performs network requests.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Model;

use Magento\Framework\FlagManager;
use Proteside\Checkout\Model\Api\Client;

class Entitlement
{
    public const FLAG_PREFIX = 'proteside_checkout_entitlement_';
    public const TOLERANCE_SECONDS = 48 * 3600;
    public const STATUS_UNKNOWN = 'unknown';

    private FlagManager $flagManager;
    private Snippet $snippet;

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(FlagManager $flagManager, Snippet $snippet)
    {
        $this->flagManager = $flagManager;
        $this->snippet = $snippet;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'key' => '',
            // Latest result: unknown | active | grace | suspended | inactive | invalid | connection_error
            'status' => self::STATUS_UNKNOWN,
            // Last DEFINITIVE status (200/401) — what the decision is based on
            'last_status' => self::STATUS_UNKNOWN,
            'active' => false,
            'plan' => null,
            'grace_until' => null,
            'cdn_base' => null,
            'domain_mismatch' => false,
            'checked_at' => 0,
            'last_success_at' => 0,
            'http_code' => 0,
            'message' => '',
            // From /api/sdk/config
            'channel' => null,
            'inline_rules' => [],
            'inline_gtm' => [],
            'config_checked_at' => 0,
            'config_http_code' => 0,
            'config_message' => '',
        ];
    }

    public function flagCode(string $key): string
    {
        return self::FLAG_PREFIX . hash('sha256', trim($key));
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $key): array
    {
        $key = trim($key);
        if (!isset($this->cache[$key])) {
            $stored = $this->flagManager->getFlagData($this->flagCode($key));
            $state = is_array($stored) ? $stored : [];
            $state = array_merge($this->defaults(), $state);
            $state['key'] = $key;
            $state['inline_rules'] = is_array($state['inline_rules']) ? $state['inline_rules'] : [];
            $state['inline_gtm'] = is_array($state['inline_gtm']) ? $state['inline_gtm'] : [];
            $this->cache[$key] = $state;
        }
        return $this->cache[$key];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function save(string $key, array $state): void
    {
        $key = trim($key);
        $state['key'] = $key;
        $this->flagManager->saveFlag($this->flagCode($key), $state);
        $this->cache[$key] = $state;
    }

    public function reset(string $key): void
    {
        $key = trim($key);
        $this->flagManager->deleteFlag($this->flagCode($key));
        unset($this->cache[$key]);
    }

    /**
     * Apply a normalised result of Client::status().
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed> new state
     */
    public function applyStatus(string $key, array $result, ?int $now = null): array
    {
        $now = $now ?? time();
        $state = $this->load($key);
        $state['checked_at'] = $now;
        $state['http_code'] = (int)($result['http'] ?? 0);
        $state['message'] = (string)($result['message'] ?? '');

        if (!empty($result['transient'])) {
            $state['status'] = Client::STATUS_CONNECTION_ERROR;
            $this->save($key, $state);
            return $state;
        }

        $status = (string)($result['status'] ?? Client::STATUS_INVALID);
        if (!in_array($status, Client::DEFINITIVE_STATUSES, true)) {
            $status = Client::STATUS_INVALID;
        }
        $state['status'] = $status;
        $state['last_status'] = $status;
        $state['last_success_at'] = $now;
        $state['active'] = !empty($result['active']) && in_array($status, [Client::STATUS_ACTIVE, Client::STATUS_GRACE], true);
        $state['plan'] = $result['plan'] ?? null;
        $state['grace_until'] = $result['grace_until'] ?? null;
        $state['domain_mismatch'] = !empty($result['domain_mismatch']);
        // Only https Proteside domains may serve shield.js (see Snippet::shieldUrl()).
        if (!empty($result['cdn_base']) && $this->snippet->isTrustedCdnBase((string)$result['cdn_base'])) {
            $state['cdn_base'] = (string)$result['cdn_base'];
        }

        $this->save($key, $state);
        return $state;
    }

    /**
     * Apply a normalised result of Client::config(). A failed fetch keeps the previous rules/channel.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed> new state
     */
    public function applyConfig(string $key, array $result, ?int $now = null): array
    {
        $now = $now ?? time();
        $state = $this->load($key);
        $state['config_checked_at'] = $now;
        $state['config_http_code'] = (int)($result['http'] ?? 0);
        $state['config_message'] = (string)($result['message'] ?? '');
        if (!empty($result['ok'])) {
            $state['inline_rules'] = $this->snippet->filterRules(is_array($result['rules'] ?? null) ? $result['rules'] : []);
            $state['inline_gtm'] = $this->snippet->filterGtm(is_array($result['gtm'] ?? null) ? $result['gtm'] : []);
            $state['channel'] = isset($result['channel']) ? $this->snippet->normalizeChannel((string)$result['channel']) : null;
        }
        $this->save($key, $state);
        return $state;
    }

    /**
     * The single source of truth for the storefront. Reads cached state only.
     *
     * @param array<string, mixed> $state
     */
    public static function decide(array $state, ?int $now = null): bool
    {
        $now = $now ?? time();
        if (empty($state['active'])) {
            return false;
        }
        $last = (string)($state['last_status'] ?? self::STATUS_UNKNOWN);
        if (!in_array($last, [Client::STATUS_ACTIVE, Client::STATUS_GRACE], true)) {
            return false;
        }
        if (($state['status'] ?? '') === Client::STATUS_CONNECTION_ERROR) {
            $lastSuccess = (int)($state['last_success_at'] ?? 0);
            return $lastSuccess > 0 && ($now - $lastSuccess) <= self::TOLERANCE_SECONDS;
        }
        return true;
    }

    public function isEntitled(string $key, ?int $now = null): bool
    {
        if (!$this->snippet->isValidKey($key)) {
            return false;
        }
        return self::decide($this->load($key), $now);
    }

    /**
     * Effective channel for a store: manual override (stable/latest) wins; "auto" follows the
     * dashboard value cached from /api/sdk/config, defaulting to stable.
     *
     * @param array<string, mixed> $state
     */
    public function effectiveChannel(array $state, string $configured): string
    {
        if ($configured === Snippet::CHANNEL_STABLE || $configured === Snippet::CHANNEL_LATEST) {
            return $configured;
        }
        return $this->snippet->normalizeChannel(isset($state['channel']) ? (string)$state['channel'] : null);
    }
}
