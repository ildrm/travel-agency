# DOC-06 — Canonical terminology, rules and decisions

Purpose: prevent contradictory names, states and approvals across the package. Prepared from perspectives: requirements engineering, domain operations, architecture, finance, privacy. Accountable reviewer: Product Owner with Architecture Lead. Dependencies: [brief](_sources/ORIGINAL-PROMPT.md), [evidence](DOC-02_RESEARCH-BENCHMARKS.md). Approval gate: accountable owners accept ADRs; changes undergo traceability review. Status: DRAFT baseline; every ADR below is PROPOSED, not business-approved.

## 1. Authority and notation

CONFIRMED business intent means requested by the brief. CONFIRMED technical guidance needs a direct applicable official source. ASSUMPTION permits a draft to proceed; it does not authorize implementation or launch. RECOMMENDATION proposes a default; DEPENDENCY identifies prerequisite evidence/work; OPEN DECISION needs owner action; REGULATORY REVIEW REQUIRED cannot be resolved by model confidence. VERIFY BEFORE BUILD marks external interfaces, versions or policies not verified in this session.

Stable ID families: FR feature, NFR quality attribute, ENT entity, WF workflow, UI screen, API proposed interface, TC acceptance test, RISK risk, ADR decision. Prefix families are defined in [DOC-07](DOC-07_BRD-SRS.md); entities in [DOC-22](DOC-22_DATA-MODEL-ERD-DICTIONARY.md); internal APIs in [DOC-26](DOC-26_APIS-INTEGRATION-CONTRACTS.md). IDs are immutable once accepted; supersede/deprecate rather than reuse. One ID can have many references but one authoritative meaning.

## 2. Canonical glossary

| Term | Definition / prohibited conflation |
|---|---|
| Operator / agency | One merchant-operating business per installation; not every partner |
| Organization | Partner business record; membership is many-to-many; not WordPress site tenant |
| Customer / purchaser | Account initiating commerce; may differ from payer and traveler |
| Traveler / attendee / learner / renter | Service participant with only necessary service-specific data |
| Catalog item / offer | Reusable content vs purchasable terms/version with dates/price |
| Tour / departure | Reusable structured trip vs dated execution with capacity |
| Event / occurrence | Event definition vs dated occurrence with independent window |
| Course / cohort | Reusable syllabus vs scheduled delivery and enrollment inventory |
| Room type / unit | Pooled category capacity vs assignable physical room/whole villa |
| Inventory / hold | Available resource capacity vs temporary exclusive local allocation |
| Booking | Domain service commitment record linked to order item; not payment proof |
| Woo order | Commerce totals/lifecycle; not a supplier ticket or universal booking state |
| Payment attempt | One attempt with provider reference, independent verification and idempotency |
| Supplier reservation / ticket | Contracted external hold vs confirmed issued entitlement |
| Refund / payout | Return to payer vs supplier disbursement; independent ledgers/states |
| Quote / RFQ | Expiring priced proposal vs request for one; neither alone guarantees inventory |
| Published / confirmed | Editorial visibility vs operational fulfillment commitment |
| IRR / display toman | Integer rial accounting unit vs explicit display conversion of 10 IRR per toman |
| Jalali / UTC / IANA zone | Local display calendar vs instant vs local scheduling rules; not interchangeable |

## 3. Non-negotiable business invariants

BR-001 public admission occurs atomically only if `registration_open_at <= server_now < registration_close_at`, eligibility passes and capacity is available. Server/database authoritative time is UTC; display includes named IANA zone. Exact close is excluded. Browser visibility is never enforcement.

BR-002 MVP hold expiry is `min(accepted_at + 10 minutes, registration_close_at)`; payment deadline equals expiry. The deadline forbids new payment initiation or admission on that hold. Its local allocation remains unreleased until a serialized consume/release transition; worker lag never permits a fresh hold to use elapsed admission rights. Independent verification completed locally at/before deadline may consume the still-allocated hold without needing provider paid_at. Verification after deadline requires independently verified, trustworthy provider paid_at at/before deadline AND still-unreleased allocation. Once the expiry transition releases allocation, never reacquire it automatically. Late verified payment on an expired booking moves the booking to exception while payment remains verified; handle by reviewed refund/alternative, not new admission. Unknown provider time after deadline enters exception/refund. Consume and expiry lock the same hold row, so either winning order is safe and auditable. Post-close confirmation may complete an already valid paid booking.

