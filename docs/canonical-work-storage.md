# Canonical employee identity and task storage (#911)

## Current-path audit and decision

Before this change, employee credentials were written by `EmployeeFsStore` to
`storage/employee-users/employees.json`, while the main login/API stack used a separate
file-user index and SQLite also contained `users`/`roles`. Employee sessions therefore
carried non-relational string IDs. Tasks were independently read and rewritten as a whole
array by both `includes/task_storage.php` and `includes/tasks_helpers.php`, while admin and
employee APIs used the incompatible `portal_tasks` table. The PHP task pages and both
dashboards queried JSON; API bootstrap/counts queried SQLite. This allowed divergent
credentials, assignments, status values, counts, and lost concurrent writes.

SQLite `users.id` is now the canonical internal employee ID and `portal_tasks` is the only
active task store. `EmployeeFsStore`, `load_tasks()`, and `save_tasks()` remain temporary
shape-compatible facades so existing pages and unrelated employee/customer features keep
their visible behavior. They no longer write employee/task JSON. The older
`includes/task_storage.php` is retained only for rollback archaeology and has no active
callers.

## Schema

`employee_legacy_ids(source, legacy_id, user_id)` durably maps exact source identifiers to
`users.id`; names are never matching keys. `task_legacy_ids` provides an idempotency key and
non-sensitive source fingerprint. Existing `portal_tasks` gains recurrence, archive,
completion-log, and integer `version` fields. Foreign keys prevent orphan assignees;
assignee/status/due and active/status/due indexes serve task pages and dashboards. Writes
are transactional. Updates compare `version`, rejecting stale browser/API writes.

## Safe deployment

1. Put the site in a brief task-write maintenance window and deploy the code.
2. Run `php bin/migrate-canonical-work.php --dry-run`; resolve every reported conflict.
3. Run `php bin/migrate-canonical-work.php`. It first copies every existing source and the
   database into `storage/backups/<UTC timestamp>/`, then commits all imports atomically.
4. Preserve the generated `storage/migration-reports/canonical-work-*.json`, smoke-test each
   employee login, task page, dashboard, and API, then end the maintenance window.

Reports contain only legacy IDs and conflict reasons: never password hashes, notes,
attachments, or secrets. Counts are `imported`, `updated`, `skipped`, `conflicted`, and
`failed`. Re-running is safe: mapping keys skip identical imports and report changed source
records rather than overwriting canonical data. Existing portal tasks are registered in
place, never deleted. Exact login ID/email is the only reconciliation key; ambiguous and
unmapped records are reported for manual resolution.

Example (sanitized) dry run:

```json
{"mode":"dry-run","database":"app.sqlite","counts":{"imported":4,"updated":1,"skipped":8,"conflicted":1,"failed":0},"conflicts":[{"type":"task","legacy_id":"task-17","reason":"unmapped assignee legacy ID"}],"backups":[]}
```

## Backup and rollback

Do not delete any source JSON. To roll back, stop writes, archive the failed database and
report, copy the timestamped `app.sqlite` backup over `storage/app.sqlite`, restore the two
JSON backups if they were modified outside this migration, deploy the preceding release,
and smoke-test authentication. SQLite sidecar files (`-wal`/`-shm`) must be removed only
while all PHP processes are stopped. The migration itself never modifies source JSON.

Known demo credentials are no longer seeded unless `PORTAL_SEED_DEMO_USERS=1` is explicitly
set in a disposable development environment. Production provisioning must create unique
credentials through the existing secured administration/recovery process.
