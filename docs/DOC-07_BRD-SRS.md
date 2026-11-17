# DOC-07 — Business and system requirements

**Status:** DRAFT specification; review pending. **Purpose:** define the contractual baseline for a reusable theme and its companion-powered agency site. **Prepared from perspectives:** product, operations, engineering, security, accessibility, finance. **Accountable reviewer:** product owner. **Dependencies:** [scope](DOC-05_SCOPE-MVP-ROADMAP.md), [decisions](DOC-06_TERMINOLOGY-RULES-DECISIONS.md), architecture and domain specifications. **Approval gate:** product owner and technical lead sign off scope and traceability; counsel and provider verification are separately required.

## 1. Business outcomes and scope

**CONFIRMED request:** English/Persian travel marketplace on a WordPress theme compatible with WooCommerce; no operational capabilities are asserted to exist. **RECOMMENDATION:** one agency operator per site; partner organizations are scoped records. The MVP sells fixed-capacity tours, events and courses, plus physical merchandise, using verified domestic payment and manual confirmation. RFQs for stays and transport are requests, not reservations. See [phase plan](DOC-05_SCOPE-MVP-ROADMAP.md). Theme presents content; plugins retain bookings, permissions, commerce extensions and records when the theme is switched. Exact versions, licensing and distribution remain OPEN DECISION (ADR-001/019).

## 2. Functional requirement register

Each criterion describes an observable outcome, not evidence of an implementation. P = proposed phase; all MVP requirements are release-blocking. See [test strategy](DOC-41_QA-TEST-STRATEGY.md) for automation and [traceability](DOC-46_TRACEABILITY-MATRIX.md).

| ID | P | Requirement and minimum acceptance criterion |
|---|---|---|
| FR-ID-001 | MVP | Users must sign in before purchase, recover their account, manage profile and see only their own bookings; staff access is membership-scoped. Unauthorized direct URLs deny access (TC-AUTH-001). |
| FR-CAT-001 | MVP | Published offerings have type, destination, language, price/unit or RFQ label and status; search filters return only authorized/public records and never promise unavailable supplier inventory. |
| FR-TOUR-001 | MVP | Approved leader can submit structured tour and dated departure with itinerary stops/legs, capacity, pricing, windows, approval history and public/customer vs confidential views. |
| FR-EVT-001 | MVP | Each occurrence stores open and close instants, zone, capacity, eligibility and schedule. Server admits an ordinary hold only at open ≤ now < close and available capacity (TC-REG-001/002). |
| FR-ACA-001 | MVP | A reusable course has independently scheduled cohorts, capacity and registration windows; authorized staff records attendance. Assessment and certificates are P2. |
| FR-STAY-001 | MVP RFQ; P2 inventory | Property partners submit property proposals; shoppers send unconfirmed requests. P2 adds room/unit availability and rates with contract and reconciliation gates. |
| FR-AIR-001 | MVP RFQ; P3 integration | Flight interest creates an unconfirmed request; P3 reservation and issuance require contracted licensed provider, verification and explicit exception handling. |
| FR-GROUND-001 | MVP RFQ; P3 integration | Rail/bus interest is request-only; later, approved adapters distinguish quote, hold, issue, cancel and refund. |
| FR-XFER-001 | MVP RFQ; P2 | Transfer request identifies pickup, drop-off, time and passengers without representing dispatch as confirmed; later assignment needs operational agreement. |
| FR-RENT-001 | MVP RFQ; P2 | Rental request captures category and interval without claiming availability. Later inventory must enforce asset and interval conflicts. |
| FR-MERCH-001 | MVP sales; P2 procurement | Purchaser can select Woo physical product variation, quantity and shipping; manufacturer proposal is scoped. Custom manufacturing/RFQ workflow and production are P2. |
| FR-PART-001 | MVP proposals; P2 | Agency approves organization and proposal; membership and ownership determine access. Contract negotiation and full RFQ procurement are P2. |
| FR-COM-001 | MVP | Woo order snapshots total and line item data; sign-in is required. Mixed physical/service and heterogeneous service inventory carts are blocked; same-departure group quantity allowed. |
| FR-PAY-001 | MVP | One eligible configured gateway must independently verify amount, currency and provider result before marking payment verified. Duplicate callback has one effect (TC-PAY-001). |
| FR-FIN-001 | MVP | Agency records refund requests, manual outcomes and partner obligations separately; Woo order status is not proof of refund or payout. No automatic split. |
| FR-SUP-001 | MVP | Customer can request help on an authorized booking; agency records status and notification outcome, without exposing internal notes to customer. |
| FR-CMS-001 | MVP | Authorized editors draft, review and publish localized content, preserve publication ownership, and use only substantiated trust claims. |
| FR-I18N-001 | MVP | English LTR and Persian RTL support translated content, prices, dates and errors, bidi isolation and equivalent core tasks; fallback is explicit. |
| FR-THEME-001 | MVP | Theme provides editable block templates, patterns and Woo presentation; missing dependencies produce clear setup notices, and durable records survive theme changes. |
| FR-ART-001 | MVP lightweight 2D; future characters/3D | Provide lightweight original 2D art with documented rights, accessible alternatives and resource budgets when approved; optional mascots and 3D are future. Core purchase never depends on art. No finished assets are claimed. |
| FR-CRYPTO-001 | Future investigation | All digital-asset methods are OFF and unavailable absent documented per-jurisdiction legal and provider approval; no activation is promised. |
| FR-DATA-001 | MVP | Authorized export and audit cover bookings and organization access; retention/deletion policy and recovery procedure require documented ownership and approval. |

