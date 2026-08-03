# Task notifications (issue #913)

## Architecture and schema

`task_events` is the immutable outbox and contract version 2 is the only workflow-notification source of truth. `portal_notifications` remains the canonical record and now carries type/category, tone, task/event/series/occurrence identity, exact link, retention date, and a unique deterministic key. `portal_notification_status` remains the recipient row and carries unread/read/dismissed state and timestamps. Dismissal never deletes the canonical row. `task_notification_projection` records projected or intentionally skipped events; `notification_leases` throttles fallback work. Indexes cover unique deduplication, source projection, task history, unread counts, and stable `(created_at,id)` pagination.

Schema migration `91301` is additive, versioned, and repeatable. Existing generic notifications remain intact. Back up `storage/app.sqlite` before deployment and run any authenticated page or `php bin/generate-task-notifications.php` to initialize it.

## Projection and recipient mapping

The projector validates required version-2 fields, reads **only** `intended_recipients`, validates each supplied link, expands an `admin` audience to active users whose canonical role is admin, and targets a user entry only when it names an active canonical employee. It never derives recipients from responsibility, attention owner, actor role, or state. Canonical notification, recipient row, and projection checkpoint are committed in one `BEGIN IMMEDIATE` transaction. Unique keys `task-event:<event>:user:<user>` make retries and concurrent workers harmless.

| Event | Explicit event recipient | Safe notification |
|---|---|---|
| assigned / recurrence_created / reassigned | employee user | assigned/new occurrence/reassigned |
| admin reply | employee user | Admin replied on task |
| blocker_response / blocker_resolved | employee user | response/resolution |
| schedule_priority_revised | employee user | due date or priority changed |
| correction_requested | employee user | returned with corrections |
| approved / cancelled | employee user, despite responsibility `none` | approved/cancelled |
| reopened | supplied employee or admin recipient | reopened |
| admin proof_uploaded | employee user | proof uploaded |
| acknowledged / started | admin audience | acknowledged/started |
| employee reply / progress | admin audience | replied/progress posted |
| blocker_reported | admin audience | blocker reported |
| submitted / resubmitted / correction_resumed | admin audience | review/resume status |
| employee proof_uploaded | admin audience | proof uploaded |

Actor display names come from canonical users. Titles are limited to 90 characters and stored text is stripped of markup/control characters and limited to 140 characters. Message bodies, payloads, storage keys, paths, hashes, tokens, and attachment metadata are never copied.

Deep links must be relative `admin-tasks.php` or `employee-tasks.php` routes appropriate to the recipient, include a numeric matching task, an allowed active/completed/cancelled/archived view, and the matching task anchor. Click-through rechecks ownership and the link, marks read, and returns a 303 redirect; external schemes, hosts, protocol-relative paths, forged roles, and mismatched tasks are rejected.

## Centre, API, and polling

`notifications.php` provides unread/all views, stable pagination, tones, task references, exact links, read/unread, mark-all, per-user dismissal, responsive empty states, and inaccessible-reference handling. `api/notifications.php` derives identity and role from the session, requires the existing `X-CSRF-Token`, uses ownership-bound mutations, returns a consistent `{ok,data}` or `{ok:false,error}` envelope, and sends private/no-store headers. Example sanitized responses:

```json
{"ok":true,"data":{"unread_count":7}}
{"ok":true,"data":{"notifications":[{"id":42,"title":"Task submitted","message":"Ravi submitted “Rooftop survey” for review.","tone":"warning","notification_type":"submitted","link":"admin-tasks.php?view=active&task=8#task-8","source_task_id":8,"created_at":"2026-08-01 14:30:00","status":"unread"}],"page":1,"view":"unread","has_more":false,"unread_count":1}}
```

The shared 44px bell is present on admin/employee dashboards, workspaces, and mobile navigation. It hides zero, shows 1–99 or 99+, maintains a textual accessible label, polls every 45 seconds by default, stops scheduling while hidden, prevents overlapping requests, times out after eight seconds, refreshes on visibility/action events, and silently tolerates transient failures.

## Reminders and configuration

The generator uses Asia/Kolkata dates and due times, excludes personal, completed, cancelled, archived, unofficial, and inactive-user work, and creates employee due-today/overdue/unacknowledged plus admin priority-overdue/unacknowledged/long-blocked reminders. Keys include type, task, series, occurrence, recipient, and date/cadence window. Concurrent inserts use the unique key in short transactions.

Preferred cron (the server timezone may differ; application calculations are always Asia/Kolkata):

```cron
*/5 * * * * cd /var/www/dentweb_re && /usr/bin/php bin/generate-task-notifications.php >> /var/log/dent-task-notifications.log 2>&1
```

Example non-sensitive report: `task-notifications: scanned=28 created=4 deduplicated=9 ineligible=15`. Exit codes are 0 success, 1 failure, and 2 non-CLI invocation. Authenticated requests provide a safety-net only: a global lease throttles work (default five minutes), the request budget is 20 tasks, failures are logged and never block rendering. Cron remains preferred.

