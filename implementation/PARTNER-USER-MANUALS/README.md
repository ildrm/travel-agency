# Partner, customer and agency local workflow manual

This manual describes the implemented local development workflows. Use the theme's **My account** and **Partner portal** links, or `/?atlas_area=account&lang=en` and `/?atlas_area=portal&lang=fa`. EN/FA changes presentation; it does not change access rights. The latest executed checks are recorded in [TEST-EVIDENCE.md](../TEST-EVIDENCE.md). Full module boundaries remain in [FEATURE-GAPS.md](../FEATURE-GAPS.md).

## Customer account and receipts

Guests see WooCommerce's native login and registration form. Local setup enables account registration with a customer-chosen password; purchase requires sign-in. Returning from authentication preserves a valid same-origin published-offer intent. Password resets and order emails are not delivered in the local demo: its mail guard returns failure instead of sending outbound messages.

**My reservations** shows only the signed-in customer's bookings, their references, exact IRR amounts and actual booking state. Use **View payment receipt** for a paid service or **View order details** for a service with no amount due. A receipt and Woo order status do not establish service confirmation or ticket issuance.

| Booking state | Meaning and next step |
|---|---|
| `held` / `pending_payment` | A temporary allocation or pending payment; observe the reservation deadline. |
| `paid_pending_confirmation` | Payment is verified; await agency confirmation. The receipt is not an issued travel ticket. |
| `confirmed` | The agency confirmed the service reservation. Supplier tickets/documents, where applicable, remain separate. |
| `fulfilled` | Service fulfillment is recorded; refunds and supplier settlement remain separate. |
| `exception` | Agency review is required. A received payment creates no new seat or confirmed ticket. Contact support. |
| `expired` / `canceled` | The reservation is unavailable. A receipt does not restore a released place; any refund has its own process. |

Customer support cases and their shared replies appear in the account. Internal agency notes are excluded. A new customer reply to a resolved or closed case reopens it for review. Course materials require a linked confirmed enrollment; an authorized learner can continue reading after registration closes.

## Agency booking confirmation

In **Partner portal**, expand **Booking confirmation queue**. Agency access is required; ordinary customers and partner roles cannot use it.

1. Select **Ready for confirmation**, **Needs review** or **Confirmed**. Use **Refresh queue** and the previous/next page controls to locate the reference. The queue displays separate booking and payment states.
2. For a paid reservation awaiting confirmation, check actual service availability and supporting fulfillment evidence. Enter the real confirmation reason in the row.
3. Use **Confirm service**. The server checks authorization and booking state and binds the request key to that reason. A repeated identical request does not confirm twice.
4. Check the returned booking state. The customer's account/receipt reflects the confirmed reservation on refresh. Confirmation does not issue a supplier ticket or complete a refund.

An exception is a review task, not permission to force confirmation. If allocation was released, preserve payment evidence and resolve the refund/alternative separately; do not reacquire inventory by editing records.

## Finance exceptions, ledger and refund decisions

The **Payment exceptions and refund review** panel requires both agency access and the WordPress `atlas_manage_finance` capability. An organization role or a customer carrying that capability alone is insufficient.

Use the bounded exception pages to find the exact payment reference, verified payment state, exception code and literal IRR amount. **Review ledger and requests** opens that payment's ledger and recorded refund requests, each with page controls. An excess payment can coexist with a confirmed booking; resolving it must preserve the original entitlement.

To request a refund, enter the verified payment reference, an exact positive integer IRR amount and a reason, then choose **Record refund request — no transfer**. Requested/approved/submitted/succeeded amounts reserve the payment balance; the server rejects over-refunds. Replaying the same key and payload returns the original request. After an uncertain network response, inspect recorded requests before initiating another.

A requested refund can be reviewed with a reason and **Approve for processing — no transfer**. Approval is replay-safe and remains `approved`. It neither sends money nor creates a verified refund debit. Only a trusted server adapter can reconcile completion; no public refund-proof endpoint or operator button accepts a claimed successful transfer. Real provider processing, bank reconciliation and partner payouts remain outside the completed local scope.

## Support conversation and triage

Expand **Customer support queue**, then the case subject. Record a shared reply or explicitly select **Internal note — hidden from customer** for agency-only content. **Next action visible to customer** is shared text; keep internal details in internal notes.

The form supports `new`, `triaged`, `waiting_customer`, `waiting_supplier`, `in_progress`, `resolved` and `closed`, subject to valid transitions. Resolution requires an allowed outcome such as answered, duplicate, withdrawn or other. It does not mark a refund paid. A stale version is rejected: refresh and review the latest case before retrying. Priority and assigned-user fields also exist in the scoped triage API; the current portal form exposes state, next action and resolution.

