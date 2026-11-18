# External dependency register

No live merchant/supplier credentials or legal/human approvals were supplied. These items gate production, not further safe coding.

| Dependency | Current behavior | Owner / exact enablement evidence |
|---|---|---|
| SEP/Saman merchant interface | Woo gateway hidden/unavailable; settings do not unlock payment | Finance + SEP: actual eligible merchant contract, dated endpoint/auth/unit/status/refund docs, credentials supplied through secret store, authorized sandbox/callback acceptance |
| Behpardakht Mellat merchant interface | Same fail-closed boundary | Finance + provider: terminal/API contract, units, secure verification/inquiry/refund semantics and real authorized acceptance |
| ZarinPal merchant interface | Prepared V4 request, independent verification and inquiry. The enabled fixture used fictional HTTP responses. Sandbox and live modes default off. | Finance + provider: verify the merchant-specific contract and eligible account, configure credentials through approved secrets, then record authorized sandbox acceptance followed by live approval. [V4 contract and evidence](API-CONTRACTS/ZARINPAL-V4.md) |
| Passenger issuance/rail/bus | Request-only; no seat/PNR/ticket promised | Ticketing/procurement/legal: licensed supplier agreement, supported methods, holds/issuance/inquiry/change/refund, test environment and operating rights |
| Accommodation channel/PMS | Local dated checkout and staff inventory. External channel availability remains unavailable. | Stays/procurement: allotment/management contract, supplier-side holds/channel sync/reconciliation |
| Transfers/tracking | Manual dispatch and driver allocation, including waiting and no-show controls. Live tracking depends on an approved service. | Operations: operator agreements, staffing, consent and licensed tracking provider if selected |
| Rental handover/deposit/insurance | Dated local rental, eligibility review, manual handover and return. | Operations/legal/finance: fleet/insurance/licence/age/deposit/claims contracts and local eligibility review |
| Email/SMS/maps/scanning | In-account delivery is implemented. Private documents remain quarantined unless a configured scanner succeeds. | Operations/privacy/security: licensed accounts, approved recipients and retention, native delivery and actual staging scanner acceptance |
| Merchant/tax/jurisdiction | No production sale authorized by this implementation | Sponsor + qualified legal/tax/finance reviewers: actual entity/market/service matrix, seller/tax/refund/settlement decisions |
| Native Persian/legal copy | Generated EN/FA copy is available for review | Native editor + legal/finance: approved dated critical copy and equivalent-task checks |
| Brand/assets/3D | Original SVG sources. DOC-15 treats 3D characters as an optional creative investment. | Creative/sponsor/rights reviewer: approved brand and provenance, and asset/character/cultural acceptance if commissioned |
| WCAG/security/performance/recovery | Local tests only, independent audits/production-like evidence pending | Independent a11y/security and SRE reviewers: actual audit, measured workload and witnessed restore/reconciliation |
| Digital assets | Always OFF; no wallet/address checkout | Qualified legal/finance/security/provider: per-jurisdiction asset/network/contract sanctions/AML/accounting/eligibility evidence, no promised activation |

Code gaps are listed separately in STATUS/NEXT_ACTIONS and feature traces. Missing code is not relabeled as an external dependency. The LOCAL simulator has no real external provider relationship and cannot satisfy any merchant evidence row.
