# ADR-IMP-001 — Implementable local boundaries and isolated runtime

Status: selected for development under user implementation authorization; production approvals pending.

Evidence: DOC-06 §3–5, DOC-19 theme/plugin boundary, DOC-24 deadline/expiry/verification policy, DOC-25 Woo authority, DOC-43 and DOC-48 launch gates; implementation prompt §0/4/5/12; user low-load/background-browser instruction.

Choose a block theme and three separately installable companions: atlas-core (transactional/auth/Woo), atlas-operations (domain workflows/UI), atlas-gateways (disabled providers + gated local simulator). This implements ADR-003's modular-plugin direction without empty per-domain shells. Public WordPress/Woo APIs are reused; no vendor modifications. Core stores scarce allocations in InnoDB custom tables and typed operational records in scoped tables, editorial content in CPTs and commerce in Woo CRUD.

Canonical documents control lifecycle names over illustrative names in the implementation prompt. On-time independently verified payment consumes unreleased allocation; late verification requires trusted timely provider paid_at; released allocation never reacquires, booking becomes exception, money stays verified. Local simulation is explicitly gated to local+demo environments and is not evidence of provider acceptance.

Use downloaded/pinned official WP/Woo in .runtime, an isolated MariaDB socket with low memory, no system service changes, and single-worker tests. Avoid Docker overhead and frontend bundling where native browser/WP globals suffice. An external provider boundary is not a completed live integration. Revisit on provider contract/version evidence, actual workload, distribution/license choice or multi-agency tenancy request.
