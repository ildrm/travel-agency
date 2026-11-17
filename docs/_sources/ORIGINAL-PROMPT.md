# SUPER-MEGA MASTER PROMPT
## Comprehensive Project Documentation Generator — WordPress + WooCommerce Travel, Leisure, Events, Academy & B2B Platform

**Target model:** `gpt-6.1-sol`  
**Reasoning configuration:** `reasoning.effort = high`  
**Requested task:** Generate a complete, internally consistent, evidence-aware, implementation-ready multi-document specification and design system. Do not build the website in this assignment.  
**Default documentation language:** English; include Persian translations and RTL-specific artifacts wherever required.  
**Project codename:** Atlas Leisure Ecosystem (placeholder; never assume this is the approved brand).

---

## A. MISSION, OPERATING RULES AND DEFINITION OF DONE

You are an **integrated, cross-functional product research, design, architecture, engineering, compliance and quality-assurance council** tasked with producing the complete project documentation for a sophisticated **travel and leisure agency website and B2B platform**. Simulate the *perspectives and rigorous reviews* of the specialist roles below; do not misrepresent simulated roles as real people, external research you did not perform, or tools you do not have.

The project SHALL be built on **WordPress** with a **customizable, production-grade theme compatible with WooCommerce**. WooCommerce SHALL support checkout, orders, payments, and applicable customer commerce operations. Extensive domain-specific functionality may require well-architected **custom WordPress plugins** and integrations. The project includes tours, itineraries, lodging, events, in-person Academy courses, transport ticketing, vehicle rentals, souvenirs, partner collaboration, agency operations, customer accounts, and multilingual presentation.

**Deliver a true requirements and implementation blueprint, not a marketing proposal or generic feature wishlist.** Produce explicit data fields, workflows, alternate paths, UI states, component specifications, APIs, persistence, role permissions, acceptance tests, failure handling, risks, dependencies, and release decisions. Make the package suitable for handoff to designers, engineers, QA, operators, and decision makers.

### Non-negotiable method

1. **Self-contained context:** Use the business brief in Section C as the authoritative input. Do not assume access to prior chat messages, websites, or files unless actually supplied or accessible.
2. **Research honesty:** If live browsing or source inspection is available, verify current WordPress/WooCommerce interfaces, relevant plugin compatibility, PSP/gateway documentation, regulations, and provider capabilities against primary sources, showing URL and retrieval date. If browsing is unavailable, explicitly label facts as `VERIFY BEFORE BUILD` and provide exact validation steps. Never invent vendor APIs, endorsements, commercial partnerships, laws, prices, software versions, plugin compatibility, market figures, or research participants.
3. **Separate fact from proposal:** Mark every significant claim `CONFIRMED`, `ASSUMPTION`, `RECOMMENDATION`, `DEPENDENCY`, `OPEN DECISION`, or `REGULATORY REVIEW REQUIRED`. Maintain an assumptions/decisions ledger.
4. **No blocking questions:** Use reasonable, conservative defaults; record open decisions in a backlog with an owner and implication. Do not ask for clarifications before producing useful documentation.
5. **Exhaustiveness with prioritization:** Cover all specified business modules, but distinguish **MVP**, **phase 2**, **phase 3**, and **future/investigation**. Rank by customer value, operational risk, external dependencies, and implementation complexity. Do not imply every proposed feature exists or is immediately practical.
6. **Cross-document consistency:** Establish IDs for requirements (`FR-...`), nonfunctional requirements (`NFR-...`), data entities (`ENT-...`), workflows (`WF-...`), UI screens (`UI-...`), API contracts (`API-...`), test cases (`TC-...`), risks (`RISK-...`), and decisions (`ADR-...`). Cross-reference IDs everywhere. Never assign incompatible names or states across documents.
7. **Commerce correctness:** Payment success, supplier booking confirmation, fulfillment, refund, and payout are separate states. Never issue a confirmed travel ticket based solely on a payment redirect. Verify inventory and entitlements server-side; implement idempotency, locking/transactional holds, reconciliation and exception recovery.
8. **Security and legality first:** Do not document cryptocurrency payment activation for domestic transactions where prohibited. Treat all cryptocurrency integrations as **disabled by default** and subject to current legal, financial, sanctions, AML/KYC and payment-provider eligibility review. Do not provide evasion or circumvention tactics. Protect customer and partner data.
9. **Theme/plugin boundary:** Put styling, templates, patterns, brand treatments and visual presentation in the **theme**. Put durable domain logic, records, integrations, reservations, payments, permissions and operational workflows in **plugins/services**. Do not modify WordPress core.
10. **Implementation realism:** Prefer official WordPress/WooCommerce APIs and verified maintained extensions when fit for purpose. Evaluate build/buy/hybrid explicitly. Complex airline, rail and bus ticketing requires licensed supplier or aggregator connectivity; never promise inventory, seats or issuance without such connectivity.
11. **Design realism:** Include comprehensive 2D/3D/fantasy-character art direction as an optional, coherent brand layer. It must improve storytelling and wayfinding without hindering credibility, accessibility, checkout completion, site speed, or content clarity.
12. **Formatting:** Produce a navigable documentation package with a master manifest, executive summary, linked section IDs, Markdown tables, Mermaid architecture and workflow diagrams, textual wireframes, data dictionaries, endpoint examples, Gherkin scenarios, decision logs, handoff checklists, and appendices. Show examples with clearly labeled fictional data.
13. **Output management:** This project exceeds the capacity of a single concise response. First produce the **complete document manifest, dependency map, cross-module conceptual model and highest-priority foundational documents**. Continue producing full documents in ordered installments until all are complete, if the environment allows it. Never substitute a table of contents for an actual document. If forced to stop by output limits, end at a stable document boundary, update a `COMPLETION MANIFEST` with complete/partial/pending document IDs, and print exactly: `NEXT: Continue from DOC-XX, preserving IDs and the decisions ledger.` When subsequently given `CONTINUE`, resume without repeating completed material.
14. **No phantom deliverables:** Never say a file, prototype, codebase, interview, benchmark, screenshot, Figma project, or audit was created unless it actually was. If export tools are available, save separate files and present the exact paths; otherwise output complete copyable document content.
15. **Quality gate:** Before each installment, do a silent multidisciplinary consistency check: scope, legal risks, dependencies, role permissions, lifecycle and edge cases, localization, a11y, security, tests, observability, cost and performance. Make meaningful conflicts and resolutions explicit.
16. **Optional existing artifacts:** If the user also provides earlier feature-backlog spreadsheets, actual website URL/screenshots, brand assets, business contracts, gateway documents or research files, ingest and reference them before finalizing the documentation. Treat unverifiable previous assertions as inputs needing verification, not as confirmed capabilities. If no URL or project file is supplied, do not claim to have audited an existing website.

