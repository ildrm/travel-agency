# DOC-28 — Infrastructure, DevOps and reliability

**Purpose:** Define environments, deployment, time-critical jobs, observability and recovery.  
**Prepared from perspectives:** SRE, WordPress, database, security, finance and operations.  
**Accountable reviewer:** Operations/SRE lead; security and product owners review.  
**Dependencies:** [architecture](DOC-19_SYSTEM-ARCHITECTURE-ADRS.md), [plugin lifecycle](DOC-21_CUSTOM-PLUGIN-ARCHITECTURE.md), [runbooks](DOC-47_OPERATIONS-RUNBOOKS.md).  
**Approval gate:** Restore drill, scheduler/failure tests and release evidence approved.  
**Status:** DRAFT specification; review pending.

## 1. Environments and operational ownership

Use isolated local, staging and production installations; staging uses fictional/anonymized data and provider sandbox credentials, never a copy of production secrets or protected traveler data by default. Maintain environment-specific domain, SMTP/SMS restrictions, credentials and payment enablement; prevent accidental real notifications or charges from staging. Production supports TLS, controlled admin access, patched WordPress/PHP/database and transactional custom tables. A designated operator owns every environment, database, domain, certificate, gateway, secret, job, backup and incident escalation.

**ASSUMPTION:** begin with a monitored WP/Woo monolith and managed database suitable for transactions; select provider/hosting region only after security, legal, data residency and recovery review. No assumed platform versions, uptime or hosting provider. PHP, WP, Woo and companion supported versions must be documented as a tested matrix at each release and upgraded within vendor support windows. No unverified third-party extension is a dependency.

## 2. Scheduler, transactions and job recovery

The server scheduler invokes the approved WordPress/Woo task runner on a reliable cadence; visitor-triggered WP-Cron alone is insufficient for booking deadlines. At the server-time deadline, new hold-related customer writes and payment initiation stop regardless of job lag. Allocation can remain unreleased until the serialized expiry transition; the late-verification exception rules in DOC-24 still apply. A worker acquires idempotent work with bounded lease/heartbeat, records attempt and next time, and handles expired leases. Retry only transient errors with capped backoff; route poison jobs or uncertain provider outcomes to a visible exception queue. Payment verification and external calls run outside DB transactions. Expiry and payment transition serialize on the same local hold/inventory rows as DOC-24 specifies.

Outbox rows are written in the same transaction as local state changes. Monitoring must cover scheduler heartbeat, oldest due job, retries, queue failures, expiring holds, verified-paid/unconfirmed bookings, provider reconciliation, email delivery and database contention. For a stopped scheduler, booking acceptance after close still fails on server clock; operators restore worker, reconcile missed jobs, and do not manually extend holds without approved audited exception.

## 3. Deployment and migrations

1. Build immutable versioned theme and companion artifacts; verify dependency inventory, license, checksums, code review and test results. Deploy exact artifact from staging, not manual production editing.
2. Run PHP/static checks, unit/domain tests, WordPress/Woo integration matrix, Blocks checkout and classic checkout only when declared supported, HPOS, concurrency, security, EN/FA, keyboard and regression checks. Maintain fixture inventory with reproducible settings; secrets never enter build output.
3. Take a restorable backup and check restore point; enable maintenance or write restrictions if the migration needs them. Preflight schema compatibility, database disk and worker readiness. Migration uses forward-compatible expand/backfill/verify/contract stages where practical; version and lock migrations.
4. Deploy and run smoke tests for login, catalog, hold boundary, approved test-mode payment, admin actions and queue processing. Monitor error/latency and reconcile payment/order/booking counts. Keep approved rollback artifact and trained operator on call.
5. If rollback would run old code against incompatible schema, do not blindly downgrade; use documented forward repair or coordinated restore, preserving new financial events and reconciling provider side effects. Communicate incident and change window according to runbook.

## 4. Availability, backup and proposed targets

**ADR-016 RECOMMENDATION, unmeasured:** propose 99.5% monthly availability for the public catalog, and a 15-minute scheduler lag alert threshold for initial planning; business must approve stricter/appropriate targets after measurement. Time-critical hold expiry cannot depend on that broad 15-minute operational threshold; define a separate tighter worker cadence and overdue hold alert after feasibility tests. No SLO implies guaranteed payment/provider uptime. Define eligible-service success and exclusions before measuring.

**Proposed, not tested:** daily encrypted backups plus more frequent transactional recovery where hosting supports it; target RPO 1 hour and RTO 4 hours for discussion. RPO/RTO require provider capability, cost approval and successful restore exercises. Backup WordPress database, custom tables, Woo data, media, deployment configuration and secrets recovery procedure without putting secrets in ordinary backups. Perform periodic restore in isolated environment; validate row counts, media, memberships, bookings, Woo orders, gateway references, outbox and a sampled order-to-payment reconciliation. A database-only restore without reconciling provider transactions risks duplicate charges or missing verified payments.

## 5. Observability and incidents

Emit structured privacy-minimized events with request/correlation ID, route, module, status, duration, job ID and release version. Avoid traveler names, card data, credentials and raw payloads. Track request failures, Core Web Vitals in privacy-approved aggregates, capacity conflicts, hold release delay, verified-payment exceptions, notification failures, external provider errors and backup restore success. Proposed thresholds become approved only after measurement; monitor both user-visible experience and background business outcomes.

For a gateway outage, disable affected method, preserve pending orders and reconcile unknown attempts when service returns; do not retry an unknown financial action without provider-safe status. For database contention, pause affected sales if oversell invariant cannot be guaranteed. For suspected breach, preserve evidence, contain, rotate affected credentials and engage security/legal per DOC-27. Assign primary, backup, escalation time and contact channels outside this document; do not invent a staffed on-call team.

## 6. Tests, example and acceptance

Fictional test: scheduler is stopped for 12 minutes while an event closes. New holds fail at close based on server time; delayed expiry processes once after scheduler resumes; a verified payment whose allocation was released enters manual exception. **TC-OPS-001:** stop/restart worker and verify no oversell or duplicate delivery. **TC-OPS-002:** interrupt migration and safely resume. **TC-OPS-003:** restore backup and reconcile external payment events after recovery. **TC-OPS-004:** deploy incompatible adapter to staging and confirm dependency warning/no service checkout. **TC-OPS-005:** trigger queue alert and use runbook to resolve with audit. **TC-OPS-006:** exercise theme and plugin artifact rollback with preserved orders.

**OPEN DECISION:** operating budget, actual measured traffic, hosting, alert thresholds, recovery objectives, database topology, provider SLAs and on-call ownership. Engineering and business must approve based on rehearsal and actual contracts.

## Review and evidence

All decisions are proposed, not approved; no implementation, operational measurement or supplier integration is implied. Accountable reviewers must record sign-off and evidence before release. Provider interfaces, compatibility versions and legal conclusions remain VERIFY BEFORE BUILD.

**Self-assessment, 0–5:** completeness 4 (document scope covered); specificity 4 (concrete contracts); consistency 4 (shared baseline); user value 4 (actionable decisions); implementation feasibility 4 (platform boundaries); security/privacy 4 (controls proposed); compliance 3 (qualified legal/provider verification outstanding); accessibility/localization 4 (cross-referenced standards); testability 4 (observable checks); traceability 4 (canonical IDs). A score of 3 is a release blocker until evidence and qualified review exist; see [risk register](DOC-45_RISKS-DECISIONS-CONTROLS.md). These are author estimates, not independent audit findings.
