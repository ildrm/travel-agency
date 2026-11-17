# Frontend and theme implementation intake

Status: intake complete; implementation **not started**. No builds, dependency installations, browsers, tests or live transactions were run during this intake. Shared interfaces are pending parent coordination. Source hashes below identify the copies read; these are project inputs, not verified human-approved decisions. Codename Atlas Leisure remains provisional.

## Read files and integrity

| Path | SHA-256 | Bytes | Read |
|---|---|---:|---|
| `docs/_meta/AUTHORING-CONTRACT.md` | `2fdc12627964f9a6c83f70a7a233845c659c2aeb0efd258510387a57c20fadc6` | 9684 | Full text |
| `/Users/shahinilderemi/Downloads/SUPER_MEGA_WORDPRESS_WOOCOMMERCE_IMPLEMENTATION_PROMPT.md` | `297b7cf0730d76684423f2d241492929e9a3c9e7560ff1abf48ffef382d98b75` | 94966 | Full text |
| `docs/DOC-07_BRD-SRS.md` | `3d38cc51ee338042dec138fac3ff18c79ecb85dec5764c2fe0babaa5643e69ec` | 14313 | Full text |
| `docs/DOC-08_USER-STORIES-ACCEPTANCE.md` | `e644a00d9024a787bb6a331371ed142155f641cf0f35f53b9eea5b977886ca2f` | 7498 | Full text |
| `docs/DOC-09_USE-CASES-SERVICE-BLUEPRINTS.md` | `13594a9b82498ba5af956b6b1b34e7502413c8e9c50b7386fa93b6e42af3bffa` | 7176 | Full text |
| `docs/DOC-10_INFORMATION-ARCHITECTURE.md` | `f379489c69dc7b22b2b5981f125f421a5fbf869e11dd35d822fd8bd67be328e8` | 5893 | Full text |
| `docs/DOC-11_UX-FLOWS-WIREFRAMES.md` | `070965237f043522bffc1028d038a2ccf77d9523239632bc57ce96fa04e84854` | 21203 | Full text |
| `docs/DOC-12_DESIGN-SYSTEM-UI-KIT.md` | `340022e01b7ec2892cced70d4bc3941685965a031b1d6cd64c9069bfb3224212` | 6685 | Full text |
| `docs/DOC-13_MOTION-MICROINTERACTIONS.md` | `af71dc38f4258be98deca8c47cb2c4e1433d703fec051fb9ea21a89c788a0297` | 5047 | Full text |
| `docs/DOC-14_2D-ILLUSTRATION-ART-DIRECTION.md` | `6aaf5a909e8262444f9e8292476a4e03d80407728e32ec8a1f74038445404df2` | 5500 | Full text |
| `docs/DOC-15_3D-FANTASY-CHARACTERS.md` | `b2f826051ff9b587d258a503b0d80faa0c037572c00d2fe93a166b0ab0e52c53` | 9646 | Full text |
| `docs/DOC-16_ACCESSIBILITY-INCLUSIVE-DESIGN.md` | `aff7d786c6cb767ceb3fc61f49632b515f42230a5ddc23ac4f63cf8a5b7eb43a` | 6300 | Full text |
| `docs/DOC-17_I18N-EN-FA-RTL.md` | `c5e1ecacd0258abf6b9875782b5c5f02b76d5f78de8573bd95ac9d6a0b4e599f` | 7416 | Full text |
| `docs/DOC-18_CONTENT-SEO-GROWTH.md` | `fb6b096e5912cc6ce24c25dd676ffec3b73da86a5cf9adb96fa7a97a616662b8` | 6229 | Full text |
| `docs/DOC-20_WORDPRESS-THEME-SPEC.md` | `3239016548c0b74d26e5687101ada56d679ddc129aee5b7379371f997b9094a3` | 8945 | Full text |

## Theme boundary and runtime

