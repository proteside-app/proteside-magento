<?php
/**
 * Request-scoped coordination of the storefront injection.
 *
 *  prepare()  — decides once per request (enabled + valid key + page match + entitled) and renders
 *               the two tags through SecureHtmlRenderer. Called from controller_action_predispatch so
 *               that Magento_Csp's dynamic collector already holds the inline hash when it renders
 *               the CSP header; called again (memoised) from the response observer.
 *  inject()   — places the markup right after the opening <head …> tag of the HTML response.
 *
 * Nothing here calls the Proteside API: the entitlement decision reads cached state.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Model;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Store\Model\StoreManagerInterface;
use Laminas\Http\Header\HeaderInterface;
use Psr\Log\LoggerInterface;

class Injector
{
    /** Present in every page that already carries the bootstrapper (pasted snippet, other integration). */
    public const ALREADY_INSTALLED_MARKER = '__PROTE_BOOT_SCRIPTS__';

    private Config $config;
    private PageMatcher $pageMatcher;
    private Entitlement $entitlement;
    private Bootstrapper $bootstrapper;
    private Snippet $snippet;
    private SecureHtmlRenderer $secureRenderer;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;

    /** @var array<string, string> memo: request signature => markup ('' = do not inject) */
    private array $memo = [];

    public function __construct(
        Config $config,
        PageMatcher $pageMatcher,
        Entitlement $entitlement,
        Bootstrapper $bootstrapper,
        Snippet $snippet,
        SecureHtmlRenderer $secureRenderer,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->pageMatcher = $pageMatcher;
        $this->entitlement = $entitlement;
        $this->bootstrapper = $bootstrapper;
        $this->snippet = $snippet;
        $this->secureRenderer = $secureRenderer;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * Markup to inject for this request, or '' when nothing must be injected. Memoised per request.
     */
    public function prepare(HttpRequest $request): string
    {
        $signature = (string)$request->getFullActionName() . '|' . (string)$request->getRequestUri();
        if (isset($this->memo[$signature])) {
            return $this->memo[$signature];
        }
        $markup = '';
        try {
            $markup = $this->build($request);
        } catch (\Throwable $e) {
            $this->logger->error('Proteside: snippet preparation failed: ' . $e->getMessage());
        }
        $this->memo[$signature] = $markup;
        return $markup;
    }

    /**
     * Inject the prepared markup into an HTML response. Returns true when the body was changed.
     */
    public function inject(HttpRequest $request, HttpResponse $response): bool
    {
        $markup = $this->prepare($request);
        if ($markup === '') {
            return false;
        }

        $code = (int)$response->getHttpResponseCode();
        if ($response->isRedirect() || ($code >= 300 && $code < 400)) {
            return false;
        }

        $contentType = $response->getHeader('Content-Type');
        if ($contentType instanceof HeaderInterface && stripos($contentType->getFieldValue(), 'text/html') === false) {
            return false;
        }

        $body = $response->getContent();
        if (!is_string($body) || $body === '') {
            return false;
        }
        if (strpos($body, self::ALREADY_INSTALLED_MARKER) !== false) {
            // The page already carries a Proteside bootstrapper (e.g. pasted in the theme). Never load twice.
            return false;
        }

        $count = 0;
        $new = preg_replace_callback(
            '/<head\b[^>]*>/i',
            static function (array $match) use ($markup): string {
                return $match[0] . $markup;
            },
            $body,
            1,
            $count
        );
        if ($count !== 1 || !is_string($new)) {
            return false;
        }

        $response->setContent($new);
        $headers = $response->getHeaders();
        if ($headers->has('Content-Length')) {
            $length = $headers->get('Content-Length');
            if ($length instanceof HeaderInterface) {
                $headers->removeHeader($length);
            }
        }
        return true;
    }

    private function build(HttpRequest $request): string
    {
        $storeId = (int)$this->storeManager->getStore()->getId();

        if (!$this->config->isEnabled($storeId)) {
            return '';
        }
        $key = $this->config->getSdkKey($storeId);
        if (!$this->snippet->isValidKey($key)) {
            return '';
        }
        if (!$this->pageMatcher->matches($request, $storeId)) {
            return '';
        }
        if (!$this->entitlement->isEntitled($key)) {
            return '';
        }
        $bootstrapperJs = $this->bootstrapper->getJs();
        if ($bootstrapperJs === '') {
            return '';
        }

        $state = $this->entitlement->load($key);
        $rules = [];
        $gtm = [];
        if ($this->config->isInlineRulesEnabled($storeId)) {
            $rules = $state['inline_rules'];
            $gtm = $state['inline_gtm'];
        }
        $inlineJs = $this->snippet->bootstrapperScript($bootstrapperJs, $rules, $gtm);
        $channel = $this->entitlement->effectiveChannel($state, $this->config->getChannel($storeId));
        $cdnBase = isset($state['cdn_base']) && is_string($state['cdn_base']) && $state['cdn_base'] !== '' ? $state['cdn_base'] : null;

        $renderer = function (string $tag, array $attributes, string $content): string {
            try {
                // Magento_Csp (when enabled) hooks into this renderer and whitelists the sha256 of the
                // inline content and the host of the src attribute.
                return $this->secureRenderer->renderTag($tag, $attributes, $content, false);
            } catch (\Throwable $e) {
                $this->logger->warning('Proteside: SecureHtmlRenderer failed, using raw tag: ' . $e->getMessage());
                return $this->snippet->renderTag($tag, $attributes, $content);
            }
        };

        return $this->snippet->markup(
            $inlineJs,
            $key,
            $channel,
            $this->config->getCalibration($storeId),
            $cdnBase,
            $renderer
        );
    }
}
