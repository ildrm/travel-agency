# DOC-27 — Security, privacy and threat model

**Purpose:** Define controls, abuse scenarios and evidence required before handling real money or traveler data.  
**Prepared from perspectives:** Security, privacy, WordPress, payment and operations.  
**Accountable reviewer:** Security architect; privacy counsel and operations review.  
**Dependencies:** [identity](DOC-23_IDENTITY-RBAC-TENANCY.md), [API](DOC-26_APIS-INTEGRATION-CONTRACTS.md), [legal review](DOC-43_LEGAL-REGULATORY-REVIEW.md).  
**Approval gate:** Threat review, independent security testing, privacy and provider/legal signoffs.  
**Status:** DRAFT specification; review pending.

## 1. Assets, boundaries and data minimization

Protected assets include traveler and purchaser identity, partner contracts/net rates, booking and inventory integrity, payment references, admin credentials, audit trail and availability. Trust boundaries: browser to WP; WP to Woo/companion; worker to provider; agency user to partner organization; backups/observability to operations. A WordPress role or browser-provided org ID cannot establish object ownership. Direct infrastructure access remains privileged and is separately controlled and logged.

Classify data before collection. Collect only eligibility and service fields needed for the particular booking; a purchaser may be distinct from traveler. Passport, identity, medical or minor-related information requires documented purpose, restricted access, retention and qualified legal review; avoid storing uploaded documents by default. Explicitly separate confidential supplier costs from public itinerary responses. Use provider-hosted card entry where eligible; do not store card PAN, CVV or raw credentials in logs, metadata or tickets. Protect secrets in managed configuration outside source and exports, with role-limited access and rotation process.

## 2. Threats and control plan

| Threat / abuse | Primary control | Detection and recovery |
|---|---|---|
| Forged payment redirect or callback | Provider-specific authentication and independent status/amount/unit verification; idempotent transition | Unknown outcome queue, reconcile order/payment/provider; no automatic issuance. |
| Last-seat race or hold replay | Local transactional locks, quantity constraints, scoped idempotency | Compare inventory ledger and active holds; alert on invariant failure and stop sales. |
| Cross-organization access by edited ID | Capability plus object/membership policy on every surface | Negative tests and audit; suspend offending sessions, investigate. |
| CSRF, XSS or injection | CSRF protections, prepared queries, context-aware escaping, input validation | Static analysis, dynamic tests, patch and review evidence. |
| Compromised staff account | MFA for sensitive accounts, least privilege, short-lived delegated access | Alert on anomalous access/export, revoke session and credentials, incident response. |
| Fraudulent document upload | Avoid unless needed; allowlist MIME/size, scan and isolate, authorize every download | Quarantine and remove; audit access; never publicly expose protected media. |
| Tampered prices/currency or supplier term | Server-side quote, integer IRR and explicit conversion, immutable snapshot | Reconcile with provider and Woo totals, block mismatch for finance review. |
| Queue replay/poison event | Durable outbox, unique IDs, deduplication and bounded retries | Alert on lag/dead letters; replay under controlled runbook. |
| Dependency compromise or outdated extension | Maintained source, licensing/security review, pin and patch policy, sandbox testing | Inventory dependencies; disable unsafe integration and preserve data. |
| Backup disclosure or loss | Encrypted, access-controlled, tested off-site backup; least privilege | Rotate keys, restore drill, assess notice obligations with counsel. |

## 3. Implementation controls

All write paths (REST, Store API extensions, admin/AJAX, classic forms, cron/workers and imports) validate data, enforce capability and record scope, and audit sensitive actions. WordPress nonces help CSRF prevention but are not authentication, authorization or anti-replay. Use content security controls appropriate to actual providers, safe output escaping and structured parameterized SQL. For provider callbacks verify actual contract-defined authentication and perform status inquiry as supported; internet-origin and user-supplied headers alone are not proof. SSRF-sensitive URLs must use allowlisted destinations and block private network targets; no arbitrary remote fetch from partner input.

Establish role separation for financial approval, exports, partner approval and capability administration. Limit unredacted logs; correlation IDs instead of raw request bodies, short retention with legal signoff, and access logging for sensitive exports. Encrypt data in transit and backups; select at-rest encryption and keys based on environment and documented threat model. Guard public caches and search projections against private values and cross-user variation. WordPress/plugin/core patching includes timely security triage, staged regression, rollback artifact and ownership.

## 4. Privacy workflows and incident handling

Maintain field-level purpose, lawful basis/consent where applicable, access category, data location, processor and proposed retention record. Consent for optional notifications is independently revocable and distinct from required transactional messages; legal basis and jurisdiction need counsel review. Account access/deletion requests are identity-verified, tracked and scoped: legal financial records, security logs and active bookings may require different treatment. Deletion must propagate to backups according to approved retention design, respecting legal holds and technical limitations; communicate actual timelines only once approved.

Incident runbook: detect and declare severity; isolate affected integration, preserve evidence and avoid destroying logs; suspend sales or credentials where necessary; reconcile financial/booking impact; restore validated service; conduct legal notification assessment with counsel; document timeline, remediation owner and lessons. A compromise is never represented as a confirmed breach before investigation. See [operations runbooks](DOC-47_OPERATIONS-RUNBOOKS.md).

## 5. Tests and approval

**TC-SEC-001:** user A probes user B/partner records through REST, export, file, search and cache; no sensitive fields leak. **TC-SEC-002:** valid CSRF nonce without object capability fails. **TC-SEC-003:** forged and replayed provider callback cannot confirm or double-credit. **TC-SEC-004:** malicious upload, SQL/XSS input and SSRF targets are rejected in all surfaces. **TC-SEC-005:** secrets, passport-like values and card data absent from logs/notifications. **TC-SEC-006:** execute revoked-access and backup restore scenarios, then independent penetration and privacy review before production data.

**OPEN DECISION / REGULATORY REVIEW REQUIRED:** jurisdiction-specific retention, data transfer, consumer notice and identity verification; chosen provider controls and payment compliance. Do not claim certifications or legal compliance without evidence. [WordPress nonce security](https://developer.wordpress.org/apis/security/nonces/) retrieved 2026-11-17.

## Review and evidence

All decisions are proposed, not approved; no implementation, operational measurement or supplier integration is implied. Accountable reviewers must record sign-off and evidence before release. Provider interfaces, compatibility versions and legal conclusions remain VERIFY BEFORE BUILD.

**Self-assessment, 0–5:** completeness 4 (document scope covered); specificity 4 (concrete contracts); consistency 4 (shared baseline); user value 4 (actionable decisions); implementation feasibility 4 (platform boundaries); security/privacy 4 (controls proposed); compliance 3 (qualified legal/provider verification outstanding); accessibility/localization 4 (cross-referenced standards); testability 4 (observable checks); traceability 4 (canonical IDs). A score of 3 is a release blocker until evidence and qualified review exist; see [risk register](DOC-45_RISKS-DECISIONS-CONTROLS.md). These are author estimates, not independent audit findings.
