# Customer lifecycle integrity audit

## Scope and safety boundary

This audit traces **Lead → Quotation → Approved → Accepted Customer → Site Completed / Completed Customer → Customer User**, including reverse references, archives, mobile correction and CSV sync. The checker is read-only: `php customer-lifecycle-integrity-check.php` loads the stores, emits JSON, exits `0` when clean and `2` when findings exist. It never saves, repairs, creates, archives, restores, merges or relinks anything.

Quotation creation/editor/save, price and item calculation, templates, finance, imports, cloning and revisions are intentionally outside the implementation boundary. Finalized document bodies and completion snapshots are historical evidence, not synchronization targets.

## Records, storage, identity and status

| Record | Storage | Canonical identity / unique key | Status meaning and source-of-truth page |
|---|---|---|---|
| Lead | `storage/leads/leads.json` | immutable `id`; normalized mobile is a match hint, not an ID | Lead status, conversion flags and archive flag are managed in `leads-dashboard.php`; `lead-detail.php` is the detail view. |
| Quotation | one JSON file per `id` under the documents quotations directory | `id`; `quote_no` is display/reference; `quote_series_id` plus revision identifies a series | `draft`, `approved`, and `accepted` are quotation states. `admin-quotations.php`, `employee-quotations.php`, and `quotation-view.php` are authoritative interfaces. Accepted is locked/finalized; Approved is not Accepted. |
| Accepted Customer | no independent record | current, non-archived quotation `id` whose normalized status is `accepted` | Derived view in `admin-documents.php`; it must not be persisted or synchronized as a customer status. |
| Completed Customer | no independent record | accepted quotation `id` with `project_completion.state=completed` | Derived view in `admin-documents.php`. Completion is explicit, independent of payment, and stores actor/time/note/financial snapshot. Reopen moves the row back to Accepted without deleting the snapshot/audit. |
| Customer User | `storage/customer-users/customers.json` | normalized valid Indian mobile (`mobile_key`); `serial_number` is stable display identity | Account/operations status is managed in `admin-customers.php`; archive is independent of quotation archive. |
| Sales documents | filesystem JSON stores used by document helpers | document `id`, with `quotation_id`/`linked_quote_id` back to quotation | `admin-documents.php` and individual view pages. Finalized snapshots are immutable evidence. |

Canonical mobile normalization accepts ten Indian digits or the same number prefixed by `91`, after punctuation removal, and requires the first digit to be 6–9. Names never establish identity. Multiple quotations may intentionally share one mobile and one Customer User; identical names on different mobiles remain isolated.

## Transitions and synchronization boundaries

1. Creating a quotation from a lead may prefill source/customer fields, but **must not create a Customer User**. Traceability requires quotation `source.lead_id`/`source.lead_mobile` and a lead-side quotation reference.
2. Approval changes only quotation status. Acceptance is a distinct, validated and locked quotation transition. A Customer User is created/linked only by an explicit successful action; a failed action must not set success metadata.
3. Accepted and Completed tabs are filtered projections of active, current accepted quotations. Completed rows additionally require `project_completion.state=completed`; reopened rows return to Accepted.
4. Mobile correction is an audited operation. Current quotation/snapshot/link metadata and an explicitly confirmed Customer User migration/relink must agree; historical finalized documents and completion snapshots are not rewritten.
5. CSV mobile synchronization matches **active Customer Users and active quotations by normalized mobile only**. It may preview/apply allow-listed contact fields, but not statuses, passwords, serials, pricing, items, finalized snapshots or archives. Archived records cannot participate in active matching.
6. Quotation status, project completion state, Customer User operational status, invoice status and payment status remain separate facts.

## Confirmed defects and follow-up issues

### Critical — conversion can claim success after Customer User creation fails

**Reproduction:** use a lead whose mobile fails Customer User validation or whose filesystem write fails, then choose Converted (AJAX, row action, or bulk action). **Impact:** the lead is marked Converted and archived even when `leads_create_customer_from_lead()` returns neither a created nor existing account, leaving no usable login and a misleading successful lifecycle. **Follow-up:** “Make lead conversion transactional and report Customer User creation failure without changing quotation creation.” Only set conversion/archive/link flags after a verified active Customer User exists.

### Critical — hardcoded initial Customer User password

**Reproduction:** create a Customer User from a lead or accepted quotation and authenticate using the shared initial password `abcd1234`. **Impact:** predictable credentials expose every account created through these paths and provide no per-user secret delivery/rotation evidence. **Follow-up:** “Replace hardcoded Customer User credentials with one-time activation tokens.” This is an authentication/customer provisioning change, not quotation creation.

### High — lead/quotation traceability is not reliably bidirectional

**Reproduction:** open quotation creation with `from_lead_id`, save the quotation, then inspect the lead record. The quotation carries source metadata when supplied, but the lead does not have a consistently maintained quotation ID collection; multiple quotations amplify the ambiguity. **Impact:** orphaned/missing reverse links, unreliable audit trails and inability to distinguish several quotations for one lead/mobile. **Follow-up:** “Persist append-only lead↔quotation references after successful quotation save.” Because this touches quotation save logic, it is explicitly deferred under issue #838’s restriction.

### High — direct lead conversion conflates Lead conversion with Customer User creation

**Reproduction:** choose Converted on an unquoted lead. The handler creates a Customer User, marks Converted and archives the lead in one UI operation. **Impact:** lifecycle stages can be skipped and conversion semantics vary between explicit customer creation and quotation acceptance; archived leads remain the only evidence. **Follow-up:** “Define and enforce a transactional lead-conversion state machine,” preserving an audit event and separate Customer User provisioning result.

### Medium — legacy customer upsert path can silently fill identity fields

**Reproduction:** invoke `documents_upsert_customer_from_quote()` for an existing mobile with blank Customer User fields. **Impact:** quotation-derived values are copied into the live account without an explicit field-by-field confirmation, while conflicting nonblank values remain, producing unclear provenance. **Follow-up:** “Retire or gate legacy quotation-to-customer upsert behind explicit preview/confirmation.” Do not alter quotation records.

## Checker finding catalogue

The JSON report covers converted leads without active valid Customer Users; missing forward/reverse lead↔quotation references; stale Customer User links; normalized-mobile/name conflicts; wrong Accepted/Completed derived membership; duplicate active accounts; archived accounts used as active matches; invalid mobiles; and contradictory completion/source metadata. Findings contain a stable code, severity, record type/ID, human-readable message and non-secret context. Operators must resolve them through separately reviewed workflows—the checker deliberately offers no repair mode.
