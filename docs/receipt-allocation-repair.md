# Receipt allocation repair

The command is CLI-only and dry-runs by default. It reports only categorized counts; it does not print customer or receipt data.

```sh
php bin/repair-receipt-ledger.php --quotation-id=QUOTATION_ID
php bin/repair-receipt-ledger.php --apply --quotation-id=QUOTATION_ID
```

Apply takes the same exclusive lock as normal receipt writes, re-reads beneath that lock, creates a timestamped `sales-receipts.json.backup.*` copy, then atomically replaces the store. Allocation migration history is appended to each changed receipt. A second apply is idempotent.

## Rollback

Put receipt entry into maintenance mode, retain the failed post-change store for audit, and copy the backup path printed by the command over the canonical receipt store. Do not edit, merge, delete, or recreate individual receipts. Re-run the dry-run and have an administrator review ambiguous splits before restoring receipt entry.

## Administrator web repair

Administrators can open **Review payment allocation** from a stale/unallocated warning. The GET review is read-only and uses the same canonical plan as the CLI. A repair button appears only for a sole active same-project invoice with enough capacity and no cross-project or over-allocation condition. Confirmation is POST-only, CSRF-protected, and bound to the preview state hash.

Confirmation locks both receipt and invoice writers, re-reads both stores, and recalculates the plan. It validates a timestamped receipt-store backup before an atomic write. On post-write or audit failure it atomically restores that backup. Immutable outcome records are stored as individual files under `data/documents/logs/payment-allocation-repairs/`; they contain identifiers and allocation metadata, but no customer identity, bank, mode, reference, or receipt financial fields. Recovery requires stopping financial writers, validating the recorded backup hash/size, and atomically replacing `payment_receipts.json` with the recovery file.

Multiple active invoices, cross-project links, over-allocation, no active invoice, and insufficient invoice capacity remain manual administrator-allocation cases. The workflow never creates or deletes a receipt, changes receipt financial facts or ownership, or changes/finalizes the invoice document status.

## Manual multi-invoice allocation

An administrator may explicitly split a finalized receipt between active invoices in the same project. Amounts accept at most two decimal places, cannot exceed the receipt, and are checked against each invoice's remaining capacity while the receipt and invoice stores are exclusively locked. Any remainder stays visible as unallocated project credit; the workflow does not guess how to distribute it.

Every successful manual split first creates a hash-validated recovery copy and then appends a separate immutable audit record under `data/documents/logs/manual-receipt-allocations/`. If audit persistence fails, the receipt store is restored automatically. Receipt amount, status, date, payment mode, reference, and project ownership are never changed by allocation.
