# DOC-47 — Operational runbooks

Purpose: specify safe daily operation, exception recovery and ownership. Prepared from perspectives: operations, support, SRE, finance, security/privacy. Accountable reviewer: Operations Lead. Dependencies: [infrastructure](DOC-28_INFRA-DEVOPS-RELIABILITY.md), [payments](DOC-37_PAYMENTS-DOMESTIC-GATEWAYS.md), [finance](DOC-39_FINANCE-PRICING-SETTLEMENTS.md), [support](DOC-40_SUPPORT-NOTIFICATIONS-CRM.md). Approval gate: named primary/backups rehearse in staging and record evidence. Status: DRAFT; commands depend on actual deployment and provider interfaces and are not fabricated here.

## 1. Common operating rules

Every run records incident/case ID, UTC start, operator/effective organization, affected object IDs, observed vs expected states, evidence reference, actions and next owner. Do not paste traveler documents, raw provider responses, card details or secrets into general tickets. Staff use MFA and least-privilege accounts. Production admin actions require allowed capability, expected record version, reason and audit event. No SQL edits to force confirmed states or delete failed payment evidence.

Severity proposal: SEV1 active money/data/inventory integrity or safety issue; SEV2 degraded checkout/operations with bounded impact; SEV3 individual service issue; SEV4 routine question. Actual response hours and on-call rota must be approved before launch; no invented 24/7 guarantee. Severity and communication clocks are separate from automatic resolution promises. Primary/backup contacts are held in controlled staff directory.

## 2. RB-01 Partner onboarding and publication

Trigger: organization application. Owner: partner operations; reviewer: authorized agency approver. Validate requested business identity/authority and minimal evidence against actual compliance checklist; assign organization and proposed contract, invite named staff with least privileges. Verify staff acceptance and active membership. Review property/tour/event/product proposal including localized content, availability source, net vs retail privacy, fees/exclusions and policies. Approve a version or return reasons. No partner publication bypass.

Failure/recovery: questionable identity -> keep suspended/pending, request secure evidence, no sellable offers. Missing supplier contract -> RFQ-only or unpublished. Membership revoked -> immediate server-side denial, retain historic audit and booking responsibility; reassign tasks explicitly. Evidence: agency approval, policy/version, authority and staff-scope test. Acceptance: another organization cannot access proposal/media/earnings.

## 3. RB-02 Daily booking and registration review

At start of staffed day, review open departures/occurrences/cohorts, expired holds, accepted paid bookings awaiting confirmation, supplier changes and capacity anomalies. Compare published capacity/window with approved version; verify UTC/local timezone and no silent changes to sold inventory. Review waitlist invitations against cutoff; after-close admissions require separate audited exception, still within capacity.

For paid pending confirmation, contact/inspect actual supplier or agency fulfillment evidence. Confirm only authorized commitments; issue accurate voucher/itinerary with policy snapshot. If not fulfillable, assign exception and refund/alternative consent path. An expired payment hold cannot be reactivated merely because a callback arrives. Materials/account history remain accessible as permitted after registration close. Evidence: booking timeline, confirmation authority, message delivery result and operator handover.

## 4. RB-03 Payment unknown, duplicate or amount mismatch

Trigger: customer return says pending, callback absent, timeout, duplicate, mismatch or aged reconciliation alert. Owner: payment operations/finance; consult integration engineer.

1. Look up immutable local attempt/order/hold linkage and expected integer amount/unit; authenticate caller without exposing provider secrets.
2. Inspect verified evidence and documented provider status inquiry when supported. Never ask customer to pay again solely because browser return failed. Do not blindly retry charge or initiate a second attempt while outcome unknown.
3. Compare merchant/reference/amount/currency and provider-paid time trust. Duplicate verified callback is a no-op with audit. Wrong unit/merchant/reference remains exception; do not mark verified from visual receipt alone.
4. Apply DOC-24 deadline/race policy. Released hold -> paid exception/refund; no inventory reclaim. Still allocated + trustworthy on-time payment -> paid_pending_confirmation, not automatic ticket issuance.
5. Reconcile Woo order through supported CRUD and local ledger/outbox with idempotent intent. Recheck after partial failure; avoid duplicate downstream actions.
6. Tell customer truthful state and next staffed update; assign owner/deadline. Resolve only with authoritative evidence or documented finance decision.