- [DOC-20 §1–5](../../docs/DOC-20_WORDPRESS-THEME-SPEC.md): deliver separately installable WordPress block theme. Theme owns templates, patterns, tokens, stylesheet, visual presentation, i18n and progressive behavior; companion plugins own domain records, inventory, authorizations, workflows, provider integrations and payments. Switching theme cannot destroy domain/order data. Do not modify core/vendor files.
- Plain WordPress: accessible generic pages/editor/navigation continue working. Without Woo, hide or clearly disable commerce; Woo alone supports ordinary merchandise with no travel bypass; absent/incompatible companion gives admin guidance and disables unsafe booking. No invented inventory, credentials, auto imports or unsupported gateway success.
- Woo owns product/cart/checkout/account/order flows; style Blocks and supported classic checkout without overriding totals or verification. Login is required before purchase. No unsupported HPOS/Blocks compatibility declaration before actual integration tests. No external card form or fake gateway.
- Templates: index, singular, page, archive, search, 404, Woo-compatible pages/parts; editable header/footer and patterns for discovery, event/cohort, itinerary, disclosures, support and editorial. WP user template changes survive updates. Assets are local, versioned, no hard remote dependencies; critical navigation has no-JS fallback.

## User journeys and UI/field contract

- [DOC-10](../../docs/DOC-10_INFORMATION-ARCHITECTURE.md), [DOC-11](../../docs/DOC-11_UX-FLOWS-WIREFRAMES.md): public home and destination, searchable/filterable catalog, tour itinerary/departure, occurrence, reusable course/cohort, merchandise, account/support; staff organization selector, onboarding, proposals, editorial/operations. Search retains text/destination/type/date/price filters; only real published accessible records. Empty/filter reset, loading, retry/error and useful success are explicit.
- Public tour detail excludes confidential cost fields. Event/cohort show actual schedule, IANA zone, opening/closing, eligibility, seat/registration status, transparent price and policy; quantity is a bounded positive integer. Course and cohort remain distinct. No availability/client-only eligibility decision implies a reservation.
- UI-STAY-001: city, dates, guest count and property preference; UI-TICKET-001: origin/destination/date, party and contact; UI-XFER-001: pickup/dropoff, local date/time + IANA zone, passenger/luggage count, class and contact; UI-RENT-001: pickup/return branch, date/time/zone, category and contact. These are unconfirmed requests, with no payment, inventory promise or live dispatch absent real provider capability. Server validation, storage and authorization belong to companion.
- Checkout shows itemized totals and units, inclusions/exclusions, necessary identity/contact/terms, accessible deadline and an eligible hosted gateway only. Mixed physical/service and different service inventory are rejected by plugin/Woo policy. Gateway redirect never claims payment or booking success. After deadline no new public admission/payment initiation. Hold consume/release is server-serialized and later verified money may become an exception; frontend countdown is informational.
- Partner UI uses server-authorized organization memberships and object visibility; no reliance on a hidden button or nonce for authorization. Approved leader builder presents structured stops/days/legs, ability to reorder by keyboard, capacity/price and separate private cost; staff approval is versioned in backend. Render actual API data only, never demo statistics or fake bookings. Accessible route to support and safe return after sign-in.

## Brand, assets and design tokens

- [DOC-12](../../docs/DOC-12_DESIGN-SYSTEM-UI-KIT.md): provisional semantic palette ink `#132638`, surface `#FFFFFF`, canvas `#F4F7F8`, brand `#005F73`, accent `#A1400B`, danger `#A51D2D`, focus `#003BDE`; spacing 4/8/12/16/24/32px in rem, radii 4/12px, body 1rem/1.6, h1 `clamp(2rem,4vw,3rem)`/1.2, reading width ~72ch. Map theme.json and semantic CSS variables with accessible defaults for independent plugin UI. Tokens are proposals, not a measured contrast pass or final brand.
- [DOC-14](../../docs/DOC-14_2D-ILLUSTRATION-ART-DIRECTION.md): recommend original lightweight layered-paper art with route/terrain motifs, no text in image, no fake geography, no copied/trademarked character, card disclosure or testimonial. Create genuine source-controlled SVG artwork where feasible; document authorship and license, treat decorative images with empty alt, restrict executable SVG. Actual asset and owner/cultural clearance remain review gates.
- [DOC-15](../../docs/DOC-15_3D-FANTASY-CHARACTERS.md): five proposed mascot concepts are not approved/trademark-cleared; 3D is future and OFF. Do not claim generated media or brand approval without files and evidence. No essential purchase function depends on artwork.
- [DOC-13](../../docs/DOC-13_MOTION-MICROINTERACTIONS.md): no autoplay sound/flashing, no animation on critical prices, legal, payment or error entry; optional short motion has static alternatives under `prefers-reduced-motion`. Status text, focus and functions persist with reduced data/no JS.

