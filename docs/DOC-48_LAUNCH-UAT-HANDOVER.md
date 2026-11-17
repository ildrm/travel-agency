# DOC-48 — Launch, UAT and handover

Purpose: define the evidence and operational acceptance needed to release the theme and companions. Prepared from perspectives: QA/UAT, release engineering, product, operations, finance, security, localization. Accountable reviewer: Release Manager; Product Owner accepts business UAT. Dependencies: [QA](DOC-41_QA-TEST-STRATEGY.md), [legal](DOC-43_LEGAL-REGULATORY-REVIEW.md), [backlog](DOC-44_IMPLEMENTATION-BACKLOG-ESTIMATES.md), [risks](DOC-45_RISKS-DECISIONS-CONTROLS.md), [runbooks](DOC-47_OPERATIONS-RUNBOOKS.md). Approval gate: explicit evidence-backed human signoff below. Status: DRAFT launch specification; no product release, UAT result or approval exists.

## 1. Release artifact and evidence bundle

REL-01 versioned theme ZIP and separate companion/integration plugin ZIPs, reproducible build hashes, source revision, changelog, license/third-party notices and asset/font provenance. No merchant secrets, production records, writable uploads, dependency caches or development credentials in packages. Distribution channel-specific requirements remain conditional on ADR-019; repository currently contains GPLv2 license, not proof every future asset is cleared.

REL-02 exact tested WP/Woo/PHP/database/browser/editor versions; supported HPOS and checkout modes; required/optional dependency versions; known limitations and update policy. “Latest” without recorded version/date is insufficient. Blocks is primary; traditional checkout only claimed when tested. Show each tested mode's real gateway/unit behavior.

REL-03 migrations/upgrade path, backup/restore and rollback compatibility; plugin data retention/export/uninstall policy; setup guide and disabled-feature behavior. A theme switch must retain domain records and allow approved companion workflows under another supported presentation path.

REL-04 requirements/test mapping (DOC-46) with execution results, build/environment, defects, retests and reviewers. Vendor simulator results clearly distinguished from real sandbox/production capability. Privacy/security, native FA, accessibility, performance and legal/vendor evidence attached or explicitly blocked.

## 2. UAT scripts and accountable signoff

| UAT ID | Actors and task | Required observed result | Reviewer |
|---|---|---|---|
| UAT-01 | Admin clean installs theme alone, Woo then companion | Content works without required optional features; notices clear, no fatal error; activation changes audited | Theme lead/PO |
| UAT-02 | Editor builds EN and FA landing/catalog pages in Site Editor | Tokens/patterns behave, content translations distinct, no raw layout hacks; no fake trust badges | UX/native editor |
| UAT-03 | Leader proposes multi-stop tour and departure; agency approves | Structured days/legs/nights/costs, ownership checks, private prices hidden; approved immutable version | Tour operations |
| UAT-04 | Customer books last event seat before exact close | Login required; one atomic hold; trustworthy deadline; second customer denied; after-close request blocked | Events/QA |
| UAT-05 | Learner compares one open and one closed cohort | Course/cohort distinction, independent windows and venue/prerequisite fields; attendance permission scoped | Academy |
| UAT-06 | Customer pays through eligible gateway with duplicate callback | Verified amount/unit once; no ticket from redirect; paid pending confirmation clear, subsequent real confirmation | Finance/payments |
| UAT-07 | Callback arrives after released hold; operator resolves | No stock reclaim; owned exception and lawful refund/alternative consent; truthful customer message | Finance/operations |
| UAT-08 | Customer purchases physical variant with shipping, coupon, refund | Correct totals, stock and policy snapshot; physical/service cart mix rejected server-side | Retail/finance |
| UAT-09 | Partner switches org, revoked user attempts direct API/file/export | Correct current org, cross-org denial and audit; revoked user blocked immediately | Security/partner ops |
| UAT-10 | Customer sends stay/rental/passenger request in MVP | Explicit unconfirmed RFQ, request ID, no unsupported payable or issued inventory | Domain owners |
| UAT-11 | Keyboard/AT and FA user completes core tasks | Equivalent actions/errors, logical focus, bidi IDs, correct date/unit; no required motion/3D | A11y/native reviewer |
| UAT-12 | SRE/finance rehearse worker outage, restore and reconciliation | Inventory correct, no duplicated effects, provider world reconciled after backup cutover | SRE/finance |
| UAT-13 | Customer opens case, cancels, finance approves supported refund | Owned case, private notes hidden, approval vs actual refund distinct; notice delivery tracked | Support/finance |
| UAT-14 | Operator checks crypto and unlicensed supplier routes | Disabled frontend/backend; no live wallet instructions or invented issuance | Legal/security |

