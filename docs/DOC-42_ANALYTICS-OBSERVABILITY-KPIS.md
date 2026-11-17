# DOC-42 — Analytics, observability and KPIs

**Purpose:** Define privacy-minimized product events, authoritative financial measures and actionable operational alerts.  
**Prepared from perspectives:** Analytics, product, finance, privacy, booking and SRE.  
**Accountable reviewer:** Analytics lead with finance and privacy review.  
**Dependencies:** [commerce](DOC-25_WOOCOMMERCE-COMMERCE-ENGINE.md), [API](DOC-26_APIS-INTEGRATION-CONTRACTS.md), [operations](DOC-28_INFRA-DEVOPS-RELIABILITY.md), [legal](DOC-43_LEGAL-REGULATORY-REVIEW.md).  
**Approval gate:** Approve event collection purposes, consent, access and reconciliation fixtures before enabling analytics.  
**Status:** DRAFT specification; review pending.

## 1. Measurement boundaries

Separate product analytics, security audit and service telemetry: each has a purpose, access policy, retention and lawful basis reviewed by privacy counsel. Tracking must not be necessary to complete checkout. A customer can browse without optional analytics where consent policy requires; transactional records and security logs follow their own reviewed purposes. Do not place names, traveler identifiers, passport/health data, email, phone, addresses, raw search terms, payment credentials or provider payloads in analytics events. Use opaque, scoped random IDs with limited retention and aggregation; do not claim that pseudonymous data is anonymous.

**Finance authority:** browser events never establish revenue, payment success, refund or supplier payout. Woo order snapshots, independently verified payment records, refund cases and append-only partner ledger are authoritative for their respective facts. Finance reconciles them against documented provider statements. Gross order value, verified paid value, refunded value, net cash and recognized revenue are distinct; finance/counsel approve accounting treatment and definitions before external reporting.

## 2. Event dictionary

The following events are **proposed**, not observed. Each event uses `event_id`, `name`, `schema_version`, `occurred_at_utc`, `source` (browser/server), opaque correlation ID and deployment environment. No arbitrary free-text properties. Consent checks apply to optional client analytics; server operational events have separate access and retention rules. Replays use the same event ID; collectors deduplicate.

| Event | Source and trigger | Allowed properties | Intended metric / restriction |
|---|---|---|---|
| `catalog_viewed` | Browser after approved analytics choice | locale, offering type, page category | Discovery count; no user-entered search term. |
| `offer_viewed` | Browser after view is visible | opaque offer reference, type, locale | Interest by category; subject to consent. |
| `registration_unavailable` | Server returns closed/sold-out | offer type, reason code, locale | Identify friction; not individual eligibility reasons. |
| `hold_created` | Server commits in-window allocation | opaque hold/offer refs, quantity, expiry, type | Accepted hold count; not a paid booking. |
| `hold_released` | Server commits expiry or cancellation | hold ref, reason code, duration bucket | Capacity recovery and failure monitoring. |
| `checkout_started` | Server links valid hold/order | opaque order/hold refs, mode, currency | Funnel entry; no payment details. |
| `payment_verified` | Server verifies provider result and records it | opaque payment/order refs, integer amount, currency, provider adapter code | Finance-reconciled operational count, not automatic revenue recognition. Restricted access. |
| `payment_exception` | Server detects unknown or paid with released hold | payment ref, reason code, age bucket | Operator queue and alert; no raw callback. |
| `booking_confirmed` | Authorized agency confirmation commits | booking ref, service type, locale | Confirmations; distinct from payment and ticket issuance. |
| `refund_succeeded` | Verified supported refund result recorded | refund/payment refs, amount and currency | Finance reconciliation; prevent double-counting. |
| `partner_proposal_submitted` | Scoped member submits draft | org pseudonym, proposal type | Partner workflow volume; no contract price. |
| `notification_failed` | Worker exhausts or cannot deliver | channel, template ID, error class, attempt count | Delivery monitoring; no message text or recipient address. |

Timestamps are server-derived for financial and state events. Display reporting timezone explicitly and record event definition version. Never attach query strings, document contents or direct personal identifiers. Customer-identifying joins, if justified for support, belong in restricted operational systems, not broad analytics dashboards.

## 3. KPI definitions and examples

