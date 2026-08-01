# Canonical official-task workflow (issues #912 and #920)

Personal reminders remain separate records and do not participate in official task metrics, workflow events, notification inputs, or performance reporting. This change prepares an event contract for #913; it does **not** implement a notification centre, badge, reminder generator, push delivery, or PWA.

## Responsibility is not notification intent

`responsibility` is the mutable next-action projection. `employee` requires the active current assignee and sets `attention_owner_id` to that employee; `admin` represents the admin audience and requires a `NULL` owner ID; `none` has no owner. Event `intended_recipients` is a separate immutable collection selected from event meaning. It can contain one user, an audience, several entries, or be empty. Consumers must never derive recipients from responsibility.

| Event | Explicit recipients |
| --- | --- |
| `assigned`, `recurrence_created`, `reassigned` | new/current assignee |
| `acknowledged`, `started` | admin audience |
| employee `reply`, attention-worthy `progress` | admin audience |
| admin `reply` | current assignee |
| `blocker_reported` | admin audience |
| `blocker_response`, `blocker_resolved` | current assignee |
| `submitted`, `resubmitted`, `correction_resumed` | admin audience |
| `correction_requested`, `approved`, `cancelled`, `schedule_priority_revised` | current assignee |
| `reopened` | audience/user owning the reopened action |
| `proof_uploaded` | admin audience for employee proof; assignee for admin proof |
| `archived`, `unarchived` | empty collection (audit event only) |

Approval and cancellation intentionally notify the employee even though the resulting responsibility is `none`. A keep-blocked admin response intentionally notifies the employee while responsibility remains `admin`.

## Links and visibility

`TaskWorkflowService::taskLink()` is the only event link builder. It selects the workspace from each recipient (`employee-tasks.php` or `admin-tasks.php`) and selects `active`, `completed`, `cancelled`, or `archived` from the resulting record. Every URL includes `task=<id>#task-<id>`. Deep-link selection is applied after the view filter, so closed and archived records remain visible.

## State, projection resets, and closed work

The state machine and optimistic version checks remain authoritative. Reassignment is permitted only for active, unarchived work and resets it to `assigned`. It clears these current projections: `acknowledged_at`, `started_at`, `submitted_at`, `submission_summary`, `correction_acknowledged_at`, `approved_at`, `approved_by`, `approval_note`, `completed_at`, `cancelled_at`, `archived_at`, `archived_flag`, and `closed_reason`. It preserves identity, series/occurrence lineage, `last_completed_at`, messages, attachments, and immutable events. Those retained historical records are the audit trail; cleared columns describe only the current projection.

Completed, cancelled, and archived work rejects reassignment, schedule/priority revision, ordinary replies, and proof uploads. The workspace does not render those active-work controls. An audited reopen transition must occur first. Closed work may expose only state-valid actions such as reopen or archive/unarchive.

## Recurrence

For daily, weekly, monthly, and custom recurrence, approval takes the later of the prior scheduled/due date and the current Asia/Kolkata business date, then advances exactly one interval. Consequently a late approval creates one future occurrence, never an overdue successor or a backlog. The unique series occurrence and parent-child constraints plus the existing child check make approval retries idempotent.

## Filtering, cards, and progressive disclosure

Both workspaces support search, employee (admin), workflow status, next-action responsibility, priority, overdue, due today, upcoming, no due date, category/reference, and active/completed/cancelled/archived views. Selected values are preserved. All date buckets use Asia/Kolkata business dates.

Admin cards are Needs admin action, Awaiting review, Blocked, Not acknowledged, Overdue, Due today, and Completed this week. Employee cards are New assignments, Needs my action, In progress, Overdue, Waiting for admin, Correction required, and Approved complete. Each card links to its corresponding filtered view.

The custom recurrence input is ordinary visible HTML when JavaScript is absent. Progressive enhancement hides it unless the keyboard-accessible recurrence select is `custom`, and toggles its required state without disabling the form's no-script fallback.

## Canonical event contract (version 2)

Every immutable `task_events.event_data` document contains `contract_version`, `canonical_task_id` (and compatible `task_id`), `series_id`, `occurrence_number`, `event_type`, actor ID/role, old/new state, old/new assignee, resulting responsibility, `intended_recipients`, occurrence time, resulting task version, recipient-correct `deep_links`, and safe structured `details`. Details contain booleans, IDs, schedule values, and similar notification-safe metadata—not message bodies, secrets, storage keys, paths, hashes, or attachment contents.

Representative sanitized approval payload:

```json
{"contract_version":2,"canonical_task_id":42,"task_id":42,"series_id":"opaque-series-id","occurrence_number":3,"event_type":"approved","actor":{"id":7,"role":"admin"},"old_workflow_state":"submitted","new_workflow_state":"completed","old_assignee_id":18,"new_assignee_id":18,"responsibility":"none","intended_recipients":[{"type":"user","user_id":18,"deep_link":"employee-tasks.php?view=completed&task=42#task-42"}],"occurred_at":"2026-08-01 14:30:00","task_version":12,"deep_links":["employee-tasks.php?view=completed&task=42#task-42"],"details":{"has_explanation":true}}
```

Representative keep-blocked payload excerpt demonstrates safe divergence:

```json
{"event_type":"blocker_response","new_workflow_state":"blocked","responsibility":"admin","intended_recipients":[{"type":"user","user_id":18,"deep_link":"employee-tasks.php?view=active&task=42#task-42"}]}
```

Deployment runs the repeatable schema initializer/migration. Back up the application database and protected attachment directory together. Added schema remains additive; event records remain update/delete protected.
