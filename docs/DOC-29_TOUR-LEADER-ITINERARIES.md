# DOC-29 — Tour leader itineraries

**Status:** DRAFT specification; review pending. **Purpose:** define structured, reviewed tour departures and operational delivery. **Prepared from perspectives:** tour operations, partner, booking, UX and privacy. **Accountable reviewer:** tour operations lead. **Dependencies:** [DOC-22](DOC-22_DATA-MODEL-ERD-DICTIONARY.md), [DOC-24](DOC-24_BOOKING-INVENTORY-REGISTRATION.md), [DOC-36](DOC-36_PARTNER-PORTALS-CONTRACTS.md). **Decision:** MVP is locally allocated, fixed-capacity, manually confirmed departures; live supplier booking is later and conditional on contract (ADR-012).

## 1. Requirements and data
**FR-TOUR-001:** an approved leader may create many tour definitions and dated departures, for one or many destinations. A traveler can inspect inclusions and reserve an available departure; agency staff approve content and confirm bookings. Site-wide capability alone never authorizes another organization's records (NFR-SEC-001).

| Object | Required proposed fields and constraints |
|---|---|
| ENT-TOUR | organization, localized title/description, languages, age and group limits, fitness, accessibility, required documents, cancellation terms, version, approval state |
| ENT-DEPARTURE | tour version, start/end with IANA zones, capacity, currency, price snapshot, owner, publication and operation states |
| ENT-STOP | ordered city/country, coordinates optional, arrival/departure instant plus zone, meeting point, overnight, contingency |
| ENT-LEG | origin/destination stops, transport type, provider reference if known, planned instants/zones, luggage, connection buffer, allocation; private net cost |
| ENT-ITINERARY | day, ordered timed activity, type, description, inclusions, meal and equipment; lodging night, room allocation, occupancy and booking status |

Validate chronological ordering by UTC instants and show local time and zone at each stop; flag impossible connections, overlapping activities, inconsistent overnight room nights, age boundaries and missing documents. Warnings require reasoned review rather than fabricating routing guarantees. Store revisions rather than changing confirmed travelers' snapshot silently. Net costs, supplier terms and margin are private to authorized agency finance and assigned partner when contract allows; traveler API returns public price only.

## 2. Workflow and interaction
**WF-TOUR-001:** leader drafts → submits immutable version → agency reviews → approves or returns with reasons → schedules departure → publishes → customer selects quantity → signs in → atomic local hold → checkout → verified payment → agency confirms → fulfillment. Capacity and payment follow [DOC-24](DOC-24_BOOKING-INVENTORY-REGISTRATION.md); Woo order is not confirmation. Hold expiry is at most ten minutes and never later than applicable cutoff. Independently verified payment completed locally by the deadline can consume a still-allocated hold without provider paid_at. After the deadline, require trustworthy provider paid_at at or before the deadline and a still-unreleased allocation; if already released, booking transitions `expired → exception` with prior expiry retained in audit, allocation remains released and payment remains verified; route to manual refund review with no new admission or automatic reclaim. Public pages show unavailable or request-only if no active departure.

**UI-TOUR-001** builder has identity, stops, leg, day, lodging/meal/equipment, cost and review panels; reorder controls work with keyboard and announce validation. **UI-TOUR-002** departure details show route, local times, price, inclusions, exclusions, terms and capacity state. **API-TOUR-001** proposed `POST /agency/v1/tours/{id}/versions` accepts revision and structured arrays, checks membership, validates server-side, and returns 201 revision ID or 403/409/422. All mutations audit actor, organization and version; the public endpoint excludes confidential costs. Staff cannot approve their own proposal unless governance explicitly permits it.

## 3. Fictional scenario C — London–Paris–Rome
Tour: five days, adults 18–70, 12 seats, departure 2027-09-01. Illustrative times and providers below are unverified, not bookable transport. Equipment: weatherproof jacket, walking shoes and daypack; accessibility needs reviewed individually. Stop/leg itinerary:

