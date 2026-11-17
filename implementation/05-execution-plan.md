# Execution plan

1. Read/hash docs and map requirements; preserve source docs.
2. Establish isolated low-load WordPress/Woo/MariaDB runtime and repeatable seed/install scripts.
3. Implement core schema, org/resource ACL, typed records, audit/outbox and public-safe offer projections.
4. Implement booking/expiry/payment proof state machine and exact money; test race/boundaries.
5. Integrate Woo products, cart/order metadata, HPOS/Store API and local-only simulated checkout.
6. Implement theme templates/tokens/ENFA/RTL and safe companion absence, catalog/details/account/partner flows.
7. Implement tours/events/cohorts, approval/attendance/check-in/materials and permitted credentials workflows.
8. Implement stay/rental local models and request-only external transport, intervals, inspections and exception handling.
9. Implement manufacturers/RFQ/proofs/production, support/finance/notifications and scoped partner operations.
10. Add provider-specific disabled boundaries; negative contracts and production-off tests.
11. Run sequential real WP/Woo integration, headless ENFA browser checks, accessibility/static/security/recovery checks; fix failures.
12. Package deterministic theme/plugins, update trace/evidence/status/checkpoint and record exact remaining gaps.

Dependencies: 1→2/3/6; 3→4/7/8/9; 4→5/10; all integrated slices→11→12. Writers work on separate files; root schedules every build/test to honor low CPU. PHP shared interfaces are in SHARED-CONTRACT.md. Code ownership: core worker (atlas-core, core tests); domain worker (atlas-operations, atlas-gateways, domain/gateway tests); frontend worker (atlas-leisure, frontend checks); root (runtime/deploy/CI/evidence/acceptance and integration fixes). None may overwrite another writer's files without coordination.
