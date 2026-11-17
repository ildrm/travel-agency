# DOC-45 — Risk register, controls and decision governance

Purpose: make uncertainties actionable and define launch blockers. Prepared from perspectives: risk, security, finance, legal, operations, architecture, QA. Accountable reviewer: Executive Sponsor; control owners below. Dependencies: [decisions](DOC-06_TERMINOLOGY-RULES-DECISIONS.md), [architecture](DOC-19_SYSTEM-ARCHITECTURE-ADRS.md), [legal review](DOC-43_LEGAL-REGULATORY-REVIEW.md). Approval gate: controls evidenced, residual risk accepted by qualified accountable owner. Status: DRAFT; no risk has been formally accepted.

## 1. Method

Likelihood (L) and impact (I) are proposed ordinal 1–5 scores, not measured probabilities; inherent score L×I guides sequencing. Critical payment/data/inventory harm blocks launch regardless of score. “Open” means evidence/control is pending. A review item stays open when a plan is written; it closes only on recorded evidence and owner approval. Regulatory/provider constraints cannot be waived by a product manager.

## 2. Prioritized register

| Risk | Trigger and harm | L×I | Control / detection / recovery | Owner; evidence; status |
|---|---|---|---|---|
| RISK-001 Scope and package ambiguity | Theme brief becomes unbounded marketplace; unsupportable product | 5×4 | ADR-001/003/019; module/phase contract; change impact review | PO; approved SKU/MVP; Open |
| RISK-002 Oversell / expiry race | Concurrent last-seat or room-night allocation | 4×5 | Local transactions/unique constraints; serialized consume/release; invariant alert; exception/refund, never reclaim | Booking lead; TC-REG-002/003 and load report; Open |
| RISK-003 Unknown jurisdiction/licensing | Sale not permitted or mandatory disclosures absent | 4×5 | Market/service matrix; qualified review; feature/market OFF until clearance | Legal/Sponsor; DOC-43 evidence; Open launch blocker |
| RISK-004 Gateway assumptions | Amount/unit, callback, refund or paid-time semantics wrong | 4×5 | Real contract/sandbox; independent verify; exact unit adapter; unknown queue | Payments/finance; Scenario H, TC-PAY; Open blocker |
| RISK-005 Merchant/tax/partner liability | Wrong seller, tax, refund duty or unauthorized split | 4×5 | Single proposed merchant; terms/ledger mapping; no automatic payout; reconciliation | Finance/legal; contracts and accounting policy; Open blocker |
| RISK-006 Payment without fulfillment | Charged customer no room/ticket/confirmed service | 4×5 | Separate axes; supplier inquiry, manual owner and refund path; aged exception alert | Operations; Scenario F and reconciliation drill; Open |
| RISK-007 Cross-organization disclosure | Partner sees other costs/travelers/files via API/export/cache | 4×5 | Capability+resource policy everywhere, short-lived file access, deny-by-default | Security; TC-AUTH-001 suite and review; Open blocker |
| RISK-008 Version/extension incompatibility | Blocks/HPOS/editor/update breaks checkout | 4×4 | Pin tested matrix; feature-by-feature trials; staging upgrade; rollback | AR/QA; compatibility report; Open |
| RISK-009 Persian/a11y defects | Wrong date/unit/bidi or inaccessible core task | 4×4 | Native review, keyboard/AT and calendar tests, text fallback | LC/UX; EN/FA UAT/WCAG records; Open blocker for core task |
| RISK-010 Sensitive PII leakage/over-retention | Identity/health/guardian details exposed or retained unnecessarily | 4×5 | Minimize, private storage, encryption/access logging, approved per-field retention | Privacy/SE; data flow and retention approval; Open |
| RISK-011 Scheduler/outbox failure | Holds linger; money/notifications not reconciled | 4×4 | Deadline checked on access, reliable runner, dedupe/retry/dead-letter; age alerts | RE; worker outage/replay drill; Open |
| RISK-012 Restore/migration inconsistency | Restored DB omits real provider payments; duplicate effect | 3×5 | Backups/expand-contract migrations, post-restore inquiry and cutover journal | RE/finance; isolated restore + reconciliation; Open blocker |
| RISK-013 Operational coverage gap | Manual confirmation/support/emergency work exceeds staff | 4×4 | Pilot caps, named rota, service promises tied to staffed hours | Operations; rehearsal and workload baseline; Open |
| RISK-014 Supplier stale inventory | External stock changes without authoritative hold | 4×5 | RFQ MVP, contracted hold/expiry, stale indicators and reconciliation | IN/operations; provider capabilities evidence; Open P3 blocker |
| RISK-015 Refund/payout duplication | Retry or malicious operator moves money twice | 3×5 | Unique intent, dual review where needed, captured limit, status inquiry | Finance/SE; replay/tamper tests and statements; Open |
| RISK-016 Theme switch/data loss | Domain records tied to presentation or destructive uninstall | 3×5 | Companion persistence, explicit retention/export, non-destructive deactivation | EN; switch/uninstall/upgrade tests; Open |
| RISK-017 Visual/third-party IP | Assets/fonts/trademarks unlicensed or fictional place misrepresented | 3×4 | Provenance/license manifest, cultural review, demo labels, lightweight originals | CR/legal; rights/native review; Open |
| RISK-018 Performance/3D overhead | Heavy artwork/editor scripts impede booking on mobile | 4×3 | Budgets, lazy optional art, no WebGL dependency, low-data fallback | RE/UX; representative field/lab evidence; Open |
| RISK-019 Crypto activation without clearance | Ineligible method exposed / sanctions or custody risk | 3×5 | Front/backend OFF; no wallet instructions; per-asset/network gates | Finance/legal/SE; DOC-38/43, flag-denial test; Open, feature dormant |
| RISK-020 Documentation drift | IDs/states/fields disagree; automated builder follows wrong contract | 4×4 | Canonical ledgers, validation, traceability and cross-owner revision review | PO/AR/QA; manifest/link/ID audit; Open |

