# DOC-46 — Requirements and implementation traceability

Purpose: connect requested behavior to screen, persistence, workflow/API, verification, phase and accountable discipline. Prepared from perspectives: business analyst, architecture, domain owners, QA. Accountable reviewer: QA Lead with Product Owner. Dependencies: [SRS](DOC-07_BRD-SRS.md), [screens](DOC-11_UX-FLOWS-WIREFRAMES.md), [entities](DOC-22_DATA-MODEL-ERD-DICTIONARY.md), [APIs](DOC-26_APIS-INTEGRATION-CONTRACTS.md), [tests](DOC-41_QA-TEST-STRATEGY.md). Approval gate: no unmapped MVP requirement; implementation adds executable test/results without changing ID meaning. Status: DRAFT baseline; no passing test is implied.

## 1. Functional traceability

Each row is the minimum representative path, not exhaustive coverage of every permission/field. The owning document defines details and negative paths. IDs in this matrix refer to proposed specification artifacts; “manual” means a documented authorized operator workflow, never absent backend enforcement. Test conditions referenced below are specified in domain/QA docs; TC-TRACE entries are additionally defined in section 3.

| Requirement | Screen(s) | Entity / persistence | Workflow / API | Acceptance tests | Phase | Owner / specification |
|---|---|---|---|---|---|---|
| FR-ID-001 | UI-ID-001,UI-ACC-001 | ENT-USER,ENT-MEMBER,ENT-TRAVELER | WP auth, API-BOOK-002 | TC-AUTH-001,TC-TRACE-001 | MVP | Identity lead; DOC-23 |
| FR-CAT-001 | UI-CAT-001 | ENT-CATALOG,ENT-OFFER | API-CAT-001 | TC-API-003,TC-TRACE-002 | MVP | Catalog lead; DOC-10/18 |
| FR-TOUR-001 | UI-TOUR-001/002 | ENT-TOUR,ENT-DEPARTURE,ENT-STOP,ENT-LEG,ENT-ITINERARY | WF-TOUR-001,API-TOUR-001 | TC-TOUR-001/002,TC-REG-002 | MVP | Tour ops; DOC-29 Scenario C |
| FR-EVT-001 | UI-EVT-001/002 | ENT-EVENT,ENT-OCCURRENCE,ENT-HOLD,ENT-BOOKING | WF-REG-001,API-BOOK-001 | TC-REG-001/002/003,TC-EVT-001/002 | MVP | Events; DOC-30 Scenario A |
| FR-ACA-001 | UI-ACA-001/002/003 | ENT-COURSE,ENT-COHORT,ENT-ENROLLMENT,ENT-CERTIFICATE | WF-ACA-001,API-ACA-001 | TC-ACA-001/002,TC-REG-001 | MVP basic; P2 assessment | Academy; DOC-31 Scenario B |
| FR-STAY-001 | UI-STAY-001/002 | ENT-PROPERTY,ENT-UNITTYPE,ENT-UNIT,ENT-RATE,ENT-INVENTORY | WF-STAY-001,API-STAY-001 | TC-STAY-001/002/003,TC-TRACE-003 | MVP RFQ; P2 bookings | Stays; DOC-32 Scenario D |
| FR-AIR-001 | UI-TICKET-001 | ENT-QUOTE,ENT-BOOKING,ENT-TICKET | WF-TICKET-001,API-TICKET-001 | TC-TICKET-001/002/003,TC-TRACE-003 | MVP request; P3 issuance | Ticketing; DOC-33 Scenario F |
| FR-GROUND-001 | UI-TICKET-001 | ENT-QUOTE,ENT-TICKET,ENT-TRAVELER | WF-TICKET-001,API-TICKET-001 | TC-TICKET-001/002,TC-TRACE-003 | MVP request; P3 issuance | Rail/bus; DOC-33 |
| FR-XFER-001 | UI-XFER-001 | ENT-VEHICLE,ENT-QUOTE,ENT-BOOKING | API-XFER-001, manual dispatch only P2 | TC-XFER-001/002,TC-TRACE-003 | MVP request; P2 dispatch | Transport; DOC-34 Scenario E contrast |
| FR-RENT-001 | UI-RENT-001/002 | ENT-VEHICLE,ENT-RENTAL,ENT-INVENTORY | WF-RENT-001,API-RENT-001 | TC-RENT-001/002/003,TC-TRACE-003 | MVP request; P2 booking | Rentals; DOC-34 Scenario E |
| FR-MERCH-001 | UI-MERCH-001/002 | ENT-MERCH,ENT-RFQ,ENT-PRODUCTION,ENT-ORDER | WF-MERCH-001,Woo Store API,API-MERCH-001 | TC-MERCH-001/002/003/004 | MVP retail; P2 manufacture | Procurement; DOC-35 Scenario G |
| FR-PART-001 | UI-PART-001/002 | ENT-ORG,ENT-MEMBER,ENT-CONTRACT,ENT-RFQ,ENT-QUOTE | WF-PART-001,API-PART-001 | TC-AUTH-001,TC-PART-001/002/003 | MVP proposal; P2 contracts/RFQ | Partnerships; DOC-36 |
| FR-COM-001 | UI-COM-001 | ENT-ORDER,ENT-OFFER,ENT-BOOKING | Woo Store API/CRUD,API-BOOK-001 | TC-COM-001–006,TC-MERCH-001 | MVP | Woo architect; DOC-25 |
| FR-PAY-001 | UI-PAY-001 | ENT-PAYMENT,ENT-ORDER,ENT-OUTBOX | WF-PAY-001,API-PAY-001 | TC-PAY-001/002/003/004,TC-REG-003 | MVP verified provider only | Finance/payments; DOC-37 Scenario H |
| FR-FIN-001 | UI-FIN-001 | ENT-LEDGER,ENT-REFUND,ENT-PAYMENT,ENT-CONTRACT | WF-FIN-001,API-FIN-001 | TC-FIN-001/002/003/004 | MVP manual; P2 automation | Finance; DOC-39 |
| FR-SUP-001 | UI-SUP-001/002 | ENT-SUPPORT,ENT-NOTIFICATION,ENT-OUTBOX | WF-SUP-001,API-SUP-001 | TC-SUP-001/002/003/004 | MVP | Support; DOC-40 |
| FR-CMS-001 | UI-ADM-001 | ENT-CATALOG,ENT-OFFER,ENT-AUDIT | WordPress editor/revision,API-CAT-001 | TC-TRACE-002/004 | MVP | Content lead; DOC-18 |
| FR-I18N-001 | All public/account/admin customer notices | Localized catalog + message catalogs; UTC fields | Locale routing and rendering | TC-I18N-001,TC-TRACE-005 | MVP | Localization; DOC-17 |
| FR-THEME-001 | All public + editor + setup | Theme files/theme.json; durable entities in plugins | Install/update/theme switch, WP template resolution | TC-THEME-001–005 | MVP | Theme lead; DOC-20 |
| FR-ART-001 | UI-HOME-001,optional illustrations | Asset manifest/license, not booking persistence | Theme progressive enhancement | TC-TRACE-006 | MVP light art; Future 3D | Creative; DOC-14/15 |
| FR-CRYPTO-001 | UI-CRYPTO-001 staff-only; checkout absent | Disabled capability config/audit, no live wallet | API-CRYPTO-001 unavailable | TC-TRACE-007 | Future investigation, OFF | Finance/legal; DOC-38 |
| FR-DATA-001 | UI-FIN-001,authorized account/admin export | ENT-AUDIT,all retained domain data | Scoped export/deletion/migration runbooks | TC-AUTH-001,TC-OPS-001–006,TC-TRACE-008 | MVP | Privacy/AR; DOC-22/27 |

