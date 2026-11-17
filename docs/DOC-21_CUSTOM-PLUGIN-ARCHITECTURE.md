# DOC-21 — Companion plugin architecture

**Purpose:** Define durable business-module boundaries, extension contracts and safe lifecycle.  
**Prepared from perspectives:** PHP, WooCommerce, database, integration and operations engineers.  
**Accountable reviewer:** Plugin lead; security and database leads review.  
**Dependencies:** [architecture](DOC-19_SYSTEM-ARCHITECTURE-ADRS.md), [data](DOC-22_DATA-MODEL-ERD-DICTIONARY.md), [API](DOC-26_APIS-INTEGRATION-CONTRACTS.md).  
**Approval gate:** Approve module owners, migrations and compatibility evidence.  
**Status:** DRAFT specification; review pending.

## 1. Boundaries and dependencies

**ADR-003 RECOMMENDATION:** ship one core companion with internal modules; split gateways and optional future integrations into separately installable adapters. The exact packaging is an open product decision. A module cannot infer authorization from a theme or UI. Core owns organization membership, audit, configuration, localization hooks, migrations and outbox; booking owns stock and hold lifecycle; catalog/tours/events/academy own content and domain rules; commerce translates domain offers to Woo products/order lines; payment adapter maps verified provider outcomes. Separate modules contain RFQ and other later-phase verticals. No component changes WordPress or Woo core.

| Module | Requires | Owns | Unavailable behavior |
|---|---|---|---|
| Core | WordPress | schema version, org membership, audit, outbox | Admin reports dependency; no domain endpoint accepts mutations. |
| Catalog and tours/events/academy | Core | offer definitions and publication, domain-specific metadata | Ordinary pages remain, book action disabled. |
| Booking | Core, database with transactional engine | capacity, holds, booking lifecycle | Fail closed; never take payment for an unallocated service. |
| Commerce | Booking, compatible Woo | item mapping and linkage | Preserve existing orders; service purchase disabled. |
| Gateway adapter | Woo, verified eligible provider account | adapter settings, callback verification | Other approved payment methods may remain; no fake success. |
| Partner/RFQ | Core | scoped proposals and requests | Agency operates manually; no unauthorized access. |

## 2. Illustrative source layout

```text
agency-core/
  agency-core.php       # metadata, dependency checks and bootstrap
  src/Domain/           # invariants; no output or direct provider calls
  src/Application/      # orchestration and idempotent use cases
  src/Infrastructure/   # repositories, WP hooks, scheduler and Woo adapter
  src/Rest/             # routes, validation and authorization
  migrations/           # ordered, versioned migrations and preflight
  assets/               # editor/dashboard sources and compiled assets
  languages/  tests/  readme.txt
agency-gateway-provider/ # optional provider-specific adapter
```

Define an explicit stable public contract: module service interfaces; versioned events with identifiers; documented hooks for extension of approved workflows. Hook consumers cannot change state without calling authorized application services. Functions declare argument types, expected capability context and errors. Avoid exposing raw repositories or unvalidated SQL as third-party API. Keep REST and server-to-server adapter code thin; all invariants must also hold for admin and background paths.

## 3. Storage, migration and lifecycle

Use Woo CRUD for Woo-owned data; custom relational tables for contention, relationships and operational records as DOC-22 specifies. A database preflight checks engine, encoding, disk space and backup before migrating. Maintain plugin code version and independent schema version. Migrations are ordered and idempotent, with a migration lock and recorded start/end/error. Breaking changes use expand → dual-read/write where justified → backfill → verify → contract, with a tested rollback or restore plan. Never assume an older binary can read a newer schema.

Activation initializes capabilities and queues migration once, but must not perform long backfills during a web request. Pending migration blocks affected writes with admin guidance. Deactivation stops new work without erasing orders, holds, audit or queues; scheduler can resume idempotently after reactivation. Uninstall defaults to preserving business and legal records. A separate explicit, authorized, audited retention/erasure process resolves outstanding balances and legal retention first; uninstall alone is not a privacy deletion workflow.

## 4. Events and integration discipline

**WF-PLUGIN-001:** application validates membership and request, opens short local transaction, updates owned rows and inserts an outbox event with a unique event ID. After commit, worker claims event, calls notification or provider using stable idempotency key where supported, records result and retries with bounded backoff. Never call provider while holding inventory locks. Timeout means unknown, so reconciliation precedes unsafe reattempt. Provider timestamps and signatures are validated by the actual contracted adapter, never guessed. See DOC-26 for envelope.

WordPress capability checks plus object and membership filters apply to REST, AJAX, admin screens, exports and worker-triggered state changes. Workers run with explicit service authority and validated action rather than inheriting a browser nonce. Sensitive logs exclude traveler identity, secrets and raw card data. Feature flags can suppress unapproved functionality but do not grant access.

## 5. Tests, examples and release gates

Fictional example: agency installs core and event module, sets 30 seats, and enables one eligible verified gateway only after provider approval. Updating the theme does not change enrollment; disabling gateway causes checkout to show no eligible payment route, not payment success. An expired hold is released once even if workers replay.

**TC-PLUGIN-001:** install, upgrade twice, interrupt and resume migration without duplicate effects. **TC-PLUGIN-002:** deactivate/reactivate around queued events, assert no duplicate notification or ledger mutation. **TC-PLUGIN-003:** Woo inactive or incompatible companion causes safe admin notice and no service order creation. **TC-PLUGIN-004:** theme switch preserves bookings and object authorization. **TC-PLUGIN-005:** simulate unknown network response and reconcile before reattempt. Automated integration tests run against each supported WP/Woo/PHP matrix combination; matrix remains VERIFY BEFORE BUILD.

**OPEN DECISION:** module distribution, dependency versions, schema portability, package signing and maintenance owners. [WordPress plugin handbook](https://developer.wordpress.org/plugins/) retrieved 2026-11-17. Supplier/provider agreements and current plugin compatibility are unverified.

## Review and evidence

All decisions are proposed, not approved; no implementation, operational measurement or supplier integration is implied. Accountable reviewers must record sign-off and evidence before release. Provider interfaces, compatibility versions and legal conclusions remain VERIFY BEFORE BUILD.

**Self-assessment, 0–5:** completeness 4 (document scope covered); specificity 4 (concrete contracts); consistency 4 (shared baseline); user value 4 (actionable decisions); implementation feasibility 4 (platform boundaries); security/privacy 4 (controls proposed); compliance 3 (qualified legal/provider verification outstanding); accessibility/localization 4 (cross-referenced standards); testability 4 (observable checks); traceability 4 (canonical IDs). A score of 3 is a release blocker until evidence and qualified review exist; see [risk register](DOC-45_RISKS-DECISIONS-CONTROLS.md). These are author estimates, not independent audit findings.
