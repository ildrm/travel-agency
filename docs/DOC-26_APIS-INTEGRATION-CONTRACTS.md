# DOC-26 — API and integration contracts

**Purpose:** Define proposed internal API and asynchronous integration behavior, not claim existing supplier APIs.  
**Prepared from perspectives:** API, WordPress, WooCommerce, security and integration engineers.  
**Accountable reviewer:** Integration lead; security and domain owners approve.  
**Dependencies:** [identity](DOC-23_IDENTITY-RBAC-TENANCY.md), [data](DOC-22_DATA-MODEL-ERD-DICTIONARY.md), [booking](DOC-24_BOOKING-INVENTORY-REGISTRATION.md).  
**Approval gate:** Review schemas and auth against selected WP/Woo versions, provider documentation and contract tests.  
**Status:** DRAFT specification; review pending.

## 1. General conventions

Everything under `/agency/v1` below is a **proposed internal contract**, not an existing WordPress, WooCommerce or supplier endpoint. Use TLS, JSON UTF-8, UTC ISO 8601 timestamps and named IANA time zone fields where local scheduling matters. APIs version incompatible shapes explicitly and document deprecation, pagination, rate limits and operational owner. Stable error envelope: `{ "code": "registration_closed", "message": "Registration is closed.", "request_id": "...", "details": {} }`; messages localize and never contain secrets or another tenant's data. Use 400/422 for invalid input, 401 unauthenticated, 403/404 for inaccessible objects by policy, 409 for state/idempotency/capacity conflict, 429 for limits, and 503 for temporarily unavailable dependencies. Actual mapping must be tested against WP REST conventions.

Authenticated browser calls use WP session and applicable CSRF nonce plus capability and object/organization authorization; nonce alone is neither identity nor authorization. Service callbacks use provider-specific verified authentication when documented; no guessed header/signature. Request IDs and idempotency keys are opaque with bounded size and expiry appropriate to the business record; same key and same canonical request returns prior result, different request returns conflict. Never put credentials or PII in query strings or logs.

## 2. Proposed public and authenticated endpoints

| ID / method / route | Request and authorization | Response / effects |
|---|---|---|
| API-CAT-001 `GET /agency/v1/catalog` | Public filters: locale, offering type, destination, page; apply publication policy. | Public localized summaries, price with explicit currency and availability freshness. No confidential costs or partner contact. |
| API-BOOK-001 `POST /agency/v1/holds` | Signed-in user; idempotency key; offer ID/version, optional tier ID, quantity and participant eligibility references. Server resolves the occurrence, departure or cohort through the offer and determines time, org and capacity. | `201` hold ID, expiry/deadline and quote snapshot; transactional allocation; 409 closed/sold-out/changed quote. |
| API-BOOK-002 `GET /agency/v1/bookings/{id}` | Signed-in customer with object relationship or explicitly scoped authorized operator. | Filtered booking states, order reference, masked traveler fields and customer-safe terms. |
| API-PART-001 `POST /agency/v1/organizations/{id}/proposals` | Active membership and proposal-create capability for the named organization. Body: type, localized content, requested terms and draft revision. | Scoped draft ID/version; never auto-publish or expose net cost publicly. |
| API-PAY-001 `POST /agency/v1/payments/{adapter}/callback` | Provider-specific public callback with verified authentic origin/signature/inquiry as actual contract requires; browser/session nonce is not sufficient. | Acknowledge only per provider contract; enqueue/perform safe verification, deduplicate, persist evidence and trigger reconciliation. |

The following domain routes are also proposed internal interfaces. Their names, fields, status codes and dependencies are subject to review; none imply a real supplier API. All create operations validate inputs, enforce object ownership where relevant and apply scoped idempotency. Public RFQ submissions may be anonymous with abuse controls; subsequent access requires a verified identity and ownership.

| ID / proposed route | Core input and result | Phase and authorization |
|---|---|---|
| API-TOUR-001 `POST /agency/v1/tours/{id}/versions` | Structured itinerary revision; returns version ID or validation/conflict. | MVP; active owning-org contributor or agency editor; no self-approval by default. |
| API-ACA-001 `POST /agency/v1/cohorts/{id}/enrollments` | Learner reference and accepted hold; validates eligibility, cohort and unique linkage; returns enrollment or conflict. | MVP; signed-in purchaser/authorized operator, not necessarily partner member. Capacity is allocated only through the shared hold transaction. |
| API-STAY-001 `POST /agency/v1/stay-requests` | Property, local check-in/out, occupancy and contact; returns request ID, never confirmed inventory. | MVP public RFQ; rate-limit and abuse-check submissions; protect subsequent access. |
| API-TICKET-001 `POST /agency/v1/ticket-requests` | Request details, passenger count and route; returns inquiry ID, no issuance. | MVP public inquiry with abuse controls; provider shopping/issuance P3 only. |
| API-RENT-001 `POST /agency/v1/rental-requests` | Dates, location, vehicle class and contact; returns request ID, no inventory promise. | MVP public RFQ; rate-limit and abuse-check submissions; protect subsequent access. |
| API-XFER-001 `POST /agency/v1/transfer-requests` | Pickup/drop-off, local date and time with IANA zone normalized to UTC, passenger and luggage counts, vehicle class, contact and idempotency key; returns unconfirmed request ID, never a booking, dispatch, payment or tracking promise. Invalid input returns 400/422; same key with conflicting request returns 409. | MVP public inquiry with rate limits and abuse controls; later access requires verified owner or authorized scoped organization. |
| API-MERCH-001 `POST /agency/v1/rfqs` | Requirements, supplier recipients and deadline; returns scoped draft. | P2 procurement; authorized agency staff and recipient-scoped partner access. |
| API-SUP-001 `POST /agency/v1/support-cases` | Bounded category, text and optional authorized object reference; returns case ID. | MVP; authenticated account; no access to another customer's object. |
| API-FIN-001 `POST /agency/v1/refunds/{id}/approve` | Expected revision, reason and idempotency key; returns approved case, not proof of provider refund. | MVP; separate finance approval capability and object policy. |
| API-CRYPTO-001 availability contract | Always returns unavailable; no address, payment instructions or activation function. | Investigation only, OFF; no actual provider endpoint. |

