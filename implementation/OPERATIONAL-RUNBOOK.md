# Implemented local operations and recovery guide

This runbook applies to the isolated development environment. It does not authorize production sales, merchant/supplier activity or outbound customer notifications. [TEST-EVIDENCE.md](TEST-EVIDENCE.md) is the authority for exact executed counts, tested versions, source digests and dates. The recorded local checkpoint includes integration suites, headless browser flows, static checks, reproducible archives, clean ZIP installation and isolated restore verification; none establishes full feature-family completion or production approval.

## Runtime and database boundaries

From repository root, setup downloads pinned WordPress/Woo dependencies as needed, links the owned development packages, activates them, installs EN/FA runtime dependencies and runs migrations:

```sh
python3 deploy/local.py setup --activate-atlas
python3 deploy/local.py start
```

The socket-only project MariaDB uses a 32 MB InnoDB buffer and no TCP listener. PHP binds only to `127.0.0.1:8840`. Generated credentials are private in `.runtime/credentials.json`; keep them out of source, reports and screenshots. The scripts do not change Homebrew/system services. `python3 deploy/local.py stop` stops project runtime processes.

Current companion schema versions are **Atlas Core 6** and **Atlas Inventory 3**. Refresh installed local schemas after an authorized code update with the supplied helper, rather than editing version options or tables:

```sh
WP_CLI_CACHE_DIR="$PWD/.runtime/cache" wp --path="$PWD/.runtime/wordpress" eval-file deploy/migrate-all.php
```

Core migration validates storage and fails closed if required schema is unavailable. `wp atlas migrate` migrates Core; the helper also handles installed inventory/check-in companions. Preserve durable records through upgrades and theme changes.

HTTP demonstration data uses **`atlas_local`**. The integration runner prepares and selects **`atlas_integration`** through the CLI-only `ATLAS_TEST_DB=atlas_integration` boundary. Disposable PHP test fixtures do not run against the browser demo database. Keep that boundary when diagnosing failures; changing database names manually defeats isolation. Browser checkout tests exercise the separate fictional demo with private customer and agency accounts.

Setup installs the mail guard from `deploy/local-mail.php`. In local plus explicit demo mode it returns failure for `wp_mail`, so simulated order, registration and password-reset emails are not sent. This is not a successful delivery simulation. Native login/registration can use a chosen password; reset/delivery acceptance requires an authorized mail service later.

## Fictional demo data and admission

```sh
WP_CLI_CACHE_DIR="$PWD/.runtime/cache" wp --path="$PWD/.runtime/wordpress" eval-file deploy/seed.php
```

Seeding is idempotent and local-only. Newly created demo **tour and cohort offers remain drafts** until linked structured itinerary/course/cohort data is complete and independently approved. Do not make them public through direct SQL or metadata edits. Follow the [partner/agency manual](PARTNER-USER-MANUALS/README.md). Fictional offers do not substantiate real supplier, schedule, insurance or availability claims. Never seed production.

Source packages live outside `.runtime` and are symlinked for development. The clean-install drill instead extracts the owned ZIPs into a fresh WordPress path/database and verifies those installed packages rather than source symlinks.

## Booking, payment and finance queues

Use **Partner portal → Booking confirmation queue** as an agency operator. Filter paid-awaiting-confirmation, exception or confirmed rows, page to the reference and refresh before acting. For an eligible paid reservation, check actual service availability, enter its real confirmation reason and choose **Confirm service**. The nonce-protected action is replay-safe. Payment verification, agency confirmation, fulfillment and supplier ticket issuance remain separate.

The owner account and Woo receipt show actual booking state. `paid_pending_confirmation` explicitly means payment verified and agency confirmation pending, with no confirmed ticket issued by that receipt. `confirmed` means the service reservation was confirmed; supplier documents remain separate. Woo processing/completed status does not replace these checks.

Use **Payment exceptions and refund review** only with both agency access and `atlas_manage_finance`. It has bounded exception pages, exact integer IRR amounts, payment references, ledger pages and refund-request pages. Record an intent with the verified payment reference, exact amount and reason. Review a `requested` item and enter its approval reason through **Approve for processing — no transfer**. Request keys provide replay protection; changed payload/key reuse conflicts. Inspect recorded state after an uncertain response before creating another request.

`requested` and `approved` are decisions, not completed refunds or transfers. Approval does not create a verified refund debit. No public refund-proof endpoint accepts operator-supplied success. Trusted server reconciliation is separate; real provider processing remains unavailable until merchant gates are met. Full bank reconciliation, statutory accounting and payable/payout operations remain unfinished.

Handle monetary exceptions by payment reference:

- **Released allocation with payment:** preserve verified payment and ledger evidence. Keep allocation released and the booking in review; assign a refund/alternative decision without silently reacquiring a seat.
- **Distinct excess payment:** preserve the original confirmed/fulfilled entitlement. Review the extra verified payment separately and request any refund against that payment's remaining balance.
- **Unknown outcome:** inspect order/booking/payment linkage and recorded request keys. Reconcile trusted provider evidence before retrying an external financial action; do not infer provider success from a Woo status or support note.

Domestic gateways stay unavailable; a settings checkbox cannot enable an unimplemented SEP/Mellat/ZarinPal adapter. The simulator requires both a local environment and explicit demo mode. It never proves a real merchant payment.

## Support and organization operations