The current support queue is bounded and the thread displays at most its first 100 messages. Complete history navigation and actual email/SMS/CRM delivery remain unfinished. Recording a reply or outbox event is not evidence that a notification was delivered.

## Organizations, onboarding and staff roles

A partner organization is a scoped business record. It does not grant WordPress administration. Applicants create and submit their own application. In **Partner applications and staff**, an independent agency operator reviews the submitted version, chooses approve/reject, supplies an opaque reviewed-evidence reference and records a reason visible to the applicant. Keep private identity files out of that reason and ordinary public media.

Approval atomically creates one approved organization and an active applicant membership; an identical replay does not create another organization. Portal approval uses the default `contributor` role. Agency staff can subsequently set an appropriate known role through **Save membership**.

For staff changes, use the displayed organization ID, existing user ID, role and active/revoked status. Leave **Current membership revision** blank only for a new member; copy the displayed revision for an existing membership, and supply a reason. A conflicting revision requires a refresh. This is direct agency activation/revocation of an existing account; invitation delivery, acceptance and expiry are not a complete implemented flow.

| Organization role | Scoped behavior |
|---|---|
| `viewer` | Authorized reads; no draft writes. |
| `contributor`, `editor`, `leader`, `manager`, `partner_admin`, `owner` | Authorized scoped draft reads/writes, subject to the global WordPress capability ceiling and record-type policy. |
| Unknown role, revoked membership or inactive organization | Access is denied. |

Select the intended organization when acting across memberships. A URL parameter does not grant access. Revocation removes that membership's access, including creator access to organization records. These roles do not authorize agency-only approval, finance controls or another organization's data.

## Vertical partner workflows

Tour leaders create structured stops, timed legs, days and private costs, then submit for independent agency review. Publication and holds require an approved linked route. Named IANA zones and chronological intervals are validated; costs/net rates remain confidential. New seeded tour and cohort examples are drafts pending completed structured review. Booked material changes remain blocked until an approved impact/consent workflow exists.

Academy staff can draft a course/cohort and submit it for independent approval. Enrollment links one accepted owned hold and does not allocate capacity twice. Multi-learner enrollment, full instructor/session conflict handling, assessments and public certificate verification remain incomplete.

Property/rental inventory is a staff-only local demonstration. Resource ownership, room-night capacity, intervals, blackout/maintenance, pickup/return and dispute guards apply. Rental pickup also requires current agency-reviewed age/licence/coverage references under the configured resource policy; that review does not establish government or insurer verification. Public inquiries do not promise availability, and the local workflow does not authorize a real deposit or capture a fee.

Makers respond only to RFQs addressed to their organization. Other suppliers' bids remain private. Bid deadlines and quote expiry are enforced; agency review and sample approval precede production. Record actual stage/inspection/evidence details. Full split awards, artwork revisions, shipping/returns and settlement remain incomplete.

Actual staffing, supplier/merchant agreements, privacy/retention, terms, native Persian copy and human operating approvals are still required for production. See the [operational runbook](../OPERATIONAL-RUNBOOK.md) for local commands, workers, backups and recovery boundaries.


## No-payment reservations and worker review

A service with no amount due still needs an owned, unexpired reservation and a linked WooCommerce checkout. The customer sees that payment is unnecessary and the agency sees the reservation in **Ready for confirmation**. Confirm the service using the same review and reason fields as a paid reservation. A no-payment order has no amount to refund. Review and approve a bilingual in-account template for `booking.no_payment_required` when these offers are in scope.

Use **Operations health** to inspect the last worker error count and the due queue. When a release fails, preserve the hold and check its booking and inventory association before making any operational change. Later valid holds continue through the worker.


## Review a dated rate plan

Create each named plan against the same physical resource assigned to the offer. Enter the bilingual name and terms, the local date band and timezone, the per-unit amount and applicable tax, the allowed duration and occupancy, and the assigned supplier reviewers. Suspend the offer before changing the published plans and reactivate after review. Existing holds preserve their accepted price and terms.

A visitor selects one applicable plan for the entire reservation. Changing the plan refreshes its quote and clears prior acceptance. Room nights or vehicles are consumed from the shared physical allocation. Use agency review for a stay that spans two nonoverlapping seasonal bands. [Accommodation requirements](../../docs/DOC-32_ACCOMMODATION-PORTAL.md)
