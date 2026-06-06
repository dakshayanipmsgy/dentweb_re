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
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Invoices</title><link rel="stylesheet" href="assets/css/admin-unified.css"></head><body class="admin-shell commercial-admin"><main class="commercial-shell">
<header class="card commercial-header"><div><p class="admin-kicker">Commercial workspace</p><h1>Invoices</h1><p>Review invoice drafts and continue payment tracking through customer receipts.</p></div><nav class="commercial-header__actions"><a class="btn secondary" href="admin-dashboard.php">Dashboard</a><a class="btn secondary" href="admin-documents.php">Document Center</a><a class="btn commercial-header__primary" href="admin-documents.php?tab=accepted_customers">Open Receipts</a></nav></header>
<nav class="commercial-flow-strip" aria-label="Commercial lifecycle"><a href="admin-quotations.php">Quotation</a><span>→</span><a href="admin-agreements.php">Agreement</a><span>→</span><a href="admin-challans.php">Challan</a><span>→</span><a class="active" href="admin-invoices.php">Invoice</a><span>→</span><a href="admin-documents.php?tab=accepted_customers">Receipt</a></nav>
<?php if ($doc !== null): ?><details class="card advanced-fields"><summary>Selected invoice raw record</summary><pre style="overflow:auto;padding:.85rem"><?= htmlspecialchars(json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '', ENT_QUOTES) ?></pre></details><?php endif; ?>
<section class="card"><h2>Invoice Drafts</h2><p class="muted-helper">Invoice creation and finalization remain in the existing document-pack workflow.</p><div class="responsive-table"><table><thead><tr><th>Invoice No</th><th>Status</th><th>Quote ID</th><th>Actions</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><strong><?= htmlspecialchars((string)($row['invoice_no'] ?? ''), ENT_QUOTES) ?></strong></td><td><span class="status-badge status-badge--<?= strtolower(htmlspecialchars((string)($row['status'] ?? ''), ENT_QUOTES)) ?>"><?= htmlspecialchars((string)($row['status'] ?? ''), ENT_QUOTES) ?></span></td><td><?= htmlspecialchars((string)($row['linked_quote_id'] ?? ''), ENT_QUOTES) ?></td><td><div class="row-action-group"><a class="btn" href="admin-invoices.php?id=<?= urlencode((string)($row['id'] ?? '')) ?>">Open</a><details class="more-actions"><summary class="btn secondary">More</summary><div class="more-actions__menu"><a class="btn secondary" href="admin-documents.php?tab=accepted_customers">Document Pack / Receipts</a></div></details></div></td></tr><?php endforeach; if ($rows === []): ?><tr><td colspan="4" class="empty-state">No invoice drafts found.</td></tr><?php endif; ?></tbody></table></div></section></main></body></html>