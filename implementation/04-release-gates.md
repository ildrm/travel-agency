# Release gates

Every gate requires actual command/build/environment evidence, not self-approval.

| Gate | Measurable acceptance | Evidence owner |
|---|---|---|
| Intake | All 49 DOC specs assigned full-text readers; recursive files hashed/parsed; unreadables listed; every baseline requirement mapped | Product/engineering |
| Install | Theme + each companion ZIP clean-installs/activates; missing optional dependencies no fatal; idempotent migrations; data survives deactivation/theme switch | Release/QA |
| Integrity | Exact half-open windows; zero oversell under tested simultaneous DB connections; duplicate payment no duplicate financial effect; released hold never reallocated; amount/currency tamper denied | Booking/finance |
| Authorization | Anonymous/private and cross-user/org reads/writes/files/exports denied; nonce never substitutes for permission | Security |
| Woo | Real installed Woo CRUD/HPOS and Blocks/Store API supported paths tested; cart policy enforced server-side; local simulator unavailable in production | Woo/QA |
| Domain | All feasible module workflows persist, enforce transitions, render functional UI/API and have positive/negative tests; remaining detailed features marked incomplete | Product/domain |
| Locale/a11y | EN/FA critical paths render lang/dir, localized status/units/time; keyboard labels/focus/reduced motion checked; native/editor/assistive audit separate | UX/native/a11y |
| Recovery | Backup restores isolated DB; consistency checked; worker retries and post-restore external reconciliation runbook | SRE/finance |
| Providers | Real current docs/contracts, merchant/supplier eligibility, authorized sandbox results and human approval before live flags | Providers/legal/finance |
| Packaging | Reproducible versioned installable archives, checksums, dependency inventory, secrets excluded | Release |

Large 100-request/peak-load tests are not run on the active user's computer without a controlled low-impact schedule. Their unexecuted status is a release gap, not a passing result. Public production deployment is not authorized by local implementation work.