---

## B. MULTIDISCIPLINARY TEAM: ASSIGN RESPONSIBILITY AND REVIEW

Convene these roles as distinct *analytic lenses*. State responsibilities, their document ownership, main decisions, and mandatory reviewers. These are disciplines, not a demand to hire every title separately; provide a staffing/consolidation and RACI recommendation for lean, growth, and enterprise delivery teams.

### 1. Product, strategy, commercial and research

- **Executive Sponsor / Agency Owner:** Business outcome, funding and final risk acceptance.
- **Product Owner:** Scope priority, acceptance and backlog decisions.
- **Chief Product Manager / Platform Product Lead:** Product strategy, economics, roadmap and KPIs.
- **Program & Delivery Manager:** Milestones, dependencies, resources, governance.
- **Technical Project Manager / Scrum Lead:** Work breakdown, release coordination, agile rituals.
- **Product Research Lead:** Research plan, evidence hierarchy, competitor comparisons, hypotheses.
- **Competitive/Market Intelligence Analyst:** Market categories, verified comparison matrices and differentiation.
- **Senior Business Analyst & Requirements Engineer:** BRD, functional requirements, business rules, user stories, traceability.
- **Travel & Tourism Domain Expert:** Tour operations, suppliers, itineraries and travel-specific constraints.
- **Commercial Partnerships / B2B Procurement Lead:** Contract models, onboarding, RFQ and supplier economics.
- **Pricing, Revenue & Inventory Manager:** Fare and rate models, allotments, discounts, yield and overbooking.
- **Finance & Treasury Controller:** Merchant of record, accounting entries, reconciliation, payouts, FX, refunds and cashflows.
- **Risk, Fraud & Trust Lead:** Fraud prevention, chargebacks, identity safeguards and abuse cases.

### 2. Domain owners and service operations

- **Tour Operations Manager / Tour Leader SME:** Multi-stop tours, departure execution, trip safety and guide portal.
- **Event Producer / Conference Planner:** Event catalog, registration, seating, agendas, check-in and supplier services.
- **Academy Director / Learning Operations Specialist:** Course catalog, cohorts, prerequisites, enrollment, assessments, credentials.
- **Accommodation Distribution Specialist:** Properties, units, rate plans, inventory, allocations and hotel-channel connectivity.
- **Aviation/Ticketing Specialist:** Airline distribution, fares, ticket issuance, revalidation and disruptions.
- **Rail & Bus Distribution Specialist:** Schedules, operator seat data, ticketing and changes.
- **Ground Transport & Vehicle Rental Specialist:** Taxi/chauffeur transfers; rentals, fleet, deposits, licensing and damages.
- **Merchandise & Manufacturing Procurement Specialist:** Souvenir catalogs, custom artwork, MOQ, quotations, wholesale and fulfillment.
- **Customer Success / Support Operations Lead:** Contacts, disputes, complaints, customer communications and emergency escalation.

### 3. Experience, content and creative production

- **UX Researcher:** Personas, accessibility needs, research plans, task testing and evidence.
- **Service Designer:** End-to-end customer/partner journeys and operational service blueprints.
- **Information Architect:** Taxonomy, navigation, sitemap, search, labels and information retrieval.
- **Senior UX/Product Designer:** Task flows, forms, states, mobile usability and prototyping specs.
- **Senior UI Designer & Design-System Engineer:** Tokens, layout, components, design consistency and responsive rules.
- **Creative Director / Brand Strategist:** Brand positioning, art direction and trust/experience balance.
- **Motion & Interaction Designer:** Animation language, microinteractions, state transitions and reduced-motion variants.
- **2D Illustrator / Icon Designer:** Destination art, maps, spot illustrations, iconography and export guides.
- **3D Artist / Technical Artist:** Models, materials, lighting, motion, optimization and progressive enhancement.
- **Character/Creature Concept Artist:** Original fantasy mascots, character turnarounds, costumes, cultural review and usage constraints.
- **Accessibility & Inclusive Design Specialist:** WCAG 2.2 AA, keyboard, screen reader, cognitive accessibility and a11y QA.
- **Content Strategist, UX Writer & SEO Lead:** Microcopy, tone, editorial workflows, structured content and discoverability.
- **English/Persian Localization Lead & Native Persian Editor:** Bidirectional UX, Persian typesetting, translation memory, numeral and calendar rules.

### 4. Software, integrations, security and infrastructure

- **WordPress Solution Architect:** Theme/plugin boundaries, content models, extensibility and platform lifecycle.
- **WooCommerce Architect:** Products, orders, HPOS, Cart & Checkout Blocks, Store API, taxes and payment flow.
- **WordPress Theme / Gutenberg Engineer:** `theme.json`, block patterns, templates, responsive frontend.
- **PHP & Custom Plugin Lead:** Business modules, services, permissions, background workers, WP APIs.
- **React / JavaScript Frontend Engineer:** Interactive dashboards, booking builder, calendars, maps and checkout UX.
- **Booking Engine / Concurrency Engineer:** Seat/room/vehicle holds, state machines, expiration, idempotency and race prevention.
- **Third-Party Integration Engineer:** Airline, train, bus, hotel channel manager, maps, notifications and partner APIs.
- **Payment Gateway Integration Engineer:** SEP/Saman, Behpardakht Mellat, ZarinPal and conditional compliant crypto adapters.
- **Database Architect / Data Engineer:** Relational model, tenant boundaries, indexes, migration, reporting and retention.
- **Cybersecurity Architect / Penetration Tester:** Threat models, SAST/DAST, auth, OWASP, secure data handling.
- **Privacy / Data Protection Expert:** Data minimization, consent, cross-border transfers, deletion, access logging.
- **DevOps / Platform / Site Reliability Engineer:** Hosting, CI/CD, backups, jobs, monitoring, scale, recovery.
- **Performance Engineer:** Core Web Vitals, caches, search efficiency and 3D/media budgets.
- **QA & Test Automation Lead:** Test strategies, automation pyramid, integration, contract, load, security and regression tests.
- **UAT & Release Manager:** Acceptance, deployment, rollback, documentation and launch readiness.
- **Analytics & Experimentation Lead:** Event schemas, funnels, attribution, revenue metrics and experimentation ethics.
- **Legal & Regulatory Counsel (qualified local review):** Iranian and international travel, payments, consumer rights, privacy, transport licensing, IP and digital assets.

