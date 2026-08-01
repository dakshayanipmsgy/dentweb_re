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

The shared 44px bell is present on admin/employee dashboards, workspaces, and mobile navigation. It hides zero, shows 1–99 or 99+, maintains a textual accessible label, polls every 45 seconds by default, pauses while hidden (with reduced scheduling), prevents overlapping requests, times out after eight seconds, refreshes on visibility/action events, and silently tolerates transient failures.

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
