# DOC-20 — Reusable WordPress theme specification

**Purpose:** Specify the shippable presentation package and its behavior alongside optional or required companions.  
**Prepared from perspectives:** WordPress theme, WooCommerce, accessibility, localization and product design.  
**Accountable reviewer:** Theme lead; reviewed by product owner and accessibility lead.  
**Dependencies:** [architecture](DOC-19_SYSTEM-ARCHITECTURE-ADRS.md), [design system](DOC-12_DESIGN-SYSTEM-UI-KIT.md), [localization](DOC-17_I18N-EN-FA-RTL.md).  
**Approval gate:** Product owner accepts packaging and supported-version test matrix.  
**Status:** DRAFT specification; review pending.

## 1. Product contract

**ADR-001/002 RECOMMENDATION:** deliver a reusable installable block theme ZIP plus separately installable versioned companion plugin ZIPs; one configured site is one agency. The theme supplies generic content and WooCommerce presentation, not durable reservations, gateway credentials, partner records or payment logic. Installing the theme must not automatically purchase a third-party plugin, transmit credentials or import fictional inventory. The agency companion is needed for travel workflows, not for rendering ordinary pages.

| Runtime condition | Required visible behavior | Data and security |
|---|---|---|
| WordPress only | Header, footer, posts, pages, patterns and site editor work; commerce-specific navigation is hidden or clearly unavailable. | No fatal error or synthetic commerce availability. |
| WooCommerce inactive | Shop/cart links and product blocks fall back to a localized explanation or are omitted; no checkout promises. | Preserve orders and domain data; never delete on deactivation. |
| Woo active, companion inactive | Standard Woo catalog and checkout remain usable for compatible merchandise; booking actions are disabled with a meaningful message. | No theme-level reservation bypass. |
| Companion schema incompatible | Admin sees actionable version warning; affected purchase actions fail closed; public browsing remains usable when safe. | Never auto-downgrade schema or erase records. |
| Gateway unavailable | Checkout displays localized unavailable reason and eligible alternatives only; do not claim payment succeeded. | Durable exception/order state is managed by plugins. |

## 2. Proposed package and editorial contract

```text
atlas-theme/
  style.css                 # theme metadata and baseline styles
  theme.json                # settings, semantic tokens and editor styles
  functions.php             # presentation hooks and asset registration only
  templates/                # block templates: index, singular, archive, search, 404
  parts/                    # header, footer, navigation regions
  patterns/                 # documented editable, localized patterns
  styles/                   # optional style variations
  assets/css/               # logical-property and Woo presentation styles
  assets/js/                # progressive UI behaviors
  languages/                # generated translation templates/catalogs
  README.txt, LICENSE.md, screenshot.png
```

This is illustrative; exact Woo templates and hooks must be verified against the supported release. Theme setup must escape output, enqueue assets with versioning and load only needed scripts. No direct order SQL, tenant capability decisions, hard-coded operator copy or remote resource required for essential navigation. A consistent fallback must render when JavaScript is unavailable, except interactions explicitly marked as requiring it and tested.

The editor can alter navigation, page content, color/style variations and patterns without modifying source. Lock only brand or disclosure regions explicitly justified by product owner; operational terms come from authoritative data. Maintain customizations through standard editor mechanisms and record how template overrides interact with updates. Do not overwrite user-created content on theme upgrade; show release notes and provide preview/rollback.

## 3. Design and Woo presentation

`theme.json` maps semantic foreground, surface, action, focus and status tokens; fluid type, spacing, content widths and approved font stacks. Token values and component states are in DOC-12, not assumed final brand values. Provide patterns for destination discovery, event/cohort information, travel disclosures, support and editorial pages. Treat unverified badges, reviews and testimonials as absent. Responsive layouts use source order meaningful to screen readers; keyboard focus is visible, errors attach to labels, and non-color cues identify status. Maintain visible final prices and exclusions without animation overlays.

Support Woo catalog, product, cart, checkout, My Account and order confirmation templates in both English and Persian. The block checkout gateway requires its own integration, not just styling. Test classic checkout if declared supported, and test Blocks as the default target. Do not replace Woo totals or payment verification. Mixed physical/service cart rejection and successful payment/booking distinctions are rendered from plugin-provided state.

## 4. RTL, media and performance

Use logical CSS properties; isolate mixed direction price, URL, email and booking reference strings; translate theme strings separately from supplier content. Persist times in UTC with IANA zone at source; optionally display a Jalali date with an accessible unambiguous corresponding date. Define locale search normalization in DOC-17. Test narrow/mobile, desktop zoom and long translated strings. Characters and rich media are future enhancements with reduced-motion and static fallback; essential content is present without WebGL. Image dimensions and lazy loading should prevent layout shift; budgets are proposed until tested.

## 5. Installation, switching and update

1. Administrator backs up and records the supported WordPress, PHP, Woo and companion versions from the release matrix. Install theme, preview generic pages and activate; install Woo and companion separately only as required by chosen services.
2. Setup checks dependency states and links to documentation. It never silently changes checkout, payment activation or privacy settings. Admin selects language, menu and permitted starter patterns; imported examples are labeled fictional and opt-in.
3. On switching away, domain records, Woo orders and partner permissions remain in plugins; a new theme may not present specialized blocks, so show an operational warning and test fallback. Switching back restores normal rendering without re-import.
4. On update, stage first, test modified templates and both languages, backup, deploy versioned ZIP and retain prior artifact. Rollback theme separately; plugin schema rollback requires its own migration procedure in DOC-21.

## 6. Tests and acceptance

**TC-THEME-001:** activate with no Woo, with Woo only, and with companion; assert no fatal error, no false booking button and clear fallback. **TC-THEME-002:** switch themes with a booking/order and verify data survives and access control remains in companion. **TC-THEME-003:** change theme styles and a template, upgrade and verify user edits persist. **TC-THEME-004:** run keyboard, screen-reader, 200% zoom, RTL, long Persian title, price bidi and reduced-motion checks. **TC-THEME-005:** exercise Woo product, cart, Blocks checkout and supported classic checkout with HPOS configurations. Theme unit fixtures and an independent accessibility review are release gates.

**OPEN DECISION:** exact support matrix, distribution destination, GPL/license obligations and final art/font licenses. Product/legal/engineering owners must approve; no version support or certification is represented as verified. [WordPress theme overview](https://developer.wordpress.org/themes/getting-started/what-is-a-theme/) and [theme testing](https://developer.wordpress.org/themes/advanced-topics/testing/) retrieved 2026-11-17.

## Review and evidence

All decisions are proposed, not approved; no implementation, operational measurement or supplier integration is implied. Accountable reviewers must record sign-off and evidence before release. Provider interfaces, compatibility versions and legal conclusions remain VERIFY BEFORE BUILD.

**Self-assessment, 0–5:** completeness 4 (document scope covered); specificity 4 (concrete contracts); consistency 4 (shared baseline); user value 4 (actionable decisions); implementation feasibility 4 (platform boundaries); security/privacy 4 (controls proposed); compliance 3 (qualified legal/provider verification outstanding); accessibility/localization 4 (cross-referenced standards); testability 4 (observable checks); traceability 4 (canonical IDs). A score of 3 is a release blocker until evidence and qualified review exist; see [risk register](DOC-45_RISKS-DECISIONS-CONTROLS.md). These are author estimates, not independent audit findings.
