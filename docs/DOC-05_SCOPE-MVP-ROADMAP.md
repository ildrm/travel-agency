# DOC-05 — Scope, MVP and release roadmap

Purpose: turn the full platform brief into releasable increments. Prepared from perspectives: product, travel operations, architecture, finance, delivery. Accountable reviewer: Product Owner. Dependencies: [vision](DOC-01_VISION-BUSINESS-MODEL.md), [evidence](DOC-02_RESEARCH-BENCHMARKS.md), [decisions](DOC-06_TERMINOLOGY-RULES-DECISIONS.md). Approval gate: sponsor approves release boundary and resources; relevant external evidence gates pass. Status: DRAFT recommended roadmap.

## 1. Release policy

The package specifies the entire intended ecosystem. Implementation proceeds in gated increments, not one simultaneous launch. A later-module specification is not an available product feature. Feature flags must disable both UI and server writes where the module has not passed release acceptance. Mock inventories never appear as real sellable availability in production.

R0 = foundation and nonproduction prototype. MVP = first operational release. P2 = deeper local fulfillment/partner operations. P3 = licensed distribution and advanced integration. Future = optional research with no promised date. Each release has an independently testable customer/support/finance path; a UI-only booking form is not a completed feature.

## 2. Explicit capability boundary

| Family / requirement | MVP | P2 | P3 / future |
|---|---|---|---|
| FR-THEME-001 | Block theme, Site Editor, styles, templates, install/update/switch, no-plugin graceful behavior | More patterns and integrations | Distribution-specific review if selected |
| FR-ID-001 / FR-PART-001 | Signed-in customers, traveler separation, approved orgs, scoped members, draft proposals | Advanced contracts/RFQ/partner analytics | Enterprise identity if justified |
| FR-CAT-001 / FR-CMS-001 | Bilingual published catalog, honest availability and price basis, filters/editorial | Cross-vertical comparison/advanced search | Dedicated search only at measured trigger |
| FR-TOUR-001 | Multiple definitions/departures, structured stops/days/legs, manual capacity, agency approval | Supplier-linked costs/allotments | Licensed dynamic bundles |
| FR-EVT-001 | Occurrences, tiers, registration windows, waitlist, basic check-in, meal needs | Sessions/exhibitors/seating complexity | Enterprise conference services |
| FR-ACA-001 | Reusable course, independent cohorts/windows, prerequisites/basic attendance | Assignments/quizzes/certificates | Advanced LMS integration |
| FR-MERCH-001 | Ready-made Woo products, variants/stock/shipping/returns | RFQ, proofing, production, consignment | Wholesale integration |
| FR-STAY-001 | Informational property proposals and RFQ only | Locally controlled room/unit-night inventory/rate plans | Contracted PMS/channel manager |
| FR-XFER-001 / FR-RENT-001 | RFQ only, no prepaid vehicle booking | Dispatch/fleet/deposit/condition operations | Advanced contracted tracking |
| FR-AIR-001 / FR-GROUND-001 | Request-based inquiry; no live seats, payment or issuance | Supplier procurement/preproduction spike | Licensed shopping/hold/issuance/refund |
| FR-COM-001 | Woo Blocks checkout primary; one physical cart OR homogeneous service cart | Cross-service carts only after compensation proof | Bundles/multiple merchants only by new ADR |
| FR-PAY-001 / FR-FIN-001 | One verified eligible domestic gateway; IRR; manual reviewed refunds/settlement | More verified gateways, approved finance automation | FX/settlement methods if legal/provider evidence exists |
| FR-SUP-001 / FR-DATA-001 | Booking-linked support, notices, audit, exports/minimization | CRM integrations/advanced reporting | Enterprise integration |
| FR-I18N-001 | EN/FA, RTL, bidi, localized notices and date/unit policy | More markets if staffed | Additional locales after review |
| FR-ART-001 | Lightweight original 2D direction and functional iconography | Select character assets after validation | Optional optimized 3D visual layer |
| FR-CRYPTO-001 | OFF, no checkout activation | OFF absent independent approval | Investigation only, no launch promise |

## 3. MVP acceptance slice

