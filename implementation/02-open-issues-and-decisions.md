# Implementation decisions and open issues

Date: 2026-11-17. Source: implementation prompt §0/1/12, DOC-05/06/19/24/25/43/48. None of these records simulates business, merchant, legal or native-language approval.

| ID | Finding / resolution | Status / owner |
|---|---|---|
| IMP-001 | User now requests all feasible documented phases, beyond DOC-05 MVP. Build local domain workflows for later modules; retain live capability flags and contract gates. | Development scope selected; sponsor approves production scope |
| IMP-002 | Prompt suggested state names conflict with canonical DOC-06/24. Use canonical independent booking, allocation, payment, supplier and refund axes. | Resolved in ADR-IMP-001 |
| IMP-003 | PSP-AMOUNT-13 wording could erase a real charge on released hold. Preserve verified money; booking expired→exception; allocation stays released, no fulfillment or new admission. | Resolved per DOC-24; finance approval of live remedy pending |
| IMP-004 | No SEP/Mellat/ZarinPal merchant contract, credentials or current merchant schema supplied. Implement typed disabled boundaries; do not invent external endpoints or claim sandbox approval. | BLOCKED_EXTERNAL; finance/provider |
| IMP-005 | No licensed supplier/issuance/channel contracts. RFQ/request workflows plus explicitly simulated test adapters; no live seat/hotel guarantee. | BLOCKED_EXTERNAL; procurement/legal |
| IMP-006 | No approved brand, asset clearance or Persian legal/financial editor. Atlas codename and original development art only; qualified review required. | BLOCKED_EXTERNAL; sponsor/creative/native editor |
| IMP-007 | codebase-memory MCP transport closed. Repository has no application code at intake; direct file inventory/source reads used. Do not infer graph coverage. | Tool limitation; direct-source fallback |
| IMP-008 | Installed PHP 8.5.8 produces WP-CLI 2.12 vendor deprecations. Record warnings; do not edit vendor files or suppress runtime defects. Exact runtime support validated by actual downloaded WP/Woo tests. | Compatibility risk; engineering |
| IMP-009 | User actively uses this computer. No visible browser/system services; isolated socket-only DB with small buffer, one PHP server, sequential low-priority checks. Large concurrency/performance gates remain explicit if not safely executed. | Mandatory execution constraint |
| IMP-010 | No human roles are staffed/accepted; no jurisdiction, tax, merchant, retention, supplier, recovery or accessibility approval provided. | BLOCKED_EXTERNAL for production; DOC-43/48 owners |

Failures discovered during execution will be appended with exact reproduction and evidence; a planned fix is not a passing test.
