<?php
/**
 * Server-side client for the Proteside SDK API:
 *   GET /api/sdk/status?key=&site=&platform=magento&version=   (entitlement, no-store)
 *   GET /api/sdk/config?key=                                   (rules, GTM containers, channel)
 *
 * Only ever called from cron, the admin "Check status" button, the config-save observer and the CLI.
 * Never from the storefront render path.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Proteside\Checkout\Model\Config;
use Proteside\Checkout\Model\Snippet;
use Psr\Log\LoggerInterface;

class Client
{
    public const API_BASE = Snippet::API_BASE;
    public const TIMEOUT_SECONDS = 5;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_GRACE = 'grace';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_CONNECTION_ERROR = 'connection_error';

    public const DEFINITIVE_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_GRACE,
        self::STATUS_SUSPENDED,
        self::STATUS_INACTIVE,
        self::STATUS_INVALID,
    ];

    private CurlFactory $curlFactory;
    private Json $json;
    private Config $config;
    private Snippet $snippet;
    private LoggerInterface $logger;

    public function __construct(
        CurlFactory $curlFactory,
        Json $json,
        Config $config,
        Snippet $snippet,
        LoggerInterface $logger
    ) {
        $this->curlFactory = $curlFactory;
        $this->json = $json;
        $this->config = $config;
        $this->snippet = $snippet;
        $this->logger = $logger;
    }

    /**
     * Normalised entitlement result.
     *
     * transient=true means 429 / 5xx / network error / timeout / unexpected body: keep the previous
     * decision. Only HTTP 200 and 401 are definitive.
     *
     * @return array{ok: bool, transient: bool, http: int, status: string, active: bool, plan: ?string,
     *               grace_until: ?string, cdn_base: ?string, domain_mismatch: bool, message: string}
     */
    public function status(string $key, string $siteUrl): array
    {
        $result = [
            'ok' => false,
            'transient' => true,
            'http' => 0,
            'status' => self::STATUS_CONNECTION_ERROR,
            'active' => false,
            'plan' => null,
            'grace_until' => null,
            'cdn_base' => null,
            'domain_mismatch' => false,
            'message' => '',
        ];

        $key = trim($key);
        if (!$this->snippet->isValidKey($key)) {
            $result['transient'] = false;
            $result['status'] = self::STATUS_INVALID;
            $result['message'] = 'The SDK key format is invalid.';
            return $result;
        }

        $query = [
            'key' => $key,
            'platform' => Config::PLATFORM,
            'version' => Config::MODULE_VERSION,
        ];
        if ($siteUrl !== '') {
            $query['site'] = $siteUrl;
        }

        $response = $this->request(self::API_BASE . '/status?' . http_build_query($query), $siteUrl);
        $result['http'] = $response['http'];
        if ($response['error'] !== '') {
            $result['message'] = $response['error'];
            return $result;
        }

        $body = $response['body'];
        if ($response['http'] === 200 && is_array($body)) {
            $status = isset($body['status']) && is_string($body['status']) ? $body['status'] : '';
            if (!in_array($status, self::DEFINITIVE_STATUSES, true)) {
                $status = !empty($body['active']) ? self::STATUS_ACTIVE : self::STATUS_INACTIVE;
            }
            $result['ok'] = true;
            $result['transient'] = false;
            $result['status'] = $status;
            $result['active'] = !empty($body['active']);
            $result['plan'] = isset($body['plan']) && is_string($body['plan']) ? $body['plan'] : null;
            $result['grace_until'] = isset($body['graceUntil']) && is_string($body['graceUntil']) ? $body['graceUntil'] : null;
            $result['domain_mismatch'] = !empty($body['domainMismatch']);
            if (isset($body['cdnBase']) && is_string($body['cdnBase']) && preg_match('#^https://[a-z0-9.\-]+(/[a-z0-9._\-/]*)?$#i', $body['cdnBase'])) {
                $result['cdn_base'] = rtrim($body['cdnBase'], '/');
            }
            return $result;
        }

        if ($response['http'] === 401) {
            $result['ok'] = true;
            $result['transient'] = false;
            $result['status'] = self::STATUS_INVALID;
            $result['active'] = false;
            $result['message'] = 'The SDK key is invalid or the store was not found.';
            return $result;
        }

        $result['message'] = sprintf('Proteside API returned HTTP %d; keeping the previous state.', $response['http']);
        return $result;
    }

    /**
     * Remote config. `rules` and `gtm` are already filtered (unknown types/targets dropped); `channel` is stable|latest|null.
     *
     * @return array{ok: bool, http: int, rules: array<int, array{type: string, target: string, value: string}>,
     *               gtm: array<int, string>, channel: ?string, entitled: ?bool, paused: ?bool, message: string}
     */
    public function config(string $key): array
    {
        $result = [
            'ok' => false,
            'http' => 0,
            'rules' => [],
            'gtm' => [],
            'channel' => null,
            'entitled' => null,
            'paused' => null,
            'message' => '',
        ];

        $key = trim($key);
        if (!$this->snippet->isValidKey($key)) {
            $result['message'] = 'The SDK key format is invalid.';
            return $result;
        }

        $response = $this->request(self::API_BASE . '/config?' . http_build_query(['key' => $key]), '');
        $result['http'] = $response['http'];
        if ($response['error'] !== '') {
            $result['message'] = $response['error'];
            return $result;
        }
        $body = $response['body'];
        if ($response['http'] !== 200 || !is_array($body)) {
            $result['message'] = sprintf('Proteside config endpoint returned HTTP %d.', $response['http']);
            return $result;
        }

        $result['ok'] = true;
        $result['rules'] = $this->snippet->filterRules(isset($body['rules']) && is_array($body['rules']) ? $body['rules'] : []);
        $result['gtm'] = $this->snippet->filterGtm(isset($body['gtmContainers']) && is_array($body['gtmContainers']) ? $body['gtmContainers'] : []);
        if (isset($body['channel']) && is_string($body['channel'])) {
            $result['channel'] = $this->snippet->normalizeChannel($body['channel']);
        }
        if (array_key_exists('entitled', $body)) {
            $result['entitled'] = (bool)$body['entitled'];
        }
        if (array_key_exists('paused', $body)) {
            $result['paused'] = (bool)$body['paused'];
        }
        return $result;
    }

    /**
     * @return array{http: int, body: mixed, error: string}
     */
    private function request(string $url, string $siteUrl): array
    {
        $out = ['http' => 0, 'body' => null, 'error' => ''];
        try {
            /** @var Curl $curl */
            $curl = $this->curlFactory->create();
            $curl->setTimeout(self::TIMEOUT_SECONDS);
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
            $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
            $curl->addHeader('Accept', 'application/json');
            $curl->addHeader('User-Agent', $this->config->getUserAgent($siteUrl));
            $curl->get($url);
            $out['http'] = (int)$curl->getStatus();
            $raw = (string)$curl->getBody();
            if ($raw !== '') {
                try {
                    $out['body'] = $this->json->unserialize($raw);
                } catch (\InvalidArgumentException $e) {
                    $out['body'] = null;
                }
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
            $this->logger->warning('Proteside: API request failed: ' . $out['error']);
        }
        return $out;
    }
}
