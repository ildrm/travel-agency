# Executed local verification

Recorded 17 November 2026. These results verify the stated local workflows and archives. They do not close all 31 broad requirements or establish production, provider, accessibility or legal approval. Detailed unfinished behavior is in [FEATURE-GAPS.md](FEATURE-GAPS.md).

## Environment and build identity

Repository: `/Users/shahinilderemi/Documents/tmp/iranbroker`, branch `feat/atlas-implementation`. Official local runtime: WordPress 7.1.4, WooCommerce 11.3.0, PHP 8.5.8, MariaDB 13.0.2; WP-CLI 2.12.0; existing headless Chrome reports 157.0.0.0. Core schema 6, local inventory schema 3, check-in schema 1. [runtime.lock.json](../deploy/runtime.lock.json) records the observed configuration, not a promise for untested versions.

The integration runner uses `atlas_integration`, independently of the HTTP demo's `atlas_local`. Only fictional fixtures were used. WP-CLI's PHP 8.5 vendor deprecations are retained in logs. No vendor patches or warning suppression were used to manufacture a pass.

| Scope | SHA-256 | Immutability evidence |
| --- | --- | --- |
| Product files under `wp-content` | `1eb48d209a970d77b1c1cbc982037e37734ed09c34ad78c7c21c2507d396fb71` | Browser/static record this same hash; browser source unchanged during run |
| Product files plus PHP test fixtures | `661f53daaacfd85f1928c26235e030904a8e2d0446150f513a514005f09496f6` | Integration source unchanged during all six suites |

These hashes deliberately cover different file sets. [dist/manifest.json](../dist/manifest.json) records individual archive source hashes; [SBOM.json](SBOM.json) inventories owned source and declared locks with explicit coverage limits.

## Integration and static checks

Executed `rtk proxy nice -n 19 python3 tests/run-integration.py`; final recorded completion 12:58:20 UTC. All six commands exited 0. [integration-results.json](evidence/integration-results.json) contains exact commands, database, hash and log paths.

| Executable suite | Recorded result | Material coverage |
| --- | --- | --- |
| `tests/core-integration.php` | 53 passed, 0 failed | Strict half-open times, actual two-connection last-seat race, idempotency, amount/unit checks, verified/released/excess money, immutable entitlement, owner status/no-store, schema failure guard, audit UUID/rollback, org and role ceilings |
| `tests/core-woo-integration.php` | 34 passed, 0 failed | Actual Woo CRUD/HPOS/Store API, local simulated checkout, service/physical cart isolation, pre-add conflict/duplicate preservation, classic/order-pay/Blocks gateway restrictions, refund proof replay, deadline and paid-receipt retry |
| `tests/domain-integration.php` | 115 passed, 0 failed | Tour review/chronology, check-in uniqueness, enrollment linkage/materials, private support, transfer request, partner onboarding/members, finance UI and access denials |
| `tests/gateway-integration.php` | Passed; no assertion total emitted | Domestic methods unavailable, explicit local-only simulator, production refusal and draft Persian gettext; no external payment request |
| `tests/frontend-integration.php` | 34 PASS checks; 0 failed, 0 skipped | Real block template/theme.json resolution, EN/FA and menu/checkout locale, dependency fallback, theme-switch persistence |
| `tests/inventory-integration.php` | 50 PASS checks; 0 failed, 0 skipped | Room nights/min-stay/blackout/villa, vehicle overlaps/turnaround/maintenance, pickup/return and reviewed eligibility, real separate-process last-room and overlapping-vehicle races |

Executed `rtk proxy nice -n 19 python3 tests/static.py` at 13:00:11 UTC: **96 passed, 0 failed**. [static-results.json](evidence/static-results.json) includes individual PHP lint, JS syntax, JSON, SVG, required theme files, limited secret-pattern and unchanged-source checks. Eleven deployment/test Python files were also parsed with Python's AST. Syntax and two secret patterns are not a complete security scanner.

## Actual hidden-browser journeys

Executed `rtk proxy nice -n 19 python3 tests/run-browser.py`, 12:59:13–12:59:32 UTC: **8 passed, 0 failed, 0 skipped, 0 flaky**, 19.1 seconds. One headless worker, a separate browser profile, reduced motion; existing desktop browser windows were not controlled. [browser-results.json](evidence/browser-results.json) and [browser-build.json](evidence/browser-build.json) record the execution.

