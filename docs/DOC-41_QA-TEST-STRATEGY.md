# DOC-41 — Quality assurance and test strategy

**Purpose:** Define reproducible evidence for a reusable theme and its commerce and booking companions before real customers or money are exposed.  
**Prepared from perspectives:** QA, booking concurrency, WooCommerce, security, accessibility and operations.  
**Accountable reviewer:** QA lead; product, security, finance and accessibility reviewers.  
**Dependencies:** [requirements](DOC-07_BRD-SRS.md), [booking](DOC-24_BOOKING-INVENTORY-REGISTRATION.md), [commerce](DOC-25_WOOCOMMERCE-COMMERCE-ENGINE.md), [release](DOC-48_LAUNCH-UAT-HANDOVER.md).  
**Approval gate:** Required evidence, defects and deviations reviewed and approved by named accountable owners.  
**Status:** DRAFT specification; review pending.

## 1. Strategy, environment and ownership

No test has been executed merely by writing this specification. Unit tests cover pure eligibility, monetary conversion and state transition rules. Database integration tests run against the actual supported transaction engine and isolation level with separate connections. Woo/WordPress integration tests exercise public APIs, HPOS mode and Blocks compatibility, plus classic checkout only where declared supported. Browser tests cover customer and partner tasks; contract tests use provider sandboxes or owned simulators explicitly labeled as simulations. Security, accessibility and recovery reviews include independent human checks. UAT uses fictional records and pre-agreed business outcomes. QA maintains a report linking build hash, environment versions, fixtures, test ID, result, defects and reviewer.

Before release, define exact supported WordPress, WooCommerce, PHP, database and browser versions, Blocks checkout and classic only if declared supported, HPOS configurations, EN/FA and screen sizes. Include theme only, theme plus Woo and full companion configurations. Pin test versions and include upgrade from last supported release. Never infer compatibility from a marketing listing. Staging cannot charge real cards or send production SMS/email, and must not use production personal data by default.

## 2. Executable acceptance scenarios

Tests below assume a clock controlled at the server and transactional database fixtures. In production the server clock and zone database must be monitored; browser time is not an authority. These Gherkin examples are specifications to automate, not results.

```gherkin
@TC-REG-001 @FR-EVT-001
Scenario Outline: admission uses a half-open window
  Given an occurrence has open 2027-06-01T05:30:00Z and close 2027-06-15T14:30:00Z
  And one seat is available and the customer is eligible and signed in
  When server time is <instant> and the customer requests one hold
  Then the outcome is <outcome>
  Examples:
    | instant              | outcome             |
    | 2027-06-01T05:29:59Z | registration_closed |
    | 2027-06-01T05:30:00Z | held                |
    | 2027-06-15T14:29:59Z | held                |
    | 2027-06-15T14:30:00Z | registration_closed |
```

```gherkin
@TC-REG-002 @NFR-CONC-001
Scenario: two customers race for the last seat
  Given one occurrence has capacity 30 and 29 seats allocated
  And two authenticated customers use distinct idempotency keys
  When two database connections concurrently request one seat
  Then exactly one hold is committed and one request reports sold out
  And allocated quantity is 30 and no capacity is negative
```

Repeat the race many times with varied ordering, group quantities, transaction aborts and worker restarts. Assert persisted database rows and invariant, not only HTTP responses. The chosen trial count and time budget are proposed by QA and approved for the target environment.

```gherkin
@TC-REG-003 @TC-PAY-001
Scenario: timely local verification does not require provider payment time
  Given a hold is still allocated and its payment deadline is 18:00:00
  And the provider confirms payment without a trustworthy paid_at
  When independent verification completes locally at 17:59:59
  Then the allocation may be consumed and the booking becomes paid_pending_confirmation
  And no new payment initiation is permitted at or after 18:00:00

@TC-REG-003
Scenario: late verification requires trustworthy timely provider time
  Given the hold is still allocated with payment deadline 18:00:00
  When independent verification completes locally at 18:00:01
  Then the allocation may be consumed only if trustworthy provider paid_at is at or before 18:00:00
  And missing, untrusted or later paid_at puts the verified payment in exception

@TC-REG-003
Scenario: payment verification and expiry race on the hold row
  Given the hold is still allocated and its deadline has passed
  And independently verified provider paid_at is at or before that deadline
  When payment consumption and the expiry worker race for the same local hold row
  Then at most one transition consumes or releases the allocation
  And if consumed first the booking becomes paid_pending_confirmation
  And if released first the booking transitions from expired to exception
  And payment stays verified, allocation stays released and manual refund is queued
  And no duplicate payment, ledger or allocation effect is recorded
```

