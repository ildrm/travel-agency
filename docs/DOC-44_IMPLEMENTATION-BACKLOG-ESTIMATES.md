# DOC-44 — Implementation backlog and estimates

Purpose: give a delivery team actionable work, dependency gates and provisional effort. Prepared from perspectives: delivery manager, WP/Woo engineering, QA, operations, finance. Accountable reviewer: Delivery Lead. Dependencies: [scope](DOC-05_SCOPE-MVP-ROADMAP.md), [architecture](DOC-19_SYSTEM-ARCHITECTURE-ADRS.md), [QA](DOC-41_QA-TEST-STRATEGY.md), [risks](DOC-45_RISKS-DECISIONS-CONTROLS.md). Approval gate: estimate after version/provider spikes and approved MVP; do not treat ranges as a quote. Status: DRAFT planning baseline.

## 1. Estimation basis and constraints

All effort below is RECOMMENDATION: engineering/UX/QA person-weeks including implementation, review and focused tests, not vendor waiting time, legal fees, asset production, hosting or measured team velocity. Assume lean team from DOC-04: 3 engineers, 1 UX/UI, 1 QA plus product and operational owners with fractional specialist access. Overlap is possible but financial/concurrency work requires review and cannot be compressed by counting nominal heads. Unclear interfaces carry wider ranges. No calendar launch date is promised.

One implementation task is not done until code review, applicable acceptance tests, localization, a11y, audit/observability, migration, documentation and operations handover are complete. Every ticket cites FR/NFR, UI/ENT/API/TC IDs and the relevant ADR. A dependency status of “unverified” cannot be changed to “done” by mocking it.

## 2. R0 and MVP work breakdown

| Epic / actionable tasks | Owner | Dependencies | Effort range | Acceptance / evidence |
|---|---|---|---|---|
| EP-01 Product contract: decide package, markets, merchant, roles, release | PO/SP | DOC-01/05/06 | 1–2 | ADR-001/007/019 recorded; named owners |
| EP-02 Compatibility spike: target versions, translation/gateway candidates, checkout/HPOS matrix | AR/IN/QA | EP-01, merchant/vendor access | 2–4 | Minimal real sandbox success + failure, version lock |
| EP-03 Dev platform: repository layout, builds, coding standards, CI, staging, secret injection | RE/EN | EP-02 selected stack | 2–3 | Reproducible build/deploy, no secrets, rollback rehearsal |
| EP-04 Schema core: org/membership, offers/versions, booking/hold, outbox/audit migrations | EN/database | EP-01/02 | 3–5 | Unique constraints, upgrade/retry/restore test |
| EP-05 Identity and RBAC: signup/login/recovery, org switch, invitations/revocation | EN/SE | EP-04 | 3–5 | TC-AUTH-001 cross-org and cross-user negative suite |
| EP-06 Design foundation: tokens, typography, responsive forms, bilingual validation | UX/EN/LC | EP-01, DOC-12/17 | 3–5 | Native review, keyboard and contrast evidence |
| EP-07 Theme package: templates, parts/patterns, Woo presentation, setup/no-plugin state | Theme EN | EP-02/06 | 4–7 | Clean install, switch/update, editor preservation |
| EP-08 Catalog/editorial: localized offers, publish review, search/facets, trust/policy content | EN/content | EP-04/06/07 | 3–5 | No private costs, correct locale and RFQ labels |
| EP-09 Admission engine: atomic quantities, windows, deadline, expiry, idempotency | Booking EN | EP-04 | 4–7 | Boundary/race tests, no oversells under contention |
| EP-10 Woo bridge: product/order-item mapping, authoritative totals, cart guard, order link | Woo EN | EP-02/04/09 | 3–6 | Blocks + declared classic/HPOS matrix and snapshot tests |
| EP-11 Eligible gateway adapter: initiation/verify/inquiry, units, callback, unknown reconciliation | IN/finance | EP-02/10, signed provider docs | 4–8 | Real sandbox evidence; duplicates/timeouts/late paid cases |
| EP-12 Finance: receipt/refund records, ledger journal, manual refund approvals, statements | EN/finance | EP-10/11 | 3–5 | Balanced examples, duplicate prevention, reconciliation |
| EP-13 Tours: structured builder, versions, leader submit/review, departures and traveler view | EN/ops | EP-05/08/09 | 4–7 | Scenario C plus confidential cost isolation |
| EP-14 Events: occurrences/tiers, waitlist, meals, notices, basic check-in | EN/ops | EP-08/09/10 | 3–5 | Scenario A cutoff and group acceptance |
| EP-15 Academy: course/cohort, prerequisite review, enrollment and attendance | EN/academy | EP-08/09/10 | 2–4 | Scenario B independent open/closed cohorts |
| EP-16 Merchandise: Woo variants, stock/shipping/returns, maker proposal | EN/ops | EP-05/07/10 | 2–4 | Shipping/tax/coupon and cart isolation tests |
| EP-17 RFQ intake: stay/transport/rental information, scoped requests, no-payment gate | EN/ops | EP-05/08 | 2–4 | Honest request labels; backend prohibits live purchase |
| EP-18 Customer account/support: traveler records, booking states, owned cases, notices | EN/CS | EP-05/10/12 | 3–5 | Paid pending confirmation clear in EN/FA; no data leak |
| EP-19 Workers/observability: outbox retries, deadline safety, queue/reconciliation alerts | RE/EN | EP-04/09/11 | 2–4 | Outage/replay test, oldest-job alert, manual queue |
| EP-20 Hardening: security, a11y, localization, load, restore, migration regression | QA/SE/LC/RE | all relevant epics | 4–7 | Critical defects closed, no invented passed results |
| EP-21 UAT/training/pilot: staffing, legal/provider evidence, runbook rehearsal, release package | DL/ops | EP-20, DOC-43/48 | 2–4 | Signed evidence bundle, support and rollback owner |

