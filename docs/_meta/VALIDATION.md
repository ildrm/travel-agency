# Documentation validation record

Baseline date: 2026-11-17. This record concerns the specification files only. No WordPress theme, plugin, provider integration, browser test, security audit or benchmark has been executed.

## Automated checks

Executed result: all 49 required DOC files present; 22 baseline FRs, 9 NFRs, 40 canonical entities, 20 ADRs, 20 risks and all eight scenario labels covered by the checks below. No missing required metadata, broken relative DOC links or unbalanced fenced blocks found. See the generated JSON for the exact final counts and error list. The copied source prompt's SHA-256 matches the supplied original: `9156931fb77de67c923dbc6b718a25b3257f5ecf29c7c8374ee844ac7f1f92d7`. The tracked README change also passed `git diff --check`.

Run `python3 docs/_meta/check_docs.py` from the repository root. The script compares DOC filenames against the original brief's registry, checks substantive document length (at least 500 whitespace-delimited words, a completeness safeguard rather than a quality score), required metadata/self-assessment, balanced Markdown fences and existence of relative link targets. It checks all 22 baseline functional requirements and 9 nonfunctional requirements occur in the SRS and traceability matrix, all canonical entities occur in the data dictionary, the 20 ADRs/risks are present in their ledgers, and all eight worked scenario labels are present in the appropriate documents.

The script writes [manifest.json](manifest.json), [id-registry.json](id-registry.json) and [validation-results.json](validation-results.json). Reference lists are generated from document links; they do not claim to represent a formally acyclic dependency graph. The ID registry indexes mentions; a mention is not proof of a unique definition or semantic consistency. Links to external URLs are not validated by this script. Mermaid syntax/rendering and actual UI/API/schema behavior are not executed.

## Cross-document review performed

Parallel author reviews checked product packaging, MVP boundaries, entity/API conventions, registration admission, payment/expiry race, currency arithmetic, org authorization, all eight fictional scenarios, and representative screen-ID collisions. Corrections incorporated:

- Theme product has separate companion plugins and safe dependency absence/theme switching.
- Screen IDs now agree for tour builder/public detail, accommodation customer/partner forms, organization onboarding/dashboard, rental request/checklist and passenger request.
- Hold API accepts offer/version and resolves occurrence server-side; cohort enrollment links one accepted hold without allocating another seat.
- Ordinary admission uses a half-open window. Deadline prevents fresh payment/admission; local allocation releases or consumes under one row lock. Independent local verification by deadline can use local completion time; later verification needs trustworthy provider paid_at and unreleased allocation. Released allocation never reacquires automatically; expired booking plus later verified payment becomes exception, payment remains verified.
- Blocks is primary; classic checkout is supported only if explicitly declared and tested.
- IRR/toman conversion is explicit; scenario arithmetic and dates were reviewed; RFQ/later features do not claim live inventory.
- UI analytics labels require approved dictionary registration before collection. Crypto is OFF and no legal/vendor approvals are fabricated.

## Evidence limits and human gates

All DOC files are written draft specifications, with human approval pending. The quality scores are author self-assessments, not independent audit results. Provider documentation, merchant eligibility, legal/jurisdiction/tax/retention decisions, actual version matrix, native Persian review, asset rights, usability/accessibility tests, production staffing, reliable hosting and restore/reconciliation evidence remain open. These are tracked in DOC-06/43/45/48.

The package is usable for implementation planning and design review. External-dependent features must not be represented as build-approved or production-ready on the strength of these documents alone.
