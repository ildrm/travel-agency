# Feature coverage and implementation ownership

This baseline is IN_PROGRESS. A module is complete only after its full fields/workflows/ACL/UI/persistence/negative tests pass. The evidence-index JSON preserves per-document sections and IDs, including field/edge-case source rows, for detailed follow-up. Proposed test IDs are not executed test evidence.

| Requirement | Source | Ownership/code | Planned verification | Status |
|---|---|---|---|---|
| FR-ID-001 | docs/DOC-07_BRD-SRS.md:line 15 | wp-content/plugins/atlas-core | TC-AUTH-001,TC-TRACE-001 | IN_PROGRESS |
| FR-CAT-001 | docs/DOC-07_BRD-SRS.md:line 16 | wp-content/plugins/atlas-operations | TC-API-003,TC-TRACE-002 | IN_PROGRESS |
| FR-TOUR-001 | docs/DOC-07_BRD-SRS.md:line 17 | wp-content/plugins/atlas-operations | TC-TOUR-001/002,TC-REG-002 | IN_PROGRESS |
| FR-EVT-001 | docs/DOC-07_BRD-SRS.md:line 18 | wp-content/plugins/atlas-operations | TC-REG-001/002/003,TC-EVT-001/002 | IN_PROGRESS |
| FR-ACA-001 | docs/DOC-07_BRD-SRS.md:line 19 | wp-content/plugins/atlas-operations | TC-ACA-001/002,TC-REG-001 | IN_PROGRESS |
| FR-STAY-001 | docs/DOC-07_BRD-SRS.md:line 20 | wp-content/plugins/atlas-operations;wp-content/plugins/atlas-inventory | TC-STAY-001/002/003,TC-TRACE-003 | IN_PROGRESS |
| FR-AIR-001 | docs/DOC-07_BRD-SRS.md:line 21 | wp-content/plugins/atlas-operations | TC-TICKET-001/002/003,TC-TRACE-003 | IN_PROGRESS |
| FR-GROUND-001 | docs/DOC-07_BRD-SRS.md:line 22 | wp-content/plugins/atlas-operations | TC-TICKET-001/002,TC-TRACE-003 | IN_PROGRESS |
| FR-XFER-001 | docs/DOC-07_BRD-SRS.md:line 23 | wp-content/plugins/atlas-operations | TC-XFER-001/002,TC-TRACE-003 | IN_PROGRESS |
| FR-RENT-001 | docs/DOC-07_BRD-SRS.md:line 24 | wp-content/plugins/atlas-operations;wp-content/plugins/atlas-inventory | TC-RENT-001/002/003,TC-TRACE-003 | IN_PROGRESS |
| FR-MERCH-001 | docs/DOC-07_BRD-SRS.md:line 25 | wp-content/plugins/atlas-operations | TC-MERCH-001/002/003/004 | IN_PROGRESS |
| FR-PART-001 | docs/DOC-07_BRD-SRS.md:line 26 | wp-content/plugins/atlas-operations | TC-AUTH-001,TC-PART-001/002/003 | IN_PROGRESS |
| FR-COM-001 | docs/DOC-07_BRD-SRS.md:line 27 | wp-content/plugins/atlas-core | TC-COM-001–006,TC-MERCH-001 | IN_PROGRESS |
| FR-PAY-001 | docs/DOC-07_BRD-SRS.md:line 28 | wp-content/plugins/atlas-core;wp-content/plugins/atlas-gateways | TC-PAY-001/002/003/004,TC-REG-003 | IN_PROGRESS |
| FR-FIN-001 | docs/DOC-07_BRD-SRS.md:line 29 | wp-content/plugins/atlas-operations | TC-FIN-001/002/003/004 | IN_PROGRESS |
| FR-SUP-001 | docs/DOC-07_BRD-SRS.md:line 30 | wp-content/plugins/atlas-operations | TC-SUP-001/002/003/004 | IN_PROGRESS |
| FR-CMS-001 | docs/DOC-07_BRD-SRS.md:line 31 | wp-content/themes/atlas-leisure | TC-TRACE-002/004 | IN_PROGRESS |
| FR-I18N-001 | docs/DOC-07_BRD-SRS.md:line 32 | wp-content/themes/atlas-leisure | TC-I18N-001,TC-TRACE-005 | IN_PROGRESS |
| FR-THEME-001 | docs/DOC-07_BRD-SRS.md:line 33 | wp-content/themes/atlas-leisure | TC-THEME-001–005 | IN_PROGRESS |
| FR-ART-001 | docs/DOC-07_BRD-SRS.md:line 34 | wp-content/themes/atlas-leisure | TC-TRACE-006 | IN_PROGRESS |
| FR-CRYPTO-001 | docs/DOC-07_BRD-SRS.md:line 35 | wp-content/plugins/atlas-operations;wp-content/plugins/atlas-gateways | TC-TRACE-007 | IN_PROGRESS |
| FR-DATA-001 | docs/DOC-07_BRD-SRS.md:line 36 | wp-content/plugins/atlas-core | TC-AUTH-001,TC-OPS-001–006,TC-TRACE-008 | IN_PROGRESS |
| NFR-SEC-001 | docs/DOC-07_BRD-SRS.md:line 42 | wp-content/plugins/atlas-core |  | IN_PROGRESS |
| NFR-PRIV-001 | docs/DOC-07_BRD-SRS.md:line 43 | wp-content/plugins/atlas-core |  | IN_PROGRESS |
| NFR-A11Y-001 | docs/DOC-07_BRD-SRS.md:line 44 | wp-content/themes/atlas-leisure |  | IN_PROGRESS |
| NFR-PERF-001 | docs/DOC-07_BRD-SRS.md:line 45 | wp-content/themes/atlas-leisure |  | IN_PROGRESS |
| NFR-REL-001 | docs/DOC-07_BRD-SRS.md:line 46 | wp-content/plugins/atlas-core |  | IN_PROGRESS |
| NFR-LOC-001 | docs/DOC-07_BRD-SRS.md:line 47 | wp-content/themes/atlas-leisure |  | IN_PROGRESS |
| NFR-MAINT-001 | docs/DOC-07_BRD-SRS.md:line 48 | wp-content/themes/atlas-leisure |  | IN_PROGRESS |
| NFR-OBS-001 | docs/DOC-07_BRD-SRS.md:line 49 | wp-content/plugins/atlas-core |  | IN_PROGRESS |
| NFR-CONC-001 | docs/DOC-07_BRD-SRS.md:line 50 | wp-content/plugins/atlas-core |  | IN_PROGRESS |

Mini-RACI: module implementer owns code; Product Owner reviews scope and customer behavior; security reviewer reviews ACL/data; QA reviews actual evidence. Payments/finance require actual provider+finance+legal signoff; local inventory requires agency/supplier/operations signoff; art/locale requires brand/native editor; no human approvals are claimed. All requested disciplines are mapped in DOC-04 and must be represented in the final review.
