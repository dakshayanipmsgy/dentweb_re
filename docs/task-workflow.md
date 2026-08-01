# Canonical official-task workflow (issues #912 and #920)

Personal reminders are intentionally separate records. They never participate in official task counts, dashboards, performance metrics, workflow events, or notification inputs.

## Responsibility and recipient invariants

* `employee` means the active current assignee must act and `attention_owner_id` equals `assignee_id`.
* `admin` means the administrator audience must act and `attention_owner_id` is `NULL`. It never points at the employee actor.
* `none` means no action is expected and `attention_owner_id` is `NULL`.

Schema initialization safely repairs rows written by PR #919 that contradict these rules. Assignment and reassignment accept only an existing, active user with the employee role. Inactive assignees remain visible on historical task cards but are absent from selectors.

## State machine

Employees may acknowledge an assigned task or start it directly (direct start records both acknowledgement and start). They can report a blocker from active work. An admin can respond while keeping it blocked, or resolve it and return responsibility to the assignee. An employee explicitly acknowledges/resumes requested corrections. First and corrected submissions use the same validated submission edge. Admins can request correction, approve, reopen, or cancel where applicable. Archived work exposes no workflow actions. Optimistic versions reject stale and simultaneous mutations.

## Timestamp rules

Times are Asia/Kolkata wall times. `acknowledged_at` and `started_at` project acknowledgement/start; `correction_acknowledged_at` projects correction resume. Every submission sets `submitted_at`, while earlier submission values survive in events. Approval sets `approved_at`, `approved_by`, `completed_at`, and `last_completed_at`. Cancellation sets `cancelled_at`. Reopening clears current `submitted_at`, `approved_at`, `approved_by`, `completed_at`, `cancelled_at`, and archive values, but deliberately retains `last_completed_at`. Immutable events preserve all prior state transitions.

Business-day overdue, due-today, and completed-this-week classifications are calculated in PHP using `Asia/Kolkata`, not SQLite UTC `date('now')`.

## Recurrence

`once`, daily, weekly, monthly, and custom schedules are supported; custom intervals must be at least one day. Approval creates at most one successor from the occurrence's scheduled date, not from a delayed approval date. The successor retains the series ID, increments the occurrence number, links to the approved parent, begins assigned to the validated current employee, and emits `recurrence_created`.

## Immutable event contract for issue #913

Every important mutation inserts a `task_events` record whose JSON `event_data` has `contract_version`, `task_id`, `occurrence_number`, `series_id`, `event_type`, `actor` (`id`, `role`), old/new workflow state, old/new assignee ID, resulting `responsibility`, `intended_recipient` (`type`, `user_id`, `audience`), `occurred_at`, resulting `task_version`, safe role-specific `deep_link`, and event-specific `details`.

The catalogue is: `assigned`, `acknowledged`, `started`, `reply`, `progress`, `blocker_reported`, `blocker_response`, `blocker_resolved`, `submitted`, `correction_requested`, `correction_resumed`, `approved`, `reopened`, `reassigned`, `schedule_priority_revised`, `cancelled`, `archived`, `unarchived`, `recurrence_created`, and `proof_uploaded`. Consumers must use the explicit recipient object and must not reinterpret workflow rules. Issue #913 notification delivery is not implemented here.

## Attachments and deployment

Files created within a mutation are tracked. Any later database, optimistic-version, or commit failure rolls back rows and deletes those files. Attachment rows are inserted only after a file is safely stored; downloads revalidate the protected storage key and authorization.

Deployment requires normal application startup (or the canonical migration command) to run the repeatable schema initializer and #919 routing backfill. Back up the application database and protected attachment directory first. Rollback should restore both together; reverting only code after the schema is safe because added columns/tables are additive, but the responsibility backfill is intentionally not reversed.
