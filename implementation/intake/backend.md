# Backend implementation intake — atlas-core

Status: INTAKE ONLY, no backend implementation or test execution yet. Ownership: `wp-content/plugins/atlas-core/`, `tests/core*.php`, and this intake file. The parent agent owns shared interfaces and coordination. This report does not represent the entire project documentation inventory. The 11 source files listed below were read in full in this task; SHA-256 is computed from their actual bytes. The prior documentation-generation session supplied experience writing several of them, but source was re-read for this implementation intake. All specification decisions remain draft; no provider/merchant/engineering sign-off is implied.

## Evidence and exact source hashes

| Source | SHA-256 | Purpose |
|---|---|---|
| `docs/DOC-07_BRD-SRS.md` | `3d38cc51ee338042dec138fac3ff18c79ecb85dec5764c2fe0babaa5643e69ec` | Functional and nonfunctional baseline |
| `docs/DOC-19_SYSTEM-ARCHITECTURE-ADRS.md` | `26b4beb8f96cef6819d128007a95c2eb2b76465bfcc68cf163c413eef1f9e757` | System architecture, boundaries, ADRs |
| `docs/DOC-20_WORDPRESS-THEME-SPEC.md` | `3239016548c0b74d26e5687101ada56d679ddc129aee5b7379371f997b9094a3` | Reusable theme/dependency behavior |
| `docs/DOC-21_CUSTOM-PLUGIN-ARCHITECTURE.md` | `6f71defda918ed787c214ee3bc438eb8819a174ed2eac53d5972b543e59d7732` | Companion modules, migrations, outbox |
| `docs/DOC-22_DATA-MODEL-ERD-DICTIONARY.md` | `9f08d60ee4aaf81ed1072eb8e68c0aa3d74f7866e7fbf457e49775a3d4ad5df6` | Canonical entities, fields and persistence |
| `docs/DOC-23_IDENTITY-RBAC-TENANCY.md` | `e672911301aab07f575a3154176eee2963e36eae8f485d13f56ae8ad44e500b8` | Identity, roles, object/organization authorization |
| `docs/DOC-24_BOOKING-INVENTORY-REGISTRATION.md` | `7b338a89df79246e6d757d37152b5993287df1fe338a2adafeeca0c90255c1d8` | Admission, inventory, payment race, states |
| `docs/DOC-25_WOOCOMMERCE-COMMERCE-ENGINE.md` | `75c0689c1d1662fe5f3adc00c12b03e322801b5ec98e292125f34597f8a8297e` | Woo commerce, cart and payment lifecycle |
| `docs/DOC-26_APIS-INTEGRATION-CONTRACTS.md` | `dc80d8bcbc1a02a4f17f7a4322565e1017121c1006b2a422aa0d8d455a78be36` | Internal API and provider adapter contracts |
| `docs/DOC-27_SECURITY-PRIVACY-THREAT-MODEL.md` | `89520e6aa5743f2b9da63db5742a88d69d8b973ac1666d1c42eef10a873a5d1c` | Threat model and privacy |
| `docs/DOC-28_INFRA-DEVOPS-RELIABILITY.md` | `279887e0896ce7360aa6053a32b87eb6441b7a9ed033f04401dc4d8e2e7a54b3` | Scheduler, deployment, recovery |

## 1. Architecture and requirements extracted

One WordPress site represents one agency. A reusable block theme controls presentation; durable records, rules, access checks, audit and workflows live in the independently versioned companion. WordPress user accounts and multiple scoped organization memberships coexist; WooCommerce owns products/order snapshots and its standard lifecycle through CRUD. No direct writes to WordPress or Woo vendor files, no assumptions that Woo orders use posts/postmeta; Blocks checkout is primary and classic is supported only when explicitly declared and tested (DOC-19–21, DOC-25).