## 3. Nonfunctional requirement register

| ID | Requirement and verification |
|---|---|
| NFR-SEC-001 | Enforce capability plus object/organization checks server-side, secure uploads and secrets, and never store raw card data; negative access tests cover URL, API and export. |
| NFR-PRIV-001 | Collect only purpose-justified traveler data; restrict, redact and retain/delete per approved schedule. Legal retention and deletion conflicts require review. |
| NFR-A11Y-001 | Target WCAG 2.2 AA; test keyboard, screen reader, contrast, focus, reflow and errors across critical paths; no conformance claim before audit. |
| NFR-PERF-001 | Proposed budgets: LCP ≤2.5 s, INP ≤200 ms, CLS ≤0.1 at the 75th percentile; establish representative field data before claiming achievement. |
| NFR-REL-001 | Recovery, backups, scheduled workers and monitored retry queues have tested procedures; RPO/RTO remain proposed pending hosting review. |
| NFR-LOC-001 | EN/FA interface, search normalization and mixed-script direction pass equivalent-task and native-review checks; time uses named IANA zone and UTC instant. |
| NFR-MAINT-001 | Declare and test an evidence-backed WordPress/Woo/PHP compatibility matrix and upgrades, rollback and deactivation; exact supported versions OPEN. |
| NFR-OBS-001 | Audit security-sensitive state changes with actor, scoped object, reason and time; monitoring redacts secrets/PII and alerts on failed jobs and reconciliation exceptions. |
| NFR-CONC-001 | A local atomic hold operation never allocates more than capacity under parallel requests; expiry versus payment races serialize on the hold. |

## 4. Cross-cutting rules and example

Registration admission is at hold creation: `open <= server_now < close`; MVP hold TTL is the lesser of ten minutes and time to close. The payment deadline equals the hold deadline. At the deadline no new public admission or payment initiation is permitted. An allocation remains allocated until the serialized consume/release operation resolves. Independent local verification completed at or before the deadline may consume an unreleased hold without provider `paid_at`; verification after the deadline requires both trustworthy provider `paid_at` at or before the deadline and an unreleased allocation. Released inventory is never automatically reclaimed. If verified payment arrives after release, booking moves from expired to exception while payment remains verified; uncertain or late paid cases enter the manual exception/refund queue. Waitlist acceptance after close requires audited administrator exception and capacity enforcement. Order, payment, booking, supplier, refund and payout are separate states. No DB transaction spans an external provider call; retry via idempotency, outbox and reconciliation. Integer IRR is baseline; 10 IRR = 1 displayed toman, with explicit gateway conversion and testing.

**Fictional check:** a 30-seat event opens at 09:00 and closes at 18:00 in its stored zone. The last seat is held at 17:59; hold deadline is 18:00. Payment verification completed by 18:00 can consume the unreleased hold without provider paid-at. Verification completed after 18:00 requires trustworthy provider paid-at no later than 18:00 and an unreleased allocation; release wins over later verification and leads to exception. Competing users cannot both acquire that seat. See [booking rules](DOC-24_BOOKING-INVENTORY-REGISTRATION.md).

## 5. Open decisions and acceptance

Product owner must approve SKU, phase and sign-in restriction; finance must approve merchant model, currency and refund ownership; legal/provider reviewers must validate eligibility, contracts and local obligations; engineering must validate platform matrix and migrations. Acceptance requires every MVP FR mapped to a test, entity or operational workflow in DOC-46, and no high-severity unowned exception.

**Self-assessment (0–5):** completeness 4 (baseline coverage); specificity 4 (criteria); consistency 4 (shared rules); user value 4 (MVP boundary); WP feasibility 4 (plugin separation); security/privacy 4 (control baseline); compliance 2 (unverified jurisdiction/merchant contracts, blocker); accessibility/localization 4 (test targets); testability 4 (observable outcomes); traceability 4 (stable IDs). Review and evidence are still pending.


## 6. Requirement coverage and responsibility index

Each row points to the owning domain specification and a representative screen and verification outcome; it is an aid to the full many-to-many matrix in [DOC-46](DOC-46_TRACEABILITY-MATRIX.md), not a substitute for test execution evidence. Screens marked P2/P3 are not MVP functionality. Domain and QA owners must add detailed executable cases and actual results before release.

