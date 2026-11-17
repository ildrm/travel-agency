# DOC-43 — Legal and regulatory review framework

**Purpose:** Prepare specific questions, evidence and approval gates for qualified counsel, payment providers and agency operators.  
**Prepared from perspectives:** Privacy, travel operations, payment, product, finance and security.  
**Accountable reviewer:** Qualified counsel in each relevant jurisdiction; business sponsor owns provision of evidence.  
**Dependencies:** [scope](DOC-05_SCOPE-MVP-ROADMAP.md), [payment](DOC-37_PAYMENTS-DOMESTIC-GATEWAYS.md), [crypto](DOC-38_CRYPTO-PAYMENT-COMPLIANCE.md), [privacy](DOC-27_SECURITY-PRIVACY-THREAT-MODEL.md).  
**Approval gate:** Jurisdiction-specific written advice, actual provider authorization and business acceptance are recorded before each affected feature or market goes live.  
**Status:** DRAFT review framework; review pending.

## 1. Status and limitations

This document is a framework for legal review, not legal advice or a determination of what a law permits. The brief identifies Iran-related domestic payments and possible international operations; it does not establish legal entity, establishment, customer/supplier locations, travel routes, merchant accounts, data hosting or governing law. No legal opinion, vendor agreement, license, identity verification standard or compliance certification has been supplied or approved. An agency’s intended roles and actual contracts determine applicable duties; product features cannot substitute for qualified advice.

**RECOMMENDATION:** Proposed agency is the single merchant of record for MVP, subject to actual contracting and counsel/finance/provider acceptance. Partners provide proposals or manually fulfilled services; no automatic split payment. The platform is a reusable theme plus companion plugins; distribution terms and bundled assets require separate IP/license review. No jurisdiction or law is presumed to permit cryptocurrency payments.

## 2. Jurisdiction and evidence intake

Before selecting requirements, business owner records legal entity and registration, operating and establishment locations, target customer locations, currencies, services, where tours start/end, supplier locations, staff/contractor locations, data hosting and processors, whether minors participate, merchant account, dispute venue, and all intended channels. Legal owner confirms which law and regulator apply to each combination and evidence date. Store the resulting determination and re-review triggers; do not infer it from UI language or visitor IP alone.

| Review topic | Exact request/evidence | Owner and gate |
|---|---|---|
| Agency/travel and transport | Provide corporate registrations, agency/operator/guide licenses, supplier contracts, route and activity list, insurance and emergency obligations; counsel map each activity/market and booking model. | Agency owner + local counsel; no live sale of a regulated service until required authorization evidenced. |
| Consumer terms and cancellation | Provide proposed booking terms, offer prices, exclusions, change/cancellation/refund notices, evidence of acceptance and complaint process in each language. | Counsel + product/support; approve required disclosures and operational refund policy. |
| Payment and merchant of record | Provide merchant contract, actual provider eligibility, verification/refund/settlement docs, unit rules, bank account ownership and chargeback process. | Finance + provider + counsel; enable only an eligible verified domestic gateway after approval and test. |
| Privacy and security | Provide data inventory by field/purpose, recipients, retention proposal, subprocessors/hosting regions, access controls, deletion/incident processes and cross-border flows. | Privacy counsel + security; approve notices, basis/consent and retention per jurisdiction before real data. |
| Minors, health and documents | Provide ages, guardian workflow, exact sensitive fields, need for collection and storage, venue requirements and emergency policy. | Counsel + operations; restrict collection and launch affected flows only after approval. |
| Financial/tax and settlements | Provide tax registrations, pricing invoices, currencies/rounding, partner contracts, commission versus net arrangements, ledger and payout process. | Finance + qualified local tax counsel; approve invoice, tax and settlement configuration. |
| Employment/partner and IP | Provide staffing/guide contracts, partner terms, membership delegation, design/font/media licenses, code and dependency licenses, distribution channel. | Sponsor + counsel; validate authority to use and distribute assets and software. |
| Accessibility and marketing | Provide UI/content, claims, consent model, accessibility evidence, messaging channels and target markets. | Accessibility lead + counsel; approve claims, required notices and contact opt-outs. |
| International travel and supplier distribution | Provide supplier/GDS agreement, routes, availability and ticketing authority, disruption/refund arrangements and cross-border payment facts. | Operations + counsel + provider; P3 only after licensed connectivity and approvals. |