| Day | Local schedule and inclusions |
|---|---|
| 1 | London welcome 09:00 Europe/London; museum walk; lunch excluded. London 15:00 → Paris 18:30 Europe/Paris; Paris standard/twin room, breakfast included. |
| 2 | Paris 09:30 museum visit; lunch and dinner excluded; Paris second night. |
| 3 | Paris 08:00 Europe/Paris → Rome 11:00 Europe/Rome; transfer 12:00 → hotel 13:00, sightseeing 16:00; Rome standard/twin room. |
| 4 | Rome 09:00 attraction visit; picnic lunch included; Rome second night, optional souvenir separate purchase. |
| 5 | Rome departure transfer 10:00 → airport 11:00 Europe/Rome; tour ends. |

Agency receives a fictional supplier quote valid to 2027-08-01: transport 90,000,000 IRR, lodging 60,000,000, sightseeing 12,000,000 and operations 18,000,000 for 12 travelers; total cost 180,000,000. Published per-person price 18,000,000 IRR gives 216,000,000 gross, indicative margin 36,000,000 before tax/fees and unlisted cost. These are snapshots, not live prices. Leader submits structured objects; operator reviews; customer sees 18,000,000 IRR, eligible inclusions and exclusions, then a hold, verified payment and pending agency confirmation. Notification says “Booking awaiting confirmation” / «رزرو در انتظار تأیید است». Supplier quote does not promise inventory; manually reconfirm contracts. Authorized agency finance sees costs; only booking owner sees personal data.

| Exception / detection | Enforcement, recovery, communication and finance |
|---|---|
| Last seat race | Lock inventory row; only winner gets hold, loser sees sold out; no charge; audit attempt. |
| Connection time impossible | UTC validation flags leg; block publication until corrected or accepted with audited rationale; notify leader. |
| Paris hotel quote expires | Mark costs stale; block promised lodging until agency obtains new quote; notify operator, revise price only with customer agreement. |
| Weather closes attraction | Operator records alternative and version; notify affected travelers, handle any price/refund difference manually. |
| Payment verified after hold release | Transition booking `expired → exception` with prior expiry audited; allocation remains released and payment remains verified. Do not admit again; open manual refund review and notify customer/support. |
| Partner tries another firm's itinerary | Object authorization returns 403; security event; no disclosure or finance effect. |

## 4. Tests and open decisions
**TC-TOUR-001:** create three stops with zones and detect leg inversion; **TC-TOUR-002:** customer response omits net costs; **TC-REG-002:** parallel last-seat requests yield one hold; **TC-AUTH-001:** cross-organization access denied. Test editing a published schedule, inaccessible service, missed connection, partial group and supplier withdrawal. **OPEN DECISION:** operations lead must determine approval, document and change-notice policies; finance must approve markup/tax and handling of group partials. Supplier agreements and legal terms are dependencies, not assumed. Analytics use aggregate searches, holds, paid-pending-confirmation and confirmation with no traveler identifiers in analytics.

## Review and acceptance
**Approval gate:** Product owner and named accountable reviewer approve the phase boundary, policy, security and acceptance evidence before implementation or launch; this is a draft, not an approval. Tests must pass in both EN/LTR and FA/RTL with keyboard and screen reader. Open decisions go to [DOC-06](DOC-06_TERMINOLOGY-RULES-DECISIONS.md); finance, privacy and supplier contracts require the relevant human owners.

**Self-assessment, 0–5:** completeness 4 (specified baseline and examples); specificity 4 (fields, paths and tests); consistency 4 (canonical policies); user value 4 (actionable flows); WordPress feasibility 4 (companion plugin boundary); security/privacy 4 (scoped access and minimization); compliance 3 (jurisdiction and contracts unverified: legal review blocker); accessibility/localization 4 (bilingual and keyboard acceptance specified); testability 4 (negative cases specified); traceability 4 (family, UI, API and test references). These are author estimates, not audits. Below-4 blocker: legal/contract review before commercial launch.
