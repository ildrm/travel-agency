# External dependency register

No live merchant/supplier credentials or legal/human approvals were supplied. These items gate production, not further safe coding.

| Dependency | Current behavior | Owner / exact enablement evidence |
|---|---|---|
| SEP/Saman merchant interface | Woo gateway hidden/unavailable; settings do not unlock payment | Finance + SEP: actual eligible merchant contract, dated endpoint/auth/unit/status/refund docs, credentials supplied through secret store, authorized sandbox/callback acceptance |
| Behpardakht Mellat merchant interface | Same fail-closed boundary | Finance + provider: terminal/API contract, units, secure verification/inquiry/refund semantics and real authorized acceptance |
| ZarinPal merchant interface | Same fail-closed boundary; no external request fabricated | Finance + provider: dated merchant API spec/account eligibility and sandbox acceptance |
| Passenger issuance/rail/bus | Request-only; no seat/PNR/ticket promised | Ticketing/procurement/legal: licensed supplier agreement, supported methods, holds/issuance/inquiry/change/refund, test environment and operating rights |
| Accommodation channel/PMS | Only locally controlled staff inventory exists; external availability unavailable | Stays/procurement: allotment/management contract, supplier-side holds/channel sync/reconciliation |
| Transfers/tracking | Unconfirmed requests; no actual live dispatch/tracking | Operations: operator agreements, staffing, consent and licensed tracking provider if selected |
| Rental handover/deposit/insurance | Local staff workflow; no assumed card authorization or regulated coverage | Operations/legal/finance: fleet/insurance/licence/age/deposit/claims contracts and local eligibility review |
| Email/SMS/maps/scanning | No guaranteed delivery, live map tiles or malware-scanned sensitive uploads | Operations/privacy/security: licensed account, approved data recipients/retention, sandbox delivery/scan evidence |
| Merchant/tax/jurisdiction | No production sale authorized by this implementation | Sponsor + qualified legal/tax/finance reviewers: actual entity/market/service matrix, seller/tax/refund/settlement decisions |
| Native Persian/legal copy | Generated EN/FA copy is available for review | Native editor + legal/finance: approved dated critical copy and equivalent-task checks |
| Brand/assets/3D | Original source SVG development concepts; no professional 3D claim | Creative/sponsor/rights reviewer: approved brand and provenance, professional asset/character/cultural acceptance |
| WCAG/security/performance/recovery | Local tests only, independent audits/production-like evidence pending | Independent a11y/security and SRE reviewers: actual audit, measured workload and witnessed restore/reconciliation |
| Digital assets | Always OFF; no wallet/address checkout | Qualified legal/finance/security/provider: per-jurisdiction asset/network/contract sanctions/AML/accounting/eligibility evidence, no promised activation |

Code gaps are listed separately in STATUS/NEXT_ACTIONS and feature traces. Missing code is not relabeled as an external dependency. The LOCAL simulator has no real external provider relationship and cannot satisfy any merchant evidence row.