Additional admin endpoints for refund approval, capacity adjustment, membership and exports require distinct capabilities, object-level policy, audit reason and idempotent mutation. Server-side validation is shared with UI, workers and classic checkout where classic support is declared; a hidden field or locked button is not enforcement. Route names and methods may change only through an approved contract/version update.

## 3. Example hold request and response

**Fictional**, `POST /agency/v1/holds` with authenticated session, `Idempotency-Key: 4c4f...`:

```json
{"offer_id":"off_demo_27","offer_version":3,"quantity":2,"participant_refs":["trav_demo_1","trav_demo_2"]}
```

```json
{"hold_id":"hold_demo_5","accepted_at":"2027-06-15T14:27:00Z","expires_at":"2027-06-15T14:30:00Z","payment_deadline":"2027-06-15T14:30:00Z","quote":{"amount":2500000,"currency":"IRR","unit":"rial","offer_version":3}}
```

Example assumes `Asia/Tehran`, a close at 18:00 local and its offset at that fictional date; actual zone conversions must use maintained time-zone data. Server must not accept client price, expiry, provider-paid time or organization claims as authoritative. An existing idempotency key reused with altered quantity returns `409 idempotency_conflict`, never allocates twice. Payment after hold release routes to exception, not automatic reallocation.

## 4. Event and adapter contracts

Use a transactional outbox record written with local domain transition. Illustrative internal envelope:

```json
{"event_id":"evt_demo_1","type":"booking.paid_pending_confirmation","schema_version":1,"aggregate_id":"booking_demo_1","occurred_at":"2027-06-15T14:29:30Z","correlation_id":"req_demo_1","payload":{"booking_id":"booking_demo_1"}}
```

No names, addresses, credentials or raw gateway data are needed in this envelope. Consumer claims event safely, persists a deduplication marker, and retries bounded transient failures with backoff; poison messages become visible operator exceptions. Contract must define at-least-once delivery, ordering per aggregate when needed, replay procedure and idempotent side effects. Monitoring alerts on oldest pending and failed jobs; event not sent before transaction commit. Delivery result is not a booking confirmation.

Supplier adapter interface is conceptual: `quote`, `hold`, `confirm`, `cancel`, `status` capabilities are individually marked supported only after a real contract. Normalize results to confirmed/rejected/unknown with provider reference, expiry and evidence; preserve external identifiers and monetary units. Handle timeout-after-success through status inquiry and reconciliation where supported. If provider has no inquiry or idempotency, manual exception workflow is mandatory; do not invent endpoint, schema or capability. MVP supplier RFQs use manual workflows; flight/rail/bus are P3 subject to licensed access. Notification provider adapter must similarly verify consent and actual delivery capability.

## 5. Contract tests and acceptance

**TC-API-001:** reject malformed input, unauthorized object, stale version and duplicate conflicting request. **TC-API-002:** simultaneous holds cannot oversell; retry returns same ID. **TC-API-003:** public catalog and partner proposal responses never disclose another organization's data. **TC-API-004:** replay provider callback and event delivery; payment and ledger effect occur once. **TC-API-005:** timeout then late provider success creates unknown/reconciled state; no invented confirmed ticket. **TC-API-006:** verify rate limit, localized validation, schema compatibility and privacy-safe correlation IDs. Test both Blocks/Store API extension and classic integration where supported.

**OPEN DECISION:** exact nonce/session conventions, idempotency retention, pagination, rate thresholds, real provider operations and credential rotation. Version, transport and adapter design are subject to actual platform and provider verification. [WordPress REST handbook](https://developer.wordpress.org/rest-api/) and [nonce guidance](https://developer.wordpress.org/apis/security/nonces/) retrieved 2026-11-17.

## Review and evidence

All decisions are proposed, not approved; no implementation, operational measurement or supplier integration is implied. Accountable reviewers must record sign-off and evidence before release. Provider interfaces, compatibility versions and legal conclusions remain VERIFY BEFORE BUILD.

**Self-assessment, 0–5:** completeness 4 (document scope covered); specificity 4 (concrete contracts); consistency 4 (shared baseline); user value 4 (actionable decisions); implementation feasibility 4 (platform boundaries); security/privacy 4 (controls proposed); compliance 3 (qualified legal/provider verification outstanding); accessibility/localization 4 (cross-referenced standards); testability 4 (observable checks); traceability 4 (canonical IDs). A score of 3 is a release blocker until evidence and qualified review exist; see [risk register](DOC-45_RISKS-DECISIONS-CONTROLS.md). These are author estimates, not independent audit findings.
