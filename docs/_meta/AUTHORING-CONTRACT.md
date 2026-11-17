# Canonical authoring contract

Specification baseline: 2026-11-17. Codename Atlas Leisure Ecosystem is unapproved. This package specifies a reusable WordPress block theme with separately installable companion plugins, initially configured for one agency. The reusable product/distribution choice is ASSUMPTION pending ADR-001. One WordPress site is one agency/operator, not a SaaS tenant. Partner organizations are scoped records; users may belong to multiple organizations. No WordPress Multisite in baseline.

## Evidence and authority

User-supplied original prompt is the business brief, CONFIRMED as a requested capability, not confirmed operating capability. No live site, supplier contract, brand, credentials, legal opinion or performance measurement exists. All design defaults are RECOMMENDATION, implementation facts require sources. Never describe proposed interfaces as real provider APIs. Decisions remain proposed until accountable human review. APPROVED is not a substitute for documented approval. Dates use client time; examples are fictional.

## Phases

- MVP: English/Persian RTL block theme; Woo physical souvenirs; manually managed fixed-capacity tour departures and event occurrences; fixed-price course cohorts with basic attendance; sign-in checkout; single agency merchant; one eligible verified domestic gateway; manual agency confirmation and refund operations; basic org-scoped leader/property/maker proposal portals. No live supplier issuance, automated payouts, mixed service/physical carts, crypto or paid third-party extension assumed. Manual stays/transport/rentals are RFQ-only, do not consume money or promise live inventory.
- P2: confirmed operational stay and rental inventory, deeper partner dashboards, manufacturing procurement, basic assessment/certificates, negotiated pricing and transfers after required contracts.
- P3: licensed flight/rail/bus integrations, channel manager, advanced bundles, richer procurement and enterprise integrations after evidence gates.
- Future: optional 3D/mascots beyond initial lightweight art, crypto investigation only. Crypto OFF by default at every phase until independently approved; there is no promised activation date.

## Canonical requirement families (one baseline FR per family)

FR-ID-001 identity/account; FR-CAT-001 catalog/search; FR-TOUR-001 itineraries/departures; FR-EVT-001 events/windows; FR-ACA-001 course/cohort; FR-STAY-001 accommodations; FR-AIR-001 flights/issuance; FR-GROUND-001 rail/bus; FR-XFER-001 transfers; FR-RENT-001 rentals; FR-MERCH-001 physical merchandise/manufacturing; FR-PART-001 organizations/contracts/RFQ; FR-COM-001 checkout/orders; FR-PAY-001 verified payments; FR-FIN-001 ledger/refunds/settlement; FR-SUP-001 support/notifications; FR-CMS-001 content/editorial; FR-I18N-001 EN/FA; FR-THEME-001 theme/install; FR-ART-001 optional original visual layer; FR-CRYPTO-001 disabled compliant crypto; FR-DATA-001 portability/audit. IDs remain stable. Specific sub-requirements may be added with numerical suffix 002 onward and defined once in own document. Same IDs may be referenced elsewhere.

NFR-SEC-001 object authorization and secure handling; NFR-PRIV-001 minimization/retention; NFR-A11Y-001 WCAG 2.2 AA; NFR-PERF-001 proposed performance budget; NFR-REL-001 reliability/recovery; NFR-LOC-001 bidirectional correctness; NFR-MAINT-001 compatibility/upgrades; NFR-OBS-001 audit/monitoring; NFR-CONC-001 atomic local inventory. Proposed numerical targets never measured claims.

## Entities

ENT-USER, ENT-ORG, ENT-MEMBER, ENT-CATALOG, ENT-OFFER, ENT-TOUR, ENT-DEPARTURE, ENT-STOP, ENT-LEG, ENT-ITINERARY, ENT-EVENT, ENT-OCCURRENCE, ENT-COURSE, ENT-COHORT, ENT-ENROLLMENT, ENT-PROPERTY, ENT-UNITTYPE, ENT-UNIT, ENT-RATE, ENT-INVENTORY, ENT-HOLD, ENT-BOOKING, ENT-TRAVELER, ENT-ORDER (Woo owned), ENT-PAYMENT, ENT-REFUND, ENT-LEDGER, ENT-CONTRACT, ENT-RFQ, ENT-QUOTE, ENT-VEHICLE, ENT-RENTAL, ENT-MERCH (Woo product), ENT-PRODUCTION, ENT-TICKET, ENT-CERTIFICATE, ENT-SUPPORT, ENT-NOTIFICATION, ENT-AUDIT, ENT-OUTBOX. Definitions owned by DOC-22.

## Lifecycle and money invariants