## 3. Risk-treatment workflow

WF-RISK-001: discover -> record impacted requirements/assets/users -> score uncertainty -> assign accountable owner and due gate -> select avoid/reduce/transfer/accept -> implement control -> collect independent evidence -> reviewer accepts residual risk -> monitor trigger. Reopen on version, provider, merchant, jurisdiction or material workflow change. Never erase historic accepted/expired risk decisions.

Risk record fields: stable ID; scenario; root cause; affected FR/NFR/ADR; inherent L/I and rationale; evidence confidence; treatment; owner/backup; milestone; detection metric; runbook; residual L/I; reviewer/date; review expiry; status. Personal contact details and confidential contract terms belong in controlled systems, not public documentation.

## 4. Decision ledger and audit requirements

DOC-06 is the canonical ADR index; this document tracks risk consequences. Each ADR is proposed until a human owner records evidence, choice, rejected alternatives, date and revisit trigger. Required technical review covers persistence/migration, lifecycle changes, permission boundaries, money/units, reliability and API compatibility. Required domain review covers actual fulfillment/cancellation obligations. Commercial urgency does not convert mocked integration into real capability.

Audit events minimally include event ID, UTC timestamp, actor type/id, effective org, resource/version, action/outcome, reason code and correlation/intent key. Sensitive old/new values are redacted or referenced securely, not copied wholesale. Correction is an appended compensating event; audit readers cannot edit audit history. Retention and access policy require privacy/legal review.

## 5. Release blockers and acceptance

MVP blockers: unresolved operating merchant/market/gateway eligibility; failed verification/conversion test; oversell; unauthorized disclosure; unrecoverable migration/backup; missing core accessibility/FA task; no staffed manual exception/refund process; critical security defect; unsubstantiated live supplier claim. Optional art or future integration unresolved can be disabled and deferred with documented scope change. A risk waiver must name actual approver, expiry and impacted behavior; this package records none.

Example fictional event: two callback retries produce two refund intents. Detection notices repeated payment/intent key; finance suspends automation, reconciles provider status, retains audit evidence and executes at most remaining approved refund. RISK-015 stays open until replay tests and provider statement reconciliation prove the fix. Customer receives one clear truthful update, not internal suspicion details.

Quality self-assessment: completeness 4; specificity 4; consistency 4; value 4; WP feasibility 4; security/privacy 4; compliance 3; accessibility/localization 4; testability 4; traceability 4. Below-4 blocker: qualified legal/provider evidence pending. This is a risk plan, not risk closure.
