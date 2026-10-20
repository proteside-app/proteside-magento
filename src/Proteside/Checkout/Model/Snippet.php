<?php
/**
 * Pure composition of the Proteside installation snippet.
 *
 * This class has NO Magento dependencies on purpose: tests/SnippetTest.php loads it with plain PHP.
 * Everything that touches Magento (reading the bundled file, CSP rendering, response injection) lives
 * in Proteside\Checkout\Model\Bootstrapper and Proteside\Checkout\Model\Injector.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Model;

class Snippet
{
    /** First statement of bootstrapper.min.js; the inline config goes right after it. */
    public const USE_STRICT = '"use strict";';

    /** `data-api` is always this value (the SDK's fixed API base). */
    public const API_BASE = 'https://app.proteside.com/api/sdk';

    /** CDN base; the status endpoint may return another `cdnBase`, which takes precedence. */
    public const CDN_BASE = 'https://cdn.proteside.com/v1';

    public const CHANNEL_STABLE = 'stable';
    public const CHANNEL_LATEST = 'latest';

    public const KEY_PATTERN = '/^pk_(live|test)_[0-9a-f]{32}$/D';
    public const GTM_PATTERN = '/^GTM-[A-Z0-9]{4,10}$/D';

    public const RULE_TYPES = ['block', 'allow'];
    public const RULE_TARGETS = ['domain', 'src', 'hash'];

    public const CALIBRATION_MIN = 10;
    public const CALIBRATION_MAX = 120;

    public const MARKER_START = '<!-- Proteside Start -->';
    public const MARKER_END = '<!-- Proteside End -->';

    /**
     * Whether a string is a well-formed public SDK key.
     */
    public function isValidKey(?string $key): bool
    {
        return is_string($key) && preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * Keep only rules the bootstrapper understands: type ∈ {block, allow}, target ∈ {domain, src, hash},
     * non-empty string value. Output is a list (sequential keys) so json_encode emits a JSON array.
     *
     * @param array<int, mixed> $rules
     * @return array<int, array{type: string, target: string, value: string}>
     */
    public function filterRules(array $rules): array
    {
        $out = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $type = $rule['type'] ?? null;
            $target = $rule['target'] ?? null;
            $value = $rule['value'] ?? null;
            if (!is_string($type) || !in_array($type, self::RULE_TYPES, true)) {
                continue;
            }
            if (!is_string($target) || !in_array($target, self::RULE_TARGETS, true)) {
                continue;
            }
            if (!is_string($value) || $value === '') {
                continue;
            }
            $out[] = ['type' => $type, 'target' => $target, 'value' => $value];
        }
        return $out;
    }

    /**
     * Keep only well-formed GTM container IDs (^GTM-[A-Z0-9]{4,10}$), deduplicated, as a list.
     *
     * @param array<int, mixed> $gtm
     * @return array<int, string>
     */
    public function filterGtm(array $gtm): array
    {
        $out = [];
        foreach ($gtm as $id) {
            if (is_string($id) && preg_match(self::GTM_PATTERN, $id) === 1 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * `window.__PROTE_INLINE__={"rules":[…],"gtm":[…]};` or '' when both lists are empty after filtering.
     * Mirrors inlineConfigScript() of the dashboard: strict JSON, `<` escaped as <.
     *
     * @param array<int, mixed> $rules
     * @param array<int, mixed> $gtm
     */
    public function inlineConfigScript(array $rules, array $gtm): string
    {
        $rules = $this->filterRules($rules);
        $gtm = $this->filterGtm($gtm);
        if ($rules === [] && $gtm === []) {
            return '';
        }
        $json = json_encode(
            ['rules' => $rules, 'gtm' => $gtm],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (!is_string($json)) {
            return '';
        }
        $json = str_replace('<', '\\u003c', $json);
        return 'window.__PROTE_INLINE__=' . $json . ';';
    }

    /**
     * Content of the inline <script>: the bootstrapper, with the optional inline config inserted right
     * after the leading `"use strict";` directive (same <script>, never a separate tag).
     *
     * @param string            $bootstrapper Raw content of bootstrapper.min.js
     * @param array<int, mixed> $rules
     * @param array<int, mixed> $gtm
     */
    public function bootstrapperScript(string $bootstrapper, array $rules = [], array $gtm = []): string
    {
        $inline = $this->inlineConfigScript($rules, $gtm);
        if ($inline === '') {
            return $bootstrapper;
        }
        if (strpos($bootstrapper, self::USE_STRICT) === 0) {
            return self::USE_STRICT . $inline . substr($bootstrapper, strlen(self::USE_STRICT));
        }
        return $inline . $bootstrapper;
    }

    /**
     * Normalise a channel value: anything other than "latest" is "stable".
     */
    public function normalizeChannel(?string $channel): string
    {
        return $channel === self::CHANNEL_LATEST ? self::CHANNEL_LATEST : self::CHANNEL_STABLE;
    }

    /**
     * URL of shield.js for the given channel: <cdn>/shield.js or <cdn>/latest/shield.js.
     */
    public function shieldUrl(string $channel, ?string $cdnBase = null): string
    {
        $base = rtrim($cdnBase !== null && $this->isTrustedCdnBase($cdnBase) ? $cdnBase : self::CDN_BASE, '/');
        return $this->normalizeChannel($channel) === self::CHANNEL_LATEST
            ? $base . '/latest/shield.js'
            : $base . '/shield.js';
    }

    /**
     * Whether a CDN base reported by the API may serve shield.js: https only, on proteside.com or one
     * of its subdomains, no credentials, query or fragment.
     */
    public function isTrustedCdnBase(string $url): bool
    {
        return (bool)preg_match('#^https://([a-z0-9-]+\.)*proteside\.com(/[A-Za-z0-9._~/-]*)?$#i', $url);
    }

    /**
     * Calibration sanitiser: null unless an integer within 10–120 seconds.
     *
     * @param mixed $value
     */
    public function normalizeCalibration($value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $int = (int)$value;
        if ($int < self::CALIBRATION_MIN || $int > self::CALIBRATION_MAX) {
            return null;
        }
        return $int;
    }

    /**
     * Attributes of the shield.js tag, in output order. Only data-key, data-api and (optionally)
     * data-calibration are allowed on this tag. No data-safe-url, ever.
     *
     * @return array<string, string>
     */
    public function shieldAttributes(string $key, string $channel, $calibration = null, ?string $cdnBase = null): array
    {
        $attrs = [
            'src' => $this->shieldUrl($channel, $cdnBase),
            'async' => 'true',
            'data-key' => $key,
            'data-api' => self::API_BASE,
        ];
        $cal = $this->normalizeCalibration($calibration);
        if ($cal !== null) {
            $attrs['data-calibration'] = (string)$cal;
        }
        return $attrs;
    }

    /**
     * Anti-optimiser attributes for the inline tag so cache/optimisation layers
     * (Cloudflare Rocket Loader, WP Rocket-like tools, PageSpeed) do not move or defer it.
     *
     * @return array<string, string>
     */
    public function inlineAttributes(): array
    {
        return [
            'data-cfasync' => 'false',
            'data-no-optimize' => '1',
            'data-nowprocket' => '',
            'data-pagespeed-no-defer' => '',
        ];
    }

    /**
     * Raw tag builder (fallback when no CSP-aware renderer is available, and used by the test).
     * Attribute values are escaped for an HTML attribute context; `$content` is emitted verbatim
     * (JavaScript), so callers must only pass trusted content (the bundled bootstrapper + JSON).
     *
     * @param array<string, string> $attributes
     */
    public function renderTag(string $tag, array $attributes, string $content = ''): string
    {
        $parts = [];
        foreach ($attributes as $name => $value) {
            $name = preg_replace('/[^a-zA-Z0-9\-_:]/', '', (string)$name);
            if ($name === '') {
                continue;
            }
            $parts[] = $name . '="' . htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
        }
        $open = '<' . $tag . ($parts ? ' ' . implode(' ', $parts) : '') . '>';
        return $open . $content . '</' . $tag . '>';
    }

    /**
     * Full markup: marker, inline bootstrapper tag, async shield tag, marker — in this exact order.
     *
     * @param string        $bootstrapperJs Output of bootstrapperScript()
     * @param callable|null $renderer       fn(string $tag, array $attrs, string $content): string — defaults to renderTag()
     */
    public function markup(
        string $bootstrapperJs,
        string $key,
        string $channel,
        $calibration = null,
        ?string $cdnBase = null,
        ?callable $renderer = null
    ): string {
        $render = $renderer ?: [$this, 'renderTag'];
        $inlineTag = $render('script', $this->inlineAttributes(), $bootstrapperJs);
        $shieldTag = $render('script', $this->shieldAttributes($key, $channel, $calibration, $cdnBase), '');
        return "\n" . self::MARKER_START . "\n" . $inlineTag . "\n" . $shieldTag . "\n" . self::MARKER_END . "\n";
    }

    /**
     * Base64 sha256 of the inline content, as used by CSP (`'sha256-…'`).
     */
    public function cspHash(string $inlineContent): string
    {
        return base64_encode(hash('sha256', $inlineContent, true));
    }
}