Environment defaults: unacknowledged 24h, blocked 24h, due-today hour 07:00, employee/admin overdue cadence 24h, projection batch 100, poll 45s, fallback 300s, retention 365 days. Values are bounded server-side; deployment does not edit PHP.

## Rollout, retention, deployment, and rollback

On first schema application, its timestamp becomes the deterministic cutoff. Older task events are recorded as `skipped/pre_rollout`, preventing a historical flood; deleting those skip rows reverses that decision for an intentional backfill. Current eligible tasks are still considered by reminders. Projected notifications survive task closure. `retention_until` records policy intent; automatic deletion is deliberately not enabled, so audit/history is preserved.

Deploy by backing up the database, releasing files, setting optional environment values, running the CLI once, verifying its report, and installing cron. Roll back application files first; additive columns/tables are safe for the earlier code. A full schema rollback requires restoring the backup (preferred). Do not drop notification tables while old code is running.

Known limitations: this is polling-based in-app delivery only. There is no browser/OS push, service-worker push, app-badge API, email, WhatsApp, marketing delivery, or #914 redesign. Retention is recorded but not purged automatically. A task deleted outside supported foreign-key workflows produces a graceful unavailable message.

## Issue #924 corrective contract

### Post-commit lifecycle and recovery

Every workflow transaction now captures all event IDs it inserts (including the event for a recurrence created while approving a task). The workflow commits task state, immutable events, messages, attachments and recurrence state first. Only after that commit does it project each exact ID. Projection uses a separate `BEGIN IMMEDIATE` transaction and deterministic per-event/per-user keys, so competing immediate and recovery workers cannot create duplicate notification/status pairs. A projection exception is logged only as `Task notification projection failed for event <id>`; the successful task mutation remains committed and the missing projection checkpoint lets `task_notification_project_pending()` retry it. Scheduled reminder generation remains a separate operation. The API count/list path performs no reminder scan.

### Assignment timestamp and migration

`portal_tasks.assigned_at` is the current-assignment projection in Asia/Kolkata wall time. Initial assignment and recurring occurrence creation set it; reassignment resets it. Acknowledge and reopen do not alter it. Immutable `assigned`, `reassigned`, and `recurrence_created` events preserve assignment history. The idempotent backfill selects the latest such event only when its new assignee matches the current assignee. A task with no events safely falls back to creation; mismatched or otherwise ambiguous history is counted in the migration report and left unset rather than invented. Unacknowledged timing reads only `assigned_at`; ambiguous rows remain ineligible until reviewed and corrected.

### Recipient-state invariants

* **Read:** status `read`; set `read_at`; clear `dismissed_at`; retain `unread_at` as historical “last became unread”.
* **Unread:** status `unread`; set `unread_at`; clear `read_at` and `dismissed_at`.
* **Dismissed:** status `dismissed`; set `dismissed_at`; retain historical `read_at`/`unread_at`; exclude from lists and unread totals.
* **Mark all read:** apply the read rule only to that user’s unread rows.

Mutations are ownership-bound, transactional, and idempotent. The centre enhances ordinary POST forms: successful actions update cards, counts, all shared bells, focus and an ARIA live message without reload; failures remain inline. Without JavaScript, the same forms submit and redirect back.

### Time and reminder boundaries

Event wall times are interpreted explicitly as Asia/Kolkata and converted to `Y-m-dTH:i:sZ` before comparison with the once-persisted UTC rollout cutoff. Before-cutoff events receive one durable `skipped/pre_rollout` checkpoint; exactly-at and later events project. Retries cannot move the cutoff or reverse a checkpoint. Operational reminders remain based on current Kolkata business time and are unaffected by event rollout skips. Due-today begins at the configured hour and stops after the due instant; absent due time means 23:59. Overdue reminders use configured epoch-aligned cadence windows. Unacknowledged cadence begins at current assignment. A keep-blocked admin response updates task activity, so the blocked threshold restarts; resolution/closure/archive removes eligibility.

### Schema, deployment, and rollback

Corrective migration `92401` adds nullable `portal_tasks.assigned_at` and `task_notification_meta(meta_key, meta_value)`, plus the existing additive notification schema when absent. Deploy by backing up `storage/app.sqlite`, releasing the PHP files, and running `php bin/generate-task-notifications.php` once. Review the assignment backfill counts and a sanitized report such as `task-notifications: scanned=28 created=4 deduplicated=9 ineligible=15`; no payloads are logged. Roll back PHP first. The additive column/table are safe to retain; restore the database backup for a full schema rollback. Do not remove notification records/status rows independently.

No push subscription, VAPID, service-worker push/click handler, Badging API, employee manifest, install page, or PWA shell work is included; issue #914 can consume these stable in-app records later.
