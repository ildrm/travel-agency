# DOC-25 — WooCommerce commerce engine

**Purpose:** Specify product mapping, checkout, order and payment integration without conflating commerce with fulfillment.  
**Prepared from perspectives:** WooCommerce, payment, inventory, finance and QA.  
**Accountable reviewer:** WooCommerce architect; finance and operations approve.  
**Dependencies:** [booking](DOC-24_BOOKING-INVENTORY-REGISTRATION.md), [payment due diligence](DOC-37_PAYMENTS-DOMESTIC-GATEWAYS.md), [finance](DOC-39_FINANCE-PRICING-SETTLEMENTS.md).  
**Approval gate:** Supported commerce matrix, gateway contract and test evidence approved.  
**Status:** DRAFT specification; review pending.

## 1. Sources of truth and product mapping

WooCommerce owns product/order/line item commerce snapshots, shipping and configured tax calculation; domain tables own reservations, eligibility, inventory holds and service fulfillment. Use Woo CRUD and public supported APIs rather than assuming orders are posts, and declare/test HPOS compatibility. Agency is proposed single merchant of record. Partner balances are a separate ledger; no automatic bank split is assumed.

| Offering | MVP mapping | Notes |
|---|---|---|
| Souvenir | Normal Woo physical product/variation with SKU, physical inventory and shipping class | Fulfillment and returns are merchandise workflows. |
| Event occurrence | Woo purchasable offer linked to occurrence, accepted hold and snapshot | Public registration must be in window; order/payment is not final event admission. |
| Tour departure | Woo purchasable offer linked to approved dated departure and hold | Itinerary and supplier content have their own version/permissions. |
| Academy cohort | Woo purchasable offer linked to cohort and hold | Course is reusable; enrollment belongs to a specific cohort. |
| Stays, transport, rentals | RFQ only; no paid Woo service cart in MVP | P2/P3 after provider and operational evidence. |

An offer maps to a durable Woo product or variation only when its lifecycle, inventory and checkout context are clear; use an explicit linkage object and immutable booking snapshot. Do not create unbounded temporary products without cleanup, idempotency, discoverability and tax review. The design must choose one approved mapping before build and verify Woo extension points for it.

## 2. Cart and checkout

**ADR-007:** block physical/service mixtures and heterogeneous service inventory carts in MVP at add/update and server-side checkout validation. Same departure and offer may allow group quantity subject to all-or-nothing allocation. Show a localized explanation and offer separate checkout; avoid destroying the existing cart automatically. Logged-in account required. Revalidate cart compatibility, price version, hold allocation, amount and eligible gateway server-side. Each hold has deadline from DOC-24; no browser edit may extend it. Fresh stock snapshot must be checked transactionally rather than trusting catalog availability.

Woo totals are authoritative commerce snapshots at order creation. Domain quote records amount, currency, terms/version and expiry; mismatches invalidate quote and require explicit repricing. Totals, mandatory fees, taxes and shipping are itemized and visible before commitment. Coupon eligibility, tax classes and shipping destinations are configured and reviewed by finance; no unverified tax advice or invented rate. MVP amounts use integer IRR in domain contracts; Woo decimal/precision configuration and actual gateway units need integration tests. Display toman is exactly 10 IRR per toman; conversion is explicit, integer-safe and rounding policy approved. Example: 1,250,000 IRR displays 125,000 toman; gateway receives its documented configured unit, never inferred.

## 3. Payment and order lifecycle

A redirect or return page is an untrusted navigation event. Gateway creates a payment attempt, maps its provider reference to order, and verifies amount, currency, provider identity and outcome via the actual contracted provider's documented mechanism; provider paid time is required when local verification completes after the hold deadline. Handle callback duplicates through idempotency, monotonic verified payment, Woo APIs and a separate refund state. Provider timeout means unknown; query/reconcile according to real provider contract before retrying a financial action. No card number, CVV or raw payment form data is stored by the platform.

Payment success is not supplier confirmation, ticket issuance, booking confirmation, payout or refund. On verified payment with an allocated hold, apply DOC-24 timing: local verification by the deadline needs no provider paid_at; later verification needs trustworthy provider paid_at by the deadline. If eligible, move booking to `paid_pending_confirmation`; agency confirms separately. If allocation was released, a later verified payment transitions the expired booking to exception, preserves payment verification and enters manual resolution/refund; inventory is not silently reacquired. Woo may represent payment/order progress through its own standard lifecycle, but no custom booking state should overwrite or claim Woo core semantics. Refund request needs authorization, financial review, provider capability verification and reconciliation, with Woo refund linkage and separate domain case.

## 4. Blocks, classic and HPOS compatibility

Support block Cart and Checkout and test classic checkout in supported configurations. Gateway integration for Blocks requires the documented client registration and server integration, plus compatibility declarations; implementing a classic gateway alone does not establish Blocks behavior. Test extension validation via Store API paths, payment availability, errors, price changes, retries, required fields and both order storage modes where included in the support matrix. HPOS compatibility declarations must reflect real test results, and extensions should use Woo CRUD rather than direct post tables. [Woo Blocks payment method integration](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/checkout-payment-methods/payment-method-integration/) and [HPOS recipe book](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/) retrieved 2026-11-17.

## 5. Tests, recovery and approval

**TC-COM-001:** authenticated homogeneous service cart: create hold, calculate order and verify price/terms snapshot. **TC-COM-002:** attempt physical+event and two different departures through UI and Store API, plus classic endpoints only when classic support is declared; block consistently without discarding valid cart. **TC-COM-003:** expire hold before checkout/order payment and show recovery path. **TC-PAY-001:** duplicate callback produces one verified result and no double ledger event. **TC-COM-004:** simulate unknown gateway response, late paid/released hold and manual refund, reconciling order, payment and booking independently. **TC-COM-005:** tax, shipping and currency rounding match approved fixtures in Blocks and, when declared supported, classic checkout, and in declared HPOS modes. **TC-COM-006:** inaccessible or missing gateway shows useful message without fake confirmation.

**OPEN DECISION:** gateway/provider eligibility and credentials, Woo precision/units, actual supported versions, refund rights and tax configuration. Approval requires real contract, sandbox evidence, finance review, compatibility matrix and documented incident procedures.

## Review and evidence

All decisions are proposed, not approved; no implementation, operational measurement or supplier integration is implied. Accountable reviewers must record sign-off and evidence before release. Provider interfaces, compatibility versions and legal conclusions remain VERIFY BEFORE BUILD.

**Self-assessment, 0–5:** completeness 4 (document scope covered); specificity 4 (concrete contracts); consistency 4 (shared baseline); user value 4 (actionable decisions); implementation feasibility 4 (platform boundaries); security/privacy 4 (controls proposed); compliance 3 (qualified legal/provider verification outstanding); accessibility/localization 4 (cross-referenced standards); testability 4 (observable checks); traceability 4 (canonical IDs). A score of 3 is a release blocker until evidence and qualified review exist; see [risk register](DOC-45_RISKS-DECISIONS-CONTROLS.md). These are author estimates, not independent audit findings.
