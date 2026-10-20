<?php
/**
 * Decides whether a storefront request is one of the pages that must receive the snippet
 * Detection is by Magento full action name, never by URL — except for the optional
 * custom patterns, which the merchant writes against the path (or an action name).
 *
 * evaluate() is static and framework-free so it can be unit-tested with plain PHP.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Model;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;

class PageMatcher
{
    /** One-page checkout (all steps are hash routes of this action) and its failure page. */
    public const CHECKOUT_ACTIONS = ['checkout_index_index', 'checkout_onepage_failure'];

    /** Multi-address checkout: every multishipping_checkout_* action except the success page. */
    public const CHECKOUT_PREFIXES = ['multishipping_checkout_'];

    public const SUCCESS_ACTIONS = ['checkout_onepage_success', 'multishipping_checkout_success'];

    public const CART_ACTIONS = ['checkout_cart_index'];

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Magento entry point.
     */
    public function matches(RequestInterface $request, $storeId = null): bool
    {
        if (!$request instanceof HttpRequest) {
            return false;
        }
        $action = (string)$request->getFullActionName();
        if ($action === '') {
            return false;
        }
        $path = (string)parse_url((string)$request->getRequestUri(), PHP_URL_PATH);
        $pathInfo = (string)$request->getPathInfo();

        $settings = $this->config->getPageSettings($storeId);

        return self::evaluate($action, $path, $settings) || ($pathInfo !== '' && $pathInfo !== $path && self::evaluate($action, $pathInfo, $settings));
    }

    /**
     * Pure decision.
     *
     * @param string $fullActionName e.g. checkout_index_index
     * @param string $path           request path, e.g. /checkout/
     * @param array{checkout?: bool, order_success?: bool, cart?: bool, all_store?: bool, custom?: array<int, string>} $settings
     */
    public static function evaluate(string $fullActionName, string $path, array $settings): bool
    {
        $action = strtolower($fullActionName);

        if (!empty($settings['all_store'])) {
            return true;
        }
        if (!empty($settings['order_success']) && in_array($action, self::SUCCESS_ACTIONS, true)) {
            return true;
        }
        if (!empty($settings['checkout']) && self::isCheckoutAction($action)) {
            return true;
        }
        if (!empty($settings['cart']) && in_array($action, self::CART_ACTIONS, true)) {
            return true;
        }
        if (!empty($settings['custom']) && is_array($settings['custom'])) {
            return self::matchesCustom($action, $path, $settings['custom']);
        }
        return false;
    }

    public static function isCheckoutAction(string $action): bool
    {
        if (in_array($action, self::CHECKOUT_ACTIONS, true)) {
            return true;
        }
        if (in_array($action, self::SUCCESS_ACTIONS, true)) {
            return false;
        }
        foreach (self::CHECKOUT_PREFIXES as $prefix) {
            if (strpos($action, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Custom patterns, one per entry:
     *  - containing "/"  → matched against the request path; "*" is a wildcard; a pattern without "*"
     *                      must equal the path (trailing slash ignored);
     *  - without "/"     → matched against the full action name ("*" allowed), e.g. paypal_express_*.
     *
     * @param array<int, string> $patterns
     */
    public static function matchesCustom(string $action, string $path, array $patterns): bool
    {
        $path = '/' . trim(strtolower($path), '/');
        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim((string)$pattern));
            if ($pattern === '') {
                continue;
            }
            if (strpos($pattern, '/') === false) {
                if (self::globMatch($pattern, $action)) {
                    return true;
                }
                continue;
            }
            $normalized = '/' . trim($pattern, '/');
            if (strpos($normalized, '*') === false) {
                if ($normalized === $path) {
                    return true;
                }
                continue;
            }
            if (self::globMatch($normalized, $path) || self::globMatch($normalized, rtrim($path, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    private static function globMatch(string $pattern, string $subject): bool
    {
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
        return preg_match($regex, $subject) === 1;
    }
}