| Requirement | Backend interpretation | Minimum proof / phase |
|---|---|---|
| FR-ID-001, FR-PART-001, NFR-SEC-001 | Signed-in purchase; roles/capabilities plus active membership, organization context and object authorization; customer owns only permitted records. Agency approval separate from staff proposal. Deny cross-org on API, export, search, files and async paths; WordPress nonce is CSRF control, not authorization. | TC-AUTH-001–006; MVP. |
| FR-CAT-001, FR-TOUR-001, FR-EVT-001, FR-ACA-001 | Published offer projections omit confidential costs; fixed-capacity occurrence, departure and cohort each have own schedule/window/eligibility and pricing snapshot; tour itinerary version has structured stops and legs; course is reusable and cohort independently scheduled. | TC-REG-001/002 and domain tests; MVP, manual agency confirmation. |
| FR-COM-001, FR-PAY-001, FR-FIN-001 | Sign-in checkout; block physical+service and heterogeneous service inventory carts; same-departure group allowed. WC totals are commerce snapshots, not booking/payment/ticket evidence. Independent payment, refund and partner ledger; no automatic payment splitting. | TC-COM-001–006, TC-PAY-001–004; eligible gateway/credentials external. |
| FR-STAY-001, FR-AIR-001, FR-GROUND-001, FR-XFER-001, FR-RENT-001 | Public RFQs are unconfirmed and never charge, dispatch, promise stock or issue tickets. Later inventory, supplier integrations and issuance require agreements, legal and technical verification. | Anonymous submission abuse controls and owner/organization-protected reads; later capabilities gated. |
| FR-MERCH-001 | WC physical goods; maker-proposed product records require agency approval; procurement P2. | WC HPOS/Blocks and shipping tests. |
| FR-SUP-001, FR-DATA-001 | Owner-scoped support and export, audit of sensitive transitions; no private notes or cross-user records in customer responses. | Negative access and redaction tests; retention pending qualified review. |
| FR-I18N-001, NFR-LOC-001 | Localized messages and fields; UTC instants plus IANA zone; English/Persian and bidi-safe identifier/amount presentation. | EN/FA equivalent workflows and timezone tests. |
| FR-CRYPTO-001 | Disabled by default; no API generates payment addresses or activation path absent separately documented approval. | Negative requests and fail-closed settings. |
| NFR-CONC-001, NFR-OBS-001, NFR-REL-001 | Transactional InnoDB local allocation; idempotency and lock ordering, outbox and reliable scheduler, no provider network call while lock held; audit/redacted telemetry and restore/reconciliation. | Real DB race/replay, scheduler outage and restore tests. |

FR-CMS-001 and FR-THEME-001 belong to presentation/content delivery while backend must enforce permissions and persist independent records. FR-ART-001 is a visual dependency, never booking precondition. No real supplier, provider, legal approval, performance, release or conformance result is evidenced in these documents.

## 2. Data and persistence contract

Common durable domain rows: opaque ID, UTC created/updated, version; organization-scoped records also store org reference. WP user IDs are identity references, not authorization by themselves. Proposed custom transactional tables include organization, membership, offer/occurrence/departure/cohort, inventory, hold, booking, payment, refund, ledger, audit, outbox and idempotency; exact names/migration format wait on parent’s shared interface. Use Woo CRUD for orders/product relationships; store references and immutable domain price/terms snapshots, no direct post-table assumptions. Public content can use CPT/taxonomy projection, but inventory cannot rely on postmeta counters.

- Organization: legal/display name, status, agency approval and minimal contact; membership `(org,user)`, scoped role, status and effective dates; unique active relationship.
- Offer: owner, type, visibility/version, currency and integer-IRR amount, eligibility/policy reference. Occurrence/departure/cohort: parent identity, approved/published state, capacity, UTC opening/closing, named IANA zone, date/schedule, price and eligibility. A course or event definition is distinct from a dated cohort/occurrence.
- Inventory: resource identity, interval/slot, capacity, allocated and version. Hold: inventory and owner, quantity, acceptance instant, deadline, state and request-idempotency reference. Booking: hold/offer/version, purchaser, independent status and Woo order/item references. Traveler/attendee/learner are distinct from payer and carry minimal data.
- Payment: provider and attempt/reference, order, amount and currency, outcome, trusted local verification completion timestamp, optional trusted provider paid time/evidence; refunds and ledger are independent. No raw PAN/CVV/credentials.
- Audit: actor/service, action/outcome, object, org, UTC time, reason and correlation; outbox: event ID/type/version, aggregate ID, minimal payload, attempts/claim status. Unique scoped `(actor, operation, idempotency key)` plus canonical request hash; identical retry returns prior result, changed payload conflicts.

