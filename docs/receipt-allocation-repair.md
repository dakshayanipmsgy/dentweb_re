# Receipt allocation repair

The command is CLI-only and dry-runs by default. It reports only categorized counts; it does not print customer or receipt data.

```sh
php bin/repair-receipt-ledger.php --quotation-id=QUOTATION_ID
php bin/repair-receipt-ledger.php --apply --quotation-id=QUOTATION_ID
```

Apply takes the same exclusive lock as normal receipt writes, re-reads beneath that lock, creates a timestamped `sales-receipts.json.backup.*` copy, then atomically replaces the store. Allocation migration history is appended to each changed receipt. A second apply is idempotent.

## Rollback

Put receipt entry into maintenance mode, retain the failed post-change store for audit, and copy the backup path printed by the command over the canonical receipt store. Do not edit, merge, delete, or recreate individual receipts. Re-run the dry-run and have an administrator review ambiguous splits before restoring receipt entry.
