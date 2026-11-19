# Atlas Leisure implementation status

**1.0.0-rc.1 · VERIFIED_LOCAL · 19 November 2026**

The release contains the Atlas Leisure block theme and four WooCommerce companion plugins. The frozen build passed 172 static checks, 25 integration suites and 13 headless browser tests. All five ZIPs matched across two builds and passed installation from the archives. The isolated restore matched 102 tables and 6,541 rows by count and logical checksum. [Release evidence](evidence/development-release.json) · [Installable artifacts](../dist/manifest.json)

The English and Persian storefronts support discovery, service reservations, Checkout Blocks and private printable vouchers. The local implementation includes dated stays and rentals, manual transfer dispatch, tours, events, Academy groups, partner contracts, procurement, in-account notices and encrypted document review. Native WooCommerce manages physical shipping and offline checkout. Air, rail and bus use a private inquiry workflow until contracted ticket issuance is available. [Whole-project review](PROJECT-REVIEW.md)

The documentation intake covers 56 files and 49 specifications. The original `docs/` tree is unchanged. The traceability table preserves all 31 requirement families and the associated 811 evidence references. Broad acceptance still includes the code and verification boundaries in the feature matrix. [Inventory](00-docs-inventory.md) · [Traceability](01-requirements-traceability.csv) · [Feature gaps](FEATURE-GAPS.md)

Core schema 7, local inventory schema 3 and the companion tables passed the disposable installation. The prepared ZarinPal adapter passed fictional HTTP fixtures. SEP and Mellat await merchant specifications and credentials. Production also requires approved supplier, legal, privacy, accessibility, native-language and operations evidence. [Readiness](RELEASE-READINESS.md) · [External dependencies](EXTERNAL-DEPENDENCIES.md)