`allocated <= capacity` enforced under deterministic local locks, using integer quantities; overlapping future room nights, villa units and vehicle intervals require explicit locking—not simple unique indices. Date-only accommodation boundaries use local property dates; instants stored UTC with source zone. Amounts are integer IRR; display toman = 10 IRR, explicit and correct provider unit conversion, no binary-float arithmetic. Version history keeps customer price, schedule and cancellation terms stable after later edits. Deactivation or uninstall does not silently delete records; migrations need preflight, lock, version, idempotence, reversible/restore plan and affected writes fail closed while schema is incompatible.

## 3. Canonical booking timing and recovery

`WF-REG-001`: server accepts ordinary hold atomically if `open <= server_now < close`, eligible, published and capacity available; group quantity all-or-nothing. Deadline `min(accepted_at + 10 minutes, registration_close_at)`. At deadline new hold-related customer writes and payment initiation are disallowed even if allocation is still awaiting the expiry worker. Never extend via browser time or refreshing checkout. Eligibility and offer revision validated server-side.

Independent verification outside database lock records trustworthy local completion instant. At/before deadline a verified result may consume a still-allocated hold without provider paid_at; later verification may consume only if provider paid_at is independently trustworthy and at/before deadline. Consume and expiry worker lock same hold and inventory rows in consistent order. Release winning means allocation stays released, `expired -> exception` on later verified payment, payment stays verified, manual review/refund is queued; no automatic reacquisition. A late result with unknown/untrusted/late provider time likewise enters exception. Confirmation is separate agency action; verified payment, Woo order status, supplier issue, booking, refund and payout are separate facts. No external provider call inside DB transaction.

Canonical booking: `held -> pending_payment -> paid_pending_confirmation -> confirmed -> fulfilled`; `held/pending_payment -> expired`, `pending_payment -> exception`, `expired -> exception` if later verified payment, and authorized cancellation. Payment: `created -> pending -> verified/failed/unknown`, unknown may resolve; verified cannot regress. Refund `requested -> approved -> submitted -> succeeded/failed`, retry requires same intent and review. Admin exception after close requires privileged actor, reason, audit and capacity; waitlist alone never overrides cutoff. Record outbox event within same local state transaction, then deliver after commit with idempotency/retries, monitored failure and reconciliation.

## 4. Proposed API boundaries

All `/agency/v1` routes are internal design contracts and do not assert existing provider APIs. Standard structured localized errors, bounded idempotency keys and payload sizes, authorization, 409 stale/idempotency conflict, 400/422 validation, 429 abuse limit and no PII in URLs/logs. Confirm implementation with parent before exposing endpoint shapes. Never allow client-supplied amount, expiry, provider timestamp, actor or org ID to become authority.

