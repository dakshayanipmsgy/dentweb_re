<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();

$id = safe_text($_GET['id'] ?? '');
$doc = $id !== '' ? documents_get_invoice($id) : null;
$rows = [];
$files = glob(documents_invoices_dir() . '/*.json') ?: [];
foreach ($files as $file) {
    $row = json_load((string) $file, []);
    if (is_array($row)) {
        $rows[] = array_merge(documents_invoice_defaults(), $row);
    }
}
?>
<!doctype html><html><head><meta charset="utf-8"><title>Invoices</title></head><body>
<h1>Invoice Drafts</h1>
<p><a href="admin-documents.php">Back</a></p>
<?php if ($doc !== null): ?><pre><?= htmlspecialchars(json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '', ENT_QUOTES) ?></pre><?php endif; ?>
<table border="1" cellpadding="6"><tr><th>Invoice No</th><th>Status</th><th>Quote ID</th><th>Action</th></tr>
<?php foreach ($rows as $row): ?><tr><td><?= htmlspecialchars((string)($row['invoice_no'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)($row['status'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)($row['linked_quote_id'] ?? ''), ENT_QUOTES) ?></td><td><a href="admin-invoices.php?id=<?= urlencode((string)($row['id'] ?? '')) ?>">View JSON</a></td></tr><?php endforeach; ?>
</table>
</body></html>
