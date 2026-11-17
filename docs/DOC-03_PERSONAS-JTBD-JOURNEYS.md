# DOC-03 — Personas, jobs and journeys

Purpose: connect screens and operational decisions to user tasks. Prepared from perspectives: UX research, service design, travel operations, localization. Accountable reviewer: UX Lead. Dependencies: [vision](DOC-01_VISION-BUSINESS-MODEL.md), [research](DOC-02_RESEARCH-BENCHMARKS.md). Approval gate: validate proposed personas with real users before treating them as research findings. Status: DRAFT; all personas below are fictional hypotheses.

## 1. Persona hypotheses

| Persona | Job to be done | Need / constraint | Success measure | Screen family |
|---|---|---|---|---|
| Sara, FA mobile purchaser | Book an eligible family excursion confidently | RTL, guardian/traveler separation, clear toman/IRR | Finds final total and confirmation state | UI-CAT,UI-TOUR,UI-COM |
| Alex, EN international traveler | Understand a multi-city trip before paying | Time zones, documents, no false ticket promises | Finds lodging, exclusions and supplier confirmation | UI-TOUR,UI-ACCOUNT |
| Mina, learner | Choose a cohort fitting work schedule | Course vs city/date/cohort, prerequisites, material access | Enrolls only open cohort; sees materials later | UI-ACA |
| Reza, leader | Propose several tours and manage dated departures | Draft versioning, offline-friendly itinerary, confidential net costs | Agency approves complete version without cost leak | UI-PART,UI-TOUR |
| Leila, property manager | Offer rooms and avoid overselling | Room type counts, assigned units, staff scoping | No double assigned unit, clear payout statement | UI-STAY,UI-PART |
| Omid, transport dispatcher | Respond to service request | Passenger/luggage details only when needed | Accepted assignment with pickup instructions | UI-XFER,UI-PART |
| Neda, maker | Quote 500 customized gifts | MOQ, art proof, production schedule, quality | Approved proof and stage payment terms | UI-MERCH,UI-PART |
| Amir, agency operator | Resolve paid but unconfirmed service | Permission to act, accurate timeline, supplier context | Every captured payment has resolution owner | UI-ADMIN |
| Farah, finance reviewer | Reconcile receipts and partner liability | Audit, currency units, segregation of duties | Difference explained, no duplicate refund | UI-FIN |
| Hamed, keyboard/screen-reader user | Complete same tasks without pointer | Logical focus, labels, reduced motion, live statuses | Finishes booking without inaccessible control | All families |

No persona substitutes for a permission role. The same user may purchase personally and act for two partner organizations. Organization context must be visibly selected and verified server-side on each request.

## 2. Journey WF-JOURNEY-001: excursion purchase

| Stage | Customer thought/action | Frontstage | Backstage / owner | Failure recovery |
|---|---|---|---|---|
| Discover | Is this suitable? | Cards show date, from-price basis, accessibility and state | Editorial approval / content owner | Missing translation blocks sale, not invented content |
| Evaluate | What is actually included? | Meals, route, gear, exclusions, cancellation terms | Immutable policy version / operations | Contact form with offering reference |
| Sign in | Will I lose selection? | Return to selected occurrence; accessible recovery | WordPress identity / support | Preserve non-sensitive selection; no hold assumed |
| Reserve | Are seats mine? | Attendees and atomic hold result with deadline | Inventory service / booking owner | Last-seat loss retains safe form fields, no charge |
| Pay | Which amount/unit? | Total IRR and explicit display conversion; hosted provider | Payment adapter / finance | Unknown result shows verifying, not “failed” prematurely |
| Confirm | Can I travel? | Distinct paid and confirmed states | Ops verifies supplier/agency obligation | Exception queue/refund options and contact |
| Attend | Where/when to meet? | Local time, time zone, printable itinerary/QR | Leader roster minimal PII | Cancellation/emergency message with delivery tracking |
| Follow up | How do I get help? | Support thread linked to own booking | Support and refund roles | Authorized escalation, timeline and receipt |

Emotional hypotheses: confidence rises when rules and status are explicit; animation is secondary. Test rather than claim conversion improvement. Event seats and cohort seats follow the same admission engine while fields differ.

## 3. Journey WF-JOURNEY-002: leader proposal

1. Approved member selects organization; dashboard lists only permitted tours.
2. Creates reusable tour with structured days, stops, legs and nights; saves incomplete draft locally/server-side with validation messages.
3. Adds departure capacity, registration window, traveler requirements and supplier costs. Missing room-night or impossible connection flags block submission; illustrative times are marked.
4. Agency reviews version, requests changes and approves a published snapshot. Leader sees decision history and submits a new version for material changes.
5. Departure roster becomes available only to assigned operators for a defined time. Payment/finance views are not automatically granted to leaders.
6. Service completion/incident record supports a settlement statement; finance approval and actual payout remain separate.

Failure: leader loses membership mid-edit -> write denied, safe draft message, no cross-org reassignment. Published change after bookings -> impact assessment, notification/consent decision, version history; no silent overwrite.

## 4. Journey WF-JOURNEY-003: learner and closed cohorts

Course page describes reusable syllabus. Each cohort independently shows city, venue, session times, prerequisites, seats and its own opening/closing instant. One open and one closed cohort may coexist. Closed enrollment must not remove prior learner confirmations/materials. Support handles prerequisite review without emailing sensitive documents. P2 assessment/certification only awards a verifiable credential after attendance and completion evidence; MVP does not imply accredited qualification.

## 5. Journey WF-JOURNEY-004: partner procurement

Maker submits a scoped quote -> agency compares net cost/lead time privately -> sample/art proof approved with version -> purchase terms accepted -> manufacturing milestones -> inspection -> acceptance/rework -> statement/payment. Customer only sees approved retail or B2B quote, never other manufacturers' bids. Property request follows similar quotation flow in MVP and explicitly says availability is unconfirmed; no checkout until a sellable confirmed operational model exists.

## 6. Service design rules and validation

JR-01 every irreversible action identifies who acts and for which booking/org. JR-02 every deadline names local zone and backend instant. JR-03 interrupted or slow network does not duplicate a booking/payment. JR-04 every denied or closed action provides an honest recovery path. JR-05 sensitive traveler details are collected only for the selected service, not during generic account signup. JR-06 native FA review covers forms, emails, vouchers and mixed-script references.

Proposed usability protocol: tasks to book the final seat, compare cohorts, identify price exclusions, locate paid-but-unconfirmed status, switch organizations and request a refund. Measure completion, critical misunderstanding, time and accessibility barriers in both languages. Proposed gate: zero critical misunderstanding of amount/unit, confirmation or cancellation in observed pilot; revise and retest critical failures. Do not infer statistical performance from a small convenience sample.

Acceptance: each MVP journey maps to a screen, state, owner, failure recovery and test in [DOC-46](DOC-46_TRACEABILITY-MATRIX.md); later journeys are clearly phase-marked. Unknown participant expectations remain assumptions. Quality self-assessment: completeness 4; specificity 4; consistency 4; value 4; WP feasibility 4; security/privacy 4; compliance 4; accessibility/localization 3; testability 4; traceability 4. Below-4 blocker: personas and native/assistive user validation pending (RISK-009).