1. Admin installs theme with Woo inactive: standard content renders, optional dependencies show actionable notices, no fatal errors or hidden data creation. On activating companions, admin configures languages/currency, supplier policies, gateway and reliable jobs in staging.
2. Approved leader proposes a tour and departure; agency approves a version with capacity, local dates/zones, inclusive/exclusive meals, registration window and policy. A separate occurrence and cohort exercise the same hold engine.
3. Customer in FA and EN browses, signs in, submits required travelers, creates an atomic hold and pays through verified eligible hosted payment. Duplicate callback produces one monetary effect. Expired hold never steals a sold seat.
4. Agency confirms manually after payment and fulfillment check. Customer receives confirmation with correct policy/itinerary snapshot. Uncertain payment or supplier outcome remains visible in exception queue.
5. Physical souvenir checkout tests shipping/tax/stock separately. Cross-cart action offers clear checkout/reset choice and preserves current cart until explicit selection.
6. Support requests cancellation, finance authorizes supported refund/manual alternative, ledger reconciles actual receipt/refund and partner statement. Restore drill and theme-switch test preserve records.

Every step must pass [DOC-41](DOC-41_QA-TEST-STRATEGY.md) and [DOC-48](DOC-48_LAUNCH-UAT-HANDOVER.md). Production payment acceptance is blocked until provider/merchant/legal evidence is approved; documentation proceeds with pending gates.

## 4. Priority method and sequencing

Score customer value, operational risk reduction and dependency readiness 1–5; divide a proposed weighted benefit by complexity (1–5). Scores guide discussion, not financial forecasts. Security/inventory/payment correctness are mandatory regardless of feature score.

| Work | Value/risk/readiness | Complexity | Sequence rationale |
|---|---|---|---|
| Product, entity/state and compatibility decisions | 5/5/4 | 2 | Removes downstream contradictions |
| Identity/org authorization and audit | 5/5/4 | 3 | Required before partner data |
| Hold/payment exception vertical slice | 5/5/2 | 5 | Highest early feasibility risk; gateway evidence needed |
| Theme/RTL/design tokens/editor | 5/3/4 | 3 | Can proceed alongside domain core |
| Tour/event/cohort forms and manual ops | 5/4/4 | 4 | Reuses proven inventory slice |
| Ready-made merchandise | 3/3/4 | 2 | Woo baseline; separate cart |
| Local stay/rental booking | 4/5/2 | 5 | Later interval/room correctness and operations |
| Licensed ticketing | 4/5/1 | 5 | Contracts and issuance semantics first |
| 3D/complex mascots | 2/1/3 | 4 | Optional; must meet performance budget |

## 5. Out-of-scope and change triggers

MVP excludes live passenger issuance, channel synchronization, automatic supplier disbursement, crypto, multi-agency SaaS, multi-merchant checkout, international tax automation, real-time vehicle tracking and accreditation claims. It does not exclude their documented future requirements. Guest checkout is an OPEN DECISION alternative; baseline respects required login. No native mobile app is specified.

A new merchant/jurisdiction, payment method, cart combination, supplier hold system or partner self-publishing power requires risk/architecture review and new test coverage. No team may turn an RFQ into a payable booking merely by changing its CTA. Cross-module feature flags have backend guards, audit and deployment-owner documentation.

## 6. Exit gates and unknowns

R0 exit: decisions/evidence assigned, prototype slice demonstrates race-safe local hold and Woo link, compatibility environment fixed. MVP exit: all acceptance slice tests and critical NFRs pass; staff/legal/provider/restore evidence exists. P2 exit: each local inventory model and finance exception passes; P3 exit: licensed integration contract/sandbox, ambiguity/reconciliation, disruptions/refunds and support escalation proven.

Budget, team availability, agency geography, license/distribution, tax treatment, actual partners/gateway and hosting are OPEN DECISION. Calendar in DOC-44 is an illustrative dependency-based range, not a commitment. Quality self-assessment: completeness 4; specificity 4; consistency 4; value 4; WP feasibility 4; security/privacy 4; compliance 3; accessibility/localization 4; testability 4; traceability 4. Below-4 blocker: merchant/gateway/jurisdiction evidence (RISK-003/004/005).
