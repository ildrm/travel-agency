# Atlas Leisure — WordPress/WooCommerce implementation

An editable EN/FA block theme and modular companions, built from the [49 project specifications](docs/DOC-00_MASTER-INDEX.md). This is an evolving local implementation; the complete platform is **not production-approved**. [Feature-level gaps](implementation/FEATURE-GAPS.md) distinguish unfinished code from [external dependencies](implementation/EXTERNAL-DEPENDENCIES.md).

## Run the local demonstration

On the current macOS/POSIX setup, PHP with mysqli/intl, MariaDB client/server tools, WP-CLI, Python3 and Node/npm must be available. Recorded runtime versions are in [runtime.lock.json](deploy/runtime.lock.json). The installer downloads pinned official WordPress/WooCommerce and language packs; it does not change system database services.

```sh
npm ci --ignore-scripts
npm run demo
```

Then open `http://127.0.0.1:8840` when convenient. Nothing opens a browser automatically. Generated agency and fictional-customer credentials are in `.runtime/credentials.json` (private and gitignored). All demonstration offers, customers, orders and payments are fictional. The test payment method explicitly transfers no funds. Outbound mail is blocked in this isolated demo; delivery and password-reset email are not represented as tested.

The runtime uses a socket-only database with a 32MB InnoDB buffer and a loopback-only PHP server. Commands use low priority and tests are sequential. `npm run stop` stops only owned project processes. PHP's built-in server is for local development, not production hosting.

## Packages and source boundaries

| Package | Responsibility |
|---|---|
| `wp-content/themes/atlas-leisure` | Block templates, theme.json, patterns, responsive EN/FA/RTL presentation and original SVG concepts |
| `wp-content/plugins/atlas-core` | Organization/object permissions, typed records, transactional seats/holds, payment/refund/ledger, Woo CRUD/Store API bridge, audit/outbox |
| `wp-content/plugins/atlas-operations` | Catalog, structured tour review, event check-in, cohort enrollment/materials, partners, support, procurement and staff queues |
| `wp-content/plugins/atlas-gateways` | Disabled SEP/Mellat/ZarinPal boundaries and a strict local-only Woo test simulator |
| `wp-content/plugins/atlas-inventory` | Staff-only local room-night/villa/vehicle scheduling, return conflicts and manual eligibility review |

Install theme and plugin ZIPs independently through WordPress. Activation order: WooCommerce → Atlas Core/migration → Operations/Gateways/Inventory → theme. Theme alone renders safely; changing it retains companion data. Core migrations are in `atlas-core/src/DB.php`; local inventory owns its separate schema. Deactivation does not silently delete records. Live provider methods and digital assets cannot be enabled by an ordinary checkbox.

## Verification and artifacts

```sh
npm run test:static
npm run test:integration
npm run test:browser
npm run package
```

Integration fixtures use `atlas_integration`, separate from the HTTP demonstration database. Browser tests use headless Chrome with a temporary profile and one worker; on this computer they use the existing Chrome executable. Override `ATLAS_CHROME` for a compatible installed executable. Tests never focus your regular browser. Large peak-load/100-process tests are not run on the active workstation.

Actual results live in `implementation/evidence/`; [STATUS](implementation/STATUS.md), [release gates](implementation/04-release-gates.md) and [continuation checkpoint](implementation/NEXT_ACTIONS.md) describe what remains. Installable development ZIPs, manifest and SHA-256 checksums are generated in `dist/`. `deploy/clean-install-check.py` installs real archives into a separate temporary WordPress/database; `deploy/recovery-check.py` restores a private snapshot into a new disposable database without overwriting the demo.

The recorded checkpoint passed six integration suites, eight hidden-browser tests and 96 static checks. Five actual ZIPs passed deterministic rebuild and clean-install checks; the local restore matched counts and logical checksums for 69 tables. [Test evidence](implementation/TEST-EVIDENCE.md) defines the measured scope; [release readiness](implementation/RELEASE-READINESS.md) records the incomplete modules and open gates. Both checkout axe scans retain a critical incomplete email-description reference requiring resolution.

External merchant/supplier contracts and credentials, legal/tax/retention decisions, native financial/legal Persian review, final brand/assets, independent accessibility/security review and production operations remain gates. No public deployment, real transaction, live ticket issuance or human signoff has been fabricated. The authored GitHub Actions workflow is not evidence that GitHub CI ran.
