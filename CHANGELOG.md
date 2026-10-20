# Changelog

All notable changes to `proteside/module-checkout` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org/).

## [1.0.2] — 2026-10-16

### Changed
- Bundled bootstrapper updated to **1.1.3** (from `proteside-sdk` 1.1.3). Its `navigator.clipboard.writeText` patch now
  hands each clipboard entry a `replace` callback, so the SDK in `block` mode can put the legitimate Pix code back
  *before* the write instead of rewriting the clipboard afterwards.
- CSP hash of the bootstrapper without inline config is now `sha256-t2ypUgwBPt6N82k2gXq5D5ZHIdxOXxhj6ZISJatVbg8=`; a policy that pins the old hash must be updated.
- `etc/csp_whitelist.xml`: hash entry renamed to `proteside-bootstrapper-1-1-3` with the new value.

### Fixed
- SDK key and GTM container validation use the `D` regex modifier, so a value with a trailing newline is rejected.

## [1.0.1] — 2026-10-16

Adobe Commerce Marketplace submission fixes.

### Changed
- `composer.json` PHP constraint now also accepts `~8.4.0` and `~8.5.0`, matching Magento 2.4.8
  (`~8.2 || ~8.3 || ~8.4`) and 2.4.9 (`~8.3 || ~8.4 || ~8.5`). The Marketplace forbids restricting
  the PHP versions declared by the supported Magento versions.
- Wording: "Helps you meet PCI DSS 4.0 requirements 6.4.3 and 11.6.1" (composer description, admin
  section comment, pt_BR / es_ES / en_US translations, READMEs) instead of implying compliance.
- Requirements in the READMEs: Magento 2.4.4 – 2.4.9, PHP 7.4 – 8.5 as supported by each Magento version.

### Security
- `cdnBase` from `GET /api/sdk/status` is only accepted on `https://` proteside.com domains (no
  credentials, query or fragment); anything else falls back to `https://cdn.proteside.com/v1`
  (`Snippet::isTrustedCdnBase()`, checked when the status is stored and when the URL is built).

### Fixed
- Admin status panel: the "Check status", "Open Proteside dashboard" and "Documentation" buttons
  overlapped when they wrapped to a second line; they now wrap with spacing.
- `etc/csp_whitelist.xml`: `connect-src` also allows `https://cdn.proteside.com`. shield.js hashes
  the page's external scripts (its own included) with a CORS fetch; on the checkout, which Magento
  2.4.7+ serves in CSP restrict mode, that request was blocked and logged as a CSP violation.
- `Entitlement::flagCode()` uses `hash('sha256', …)` instead of `md5()`, the only severity-10 error of
  the Magento coding standard (blocks Marketplace technical review). Cached entitlement stored under
  the old flag code is not read anymore; it is rebuilt on the next status check (save, cron or
  "Check status").

## [1.0.0] — 2026-10-10

First release, written against `proteside-sdk` 1.1.2 (bundled bootstrapper 1.1.2).

### Added
- Storefront injection of the canonical two-tag snippet (inline synchronous bootstrapper + async
  `shield.js`) as the first content of `<head>` on checkout (`checkout_index_index`,
  `multishipping_checkout_*`), order confirmation (`checkout_onepage_success`,
  `multishipping_checkout_success`) and, optionally, cart, entire store and custom URL/action patterns.
- Observer on `controller_front_send_response_before` (runs after the built-in full-page cache, so
  cached pages always get the current snippet) plus a pre-dispatch observer that registers the inline
  hash with Magento_Csp before the CSP header is rendered.
- CSP support: `etc/csp_whitelist.xml` (`script-src https://cdn.proteside.com` + static sha256 of the
  bundled bootstrapper, `connect-src https://app.proteside.com`) and dynamic hash whitelisting through
  `Magento\Framework\View\Helper\SecureHtmlRenderer` (works with Magento_Csp disabled too).
- Bundled bootstrapper 1.1.2 (`etc/proteside/bootstrapper.min.js`, readable sources and `VERSION`).
- Optional inline config (`window.__PROTE_INLINE__`) composed exactly like the dashboard: active
  rules and allowed GTM containers fetched server-side from `GET /api/sdk/config` on every status check.
- Entitlement state machine (`GET /api/sdk/status`, fail-closed, 48 h tolerance on transient errors)
  persisted per SDK key through `FlagManager`; only HTTP 200/401 change the decision.
- Scheduled revalidation (cron job `proteside_checkout_revalidate`, every 6 h by default, 12 h / 24 h
  selectable), immediate check on config save and on the admin "Check status" button.
- Admin configuration section Stores > Configuration > Security > Proteside with a read-only status
  row (pill, plan, decision, last check, bootstrapper version, inline rule counts, verify hint).
- CLI: `bin/magento proteside:status [--refresh] [--json]`.
- Translations: en_US, pt_BR, es_ES.
- Full-page cache flush (built-in and Varnish, via the same path as "Refresh Full Page Cache") when
  settings or the entitlement decision change.
- Standalone composition test: `php tests/SnippetTest.php`.
