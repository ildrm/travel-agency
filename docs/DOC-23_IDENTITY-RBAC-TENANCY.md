# DOC-23 — Identity, authorization and organization boundaries

**Purpose:** Specify sign-in, partner membership and object-level access across every interface.  
**Prepared from perspectives:** Identity, WordPress, privacy, partner operations and security.  
**Accountable reviewer:** Security architect with partner operations owner.  
**Dependencies:** [data dictionary](DOC-22_DATA-MODEL-ERD-DICTIONARY.md), [security](DOC-27_SECURITY-PRIVACY-THREAT-MODEL.md), [partners](DOC-36_PARTNER-PORTALS-CONTRACTS.md).  
**Approval gate:** Security review and cross-organization tests pass.  
**Status:** DRAFT specification; review pending.

## 1. Identity and tenancy model

A WordPress site represents a single agency, not a multi-tenant hosting service. **ENT-USER** can be a shopper and a member of several **ENT-ORG** records through **ENT-MEMBER**. WordPress roles grant broad capabilities; they do not prove ownership of a particular partner resource. An active scoped membership and object relationship are checked on every relevant action. Public browse is allowed; MVP checkout requires sign-in. A traveler may differ from purchaser and has no automatic account access merely because they appear in booking.

| Actor | Public browse | Own customer record | Org proposals | Booking operational detail | Financial records |
|---|---|---|---|---|---|
| Visitor | Published only | None | None | None | None |
| Customer | Published | Own profile/orders/bookings | None without membership | Own authorized traveler details | Own receipts, no supplier margin |
| Partner contributor | Published | Own customer record | Draft/read only for active member org; submit within granted role | Assigned records, minimum fields for contracted work | Own permitted statement, no agency margin |
| Partner org admin | Published | Own | Manage permitted member roles and own org proposals | Scoped permitted assignments | Own permitted terms and statements |
| Agency reviewer | Published and assigned pending | If own | Review/approve with separate capability | Scope as assigned and justified | No finance data absent explicit capability |
| Agency finance | Published | If own | No implicit approval | Minimum necessary IDs/status | Reconcile, refund and ledger according to delegated capability |
| Site administrator | Technical authority | According to role | No assumed business approval merely from UI | Privileged access is audited and minimized | Only explicitly delegated finance capability; hosting/database administrators remain a distinct high-trust risk. |

The matrix is a baseline; create distinct capabilities for list, read, create, submit, approve, suspend, export, refund and manage members. Deny by default. Agency policy decides whether a technical administrator may also hold operational capabilities; configuration cannot make direct database access impossible for infrastructure administrators.

## 2. Authorization flow

**WF-AUTH-001:** authenticate user; validate request/nonce where applicable; identify current organization from membership, never trust client `org_id`; load target object and parent ownership; check status, scoped capability and purpose; filter fields; perform action; audit sensitive access and changes. On denial, return a consistent authorization response without revealing whether another organization’s record exists. Re-evaluate membership on asynchronous action execution. For approved agency impersonation/support, use time-limited scoped delegation with reason, actor, target, no ability to approve one's own elevated access, and immutable audit.

Membership invitation contains short-lived one-use token, scoped org and role; verify address ownership, bind to existing account only after sign-in, permit explicit acceptance and expire/revoke. Role changes, removal and staff departure take effect immediately for new requests; invalidate sensitive sessions or reauthorize in-flight jobs. An org suspension blocks new submissions and restricted access, but preserves customer service obligations and provides a controlled agency override with reason. Agency approval is distinct from partner's own submission.

## 3. Authentication, sessions and account recovery

Use maintained WordPress authentication mechanisms with rate limits on login and recovery, generic failure messages and secure session cookies over TLS. Require MFA for agency administrators and sensitive operations when supported and tested; recovery, lost second factor and emergency access have human-reviewed procedures. Password reset and invitation tokens are single-use and time-limited. Keep Woo customer checkout authenticated end to end; session expiration after hold creation does not extend its deadline. Avoid collecting identity documents until a business need and retention policy are approved.

WordPress nonces mitigate CSRF but are neither authentication nor authorization and are not replay prevention. REST handlers must validate capabilities and object scope separately; server-to-server requests use an independently managed credential/signature scheme verified per provider. [WordPress nonce guidance](https://developer.wordpress.org/apis/security/nonces/) retrieved 2026-11-17.

## 4. Coverage across surfaces

Apply the same policy to REST/Store API extensions, admin screens, AJAX, bulk operations, search filters and counts, cached views, media/document download, exports, notifications and workers. Cache keys must include authorization context or responses must be private/non-cacheable; do not place sensitive files at predictable public media URLs. Authorized downloads go through access checks with short-lived links only if backend supports them safely. Log access to confidential records without storing payload or secrets. Field filtering precedes serialization, analytics and error construction.

**Fictional example:** Sam is a customer and contributor to org A and admin of org B. While editing an A proposal, submitting B's object ID must fail even if Sam can read B as its admin unless action is performed under B's authorized context; customer-facing itinerary never includes either org's confidential net rates. Removal from A immediately denies A exports; existing customer bookings remain accessible through customer rights.

## 5. Tests and acceptance

**TC-AUTH-001:** authenticated A member requests B's proposal, booking, export, media and search count; none disclose fields or record existence. **TC-AUTH-002:** customer changes order ID; object policy denies unrelated order. **TC-AUTH-003:** revoked membership cannot complete queued approval or reuse prior link. **TC-AUTH-004:** valid nonce without required capability or membership is denied. **TC-AUTH-005:** multilingual caches never serve confidential or another user's response. **TC-AUTH-006:** agency finance can issue authorized refund but cannot approve partner listing without that separate capability. Audit includes actor, object, purpose, time and outcome without PII payload.

**OPEN DECISION:** delegated role names, MFA provider and support impersonation scope. Privacy/legal owners must approve identity collection, access logs and retention; do not claim an operational identity assurance level without evidence.

## Review and evidence

All decisions are proposed, not approved; no implementation, operational measurement or supplier integration is implied. Accountable reviewers must record sign-off and evidence before release. Provider interfaces, compatibility versions and legal conclusions remain VERIFY BEFORE BUILD.

**Self-assessment, 0–5:** completeness 4 (document scope covered); specificity 4 (concrete contracts); consistency 4 (shared baseline); user value 4 (actionable decisions); implementation feasibility 4 (platform boundaries); security/privacy 4 (controls proposed); compliance 3 (qualified legal/provider verification outstanding); accessibility/localization 4 (cross-referenced standards); testability 4 (observable checks); traceability 4 (canonical IDs). A score of 3 is a release blocker until evidence and qualified review exist; see [risk register](DOC-45_RISKS-DECISIONS-CONTROLS.md). These are author estimates, not independent audit findings.