- English and Persian discovery: document language/direction, visible content, keyboard skip link, no page JavaScript errors, desktop 1440×1000 and narrow 320×800 reflow/screenshots.
- Separate English and Persian desktop axe scans.
- Actual native sign-in, Persian customer-account rendering and authenticated partner portal.
- Public catalog plus denial of anonymous private access and forced crypto activation.
- Each language: fictional customer signs in, uses the offer form, receives a real persisted hold, submits Woo Checkout Blocks billing/payment UI, reaches the local-simulator receipt, observes verified money plus `paid_pending_confirmation`, then a separately signed-in agency operator confirms through its actual queue UI. Customer owner API then reports `confirmed`. No funds moved and no supplier ticket was issued.

The browser resets only its dedicated fictional customer's saved cart as a precondition. It does not bypass checkout, inject payment proof, change agency authorization, or confirm through a direct test-only API.

Evidence: [English checkout](evidence/checkout-en.png), [Persian checkout](evidence/checkout-fa.png), [English receipt](evidence/receipt-en.png), [Persian receipt](evidence/receipt-fa.png), [English agency confirmation](evidence/agency-confirm-en.png), [Persian agency confirmation](evidence/agency-confirm-fa.png). These screenshots contain fictional demonstration details only. Credentials, order keys and nonces are not release evidence; private opaque order associations stay in ignored `.runtime`.

All four axe snapshots have zero `violations`. Both checkouts still contain one **critical `incomplete`**: email `aria-describedby` points at missing `wc-guest-checkout-notice`. That finding remains open. [ACCESSIBILITY-REPORT.md](ACCESSIBILITY-REPORT.md) records manual/assistive/native review gaps. [PERFORMANCE-REPORT.md](PERFORMANCE-REPORT.md) limits the navigation samples to local observations; no field p75, INP or load claim is made.

## Archives, clean installation and restore

Executed `rtk proxy nice -n 19 python3 deploy/verify-packages.py` at 13:01:45 UTC. Two sequential generations produced identical bytes for all **five ZIPs plus SBOM, manifest and SHA256SUMS**. [package-reproducibility.json](evidence/package-reproducibility.json) records both hash sets. Archives use sorted files, fixed timestamps and stored compression. Hashes provide integrity/reproducibility evidence, not a cryptographic release signature or independent attestation.

Executed `rtk proxy nice -n 19 python3 deploy/clean-install-check.py`: actual theme ZIP rendered without Woo/companions; all four actual plugin ZIPs extracted/activated into a new local WordPress directory and disposable database, with repeatable migrations/schema and registered catalog checks. Owned packages were extracted archives, not source symlinks. [clean-install-results.json](evidence/clean-install-results.json) contains the final checks and exact archive hashes. This is a fresh-install smoke check; it does not prove every upgrade, rollback, browser or extension combination. Mail was blocked in this isolated check.

Executed `rtk proxy nice -n 19 python3 deploy/recovery-check.py`, 13:03:11–13:03:14 UTC: **69 tables, 1,736 rows**, all restored row counts and logical checksums matched, source unchanged, 2.66 seconds. It restored a private transactional snapshot to a new disposable database and removed that target. It never overwrote the demo. [recovery-results.json](evidence/recovery-results.json) records dump hash and scope. It does not prove file/media recovery, external PSP/supplier reconciliation, production RTO/RPO or disaster operations.

## Security, failures and limits

Executed `rtk proxy nice -n 19 npm audit --json`: exit 0, zero known vulnerabilities reported for the pinned npm dependency set. [npm-audit.json](evidence/npm-audit.json) is the raw advisory response. This concerns development tooling, not every WordPress/WooCommerce/PHP/OS dependency or application vulnerability. [SECURITY-REPORT.md](SECURITY-REPORT.md) is a bounded AI-assisted review, not an independent audit.

Earlier runs exposed real defects: audit UUID coercion, organization/role gaps, excess-payment entitlement regression, MariaDB contention/snapshot behavior, symlink asset routing, invalid generated Woo block pages, coming-soon mode, simulator's missing accessible label, and absent API payment projection. These were repaired and covered by the final runs above. Current files supersede intermediate failing snapshots; historical logs remain local where retained. Old malformed demo audit associations were preserved and are not used as financial linkage proof.

Unexecuted: actual PSP/supplier sandbox/live transactions; production deployment; GitHub-hosted CI; full original TC catalogue; complete cross-browser/assistive/native review; 100-worker/peak load; production scheduler/notifications; real refund/deposit/settlement; complete privacy retention/erasure; all advanced business workflows. The authored [CI workflow](../.github/workflows/verify.yml) is automation source, not evidence of a remote run.
