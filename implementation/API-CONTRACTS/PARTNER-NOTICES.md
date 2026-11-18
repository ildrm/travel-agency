# Private domain notices

`atlas-operations/partner-notices.php` registers two filters at `atlas_operations_ready`: `atlas_notification_events` and `atlas_notification_recipients`. It adds no transport, table, automatically approved template, or public endpoint. The existing notification service handles reviewed EN/FA templates, private per-account pagination/read state and per-event/per-recipient deduplication.

Actual emitted events:

- Partner: `partner.reviewed`, `partner.invitation_created`, `partner.member_accepted`, `partner.contract_create`, `partner.contract_revise`, `partner.contract_review`, `partner.contract_accept`, `partner.contract_close`.
- Procurement: `procurement.` followed by `rfq`, `quote`, `award`, `artwork`, `art_review`, `sample`, `authorize`, `milestone`, `shipment`, `inspection`, `return_request`, `return_resolve`, `payable`, `settlement_intent`, `settlement_approve`, `settlement_evidence`, `close`.
- Dispatch: `dispatch.` followed by `assign`, `supplier_accept`, `supplier_reject`, `policy_accept`, `confirm`, `en_route`, `arrived`, `waiting`, `contact_attempt`, `onboard`, `completed`, `no_show`, `cancel`, `schedule_conflict`.

Each resolved recipient contains only `{user: integer, reference: object UUID}`. Resolution ignores raw event payload, including recipient, organization and contact hints, and looks up the persisted object anew. Agency review uses the shared agency queue: current `manage_options` and `atlas_manage_records` are both required; procurement finance events additionally require `atlas_manage_finance`. There is no claim of individually assigned agency reviewers where the underlying workflow has no such assignment.

Partner applications notify the persisted applicant owner and agency reviewers. Pending unexpired invitations notify the current authorized creator and an existing account with the exact invitation email; neither the token nor email enters a message body. This notice does not deliver a single-use invitation token. The creator must still deliver the token manually. Revoked/expired invitations do not notify the prospective recipient. Acceptance follows the stored active accepted membership. Contract notices require current partner manager/administrator/owner membership.

RFQ creation may notify active scoped members of invited organizations. Quote and production notices are confined to the stored quote/order organization; rival suppliers receive no object reference. Dispatch notices reach the stored customer, current assigned driver with active membership/capability, current operator managers and agency reviewers. Internal contact-attempt events exclude the customer. Other stage notices carry only the dispatch reference, with no location, driver contact, evidence, GPS or financial payload.

Membership, known role, global capability and organization status are checked at delivery time. A missing/mismatched aggregate or audience exceeding the bounded limits fails delivery for operational review. A valid obsolete/unassigned event with no eligible recipients returns the notification service's user-zero no-disclosure sentinel. Unrelated event routing is preserved.

`tests/partner-notices-integration.php` uses fictional persisted association rows and the actual outbox/template/in-app persistence pipeline. All mail and HTTP transports are intercepted and counted. These are local integration checks, not evidence of external message delivery or independent privacy approval. Root schedules execution and records exact outcomes in `implementation/TEST-EVIDENCE.md`.

## Procurement artwork consumer

Artwork uses private-file context `procurement` and the exact production order/award UUID. The trusted ACL derives the organization and stable owner from that stored award; callers cannot supply either. A current organization viewer can read an authorized proof but cannot upload or create a revision. Agency uploads also require an active award organization and records capability.

Attachment requires an existing clean private-file UUID, matching SHA-256, approved unexpired purpose policy and unexpired retention. Quarantine, manual opaque references, wrong-award references, wrong digest and withdrawn policies fail closed. File/policy row locks keep proof acceptance serialized with scanning, purge and policy withdrawal without invoking a nested file-service mutation. The same current file/policy checks apply before proof approval, sample approval, production authorization and production start.

Each replacement retains the prior proof's file/digest/policy, rights attestation, proposer and independent review/sample snapshot, then resets current approvals. Retaining this metadata does not extend document retention or grant public access. Private upload/scanning controls, a clean-file selector with readonly digest and a fresh nonce-scoped download are available in the EN/FA portal. No public media URL is created.

The procurement fixture stores actual known PNG bytes encrypted outside the served directory and uses an explicit `MOCK_KNOWN_BYTES_ONLY` scanner. It demonstrates software boundaries; production encryption key/storage configuration, purpose-policy approval and a functioning external scanner remain required.
