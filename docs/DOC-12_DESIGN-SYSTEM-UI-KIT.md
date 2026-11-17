# DOC-12 — Design system and UI kit

**Status:** DRAFT specification; review pending. **Purpose:** specify reusable accessible visual tokens and components for the block theme and companion interfaces. **Prepared from perspectives:** UI, theme engineering, accessibility, Persian typography. **Accountable reviewer:** design-system lead. **Dependencies:** [theme architecture](DOC-20_WORDPRESS-THEME-SPEC.md), [accessibility](DOC-16_ACCESSIBILITY-INCLUSIVE-DESIGN.md), [localization](DOC-17_I18N-EN-FA-RTL.md). **Approval gate:** design, engineering and accessibility reviewers verify rendered component states and contrast.

## 1. Principles and tokens

The visual system prioritizes trustworthy prices, readable multilingual content and clear action hierarchy. Tokens below are **RECOMMENDATION**, not approved brand. Semantic names map consistently to theme settings and plugin UI; companion plugins must remain usable with another compatible theme, with their own accessible fallbacks and no dependence on theme-only logic.

| Token | Initial value | Intended role |
|---|---|---|
| `color.ink` | `#132638` | primary text on light background |
| `color.surface` | `#FFFFFF` | cards, fields and primary reading surface |
| `color.canvas` | `#F4F7F8` | page background |
| `color.brand` | `#005F73` | primary action and links on light backgrounds |
| `color.accent` | `#A1400B` | supporting highlight; not sole error cue |
| `color.danger` | `#A51D2D` | error text/icon on light background |
| `color.focus` | `#003BDE` | visible focus ring plus contrasting offset |
| `space.1–6` | 4, 8, 12, 16, 24, 32 px | spacing scale; use rem in implementation |
| `radius.sm/md` | 4/12 px | inputs/cards, never essential to meaning |
| `type.body` | 1rem / 1.6 | localized sans-serif stack; font licensing checked |
| `type.h1` | clamp(2rem, 4vw, 3rem) / 1.2 | responsive page title, no fixed-height crop |
| `layout.reading` | 72ch max | adapt for Persian typography and zoom |

Contrast must be measured for actual text, component and adjacent backgrounds; token selection alone is not proof of WCAG compliance. Target 4.5:1 for normal text, 3:1 for qualifying large text and required non-text indicators, with tested focus appearance. Semantic error and warning use label, icon and text in addition to color. Use tested system font stacks until selected fonts and redistribution rights are approved. No screenshot or visual benchmark exists.

## 2. Illustrative theme.json mapping

```json
{
  "version": 3,
  "settings": {
    "color": { "palette": [
      {"slug":"ink","name":"Ink","color":"#132638"},
      {"slug":"surface","name":"Surface","color":"#ffffff"},
      {"slug":"brand","name":"Brand","color":"#005f73"}
    ]},
    "spacing": {"spacingSizes": [
      {"slug":"2","name":"Small","size":"0.5rem"},
      {"slug":"4","name":"Medium","size":"1rem"},
      {"slug":"6","name":"Large","size":"2rem"}
    ]},
    "typography": {"fontSizes": [
      {"slug":"body","name":"Body","size":"1rem"},
      {"slug":"title","name":"Title","size":"clamp(2rem, 4vw, 3rem)"}
    ]}
  },
  "styles": {"color":{"text":"var(--wp--preset--color--ink)","background":"var(--wp--preset--color--surface)"}}
}
```

Illustrative only: verify schema and support against selected WordPress versions before implementation. Theme presets are the site's design token source; documented semantic CSS custom properties provide the contract for companion interfaces with safe fallback values. A design-file token mapping may use `color/brand` etc., but no Figma file is claimed. Version token changes and test derived components in both directions and other themes.

## 3. Component contract

| Component | Required props/content | States and behavior |
|---|---|---|
| Button/link | label, intent, destination or action | hover, focus-visible, pressed, disabled with reason; links navigate, buttons act |
| Field | unique ID, label, type, value, hint/error, required | native semantics; visible label, associated help/error; no placeholder-only label |
| Price breakdown | integer amount, currency unit, line labels, tax/fee status, total | localized display; unknown fees labeled, no float rounding; explicit exclusions |
| Offer card | title, destination, image alt, type, status, price or RFQ | one clear detail link, loading skeleton is not actionable, sold-out text |
| Availability picker | zone, dates, availability, selected value, instruction | keyboard and text-entry alternatives; server revalidates on submission |
| Step progress | current step, labels, state | ordered list and `aria-current` where appropriate; no click trap or color-only progress |
| Alert/toast | severity, heading, message, optional action | focus summary for failed submit; nonurgent status announced politely; no essential auto-dismiss |
| Dialog | title, description, close and return focus | initial focus, modal focus containment and Escape where appropriate; no nested dialogs |
| Itinerary timeline | day, stop/leg, local timestamps, inclusion and transit | chronological structured list; map decorative or accessible alternative |
| Language selector | language names in own language and target URL | correct `lang`/`dir`; missing translation disclosed; keyboard operable |

## 4. Responsive, accessibility and governance

Use content-driven breakpoints: single-column by default, two columns when readable with at least approximately 20rem per column; values are proposed, not device claims. At 320 CSS px and high zoom, no critical horizontal scroll. Logical properties (`margin-inline`, etc.) support RTL; avoid mirroring numerals or brand marks. Hit targets target at least 24×24 CSS px or a WCAG-permitted exception. Reduced motion removes ornamental movement. Test all components in EN and FA and on Woo blocks/traditional checkout when applicable, including keyboard, zoom, forced colors and errors.

**Fictional example:** event card with “30 seats”, “registration opens 1 June at 09:00 Asia/Tehran” and price in IRR; a status badge and visible text convey not-open state. Component owners record prop changes, visual regression review and release notes; exact font, contrast results and browser/version matrix remain open.

**Self-assessment (0–5):** completeness 4 (core component families); specificity 4 (tokens/props); consistency 4 (plugin fallback); user value 4 (clear interactions); WP feasibility 4 (theme.json illustrative); security/privacy 4 (minimal data contract); compliance 2 (font rights/claims unverified, blocker); accessibility/localization 4 (testable targets); testability 4 (state matrix); traceability 4 (linked screens). Palette requires real contrast validation.