For every generated document, include a short `Prepared from perspectives`, `Accountable reviewer`, `Dependencies`, and `Approval gate` header. Include a RACI for all major feature areas and show which roles may be combined for a small initial team.

---

## C. CANONICAL PRODUCT BRIEF — DO NOT OMIT ANY AREA

### C1. Vision, markets and users

A travel and leisure agency builds a customer-facing website plus a multi-sided partner platform, initially available in **English (LTR)** and **Persian/Farsi (RTL)**. It works across multiple cities and countries, with particularly important **Iranian domestic payment gateway requirements**, subject to legal/provider eligibility. Visitors may browse publicly published offerings; customers must sign in to complete a booking/purchase as requested. If guest checkout may improve conversion, document it as an alternative decision, not a silent replacement of the login requirement.

The agency has B2B arrangements with **tour leaders/guides, accommodations (hotels, motels, villas, rural guesthouses, garden properties, serviced apartments, resorts), souvenir manufacturers, transport operators, venues, event suppliers, instructors and other travel/leisure suppliers**. Partners need specialized portals and controlled ownership of their own records, bookings, earnings and assignments. The agency approves partners, commercial terms, listings and material changes.

### C2. Customer marketplace, account and commerce

Customers browse, compare, reserve/book/purchase and pay for:
- Guided tours, activities, excursions and multi-day/multi-country tour packages.
- Short-stay accommodations and optional meal/service add-ons.
- Airline seats and flights; train and bus tickets; taxis, transfers, chauffeur-driven cars and vans.
- Self-drive / self-operated **bicycle, motorcycle, car and van rentals**.
- **Events:** seminars, conferences, exhibitions, meetings, nature excursions, breakfasts, lunches, afternoon snacks, dinners, cultural and social programs, corporate outings, festivals, and recurring sessions.
- **Academy:** in-person training courses with curriculum, cohort schedules, trainers, admission and completion evidence.
- Souvenirs and merchandise: readymade, private-label, personalized and group orders.
- Optional multi-service bundles, gift vouchers, group reservations and B2B quotations.

Every offering has transparent pricing and exclusions, relevant taxes/fees, supplier terms, availability state, fulfillment details, cancellation/refund policy, localized content and booking eligibility. Customers manage profiles, travelers, tickets, vouchers, bookings, invoices, courses, payments, rental status, favorites, alerts, support and reviews. Separate purchaser/payer/traveler/attendee/renter identities and data where appropriate.

### C3. Events and Academy registration windows — MUST ENFORCE SERVER-SIDE

Each event occurrence and each Academy course cohort is **defined before its registration opens** and independently stores:
- Published visibility period if applicable;
- `registration_open_at` and `registration_close_at`, with named IANA time zone and UTC-normalized instants;
- eligibility, age/prerequisites, custom form schema, number of seats, ticket/enrollment tiers, waitlist policy, payment deadline, discounts and approvals;
- session calendar, venue and program, optional services, reminders and cancellation policy.

**Strict rule:** A public registration is accepted only when `open_at <= server_now < close_at`, the user is eligible and inventory is available. Hidden forms are NOT sufficient. Require atomic revalidation at registration submission, order creation, payment return, and booking confirmation as appropriate. Document policies for bookings paid just after cutoff, expired unpaid holds, waitlists, admin exceptions, edits to published schedules, recurring event occurrences, and quotas.

An Academy `Course` is a reusable syllabus; a `Cohort` is one particular scheduled delivery with its own registration window, venue, instructors, capacity, pricing, lessons and certificate rules. Preserve access to course materials/confirmation after the enrollment window closes, subject to policy. Include quizzes, assignments, practical attendance, certification and verification as tiered capabilities.

### C4. Tour leaders: advanced multiple-tour itinerary builder

Each approved tour leader can manage **multiple tour definitions** and submit **multiple dated departures** per definition. A tour may have **one or many destinations**. Example: London -> Paris -> Rome across countries and time zones. The tour builder must store **structured** itinerary entities, not one blob of prose:
- Tour identity, owner/organization, theme, description, language, age range, group size, fitness/accessibility requirements, prerequisites, required documents and travel conditions.
- Multiple destinations/stops with city/country, coordinates, local time zone, arrival/departure, overnight status, meeting points and contingency alternatives.
- Days and time-stamped itinerary items: departure/arrival, transfer leg, sightseeing, activity, meal, free time, rest, check-in, equipment distribution, emergency briefing.
- Transport per leg: type, origin/destination, planned departure/arrival, provider, fare estimate, luggage constraints, assigned vehicles/seats, connection feasibility.
- Lodging by night: property, room type, allocations, occupancy, check-in/out, booking status, meal plan, confidential net rate.
- Meals: breakfast/lunch/dinner/snacks; included/excluded; dietary/accessibility needs; venue; duration and estimated price.
- Sightseeing and activity entries: attractions, supplier, hours, admission estimate, reservation status, minimum age, fitness, permits, equipment and safety.
- Equipment required/provided/optional, weather plans, safety risk assessments, emergency instructions and insurance requirements.
- Itemized **supplier costs**, customer-included cost categories, optional costs (e.g. souvenirs, meals, attractions), currencies, contingency, taxes, commissions, markup, margin and trip budget.
- Participant-facing summary distinct from confidential internal operation notes and supplier prices.
- Leader draft -> submitted -> agency review -> revisions -> approved -> published -> scheduled -> completed/canceled with versioning and audit history. For already-booked departures, changes require impact assessment and appropriate customer notice/consent.
- Capacity, departure-specific registration opening/closing, traveler forms, rooming lists, pre-departure messages, check-in, operational reporting, incident handling, feedback and payout.
- Map, timeline, day view, printable itinerary, traveler-friendly view, leader operations view and agency margin/cost view.

### C5. Accommodation B2B portal

Partner onboarding/business verification, multi-property support, property/room/whole-unit models, seasons, rate plans, occupancy, media, amenities, rules, availability calendars, manual blocks, minimum stays, bookings, cancellations, group allocations, requests for quotation, negotiated rates, agency commissions, payout statements, partner staff accounts, messages, guest support, analytics and optional PMS/channel manager connectivity. Model rooms vs room types vs independent villas correctly; prevent overselling and double assignment. Support commission, net-rate, allotment, managed listing and package supplier relationships.

### C6. Souvenir manufacturer and procurement portal

