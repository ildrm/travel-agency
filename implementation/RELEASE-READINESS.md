# Release decision

**VERIFIED_LOCAL** for Atlas Leisure 1.0.0-rc.1 on WordPress 7.1.4 and WooCommerce 11.3.0. The frozen theme and companion build passed 172 static checks, 25 integration suites, 13 headless browser tests, deterministic packaging, a fresh installation from the five ZIPs and isolated database recovery. [Exact evidence](TEST-EVIDENCE.md) · [Current ZIPs](../dist/manifest.json)

| Module | Locally verified scope | Remaining acceptance |
| --- | --- | --- |
| Identity | Native account flow, organization role ceilings, scoped invitations and revocation | Actual delivery and recovery, MFA and abuse controls |
| Catalog | Bilingual discovery, search normalization, typed filters, comparison and freshness | Licensed geosearch and real supplier data freshness |
| Tours | Reusable versions and departures, schedule review, customer consent, rooming, manifests and assigned operations | Real itinerary and supplier evidence, staff rehearsal |
| Events | Tiers and quotas, reviewed recurrence, changes, versioned attendee forms, QR image and local scan queue | Venue/catering integration and field testing with physical devices |
| Academy | Cohort scheduling, group enrollment, payer separation, attendance, assessments and certificate controls | Instructor and learner acceptance, any chosen accreditation/provider agreement |
| Stays | Named rate plans, exact dated quotes, shared room-night capacity, WooCommerce, supplier and agency acceptance and cancellation | Mixed-rate stays, allotments and approved channel/PMS reconciliation |
| Rentals | Local fleet pricing, inventory, eligibility, pickup, return, disputes and cancellation | Inspection workflows, external deposits and insurer verification |
| Transfers | Manual dispatch, assigned driver, schedule locks, waiting and no-show lifecycle | Staffed operations, approved provider tracking if selected |
| Flight, rail and bus | Scoped inquiry records and review | Licensed supplier terms and authoritative shopping, hold, issue and change/refund APIs |
| Physical commerce | Native WooCommerce shipping, offline checkout and manual bookkeeping refund | Wider variants, taxes, refunds and carriers |
| Partner and procurement | Onboarding, contracts, bids and split awards, private artwork, sample/QC and manual payable | KYB policy, operational acceptance and actual settlements |
| Service commerce | Blocks and HPOS, exact accepted quote, separate confirmation and printable EN/FA voucher | General requotes, bundles and statutory invoice policy |
| Payments and finance | Local simulator, prepared ZarinPal V4, verified ledger and refund review, manual procurement evidence | Merchant acceptance, statement reconciliation, chargebacks, live refunds and payouts |
| Support and operations | Pagination, cases and notes, in-account notices, retries, worker heartbeat and health | Staffed delivery, external channels, SLA and production monitoring |
| CMS and localization | Reviewed guides, reciprocal canonical links, EN/FA/RTL core journeys | Site-wide content ownership, native review, full terminology and equivalent tasks |
| Theme and visuals | Editable theme ZIP, safe standalone behavior, original SVG assets | Supported version/browser matrix and final creative approval. DOC-15 keeps 3D optional. |
| Data and privacy | Scoped access, encrypted files, scan contract, expiry, legal holds, portability and erasure review | Actual scanner, approved data retention, key/file recovery and independent privacy assessment |

| Gateway | Current behavior | External requirement |
| --- | --- | --- |
| Local simulator | Explicit local demo only. Actual Blocks/HPOS journeys exercised with fictional orders. | Production uses approved merchants. |
| ZarinPal | Official V4 adapter prepared and tested with 38 fictional HTTP checks. Sandbox and live modes default off. | Eligible merchant account, current terms, approved credential setup, authorized sandbox and live verification. [Contract](API-CONTRACTS/ZARINPAL-V4.md) |
| SEP/Saman | Unavailable by default and under forced use. | Merchant-issued specification, agreed IRR/verification/refund rules, credentials and authorized acceptance. |
| Behpardakht Mellat | Unavailable by default and under forced use. | Eligible terminal agreement, current authenticated API contract and authorized acceptance. |
| Digital assets | Hard-disabled. | Separate legal, financial, security and provider review under the original decision. |

The four desktop axe scans in English and Persian reported zero violations and zero incomplete findings. The tested checkout email reference is present in both languages. Native accessibility and real-device usage, independent security and production performance checks remain open. Local restore checks cover the database. Staging must verify actual external delivery, encrypted file and key recovery, approved rollback, reconciliation and staff procedures. [Accessibility](ACCESSIBILITY-REPORT.md) · [Security](SECURITY-REPORT.md) · [Performance](PERFORMANCE-REPORT.md) · [External dependency owners](EXTERNAL-DEPENDENCIES.md)


## Target installation preflight

Run these commands from the intended WordPress installation after installing the five candidate ZIPs and activating WooCommerce.

```sh
wp atlas migrate
wp atlas preflight
wp atlas run_due
wp atlas preflight --production
```

The first preflight verifies the installed stack, Core and Inventory schema readiness, matching package versions, a published Checkout Blocks page and whole IRR configuration. The production check also verifies the site's configured HTTPS URL, environment, demo and debug settings, and a recorded worker run without errors. Add `--service-payments` when the launch scope requires ZarinPal. The check confirms local configuration and gateway availability. Merchant acceptance requires an authorized provider exercise and reconciliation evidence. Provision the external scheduler and monitor the age of the worker heartbeat, due holds and failed outbox events separately. [Operations runbook](OPERATIONAL-RUNBOOK.md) · [ZarinPal configuration](API-CONTRACTS/ZARINPAL-V4.md)

## Production launch decision

| Gate | Candidate evidence | Acceptance needed |
| --- | --- | --- |
| G-01 Commercial scope and G-02 provider | Request-only paths and provider availability checks | Sponsor scope, terms, staffing and authorized merchant and supplier acceptance |
| G-03 Money and inventory | Local holds, dated quotes, checkout, verification, exception and refund regressions | Reconciliation and concurrency exercise with approved target workload and contracted processors |
| G-04 Security and privacy | Local role, ownership, data and file checks | Independent review, deployed scanner, secrets and retention acceptance |
| G-05 Accessibility and localization | Mobile and desktop EN/FA automation and page snapshots | Native Persian review, screen readers and real devices |
| G-06 Packaging and compatibility | Reproducible ZIPs and disposable WordPress install | Compatibility and populated upgrade on the intended hosting and WooCommerce stack |
| G-07 Reliability and support | Scheduler recovery regressions and isolated logical database restore | Real cron and alerting, file and key recovery, witnessed rollback, support rota |
| G-08 Content and G-09 user acceptance | Local fixture journeys and approved-content workflows | Approved bilingual production content, staffed acceptance scenarios and release signoff |

Production launch remains pending while these acceptances are outstanding. [Launch requirements and accountable signers](../docs/DOC-48_LAUNCH-UAT-HANDOVER.md)
