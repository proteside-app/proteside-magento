# VERIFICATION — Proteside_Checkout (Magento 2 / Adobe Commerce)

## 1.0.2 — bootstrapper 1.1.3 (2026-10-16)

Bundled bootstrapper replaced with `proteside-sdk` 1.1.3 (`dist/bootstrapper.min.js`, 4390 bytes, `sha256-t2ypUgwBPt6N82k2gXq5D5ZHIdxOXxhj6ZISJatVbg8=`;
the `navigator.clipboard.writeText` patch now hands each entry a `replace` callback for the Pix restore in `block`
mode). Files replaced: `src/Proteside/Checkout/etc/proteside/` (`bootstrapper.min.js`, `bootstrapper.src.index.ts`, `VERSION`). `etc/csp_whitelist.xml` carries the new hash (id `proteside-bootstrapper-1-1-3`). `Snippet::KEY_PATTERN` and `GTM_PATTERN` gained the `D` modifier (regression assertions in `tests/SnippetTest.php`). The module uses Magento's `Curl` client (no direct `curl_close()`).

Re-run on macOS, PHP 8.5.10 CLI / Node v20.20.2:

```
$ for f in $(find . -name '*.php' -o -name '*.phtml'); do php -l "$f"; done   → no syntax errors
$ php tests/SnippetTest.php     → ALL PASSED — 90 passed, 0 failed
$ (every src/**/*.xml through xml.dom.minidom) → well-formed
$ node -e "JSON.parse(…composer.json…)" && node --check etc/proteside/bootstrapper.min.js → ok
```

Not redone in this pass: the real Magento 2.4.9 install below, and the Marketplace package in `marketplace/` (regenerated separately for 1.0.2).

The sections below are the original record; hashes and versions in them (bootstrapper 1.1.2,
`HWXxhSKTNGLCXBBWdCXnTklExUDKbAB+ZxAleXl2XWA=`) describe that earlier build.

## 1.0.1 — real install on Magento 2.4.9 (2026-10-16)

Environment: Magento Open Source 2.4.9 cloned from GitHub (`magento/magento2` tag 2.4.9) + Composer,
PHP 8.5.10 CLI with the built-in server, MariaDB 13.0 (accepted through a test-only edit of the
supported-version list in `app/etc/di.xml`), search modules disabled (no OpenSearch), Luma theme,
built-in full-page cache. The module was installed with Composer from a path repository, exactly as
packaged.

