# DOC-16 — Accessibility and inclusive design

**Status:** DRAFT specification; review pending. **Purpose:** specify inclusive interaction requirements and evidence for core journeys. **Prepared from perspectives:** accessibility, UX research, frontend engineering, localization and QA. **Accountable reviewer:** accessibility lead. **Dependencies:** [screens](DOC-11_UX-FLOWS-WIREFRAMES.md), [components](DOC-12_DESIGN-SYSTEM-UI-KIT.md), [localization](DOC-17_I18N-EN-FA-RTL.md). **Approval gate:** accessibility lead and product owner accept actual test results and resolved critical defects.

## 1. Standard and scope

**RECOMMENDATION:** target WCAG 2.2 AA on public, checkout, account, partner and agency interfaces, including third-party content within product control. Source: [W3C WCAG 2.2](https://www.w3.org/TR/WCAG22/) (retrieved 2026-11-17). This is a target; no audit or conformance is claimed. A hosted gateway is an external dependency: assess its accessibility with provider evidence, document any limitations and provide an equitable support path without collecting card data. Legal obligations by jurisdiction need qualified review.

## 2. Interaction and content requirements

1. Use semantic landmarks, one clear page heading, meaningful hierarchy, native links/buttons and programmatic labels. Language and direction must be correct at document and inline changes. Identical actions have consistent names across EN/FA.
2. All functions are keyboard-operable; show visible focus with sufficient contrast, no keyboard trap. Dialogs provide an accessible name, appropriate initial focus, Escape where appropriate, contained modal focus and return focus to trigger. Never use click-only divs as controls.
3. Associate errors and hints with inputs. On submit error, move focus to a concise summary linking to fields; preserve valid data. Explain how to fix errors in text, not color alone. Time limits disclose the actual server deadline and explain recovery; adjustability exceptions for inventory/payment require a reasoned WCAG assessment, not a blanket claim of exemption.
4. Offer structured itinerary lists and tables alongside maps, accessible text alternatives for charts, and meaningful alt for informational imagery. Decorative icons and mascot images use empty alt. A check-in QR code must have a text/reference or staff-assisted alternative that does not expose personal data.
5. Text and interface graphics meet applicable contrast; support resize, reflow and user spacing without loss of content or operation. At 320 CSS px avoid two-dimensional scrolling except content that genuinely requires it. Minimum target-size requirement and exceptions must be verified against the actual standard.
6. Use native status messages and polite announcements for async outcomes. Critical errors are announced and remain available; avoid repeated announcements from countdowns, animation or automatic updates. Persist essential notices.
7. Forms distinguish purchaser, traveler, attendee and guardian when relevant. Age, literacy, vision, motor and cognitive differences inform clear language and flexible assistance. Ask for dietary/access needs only where relevant, protect sensitive data and explain who uses it.
8. Respect reduced-motion settings and avoid flashing or auto-playing sound. Time-sensitive registration still needs correct live server validation, even if the client UI cannot update continuously.

## 3. Test matrix

| Area | Manual procedure | Pass condition / evidence |
|---|---|---|
| Navigation | Keyboard from page start through menu, language switch, search and account | visible focus, logical order, escape/return works; record browser and OS |
| Search/filter | Apply filters, trigger no result, clear, induce API failure | labels and status read correctly; selections persist and recovery is possible |
| Registration | Open/close boundary, final seat race, validation and hold expiry | accessible explanations match server result; no reliance on color or timer alone |
| Checkout | Complete domestic gateway test with keyboard and screen reader | cost, terms, currency, order and post-return state understandable; provider barrier logged |
| Account/partners | Access owned record and attempt cross-org URL | authorized actions accessible; unauthorized content absent and clear denial |
| Locale/zoom | EN/FA at 320 CSS px, 200% resize, text-spacing override, mixed-script reference | no clipped controls; reference remains readable and copyable |
| Visual | Measure text/control/focus colors in rendered states, forced colors, no images | labels/state remain apparent; record measurements and exceptions |
| Motion | reduced motion, interrupts, offscreen behavior | static equivalent with no blocked or time-delayed task |

Run automated scans on representative templates, but use manual keyboard and screen reader testing, including native Persian review. Record build, browser/assistive technology, locale, data fixture, failure, severity, owner, reproduction and retest. Engage people with disabilities using a real research plan and consent; do not imply such testing occurred. Triage blocking defects in essential purchase and account tasks before release.

## 4. Fictional scenario and approval

An attendee uses a keyboard to select a course cohort. The page provides cohort dates, registration window, price, prerequisites and accessible venue information as text; unavailable venue information is “not yet confirmed,” not a promise. On error the summary names the field and focus goes there; if registration closes during payment, the status accurately indicates review or pending confirmation. **OPEN DECISIONS:** supported assistive-technology combinations, accessibility support contact and provider accessibility evidence. Agency must staff any promised assistance.

**Self-assessment (0–5):** completeness 4 (critical paths); specificity 4 (manual matrix); consistency 4 (deadline states); user value 4 (equitable alternatives); WP feasibility 4 (semantic components); security/privacy 4 (minimized support data); compliance 2 (audit and legal review absent, blocker); accessibility/localization 4 (test protocol, not proof); testability 4 (pass/evidence); traceability 4 (NFR-A11Y-001). No conformance certification is asserted.