## 2. Nonfunctional traceability

| Requirement | Applicable artifacts | Minimum verification | Owner / release gate |
|---|---|---|---|
| NFR-SEC-001 | All UI/API/files/workers, ENT-MEMBER/AUDIT; DOC-23/27 | TC-AUTH-001–006, tampered callbacks, upload/code/security review | SE; no unresolved critical disclosure/financial flaw |
| NFR-PRIV-001 | ENT-TRAVELER/SUPPORT, exports/logs/backups; DOC-22/27/43 | TC-TRACE-008, data inventory/retention review, redaction checks | Privacy/legal; purpose/retention approved |
| NFR-A11Y-001 | UI components/flows/media; DOC-12/16 | Keyboard, AT, contrast/reflow/error review and automated scan | UX/a11y; critical tasks pass WCAG target |
| NFR-PERF-001 | Theme assets/search/checkouts; DOC-13/15/28 | Representative workload/lab+field measurements, optional media off | RE; proposed budgets reviewed, no benchmark claimed |
| NFR-REL-001 | ENT-OUTBOX/HOLD/PAYMENT, deployment/backup; DOC-28/47 | TC-OPS-001–006, worker outage, restore/reconciliation | RE/finance; witnessed restore and operational drill |
| NFR-LOC-001 | EN/FA pages/emails/vouchers/search; DOC-17 | TC-I18N-001,TC-TRACE-005, native-editor review | LC; equivalent core tasks |
| NFR-MAINT-001 | Theme/plugin packages, schema/version matrix; DOC-20/21/28 | TC-THEME-001–005, upgrade/rollback/HPOS/Blocks reports | AR; every claimed configuration tested |
| NFR-OBS-001 | ENT-AUDIT/OUTBOX/LEDGER and event dictionary; DOC-42/45 | Alert routing/redaction/replay; trace paid -> order -> booking | RE/finance; owned alerts and reconcile queue |
| NFR-CONC-001 | ENT-INVENTORY/HOLD,API-BOOK-001; DOC-24 | TC-REG-002/003, all-or-nothing quantities and failure injection | Booking/QA; zero oversells invariant |

