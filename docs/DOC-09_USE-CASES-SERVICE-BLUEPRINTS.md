# DOC-09 — Use cases and service blueprints

**Status:** DRAFT specification; review pending. **Purpose:** specify cross-actor handoffs, exceptions and support ownership. **Prepared from perspectives:** service design, operations, finance, engineering, QA. **Accountable reviewer:** operations lead. **Dependencies:** [requirements](DOC-07_BRD-SRS.md), [booking](DOC-24_BOOKING-INVENTORY-REGISTRATION.md), [support](DOC-40_SUPPORT-NOTIFICATIONS-CRM.md). **Approval gate:** operations and finance validate staffing, queues and response targets.

## 1. WF-REG-001 — Admission to fulfillment

**Actors:** visitor/purchaser, booking plugin, Woo, gateway, agency operator. **Preconditions:** occurrence published, purchaser signed in, legal terms and price presented, capacity configured, current eligible gateway selected. **Trigger:** customer submits eligible registration.

```mermaid
sequenceDiagram
 actor Customer
 participant Site as Site and booking plugin
 participant Woo as Woo order
 participant Pay as Gateway (verified adapter)
 participant Ops as Agency operations
 Customer->>Site: Submit registration
 Site->>Site: Lock capacity; check window and eligibility; create hold
 Site->>Woo: Snapshot order/price and deadline
 Customer->>Pay: Pay through hosted flow
 Pay-->>Site: Callback or later status inquiry
 Site->>Pay: Verify independently outside DB transaction
 Site->>Site: Serialize hold/payment decision; enqueue outcome
 Site-->>Customer: Pending confirmation or review
 Site-->>Ops: Confirmation or exception queue
 Ops->>Site: Confirm or resolve/refund manually
```

**Control:** ordinary admission occurs only on atomic hold creation inside `open <= now < close`. Deadline = min(hold-created + 10 minutes, close). At the deadline new public admission and payment initiation stop, but an allocation remains until serialized consumption or release. Local independent verification completed by the deadline can consume an unreleased hold without provider paid-at. Verification completed after the deadline requires trustworthy provider paid-at ≤ deadline and an unreleased allocation. If release wins, later verified payment moves the expired booking to exception while payment stays verified; no stock is silently reclaimed. An untrustworthy paid-at after deadline requires manual review. Payment state is separate from order, booking and refund. All state changes persist before outbox-driven notifications; duplicate callbacks and queue retries are idempotent.

| Stage | Customer sees | Frontstage/automation | Backstage owner and evidence |
|---|---|---|---|
| Browse | schedule, price, inclusion, status | localized public catalog | editor approves publication |
| Hold | countdown and terms | transactional capacity allocation | event operator owns capacity |
| Payment | hosted payment and pending indicator | provider verification, amount/unit check | finance owns reconciliation |
| Confirmation | pending until agency confirms | queue and notification | operations checks delivery and confirms |
| Exception | review, support route; no false ticket | idempotent exception record | finance/support determine refund and communicate |
| Fulfillment | itinerary, event details or attendance | access-controlled entitlements | operator records completion |

Failures: last seat taken → sold-out with waitlist if configured; payment timeout → show pending, status inquiry/reconcile; duplicate callback → existing result; released hold later paid → expired booking becomes exception, verified payment stays verified, and review/refund proceeds without automatic reclaim; notification failure → retry and operator alert; canceled occurrence → review impacted bookings and refund policy. Staff exception after close requires reason, actor/time and available capacity; notification must not claim automatic entitlement.

## 2. WF-PART-001 — Partner proposal to publication

Precondition: agency approves organization, staff membership and role. Partner creates a draft listing with owned fields and optionally confidential net cost; submission saves a version. Agency validates claims, safety and pricing and approves, requests edits or rejects with reason. Only approved content becomes publishable. Subsequent material edits return to review; booked departure changes require impact analysis and notification. Access is checked on organization and object, including direct API and exports. A revoked member loses access immediately; audit records actor, proposal, transition and reason. Exception: supplier withdraws proposal after bookings → agency pauses sales and manages affected customers; the proposal is not deleted to hide history.

## 3. WF-RFQ-001 — Request-based stay or transport

A visitor chooses category, city, interval, party size and contact channel. Site saves a request, with explicit consent where required, and returns a reference labeled unconfirmed. Support checks completeness, availability and contracting offline; an authorized agent provides a quote with expiration and explicit terms. MVP stops short of automatic booking or payment for these categories. A non-response or supplier rejection produces an update and closure; stale quoted prices are not reused as live availability. No sensitive identity document should be requested before there is a defined need and retention basis.

## 4. WF-SUP-001 — Complaint and financial resolution

Customer opens case from owned booking; system timestamps and scopes it; staff classify urgency and route to finance or operations. Assigned owner tracks customer-visible messages separately from internal notes and records disposition. A disputed payment requires provider investigation and independent refund state; a simple Woo status change is not a bank settlement. Failure to send communication is visible to staff and retried. Emergency safety escalations use an agency-approved procedure and phone contact; no 24/7 coverage is asserted without staffing.

## 5. Operational acceptance and decisions

**Fictional example:** 30-seat event, 29 allocated to confirmed/active bookings plus one active hold: another buyer gets sold-out. If the hold is released, new public admission can use that capacity only while the registration window remains open; after close, admission requires an explicit audited administrator exception and a fresh capacity check. Acceptance requires replay tests for timeout, callback ordering, final-seat contention, staff revocation and notification retries; operations must assign real queue ownership and service targets before release. SLA, channel coverage and consent/retention rules are OPEN DECISION; no legal claims are made.

**Self-assessment (0–5):** completeness 4 (core service paths); specificity 4 (handoffs and recovery); consistency 4 (canonical lifecycle); user value 4 (truthful status); WP feasibility 4 (local/outbox boundary); security/privacy 4 (scope and minimum data); compliance 2 (local legal review missing, blocker); accessibility/localization 4 (status parity referenced); testability 4 (observable exception outcomes); traceability 4 (workflow/requirement IDs). Operator SLAs remain unapproved.