Passed:
- `composer require proteside/module-checkout:1.0.1`, `module:enable`, `setup:upgrade`,
  `setup:di:compile`, `setup:static-content:deploy`, `deploy:mode:set production` (the first attempt
  ran out of the CLI's 128 MB `memory_limit` inside Magento core; with more memory it passes).
- Storefront: the inline bootstrapper (with `window.__PROTE_INLINE__`) and the async shield.js are the
  first content of `<head>` on the checkout, on the order success page and, with "Entire store", on
  the home page, including the second request served by the full-page cache. Default targeting leaves
  the home page and the cart untouched.
- shield.js 1.1.3 in the browser: `Proteside.getStatus()` → `initialized: true`,
  `installationOrderValid: true`, `rulesApplied: true`.
- Guest order with Flat Rate + Check / Money order placed (order #000000001); checkout not affected.
- CSP: on the checkout (restrict mode) the enforced `connect-src` includes `https://cdn.proteside.com`
  and `https://app.proteside.com` after the 1.0.1 whitelist change.
- Real API with a syntactically valid fake key: `proteside:status --refresh` and the admin "Check
  status" button → HTTP 401 → "Invalid key", protection off. Saving the section re-validates.
- An API `cdnBase` outside proteside.com (`https://evil.example/v1`) is not stored; shield.js stays on
  `https://cdn.proteside.com/v1/shield.js`.
- Admin: Stores > Configuration > Security > Proteside renders all groups and the status panel.
- Magento coding standard (`--error-severity=10 --ignore-annotations`): 0 errors, 321 warnings.
  PHPCompatibility 7.4–8.5: clean. `tests/SnippetTest.php`: 89 passed.

Not tested: Varnish (not installed here), PHP versions other than 8.5, Magento 2.4.4–2.4.8, Adobe
Commerce (EE/B2B), multi-address checkout, CSP with Magento_Csp disabled.

---

## 1.0.0 (2026-10-10)

Written against `proteside-sdk` 1.1.2 (bundled bootstrapper 1.1.2).

**No Magento instance exists in this environment. The module was never installed, compiled
(`setup:di:compile`) or exercised against a storefront.** Everything listed under "Verified" is static
verification plus a framework-free unit test; everything under "Not tested" needs a real 2.4.x box.

## 1. What was implemented

```
plugin-magento/
  README.md · README.pt-BR.md · README.es.md · CHANGELOG.md · LICENSE (MIT) · VERIFICATION.md
  tests/SnippetTest.php                         plain PHP, no PHPUnit — 80 assertions
  src/Proteside/Checkout/                       module root (composer "proteside/module-checkout" 1.0.0)
    registration.php · composer.json
    etc/module.xml · di.xml · config.xml · acl.xml · csp_whitelist.xml · crontab.xml
    etc/frontend/events.xml                     controller_action_predispatch + controller_front_send_response_before
    etc/adminhtml/system.xml · events.xml · routes.xml
    etc/proteside/bootstrapper.min.js · bootstrapper.src.index.ts · bootstrapper.src.match.ts · VERSION (1.1.2) · SOURCE.md
    Model/Config.php                            typed scope-config access, constants (version, URLs, intervals)
    Model/Snippet.php                           PURE composition (no Magento deps): key regex, rule/GTM filters, inline config,
                                                bootstrapperScript(), shield attrs, anti-optimiser attrs, raw tag renderer, markup(), cspHash()
    Model/Bootstrapper.php                      reads etc/proteside/* via Module Dir Reader + Filesystem\Driver\File, memoised
    Model/PageMatcher.php                       static evaluate(actionName, path, settings) + Magento matches(request)
    Model/Entitlement.php                       FlagManager-persisted state per key; decide() = decision table (48 h tolerance)
    Model/Api/Client.php                        Magento\Framework\HTTP\Client\Curl, 5 s timeout, UA "Proteside-Magento/1.0.0; <site>",
                                                GET /api/sdk/status and /api/sdk/config, normalised results (200/401 definitive, rest transient)
    Model/Revalidator.php                       collectTargets() over all store views, revalidateKey(), revalidateAll(), FPC flush on output change
    Model/Injector.php                          prepare(request) [memoised decision + SecureHtmlRenderer tags] and inject(request, response)
    Model/Cache/PageCacheFlusher.php            cleanType('full_page') + dispatch adminhtml_cache_refresh_type (Varnish purge path)
    Model/StatusPresenter.php                   labels/notices/severity shared by block, controller, observer, CLI
    Model/Config/Backend/SdkKey.php             validates ^pk_(live|test)_[0-9a-f]{32}$ on save
    Model/Config/Backend/Interval.php           writes crontab/default/jobs/proteside_checkout_revalidate/schedule/cron_expr (6/12/24 h)
    Model/Config/Source/Channel.php · Interval.php
    Observer/PrepareSnippet.php                 predispatch: registers inline hash with Magento_Csp before the header is built
    Observer/InjectSnippet.php                  injects after <head …>; never throws
    Observer/FlushPageCache.php                 admin_system_config_changed_section_proteside: revalidateAll + flush + admin messages
    Cron/Revalidate.php
    Console/Command/StatusCommand.php           bin/magento proteside:status [--refresh] [--json]
    Controller/Adminhtml/Status/Check.php       POST proteside/status/check (ACL Proteside_Checkout::config, form key)
    Block/Adminhtml/System/Config/Status.php + view/adminhtml/templates/system/config/status.phtml
    view/adminhtml/web/js/status-check.js       data-mage-init handler for the "Check status" button (no inline JS)
    i18n/en_US.csv · pt_BR.csv · es_ES.csv      74 strings each, %1 placeholders
```

Rules the module follows:

- The inline synchronous bootstrapper is the first script content of `<head>`, followed by
  `shield.js` (`async`, only `data-key`, `data-api`, optional `data-calibration`). No badge, no
  `data-safe-url`. The bootstrapper ships inside the module and is never downloaded at runtime.
- Inline config (`window.__PROTE_INLINE__={…};`) is inserted after the leading `"use strict";` in the
  same tag, JSON-escaped (`<` → `<`), filtered to known rule types/targets and well-formed GTM IDs;
  empty lists emit nothing, so the body equals the shipped file and its CSP hash is
  `sha256-HWXxhSKTNGLCXBBWdCXnTklExUDKbAB+ZxAleXl2XWA=`.
- Entitlement: only HTTP 200 and 401 change the decision; 429/5xx/timeouts keep the previous state
  with a 48-hour tolerance after the last success; never-validated keys never inject.
- Nothing on the render path calls the Proteside API; the injector reads cached state only.

### Design decisions worth knowing

1. **CSP via `SecureHtmlRenderer`, not `InlineUtilInterface` directly.** In Magento DI a
   constructor parameter with a `= null` default is *not* auto-instantiated, so an optional
   cross-module dependency on `Magento\Csp\Api\InlineUtilInterface` would need the ObjectManager or a
   Proxy that still fails at `setup:di:compile` if Magento_Csp is removed.
   `Magento\Framework\View\Helper\SecureHtmlRenderer` (in `magento/framework`, always present) has the
   same `renderTag($tag, $attrs, $content, $textContent)` signature, and Magento_Csp's di.xml registers
   `Magento\Csp\Helper\InlineUtil` as one of its processors — so the sha256 of the inline content and
   the `src` host are whitelisted when Csp is enabled, and plain tags are emitted when it is disabled.
   No ObjectManager, no class_exists. `etc/csp_whitelist.xml` adds the static host entries and the
   static hash of the bootstrapper without inline config (belt and braces for FPC hits, see §4).
2. **Two observers.** Magento_Csp also renders its header in `controller_front_send_response_before`
   and, because modules load alphabetically (`Magento_Csp` < `Proteside_Checkout`), its observer runs
   *before* ours. Registering the hash during `controller_action_predispatch` (`PrepareSnippet`)
   solves the ordering for every non-cached page; `InjectSnippet` reuses the memoised result.
3. **Injection after the FPC on purpose:** the built-in FPC stores the page during `renderResult`,
   before `controller_front_send_response_before`, so the snippet is never cached and always reflects
   the current state. Varnish caches the sent response, hence the explicit flush on config save and on
   state change.
4. **Layout XML (`head.additional` / `default.xml`) is not used:** anything rendered there comes after
   `requirejs` and the theme's head scripts and would always trigger `INSTALLATION_ORDER_WARNING`.
   Documented in `etc/frontend/events.xml` and README ("Installation order"). No fallback block was
   added (it would need safeguards against double injection and has no advantage over the observer).
5. **Bootstrapper emitted byte for byte (trailing newline kept).** The published hash
   `sha256-HWXxhSKTNGLCXBBWdCXnTklExUDKbAB+ZxAleXl2XWA=` is the hash of the file *as shipped*
   (verified below), so the static whitelist entry matches exactly what is emitted without inline config.
6. **Entitlement per SDK key** (flag code `proteside_checkout_entitlement_<md5(key)>`), shared by all
   store views using the same key; cron/admin/CLI iterate every store view and check each distinct key.
   The 48 h tolerance applies only when the *latest* check was a connection error; a missed cron (no
   check at all) keeps the last decision and the admin shows a "more than 48 h" warning instead of
   silently failing closed on stores with broken cron.
7. **Interval default 6 h**; 12/24 h selectable.
8. **License MIT**: the short MIT text can be reproduced exactly; MIT, OSL-3.0 and AFL-3.0 are all
   accepted by the Adobe Commerce Marketplace.
9. **Duplicate-safety:** if the response already contains `__PROTE_BOOT_SCRIPTS__` (snippet pasted in
   the theme) nothing is injected.

## 2. Verified (commands and output)

Environment: macOS, PHP 8.5.10 CLI (syntax check only; code uses PHP 7.4-compatible syntax — typed
properties and `?T`, no `match`/enums/union types/`str_*` 8.0 helpers, confirmed by grep), Python 3,
Node 20. All commands run from the package root.

```
$ for f in $(find . -name '*.php' -o -name '*.phtml' | sort); do php -l "$f"; done
No syntax errors detected in src/Proteside/Checkout/Block/Adminhtml/System/Config/Status.php
No syntax errors detected in src/Proteside/Checkout/Console/Command/StatusCommand.php
No syntax errors detected in src/Proteside/Checkout/Controller/Adminhtml/Status/Check.php
No syntax errors detected in src/Proteside/Checkout/Cron/Revalidate.php
No syntax errors detected in src/Proteside/Checkout/Model/Api/Client.php
No syntax errors detected in src/Proteside/Checkout/Model/Bootstrapper.php
No syntax errors detected in src/Proteside/Checkout/Model/Cache/PageCacheFlusher.php
No syntax errors detected in src/Proteside/Checkout/Model/Config.php
No syntax errors detected in src/Proteside/Checkout/Model/Config/Backend/Interval.php
No syntax errors detected in src/Proteside/Checkout/Model/Config/Backend/SdkKey.php
No syntax errors detected in src/Proteside/Checkout/Model/Config/Source/Channel.php
No syntax errors detected in src/Proteside/Checkout/Model/Config/Source/Interval.php
No syntax errors detected in src/Proteside/Checkout/Model/Entitlement.php
No syntax errors detected in src/Proteside/Checkout/Model/Injector.php
No syntax errors detected in src/Proteside/Checkout/Model/PageMatcher.php
No syntax errors detected in src/Proteside/Checkout/Model/Revalidator.php
No syntax errors detected in src/Proteside/Checkout/Model/Snippet.php
No syntax errors detected in src/Proteside/Checkout/Model/StatusPresenter.php
No syntax errors detected in src/Proteside/Checkout/Observer/FlushPageCache.php
No syntax errors detected in src/Proteside/Checkout/Observer/InjectSnippet.php
No syntax errors detected in src/Proteside/Checkout/Observer/PrepareSnippet.php
No syntax errors detected in src/Proteside/Checkout/registration.php
No syntax errors detected in src/Proteside/Checkout/view/adminhtml/templates/system/config/status.phtml
No syntax errors detected in tests/SnippetTest.php
php files OK: 24

$ grep -rnE "\bmatch\s*\(|\benum\s+[A-Z]|readonly |str_starts_with|str_ends_with|str_contains|\?->|: (int|string|bool|array)\|" src tests
(none)

$ php tests/SnippetTest.php
  …
  ok   non-matching / blank patterns ignored
ALL PASSED — 80 passed, 0 failed      (exit 0)

$ for f in $(find src -name '*.xml' | sort); do python3 -c "import sys,xml.dom.minidom; xml.dom.minidom.parse(sys.argv[1]); print('well-formed', sys.argv[1])" "$f"; done
well-formed src/Proteside/Checkout/etc/acl.xml
well-formed src/Proteside/Checkout/etc/adminhtml/events.xml
well-formed src/Proteside/Checkout/etc/adminhtml/routes.xml
well-formed src/Proteside/Checkout/etc/adminhtml/system.xml
well-formed src/Proteside/Checkout/etc/config.xml
well-formed src/Proteside/Checkout/etc/crontab.xml
well-formed src/Proteside/Checkout/etc/csp_whitelist.xml
well-formed src/Proteside/Checkout/etc/di.xml
well-formed src/Proteside/Checkout/etc/frontend/events.xml
well-formed src/Proteside/Checkout/etc/module.xml

$ python3 csv parse (2 columns, no empty cells)
OK src/Proteside/Checkout/i18n/en_US.csv 74 rows
OK src/Proteside/Checkout/i18n/es_ES.csv 74 rows
OK src/Proteside/Checkout/i18n/pt_BR.csv 74 rows

$ node -e "JSON.parse(require('fs').readFileSync('src/Proteside/Checkout/composer.json','utf8'))"
valid composer.json
$ node --check src/Proteside/Checkout/view/adminhtml/web/js/status-check.js     → ok
$ node --check src/Proteside/Checkout/etc/proteside/bootstrapper.min.js          → ok

$ cat src/Proteside/Checkout/etc/proteside/VERSION
1.1.2
$ openssl dgst -sha256 -binary src/Proteside/Checkout/etc/proteside/bootstrapper.min.js | base64
HWXxhSKTNGLCXBBWdCXnTklExUDKbAB+ZxAleXl2XWA=
```

`tests/SnippetTest.php` covers: the emitted inline content starts with `"use strict";` and, with rules,
with `"use strict";window.__PROTE_INLINE__={`; filtering of rules/GTM, empty lists → no assignment,
strict JSON, `<` → `<`, same tag, no duplication of the directive; two tags in order, inline tag
without src/async/defer/type, anti-optimiser attributes, shield.js async with only
`data-key`/`data-api`/`data-calibration`, `data-api` constant; key regex, stable/latest URLs; CSP hash
equals the published hash and `csp_whitelist.xml`; page decision table incl. custom patterns; and the
renderer-callback hook used with `SecureHtmlRenderer`.

## 3. Not tested / needs a real Magento 2.4.x

- `bin/magento module:enable Proteside_Checkout && setup:upgrade && setup:di:compile` — class/DI
  resolution, XSD validation of the XML files against Magento's schemas (only well-formedness was
  checked), generation of `CurlFactory`/Proxies, `setup:static-content:deploy` of the admin JS.
- Admin: section rendering under the "Security" tab (tab is re-declared locally in case
  `Magento_Security` is disabled), the status row, the "Check status" button (AJAX POST + form key),
  scope switching (website/store), the key validator message, cron expression rewrite on interval change.
- Storefront: observer firing order with Magento_Csp on 2.4.7 restrict mode
  (`csp/mode/storefront_checkout_index_index/report_only=0`), actual `<head …>` regex on Luma/Blank and
  third-party themes, `Proteside.getStatus().installationOrderValid === true`, multishipping and
  success pages, custom patterns, built-in FPC hit/miss behaviour, Varnish purge via
  `adminhtml_cache_refresh_type`.
- Network: real `GET /api/sdk/status` and `/api/sdk/config` responses (only the documented shapes were
  implemented), 401/429/5xx handling, timeout behaviour of `Magento\Framework\HTTP\Client\Curl`.
- Cron job registration and execution (`cron_schedule` rows for `proteside_checkout_revalidate`).
- CLI command output in a real shop; `--json`.
- Translations loading (`i18n/*.csv` picked up by `setup:static-content:deploy`/admin locale).
- PHP 7.4 runtime (only syntax-level compatibility was checked; typed properties are 7.4+).

## 4. Open issues / risks

1. **Observer order on FPC hits for cacheable pages.** On a built-in FPC *hit* the action is not
   dispatched, so the inline hash is only added in `controller_front_send_response_before`, after
   Magento_Csp has rendered the header. Checkout/cart/success are non-cacheable (unaffected). For
   "Entire store"/custom cacheable pages the static hash in `csp_whitelist.xml` covers the no-inline-
   config case; with inline rules embedded on such pages under *restrict* mode the inline script would
   be blocked. Documented in README ("Content-Security-Policy"). Mitigation if needed: a plugin on
   `Magento\Csp\Model\CspRenderer::render` (before) that calls `Injector::prepare()` — not added to
   avoid a hard dependency on Magento_Csp.
2. **`SecureHtmlRenderer` + Csp processor behaviour** is based on Magento 2.4 architecture knowledge,
   not on an executed test. If the Csp processor is not registered on the renderer in some version,
   the static whitelist still covers the default case and the hash would otherwise need `InlineUtil`.
3. **Varnish purge path** relies on `Magento_CacheInvalidate` listening to `adminhtml_cache_refresh_type`
   in its global `events.xml` (as "Refresh Full Page Cache" does). Verify the purge really reaches
   Varnish from cron/admin save; fallback is a manual refresh in Cache Management.
4. **`Magento\Framework\HTTP\Client\CurlFactory`**: a concrete class in the framework; if a given
   version lacks it Magento auto-generates the factory at compile time.
5. **Multiple keys per site**: `site` reported to the status endpoint is the base URL of the first
   store view using each key.
6. `Magento_Multishipping` is optional; the `multishipping_checkout_*` action names are simply never
   seen if it is disabled.
7. Hyvä / PWA Studio / headless storefronts are out of scope (no Magento HTML response pipeline).

## 5. Next steps for maintainers

1. Install on a Magento 2.4.7 sandbox (Luma, Csp restrict mode on checkout, built-in FPC, then Varnish)
   and run the "Not tested" list; capture `Proteside.getStatus()` output.
2. Run Magento's static tests (`dev/tests/static` — PHPCS Magento2 standard, XSD validation) and fix
   whatever the compiled DI reports.
3. Publish the package (Packagist / Marketplace) and switch the README install section from path
   repository to the public package.
4. When a new bootstrapper is released, replace `etc/proteside/*`, update the hash in
   `etc/csp_whitelist.xml`, `etc/proteside/SOURCE.md` and README/CHANGELOG, bump `Config::MODULE_VERSION`
   and `composer.json`, and re-run `php tests/SnippetTest.php`.
