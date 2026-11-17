# DOC-04 — Stakeholders, delivery team and RACI

Purpose: assign decisions, authoring responsibility, review and operational ownership without implying staff have been hired. Prepared from perspectives: sponsor, program manager, product owner, engineering and operations. Accountable reviewer: Executive Sponsor. Dependencies: [vision](DOC-01_VISION-BUSINESS-MODEL.md). Approval gate: named humans accept each MVP accountability before implementation. Status: DRAFT staffing recommendation; all roles are analytic perspectives.

## 1. Role inventory and document ownership

Each role below corresponds to a requested specialist perspective. One person may cover several perspectives if competent. Ownership is responsibility for maintaining content, not proof of authorship by an actual specialist.

| Group | Perspectives covered | Maintains documents | Mandatory review |
|---|---|---|---|
| SP / executive | Executive Sponsor / Agency Owner | 01,04,05 | Product, finance, legal |
| PO / product | Product Owner; Chief Product Manager / Platform Product Lead; Senior Business Analyst & Requirements Engineer | 00,01,03,05,06,07,08,46 | Architecture, UX, operations, QA |
| DL / delivery | Program & Delivery Manager; Technical Project Manager / Scrum Lead; UAT & Release Manager | 04,44,48 | PO, engineering, operations |
| RS / research | Product Research Lead; Competitive/Market Intelligence Analyst; Travel & Tourism Domain Expert | 02,03 | PO and domain owner |
| OP / travel operations | Tour Operations Manager / Tour Leader SME; Event Producer / Conference Planner; Academy Director / Learning Operations Specialist | 09,29,30,31,47 | PO, finance, QA |
| DS / suppliers | Accommodation Distribution Specialist; Aviation/Ticketing Specialist; Rail & Bus Distribution Specialist; Ground Transport & Vehicle Rental Specialist; Merchandise & Manufacturing Procurement Specialist | 32,33,34,35 | Legal, integration, finance |
| CO / commercial | Commercial Partnerships / B2B Procurement Lead; Pricing, Revenue & Inventory Manager | 24,36,39 | Finance, legal, booking engineer |
| FI / finance | Finance & Treasury Controller; Risk, Fraud & Trust Lead | 37,38,39,45 | Legal, security, payments |
| CS / service | Customer Success / Support Operations Lead | 40,47 | Operations, privacy, PO |
| UX / experience | UX Researcher; Service Designer; Information Architect; Senior UX/Product Designer; Senior UI Designer & Design-System Engineer | 03,09,10,11,12 | Native localization, a11y, engineering |
| CR / creative | Creative Director / Brand Strategist; Motion & Interaction Designer; 2D Illustrator / Icon Designer; 3D Artist / Technical Artist; Character/Creature Concept Artist | 13,14,15 | PO, a11y, performance, rights review |
| LC / language/content | Content Strategist, UX Writer & SEO Lead; English/Persian Localization Lead & Native Persian Editor; Accessibility & Inclusive Design Specialist | 16,17,18 | UX, native editor, QA |
| AR / architecture | WordPress Solution Architect; WooCommerce Architect; Database Architect / Data Engineer | 19,21,22,25,26 | Security, booking, DevOps |
| EN / implementation | WordPress Theme / Gutenberg Engineer; PHP & Custom Plugin Lead; React / JavaScript Frontend Engineer; Booking Engine / Concurrency Engineer | 20,21,24 | AR, UX, QA |
| IN / integration | Third-Party Integration Engineer; Payment Gateway Integration Engineer | 26,33,37 | AR, finance, legal, QA |
| SE / safeguards | Cybersecurity Architect / Penetration Tester; Privacy / Data Protection Expert; Legal & Regulatory Counsel (qualified local review) | 23,27,38,43,45 | Sponsor and affected domain owner |
| RE / reliability | DevOps / Platform / Site Reliability Engineer; Performance Engineer | 28,47 | Security, booking, QA |
| QA / assurance | QA & Test Automation Lead | 41,46,48 | Independent domain owners |
| AN / data | Analytics & Experimentation Lead | 42 | Privacy, finance, PO |

Document-specific Accountable reviewer headers determine final document acceptance. Cross-owned documents have a primary maintainer appointed by DL; a file cannot have conflicting approved copies. A supplier representative can confirm its interface/terms but cannot approve the agency's risk policy.

## 2. Major-feature RACI

