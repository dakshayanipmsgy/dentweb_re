# Official task workflow (#912)

`TaskWorkflowService` is the only writer for official work. It derives actor ID and role from the authenticated session; employee access is additionally restricted to the current assignee. Every mutation requires the last-read positive task version and runs under one `BEGIN IMMEDIATE` transaction covering task state/routing, typed message, immutable event, attachment metadata, recurrence, and version increment.

## State and routing model

The canonical states are `assigned`, `acknowledged`, `in_progress`, `blocked`, `submitted`, `correction_required`, `completed`, and `cancelled`. Employee transitions are acknowledge, start/resume, block, and submit. Admin transitions are blocker response, correction, approval, reopen, and cancellation. Only admin approval changes submitted official work to completed.

`responsibility` is explicit: `admin`, `employee`, or `none`. `attention_owner_id` is maintained as a compatibility/convenience projection and is never used to infer responsibility. Replies hand responsibility to the other role; blockers and submissions route to admin; corrections, responses, reopening, and reassignment route to the employee; approved or cancelled work routes to none.

## Legacy compatibility

`portal_tasks.status` remains constrained to `todo`, `in_progress`, and `done`. `workflow_status` is canonical. The service projects assigned/acknowledged/blocked/correction-required to `todo`, in-progress/submitted to `in_progress`, and completed/cancelled to `done`. Existing rows are safely backfilled on first schema initialization. Legacy JSON is not written.

## Attachments and recurrence

Files are stored outside the public asset tree with 192-bit random names. Extension and detected MIME must match, executable formats are excluded, and the limit is 10 MiB. Metadata and SHA-256 live in `task_attachments`; authenticated `task-attachment.php` checks admin role or current assignee ownership, validates the storage key, and prevents traversal before streaming with `nosniff`.

Each recurring occurrence is a new `portal_tasks` record linked by `task_occurrences`, `parent_task_id`, and a random series ID. Submission creates nothing. Approval creates exactly one next occurrence, anchored at the later of the prior due date and current Asia/Kolkata business date, preventing overdue backlog generation.

## Deployment and rollback

Back up `storage/app.sqlite` and protected task attachments, deploy PHP files, then exercise `php tests/task_workflow_test.php`. Schema initialization is repeatable and records version `91202`. No standalone SQL migration file is required. For rollback, stop writers, restore the database and attachment directory from the same pre-deployment backup, and restore the prior PHP release; database and file backups must remain paired.

## Limitations

The workspace implements server-rendered request/response actions; notification bubbles, scheduled delivery, browser push, and employee PWA work are intentionally excluded. Existing personal-reminder creation remains outside the official workflow and official metrics.