```gherkin
@TC-PAY-003 @FR-PAY-001
Scenario: unknown provider outcome is reconciled
  Given a payment initiation times out with no reliable result
  When the user returns to the site or repeats the request
  Then payment is unknown and no booking or ticket is claimed confirmed
  And the system uses documented provider status inquiry when supported
  And retries no unsafe financial action until reconciliation or manual resolution
```

```gherkin
@TC-AUTH-001 @NFR-SEC-001
Scenario: partner staff cannot access another organization's records
  Given a user is an active contributor of organization A but not organization B
  And B owns a proposal, booking, export and private attachment
  When the user requests each B resource via UI, REST, search and direct download
  Then all attempts are denied without revealing confidential data or existence
  And the denial is recorded without logging sensitive payloads
```

## 3. Test coverage and evidence matrix

| Area | Required checks and failure paths | Evidence / owner |
|---|---|---|
| Theme/FR-THEME-001 | Install without Woo, Woo without companion, activate full stack, switch theme, update while retaining user templates and plugin data; no fatal error or fake purchase. | TC-THEME-001–005, theme lead. |
| Checkout/FR-COM-001 | Sign-in required; mixed physical/service and heterogeneous service carts rejected server-side in Blocks and classic mode only if declared supported; totals/terms snapshot; shipping and coupon edge cases. | TC-COM-001–006, Woo lead. |
| Payment/FR-PAY-001 | Forged redirect, duplicate callback, wrong currency/unit, timeout-after-success, verification after hold released, unsupported refund. | TC-PAY-001–003 and gateway test report, finance. |
| Booking/FR-TOUR-001, FR-EVT-001, FR-ACA-001 | Exact windows, expired and re-entered checkout, all-or-nothing groups, waitlist after close, audited admin exception, eligibility and time zone conversion. | TC-REG-001–007, domain lead. |
| Partners/FR-PART-001 | Multiple org memberships, revocation, member assignment, ownership transfer, export/media/cache leaks, least privilege and audit. | TC-AUTH-001–006, security. |
| Localization/NFR-LOC-001 | Persian/English labels, RTL/Bidi references, digits, IRR/toman conversion and local/UTC schedule; text expansion and translation fallback. | TC-I18N-001, localization lead. |
| Accessibility/NFR-A11Y-001 | Keyboard-only, focus, labels/errors, 200% zoom, screen reader, contrast and reduced motion; no inaccessible booking prerequisite. | WCAG review and automated scan plus manual evidence, accessibility lead. |
| Recovery/NFR-REL-001 | Worker outage, retries, restore and post-restore provider reconciliation; interrupted migration and rollback compatibility. | TC-OPS-001–006, SRE. |

Evaluate WCAG 2.2 AA against actual UI using [W3C WCAG 2.2](https://www.w3.org/TR/WCAG22/), retrieved 2026-11-17; an automated scan alone is insufficient. Verify WordPress nonce behavior and authorization independently: [WordPress nonces](https://developer.wordpress.org/apis/security/nonces/), retrieved 2026-11-17.

## 4. Proposed performance and resilience experiments

**RECOMMENDATION, not measured:** run a representative staging workload with 100 simultaneous catalog users and 20 concurrent hold requests including a last-seat race; record p50/p95 latency, errors, CPU, database lock waits, queue age and oversell count. The only unconditional integrity outcome is zero oversells; acceptable response times require owner-approved budgets and realistic hardware. Repeat with database contention, scheduler outage and provider delay/failure, then reconcile all events. Targets and workload are hypotheses, not claimed capacity, SLA or benchmark.

## 5. Defects and acceptance

A reproducible unauthorized disclosure, oversell, unverified payment marked verified, lost financial record, broken required checkout, inaccessible core task or untested restore blocks launch. Defects include severity, reproduction, owner, customer impact, workaround, retest and approval; exceptions require explicit risk acceptance, and cannot supersede legal/provider obligations. QA owns execution; finance owns gateway and money acceptance; operations owns runbook rehearsal; product owner accepts business UAT.

**OPEN DECISION:** version/support matrix, representative traffic, payment provider test capabilities and external security auditor. No passing test, certification or approved release is claimed.

## Review and evidence

This is a proposed specification, not evidence of execution, legal advice, independent audit, measured performance, or human approval. Provider details and applicable legal duties remain VERIFY BEFORE BUILD.

**Self-assessment, 0–5:** completeness 4 (document scope covered); specificity 4 (actionable examples); consistency 4 (shared baseline); user value 4 (decision support); implementation feasibility 4 (platform integration considered); security/privacy 4 (controls proposed); compliance 3 (jurisdiction and qualified review outstanding); accessibility/localization 4 (explicit tests or controls); testability 4 (observable acceptance); traceability 4 (shared IDs). The compliance score of 3 is a release blocker pending qualified review and evidence; see [risk register](DOC-45_RISKS-DECISIONS-CONTROLS.md). Scores are author estimates, not independent audit findings.