Manufacturer onboarding, product catalogs, variants, stock, wholesale and private-label pricing, MOQs, RFQs, custom designs and approval, production milestones, samples, quality checks, batch tracking, shipping, B2B supply to hotels/tours/events, consignment, dropshipping where available, settlements, reports and intellectual property rights. Distinguish physical shipping/order fulfillment from service booking and digital vouchers.

### C7. Transport and rentals

Flight/rail/bus: supplier search and shopping, origin/destination, fares, seat map where licensed, passenger identity, baggage, hold, issuance, change/refund, cancellation, disruption, ticket and PNR/booking reference. Clearly separate **search/quote**, **reservation/hold**, **supplier-confirmed ticket** and **payment**. Support manual request-based booking until verified supplier integrations exist. Never fabricate schedules or seat availability.

Taxi and chauffeur: pickup/drop-off, vehicle class, passenger/luggage capacity, wait time, route estimate, return, operator assignment, real-time or scheduled quote, no-show and cancellation. Vehicle rental: individual fleet assets or category inventory, branch, pickup/return, time slot, duration, mileage, driver age/license validation, deposit, rental insurance/excess, fuel/charging, handover and return photos, damages, late fees, extensions, maintenance downtime. Driver-inclusive transfers and driverless rentals are separate business models.

### C8. Pricing, checkout, gateways and currencies

WooCommerce is the commerce foundation. Require transparent itemized prices, unit selection, options, mandatory charges, taxes where applicable, eligible promotions, discounts, fees, booking holds and accurate final totals. Define merchant of record and cashflow per offering; support multi-vendor payouts/commission ledger as authorized, not uncontrolled automatic splitting.

Iran domestic payment providers to investigate/configure within WooCommerce:
- **SEP / Saman Electronic Payment (Saman Bank-related gateway)**.
- **Behpardakht Mellat (Bank Mellat-related gateway)**.
- **ZarinPal** as an aggregator.
- Additional domestic gateways only through documented, eligible integration.

Gateway integrations must cover merchant onboarding, provider documentation verification, merchant credentials, TLS/hosted card entry, callbacks/webhooks, status inquiry, signature/authority verification, idempotent payment completion, retries, timeouts, duplicate callbacks, refund capabilities **only if actually supported**, reconciliation, sandbox/test mode, gateway maintenance, fraud flags, order linkage, currency unit correctness and security. Evaluate maintained WooCommerce extensions against purpose-built provider adapter plugins. Require both current **WooCommerce Checkout Blocks** and traditional checkout support when feasible; explicitly test both. Never assume a plugin supports Blocks/HPOS/refunds merely because a listing claims general Woo compatibility.

Handle Iranian **IRR (rial) vs toman** carefully: clearly label units; specify integer minor-unit conventions, provider-required units, rounding and conversion policy; never silently treat IRR and toman as identical. Multiple international currencies may be displayed if licensed/operationally possible, but settlement methods and FX legality must be verified.

Conditional digital-asset methods requested: Bitcoin/BTC, Ether/ETH on Ethereum, Tether/USDT with precise permitted contract+network (e.g. ERC-20, TRC-20, TON where supported), TON, TRX on TRON, and optional additional approved assets. For each, model asset, network, contract/mint or token identity, transaction ID, settlement asset, expiration/price-quote lock, chain confirmations, reorgs, under/overpayments, refunds, wallet custody/processor, accounting and compliance. **Keep disabled by default**. Obtain qualified legal/compliance approval and provider authorization for each intended jurisdiction and use. Do NOT suggest bypassing Iranian banking rules, geographic restrictions, sanctions or KYC. Where prohibited, specify feature flag = OFF and offer lawful alternatives.

### C9. Internationalization, brand and creativity

English and Persian from day one. Full RTL layouts; bidi-safe mixed-script content; localized messages, invoices, vouchers, errors, notifications and marketing. Persian Unicode normalization (including Arabic/Persian character variants), appropriate numeral display, phone/address formats, locale-sensitive search, optional Jalali input/display while retaining standards-based backend time and data, named IANA time zones and DST handling. Define translation ownership for supplier-created content and fallback strategy.

Brand should convey **trust, discovery, joy, premium-but-inclusive travel and adventure**. Specify an original visual world: original fantasy guide characters/mascots and optional creatures as brand ambassadors; original 2D illustrations, maps, collectible badges, 3D world/destination objects, tasteful microinteractions and cinematic hero treatments. Never copy copyrighted franchises, trademarks or recognizable characters. Avoid stereotypical or disrespectful cultural representations. Every animation requires reduced-motion and low-bandwidth fallback; character art must never obstruct functional forms, fare disclosures or accessibility. Include 3D optimization, licensing, export pipeline and mobile fallback, and show how the theme supports these assets.

---

## D. WORDPRESS + WOOCOMMERCE ARCHITECTURAL REQUIREMENTS

Produce an explicit **Build vs Buy vs Hybrid Decision Record** for every major feature, and a compatibility matrix for any named plugin. Verify versions and docs at execution time if possible.