BR-003 waitlist invitation does not bypass closing. Admin exception is explicit, actor/reason/version audited, and never overrides capacity. Schedule/capacity/window edits after booking require impact analysis; old purchased terms retained. Registration close never hides existing confirmations/materials unlawfully or by accident.

BR-004 payment verified, supplier confirmed, booking confirmed, fulfilled, refunded and paid out are separate facts. Redirect is never proof. Retry has scoped idempotency; verified payments cannot regress on late failure messages. Refund has independent state.

BR-005 use integer IRR baseline snapshots; no binary floating-point money. Provider input/output units are configured and tested. Toman display is labeled and converted once. Refund total cannot exceed captured less previous refunds. FX optional and blocked absent legal/operational policy.

BR-006 Woo totals own tax/shipping/coupon commerce snapshot. Domain quote owns resource terms/expiry. Price change requires new customer acceptance, never silent overwrite. MVP permits physical-only or same-occurrence/departure/cohort service cart, with group quantity; heterogeneous resource or physical/service mix rejected server-side before payment.

BR-007 agency approves material partner/listing changes. Capability AND object organization/ownership checks apply to UI, API, uploads, exports, cache, search and workers. Organization ID from client is untrusted. No raw card storage or secret/PII logs. Durable records remain in plugins when themes change.

BR-008 local inventory transactions are short and never hold locks across network calls. Woo updates/gateway/supplier operations are distributed workflow steps with outbox, reconciliation and compensation. Supplier availability is only guaranteed to the extent supported by contract/provider-side holds.

BR-009 crypto is OFF on frontend/backend with no date promised. Flight/rail/bus/stays/rental RFQ is visibly unconfirmed, not payable live inventory. No fictional licenses, endorsements, research participants or regulatory approval.

## 4. Canonical state axes

Booking `held -> pending_payment -> paid_pending_confirmation -> confirmed -> fulfilled`; alternate `expired`, `canceled`, `exception`. Independently verified payment arriving after allocation release explicitly causes `expired -> exception` without reacquiring stock. Payment attempt `created -> pending -> verified | failed | unknown`, with unknown recoverable to verified/failed by inquiry. A verified attempt never reverts; refund separate. Refund `requested -> approved -> submitted -> succeeded | failed`; failure permits reviewed retry with same intent, not duplicate money. Supplier state is adapter-specific normalized `not_requested, requested, held, confirmed, failed, unknown, canceled` with raw states retained privately. Editorial states do not reuse booking states. Woo standard order statuses remain Woo-owned; mapping is documented in DOC-25.

## 5. Top 20 decision ledger

Confidence below is confidence in recommendation, not external readiness. Every decision status is PROPOSED. Accountable owner/reviewer must record approval date and evidence in an amended ledger.