## Accessibility, localization and content

- [DOC-16](../../docs/DOC-16_ACCESSIBILITY-INCLUSIVE-DESIGN.md): target WCAG 2.2 AA, but do not claim conformance without audit. Use landmarks/skip link, real labels, semantic controls, persistent errors, keyboard-operable navigation/focus, text equivalents to maps, reduced motion, contrast verification, zoom and 320 CSS px reflow; verify both locales and relevant Woo states.
- [DOC-17](../../docs/DOC-17_I18N-EN-FA-RTL.md): `en`/LTR and `fa`/RTL via WP gettext and compatible domain-content strategy; approved text domain depends on shared conventions. Use logical CSS, correct document/inline lang/dir, bidi isolation for references, emails and prices. Persian text must shape, wrap and support yeh/kaf search normalization in backend; timestamps remain UTC+IANA zone, optional Jalali is a presentation-only feature. IRR integer authoritative; 10 IRR = 1 toman with explicit unit/rounding, no frontend-only finance calculations. Untranslated content requires explicit safe fallback. Native Persian and legal/finance review remains necessary.
- Public pages have factually accurate metadata, no invented price or rating; private/cart/payment/portal routes require authorization regardless of indexing rules. Language switch preserves a current record only where a genuine translation exists. Analytics names in DOC-11 are unapproved proposals and must not be collected until reviewed and mapped in DOC-42.

## Conflicts, unresolved dependencies and integration handoff

1. Implementation prompt §8.5 asks for completed original 2D/3D/characters; approved phase baseline is draft [DOC-07 §2](../../docs/DOC-07_BRD-SRS.md), [DOC-15](../../docs/DOC-15_3D-FANTASY-CHARACTERS.md) and authoring contract: MVP lightweight 2D, optional 3D/mascots future. Implement real lightweight originals and leave 3D/characters open until directed; never invent assets or brand acceptance. Parent should record the cross-scope conflict.
2. Implementation prompt seeks entire platform; docs phase work MVP/P2/P3 for dependent capabilities and use request-only for external providers. Build feasible functionality progressively while honoring supplier and legal gates and truthful states; do not advertise unsupported inventory or payment activation.
3. Exact WordPress/Woo/PHP support matrix, block theme schema, route and localization/content translation strategy, plugin identifiers, screen rendering blocks/shortcodes/hooks, API payloads, nonce/auth behavior and asset contracts are unverified or unchosen. Coordinate with parent and plugin owners before implementation; default to no booking action when companion APIs are unavailable. Native Persian review, legal/finance copy, provider/merchant eligibility, art rights and accessibility audit require actual evidence.
4. TC-THEME-001–005: dependency fallback, theme switch/data retention, preservation of editor customizations, keyboard/RTL/zoom/reduced-motion, Woo product/cart/Blocks/classic and HPOS where supported. These are targets only; no test was run and no version compatibility is asserted during intake.

## Ownership and next action

Only `wp-content/themes/atlas-leisure/**`, `tests/frontend*` and this intake file are assigned to this worker. The project starts without an existing application according to parent intake; no structure is assumed until verified. WAIT for parent-approved shared interfaces and scheduling, then implement the theme and lightweight original assets. Browser, build and expensive commands are prohibited until the parent schedules them; do not revert other contributors' work.