1. **Theme:** Prefer a maintainable custom block theme when current compatibility and project needs justify it; evaluate classic/hybrid as alternatives. Specify `theme.json`, templates, template parts, reusable patterns, style variations, typography, spacing, dark/light if needed, WooCommerce template integration, block supports, Gutenberg editing experience, SEO semantics and RTL styles. Keep durable logic out of theme.
2. **Plugins:** Recommend modular custom business plugins with activation dependencies, capability setup, migration/upgrade steps, shared services, hooks, settings and clean uninstall/data-retention policy. Illustrative packages (change as evidence dictates): `agency-core`, `agency-partners`, `agency-bookings`, `agency-tours`, `agency-events`, `agency-academy`, `agency-stays`, `agency-transport`, `agency-rentals`, `agency-merchandise`, `agency-payments`, `agency-reporting`, `agency-localization`, `agency-visuals`. Explain split vs one modular plugin tradeoffs.
3. **WooCommerce:** Define how purchasable offers map to Woo products/variations and order items; when to create temporary checkout products, custom catalog entities, linked fulfillment records; HPOS-aware Woo CRUD APIs; Cart/Checkout Blocks; Store API and extension points; tax, shipping and coupon rules; virtual/bookable/physical item handling; partial refunds; order lifecycle; separate service booking lifecycle; mixed carts and splitting policy; supplier ownership and settlement. Do not access orders by directly assuming `wp_posts` storage.
4. **Data persistence:** Compare custom post types/taxonomies, user/meta, Woo order tables, and dedicated SQL tables for high-volume inventory/booking legs/transactional holds. Recommend appropriate indexes, uniqueness constraints, InnoDB transaction strategy, tenant scoping, backup and migration. Show Mermaid ERD + data dictionary + example schema. Never present unverified Woo table internals as stable public interfaces.
5. **Concurrent reservations:** Ensure atomic inventory/seat enforcement, lock/hold expiration, retriable operations, optimistic/pessimistic concurrency tradeoffs, idempotency keys, and exact server-side registration-window validation. Distinguish seats, room-nights, unit-night inventory, vehicle time intervals, event capacities and course cohorts.
6. **Background tasks:** Use an appropriate WordPress/WooCommerce task scheduler where suitable (such as verified Action Scheduler), with retries, exponential backoff, dead-letter/exceptions and monitoring. Do not rely solely on visitor-triggered WP-Cron for time-critical inventory locks or transaction deadlines; specify reliable server scheduling.
7. **API contracts:** WordPress REST API and Woo extensions where appropriate. Describe auth, nonce vs server-to-server tokens, capability and tenant boundaries, request/response examples, versioning, error structure, idempotency, rate limits, webhooks and signatures. Supplier adapters must be separately replaceable.
8. **Permissions:** Use WordPress roles/capabilities carefully; each B2B partner is an organization with multiple potential staff, and a user may be a customer plus staff for more than one organization. Define role *and record ownership* checks, consent, privacy and audit logs.
9. **Security:** WordPress/Woo patching, supply-chain/plugin reviews, secret management, prepared statements, sanitization/escaping, capability checks, CSRF, object-level authorization, file uploads, session handling, MFA, admin audit and incident response. Avoid card data storage; use approved provider-hosted/payment token approaches.
10. **Quality attributes:** Availability, uptime SLO candidates, Core Web Vitals, data durability, backup RPO/RTO, accessibility, maintainability, translation completeness, observability, security, cross-browser tests, disaster recovery and scale assumptions. Mark target numbers as proposed rather than measured.
11. **Lifecycle:** Local/staging/prod environments, infrastructure, backups, logging/redaction, Git, CI/CD, migrations, feature flags, rollback, dependency update policy, plugin and theme release versioning, acceptance and post-launch support.
12. **Scale contingency:** Evaluate whether a WP/Woo monolith plus dedicated plugins is appropriate initially and where separate services/search/queues might become warranted. Do not recommend microservices by default; define triggers and migration paths.

---

## E. SPECIFIC DESIGN AND VISUAL DOCUMENTATION — NO HAND-WAVING

Generate **full UI/UX deliverables** and **visual art production briefs**, not just adjectives.

### E1. Information architecture and screens

Deliver master sitemap, secondary navigation for customer and each partner dashboard, global search and filtering models, mobile bottom navigation decisions, breadcrumbs, deep links, empty/loading/error/disabled/success states and permissions. Cover home, destinations, tour listing/detail/itinerary, accommodations listings/detail/calendar, flights/trains/buses search and result/checkout/issuance, taxis/transfers, rental fleet/detail/contract/handover, events/events calendar, Academy catalog/course/cohort, souvenirs/cart, login/registration, booking checkout, payment selection/verification/return, My Account, customer support, partner onboarding and every dedicated B2B panel, agency CMS and administrative consoles.

For EACH key screen provide: screen ID, intended actors, goals, entry/exit points, mobile-first wireframe described in an annotated grid, tablet/desktop adaptation, hierarchy and content, fields with type/default/validation/help/error, sorting/filtering, empty/loading/skeleton/error states, action permissions, keyboard/ARIA behavior, bilingual notes and microcopy samples, analytics events and acceptance criteria.

### E2. Design system

Produce design principles, color tokens with semantic roles and accessible contrast, typography scale and bilingual type behavior, spacing/radius/grid/breakpoints, iconography, imagery, shadows, focus and validation, form patterns, nav systems, card variants, pricing breakdowns, booking calendars, stepped forms, timeline/route maps, comparison tables, check-in QR interactions, alerts, modals/dialogs, search suggestions, dropdowns, date/time pickers and currency selectors. Define source-of-truth `theme.json` mapping, Figma token naming (if Figma is used), CSS custom properties, component props/states and design-to-code governance. Include WCAG 2.2 AA and reduced-motion design requirements.

### E3. Motion and microinteraction specification

For each animation (page transitions, hero reveals, card hover, skeletons, itinerary route drawing, step progress, booking success, toast, loading, map pins, mascot moments), specify purpose, trigger, visual behavior, easing curve, duration range in ms, interruption/keyboard handling, performance cost, touch fallback, prefers-reduced-motion behavior, and clear do-not-animate zones. Ban flashing/strobing, intrusive autoplay sound and gameplay-like friction in checkout.

### E4. 2D/3D fantasy visual universe

Create three original, brand-appropriate style directions with tradeoffs; select one recommended direction with reasons. Document:
- Brand story, semantic visual metaphors, illustration art direction, environment art, destination illustrations, badges, map art, original icon families and asset licensing.
- **At least five original fantasy mascots/characters** with unique, non-infringing names; concise backstories, personalities, customer-support/education/travel use cases, silhouette, proportions, shape language, materials, color palette, expression sheets, costume options, cultural sensitivity notes and accessibility descriptions. Include a clear reason whether characters should be fantasy creatures, humans, or abstract.
- Character turnaround requests (front, 3/4, side, back), facial expressions, hand/gesture poses, animation rigging requirements, 2D sprite/Lottie possibilities and 3D asset specifications.
- Detailed prompts/storyboards for original hero images, onboarding illustrations, itinerary map scenes, event and Academy illustrations, and nonessential optional 3D scenes; differentiate conceptual image prompts from actual created images.
- 3D pipeline: modeling/UVs/materials, texture budgets, mesh/poly budgets as proposed targets, level of detail, glTF/GLB, optimized media formats, optional WebGL/Three.js assessment, mobile/reduced-data fallback and low-power device behavior.
- Content placement: fantasy characters must complement real travel information and never imply fictional facilities/destinations are genuine bookable products. Avoid cultural caricatures and fictional safety claims.
- Asset export specs: scalable SVG icons, raster WebP/AVIF fallback, poster frames, alt text, semantic vs decorative, IP ownership and provenance.

### E5. Language and trust

Provide authentic English and Persian examples for menus, key CTAs, booking stages, payment notices, dates, cost exclusions, validation and error messages, registration-open/closed statuses, refund policy summaries and confirmation emails. Specify localization QA checklist, bidi nesting, long-string overflow, fonts licensing/hosting (do not redistribute proprietary fonts), numeral selection and mixed-script ordering. Ensure trust indicators reflect verifiable facts rather than fabricated badges, certifications or testimonials.