| API | Contract |
|---|---|
| API-CAT-001 `GET /agency/v1/catalog` | Published summaries only, locale/type/destination/page, no net costs. |
| API-BOOK-001 `POST /agency/v1/holds` | Signed-in user, offer ID/version, optional tier, quantity, participant eligibility references and scoped idempotency; server resolves resource, validates eligibility, returns hold/deadline and quote or conflict. |
| API-BOOK-002 `GET /agency/v1/bookings/{id}` | Owner or explicitly scoped authorized operator; filtered snapshot only. |
| API-PART-001 `POST /agency/v1/organizations/{id}/proposals` | Active matching membership/capability; draft, no automatic publication. |
| API-TOUR-001 `POST /agency/v1/tours/{id}/versions` | Scoped contributor; structured revision and validation; agency approval distinct. |
| API-ACA-001 `POST /agency/v1/cohorts/{id}/enrollments` | Purchaser/learner relationship; references already accepted hold and never double allocates. |
| API-STAY-001, API-TICKET-001, API-RENT-001, API-XFER-001 | Public request-only routes with rate/abuse controls; later access requires verified ownership/scoped organization. Transfer input is pickup/dropoff, local datetime/IANA zone normalized UTC, passenger/luggage counts, vehicle class and contact. |
| API-MERCH-001, API-SUP-001, API-FIN-001 | Scoped supplier RFQ, owner support case, authorized finance approval; approval is not evidence of provider refund. |
| API-PAY-001 | Provider callback independently authenticated/verified according to real contract; no guessed signature, no browser nonce as provider authorization; duplicate-safe and no assumed provider endpoint. |
| API-CRYPTO-001 | Unavailable only; no generated address/instructions or activation. |

Outbox is at-least-once with stable event ID; consumers deduplicate and reconcile unknown provider outcome before unsafe retry. Event payload avoids personal identity and sensitive raw provider data. Notification/customer access must be filtered by owner and organization. Status code and contract choices not yet verified against installed WordPress/Woo APIs.

## 5. Security, test constraints and unresolved choices

Validate/sanitize input, parameterize SQL, escape on output, enforce capabilities and object scopes on every write/read, including jobs and downloads. REST CSRF/nonces never substitute for user auth, resource ownership or replay protection. Limit login/RFQ abuse; secrets outside source/logs; private file access gated; do not leak raw provider callback or sensitive fields to analytics. Independent provider verification performed by trusted server-side adapter only: a client cannot submit a paid flag or assert local verification timestamp. Payment creation requires valid hold and no incompatible cart. Feature flags cannot grant permission.

Required meaningful tests before claiming coverage: exact open/close and TTL boundaries; one-seat concurrent race under separate database connections; safe idempotency retry/conflicting payload; expiry-vs-verification race in both lock orders and `expired -> exception`; late payment with missing/untrusted provider time; duplicate callback/no double ledger event; unknown provider response/no double charge; IRR/toman conversion and Woo price mismatch; API/export/media cross-org denial and revoked membership; Woo unavailable fail-closed, theme switch data persistence, HPOS/Blocks plus classic only if declared, migration interruption/retry, scheduler recovery and no sensitive log payload. PHP syntax and isolated unit checks alone cannot prove WordPress/Woo integration or DB concurrency.

**Unresolved / conflict requiring shared interface:** (1) DOC-25 leaves exact Woo product/offer mapping open; define canonical metadata keys and line-item snapshot schema before frontend/gateway work. (2) DOC-24 requires trusted local verification completion timestamp, but actual provider adapter contracts and credential model are absent; never trust public route/client claims and keep live payments disabled. (3) DOC-22 requires unique active membership but portable schema/partial-index design for MySQL/MariaDB not specified; design and verify without blocking legitimate inactive history. (4) Provider contracts, HPOS and Blocks versions, refund capabilities, schedule execution and reliable external scheduler are unverified. (5) The implementation prompt asks for full eventual framework and all gateway packages while docs phase supplier features and only one verified eligible domestic gateway for MVP: implement feasible modular boundaries and maintain phased safety gates, do not claim vendor integration or enable methods without evidence. (6) Exact WordPress, Woo and PHP compatibility matrix remains open; PHP 8.5.8/Node 26/WP-CLI 2.12/MariaDB 13 were reported by coordinating agent, not independently executed in this intake; a running local DB was not detected by that agent. No live credentials or external approvals are supplied.

**Next:** Wait for parent-approved shared schema, route, hook and ownership interfaces; then implement coherent backend slices, with limited, low-priority single-worker validation per user constraint. Do not run installers, heavyweight builds, visible browser or tests before coordination.
