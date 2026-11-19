# Implemented API contract evidence

Specification: docs/DOC-26. Source: atlas-core/src/Rest.php, atlas-operations and atlas-inventory. All `/agency/v1` and `/atlas-inventory/v1` routes are internal implementation interfaces, not provider APIs. [registered-routes.json](registered-routes.json) exports 46 actual route/method entries after migration. Argument names are only registered WordPress schemas; some endpoints additionally validate JSON inside their callback. This metadata is not a complete OpenAPI contract or evidence every proposed DOC-26 route exists.

Browser mutations require WordPress authenticated cookies plus `X-WP-Nonce` for `wp_rest` and an idempotency key for retry-safe create/payment/booking operations. WordPress cookie auth without a valid nonce yields anonymous identity; service permissions still verify owner/org/capability. Public catalog is a filtered published projection. There is no route that accepts a user-supplied verified-payment flag. Anonymous private resources are denied; anonymous RFQ intake remains unavailable until abuse/idempotency controls exist.

Hold: `POST /agency/v1/holds`, JSON `offer_id` (integer), `offer_version` (integer), `quantity` (positive integer), `participant_refs` (owned references if eligibility requires); `Idempotency-Key` header. Server decides actor, organization, amount, availability, UTC time, policy and allocation. Same key/same canonical data retries return original allocation; differing data conflicts. Response includes opaque booking/hold IDs, acceptance/expiry/payment deadline, integer IRR quote. Exact close forbids ordinary admission.

Travelers: authenticated owner-only create/list; self-declared birth-date and guardian evidence does not represent verified identity or legal eligibility. Existing bookings/materials have a separate access policy from open enrollment. Errors must remain actionable and private; inspect actual test evidence rather than assuming every proposed DOC-26 endpoint is implemented.

External PSP callback schemas are deliberately not invented. Three disabled provider boundaries await current merchant documentation and actual contracts. Local simulation is available only under exact local environment plus explicit demo constant. Public inventory/supplier issuance/crypto/payment activation cannot be unlocked by caller-controlled JSON or frontend flags.

Booking status: owner-authorized `GET /agency/v1/bookings/{uuid}` returns independent persisted `payment_status` (`unverified` or `verified`) and service `status`, with `Cache-Control: private, no-store`. Monetary verification does not imply agency confirmation or supplier issuance; provider references, participants and snapshot fields are excluded. `GET /agency/v1/booking-queue` and `POST /agency/v1/bookings/{uuid}/confirm` are agency-only, bounded/reasoned/idempotent operational interfaces. Customer and partner capabilities alone cannot authorize confirmation.

Finance interfaces expose scoped operational exceptions/ledger and manual refund request/approval. There is no public route to submit trusted payment/refund proof or execute a bank transfer. Internal proof factories must only be called after real adapter verification when such an adapter is implemented and approved.


## Dated rate plans

The existing reviewed resource-offer endpoint accepts `plan_id`, `name_en`, `name_fa` and `max_guests_per_unit` alongside its versioned price and policy fields. The public offer and availability requests accept `rate_plan_id`. The booking hold options include `rate_plan_id` and `rate_version` with the accepted date interval and terms. Multi-plan offers require explicit plan selection. Legacy single-plan holds may use the existing version field. Catalog price filters use the advertised base amount. The dated quote reports its chosen plan and price separately.

The persisted booking retains the reviewed plan ID and version, exact totals and terms. Price choices for one offer share the same physical allocation. A single selected plan must cover the entire service interval.
