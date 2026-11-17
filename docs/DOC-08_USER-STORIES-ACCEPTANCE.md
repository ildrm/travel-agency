# DOC-08 — User stories and acceptance

**Status:** DRAFT specification; review pending. **Purpose:** provide verifiable behavior and negative paths for prioritized journeys. **Prepared from perspectives:** product, QA, support, finance, accessibility. **Accountable reviewer:** product owner. **Dependencies:** [requirements](DOC-07_BRD-SRS.md), [booking](DOC-24_BOOKING-INVENTORY-REGISTRATION.md). **Approval gate:** product and QA approve criteria; live integration requires provider evidence.

## 1. Epic: discover and choose

**US-01 (MVP, FR-CAT-001):** As a visitor I can filter published activities by destination, date and type so I can assess a relevant offer. Given a published occurrence with a future registration window, when I search the destination, then its detail shows dates, time zone, price unit, opening time and “not yet open”, without an enabled registration action. An empty result provides filter reset; an unavailable API presents an actionable retry without inventing inventory. Keyboard and Persian tasks follow the same route.

**US-02 (MVP, FR-TOUR-001):** As a customer I need to understand what a departure includes before paying. Given an approved published departure, when I open its itinerary, then each stop, transport leg, included and excluded meal and customer price is visible, but confidential supplier costs and internal notes are absent from page source, API and export. Given a material published change, agency assesses existing bookings and records customer notification; no silent contract change is assumed.

## 2. Epic: admission, checkout and resolution

**US-03 (MVP, FR-EVT-001 / FR-ACA-001):** As a customer I can register only during the occurrence’s own window and only if seats remain. **TC-REG-001:** Given a published eligible occurrence, when server time equals open, then a hold may be created; when server time equals close, it is denied. Client clock and disabled buttons are not authoritative. **TC-REG-002:** Given one seat and two concurrent eligible requests, when both submit, then only one hold commits and the other receives sold-out feedback. A course and two cohorts can show one open and one closed independently.

**US-04 (MVP, FR-COM-001 / FR-PAY-001):** As a signed-in purchaser I can review the final price, go to an eligible gateway, and see a truthful outcome. Given a hold, when the user starts payment, show its deadline and displayed currency unit; never request card details in the site. Given independent local verification with matching amount and currency completed at or before the deadline, when the hold is unreleased, consume it once and create a paid-pending-confirmation booking without requiring provider paid-at. Given verification completed after the deadline, require both trustworthy provider paid-at at or before the deadline and an unreleased allocation; then consume once even if confirmation occurs after close. At the deadline, block new payment initiation and public admission; serialize consume versus release. A payment redirect by itself does not confirm booking. Duplicate callback has no second effect (TC-PAY-001).

**US-05 (MVP, FR-FIN-001):** As support I need to handle an unverified or late paid booking safely. Given a released hold and a subsequently verified payment, when reconciliation processes it, the expired booking moves to exception and payment remains verified; no inventory is reclaimed automatically, staff receives an exception and the customer sees “payment under review”. Refund is independently requested, approved and tracked. A deadline-passed allocation remains allocated until the serialized consume/release race decides it; if verification is late and paid-at is unavailable or untrustworthy, route to manual review. One local transaction never crosses a gateway network call. Waitlist after close requires an audited admin exception and available capacity.

**US-06 (MVP, FR-COM-001):** Given a souvenir in cart, when a user adds a tour, then explain incompatible cart types and offer separate purchase without silently discarding items. A homogeneous group of the same departure may use one quantity subject to capacity; differing departure inventory is not mixed.

## 3. Epic: partners and support

**US-07 (MVP, FR-PART-001 / FR-TOUR-001):** As approved leader staff I can draft several tours and dated departures for my organization, submit them, and view agency feedback. Given another organization’s identifier, when I request its proposal via UI, API or export, then access is denied and no sensitive record is returned (TC-AUTH-001). Agency reviewers see submitted material and record approve/revise decision with actor/time. A role alone does not grant access to every organization.

**US-08 (MVP, FR-STAY-001 / FR-AIR-001 / FR-GROUND-001 / FR-XFER-001 / FR-RENT-001):** As a visitor I can request a stay or transport quote and know it is not confirmed. A successful request returns a reference and “request received; availability and price unconfirmed”; it must not create a paid order or issued ticket. Identity fields are minimized to what is necessary for a response. Future integrations require contracted provider evidence.

**US-09 (MVP, FR-SUP-001):** As a customer I can contact support about a booking. Given a case and my ownership, when I post a message, staff can respond and record the outcome; internal notes stay private. Notification failure queues a retry and does not silently resolve the case.

## 4. Epic: accessible multilingual content

**US-10 (MVP, FR-I18N-001 / NFR-A11Y-001):** As a Persian reader using a keyboard I can complete the same task as an English reader. Given mixed English booking reference in Persian content, when displayed, then it remains readable and copied unchanged, direction is isolated and focus order follows the logical task. An error identifies the field and correction text; announcement does not rely on color. Switching language preserves the same record and safe route when translation exists; missing translation is labeled and never substitutes invented text.

**US-11 (MVP, FR-THEME-001 / FR-DATA-001):** As an agency administrator I can switch theme without losing booking records. Given active companion data, when theme changes on staging, then records remain retrievable with permissions, and no destructive uninstall occurs. With Woo absent, setup gives a clear dependency notice and storefront commerce does not appear operational.

## 5. Acceptance workflow and evidence

QA maps stories to requirements, screens, test fixtures and phase in DOC-46; automation records platform versions and results, while UAT records reviewer, build, date and unresolved defects. Example fixture: fictional event, 30 seats, named IANA zone, a purchaser, two rival requests and a gateway simulator; it does not imply a live gateway. A test can pass in a simulator while production eligibility remains a separate blocker. Legal/finance review must sign off actual refunds, price units and consumer copy.

**Self-assessment (0–5):** completeness 4 (MVP journeys); specificity 4 (observable tests); consistency 4 (shared deadlines); user value 4 (customer and operations); WP feasibility 4 (Woo and plugin boundary); security/privacy 4 (negative access); compliance 2 (provider/legal evidence missing, blocker); accessibility/localization 4 (critical journey); testability 4 (Given/When/Then); traceability 4 (FR/TC links). Future vertical-specific criteria reside in domain documents.