All customer paths run in EN/FA on narrow viewport and supported browser matrix. UAT participants and consent are recorded only when actual sessions occur. No fictional persona is counted as a participant or pass.

## 3. Go/no-go checklist

| Gate | Required evidence | Accountable signer | Current status |
|---|---|---|---|
| G-01 Commercial/scope | Merchant entity, markets, release scope, partner/service terms and staffing | Sponsor/PO | Pending |
| G-02 Legal/provider | Jurisdiction-specific qualified review, licenses/eligibility, gateway agreement and permitted units | Legal/finance | Pending |
| G-03 Money/inventory integrity | Boundary/race/duplicate/unknown/refund reconciliation tests pass | Booking lead/finance/QA | Pending |
| G-04 Security/privacy | Object authorization, secure uploads/secrets, threat review, retention and incident plan | Security/privacy | Pending |
| G-05 Accessibility/localization | Critical tasks pass manual/automated WCAG target review and native FA review | UX/a11y/LC | Pending |
| G-06 Compatibility/theme product | Version matrix, install/switch/update/no-plugin behavior, licenses/exports | Architecture/release | Pending |
| G-07 Reliability/support | Verified backup, witnessed restore, scheduler/alerts/rotas and rehearsed runbooks | SRE/operations | Pending |
| G-08 Content/trust | Approved localized prices/policies, real supplier claims only, provenance | Content/operations | Pending |
| G-09 UAT/defects | Results per script; no unresolved critical blocker; approved residual risks | PO/QA/release | Pending |

No production selling or live provider feature enabled by this document alone. Pending future modules remain server-disabled. A known optional-art limitation may be deferred by scope decision; a critical money/data/legal/core-task failure cannot be hidden by a design waiver.

## 4. Deployment, monitoring and rollback

Before cutover: announce internal window through authorized channels, capture backup/checksum/schema version, verify provider credential separation, test alert routing and verify payment/reconciliation queues clear or explicitly owned. Apply compatible migrations in staged order; deploy versioned packages; run non-destructive smoke checks for content/login/org scope/holds and gateway configuration. Any real-money smoke transaction requires actual merchant authorization and a documented reconciliation/refund plan; do not assume a sandbox validates production routing.

Pilot release caps traffic/offer capacity to staffed operations; monitor hold invariant, verification/unknown queue, errors, queue age, confirmations, notification delivery and support load. Compare to proposed budgets; measure real field behavior rather than announce achieved SLOs. Expand only after pilot issues are resolved.

Rollback trigger: unauthorized disclosure, oversell, wrong financial unit, payment integrity failure, fatal checkout or unrecoverable migration. Owner stops new writes/payment initiation as needed, preserves evidence, follows compatible rollback or forward-fix plan, reconciles all provider operations since cutover and validates records before reopening. Reverting code cannot undo a bank charge, supplier ticket or customer message. Customer communications state verified impact and next action.

## 5. Training, handover and maintenance

Handover includes: admin installation/configuration, editor/pattern guide, org permission guide, module lifecycle/fields, gateway setup without secrets in manuals, reconciliation/refund guide, operator checklists, emergency contact plan, exports/retention, backup restore, change/release procedure and ownership directory. Train customer support to explain “payment verified / confirmation pending,” closed registration, RFQ-only and unit labels.

Proposed first 30 days: daily staffed money/booking reconciliation and issue triage, weekly pilot UX/support review, prompt patch/security updates under tested compatibility procedure. Support/warranty duration, licensing/updater service, paid-extension renewal responsibility and maintenance fees are OPEN DECISION, not implied included promises. Every operational alert has a primary and backup human. Final acceptance records build hash, environment, signer/date, exceptions, training attendance and residual risks.

Quality self-assessment: completeness 4; specificity 4; consistency 4; value 4; WP feasibility 4; security/privacy 4; compliance 3; accessibility/localization 4; testability 4; traceability 4. Below-4 blocker: legal/vendor and actual release evidence pending. This document is ready for review as a launch plan; the application is not launch-ready merely because all documentation files exist.