R = performs work; A = one accountable decision owner; C = consulted before acceptance; I = informed. The abbreviated team below consolidates disciplines: Engineering includes AR/EN/IN/RE; Operations includes OP/DS/CO/CS; Safeguards includes SE. Finance is independent of engineering where money changes.

| Area | A | R | C | I |
|---|---|---|---|---|
| Product package, phase boundary | Sponsor | PO,DL | Engineering,Operations,Finance,SE | All team |
| Research / competitor evidence | PO | RS | Domain,UX,SE | Sponsor |
| Theme/templates/editor | Engineering lead | EN,UX | LC,QA,RE | PO |
| Tokens/art/motion | UX lead | UX,CR | LC,RE,SE | Engineering |
| Identity / org membership | Engineering lead | EN | SE,Operations,QA | PO |
| Tours / itineraries | Operations lead | OP,EN | PO,CO,QA | CS |
| Events / registration | Operations lead | OP,EN | QA,LC,Finance | CS |
| Academy/cohorts | Academy owner | OP,EN | QA,SE,LC | CS |
| Stays / inventory | Operations lead | DS,EN | CO,IN,QA | PO |
| Passenger issuance | Operations lead | DS,IN | Legal,Finance,QA | CS |
| Transfers/rentals | Operations lead | DS,EN | Legal,Finance,QA | CS |
| Merchandise/manufacturing | Commercial lead | DS,EN | Finance,Legal,QA | PO |
| Partner contracts/RFQ | Commercial lead | CO | Legal,Finance,SE | Operations |
| Checkout/tax/shipping | Finance lead | EN,IN | Legal,QA,UX | PO |
| Gateway activation | Finance lead | IN | Legal,SE,QA,Operations | Sponsor |
| Refund approval | Finance lead | Finance operator | CS,Operations,Legal | Customer via authorized notices |
| Supplier payout approval | Finance lead | Finance operator | CO,SE | Partner via statement |
| Privacy and retention | Sponsor | Privacy owner | Legal,Engineering,Operations | Support |
| Security incident | Incident commander | RE,SE | Legal,Operations,Finance | Sponsor |
| Accessibility and EN/FA | PO | LC,UX,EN | QA,CS | Sponsor |
| Analytics/reporting | PO | AN | Privacy,Finance | Operations |
| Release/rollback | Release manager | EN,RE,QA | PO,Finance,Operations,SE | Sponsor |

## 3. Staffing recommendation

Lean MVP: PO/business analyst (1), WP/Woo technical lead (1), PHP/booking engineer (1), theme/frontend engineer (1), UX/UI with localization coordination (1), QA/release engineer (1), agency operations/customer service (1–2). Fractional finance, native Persian editor, legal, security and DevOps specialists are still required for relevant acceptance. These are proposed skill allocations, not a hiring quote or measured capacity. A single engineer does not independently approve their own payment/security work.

Growth: separate product/delivery, UX/research/a11y, platform/plugin, frontend/theme, integrations, QA automation, SRE, partner operations and finance. Enterprise: dedicated domain squads and security/privacy functions only when volume and scope justify coordination cost. Do not require hiring every title in the original brief.

## 4. Governance and review protocol

Change request: owner records affected FR/ADR/entities/APIs/screens/tests and phase; technical and domain reviewers assess impact; PO prioritizes; legal/finance accepts its own required evidence; DL records approved revision and release. High-risk decisions cannot be approved by majority vote over a required qualified reviewer.

Weekly proposed decision review addresses unowned assumptions, provider uncertainty, security findings and customer money exceptions. Daily pilot reconciliation assigns an owner to every captured/unconfirmed transaction. Incident escalation has named primary and backup contacts stored privately, not invented in documents. For emergencies, operations defines actual staffed coverage before publishing a service promise.

## 5. Acceptance and open assumptions

AC-TEAM-01 every MVP feature has exactly one accountable role and a named human before implementation commitment. AC-TEAM-02 finance-changing action has approval evidence. AC-TEAM-03 after-hours operator/contact responsibility exists before selling travel. AC-TEAM-04 lean consolidation retains independent review of security, money and legal gates. Unknown budget/headcount remain OPEN DECISION owned by Sponsor; estimates in DOC-44 assume this lean team.

Quality self-assessment: completeness 4; specificity 4; consistency 4; value 4; WP feasibility 4; security/privacy 4; compliance 4; accessibility/localization 4; testability 4; traceability 4. Review limitation: no actual staffing acceptance; RISK-013 tracks capacity and operational coverage.