| ID | Recommendation / options and rationale | Owner / reviewers | Confidence / revisit trigger |
|---|---|---|---|
| ADR-001 | Reusable theme + separate companions, first single-agency configuration; agency-only build remains alternative | Sponsor / PO,AR | Medium; distribution/buyer changes |
| ADR-002 | Custom block theme; classic/hybrid only if verified integration gap warrants | AR / UX,EN | High principle; target-version spike |
| ADR-003 | One modular companion core with domain modules; separate gateway/integration packages; avoid 15 tiny deployment units initially | AR / EN,RE | Medium; independent release/security needs |
| ADR-004 | Woo CRUD/order ownership and HPOS support; no direct order post-table assumptions | Woo architect / QA | High, official guidance; version upgrades |
| ADR-005 | Dedicated transactional inventory/booking/org tables, editorial CPTs, Woo-owned commerce | AR / database,QA | High; load/model spike |
| ADR-006 | Atomic admission + capped 10-minute hold; no released-allocation reclaim; local on-time verification or trusted paid_at for late verification; late paid release becomes exception | PO / ops,finance,booking | Medium; provider timing evidence, customer testing |
| ADR-007 | One agency merchant; restrict mixed carts; manual approved partner payout | Sponsor / finance,legal,AR | Medium; different contract/merchant model |
| ADR-008 | Integer IRR baseline, explicit 10:1 display toman conversion and adapter units | Finance / payments,LC | High arithmetic; provider/tax/currency changes |
| ADR-009 | Blocks primary, select one verified eligible domestic gateway; classic only if explicitly supported/tested | Finance / IN,QA,legal | Low vendor readiness; contract/sandbox |
| ADR-010 | EN/FA day one, locale content routing, UTC instants + IANA zone, optional Jalali display | PO / LC,AR | High design; translation-extension spike |
| ADR-011 | One operator installation, org membership and resource capabilities; no SaaS Multisite baseline | AR / SE,PO | High; multi-agency requirement |
| ADR-012 | RFQ until supplier contracts; local stay/rental P2, licensed issuance P3 | Operations / legal,IN,finance | High risk control; signed supplier evidence |
| ADR-013 | Outbox/idempotent workers with reliable scheduler and deadline checks independent of cleanup | AR / RE,booking | High; scheduler performance evidence |
| ADR-014 | Minimal purpose-bound PII; field retention and privacy exports; do not collect identity docs generically | Privacy owner / legal,ops | Medium; jurisdiction/provider data requirements |
| ADR-015 | Capability+object auth, hosted payment, secure secret store, audit, MFA for staff | SE / AR,QA | High; threat model/pen test |
| ADR-016 | Managed WP stack with staging, server scheduling, monitored backups and restore; targets proposed | RE / SE,finance | Medium; hosting region/budget/workload |
| ADR-017 | Lightweight 2D first; 5 character briefs and optional 3D remain nonessential | PO / CR,a11y,RE | Medium; brand/user/performance validation |
| ADR-018 | Hybrid Woo+custom domain; extension procurement by reproducible compatibility evidence | AR / procurement,QA | Medium; trials/cost/license |
| ADR-019 | Respect repository GPLv2 license; distribution/third-party asset terms checked separately | Sponsor / legal,release | Medium; channel/assets selected |
| ADR-020 | Release only on evidence bundle, critical tests, staff/restore/payment reconciliation and legal gates | Release manager / PO,QA,SE,finance | High; residual-risk acceptance |

## 6. Assumptions and decision change control

| Assumption | Owner | Consequence / evidence needed |
|---|---|---|
| A-001 single agency initially, reusable theme desirable | Sponsor | Distribution, licensing/support contract |
| A-002 agency acts as merchant for MVP | Finance | Actual operating entity, gateway and supplier agreements |
| A-003 modest pilot traffic and human manual confirmation capacity | Operations/RE | Workload/staff measurements before SLO commitment |
| A-004 no existing content/brand/code/supplier integrations | PO | Only README/LICENSE supplied in workspace; import audit if assets arrive |
| A-005 initial payable items use IRR | Finance | Provider/tax eligibility verified before payment activation |
| A-006 exact supported platform versions unset | AR | Date-stamped compatibility matrix before development release |
| A-007 no native/editor/legal validation yet | PO/SE | Human review records before relevant release |

A change cites old/new rule, evidence, approving owner, migration/backfill impact, UI/API changes and tests. Preserve past order/booking snapshots; never reinterpret historical amounts or deadlines. Cross-document conflicts default to this ledger and the authoritative technical doc, with explicit reconciliation rather than silent preference.

Quality self-assessment: completeness 4; specificity 4; consistency 4; value 4; WP feasibility 4; security/privacy 4; compliance 3; accessibility/localization 4; testability 4; traceability 4. Below-4 blocker: external legal/provider/merchant evidence (RISK-003/004/005); all ADR approvals pending.
