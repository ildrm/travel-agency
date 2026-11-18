# Atlas Leisure — WordPress/WooCommerce implementation

Atlas Leisure is an editable English and Persian block theme with four WooCommerce companion plugins. The project review traces working features, verification and remaining release gates to the 49 specifications. [Specifications](docs/DOC-00_MASTER-INDEX.md) · [Project review](implementation/PROJECT-REVIEW.md) · [Release status](implementation/STATUS.md)

## Run the local demonstration

The macOS/POSIX demo uses PHP with mysqli, intl, fileinfo and sodium, plus MariaDB client/server tools, WP-CLI, Python 3 and Node/npm. The installer downloads pinned official WordPress, WooCommerce and language packs. It runs a project-owned database process. [Runtime versions](deploy/runtime.lock.json)

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
| `wp-content/plugins/atlas-operations` | Catalog, editorial review, tours, events, Academy, dated resources, dispatch, partner contracts, procurement, support, notices and private documents |
| `wp-content/plugins/atlas-gateways` | Local simulator, prepared ZarinPal V4 adapter, and disabled SEP and Mellat boundaries |
| `wp-content/plugins/atlas-inventory` | Shared local room-night, villa and vehicle calendars with maintenance, return conflicts and manual eligibility review |

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

The test evidence records the exact source hashes, commands and results for the available build. English and Persian Checkout Blocks journeys include the repaired email description and simulated service payment. The physical purchase test uses WooCommerce shipping and its offline payment method. [Test evidence](implementation/TEST-EVIDENCE.md) · [Release readiness](implementation/RELEASE-READINESS.md)

External merchant/supplier contracts and credentials, legal/tax/retention decisions, native financial/legal Persian review, final brand/assets, independent accessibility/security review and production operations remain gates. No public deployment, real transaction, live ticket issuance or human signoff has been fabricated. The authored GitHub Actions workflow is not evidence that GitHub CI ran.
