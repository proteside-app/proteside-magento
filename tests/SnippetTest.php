<?php
/**
 * Minimal, dependency-free test of the snippet composition rules (two tags, inline config, CSP hash) and of the
 * page matcher decision table.
 *
 * Run:  php tests/SnippetTest.php        (exit code 0 = all assertions passed)
 *
 * No PHPUnit, no Magento: only Model/Snippet.php and Model/PageMatcher.php (static evaluate()) are loaded.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

$root = dirname(__DIR__) . '/src/Proteside/Checkout';
require $root . '/Model/Snippet.php';
require $root . '/Model/PageMatcher.php';

use Proteside\Checkout\Model\PageMatcher;
use Proteside\Checkout\Model\Snippet;

$failures = 0;
$passed = 0;

function check(bool $condition, string $label): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  ok   $label\n";
    } else {
        $failures++;
        echo "  FAIL $label\n";
    }
}

$snippet = new Snippet();
$bootstrapperFile = $root . '/etc/proteside/bootstrapper.min.js';
$bootstrapper = (string)file_get_contents($bootstrapperFile);
$version = trim((string)file_get_contents($root . '/etc/proteside/VERSION'));
$key = 'pk_live_3f9c1a7b2e4d6f8a0c1e3b5d7f9a1c3e';

echo "Bundled bootstrapper\n";
check($bootstrapper !== '', 'bootstrapper.min.js is readable and not empty');
check(strpos($bootstrapper, Snippet::USE_STRICT) === 0, 'bootstrapper starts with "use strict";');
check(strpos($bootstrapper, '__PROTE_BOOT_SCRIPTS__') !== false, 'bootstrapper records window.__PROTE_BOOT_SCRIPTS__');
check(strpos($bootstrapper, '__PROTE_INLINE__') !== false, 'bootstrapper reads window.__PROTE_INLINE__');
check(preg_match('/^\d+\.\d+\.\d+$/', $version) === 1, "VERSION is semver ($version)");
check(version_compare($version, '1.1.2', '>='), 'bootstrapper >= 1.1.2 (inline config in the same tag is discounted by the SDK)');

echo "\nCSP hash\n";
check(
    $snippet->cspHash($snippet->bootstrapperScript($bootstrapper)) === 't2ypUgwBPt6N82k2gXq5D5ZHIdxOXxhj6ZISJatVbg8=',
    'sha256 of the emitted inline content without inline config equals the published hash'
);
$whitelist = (string)file_get_contents($root . '/etc/csp_whitelist.xml');
check(strpos($whitelist, 't2ypUgwBPt6N82k2gXq5D5ZHIdxOXxhj6ZISJatVbg8=') !== false, 'csp_whitelist.xml carries the same hash');
check(strpos($whitelist, 'https://cdn.proteside.com') !== false && strpos($whitelist, 'https://app.proteside.com') !== false, 'csp_whitelist.xml whitelists cdn (script-src) and app (connect-src)');

echo "\nKey validation (pk_live_/pk_test_ + 32 hex chars)\n";
check($snippet->isValidKey($key), 'pk_live_ + 32 hex is valid');
check($snippet->isValidKey('pk_test_' . str_repeat('a', 32)), 'pk_test_ + 32 hex is valid');
check(!$snippet->isValidKey('sk_live_' . str_repeat('a', 32)), 'sk_live_ is rejected');
check(!$snippet->isValidKey('pk_live_' . str_repeat('A', 32)), 'uppercase hex is rejected');
check(!$snippet->isValidKey('pk_live_' . str_repeat('a', 31)), '31 hex chars rejected');
check(!$snippet->isValidKey($key . "\n"), 'trailing newline rejected');
check(!$snippet->isValidKey(''), 'empty rejected');
check(!$snippet->isValidKey(null), 'null rejected');

echo "\nInline config composition\n";
check($snippet->inlineConfigScript([], []) === '', 'no rules + no gtm → no assignment');
check($snippet->bootstrapperScript($bootstrapper, [], []) === $bootstrapper, 'without inline config the bootstrapper is emitted unchanged');

$rules = [
    ['type' => 'block', 'target' => 'domain', 'value' => 'evil.example'],
    ['type' => 'allow', 'target' => 'src', 'value' => 'https://cdn.good.example/a.js'],
    ['type' => 'block', 'target' => 'hash', 'value' => 'abc123'],
    ['type' => 'monitor', 'target' => 'domain', 'value' => 'ignored.example'],   // invalid type
    ['type' => 'block', 'target' => 'path', 'value' => '/x'],                    // invalid target
    ['type' => 'block', 'target' => 'domain', 'value' => ''],                    // empty value
    ['type' => 'block', 'target' => 'domain', 'value' => 42],                    // non-string value
    'garbage',
];
$gtm = ['GTM-ABCD', "GTM-WXYZ\n", 'GTM-ABC', 'gtm-abcd', 'GTM-ABCDEFGHIJK', 'GTM-XYZ123', 'GTM-ABCD', 7];

$filteredRules = $snippet->filterRules($rules);
check(count($filteredRules) === 3, 'rules filtered to type∈{block,allow}, target∈{domain,src,hash}, non-empty string value (3 of 8)');
check(array_keys($filteredRules) === [0, 1, 2], 'filtered rules are a list (JSON array, not object)');
check($snippet->filterGtm($gtm) === ['GTM-ABCD', 'GTM-XYZ123'], 'gtm filtered to ^GTM-[A-Z0-9]{4,10}$, deduplicated');

$inline = $snippet->inlineConfigScript($rules, $gtm);
$expectedInline = 'window.__PROTE_INLINE__={"rules":[{"type":"block","target":"domain","value":"evil.example"},{"type":"allow","target":"src","value":"https://cdn.good.example/a.js"},{"type":"block","target":"hash","value":"abc123"}],"gtm":["GTM-ABCD","GTM-XYZ123"]};';
check($inline === $expectedInline, 'inline assignment is strict JSON, unescaped slashes, ends with ;');

$composed = $snippet->bootstrapperScript($bootstrapper, $rules, $gtm);
check(strpos($composed, '"use strict";window.__PROTE_INLINE__={') === 0, 'composed script starts with "use strict";window.__PROTE_INLINE__={');
check($composed === Snippet::USE_STRICT . $expectedInline . substr($bootstrapper, strlen(Snippet::USE_STRICT)), 'assignment sits right after "use strict"; and the rest of the bootstrapper is untouched');
check(substr_count($composed, '"use strict";') === substr_count($bootstrapper, '"use strict";'), 'the "use strict" directive is not duplicated');
check(strlen($composed) === strlen($bootstrapper) + strlen($expectedInline), 'nothing else was added or removed');

$composedOnlyGtm = $snippet->bootstrapperScript($bootstrapper, [], ['GTM-ABCD']);
check(strpos($composedOnlyGtm, '"use strict";window.__PROTE_INLINE__={"rules":[],"gtm":["GTM-ABCD"]};') === 0, 'gtm only → still emitted with empty rules array');

$composedOnlyInvalid = $snippet->bootstrapperScript($bootstrapper, [['type' => 'monitor', 'target' => 'domain', 'value' => 'x']], ['nope']);
check($composedOnlyInvalid === $bootstrapper, 'only invalid entries → no assignment at all');

$xss = $snippet->inlineConfigScript([['type' => 'block', 'target' => 'src', 'value' => '</script><script>alert(1)</script>']], []);
check(strpos($xss, '<') === false, '"<" is escaped (\\u003c) so a rule value can never close the inline tag');
check(strpos($xss, '\\u003c/script>') !== false, 'escaped as \\u003c');

$noStrict = 'var x=1;';
$fallback = $snippet->bootstrapperScript($noStrict, [['type' => 'block', 'target' => 'domain', 'value' => 'a.b']], []);
check($fallback === 'window.__PROTE_INLINE__={"rules":[{"type":"block","target":"domain","value":"a.b"}],"gtm":[]};var x=1;', 'bootstrapper without "use strict" → assignment is prepended');

echo "\nshield.js tag (async, only data-key/data-api/data-calibration, channel URL)\n";
check($snippet->shieldUrl('stable') === 'https://cdn.proteside.com/v1/shield.js', 'stable → /v1/shield.js');
check($snippet->shieldUrl('latest') === 'https://cdn.proteside.com/v1/latest/shield.js', 'latest → /v1/latest/shield.js');
check($snippet->shieldUrl('auto') === 'https://cdn.proteside.com/v1/shield.js', 'unknown channel → stable');
check($snippet->shieldUrl('stable', 'https://cdn.proteside.com/v1/') === 'https://cdn.proteside.com/v1/shield.js', 'cdnBase trailing slash tolerated');

echo "\ncdnBase allow-list (https Proteside domains only)\n";
check($snippet->isTrustedCdnBase('https://cdn.proteside.com/v1'), 'trusted: cdn.proteside.com over https');
check($snippet->isTrustedCdnBase('https://proteside.com/cdn'), 'trusted: apex proteside.com');
check(!$snippet->isTrustedCdnBase('http://cdn.proteside.com/v1'), 'untrusted: plain http');
check(!$snippet->isTrustedCdnBase('https://evil.example/v1'), 'untrusted: other domain');
check(!$snippet->isTrustedCdnBase('https://cdn.proteside.com.evil.example/v1'), 'untrusted: proteside.com as a prefix of another domain');
check(!$snippet->isTrustedCdnBase('https://evilproteside.com/v1'), 'untrusted: lookalike domain');
check(!$snippet->isTrustedCdnBase('https://user:pw@cdn.proteside.com/v1'), 'untrusted: credentials in URL');
check(!$snippet->isTrustedCdnBase('https://cdn.proteside.com/v1?x=1'), 'untrusted: query string');
check($snippet->shieldUrl('stable', 'https://evil.example/v1') === 'https://cdn.proteside.com/v1/shield.js', 'untrusted cdnBase falls back to the default CDN');

$attrs = $snippet->shieldAttributes($key, 'stable');
check(array_keys($attrs) === ['src', 'async', 'data-key', 'data-api'], 'shield attributes: src, async, data-key, data-api — nothing else by default');
check($attrs['data-api'] === 'https://app.proteside.com/api/sdk', 'data-api is always https://app.proteside.com/api/sdk');
check($attrs['data-key'] === $key, 'data-key is the SDK key');
check(!isset($attrs['data-safe-url']) && !isset($attrs['data-mode']) && !isset($attrs['data-channel']), 'no data-safe-url / data-mode / data-channel');

$withCal = $snippet->shieldAttributes($key, 'latest', 45);
check(($withCal['data-calibration'] ?? null) === '45', 'data-calibration emitted when 10–120');
check(!isset($snippet->shieldAttributes($key, 'stable', 5)['data-calibration']), 'calibration 5 → dropped (below 10)');
check(!isset($snippet->shieldAttributes($key, 'stable', 121)['data-calibration']), 'calibration 121 → dropped (above 120)');
check(!isset($snippet->shieldAttributes($key, 'stable', '')['data-calibration']), 'calibration "" → dropped');
check(($snippet->shieldAttributes($key, 'stable', '30')['data-calibration'] ?? null) === '30', 'calibration "30" (string) → 30');

echo "\nFull markup (inline bootstrapper first, then async shield.js)\n";
$markup = $snippet->markup($snippet->bootstrapperScript($bootstrapper), $key, 'stable', null);
$inlinePos = strpos($markup, '<script data-cfasync="false"');
$shieldPos = strpos($markup, '<script src="https://cdn.proteside.com/v1/shield.js"');
check(strpos($markup, Snippet::MARKER_START) !== false && strpos($markup, Snippet::MARKER_END) !== false, 'wrapped in <!-- Proteside Start --> / <!-- Proteside End -->');
check($inlinePos !== false && $shieldPos !== false && $inlinePos < $shieldPos, 'inline bootstrapper tag comes before the shield.js tag');
check(substr_count($markup, '<script') === 2, 'exactly two <script> tags');
preg_match('/<script data-cfasync="false"[^>]*>/', $markup, $m);
$inlineOpenTag = $m[0] ?? '';
check(strpos($inlineOpenTag, ' src=') === false, 'inline tag has no src');
check(preg_match('/\s(async|defer|type|src)(=|\s|>)/i', $inlineOpenTag) === 0, 'inline tag has no async/defer/type/src attribute (synchronous, inline)');
foreach (['data-cfasync="false"', 'data-no-optimize="1"', 'data-nowprocket=""', 'data-pagespeed-no-defer=""'] as $attr) {
    check(strpos($inlineOpenTag, $attr) !== false, "inline tag carries anti-optimiser attribute $attr");
}
check(strpos($markup, 'async="true"') !== false, 'shield.js is async');
check(strpos($markup, $inlineOpenTag . $bootstrapper . '</script>') !== false, 'inline tag content is the bootstrapper, byte for byte');

$markupLatest = $snippet->markup($snippet->bootstrapperScript($bootstrapper, $rules, $gtm), $key, 'latest', 60);
check(strpos($markupLatest, 'https://cdn.proteside.com/v1/latest/shield.js') !== false, 'latest channel URL in markup');
check(strpos($markupLatest, 'data-calibration="60"') !== false, 'calibration attribute in markup');
check(strpos($markupLatest, '"use strict";window.__PROTE_INLINE__={') !== false, 'inline config inside the SAME inline tag');
check(substr_count($markupLatest, '<script') === 2, 'inline config never adds a third <script> tag');

// Custom renderer hook (used with Magento's SecureHtmlRenderer): called twice, order and arguments preserved.
$calls = [];
$custom = $snippet->markup('JS', $key, 'stable', null, null, static function (string $tag, array $a, string $c) use (&$calls): string {
    $calls[] = [$tag, $a, $c];
    return "<$tag>" . $c . "</$tag>";
});
check(count($calls) === 2 && $calls[0][0] === 'script' && $calls[1][0] === 'script', 'renderer callback invoked for both tags');
check($calls[0][2] === 'JS' && $calls[1][2] === '', 'first call receives the inline JS, second the (empty) shield content');
check($calls[1][1]['src'] === 'https://cdn.proteside.com/v1/shield.js', 'second call receives the shield attributes');

echo "\nAttribute escaping\n";
$tag = $snippet->renderTag('script', ['data-key' => 'a"b<c>', 'bad name!' => 'x'], '');
check(strpos($tag, 'data-key="a&quot;b&lt;c&gt;"') !== false, 'attribute values are HTML-escaped');
check(strpos($tag, 'badname="x"') !== false, 'attribute names are sanitised');

echo "\nPage matcher\n";
$defaults = ['checkout' => true, 'order_success' => true, 'cart' => false, 'all_store' => false, 'custom' => []];
check(PageMatcher::evaluate('checkout_index_index', '/checkout/', $defaults), 'checkout_index_index → inject (default)');
check(PageMatcher::evaluate('checkout_onepage_success', '/checkout/onepage/success/', $defaults), 'checkout_onepage_success → inject (default)');
check(PageMatcher::evaluate('multishipping_checkout_billing', '/multishipping/checkout/billing/', $defaults), 'multishipping_checkout_* → inject (default)');
check(PageMatcher::evaluate('multishipping_checkout_success', '/multishipping/checkout/success/', $defaults), 'multishipping_checkout_success → inject (default)');
check(!PageMatcher::evaluate('checkout_cart_index', '/checkout/cart/', $defaults), 'cart → not injected by default');
check(!PageMatcher::evaluate('cms_index_index', '/', $defaults), 'home → not injected by default');
check(!PageMatcher::evaluate('catalog_product_view', '/foo.html', $defaults), 'product → not injected by default');
check(PageMatcher::evaluate('checkout_cart_index', '/checkout/cart/', array_merge($defaults, ['cart' => true])), 'cart enabled → inject');
check(PageMatcher::evaluate('cms_index_index', '/', array_merge($defaults, ['all_store' => true])), 'entire store → inject everywhere');
check(!PageMatcher::evaluate('checkout_index_index', '/checkout/', array_merge($defaults, ['checkout' => false])), 'checkout disabled → not injected');
check(!PageMatcher::evaluate('multishipping_checkout_success', '/x', array_merge($defaults, ['order_success' => false])), 'multishipping success follows order_success, not checkout');
check(PageMatcher::evaluate('paypal_express_review', '/paypal/express/review/', array_merge($defaults, ['custom' => ['/paypal/*']])), 'custom path glob /paypal/* matches');
check(PageMatcher::evaluate('paypal_express_review', '/paypal/express/review/', array_merge($defaults, ['custom' => ['/paypal/express/review']])), 'custom exact path matches (trailing slash ignored)');
check(PageMatcher::evaluate('paypal_express_review', '/paypal/express/review/', array_merge($defaults, ['custom' => ['paypal_express_*']])), 'custom action-name glob matches');
check(PageMatcher::evaluate('braintree_paypal_review', '/braintree/paypal/review', array_merge($defaults, ['custom' => ['braintree_paypal_review']])), 'custom exact action name matches');
check(!PageMatcher::evaluate('cms_page_view', '/about-us', array_merge($defaults, ['custom' => ['/paypal/*', '', '  ']])), 'non-matching / blank patterns ignored');

echo "\n" . ($failures === 0 ? "ALL PASSED" : "FAILED") . " — $passed passed, $failures failed\n";
exit($failures === 0 ? 0 : 1);
