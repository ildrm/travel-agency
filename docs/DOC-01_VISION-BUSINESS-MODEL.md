# DOC-01 — Vision and business model

Purpose: define the commercial problem, intended product and measurable outcomes. Prepared from perspectives: sponsor, product lead, travel operations, finance, UX. Accountable reviewer: Executive Sponsor. Dependencies: [brief](_sources/ORIGINAL-PROMPT.md), [decisions](DOC-06_TERMINOLOGY-RULES-DECISIONS.md). Approval gate: sponsor accepts customer, merchant and MVP assumptions. Status: DRAFT specification; review pending.

## 1. Vision and boundaries

CONFIRMED business intent: an English/Persian travel and leisure experience combines tours, stays, events, in-person Academy, transport, rentals and merchandise, with controlled B2B collaboration. The product must be a customizable WordPress theme compatible with WooCommerce. Durable booking, identity, payment and operations records survive theme changes in companion plugins. Atlas Leisure Ecosystem is a codename only.

ASSUMPTION A-001: initial customer is a single agency operating its own site; reusable theme packaging is desirable, but public marketplace distribution is not approved. One WordPress deployment is one operator, not a hosted SaaS. Partner organizations share the agency installation with strict record scoping. A multi-agency SaaS would require a new isolation, billing and operations ADR.

The customer problem is uncertainty: what is included, when registration is open, whether a payment resulted in a confirmed service and where to obtain help. The agency problem is fragmented supplier commitments, operational exceptions and reconciliation. The theme product solves consistent browsing, trust, responsive presentation and editing. Companion plugins solve structured itineraries, windows, availability, organizations and fulfillment. WooCommerce owns commerce order totals and the checkout foundation.

## 2. Capability and revenue map

| Capability | Value and intended revenue | Operator obligation | First supported form |
|---|---|---|---|
| Tours / activities | Package margin or agreed commission | Publish true inclusions, supplier plan and traveler notice | MVP manually managed departure |
| Events | Ticket/service revenue | Capacity, schedule, check-in, catering | MVP occurrence with seat hold |
| Academy | Cohort tuition | Prerequisites, sessions, attendance | MVP basic cohort; assessments P2 |
| Souvenirs | Retail/wholesale margin | Stock, shipping, returns and artwork rights | MVP ready-made Woo products |
| Stays | Contracted net-rate margin/commission | Room inventory and guest service | MVP RFQ; P2 confirmed local inventory |
| Transfers / rentals | Service/rental revenue | Dispatch or fleet, eligibility, deposits | MVP RFQ; P2 operational booking |
| Flights / rail / bus | Contracted service fee/commission | Licensed inventory, issuance, changes | MVP RFQ; P3 supplier integration |
| B2B procurement | Negotiated margin or handling fee | Partner terms, samples, inspection | Proposals MVP; RFQ/manufacturing P2 |

All economic models are proposals subject to contracts and tax review. No subscription revenue, take rate, cross-border operating right or supplier agreement is assumed.

## 3. Customer and partner service journeys

WF-VISION-001 customer: discover localized offering -> review dated availability, eligibility and exclusions -> sign in -> create atomic hold -> inspect total and payment deadline -> hosted payment -> see payment verification pending -> agency/supplier confirmation -> receive itinerary/ticket/voucher -> attend/receive -> support/refund/review. A payment receipt must never be labeled a confirmed travel ticket. Closed registration remains publicly informative, with materials and previous bookings accessible as permitted.

WF-VISION-002 partner: apply as organization -> agency verification -> agreement and role-scoped staff -> draft proposal -> agency review and changes -> published offer -> assignments and minimum necessary traveler details -> fulfillment evidence -> statement and dispute -> approved settlement. A partner does not self-approve confidential price changes into a live booking.

WF-VISION-003 agency: review offering and supplier obligations -> approve version -> monitor seats/payment exceptions -> verify operational confirmation -> manage changes with customer notice -> reconcile captured payments/refunds -> reconcile partner statement -> close service and support follow-up. Each stage has an accountable human until validated automation is released.

## 4. Unit economics and pricing inputs

Fictional illustration, not a quote: a 30-seat event sells 25 seats at 5,000,000 IRR = 125,000,000 IRR gross service sales. If discounts total 5,000,000, refunds 10,000,000, direct supplier cost 70,000,000 and processing cost 1,000,000, illustrative contribution before tax/overhead is 39,000,000 IRR. Gross receipts are not profit or earned revenue. Tax, deferred revenue and supplier liability treatments require finance signoff in DOC-39.

Required offer fields: selling unit; currency/unit; tax treatment; supplier cost visibility; agency price; included/excluded service; cancellation policy version; availability source; quote expiry; confirmation mode; merchant identity. Changing costs after a sale cannot silently change the customer's purchased snapshot. Manufacturer quotes and agency margins remain confidential.

## 5. Outcomes, measurement and guardrails

| Outcome | Proposed metric | Baseline and decision |
|---|---|---|
| Reliable booking | Oversold confirmed inventory = 0 | Invariant, checked per service |
| Clear payment handling | Every verified payment maps to confirmed service or visible exception/refund | Daily reconciliation; no target invented for provider latency |
| Useful bilingual experience | Required MVP strings/content translated and reviewed | 100% release checklist, native review pending |
| Conversion | Confirmed bookings / eligible signed-in checkout starts | Establish after pilot; no forecast uplift |
| Operational efficiency | Minutes of manual work / confirmed booking | Measure pilot before automating |
| Trust | Unresolved payment complaints; support resolution time | Observe first pilot; set SLA by staffing |
| Theme product quality | Clean-install, theme-switch and update acceptance pass rate | 100% declared supported configurations |

Analytics excludes traveler identity, medical/dietary notes, passport/licence numbers and raw payment tokens. Sensitive customer service work must not be optimized away to improve conversion.

## 6. Business gates and open decisions

Sponsor must decide distribution model (ADR-001/019), operating entity and merchant (ADR-007), initial cities/markets and sellable verticals (DOC-05), service staffing/emergency coverage, payment agreement (ADR-009), tax and consumer terms (DOC-43), and brand (ADR-017). No human is named or presumed to have accepted a role. The role owner is the proposed decision authority.

Acceptance: a sponsor can explain which party sells, fulfills, supports and refunds each MVP item; a buyer can distinguish payment from confirmation; every claimed revenue stream has a contract/evidence gate; no fictional business or KPI result is represented as actual. Example acceptance: given an unpaid reservation, the account shows its deadline and pending state, with no issued ticket.

Quality self-assessment: completeness 4; specificity 4; consistency 4; value 4; WP feasibility 4; security/privacy 4; compliance 3; a11y/localization 4; testability 4; traceability 4. Below-4 blocker: merchant/jurisdiction/contracts unknown (RISK-003/005); qualified review required before selling, while design documentation can proceed.
