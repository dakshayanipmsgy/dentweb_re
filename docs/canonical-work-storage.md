# Canonical employee and task storage

SQLite is the sole authoritative store for employees and tasks. `EmployeeFsStore` and the task helper names are compatibility facades only; they never write the legacy JSON files.

## Schema version 91701

`schema_migrations` records applied versions. Employee identity remains in `users`; employee-only phone and designation fields live in `employee_profiles`. `employee_legacy_ids` records the legacy ID, a non-sensitive source fingerprint, and migration version. It never contains a password hash.

`portal_tasks` retains the existing `todo`/`done` values presented as `Open`/`Completed`, and adds expected outcome, category, linked entity, urgent priority support, attention owner, proof requirement, due time/timezone, submitted/approved/cancelled/archived/last-activity timestamps, task version, and recurrence lineage. `task_messages`, immutable `task_events`, `task_attachments` (metadata only), and `task_occurrences` are the normalized #912-ready foundation. This migration intentionally adds no #912 workflow or UI.

Every update mutation must send the positive `version` last read. Missing and stale versions fail instead of falling back to the current database value.

## Reconciliation rules

Mapped legacy IDs are authoritative only when their source fingerprint is unchanged. A changed mapped source is a conflict for manual review. Unmapped records may match exactly one username/email belonging to an employee. Multiple cross-column matches and every admin, customer, installer, or other non-employee match are conflicts. Migration never changes an existing role or status and never reactivates an account. New imports preserve login ID, name, phone, designation, status, and an existing valid hash. Records without a valid hash are conflicts; hashes are never printed in reports.

## Deployment and backup

1. Put employee/task mutations into a maintenance window.
2. Run `php bin/migrate-canonical-work.php --dry-run` and resolve reported conflicts.
3. Run `php bin/migrate-canonical-work.php`.
4. Run application smoke tests, then retain the timestamped backup and sanitized report.

The command uses SQLite `VACUUM INTO`, validates the backup with `PRAGMA integrity_check`, copies it to an isolated temporary location, opens it, and repeats `integrity_check` as an executable restoration rehearsal. WAL/SHM consistency is handled by SQLite itself rather than copying those files. This implementation **requires a maintenance window for application writes**; it does not claim tested hot-backup support.

To create/validate only a backup:

```sh
php bin/migrate-canonical-work.php --backup-only --db=/absolute/path/app.sqlite
```

## Rollback / exact restoration

Stop all application writers, preserve the failed database plus its `-wal` and `-shm` files for investigation, then restore the validated backup as a new file:

```sh
cp storage/backups/YYYYmmdd_HHMMSS_random/app.sqlite storage/app.sqlite.restore
php -r '$d=new PDO("sqlite:storage/app.sqlite.restore"); echo $d->query("PRAGMA integrity_check")->fetchColumn(),PHP_EOL;'
mv storage/app.sqlite storage/app.sqlite.failed
mv storage/app.sqlite.restore storage/app.sqlite
rm -f storage/app.sqlite-wal storage/app.sqlite-shm
```

Restart the application only after the check prints `ok`. Legacy JSON remains read-only migration input and is not a rollback write target.
