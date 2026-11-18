# Continuation and production acceptance

Atlas Leisure 0.2.0 is verified locally at the source hash in the release evidence. The theme and four plugins are packaged as installable WordPress ZIPs. Use the 0.2.0 manifest when selecting files. [Release evidence](evidence/development-release.json) · [Artifacts](../dist/manifest.json)

The next engineering work is the staging acceptance matrix. Test actual upgrade from a copy of the intended deployment, paired English/Persian operation, screen readers and real devices, physical carrier/refund variations, encrypted-file backup and key recovery, monitored retries and reconciliation. Use safe dummy records until approved policy covers actual data. The branch already preserves schema migrations and the local restore rehearsal. [Feature gaps](FEATURE-GAPS.md) · [Operational runbook](OPERATIONAL-RUNBOOK.md)

The merchant owners need current SEP and Mellat contracts and an eligible ZarinPal account with an authorized acceptance environment. Supplier owners need licensed air, rail, bus, property and tracking contracts for their selected integrations. Product and legal owners need to approve payout, tariff, tax, retention and external delivery policies. The register lists each owner and acceptance requirement. [External dependencies](EXTERNAL-DEPENDENCIES.md)

For local use, `rtk proxy nice -n 19 python3 deploy/local.py demo` starts the owned loopback runtime without opening a browser. Run the tests in the order documented in the test evidence. Keep a single headless worker and leave the project's generated credentials and backups inside the private runtime directory. [Verification commands](TEST-EVIDENCE.md)
