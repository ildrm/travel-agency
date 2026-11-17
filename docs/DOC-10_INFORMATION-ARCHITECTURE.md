# DOC-10 — Information architecture

**Status:** DRAFT specification; review pending. **Purpose:** define navigable public, account and agency/partner spaces. **Prepared from perspectives:** information architecture, UX, editorial, localization, security. **Accountable reviewer:** product design lead. **Dependencies:** [scope](DOC-05_SCOPE-MVP-ROADMAP.md), [requirements](DOC-07_BRD-SRS.md), [localization](DOC-17_I18N-EN-FA-RTL.md). **Approval gate:** product and content owners validate terminology, navigation and routes with representative users.

## 1. Public and authenticated structure

```text
/ [language entry and home]
  /destinations/ -> /destinations/{place}/
  /tours/ -> /tours/{tour}/ -> /departures/{id}/
  /events/ -> /events/{event}/ -> /occurrences/{id}/
  /academy/ -> /academy/{course}/ -> /cohorts/{id}/
  /shop/ -> /shop/{product}/ -> cart -> checkout (signed in)
  /stays/ | /flights/ | /rail-bus/ | /transfers/ | /rentals/ -> request a quote
  /journal/ | /about/ | /contact/ | /policies/
/account/ -> profile, travelers, bookings, orders, support, notifications
/partner/ -> organization selector, proposals, bookings/assignments as authorized
/agency/ -> approval queue, inventory, exceptions, support, finance, content, audit
```

Paths are illustrative, not committed WordPress rewrite rules. Public pages are indexable only when published and useful; account, partner, cart, checkout, payment return, search result variants and administrative screens are private/nonindexable as appropriate. Server authorization is required regardless of robots instructions. Destinations may cross multiple product types; filters must not silently imply live supplier inventory. A course page describes reusable material and links to separate cohort pages; occurrence/departure and inventory are separate records.

## 2. Navigation and hierarchy

Header: logo to home, Tours, Events, Academy, Shop, More (stays, transport and other request categories), search, language and account. Always show route to support. Desktop uses text navigation; on mobile a menu button opens a labeled dialog/drawer with focus management; optional bottom navigation is an OPEN DECISION subject to small-screen usability tests and must not cover content. Breadcrumbs represent the public hierarchy and link each ancestor; filtered search and direct deep links return via understandable navigation rather than assumed browser history. Partner header always identifies current organization; changing organization triggers a scoped refresh without carrying another organization's identifiers or cached data.

## 3. Taxonomy and search

| Dimension | Values/behavior | Governance |
|---|---|---|
| Offering type | tour, event, course/cohort, merchandise, stay/transport RFQ | fixed canonical internal IDs; localized labels |
| Place | country > city > named place, multiple per itinerary | normalized IDs; human checks ambiguous locations |
| Time | departure/occurrence start, registration period, zone | server compares UTC instants; filters show local interpretation |
| Price | numeric integer IRR, optional displayed toman | no mixed-unit comparison; RFQ has no false numeric rate |
| Attributes | language, accessibility information, duration and inclusion | controlled vocabulary with source and last review |
| Availability | not open, open, sold out, closed, on request | actual server state; never conflate request with reservable |

Search exposes text and destination first, then date, type, price, duration, accessible features and sort. Preserve applied filters in accessible chips and offer one clear-all button. For Persian search normalize Arabic/Persian yeh and kaf and whitespace for matching while preserving source text; review transliteration and spelling fallback with native editors. Do not index private names, travel documents or supplier costs. A loading state announces progress politely; empty state explains filters and reset; errors retain selections and offer retry. Exact relevance, geosearch and provider integration are future decisions and must not be presented as measured relevance.

## 4. Content ownership and route policies

Agency owns public routes and editorial approval; organization members may create only authorized drafts. Content IDs remain stable across translation and slug changes; each language gets a preferred canonical URL under an approved routing scheme. **RECOMMENDATION:** language-prefixed routes such as `/en/` and `/fa/`, subject to translation-plugin compatibility decision. Translation availability, hreflang pairing, redirects, pagination and duplicate handling are verified in [content/SEO](DOC-18_CONTENT-SEO-GROWTH.md). External booking links require explicit disclosure and destination verification; no supplier deep link is presumed.

## 5. Fictional path and validation

A visitor enters Persian, searches “Tehran”, selects Events, opens a public event and sees the June occurrence's closed/open state. They sign in at checkout and return to the same authorized offer, if the hold is still valid; the server must recheck it. A screen reader announces navigation regions and current page. Validate sitemap with a card sort and task-based test for customer, agency and two partner memberships; measure task completion without treating proposed taxonomy as user research. Design owner resolves navigation and language-route decisions before implementation.

**Self-assessment (0–5):** completeness 4 (core spaces); specificity 4 (route and taxonomy rules); consistency 4 (request vs booking); user value 4 (wayfinding); WP feasibility 4 (routes illustrative); security/privacy 4 (private routes); compliance 2 (indexing/privacy policy approval missing, blocker); accessibility/localization 4 (navigation and normalization); testability 4 (task scenarios); traceability 4 (requirement links). No user research is claimed.