| Requirement | Primary specification and representative UI | Minimum verification target |
|---|---|---|
| FR-ID-001 | [Identity](DOC-23_IDENTITY-RBAC-TENANCY.md), UI-ID-001, UI-ACC-001 | Sign in/recovery and cross-user denial |
| FR-CAT-001 | [Information architecture](DOC-10_INFORMATION-ARCHITECTURE.md), UI-CAT-001 | Published results, locale and request-only status |
| FR-TOUR-001 | [Tours](DOC-29_TOUR-LEADER-ITINERARIES.md), UI-TOUR-001 / UI-TOUR-002 | Structured builder and departure detail with private costs withheld |
| FR-EVT-001 | [Events](DOC-30_EVENTS-MANAGEMENT.md), UI-EVT-001 / UI-REG-001 | Exact cutoff and final-seat race, TC-REG-001/002 |
| FR-ACA-001 | [Academy](DOC-31_ACADEMY-COURSES-COHORTS.md), UI-ACA-001 | One course with independent open/closed cohorts |
| FR-STAY-001 | [Stays](DOC-32_ACCOMMODATION-PORTAL.md), UI-STAY-001 | MVP RFQ is unconfirmed; P2 overlapping inventory |
| FR-AIR-001 | [Ticketing](DOC-33_FLIGHTS-RAIL-BUS-TICKETING.md), UI-TICKET-001 | MVP request only; P3 failed issuance enters review |
| FR-GROUND-001 | [Ticketing](DOC-33_FLIGHTS-RAIL-BUS-TICKETING.md), UI-TICKET-001 | Request-only in MVP; P3 supplier contract gating |
| FR-XFER-001 | [Transfers](DOC-34_TRANSFERS-AND-RENTALS.md), UI-XFER-001 | Request unconfirmed; P2 dispatch requires authorization |
| FR-RENT-001 | [Rentals](DOC-34_TRANSFERS-AND-RENTALS.md), UI-RENT-001 | Request unconfirmed; P2 conflicting interval rejected |
| FR-MERCH-001 | [Merchandise](DOC-35_SOUVENIRS-MANUFACTURING.md), UI-MERCH-001 | Valid Woo variant/quantity and shipping; RFQ P2 |
| FR-PART-001 | [Partners](DOC-36_PARTNER-PORTALS-CONTRACTS.md), UI-PART-001 / UI-PART-002 | Membership-scoped proposal; cross-org denial TC-AUTH-001 |
| FR-COM-001 | [Commerce](DOC-25_WOOCOMMERCE-COMMERCE-ENGINE.md), UI-COM-001 | Sign-in and mixed-cart rejection; Woo total snapshot |
| FR-PAY-001 | [Domestic payments](DOC-37_PAYMENTS-DOMESTIC-GATEWAYS.md), UI-PAY-001 | Verify callback, amount/unit; duplicate TC-PAY-001 |
| FR-FIN-001 | [Finance](DOC-39_FINANCE-PRICING-SETTLEMENTS.md), UI-OPS-001 | Refund/payment/payout separately reconciled |
| FR-SUP-001 | [Support](DOC-40_SUPPORT-NOTIFICATIONS-CRM.md), UI-SUP-001 | Owner-only case, private staff notes and retryable send |
| FR-CMS-001 | [Content](DOC-18_CONTENT-SEO-GROWTH.md), UI-ADM-001 | Only approved translations publish; revisions retained |
| FR-I18N-001 | [Localization](DOC-17_I18N-EN-FA-RTL.md), all localized screens | EN/FA equivalent tasks, bidi ID, units and time zone |
| FR-THEME-001 | [Theme](DOC-20_WORDPRESS-THEME-SPEC.md), all public screens | Plugin-absent notice and theme switch without data loss |
| FR-ART-001 | [Art direction](DOC-14_2D-ILLUSTRATION-ART-DIRECTION.md), UI-HOME-001 | Optional artwork, text alternative and asset rights |
| FR-CRYPTO-001 | [Crypto compliance](DOC-38_CRYPTO-PAYMENT-COMPLIANCE.md), UI-COM-001 | No exposed digital-asset payment by default |
| FR-DATA-001 | [Data model](DOC-22_DATA-MODEL-ERD-DICTIONARY.md), UI-OPS-001 | Scoped export and audit with retention controls |

NFR-SEC-001 and NFR-PRIV-001 are detailed in [security](DOC-27_SECURITY-PRIVACY-THREAT-MODEL.md); NFR-A11Y-001 and NFR-LOC-001 in [accessibility](DOC-16_ACCESSIBILITY-INCLUSIVE-DESIGN.md) and [localization](DOC-17_I18N-EN-FA-RTL.md); NFR-PERF-001, NFR-REL-001, NFR-MAINT-001 and NFR-OBS-001 in [reliability](DOC-28_INFRA-DEVOPS-RELIABILITY.md); NFR-CONC-001 in [booking](DOC-24_BOOKING-INVENTORY-REGISTRATION.md). Full pass/fail records belong to QA, not these proposed criteria.