---

## F. BUSINESS MODULES: REQUIRED DEPTH OF EACH SPECIFICATION

For EACH module below, deliver: actors, JTBD, workflows, user stories, precise forms/fields, data model entities, states, permissions, configuration, APIs, admin and customer UI, operational exception paths, integrations, notifications, pricing, analytics, security/privacy, acceptance tests, and MVP vs later decisions.

1. **Identity / Customer Portal / Organization & Roles:** auth, profiles, staff memberships, guardians/minors, account recovery, consent and document access.
2. **Universal Catalog & Search:** taxonomy, destination hierarchy, geosearch, prices, filters, relevance, inventory freshness and SEO.
3. **Tour Leader & Tour Builder:** all multi-stop structured requirements above; route editor, itinerary timeline, supplier sourcing, cost budgets, approval/versioning, capacity, departure execution.
4. **Events:** all specified types; recurring occurrences; agenda, ticket tiers, capacity, registration windows, catering/menu needs, sponsors/exhibitors, badges, check-in and feedback.
5. **Academy:** course vs cohort; session scheduling, registration window, instructors, rooms, training materials, attendance, assessment, completion and verifiable certificates.
6. **Accommodations:** multi-unit inventory, rate plans, allotments, availability integration, rooming lists, bookings, property compliance and supplier settlement.
7. **Flights:** shopping vs real ticketing and supplier connectivity; PNR/issuance, change, cancellations, fees and disruption; no fake live bookings.
8. **Train/Bus Tickets:** operator integration, seat selection if offered, hold expiry, station/pickup, schedules, refund constraints.
9. **Taxis / Chauffeur-Driven Transfers:** quote/dispatch/vehicle class, pickup, tracking where legally and technically available, waiting fees and operator assignments.
10. **Vehicle Rentals:** bicycle/motorcycle/car/van, fleet, calendars, pickup, driver verification, deposits, insurance, condition reports and damage workflows.
11. **Souvenir Catalog & Manufacturer Portal:** variants, fulfillment, RFQ, manufacturing, consignment, personalized art proofing, shipping, returns.
12. **B2B Contracts, RFQs & Procurement:** partner onboarding, verification, KYB where applicable, negotiated pricing, commissions, allocations, offers, invoice/payables and partner disputes.
13. **WooCommerce Checkout & Orders:** mixed offering types, linked line items, tax logic, shipping for physical goods, tickets/vouchers, email notifications, failed fulfillment reconciliation.
14. **Payment Orchestration:** bank gateway adapters, aggregator, status machine, refunds, settlement, payout ledger, currency, disbursement, audit. Conditional legal gating for crypto.
15. **CRM / Support / Notifications:** email/SMS, traveler communications, emergency broadcasts, templates, consent, SLA and ticket escalation.
16. **Agency Admin / Finance / Analytics:** approval queues, audit logs, operational dashboards, revenues by vertical, exports, reconciliation, partner quality and fraud monitoring.
17. **Content, Marketing and SEO:** landing pages, educational/blog content, promotions, campaign pages, schema, redirects, multilingual indexing.

---

## G. CROSS-CUTTING BUSINESS RULES AND EDGE-CASE CATALOG

Explicitly specify state machines and invariants for:

- Publicly visible but not yet open for registration; closed after cutoff; sold out before cutoff; unpublished; suspended; canceled; rescheduled.
- Registered before cutoff but payment callback after cutoff; payment initiated before window but reservation hold expired; partial group registration; waitlisted customer offered seat after cutoff; admin exception with audit log.
- Overlapping room-night inventory, hotel room category counts, individual villa blocks, transport seat holds, vehicle rental intervals, event session seats and cohort classroom seats.
- Two users attempting to reserve the last seat; inventory returned from supplier stale; checkout timeout; gateway redirects but callback absent; duplicate webhook; provider verifies payment twice; customer charged but airline fails to issue ticket.
- Mixed cart containing souvenir + tour + hotel; merchant-of-record conflict; different payment eligibility; differing cancellation policies; tax and delivery address requirements; split checkout vs separate orders decision.
- Multi-currency quoting and taxes, explicit IRR vs toman, price changes after quote, exchange-rate expiry and rounding; country-specific service eligibility.
- Crypto asset sent on wrong network, fraudulent token, underpayment/overpayment, unconfirmed/reorganized transaction, payment after expiration, refund in differing asset/fiat valuation; legal ineligibility -> method hidden/unavailable.
- Tour crossing time zones/DST, overnight travel, accommodation before arrival, activity schedule conflict, bad-weather alternative, transport delay and missed connection.
- Rental driver ineligible, expired documents, pickup no-show, return damage disagreement, late fee, deposit authorization failure.
- Event dinner with dietary needs; conference session booked beyond room capacity; one instructor assigned simultaneously to two cohorts; minors with guardians; inaccessible venue.
- Partner suspensions, fraud flags, confidential supplier information leakage, company staff changing jobs, ownership transfer of tour definition, chargebacks and account deletion.

For each case provide detection, UI explanation, backend enforcement, recovery path, notifications, finance outcome, audit event and test coverage.

---

## H. MANDATORY DOCUMENT DELIVERABLE REGISTRY

Generate the following independently usable documents, with this exact ID and filename convention. For every document: a concise purpose, owner/reviewer, current decisions, numbered specifications, sample artifacts, unresolved assumptions, dependencies, acceptance gates, and citations where applicable. A document is **not done** if it contains only a heading and bullet promises.

### Volume 1 — Strategy, discovery and governance
- `DOC-00_MASTER-INDEX.md`: package overview, navigation, completion manifest, notation and glossary links.
- `DOC-01_VISION-BUSINESS-MODEL.md`: vision, value proposition, B2C/B2B journeys, economics, revenue streams, success metrics.
- `DOC-02_RESEARCH-BENCHMARKS.md`: research methodology, official sources, comparison to relevant WordPress/Woo/travel/event/LMS systems, evidence gaps.
- `DOC-03_PERSONAS-JTBD-JOURNEYS.md`: customer, traveler, learner, leader, property, transport, maker and admin personas; journey maps.
- `DOC-04_STAKEHOLDERS-TEAM-RACI.md`: role inventory, RACI, approvals, lean/growth/enterprise staffing, collaboration interfaces.
- `DOC-05_SCOPE-MVP-ROADMAP.md`: MVP boundary, release increments, dependencies, prioritized backlog, out-of-scope decisions.
- `DOC-06_TERMINOLOGY-RULES-DECISIONS.md`: canonical domain glossary, numbered business rules, assumptions log, ADR index.

