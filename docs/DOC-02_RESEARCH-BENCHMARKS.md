# DOC-02 — Research, evidence and benchmarks

Purpose: separate observed platform guidance, supplied intent and untested proposals. Prepared from perspectives: research lead, Woo architect, procurement, legal. Accountable reviewer: Product Research Lead. Dependencies: [source brief](_sources/ORIGINAL-PROMPT.md). Approval gate: evidence required for each build/buy and launch claim. Status: DRAFT research baseline; no interviews, vendor trials or performance benchmark were performed.

## 1. Method and evidence hierarchy

1. Business intent: original prompt, supplied by user; evidence of requested scope only.
2. Current official technical docs: direct inspection, URLs below, retrieved 2026-11-17. These support API/architecture principles, not compatibility of an untested installed combination.
3. Provider contracts/signed API specs and qualified jurisdiction advice: required but not supplied.
4. Reproducible sandbox/compatibility measurements: required during implementation, not yet performed.
5. Practitioner claims, directory listings and marketing pages: discovery leads only; insufficient for approval.

Store each research record as `source_id, URL/file, publisher, title, retrieved_at, version_if_known, claim, applicability, limitations, owner, next_check`. A CONFIRMED fact needs direct applicable evidence. A model recommendation remains a recommendation even if technically plausible. Recheck on target-version selection and release.

## 2. Source register

| ID | Official source | Supported conclusion / limit |
|---|---|---|
| SRC-WP-THEME | [WordPress: What is a Theme?](https://developer.wordpress.org/themes/getting-started/what-is-a-theme/) | Presentation belongs in theme, durable features in plugins; does not select this project's packaging |
| SRC-WOO-HPOS | [HPOS recipe book](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/) | Use Woo order abstractions and test storage compatibility; custom module behavior untested |
| SRC-WOO-BLOCKPAY | [Checkout payment integration](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/checkout-payment-methods/payment-method-integration/) | Blocks integration has registration/asset requirements separate from payment processing; no domestic gateway endorsement |
| SRC-WOO-COMPAT | [Compatibility](https://developer.woocommerce.com/docs/best-practices/compatibility/) | Declare and test feature compatibility; no exact version matrix verified |
| SRC-WOO-FIELDS | [Additional checkout fields](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/additional-checkout-fields/) | Location affects persistence; traveler records need purpose-specific design |
| SRC-WP-NONCE | [WordPress nonces](https://developer.wordpress.org/apis/security/nonces/) | CSRF mitigation cannot replace authentication/authorization |
| SRC-WCAG | [WCAG 2.2](https://www.w3.org/TR/WCAG22/) | Accessibility criteria basis; no conformance audit has occurred |
| SRC-WP-PRIV | [Plugin privacy](https://developer.wordpress.org/plugins/privacy/) | Minimize and disclose personal data handling; does not establish applicable law |
| SRC-AS | [Action Scheduler usage](https://actionscheduler.org/usage/) | Queue integration reference; task deadlines still need runtime design and tests |

All URLs above retrieved 2026-11-17. If an endpoint moves, locate the publisher's replacement and record the new version rather than silently changing a claim. Provider documentation for SEP, Mellat, ZarinPal, airline/GDS, lodging channels, SMS and crypto remains VERIFY BEFORE BUILD; no provider behavior is inferred from sample interfaces.

## 3. Capability benchmark and build/buy questions

This is a requirements comparison, not a tested competitive ranking. No vendor pricing, endorsement or market share is claimed.

| Approach/category | Candidate role | Fit to investigate | Gap requiring evidence |
|---|---|---|---|
| WordPress + custom block theme | Editing/content and bilingual presentation | Design tokens/templates/patterns | Target core version, plugin/editor interactions |
| WooCommerce core | Products, orders, payment/tax/shipping foundation | Avoid custom commerce reinvention | Domain inventory and independent service confirmation |
| Booking extension category | Seats/rooms/resource calendars | Buy common inventory UI if contracts fit | Atomic holds, strict cutoff, HPOS/Blocks, org permissions, migration |
| Event extension category | Occurrences, check-in, ticketing | Buy check-in tooling if data portable | Cohorts vs events, waitlist/cutoff, bilingual UX |
| LMS extension category | Learning, assessment, credentials | Consider P2 Academy growth | In-person cohort windows and Woo integration |
| Multivendor category | Partner dashboards/commissions | Evaluate only when controls align | Multiple-org memberships, record isolation, no uncontrolled payout |
| Multilingual category | Content language routing/translation | Buy maintained infrastructure | Woo compatibility, RTL, Jalali, private domain entities |
| Supplier aggregator | Licensed shopping/issuance | Contracted adapter P3 | Eligibility, seats, issuance, changes, settlement |

DOC-19 owns final build/buy/hybrid ADRs. Default design is hybrid: Woo for commerce; custom small transactional domain layer; select maintained translations/payment extensions only after proof. Do not install fifteen extensions merely because each has a matching marketing feature.

## 4. Research work plan

RES-01 Product owner interviews: 2 agency operators, 2 partner staff, 3 customer task sessions per language as proposed convenience samples; not representative market research. Obtain informed consent, redact records and document actual participants when done. Ask users to distinguish “paid” from “confirmed” and find closed-cohort information.

RES-02 Vendor due diligence: request legal entity/eligibility, dated API spec, sandbox, unit requirements, verification/refund/status semantics, webhook auth, timeouts, support escalation, commercial terms and data location. Record contradictions; integration engineer reproduces both success and ambiguous failure. Never include secrets in this repository.

RES-03 Compatibility spike: freeze exact WP/Woo/PHP and extension versions; run minimal theme + one companion plugin + selected gateway in Blocks checkout and every claimed classic mode, HPOS settings, FA RTL and mobile. Publish pass/fail with environment and evidence, not “compatible” based on metadata.

RES-04 Legal/tax/accessibility: obtain jurisdiction-specific qualified advice; native Persian review; assistive-technology testing. No current Iranian legal conclusion is asserted by this package.

## 5. Acceptance and known gaps

Each purchase decision has a reproducible evaluation card: must-have pass/fail, license, updates, vulnerability history, export/uninstall behavior, Blocks/HPOS, API documentation, support, 3-year total-cost assumptions, exit plan. Reject unsupported payment/ownership/stock behavior regardless of score. Benchmark latency with workload and raw measurements; proposals in DOC-28 are not results.

Quality self-assessment: completeness 4; specificity 4; consistency 4; value 4; WP feasibility 4; security/privacy 4; compliance 3; accessibility/localization 3; testability 4; traceability 4. Below-4 blockers: no qualified legal review or native-user study (RISK-003/009); evidence gaps are explicit. This document is complete as a research baseline, not completed field research.
