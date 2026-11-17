# Deterministic continuation checkpoint

Last completed slice, 17 November 2026: repaired and verified actual EN/FA customer hold→Woo Checkout Blocks→local simulator receipt→separate agency UI confirmation; strengthened role/audit/payment/cart/time/inventory safety; built five reproducible ZIPs, installed actual archives in a new database and restored the demo snapshot to a separate database with matching logical checksums. All six integration suites, eight browser tests and 96 static checks passed at the hashes in [TEST-EVIDENCE.md](TEST-EVIDENCE.md).

Branch: `feat/atlas-implementation`. Preserve the user's initial commit and subsequent local changes. Do not rerun document intake or replace the canonical 49 specifications. Source is in `wp-content`; do not edit downloaded WordPress/Woo vendor code or reset the existing demo database. Core 6/inventory 3/check-ins 1 are current. Original docs are unchanged.

## Next code slice: FR-CAT-001 search and facets

1. Read DOC-10, DOC-18, DOC-26 and DOC-29's exact catalog requirements plus [FEATURE-GAPS.md](FEATURE-GAPS.md). Use the codebase-memory graph with path coverage and direct fallback for recorded parse gaps; graph at this checkpoint knows the repository, while `functions.php` has a parse gap and `deploy` is excluded.
2. Implement localized normalized search and destination/date/price/accessibility filters with bounded paging and input validation. Primary files: `atlas-core/src/Offers.php`, `Rest.php`, `atlas-operations/operations.php`, theme catalog presentation; add migrations only when actually required. Keep private costs, supplier fields, nonpublic offers and owner records out of search/counts/facets.
3. Add meaningful fixtures for Persian/Arabic character normalization, mixed locale values, strict date/price boundaries, empty/invalid filter combinations, pagination and public/private exclusion. Add actual EN/FA keyboard/browser filter interactions. Do not substitute a passing kind filter for the documented full facets.
4. Run only affected checks until stable, then complete sequential suites against a frozen product hash. Update the traceability row and feature gaps from actual behavior and evidence.

## Following slices

- Customer accommodation and rental quote/booking: connect resource calendars/intervals and immutable eligibility to one authoritative allocation/payment lifecycle, including expiry/payment/return races and safe compensation. Current staff inventory is local/demo-only and is not a public production booking service.
- Events: tiers/session quotas, participant schemas, recurrence/waitlist and offline/QR check-in. Academy: multi-learner, schedule conflicts, assessment and certificate verification/revocation. Tours: reusable definitions/departures and booked change/consent/manifest operations.
- Procurement full award/proof/QC/shipping/settlement semantics; invitation acceptance/contracts; support pagination and actual notification consumers; complete localized operational labels/documents and editorial URLs.
- Close checkout's missing ARIA description with supported integration behavior and native/assistive verification; finish privacy/retention/erasure and denial telemetry with approved policy. Keep unavailable live PSP/supplier/crypto paths closed while implementing feasible code.

## Safe local commands

Run at low priority with one test runner at a time; only headless/hidden browsers, no user desktop or system-service disruption:

```sh
rtk proxy nice -n 19 python3 deploy/local.py demo
rtk proxy nice -n 19 python3 tests/static.py
rtk proxy nice -n 19 python3 tests/run-integration.py
rtk proxy nice -n 19 python3 tests/run-browser.py
rtk proxy nice -n 19 python3 deploy/verify-packages.py
rtk proxy nice -n 19 python3 deploy/clean-install-check.py
rtk proxy nice -n 19 python3 deploy/recovery-check.py
rtk proxy nice -n 19 python3 deploy/local.py stop
```

Run full suites after source changes justify them; do not repeat already passing checks without a new change or unresolved concern. Browser tests use a dedicated fictional customer, persist fictional receipts and consume capacity; do not apply them to a real deployment. Do not run 100 PHP workers or production peak-load tests on this active workstation.

Private generated credentials and SQL snapshots are under ignored `.runtime`; never copy them into reports, git or releases. Demo mail is deliberately blocked; reset/delivery acceptance is not tested. External requirements and owners are in [EXTERNAL-DEPENDENCIES.md](EXTERNAL-DEPENDENCIES.md). No public deployment, external message, real charge/refund or provider test is authorized by a local verification pass. Do not claim work continues in the background after handoff.
