# Proteside checkout protection for Magento 2 / Adobe Commerce

[Português (Brasil)](README.pt-BR.md) · [Español](README.es.md)

**Download:** the installable package is attached to the [latest release](https://github.com/proteside-app/proteside-magento/releases/latest). This repository is published by the Proteside team for download only; it does not accept contributions. Questions: [team@proteside.com](mailto:team@proteside.com).

`Proteside_Checkout` (`proteside/module-checkout`) helps you meet PCI DSS 4.0
requirements 6.4.3 and 11.6.1 by loading the Proteside client-side protection on your payment pages,
using your public SDK key. On its own it does not make a store PCI DSS compliant: compliance covers
the whole environment and is assessed by your acquirer or QSA. It
reproduces the canonical installation from the Proteside dashboard — an inline, synchronous
bootstrapper followed by the async `shield.js` — as the **first scripts in `<head>`**, keeps the
entitlement of your subscription in sync with Proteside, and integrates with Magento's
Content-Security-Policy and full-page cache.

## Requirements

| | |
| --- | --- |
| Magento Open Source / Adobe Commerce | 2.4.4 – 2.4.9 |
| PHP | 7.4, 8.1, 8.2, 8.3, 8.4, 8.5 (as supported by your Magento version) |
| Proteside account | <https://app.proteside.com> — the public SDK key (`pk_live_…`) is in **Settings → Pages and Domains** |
| Magento cron | running (`bin/magento cron:run`) for the periodic status check |

Optional: `Magento_Csp` (bundled with Magento since 2.3.5) for CSP hash/host whitelisting; the
module works without it. Varnish is supported (see *Caching*).

## Installation

### Via Composer (recommended)

Until the package is published on Packagist / the Adobe Commerce Marketplace, add the module
directory as a path repository (or a VCS repository pointing at this folder):

```bash
composer config repositories.proteside path /path/to/plugin-magento/src/Proteside/Checkout
composer require proteside/module-checkout:^1.0
bin/magento module:enable Proteside_Checkout
bin/magento setup:upgrade
bin/magento setup:di:compile          # production mode
bin/magento setup:static-content:deploy -f   # production mode (admin JS)
bin/magento cache:flush
```

### Via `app/code`

```bash
mkdir -p app/code/Proteside
cp -R /path/to/plugin-magento/src/Proteside/Checkout app/code/Proteside/Checkout
bin/magento module:enable Proteside_Checkout
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

### Uninstall

```bash
bin/magento module:disable Proteside_Checkout
composer remove proteside/module-checkout   # or rm -rf app/code/Proteside/Checkout
bin/magento setup:upgrade && bin/magento cache:flush
```

The module stores its state in the `flag` table (`proteside_checkout_entitlement_*`) and in
`core_config_data` (`proteside/*`, `crontab/default/jobs/proteside_checkout_revalidate/*`). It
creates no tables of its own.

## Configuration

**Stores → Configuration → Security → Proteside** (ACL resource `Proteside_Checkout::config`).

| Group | Field | Default | Notes |
| --- | --- | --- | --- |
| Proteside checkout protection | Enabled | No | Per website / store view |
| | SDK key | — | `pk_live_` or `pk_test_` + 32 hex characters. Validated on save. Public by design. |
| Connection status | Status | — | Read-only: Active / Grace period / Suspended / Inactive / Invalid key / Connection error / Not verified yet, plan, decision, last check, bundled bootstrapper version, inline rules count, **Check status** button |
| | Re-check status every | 6 hours | 6 / 12 / 24 h; rewrites the cron expression of `proteside_checkout_revalidate` |
| Where to load the protection | Checkout (all steps) | Yes | `checkout_index_index`, `checkout_onepage_failure`, `multishipping_checkout_*` |
| | Order confirmation (thank you) | Yes | `checkout_onepage_success`, `multishipping_checkout_success` — the SDK uses it to confirm card payments; keep it on |
| | Cart | No | `checkout_cart_index` |
| | Entire store | No | Every storefront HTML page |
| | Custom URL patterns | — | One per line. With `/` → matched against the request path, `*` wildcard (`/paypal/*`). Without `/` → matched against the full action name (`paypal_express_review`, `braintree_*`). Use it for payment-return pages of your gateway. |
| Options | Release channel | Follow the dashboard | `auto` uses the `channel` returned by `/api/sdk/config` (default `stable`); `stable` / `latest` override it |
| | Calibration (seconds) | — | 10–120; only used if the remote configuration cannot be fetched |
| | Embed blocking rules in the page (inline config) | Yes | Copies your active rules and allowed GTM containers into the bootstrapper (`window.__PROTE_INLINE__`) so blocking starts from the first byte |

Saving the section triggers an immediate status + config check and a full-page cache flush.

Pages are detected by **Magento action name**, never by URL, so custom checkout URLs and store codes
in URLs are fine. Magento's default checkout is a single page (`/checkout/#shipping`, `#payment`):
the SDK stays loaded across steps. If you use a third-party SPA checkout that changes the URL path
without a full page load, call `Proteside.pageChanged(path)` on route changes (see the docs).

## What gets injected

Right after the opening `<head …>` tag of every selected page:

```html
<!-- Proteside Start -->
<script data-cfasync="false" data-no-optimize="1" data-nowprocket="" data-pagespeed-no-defer="">"use strict";window.__PROTE_INLINE__={"rules":[…],"gtm":[…]};var __ProteBoots=…</script>
<script src="https://cdn.proteside.com/v1/shield.js" async="true" data-key="pk_live_…" data-api="https://app.proteside.com/api/sdk"></script>
<!-- Proteside End -->
```

- The bootstrapper (version in `etc/proteside/VERSION`, sources `bootstrapper.src.*.ts`) is shipped
  inside the module and emitted byte for byte. It is never downloaded at runtime.
- `window.__PROTE_INLINE__` only appears when inline config is enabled **and** there is at least one
  valid rule or GTM container; it lives in the same `<script>` as the bootstrapper.
- `shield.js` only carries `data-key`, `data-api` and, optionally, `data-calibration`. The module
  does not render a trust badge and never sends `data-safe-url` (the badge is a static snippet copied
  from the dashboard, Settings → Store).
- If the page already contains a Proteside bootstrapper (e.g. pasted in the theme), nothing is
  injected to avoid loading the SDK twice. Remove the manual snippet once the module is active.

## Installation order (`INSTALLATION_ORDER_WARNING`)

The bootstrapper records `window.__PROTE_BOOT_SCRIPTS__ = document.scripts.length - 1`. If any
`<script>` (inline or external) runs before it, the SDK reports `INSTALLATION_ORDER_WARNING` to the
dashboard and `Proteside.getStatus().installationOrderValid` is `false`; protection continues with
partial coverage. This module injects immediately after `<head …>`, before `requirejs`, the theme's
head scripts and any tag manager, so the order is correct out of the box.

**Verify:** open your checkout, open the browser console and run

```js
Proteside.getStatus()
```

`installationOrderValid` must be `true`. If it is `false`, something else puts a `<script>` in
`<head>` before our tags — typically another module that also rewrites the response, a CDN/edge
worker, or a Google Tag Manager snippet hard-coded in the theme's `head.phtml` (Proteside must come
**before** GTM; never install Proteside through GTM).

Why not layout XML? Blocks in `head.additional` / `default.xml` render after `requirejs` and the
theme's scripts, which would always trip the warning. The response observer is the only way to be
first.

## Content-Security-Policy

`etc/csp_whitelist.xml` adds, when `Magento_Csp` is enabled:

- `script-src https://cdn.proteside.com` + the static hash of the bundled bootstrapper without
  inline config (`sha256-t2ypUgwBPt6N82k2gXq5D5ZHIdxOXxhj6ZISJatVbg8=`, bootstrapper 1.1.3);
- `connect-src https://app.proteside.com https://cdn.proteside.com` (shield.js also hashes external scripts, its own included, with a CORS fetch).

Additionally both tags are rendered through `Magento\Framework\View\Helper\SecureHtmlRenderer`,
which `Magento_Csp` hooks to whitelist the sha256 of the **actual** inline content (it changes when
inline rules are embedded) and the host of the `src`. The hash is registered during
`controller_action_predispatch`, before the CSP header is built, so Magento 2.4.7's *restrict mode*
on `checkout_index_index` works without `'unsafe-inline'`.

Pages served from the built-in full-page cache (never the checkout, cart or success pages, which
are non-cacheable) rely on the static hash: on such pages, if inline rules are embedded, keep CSP in
report-only mode or disable "Embed blocking rules" for "Entire store" / custom cacheable pages.

If you run your own CSP outside Magento (web server, WAF, CDN), add:

```
script-src  … https://cdn.proteside.com 'sha256-<hash of the emitted inline content>'   (or 'unsafe-inline')
connect-src … https://app.proteside.com https://cdn.proteside.com
```

`bin/magento proteside:status` prints the current bootstrapper hash. With inline rules the content
— and therefore the hash — changes whenever your rules change; use a nonce/`'unsafe-inline'` in
that case or disable inline config.

## Caching (full-page cache and Varnish)

- **Built-in FPC:** the snippet is injected in `controller_front_send_response_before`, which runs
  *after* the FPC stored (miss) or served (hit) the page. The snippet is therefore **never part of
  the cached entry** and cached pages always receive the current key, channel, rules and
  entitlement decision. Nothing to flush.
- **Varnish / reverse proxies / CDN page caches:** the response leaving Magento already contains the
  snippet, so the cache holds it. The module flushes the full-page cache (same path as Cache
  Management → Refresh *Page Cache*, which purges Varnish) when you save the configuration and
  whenever a status check changes what is emitted (decision, channel, inline rules, CDN base). If
  you cache pages in a CDN that Magento does not purge, purge it manually after changing the key.
- Checkout, cart and order-success pages are non-cacheable in Magento, so the protection on payment
  pages is never stale.

## Entitlement and the status check

The module calls `GET https://app.proteside.com/api/sdk/status` **only on the server**: when the
configuration is saved, from cron (every 6/12/24 h) and from the **Check status** button /
`proteside:status --refresh`. Never while rendering a page.

| Last definitive answer | API now | Protection loads? |
| --- | --- | --- |
| `active` / `grace` | OK | yes (grace shows a billing warning in the admin) |
| `suspended` / `inactive` / `invalid` (401) | OK | no |
| never validated | unavailable | no (fail-closed) |
| `active` / `grace`, last success ≤ 48 h ago | unavailable (429/5xx/timeout) | yes (tolerance) |
| `active` / `grace`, last success > 48 h ago | unavailable | no |

`domainMismatch: true` is only a warning ("this key belongs to a store registered under a different
domain"). The same cycle fetches `GET /api/sdk/config` for the inline rules, GTM containers and the
release channel. State is kept per SDK key (`flag` table) and is shared by all store views using the
same key.

Cron: job `proteside_checkout_revalidate`, group `default`. Check it is scheduled with
`bin/magento cron:run --group=default` and the `cron_schedule` table. If no check ran for more than
48 hours the status row warns you.

## CLI

```bash
bin/magento proteside:status            # cached state per key, decision, plan, channel, inline counts
bin/magento proteside:status --refresh  # query the API first
bin/magento proteside:status --json
```

## Multi-store

The SDK key, pages and options can be set per website or store view. Each Proteside store (domain)
has its own key: set it at the store-view scope of that domain. The `site` sent to the status
endpoint is the base URL of the first store view using the key.

## External services / data sent

- `https://app.proteside.com/api/sdk/status` and `/api/sdk/config` (server-side): your public SDK
  key, the store base URL (`site`), `platform=magento`, the module version and a `User-Agent`
  `Proteside-Magento/1.0.1; <site>`. No customer or order data.
- In the visitor's browser, `shield.js` (from `https://cdn.proteside.com`) sends its telemetry to
  `https://app.proteside.com/api/sdk/events` as described in the Proteside documentation.
- Terms: <https://proteside.com/termos> · Privacy: <https://proteside.com/privacidade>.

## Limitations

- Nothing here can be verified in this repository without a Magento instance; see `VERIFICATION.md` for
  what was and was not executed.
- Pages whose HTML has no `<head …>` tag, non-HTML responses, redirects and AJAX fragments are left
  untouched.
- Other modules or edge workers that rewrite the response after this observer (alphabetically later
  module names run later on the same event) could move our tags. Verify with `Proteside.getStatus()`.
- The bootstrapper updates with the module. When Proteside publishes a new bootstrapper, update the
  module.
- PWA Studio and other headless storefronts do not render HTML through Magento's response
  pipeline; use the Proteside snippet in the frontend application instead.

## FAQ

**The status says "Not verified yet".** Save the configuration or click **Check status**. Make sure
the server can reach `app.proteside.com` over HTTPS (port 443).

**"Connection error" but protection still active?** Transient API errors keep the last decision for
48 hours. If it persists, check outbound connectivity/firewall and `var/log/system.log`.

**I changed the key and the storefront still shows the old one.** With Varnish, flush the page cache
(the module does it on save; otherwise Cache Management → Refresh Page Cache). Also clear your CDN.

**Does it work with Magento 2.4.7 CSP restrict mode on checkout?** Yes — the inline hash and
`cdn.proteside.com` are whitelisted dynamically before the header is sent.

**Can I keep the snippet pasted in my theme?** No. Remove it; the module skips injection when it
detects a bootstrapper already on the page, but you would lose the entitlement/rules sync.

## Support

team@proteside.com · <https://docs.proteside.com> · dashboard <https://app.proteside.com>

## License

MIT — see [LICENSE](LICENSE).
