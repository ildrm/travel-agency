# Implementation status

Baseline: 2026-11-17. Overall **IN_PROGRESS**; full documented framework not complete. No production deployment or external approval.

Completed intake: 56 files under docs, 49 primary specifications, zero unreadable. Full-text team intake and 31 baseline FR/NFR mappings, 811 section/ID evidence rows exist. Original documentation preserved.

Local runtime installed from official sources: WordPress 7.1.4, WooCommerce 11.3.0, PHP 8.5.8, MariaDB 13.0.2. Socket-only DB with 32 MB buffer and loopback-only PHP; no system services or visible browser touched. Existing WP-CLI vendor emits PHP 8.5 deprecation warnings, retained in logs.

| Area | Status | Current evidence / gap |
|---|---|---|
| Core schema, roles/org ACL, offers | IMPLEMENTED | First integration checks pass; schema4 migration/new coverage pending rerun |
| Booking/time/payment | IN_PROGRESS | First run 16 passed/1 race-worker bootstrap failure; fix written, rerun pending |
| Theme/ENFA | IMPLEMENTED | Actual files and SVG sources; first browser found routing/a11y defects, fixes written and pending rerun |
| Woo bridge / checkout / HPOS | IN_PROGRESS | Real Woo installed, bridge and local simulator code; full browser/Store API purchase proof pending |
| Tours/events/Academy | IN_PROGRESS | Typed records, approval/itinerary and attendance/eligibility workflows; detailed coverage incomplete |
| Local stays/vehicle inventory | IMPLEMENTED | New staff-only transactional plugin; integration tests unrun |
| Passenger/transfer connectors | BLOCKED_EXTERNAL | Safe request paths; no live inventory/issuance/provider access |
| Procurement/partner/support/finance | IN_PROGRESS | Scoped forms/workflows and tests written; incomplete advanced coverage |
| SEP/Saman | BLOCKED_EXTERNAL | Disabled Woo boundary, no official merchant contract/credentials/sandbox proof |
| Mellat | BLOCKED_EXTERNAL | Disabled Woo boundary, no official merchant contract/credentials/sandbox proof |
| ZarinPal | BLOCKED_EXTERNAL | Disabled Woo boundary, no official merchant contract/credentials/sandbox proof |
| Crypto | BLOCKED_EXTERNAL | Forced OFF; no wallet/address/activation path |
| Security/a11y/performance/recovery | IN_PROGRESS | Source checks and first browser reports; independent/human/production-like evidence pending |
| Packaging/CI/operating evidence | IN_PROGRESS | Packaging/test scripts exist; clean-install archives and restore evidence pending |

Read TEST-EVIDENCE.md / RELEASE-READINESS.md when present for final executed results; this checkpoint will be updated after integration. No work continues after a final response unless another turn starts.