## 3. Additional acceptance definitions

| Test | Setup / action | Required result |
|---|---|---|
| TC-TRACE-001 | User A requests B's booking/traveler profile and replays old session after revocation | Denied without private fields; audit safe; own account still usable |
| TC-TRACE-002 | Unpublished FA offering and confidential supplier net price exist; public catalog searched in EN/FA | Only approved localized public projection returned; no draft/private cost |
| TC-TRACE-003 | MVP stay/flight/rail/bus/transfer/rental request submitted; attempt to force payment/confirm endpoint | Request ID and honest unconfirmed message; backend refuses unsupported sellable booking |
| TC-TRACE-004 | Editor changes live cancellation terms after existing booking | New revision subject to approval; old purchased snapshot retained; change notice process invoked |
| TC-TRACE-005 | FA booking uses Persian digits, LTR reference, Jalali display and IANA-local deadline | Correct integer amount, explicit unit, preserved identifier/bidi, same UTC instant in EN/FA |
| TC-TRACE-006 | Disable JS/3D, reduce motion and load missing optional art on narrow screen | Core information and checkout remain usable, text alternative present, no layout-blocking overlay |
| TC-TRACE-007 | Fresh install, flag tamper, direct API request and stale checkout client request crypto | Unavailable server-side; no address, wallet or payment instructions; no activation without gate |
| TC-TRACE-008 | Customer asks export/deletion with open dispute and finance retention requirement | Identity checked; scoped export; legal hold/retention decision recorded; minimal retained record and accurate notice |

## 4. Matrix maintenance and coverage acceptance

For each new story attach one FR/NFR, owner, phase and positive/negative tests. Every screen action has authorization and an error outcome. Every persisted PII field has purpose/classification/retention; every integration has capability evidence and timeout/duplicate behavior. Every external claim needs source/retrieval date or an explicit verification gate. Inventory types require separate race evidence; passing seats does not prove room nights or rental intervals.

Machine validation checks file/ID presence and local links, not that semantics or implemented code are correct. The [validation record](_meta/VALIDATION.md) lists actual performed documentation checks. QA adds execution IDs, build hashes and defects later; absent results remain untested. An approved requirements revision updates affected rows and downstream docs together, never silently repurposes IDs.

Quality self-assessment: completeness 4; specificity 4; consistency 4; value 4; WP feasibility 4; security/privacy 4; compliance 3; accessibility/localization 4; testability 4; traceability 4. Below-4 blocker: legal/provider validation pending; release test evidence absent by design. Human semantic review still required.
