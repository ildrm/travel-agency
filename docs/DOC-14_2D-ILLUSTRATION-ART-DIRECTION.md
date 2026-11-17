# DOC-14 — 2D illustration art direction

**Status:** DRAFT specification; review pending. **Purpose:** guide optional original illustration without compromising trustworthy travel information. **Prepared from perspectives:** creative direction, illustration, content, accessibility and performance. **Accountable reviewer:** creative director. **Dependencies:** [visual scope](DOC-06_TERMINOLOGY-RULES-DECISIONS.md), [UI kit](DOC-12_DESIGN-SYSTEM-UI-KIT.md), [characters](DOC-15_3D-FANTASY-CHARACTERS.md). **Approval gate:** brand owner, cultural reviewers and accessibility lead approve concepts and rights.

## 1. Direction options

| Direction | Visual language | Benefits | Tradeoffs |
|---|---|---|---|
| A. Atlas paper | layered paper shapes, fine route lines, subdued mineral colors | clear, lightweight, complements real photography | may feel restrained for playful campaigns |
| B. Starlit expedition | bold night gradients, fantasy creatures and luminous waypoints | distinctive editorial storytelling | higher contrast, cultural and file-size risks |
| C. Local field notes | line drawings, stamps and specimen-card layouts | strong destination and educational storytelling | demands local contributor review to avoid stereotypes |

**RECOMMENDATION:** A as a subtle default, combining selectively reviewed field-note details. User-provided brand approval is outstanding; no actual logo or art exists. Prioritize true, current photography or factual descriptions of facilities. Fictional visuals are clearly editorial and do not depict imaginary bookable amenities.

## 2. Asset specification

- Hero: 16:9 desktop and portrait-safe crop; subject avoids 40% central text-safe area. Keep a simple backdrop and independent HTML heading/CTA; no prices or embedded essential text in image.
- Destination spot art: landscape/portrait variants with space for localized captions; accurate geographic motifs only after local review. A stylized map is decorative unless paired with an accessible place list and fact-checked labels.
- Icons: consistent 24-unit viewbox, rounded joins, predictable stroke; distinguish transport, accessibility and warning symbols with text. Authoring SVGs must be sanitized before upload and avoid scripts/external links.
- Badges: represent known categories or actual verified achievements only; no invented certifications, star ratings or testimonials. Accessibility description is empty alt when decorative; meaningful diagrams need a concise equivalent text description.
- Event and Academy scenes: depict inclusive people in varied settings without relying on a specific identity as shorthand; test cropped views, high contrast and small sizes.

## 3. Production briefs and conceptual prompts

These are text briefs, **not generated assets**. All outputs require human quality, provenance and cultural review.

1. **Homepage editorial hero:** “Original layered-paper landscape with a winding path connecting abstract mountain, coast and city silhouettes; warm daylight, mineral teal and clay palette, open text-safe area, no logos, no lettering, no real destination claim; 16:9 and vertical crops.” Use as decoration adjacent to factual headline.
2. **Onboarding:** “Friendly abstract compass made from folded paper marking three numbered stages, calm geometric shapes, ample white space, no written characters, original design.” The UI supplies stage labels.
3. **Itinerary:** “An editorial five-stop route represented as colored paper markers on an abstract terrain, clear separation between markers, no false geographic detail or map labels.” The real accessible itinerary is a structured list.
4. **Event and Academy:** “Inclusive adults learning outdoors and in a simple classroom, accurate general equipment, layered-paper aesthetic, no identifiable brand or fictional safety claim.” Real venue photos remain separately labeled.

## 4. Export and review pipeline

Creative owner records source, creator, license, territorial rights, expiry and approved use; no copyrighted franchise imitation or proprietary font redistribution. Export SVG for approved vector icons, optimized WebP/AVIF where supported and a compatible fallback, plus original source files in an approved rights-controlled repository. Set actual pixel dimensions, avoid layout shift, strip unneeded metadata, test crop at 320 CSS px and with 200% text enlargement. Image budgets and target file sizes are proposed in design review, measured on real files, not asserted here. Local reviewers inspect representation; accessibility lead decides meaningful versus decorative alt; performance lead checks delivery; product owner signs off truthful use.

**Fictional example:** a souvenir card may use an original geometric mountain spot illustration while the actual product photo and shipping details identify what the customer receives. The art must not imply a nonexistent product variant. **OPEN DECISIONS:** approved palette, suppliers, asset rights, culturally appropriate imagery, actual performance budget.

**Self-assessment (0–5):** completeness 4 (directions/briefs/pipeline); specificity 4 (placement and prompts); consistency 4 (optional layer); user value 4 (clarity/trust); WP feasibility 4 (standard media formats); security/privacy 4 (SVG handling); compliance 2 (rights and local review pending, blocker); accessibility/localization 4 (text-safe/alt); testability 4 (crop and rights checks); traceability 4 (FR-ART-001). No illustrations have been made.