For each approval record retain reviewer identity and qualification, jurisdiction, scope, effective date, documents reviewed, decision, constraints, expiration/review date and issue owner. An internal feature flag or AI-written checklist is not a legal opinion. Legal or provider restrictions override roadmap assumptions.

## 3. Digital-asset gating matrix

All entries below are **OFF in every phase**; this is a capability inventory for investigation only, not a recommendation, eligibility finding, list of supported assets or promise of activation. Exact network/asset identity and provider capability remain unverified.

| Asset | Network/identity requiring confirmation | Default | Evidence before any proposed change |
|---|---|---|---|
| BTC | Bitcoin network and provider-supported representation | OFF | Jurisdiction-specific written legal and sanctions/AML review, provider authorization and operating/accounting controls. |
| ETH | Ethereum network and verified asset identity | OFF | Same evidence, including custody, confirmation and refund terms. |
| USDT | Exact token contract and eligible network, e.g. Ethereum, TRON or TON as actually supported | OFF | Same evidence per network and contract; document network mismatch/reorg/underpayment handling. |
| TON | TON network and verified asset identity | OFF | Same evidence, provider support and custody determination. |
| TRX | TRON network and verified asset identity | OFF | Same evidence and operational risk approval. |
| Other | Specific asset, contract and network | OFF | Independently documented approval for each intended jurisdiction and use. |

If any use is prohibited or provider eligibility is absent, the method remains unavailable and no bypass is designed. Enabling any method would require separate product, security, finance and qualified legal signoffs, real processor agreement, documented geography/asset restrictions, tested accounting, consumer disclosures and rollback; ordinary gateway approval does not authorize digital assets.

## 4. Operational checkpoints and tests

**TC-LEGAL-001:** no domestic or international gateway is activated without recorded contract, provider eligibility and documented test evidence. **TC-LEGAL-002:** crypto feature flags and checkout eligibility remain OFF for all listed assets/networks in every default environment. **TC-LEGAL-003:** translated terms show approved version and capture acceptance without exposing confidential partner terms. **TC-LEGAL-004:** privacy request and legal hold workflow is reviewed with actual applicable retention rules; do not assume automated deletion is universally permitted. **TC-LEGAL-005:** P3 ticket issuance and P2 accommodation/rental features stay gated until supplier and licensing evidence exists.

WordPress provides tooling and developer guidance for personal data export/erasure, but using it does not itself establish legal compliance: [WordPress plugin privacy guidance](https://developer.wordpress.org/plugins/privacy/), retrieved 2026-11-17. An accessibility conformance target is described in [WCAG 2.2](https://www.w3.org/TR/WCAG22/), retrieved 2026-11-17; applicable legal obligations require local review.

**OPEN DECISION / REGULATORY REVIEW REQUIRED:** operating jurisdictions, entity and merchant model, provider agreements, licensing, taxes, privacy basis, retention and actual crypto eligibility are unknown. These are launch gates for affected operations, not instructions to delay drafting or invent facts.

## Review and evidence

This is a proposed specification, not evidence of execution, legal advice, independent audit, measured performance, or human approval. Provider details and applicable legal duties remain VERIFY BEFORE BUILD.

**Self-assessment, 0–5:** completeness 4 (document scope covered); specificity 4 (actionable examples); consistency 4 (shared baseline); user value 4 (decision support); implementation feasibility 4 (platform integration considered); security/privacy 4 (controls proposed); compliance 3 (jurisdiction and qualified review outstanding); accessibility/localization 4 (explicit tests or controls); testability 4 (observable acceptance); traceability 4 (shared IDs). The compliance score of 3 is a release blocker pending qualified review and evidence; see [risk register](DOC-45_RISKS-DECISIONS-CONTROLS.md). Scores are author estimates, not independent audit findings.
