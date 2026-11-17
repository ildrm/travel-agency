# Implemented API contract evidence

Specification: docs/DOC-26. Source: atlas-core/src/Rest.php, atlas-operations and atlas-inventory. All `/agency/v1` and `/atlas-inventory/v1` routes are internal implementation interfaces, not provider APIs. Exact currently registered routes are exported after final migration into `registered-routes.json` when the runtime is stable.

Browser mutations require WordPress authenticated cookies plus `X-WP-Nonce` for `wp_rest` and an idempotency key for retry-safe create/payment/booking operations. WordPress cookie auth without a valid nonce yields anonymous identity; service permissions still verify owner/org/capability. Public catalog is a filtered published projection. There is no route that accepts a user-supplied verified-payment flag. Anonymous private resources are denied; anonymous RFQ intake remains unavailable until abuse/idempotency controls exist.

Hold: `POST /agency/v1/holds`, JSON `offer_id` (integer), `offer_version` (integer), `quantity` (positive integer), `participant_refs` (owned references if eligibility requires); `Idempotency-Key` header. Server decides actor, organization, amount, availability, UTC time, policy and allocation. Same key/same canonical data retries return original allocation; differing data conflicts. Response includes opaque booking/hold IDs, acceptance/expiry/payment deadline, integer IRR quote. Exact close forbids ordinary admission.

Travelers: authenticated owner-only create/list; self-declared birth-date and guardian evidence does not represent verified identity or legal eligibility. Existing bookings/materials have a separate access policy from open enrollment. Errors must remain actionable and private; inspect actual test evidence rather than assuming every proposed DOC-26 endpoint is implemented.

External PSP callback schemas are deliberately not invented. Three disabled provider boundaries await current merchant documentation and actual contracts. Local simulation is available only under exact local environment plus explicit demo constant. Public inventory/supplier issuance/crypto/payment activation cannot be unlocked by caller-controlled JSON or frontend flags.
