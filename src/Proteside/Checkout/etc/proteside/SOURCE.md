# Bundled Proteside bootstrapper

| File | Purpose |
| --- | --- |
| `bootstrapper.min.js` | The inline, synchronous bootstrapper emitted by the module (byte-for-byte; the trailing newline is part of the published CSP hash). Optionally `window.__PROTE_INLINE__={…};` is inserted right after `"use strict";` by `Model/Snippet.php`. |
| `bootstrapper.src.index.ts` | Readable source of the bootstrapper (TypeScript). Not used at runtime. |
| `bootstrapper.src.match.ts` | Readable source of the rule matcher used by the bootstrapper. Not used at runtime. |
| `VERSION` | Bootstrapper version (currently `1.1.3`, matches `proteside-sdk` 1.1.3). Shown in the admin status row and in `bin/magento proteside:status`. |

Origin: copied unchanged from the Proteside SDK repository (`proteside-sdk`, build output `dist/bootstrapper.min.js`,
version 1.1.3) on 2026-10-16. It is **never** downloaded from the CDN at runtime; it ships with the module and is
updated together with the module version.

CSP hash of `bootstrapper.min.js` as shipped (no inline config): `sha256-t2ypUgwBPt6N82k2gXq5D5ZHIdxOXxhj6ZISJatVbg8=`
(also listed in `etc/csp_whitelist.xml`).

Updating: replace the four files with the new `proteside-sdk` build, update the hash in `etc/csp_whitelist.xml` and in
the READMEs, bump `Config::MODULE_VERSION` and `composer.json`, run `php tests/SnippetTest.php` (the CSP hash assertion
must be updated), and record the change in `CHANGELOG.md`. Never download the bootstrapper at runtime and never replace
the inline tag with an external `<script src>`.
