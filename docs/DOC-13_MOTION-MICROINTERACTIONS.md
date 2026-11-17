# DOC-13 — Motion and microinteractions

**Status:** DRAFT specification; review pending. **Purpose:** make movement useful, optional and accessible. **Prepared from perspectives:** motion design, accessibility, performance, UX. **Accountable reviewer:** design-system lead. **Dependencies:** [UI kit](DOC-12_DESIGN-SYSTEM-UI-KIT.md), [accessibility](DOC-16_ACCESSIBILITY-INCLUSIVE-DESIGN.md). **Approval gate:** design and accessibility reviewers approve actual rendered motion on representative devices.

## 1. Motion rules

Motion indicates a state change or spatial relationship, never hides prices, consent or status. The default is restrained; all duration ranges below are proposed and subject to testing. Respect `prefers-reduced-motion`, low-power and reduced-data preferences where detectable; alternatives convey identical information without motion. No flashing/strobing, autoplay sound, parallax that impedes reading, fake progress or required mascot interaction. A user can interrupt interaction; keyboard and touch do not depend on hover. Do not animate fields while a user enters payment/traveler data, legal terms, a countdown, price changes or error correction.

| Interaction | Trigger and purpose | Proposed timing / easing | Reduced motion and input / cost |
|---|---|---|---|
| Page transition | route changes: orient navigation | no global transition by default; optional opacity 100–150 ms ease-out | instant; preserve focus and history; no full-page animation cost |
| Hero reveal | first render, introduce editorial image | optional opacity 150–250 ms ease-out | static poster; never delay H1; defer heavy media |
| Card hover/focus | indicate actionable card | color or underline 100–150 ms ease-out | immediate; focus ring persists; touch shows standard pressed state |
| Skeleton | asynchronous loading | static shapes preferred; optional subtle shimmer 800–1200 ms linear | static with text “Loading”; never imply progress percent |
| Itinerary route | opening timeline map | optional line draw 250–400 ms ease-out | complete line shown immediately; structured list is authoritative |
| Step progress | successful step advance | 120–200 ms ease-out | instant label/current-step update; focus on heading |
| Booking status | state transition after server result | short check appearance 150–200 ms ease-out | static text/icon; success only after actual verified state |
| Toast | nonurgent status | 100–150 ms ease-out | instant; essential notices persist and are accessible elsewhere |
| Loading | request in progress | static label plus optional spinner, 1000 ms per rotation | static label; timeout/retry available; avoid forced endless motion |
| Map pin | selecting stop | color/outline change 100–150 ms | instant; selected stop also appears in list |
| Mascot moment | optional editorial area | ≤400 ms ease-out, user initiated | poster and alt treatment; no checkout or form use |

## 2. Implementation and interruption

Use opacity or transforms sparingly, avoiding repeated layout changes; profile real devices before choosing animation technique. Cap simultaneous animations and stop offscreen work. Opening an accessible modal must not wait for animation to set focus; closing restores focus even when motion is interrupted. A user returning from a gateway should immediately receive truthful pending/verified/exception status; no celebratory motion based on redirect alone. Announce status changes through an appropriate live region, not the animation itself. Do not repeatedly announce the visual hold countdown; expose absolute deadline and provide a user-requested update if needed.

## 3. Fictional example and tests

A user selects a stop in a five-day itinerary. The corresponding list item and map pin acquire a visible selected state immediately; on standard settings a short route line animation may follow. A keyboard-only user can select the same stop, and a reduced-motion user sees the complete route instantly. Test rapid repeated selection, page navigation mid-animation, pause/background, screen reader status, low-end CPU and 200% zoom. Confirm no essential state is conveyed solely by movement and no visual layer obscures controls.

**OPEN DECISION:** actual animation library, if any, depends on compatibility, maintenance and performance budget; core tasks require none. The art director must approve illustrations before animation work and verify license rights. Source of reduced-motion standard: [WCAG 2.2](https://www.w3.org/TR/WCAG22/) (retrieved 2026-11-17; implementation conformance unverified).

**Self-assessment (0–5):** completeness 4 (key interactions); specificity 4 (timing and fallback); consistency 4 (no premature success); user value 4 (clarity); WP feasibility 4 (no mandatory library); security/privacy 4 (no personal data); compliance 2 (actual audit absent, blocker); accessibility/localization 4 (equivalent states); testability 4 (interruption matrix); traceability 4 (component links). Durations and performance remain proposals.
