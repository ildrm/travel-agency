# Prepared ZarinPal V4 adapter contract

Implementation baseline: 2026-11-17. The adapter is implemented; actual ZarinPal sandbox/live acceptance has not been performed. All provider requests remain disabled in this workspace except fictional WP HTTP interception inside guarded local CLI fixtures. SEP and Mellat remain unavailable boundaries. This is a payment adapter, not a refund/payout or split-settlement implementation.

## Primary contract evidence

Read the official [payment request](https://www.zarinpal.com/docs/sdk/nodejs/method/request), [verification](https://www.zarinpal.com/docs/sdk/nodejs/method/verify) and [inquiry](https://www.zarinpal.com/docs/sdk/nodejs/method/inquiry) documentation, and the official PHP SDK pinned at commit `7025d1562796938f08e95e54d7ac272d1fcec2c1`:

- [Options.php](https://github.com/ZarinPal/zarinpal-php-sdk/blob/7025d1562796938f08e95e54d7ac272d1fcec2c1/src/Options.php): fixed production and sandbox origins.
- [PaymentGateway.php](https://github.com/ZarinPal/zarinpal-php-sdk/blob/7025d1562796938f08e95e54d7ac272d1fcec2c1/src/Endpoint/PaymentGateway/PaymentGateway.php): POST paths and redirect construction.
- [RequestRequest.php](https://github.com/ZarinPal/zarinpal-php-sdk/blob/7025d1562796938f08e95e54d7ac272d1fcec2c1/src/Endpoint/PaymentGateway/RequestTypes/RequestRequest.php), [VerifyRequest.php](https://github.com/ZarinPal/zarinpal-php-sdk/blob/7025d1562796938f08e95e54d7ac272d1fcec2c1/src/Endpoint/PaymentGateway/RequestTypes/VerifyRequest.php), [InquiryRequest.php](https://github.com/ZarinPal/zarinpal-php-sdk/blob/7025d1562796938f08e95e54d7ac272d1fcec2c1/src/Endpoint/PaymentGateway/RequestTypes/InquiryRequest.php): payload fields.
- [VerifyResponse.php](https://github.com/ZarinPal/zarinpal-php-sdk/blob/7025d1562796938f08e95e54d7ac272d1fcec2c1/src/Endpoint/PaymentGateway/ResponseTypes/VerifyResponse.php), [RequestResponse.php](https://github.com/ZarinPal/zarinpal-php-sdk/blob/7025d1562796938f08e95e54d7ac272d1fcec2c1/src/Endpoint/PaymentGateway/ResponseTypes/RequestResponse.php), [Validator.php](https://github.com/ZarinPal/zarinpal-php-sdk/blob/7025d1562796938f08e95e54d7ac272d1fcec2c1/src/Validator.php): response/identifier shapes. The SDK was read, not installed or copied wholesale.

The request documentation requires a minimum of **10,000 IRR**, while this pinned SDK validator defaults to 1,000. The adapter deliberately enforces the stricter documented 10,000 IRR. Merchant acceptance must resolve this documentation discrepancy before deployment.

## Wire behavior

| Operation | Prepared transport |
|---|---|
| Request | `POST /pg/v4/payment/request.json`: server merchant ID, stored integer `amount`, explicit `currency=IRR`, callback URL and minimal order-number description. Optional customer metadata, PAN and wages are omitted. |
| Redirect | Fixed selected origin plus `/pg/StartPay/{authority}`. Authority must match the pinned SDK's `^[AS][0-9a-zA-Z]{35}$`; response URLs are never accepted. |
| Verify | `POST /pg/v4/payment/verify.json`: server merchant ID, persisted amount and persisted authority. Callback totals are ignored. |
| Inquiry | `POST /pg/v4/payment/inquiry.json`: server merchant ID and persisted authority. Used to investigate uncertain verification; its status does not create a new monetary proof. |

Origins are exactly `https://sandbox.zarinpal.com` and `https://payment.zarinpal.com`. WordPress safe HTTP handles transport with TLS verification, a 15-second timeout, zero redirects and a 64 KiB response limit. Responses are reduced to bounded code/authority/reference/status/amount fields; raw bodies, PAN/card hash, arbitrary provider messages and optional metadata are not persisted or logged by this adapter.

Verification code 100 plus a valid reference establishes the independently stored provider fact. Code 101 means already verified; **without an independently stored code-100 reference/proof it remains unknown for manual review**, even if inquiry reports a successful state. The reviewed contract establishes no trustworthy provider-paid timestamp, so none is passed to Core. Recovery or callbacks after the hold deadline follow Core's existing exception rules and cannot reacquire released capacity.

## Explicit deployment gates

Default configuration permits no provider I/O. Configure only on the server; there is no browser-controlled enable switch or merchant form.

| Setting | Requirement |
|---|---|
| `ATLAS_ZARINPAL_MODE` | Exact `sandbox` or `live`. |
| `ATLAS_ZARINPAL_MERCHANT_ID` | Actual merchant UUID supplied through deployment secrets/configuration. Never put real values in fixtures or reports. |
| `ATLAS_ZARINPAL_MERCHANT_CONFIRMED` | Explicit boolean `true`. Configuration is a human assertion, not automatic KYB verification. |
| `ATLAS_ZARINPAL_SANDBOX_ENABLED` | Boolean `true`; only local/development/staging environments, with an HTTPS callback. |
| `ATLAS_ZARINPAL_LIVE_ENABLED`, `ATLAS_ZARINPAL_LIVE_APPROVED` | Both boolean `true`, WordPress production environment, HTTPS callback, and nonempty `ATLAS_ZARINPAL_MERCHANT_EVIDENCE` reference. |

`Atlas_ZarinPal::ready()` additionally requires validated transactional attempt storage and ready Core services. Core's `atlas_core_verified_service_gateway` server filter admits `atlas_zarinpal` only when this readiness contract is true. Classic checkout/order-pay and Blocks use the same server policy; Blocks includes an explicit accessible payment `ariaLabel`. HPOS orders and line metadata are accessed through Woo CRUD.

## Durable states and reconciliation

There is one durable attempt per Woo order, unique environment/authority and unique merchant/environment/provider reference. Exact amount/currency, order/customer/booking binding, merchant/environment fingerprint and a hash of a random 256-bit callback state are persisted. No plaintext callback state is stored. Schema readiness checks required column types and unique index order before marking the schema valid.

Initiation checks the signed-in order owner, unpaid ZarinPal order, IRR total, booking linkage and `Booking::can_initiate_payment`. It repeats these checks under a per-order database advisory lock, commits `requesting` before HTTP, and rechecks before redirect. Pending retries reuse the same stored authority while still eligible. Interrupted/unknown requests never blindly initiate again; absent authority requires operator reconciliation with the real merchant dashboard. Existing-attempt guards also prevent switching an unresolved order to another gateway through classic checkout/order-pay or Store API dispatch. Ordinary orders without such an attempt keep their normal payment methods. Changing merchant, environment or callback configuration cannot silently reconcile an old attempt using a different merchant.

Short database transactions cover each attempt/audit/outbox write. No transaction spans provider HTTP, Core `Booking::verify`, or Woo payment completion. A stored code-100 proof is applied through Core's replay-safe provider reference and Woo `payment_complete`; later recovery retries these local operations. Changed order owner/amount/method/state and another payment on the order retain the monetary fact and require review. A verified payment is never changed to failed because of return-page text or local fulfillment failure. No refund is inferred or executed.

The callback is the normal Woo endpoint `?wc-api=atlas_zarinpal` with opaque `attempt`/`state`; provider `Authority` must match storage. This preserves ordinary WordPress cookie identity without requiring the PSP to carry a WordPress REST nonce. The endpoint independently verifies and ignores raw `Status`, including NOK. Anonymous/unrelated users always receive the same generic received response. Only an authenticated current owner matching both attempt and order can receive a receipt redirect. Deployment access logs and HTTP debug instrumentation must redact callback state and avoid raw financial response logging.

Agency finance can inspect the private, bounded WooCommerce → ZarinPal reconciliation screen or nonce/capability-protected `GET /agency/v1/zarinpal/attempts`, and request reason-audited inquiry/verification of an existing attempt through the screen or `POST /agency/v1/zarinpal/attempts/{id}/reconcile`. These actions never create a new PSP request.

## Verification boundary

`tests/zarinpal-integration.php` exercises the real adapter against a guarded `pre_http_request` fixture. The fixture seam requires local PHP CLI and an explicit test constant, permits sandbox mode only, intercepts every HTTP request, and refuses readiness without interception. It tests ownership, redirects, callback tampering/privacy, durable unknowns, 101, inquiry limits, amount mismatch, replay, Core/Woo application, deadline checks and absence of stored card fields. No real merchant credentials, request, charge, refund, payout or message are used. Final executed results belong in `implementation/TEST-EVIDENCE.md`.

Remaining acceptance includes actual merchant activation and provider sandbox/live evidence, real callback delivery and outage behavior, qualified security/finance/native-Persian review, retention/monitoring and bank/provider statement reconciliation. Prepared source and fictional tests do not satisfy those external gates.
