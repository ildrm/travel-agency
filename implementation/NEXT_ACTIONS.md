# Deterministic continuation checkpoint

Last completed: recursive document/team intake, isolated official WordPress/Woo runtime installation, core/operations/gateway/theme/local-inventory implementation written. First core run 16 pass/1 failed test-worker bootstrap; first browser run 1 pass/5 fail from router/a11y/temporary schema mismatch. Fixes exist but need execution.

Next safe sequence (low priority, one runner):

1. `python3 deploy/local.py setup --activate-atlas` — activate complete packages, migrate core4, preserve existing local data.
2. `python3 tests/run-integration.py` — all five suites sequentially, inspect logs under implementation/evidence; repair failures before status changes.
3. `python3 deploy/local.py start`, `npm run test:browser` — headless existing Chrome, one worker; browser rerun only after core ready, no visible UI.
4. Complete actual local service hold→Woo Store API→simulated checkout→manual confirmation in EN/FA, with mismatch/replay negative tests.
5. Fill advanced field/workflow gaps listed by domain review, audit feature-level mapping and keep unsupported external methods OFF.
6. Sequential static/source/security, inventory race, backup/restore and clean-ZIP activation checks; no 100-PHP-process load on active workstation.
7. Generate deterministic packages and checksums, update traces/evidence/release reports and exact remaining gates.

Runtime secrets: .runtime/credentials.json (generated, private, gitignored). Never copy into evidence/git. All data is fictional. Runtime stop: `python3 deploy/local.py stop` shuts only this project’s DB/server. External blockers: merchant/supplier contracts+docs+credentials, legal/tax/retention, actual native/editor/brand/a11y signoffs. Code/advanced feature gaps remain implementation work, not external blockers.