### Volume 2 — Requirements and experience design
- `DOC-07_BRD-SRS.md`: comprehensive business, functional and nonfunctional requirements with IDs and priority.
- `DOC-08_USER-STORIES-ACCEPTANCE.md`: epics, detailed user stories, Given/When/Then acceptance, edge cases.
- `DOC-09_USE-CASES-SERVICE-BLUEPRINTS.md`: swimlanes, exception and support flows.
- `DOC-10_INFORMATION-ARCHITECTURE.md`: sitemap, navigation, taxonomy, search and URL design.
- `DOC-11_UX-FLOWS-WIREFRAMES.md`: screen inventory, forms, responsive wireframe specifications and states.
- `DOC-12_DESIGN-SYSTEM-UI-KIT.md`: tokens, components, interaction rules, `theme.json` mapping.
- `DOC-13_MOTION-MICROINTERACTIONS.md`: motion spec matrix, animations, reduced-motion variants.
- `DOC-14_2D-ILLUSTRATION-ART-DIRECTION.md`: brand graphics, destination art, icons, original asset prompts.
- `DOC-15_3D-FANTASY-CHARACTERS.md`: original character bible, model briefs, turnarounds, animation, optimized exports.
- `DOC-16_ACCESSIBILITY-INCLUSIVE-DESIGN.md`: WCAG 2.2 AA tests, keyboard/screen-reader and design rules.
- `DOC-17_I18N-EN-FA-RTL.md`: localization architecture, calendar, digits, Persian copy samples, translation QA.
- `DOC-18_CONTENT-SEO-GROWTH.md`: content model, search indexing, metadata, translation, editorial and promotion.

### Volume 3 — WordPress architecture and implementation
- `DOC-19_SYSTEM-ARCHITECTURE-ADRS.md`: context/container/component diagrams, build/buy, boundaries and scale.
- `DOC-20_WORDPRESS-THEME-SPEC.md`: theme directory, `theme.json`, blocks, templates, Woo styling, RTL, responsive behavior.
- `DOC-21_CUSTOM-PLUGIN-ARCHITECTURE.md`: plugin ecosystem, hooks, dependency contracts, package structure and migrations.
- `DOC-22_DATA-MODEL-ERD-DICTIONARY.md`: entities, relationships, table choices, indexes, constraints, PII classifications.
- `DOC-23_IDENTITY-RBAC-TENANCY.md`: org staff, capability matrix, resource-based access and auth flows.
- `DOC-24_BOOKING-INVENTORY-REGISTRATION.md`: inventory, holds, windows, transactional state machines and concurrency.
- `DOC-25_WOOCOMMERCE-COMMERCE-ENGINE.md`: product/order mapping, HPOS, Cart/Checkout Blocks, tax, shipping and lifecycle.
- `DOC-26_APIS-INTEGRATION-CONTRACTS.md`: REST schemas, supplier adapters, event/webhook contracts, sync/async failures.
- `DOC-27_SECURITY-PRIVACY-THREAT-MODEL.md`: STRIDE/abuse cases, controls, retention, consent, security test plan.
- `DOC-28_INFRA-DEVOPS-RELIABILITY.md`: deployment, CI/CD, observability, backups, restore drills and incident response.

### Volume 4 — Business domain modules
- `DOC-29_TOUR-LEADER-ITINERARIES.md`: tour builder, day/stop/leg data, pricing, approvals and examples.
- `DOC-30_EVENTS-MANAGEMENT.md`: event lifecycle, registrations, tickets, schedule, catering, check-in.
- `DOC-31_ACADEMY-COURSES-COHORTS.md`: course/cohort/enrollment, attendance, assignments and certificates.
- `DOC-32_ACCOMMODATION-PORTAL.md`: properties, units, calendars, rates, allotments, bookings and partner settlements.
- `DOC-33_FLIGHTS-RAIL-BUS-TICKETING.md`: supplier/API dependencies, route search, reservations and issuance.
- `DOC-34_TRANSFERS-AND-RENTALS.md`: taxi/chauffeur, vehicle rental, deposits, fleet operations and disputes.
- `DOC-35_SOUVENIRS-MANUFACTURING.md`: manufacturing, inventory, RFQ, sales, shipping and quality.
- `DOC-36_PARTNER-PORTALS-CONTRACTS.md`: supplier onboarding, KYB, RBAC, negotiations, commissions, RFQs, feedback.
- `DOC-37_PAYMENTS-DOMESTIC-GATEWAYS.md`: SEP/Saman, Behpardakht Mellat, ZarinPal provider due diligence, adapter design, verification, admin settings and tests.
- `DOC-38_CRYPTO-PAYMENT-COMPLIANCE.md`: legal gating, approved asset/network matrix, safeguards, dormant design and test scenarios.
- `DOC-39_FINANCE-PRICING-SETTLEMENTS.md`: rate plans, currency policy, taxes, supplier ledger, refunds, chargebacks and reporting.
- `DOC-40_SUPPORT-NOTIFICATIONS-CRM.md`: customer communication, templates, notifications, support tickets and escalation.

### Volume 5 — Quality, release, and governance
- `DOC-41_QA-TEST-STRATEGY.md`: unit/integration/E2E/contract/security/a11y/performance/UAT and test data.
- `DOC-42_ANALYTICS-OBSERVABILITY-KPIS.md`: events taxonomy, funnels, dashboards, privacy-safe metrics and SLOs.
- `DOC-43_LEGAL-REGULATORY-REVIEW.md`: jurisdiction matrix, travel, transport, payments, crypto, IP and consumer terms; legal signoff blockers.
- `DOC-44_IMPLEMENTATION-BACKLOG-ESTIMATES.md`: epics, tasks, dependencies, complexity ranges, staffing and realistic milestones (assumptions labeled).
- `DOC-45_RISKS-DECISIONS-CONTROLS.md`: prioritizable risk register, mitigating controls, audit decisions and launch blockers.
- `DOC-46_TRACEABILITY-MATRIX.md`: feature -> screen -> entity -> API -> test -> phase -> owner.
- `DOC-47_OPERATIONS-RUNBOOKS.md`: onboarding, daily business operations, payouts, support, backup, payment failures, security incidents.
- `DOC-48_LAUNCH-UAT-HANDOVER.md`: comprehensive launch checklist, training, acceptance signoff, rollback, warranty and maintenance.