Ranges are deliberately broad; custom plugin scope and gateway evidence dominate uncertainty. Do not add them into a fixed-price promise. Integration or inventory spikes may change build/buy decisions and require re-estimation. Basic original illustration is included in UX; five finished character models and 3D production are not included.

## 3. Proposed milestones and critical path

| Milestone | Indicative elapsed window from staffed kickoff | Required outcome |
|---|---|---|
| M0 scope/evidence | Weeks 1–3 | Business contract and risk owners; vendor requests sent |
| M1 vertical feasibility | Weeks 3–7 | Race-safe hold -> Woo -> real sandbox verify -> exception |
| M2 functional alpha | Weeks 7–15 | Theme/catalog/accounts, one complete event + physical checkout |
| M3 feature beta | Weeks 15–22 | Tours/cohorts/partner proposals/support/finance integrated |
| M4 pilot readiness | Weeks 22–30+ | Hardening, legal/provider approvals, restore and operations |

These windows are planning hypotheses for the assumed team, not validated estimates. Delayed agreements, failed extension compatibility or security findings can extend them substantially. Gate M1 before investing in broad domain UI: a beautiful catalog does not resolve payment/inventory feasibility. Allow a separately budgeted contingency after spikes rather than silently consuming QA time.

## 4. Later-phase backlog

| Epic | Phase / owner | Dependencies and release test |
|---|---|---|
| EP-22 Room/unit-night inventory, rate plans, rooming/allotment | P2 / stays+EN | Local ownership/contracts; Scenario D race and assignment |
| EP-23 Fleet intervals, deposits, condition/damage workflows | P2 / rentals+EN | Eligibility/insurance/deposit method; Scenario E |
| EP-24 Manufacturing RFQ/proofs/milestones/inspection | P2 / procurement+EN | Contracts/IP and payout approvals; Scenario G |
| EP-25 Academy assessments/verifiable certificates | P2 / academy | Completion rules, revocation and privacy review |
| EP-26 Transfer dispatch/provider assignments | P2 / transport | Staff capacity, cancellation/quote terms |
| EP-27 Channel inventory synchronization | P3 / IN | Licensed contract, reconciliation and stale-stock behavior |
| EP-28 Flight/rail/bus shopping/reserve/issue/change/refund | P3 / ticketing+IN | Licenses/provider sandbox, Scenario F ambiguity/compensation |
| EP-29 Cross-service bundles / new merchant models | P3 / AR+finance | New ADR, atomic local rules, external compensation, tax/legal |
| EP-30 Rich 3D/character asset production | Future / CR+RE | Rights, brand, a11y and budget approval; no checkout dependency |
| EP-31 Digital-asset investigation | Future / legal+finance | OFF until asset/network/jurisdiction/provider approval; not a promised release |

Later epic estimates are intentionally deferred until their actual contracts/workloads are known. Produce discovery ranges and acceptance criteria before committing cost. No missing legal/vendor document is treated as a zero-cost task.

## 5. Ticket template and example

Ticket `TASK-REG-004 — serialize expiry and verification` references FR-EVT-001, NFR-CONC-001, ADR-006/013, ENT-HOLD/BOOKING/PAYMENT, API-BOOK-001/API-PAY-001 and TC-REG-003. Preconditions: accepted offer version and transactional table migration. Work: implement row-serialized consume/release, outbox event, unique effect key and exception routing. Done: tests cover both winning orders, late provider success and duplicate callback; audit records no PII; operator can resolve exception without overriding capacity. Estimate only after schema spike. Reviewer: separate booking/QA engineer.

Quality self-assessment: completeness 4; specificity 4; consistency 4; value 4; WP feasibility 4; security/privacy 4; compliance 3; accessibility/localization 4; testability 4; traceability 4. Below-4 blocker: legal/provider readiness and staffed capacity (RISK-003/004/013); schedule is not approved.
