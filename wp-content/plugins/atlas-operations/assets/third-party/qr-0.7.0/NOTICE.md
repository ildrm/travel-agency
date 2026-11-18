# Pinned QR implementation

`qr` 0.7.0 by Paul Miller is vendored unmodified from the npm release whose
repository is <https://github.com/paulmillr/qr>. The official signed immutable
release is <https://github.com/paulmillr/qr/releases/tag/0.7.0>, published
2026-08-31, commit `be475cca488635b8a71e158ac6190a5c78a201a8`.

The package declares `(MIT OR Apache-2.0)`. This project selects the MIT option
and preserves both original license texts and upstream copyright headers.
`MANIFEST.json` records the registry tarball SHA-512 integrity and the exact
SHA-256/size of each copied upstream file. No install or build scripts ran;
there are no runtime dependencies or CDN requests. Original source and current
maintenance/release notes were verified from the maintainer's repository.

Only explicit product actions request a camera. QR payloads contain opaque
signed attendee references, never attendee form answers or personal names.
Decode success is not attendance authorization; the server rechecks actor,
assignment, booking confirmation, event state, window and replay key.