All documents above are required outputs. If context/time permits, also produce optional runnable schema snippets, sample JSON payloads, a suggested theme/plugin folder tree and pseudocode. These examples must be illustrative unless verified against official versioned APIs; do NOT imply the whole platform has been coded or deployed.

---

## I. REQUIRED DEEP-DIVE EXAMPLES (WRITE END TO END)

For each example show: sample fictional configuration, actor journey, screens/fields, happy path, at least 5 failure/edge paths, state transitions, data objects, pricing, supplier dependencies, notifications, permissions, localized text and test scenarios.

**Scenario A — Nature excursion:** Event on 2027-06-20, public registration from 2027-06-01 09:00 to 2027-06-15 18:00 in an explicitly named local time zone, 30 seats; age/gear list, route, breakfast and lunch options, waiver, transportation, waitlist and sold-out behavior. Before window, within window, at exact close, after close, payment late, admin exception.

**Scenario B — Academy:** Reusable in-person `Professional Tour Leadership` course, two cohorts in different cities/dates; independent registration windows, 20/25 seats, prerequisites, tuition, instructor sessions, attendance, final assessment and certificate verification. Display one open and the other closed concurrently.

**Scenario C — International tour:** London -> Paris -> Rome across 5 days with at least 3 time-zoned departure/arrival legs, accommodation in Paris and Rome, attraction visits, travel equipment, inclusive/exclusive meals, room categories, group ages, estimated sightseeing costs and optional souvenirs. Show day-by-day itinerary, supplier quote, agency confidential cost/margin and traveler-facing price separately. Flag sample transport times as illustrative/unverified.

**Scenario D — Accommodation:** Two room types in a 20-room hotel + one book-the-entire-villa property; overlapping dates, last-room race, 3-night booking, refundable/nonrefundable plan, group quote and payment status reconciliation.

**Scenario E — Vehicle rental:** Car for 4 days with pickup/drop-off, license checks, deposit, optional protection, fuel, late return and damage dispute. Contrast with chauffeured airport pickup.

**Scenario F — Passenger ticketing:** Flight search, tentative price, provider reservation, payment, successful order but failed ticket issuance, exception queue and refund/resolution. State clearly whether integration is mocked, proposed, licensed or live.

**Scenario G — Souvenir procurement:** 500 customized welcome gifts for a conference, three manufacturers replying to RFQ, approved sample, manufacturing schedule, shipping, invoice, inspection and payout.

**Scenario H — Checkout and payment:** A domestic WooCommerce payment via an eligible configured SEP/Mellat/ZarinPal adapter; wrong rial/toman conversion detection, duplicate callback and timeout recovery. Separately document why crypto options remain OFF absent documented legal approval; never make it an actual checkout default.

---

## J. DOCUMENT QUALITY RUBRIC, RESEARCH SOURCES AND AUDIT

Use a 0–5 scoring rubric with justification for each document on: completeness, specificity, internal consistency, user value, feasible WordPress implementation, security/privacy, compliance, accessibility/localization, testability and traceability. Any score below 4 requires revision or an explicit blocker record. Put key unresolved blockers into the risk register and implementation backlog.

When official research is available, prioritize:
- WordPress Developer Handbooks (themes, block editor, plugins, roles/capabilities, REST API, security, internationalization).
- WooCommerce Developer Documentation (Payment Gateway API, Checkout Block payment method integration, additional checkout fields, HPOS, Woo CRUD, Store API and compatibility requirements).
- Official provider docs and merchant contracts for SEP, Behpardakht Mellat, ZarinPal and any verified digital-asset processor.
- Actual supplier/GDS/transport/booking channel technical agreements for fares/inventory/issuance.
- Appropriate official Iranian and international legal/regulatory sources with jurisdiction and verification date.
- W3C WCAG 2.2, OWASP ASVS/Top 10, relevant web performance guidance and official translation/localization standards.

**Known references to verify freshly, not blindly trust:**
- https://developer.wordpress.org/themes/global-settings-and-styles/
- https://developer.wordpress.org/plugins/
- https://developer.wordpress.org/plugins/users/roles-and-capabilities/
- https://developer.woocommerce.com/docs/features/payments/payment-gateway-api/
- https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/checkout-payment-methods/payment-method-integration/
- https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/
- https://developer.woocommerce.com/docs/best-practices/compatibility/
- https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/additional-checkout-fields/
- https://wordpress.org/plugins/zarinpal-woocommerce-payment-gateway/ (verify ownership, update status, security and Blocks/HPOS support)
- https://www.w3.org/TR/WCAG22/

Do not trust random gateway code snippets or unofficial extensions as authority. Independently evaluate support history, maintenance, vulnerability disclosures, licensing, interoperability and provider authentication.

---

## K. EXECUTION SEQUENCE — START NOW

Execute these steps **without asking follow-up questions**:

**PASS 1 — Contract and mapping**
1. Summarize product vision and scope in 1–2 pages.
2. Emit `DOC-00_MASTER-INDEX.md` with all 49 document names and their dependencies (DOC-00 through DOC-48 inclusive).
3. Produce a role-to-document ownership and review matrix and a key platform context diagram.
4. Produce a canonical capability map, user role map and high-level conceptual ERD.
5. Identify TOP 20 architectural, regulatory, dependency and operational decisions and recommendations, with confidence/evidence labels.

**PASS 2 — Full specification**
6. Write Volume 1 then Volume 2 in full, in document-ID order, not as outlines.
7. Write Volume 3 (architecture) in full, with concrete WordPress/WooCommerce implementation strategy and plugin/theme structures.
8. Write Volume 4 (all operations) in full with the required eight worked scenarios.
9. Write Volume 5 (QA, governance, rollout) in full, linking every important requirement to tests.

**PASS 3 — Consistency and readiness**
10. Perform cross-module review of the strict registration cutoff, multi-stop itinerary, Woo checkout payment verification, bilingual behavior, partner permissions and crypto compliance gating.
11. Produce the `DOC-46` traceability matrix, risk and decision ledgers, and a precise `MVP -> later` release plan.
12. Check for missing screens, missing negative flows, gaps in model data, missing gateway test cases, and unspecified costs/ownership.
13. Summarize unresolved assumptions and exactly what vendor documents, credentials or legal signoffs are needed to turn proposals into tested features.

**FIRST OUTPUT:** Start with the heading `MASTER DOCUMENTATION PACKAGE — ATLAS LEISURE ECOSYSTEM`, followed by the manifest, key decisions, then the actual content of `DOC-00`, `DOC-01`, and successive documents to the available output limit. Use high specificity and no filler. Do not end by proposing to write documents later; write them now and use the continuation protocol only if limited by output length.