Availability, editorial approval, booking, supplier, payment, order, refund and payout states are independent. Booking: held -> pending_payment -> paid_pending_confirmation -> confirmed -> fulfilled; exceptions: expired, canceled, exception. Refund has independent requested/approved/submitted/succeeded/failed. Payment attempt: created -> pending -> verified/failed/unknown; verified is monotonic, refund separate. Woo order uses Woo standard lifecycle; it never alone proves booking or ticket issuance.

Ordinary admission is accepted atomically on hold creation only if registration_open_at <= server_now < registration_close_at. Hold TTL = min(10 minutes, close-now) for MVP, payment deadline = hold expiry. New payment initiation/admission is forbidden once deadline elapsed. Local allocation remains until consume or expiry transition wins under the same hold-row lock; elapsed rights never permit fresh admission. Independent verification completed locally <= deadline may consume still-allocated hold without provider paid_at. Verification after deadline requires trustworthy independently verified provider paid_at <=deadline AND still-unreleased allocation. Released allocation is never automatically reacquired; expired booking plus late verified payment explicitly transitions to exception while payment stays verified, for reviewed refund/alternative. If provider time cannot be trusted after deadline, manual exception. Confirmation after close may complete a valid paid accepted booking; no new public admission after close. Waitlist invitations do not override close; post-close acceptance needs explicit admin exception with reason, actor and audit, capacity still enforced. Manual booking request is not confirmed availability.

Woo totals are authoritative commerce snapshots, domain quote carries expiry, unit/currency, amounts in integer IRR baseline (no floating point); display toman is explicitly 10 IRR per display toman and provider conversion configured/tested. Agency is proposed single merchant of record; partner ledgers have no automatic bank split. Mixed physical/service and heterogeneous service inventory carts blocked MVP with clear UI; homogeneous same-departure group quantity allowed. Local DB transaction never spans external network call. Outbox, idempotency, compensation and reconciliation bridge Woo/gateway/supplier. No raw card details.

## Shared IDs and sources

ADRs: ADR-001 product package; 002 block theme; 003 modular companion plugin; 004 Woo source of truth/HPOS; 005 inventory/custom tables; 006 registration deadline policy; 007 single merchant/mixed carts; 008 IRR/toman; 009 gateway selection/Blocks; 010 localization/calendar; 011 org authorization; 012 supplier RFQ/integrations; 013 scheduler/outbox; 014 PII/retention; 015 security; 016 hosting/SLO; 017 visual scope; 018 build/buy; 019 distribution/license; 020 release evidence. Authoritative decision ledger DOC-06; technical consequences DOC-19. RISK-001..020 allocated in DOC-45. Module authors may use named RISK codes, then root maps them.

Use WF-MODULE-NNN, UI-MODULE-NNN, API-MODULE-NNN, TC-MODULE-NNN. Examples: WF-REG-001 admission/payment/confirmation; UI-COM-001 checkout; API-BOOK-001 POST /agency/v1/holds; API-BOOK-002 GET /agency/v1/bookings/{id}; API-PAY-001 callback; API-PART-001 organization proposals; API-CAT-001 public catalog; TC-REG-001 boundary; TC-REG-002 last-seat race; TC-PAY-001 duplicate callback; TC-AUTH-001 cross-organization denial. Interfaces all proposed except documented WP/Woo APIs; no invented external supplier endpoints.

Official sources verified in this session (retrieved 2026-11-17):
- SRC-WP-THEME https://developer.wordpress.org/themes/getting-started/what-is-a-theme/
- SRC-WOO-HPOS https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/
- SRC-WOO-BLOCKPAY https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/checkout-payment-methods/payment-method-integration/
- SRC-WOO-COMPAT https://developer.woocommerce.com/docs/best-practices/compatibility/
- SRC-WOO-FIELDS https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/additional-checkout-fields/
- SRC-WP-NONCE https://developer.wordpress.org/apis/security/nonces/
Provider APIs, laws, plugin compatibility, exact platform versions and certifications are VERIFY BEFORE BUILD. A provider listing is not sufficient evidence. Cite sources where applicable with URLs and retrieval date. Do not claim verified prices, versions, benchmarks, interviews, legal approval or vendor capabilities.

## Document structure and acceptance

Every DOC file has exact required filename, purpose, Prepared from perspectives, Accountable reviewer, Dependencies, Approval gate, status (DRAFT specification; review pending). Include specific numbered requirements, fields/states/workflows/UI/API/permissions/tests appropriate to its purpose, realistic sample, unresolved assumptions and acceptance gate. Full prose/tables/artifacts, not outline promises. Cross-link files relatively. Include a short 0–5 quality assessment (10 dimensions; limitations and below-4 blockers) explicitly self-assessed. Full 49-doc package required. Do not shrink future modules to generic one-paragraph stubs. Domain DOC-29..40 own eight detailed scenarios A..H with 5+ failure paths each. Review gates do not block writing documents. No code implementation or fictional approvals. Root will audit links, IDs, contradictions, coverage, manifest and requirements.
