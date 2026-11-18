# Test evidence for Atlas 0.2.0

Verified 18 November 2026. The frozen source passed 165 static checks, 24 integration suites and 13 headless browser scenarios. The five current ZIPs were built twice, extracted and activated from their archives. Restored database counts and logical checksums match across 102 tables and 2,727 rows. [Release evidence](evidence/development-release.json)

Product SHA-256: `d022d55075978b95aa3f02d69d158067f8f049fd58b0519750398868c7138a1c`. The integration runner adds the PHP and JavaScript fixture sources to its fingerprint, `d07cba0a7bd23358b721811b6ed820d29121aa1b1fa35378f9ede44dc5143b7b`. Static, integration and browser evidence agree on the same product hash. The archive contents also match their workspace sources. The isolated integration database was `atlas_integration`. Observed versions were WordPress 7.1.4, WooCommerce 11.3.0, PHP 8.5.8, MariaDB 13.0.2, Node 26.12.0 and WP-CLI 2.12.0. [Runtime lock](../deploy/runtime.lock.json)

| Integration suite | Result | Local execution log |
| --- | --- | --- |
| `core-integration` | 53 passed, 0 failed | [Log](evidence/core-integration.log) |
| `core-woo-integration` | 39 passed, 0 failed | [Log](evidence/core-woo-integration.log) |
| `domain-integration` | 111 passed, 0 failed | [Log](evidence/domain-integration.log) |
| `gateway-integration` | Passed with exit code 0 | [Log](evidence/gateway-integration.log) |
| `frontend-integration` | Passed with exit code 0 | [Log](evidence/frontend-integration.log) |
| `inventory-integration` | Passed with exit code 0 | [Log](evidence/inventory-integration.log) |
| `academy-completion-integration` | 74 passed, 0 failed | [Log](evidence/academy-completion-integration.log) |
| `booking-extensions-integration` | 8 passed, 0 failed | [Log](evidence/booking-extensions-integration.log) |
| `catalog-integration` | 76 passed, 0 failed | [Log](evidence/catalog-integration.log) |
| `dispatch-completion-integration` | 35 passed, 0 failed | [Log](evidence/dispatch-completion-integration.log) |
| `editorial-integration` | 14 passed, 0 failed | [Log](evidence/editorial-integration.log) |
| `events-advanced-integration` | 66 passed, 0 failed | [Log](evidence/events-advanced-integration.log) |
| `events-completion-integration` | 82 passed, 0 failed | [Log](evidence/events-completion-integration.log) |
| `learner-booking-integration` | 70 passed, 0 failed | [Log](evidence/learner-booking-integration.log) |
| `notifications-support-integration` | 22 passed, 0 failed | [Log](evidence/notifications-support-integration.log) |
| `partner-completion-integration` | 41 passed, 0 failed | [Log](evidence/partner-completion-integration.log) |
| `partner-notices-integration` | 25 passed, 0 failed | [Log](evidence/partner-notices-integration.log) |
| `private-file-privacy-integration` | 24 passed, 0 failed | [Log](evidence/private-file-privacy-integration.log) |
| `private-files-integration` | 28 passed, 0 failed | [Log](evidence/private-files-integration.log) |
| `procurement-completion-integration` | 43 passed, 0 failed | [Log](evidence/procurement-completion-integration.log) |
| `resource-booking-integration` | 111 passed, 0 failed | [Log](evidence/resource-booking-integration.log) |
| `tours-completion-integration` | 94 passed, 0 failed | [Log](evidence/tours-completion-integration.log) |
| `zarinpal-integration` | 38 passed, 0 failed | [Log](evidence/zarinpal-integration.log) |
| `events-qr` | 9 passed, 0 failed | [Log](evidence/events-qr.log) |

The suites with numeric summaries printed 1,063 passing assertions and zero failures. Three other suites record success through their exit status and own logs. The JSON report retains every command and result. WP-CLI's PHP 8.5 dependency deprecation messages remain visible in the logs. [Integration record](evidence/integration-results.json)

The headless browser ran in one worker for 43.9 seconds. It covered both homepage languages and service checkout/voucher flows, private access and crypto denial, localized catalog and editorial navigation, physical flat-rate shipping with offline payment, an exact dated-stay quote followed by supplier and agency confirmation, and QR image/file scanning with a mocked camera. The paired homepage and checkout axe reports each show zero violations and zero incomplete findings. [Browser record](evidence/browser-results.json) · [Build record](evidence/browser-build.json) · [Accessibility evidence](ACCESSIBILITY-REPORT.md)

The aggregate release check compares the five packages and three accompanying integrity files across two generations. A fresh installation used the actual ZIP files and a disposable database, verified theme-only fallback and companion activation, migrated the schema and checked private routes and payment enablement defaults. The restore drill verified the loopback demo database in a different temporary database. [Reproducibility](evidence/package-reproducibility.json) · [Installation](evidence/clean-install-results.json) · [Restore](evidence/recovery-results.json) · [Manifest](../dist/manifest.json)

The copied documentation validated 49 primary specifications, all 31 functional and nonfunctional IDs, with zero errors or warnings. The 56 source documentation files are unchanged. The public npm advisory checks reported zero matching vulnerabilities for the locked development package set and the bundled QR library. [Documentation inventory](00-docs-inventory.md) · [Development dependencies](evidence/npm-audit.json) · [Bundled library audit](evidence/npm-audit-bundled.json)

Run these commands against the project-owned local runtime in order. Browser verification prepares its own dated-stay fixture and uses headless Chrome. Use the current release manifest and keep private runtime credentials and database snapshots outside Git.

```sh
rtk proxy nice -n 19 python3 deploy/local.py demo
rtk proxy nice -n 19 python3 tests/static.py
rtk proxy nice -n 19 python3 tests/run-integration.py
rtk proxy nice -n 19 python3 tests/run-browser.py
rtk proxy nice -n 19 python3 deploy/verify-packages.py
rtk proxy nice -n 19 python3 deploy/clean-install-check.py
rtk proxy nice -n 19 python3 deploy/recovery-check.py
rtk proxy nice -n 19 python3 tests/verify-release-evidence.py
rtk proxy nice -n 19 python3 deploy/local.py stop
```

Payments, scanner clearance and confirmed privacy requests use fictional fixture evidence. External merchant, supplier, email, SMS, real antivirus operation and physical camera acceptance require their own evidence. The checks also leave real devices, independent security and accessibility review, production performance and deployment open. [Remaining release gates](RELEASE-READINESS.md)