| KPI | Numerator / denominator and caveat | Owner |
|---|---|---|
| Registration conversion | Distinct eligible holds created / eligible offer views in the same defined cohort and interval; subject to consent and incomplete client coverage. | Product |
| Hold completion | Timely verified payments associated with accepted holds / holds created; report paid exceptions separately. | Operations/finance |
| Confirmed bookings | Count of `confirmed` domain bookings by confirmation date and service type; excludes paid pending and exception. | Operations |
| Verified paid value | Sum of unique verified payment amounts in IRR, per payment date; exclude failed/unknown and do not subtract refunds silently. | Finance |
| Net cash movement | Verified settled inflows minus verified refunds/chargebacks and fees, only as provider statements support; not automatically recognized revenue. | Finance |
| Booking exception age | Oldest and count of unresolved paid, unconfirmed or refund exceptions by age bucket. | Operations |
| Availability and job lag | Successful eligible requests / eligible requests; oldest due background job and scheduler heartbeat. Define exclusions before any SLO is adopted. | SRE |

Fictional reconciliation: a verified 1,250,000 IRR payment linked to an order with an expired/released hold counts in verified paid value and exceptions, but not confirmed booking count. A later verified full refund is reported separately and changes net cash; it does not erase the original payment. Display 125,000 toman only with explicit unit labeling. Do not add IRR and other currencies without an approved conversion policy.

## 4. Alerts and response

| Signal | Proposed alert and response | Owner |
|---|---|---|
| Inventory invariant | Any detected allocation above capacity or negative stock: pause affected sales immediately, preserve evidence and reconcile. | Booking on-call |
| Payment mismatch | Amount/unit mismatch, verified paid with released hold or provider unknown past agreed reconciliation interval: open exception; finance reviews, never silently mark refunded. | Finance/operations |
| Scheduler failure | Missing heartbeat or increasing oldest due hold/queue age: investigate worker; local registration cutoff still enforced by server time. | SRE |
| Security | Cross-org denial spike, unauthorized export attempts or privileged membership changes: assess and investigate under incident procedure. | Security |
| Backup | Backup failure or overdue restore rehearsal: alert owner; do not state recovery objectives are met without a restore. | SRE |
| Delivery | High notification failure rate or aged unsent mandatory message: use approved manual support fallback. | Support |

Thresholds and routing are proposals to calibrate against measured workloads; alert contact details and coverage must be established before launch. Related retry and worker mechanics are in [Action Scheduler usage](https://actionscheduler.org/usage/), retrieved 2026-11-17; scheduled actions alone do not prove a reliable server scheduler.

## 5. QA and governance

**TC-ANALYTICS-001:** verify consent states, event schema, forbidden fields and locale. **TC-ANALYTICS-002:** replay verified payment and event delivery; deduplication produces one financial fact. **TC-ANALYTICS-003:** paid/released-hold fixture appears in payment and exception reports, never confirmed bookings. **TC-ANALYTICS-004:** compare report totals to Woo CRUD, verified payment, refund ledger and sandbox statement fixture. **TC-ANALYTICS-005:** trigger worker, inventory and backup failure alerts and verify owner routing without leaking PII. Review schema versions and retention before change; disable optional analytics on consent withdrawal where required.

**OPEN DECISION:** analytics processor, consent implementation, event retention, organization attribution, reporting currency and approved alert thresholds. No audience, conversion, uptime, revenue or provider result has been measured.

## Review and evidence

This is a proposed specification, not evidence of execution, legal advice, independent audit, measured performance, or human approval. Provider details and applicable legal duties remain VERIFY BEFORE BUILD.

**Self-assessment, 0–5:** completeness 4 (document scope covered); specificity 4 (actionable examples); consistency 4 (shared baseline); user value 4 (decision support); implementation feasibility 4 (platform integration considered); security/privacy 4 (controls proposed); compliance 3 (jurisdiction and qualified review outstanding); accessibility/localization 4 (explicit tests or controls); testability 4 (observable acceptance); traceability 4 (shared IDs). The compliance score of 3 is a release blocker pending qualified review and evidence; see [risk register](DOC-45_RISKS-DECISIONS-CONTROLS.md). Scores are author estimates, not independent audit findings.