Evidence: redacted inquiry reference, unit conversion, reconciliation action and audit. Acceptance: duplicate replay has one effect, unknown never masquerades as success/failure, no customer money unowned.

## 5. RB-04 Refund, supplier payable and payout

Support receives cancellation with authorized booking; operations checks immutable policy and service state. Finance computes approved refund not exceeding captured less prior refunds and checks actual provider capability. If automated refund unsupported, use approved manual channel and record evidence; do not label an approval “refunded.” Separate approver from executor where policy/threshold requires. Retry ambiguous submission only after inquiry/reconciliation. On authoritative completion, record refund, update customer notice and reconcile Woo/ledger; retain original payment.

Supplier payable: match contract/version, fulfilled service or agreed milestones, invoice and inspection evidence. Partner sees only own approved statement. Finance reviews adjustments/disputes and executes authorized manual settlement; no assumed automatic bank split. Record actual bank/provider evidence and unique settlement intent. A repeated uploaded receipt cannot create a second payout. Ledger correction appends reversal/adjustment, never erases history.

## 6. RB-05 Worker outage and stuck holds

Owner: SRE with booking lead. Trigger: oldest outbox/reconciliation job threshold or scheduler health alert. Inspect runner health/clock, queue age, failed jobs and dependency health. Pause unsafe consumers if replay could duplicate money; keep read-only customer status available. Expired holds are invalid on transactional access even if cleanup delayed. Restore reliable runner, retry transient failures with bounded backoff, route poison jobs to owned exception. Reconcile provider state before replaying financial effects. Expiry/payment races lock same record; no bulk “release all” without eligibility checks.

Evidence: backlog before/after, deduplication/inventory invariant report, alert recovery and root cause. Acceptance: no oversell, duplicate notice or money effect; all dead-letter jobs assigned.

## 7. RB-06 Backup restore and deployment rollback

Owner: SRE; finance/operations approve money cutover. Declare incident; stop writes/payment initiation if necessary, preserve logs and out-of-band provider/bank evidence. Select backup by verified time/checksum and restore into isolated environment. Restore compatible application, schemas, media, settings and secret references through secure process. Do not send customer notifications from rehearsal.

Validate users/organization scopes, representative orders/bookings/ledger and inventory. Reconcile every provider operation after backup timestamp: the external world was not rolled back. Reapply verified payments/refunds through idempotent recovery workflow, not blind webhook replays. Check missed supplier issuance and notification events. Cut over only after authority approval and integrity checks; monitor closely. Expand/contract schema policy determines whether previous code is compatible; if not, forward-fix or restore coherent bundle under approved plan.

Proposed RPO/RTO are in DOC-28; actual achieved times require witnessed drill. Evidence: backup age, restore duration, integrity and reconciliation report, approver/time.

## 8. RB-07 Security/privacy incident and traveler safety

Security trigger: cross-org exposure, compromised account/secret, suspicious upload or financial tampering. Incident commander restricts affected feature/access, revokes credentials through secure process, preserves evidence, engages qualified security/privacy/legal reviewers and evaluates required notifications under applicable law. Do not invent notification deadlines. Scope impacted records and minimize further access; communicate verified facts via approved channel. Re-enable after containment, tests and authorization. Track remediation and retrospective.

Safety/service incident: assigned operations lead follows an approved local emergency/contact plan and licensed service responsibilities. Website support is not an emergency service. Notify affected travelers with factual localized instructions and contact options, protect location/health information and retain necessary incident evidence. Actual local contacts and legal duties must be supplied before selling relevant travel; this package invents none.

## 9. Rehearsal and closure

Rehearse duplicate callback, last-seat race with expired hold, refund ambiguity, queue outage, cross-org file attempt, restore and canceled departure in EN/FA. TC-OPS-001 scheduler; 002 restore; 003 rollback/migration; 004 reconciliation after restore; 005 notification replay; 006 incident escalation. Record actors, time, outcome/defect, handoff and follow-up. Open decisions: actual hours, escalation contacts, refund thresholds, retention and production dashboards. Quality self-assessment: completeness 4; specificity 4; consistency 4; value 4; WP feasibility 4; security/privacy 4; compliance 3; accessibility/localization 4; testability 4; traceability 4. Below-4 blocker: qualified duties/provider rules/staff coverage unverified (RISK-003/004/013).
