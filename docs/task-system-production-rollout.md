# Task system production rollout runbook (#915)

## Architecture and release gates

SQLite `storage/app.sqlite` is the canonical source for identities, official tasks and their immutable events/messages/attachment metadata, notification projection/status, and encrypted push subscriptions/deliveries. Legacy employee, task, and push JSON is migration input only. Schema releases are `91701` (canonical identity/work), `92001` (workflow), the notification/push versions recorded by their services, and `91500` (release reconciliation marker).

Production requires PHP 8.2+ with PDO, PDO SQLite, sodium, GD, JSON, mbstring, OpenSSL, fileinfo and adequate disk space. Install a reproducible production dependency set with:

```sh
composer update --no-dev --prefer-dist --no-interaction --with-all-dependencies
composer validate --strict
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

`composer update` is performed only while deliberately updating and reviewing the lock. Deployments run validate/install against committed `composer.lock`. Push remains off unless `PUSH_ENABLED=1`; missing autoload, sodium, encryption, or VAPID configuration must disable push without changing canonical in-app notifications.

## Credentials, flags and privacy

Known demo accounts are insert-only and require `PORTAL_SEED_DEMO_USERS=1`; never set this in production. Existing identities and hashes are never rewritten by bootstrap or migration. Production initialization must use the administrator recovery/initialization flow with an operator-supplied password of at least 12 characters containing upper/lowercase, number and symbol. Rotate administrator, employee and integration credentials immediately before rollout. Never place credentials in command arguments, reports, logs, `.env` committed to Git, or browser JavaScript.

Safe initial flags: `PUSH_ENABLED=0`, `TASK_OFFICIAL_ENTRY_ENABLED=0`, and legacy writes enabled only for the observation window. Push and task entry can be rolled back independently without deleting migrated data. Private pages/APIs/downloads use no-store, nosniff, framing/referrer protection and server-side role/ownership authorization. The service worker caches only the public static allowlist, never API or authenticated responses.

## Operator sequence

Run in a maintenance window from the release directory:

```sh
php bin/task-system-preflight.php --strict
php bin/backup-task-system.php --dry-run
php bin/backup-task-system.php --apply
php bin/backup-task-system.php --verify=storage/task-system-backups/YYYYmmdd_HHMMSS_random
php bin/migrate-task-system.php --dry-run --backup=storage/task-system-backups/YYYYmmdd_HHMMSS_random --report=/secure/reconciliation.json
php bin/migrate-task-system.php --apply --backup=storage/task-system-backups/YYYYmmdd_HHMMSS_random --report=/secure/rehearsal.json
php bin/migrate-canonical-work.php --dry-run
# Resolve every fatal/manual-review mapping; then run the existing canonical apply command.
php bin/migrate-canonical-work.php
php bin/migrate-push-json.php
php bin/audit-task-system.php --strict
```

The #915 apply command intentionally rehearses coordinated schema services on a SQLite snapshot and does not replace the existing canonical migration implementations. It requires a verified backup. Reports contain counts and identifiers for check categories, never password hashes, endpoint material, keys, or attachment content. Preserve legacy files read-only for at least the agreed rollback retention period and monitor migration/audit counts, reminder/push worker summaries, authentication failures, authorization denials, task version conflicts, and attachment validation categories.

Cron examples (use an OS account with minimum filesystem access and prevent overlap):

```sh
*/5 * * * * cd /srv/dentweb && php bin/generate-task-notifications.php
* * * * * cd /srv/dentweb && php bin/send-task-push.php
```

Generate icons during deployment with `php bin/generate-employee-pwa-icons.php --output-dir=assets/icons/employee` and validate with `--check`. Configure VAPID and the 32-byte base64 push encryption key only in the secret manager after canonical notifications are stable. Never print them during preflight.

## Restore rehearsal and rollback

Stop writers. Restore into a temporary name first, never over the live file:

```sh
mkdir -m 700 /secure/restore-rehearsal
cp storage/task-system-backups/YYYYmmdd_HHMMSS_random/database/app.sqlite /secure/restore-rehearsal/app.sqlite
php -r '$d=new PDO("sqlite:/secure/restore-rehearsal/app.sqlite");$d->exec("PRAGMA foreign_keys=ON");echo $d->query("PRAGMA integrity_check")->fetchColumn(),PHP_EOL;'
PORTAL_DB_PATH=/secure/restore-rehearsal/app.sqlite php bin/audit-task-system.php --strict
mv storage/app.sqlite storage/app.sqlite.failed
cp /secure/restore-rehearsal/app.sqlite storage/app.sqlite.restore
chmod 600 storage/app.sqlite.restore
mv storage/app.sqlite.restore storage/app.sqlite
rm -f storage/app.sqlite-wal storage/app.sqlite-shm
```

Rollback stages are: disable push; disable new official-task entry points; stop workers/writers; preserve the failed database for investigation; verify and restore the selected backup; audit; re-enable the previous code; retain legacy inputs. No backup is automatically deleted.

## Acceptance, performance and known limitations

Route authorization covers public, unauthenticated, customer, employee and admin roles; attachment downloads always repeat ownership checks. Workflow mutations require CSRF/session identity and rendered task version, with stale writes rejected. Review `EXPLAIN QUERY PLAN` for task assignee/status/due, notification unread, and push ready/claim queries using sanitized staff-scale fixtures. Lists must paginate timelines/history; polling pauses while hidden and never overlaps; workers remain bounded/idempotent and push runs outside workflow transactions.

Production migration and physical-device validation are controlled manual gates. This repository run cannot claim either. Record the backup directory, checksum verification, temporary-copy migration, audit output, representative counts, query plans and operator approval in the PR/deployment record.

### Sanitized reconciliation report template

```json
{"migration_version":91500,"mode":"dry-run","counts_before":{},"counts_expected_after":{},"fatal_conflicts":0,"warnings":0,"manual_review":[]}
```

### Browser/device matrix (do not convert expectations into passes)

| Check | Result | Manual procedure |
|---|---|---|
| Android Chrome install / push | Not executed | Install from browser menu; sign in; enable notifications; deliver one ID-only notification; verify logout/account switch. |
| Android Edge | Not executed | Repeat install, offline, push, permission revoke and update checks. |
| Desktop Chrome install / push | Not executed | Install; test offline generic page, push, badge 0/1/99/99+, update and logout. |
| Desktop Edge | Not executed | Repeat install, push, update, offline and account switch. |
| iPhone/iPad Safari A2HS | Not executed | Add to Home Screen; test installed-app push where supported, offline, logout and account switch. |
| Unsupported / denied / revoked | Not executed | Confirm canonical in-app notification survives and UI gives safe guidance. |
| Narrow / tablet / desktop / slow network | Not executed | Exercise long names, empty/error states, multiple attachments and stale edits. |

## Rollout stages

Deploy code/schema with flags off; preflight; verify backup; dry-run; resolve mappings; apply canonical migration; strict audit; enable admin and one designated employee; execute the full create-to-approve and recurrence acceptance flow; validate in-app notification/badges; expand employees; observe stability; configure secrets and enable push; monitor retries/duplicates/login failures/conflicts; disable legacy writes after the observation period; retain rollback backups. Administrator and employee guides are in `docs/task-workflow.md`; notification/PWA details are in `docs/task-notifications.md` and `docs/employee-pwa-push.md`.