Agency staff use **Customer support queue** for shared replies, explicitly private notes and version-checked state/next-action/resolution updates. The next action is visible to the customer. A new customer reply reopens a resolved/closed case. Resolution codes do not mark a refund complete. Refresh after a stale-version error. Full support history navigation, notification consumers and CRM/channel delivery remain incomplete; an outbox row proves no delivered notification or SLA.

Use **Partner applications and staff** for independent submitted-application review. Approval creates one approved organization and active applicant membership atomically; the default initial role is contributor. Membership changes require existing user/organization references, a known role, reason and the displayed current revision, with a blank revision only for a new member. Viewer is read-only; other known roles remain constrained by WordPress capabilities and record policy. Unknown roles, revoked memberships and inactive organizations deny scoped access. Partner roles never grant agency/finance power. Direct staff activation is implemented; invitation acceptance/expiry and complete contracts remain gaps.

## Workers and notifications

For a reviewed deployment, a real server scheduler must invoke `wp atlas run_due`; visitor traffic alone is unreliable. Locally, WP-Cron is disabled to limit background load and no real server scheduler is installed. An explicit maintenance invocation is:

```sh
WP_CLI_CACHE_DIR="$PWD/.runtime/cache" wp --path="$PWD/.runtime/wordpress" atlas run_due
```

The worker processes bounded due holds and available outbox work. The agency workspace shows the last completed worker run, lag, due and failed queue counts, bookings awaiting review and primary inventory over capacity. `GET /agency/v1/operations-health` requires agency authority and a fresh REST nonce. Use these signals with the agreed production scheduler and alert thresholds.

In-account delivery requires an approved template for the recipient's language and event. Review the notification queue when an event exhausts its retries. A verified payment exception opens a private support case even when the notice template is unavailable. Review money and capacity in their own queues. External email and SMS delivery require their own configured and verified provider channels.

For a reviewed upload workflow, keep encrypted bytes and keys in separate controlled stores and verify the scanner before granting download access. [Private-document contract](API-CONTRACTS/PRIVATE-DOCUMENTS.md)

## Verification and installable archives

Run verification sequentially at low priority.

```sh
nice -n 19 python3 tests/static.py
nice -n 19 python3 tests/run-integration.py
nice -n 19 python3 tests/run-browser.py
nice -n 19 python3 deploy/verify-packages.py
nice -n 19 python3 deploy/clean-install-check.py
```

The integration runner prepares its separate database, records suite logs/exit statuses and checks that source stayed unchanged. The browser runner uses hidden/headless Chrome, a separate profile and one worker; it covers actual fictional checkout and independent agency confirmation in EN/FA. Full advanced module coverage, assistive-technology/native review and production workload acceptance remain separate.

`verify-packages.py` builds twice and compares every delivery byte, including archives, manifest, SBOM and checksums. It writes `implementation/evidence/package-reproducibility.json`; reproducibility alone does not prove installation. `clean-install-check.py` verifies checksums, creates a separate WordPress path/database, installs real owned ZIPs, tests the theme without companions, activates plugins and checks migrations/catalog registration. Third-party Woo source is reused separately. The generated database/user are removed afterward; its private installation path/log remain development artifacts. Evidence is `implementation/evidence/clean-install-results.json`.

Exact local test results belong in [TEST-EVIDENCE.md](TEST-EVIDENCE.md). Read matching machine reports rather than carrying forward a prior run's totals after source changes. An authored GitHub workflow is not evidence that GitHub CI ran.

## Backup and isolated restore rehearsal

```sh
python3 deploy/local.py backup
nice -n 19 python3 deploy/recovery-check.py
```

Backup writes private `.runtime/backup.sql` using a single-transaction dump. `recovery-check.py` dumps `atlas_local`, restores into a newly named disposable `atlas_restore_*` database and compares every table's row count and extended logical checksum. It checks that source counts/checksums stayed unchanged, records the dump hash and removes only its generated database. The private restore dump is mode 0600. No working database is overwritten.

The recorded local restore checkpoint passed table-count, row-count and logical-checksum comparison. See [TEST-EVIDENCE.md](TEST-EVIDENCE.md) and `implementation/evidence/recovery-results.json` for exact totals and time. This is an isolated logical restore test, not a production RPO/RTO or reconciliation of real provider activity. Keep backup dumps and raw logs private under an approved retention policy.

## Rollback and reopening

For a real incident, stop new financial initiation, preserve the backup/build hash and immutable evidence, and choose only code compatible with installed schema. Do not delete booking/payment records to undo deployment. Validate auth/org boundaries, allocation totals, hold expiry, outbox side effects and Woo order linkage before reopening.

A restore does not reverse out-of-band payments, refunds or supplier actions. A qualified owner must reconcile those events from the backup timestamp and establish the external outcome before sales resume. Production backup storage, scheduler/monitoring/alerts, provider reconciliation and witnessed RPO/RTO acceptance remain required.

Keep workstation verification quiet: one sequential low-priority runner, hidden/headless browser, no foreground focus and no system-service changes. Large peak-load and 100-process stress gates require a separate approved environment. Human/vendor, native-language, security/privacy and release acceptance remain listed separately in [EXTERNAL-DEPENDENCIES.md](EXTERNAL-DEPENDENCIES.md) and [FEATURE-GAPS.md](FEATURE-GAPS.md).
