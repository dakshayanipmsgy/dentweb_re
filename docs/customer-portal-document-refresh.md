# Customer portal document refresh

Customers open payment receipts through `receipt-view.php?customer_view=1`, using the same template as staff. Each request checks the active customer session, current quotation owner, immutable project link and canonical finalized receipt ledger. Draft, voided, reversed, archived and other customers' receipts remain unavailable. Existing portal receipt URLs redirect to this view.

The dashboard has touch-friendly navigation, document cards and one account-level handover entry, including accounts without an accepted quotation. Existing saved handovers are opened through `customer-handover.php`, which resolves only the authenticated customer's assigned HTML file inside the handover directory. It preserves the saved body rather than regenerating customer terms. Older template settings containing a full HTML header are reduced to CSS before wrapping.

Invoices, receipts and handover packs have A4 portrait print styles, hidden navigation, readable tables and Print / Save PDF controls. Invoice totals are prominent in the header; internal invoice notes are no longer used as a customer-facing fallback. Invoice print styles restore desktop grids independently of the mobile viewport, allow long tables to paginate and repeat table headers.

## Validation

- PHP 8.3 syntax checks on changed PHP files.
- `customer_receipt_access_test.php`: ownership, stale snapshot phone, missing/wrong project, draft/cancelled/voided/archived/reversed receipts, unqualified IDs.
- `handover_portal_layout_test.php`: content preservation, print toolbar, escaping, repeated opening, malformed legacy HTML/CSS settings.
- Existing customer invoice visibility/tax/project value, bank details, payment link/cross-session, receipt ledger and signature tests.
- Synthetic invoice, receipt and handover HTML rendered to PDFs and inspected. This checks print layout; browser print engines and live customer data still require a post-deployment check.

## Release

Deploy all changed PHP files and the three new CSS files together, including `customer-handover.php` and `includes/customer_receipt_access.php`. No data migration, SQL or Node.js dependency is added. Retain runtime `storage/`, `data/`, `handovers/` and uploads. Check an authorized customer account on phone/iPad/desktop, open a finalized receipt, invoice and saved handover, and check print preview. Confirm another customer's document ID is denied. The GitHub change itself does not verify a cPanel deployment.
