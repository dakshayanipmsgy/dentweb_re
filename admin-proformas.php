<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();

$id = safe_text($_GET['id'] ?? '');
$doc = $id !== '' ? documents_get_proforma($id) : null;
$rows = [];
$files = glob(documents_proformas_dir() . '/*.json') ?: [];
foreach ($files as $file) {
    $row = json_load((string) $file, []);
    if (is_array($row)) {
        $rows[] = array_merge(documents_proforma_defaults(), $row);
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Proformas</title><link rel="stylesheet" href="assets/css/admin-unified.css"></head><body class="admin-shell commercial-admin"><main class="commercial-shell">
<header class="card commercial-header"><div><p class="admin-kicker">Commercial workspace</p><h1>Proforma Drafts</h1><p>Review proforma records created from accepted quotations before the final invoice workflow.</p></div><nav class="commercial-header__actions" aria-label="Page actions"><a class="btn secondary" href="admin-dashboard.php">Dashboard</a><a class="btn secondary" href="admin-documents.php">Document Center</a><a class="btn secondary" href="admin-invoices.php">Invoices</a></nav></header>
<?php if ($doc !== null): ?><section class="card workspace-panel"><div class="commercial-toolbar"><div><h2>Selected proforma JSON</h2><p class="muted-helper">Raw record preview for support and verification.</p></div><a class="btn secondary" href="admin-proformas.php">Clear preview</a></div><pre class="debug-record"><?= htmlspecialchars(json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '', ENT_QUOTES) ?></pre></section><?php endif; ?>
<section class="card workspace-panel"><div class="commercial-toolbar"><div><h2>All proforma drafts</h2><p class="muted-helper"><?= count($rows) ?> file-backed proforma record<?= count($rows) === 1 ? '' : 's' ?> found.</p></div></div><div class="responsive-table"><table><thead><tr><th>Proforma No</th><th>Status</th><th>Quote ID</th><th>Action</th></tr></thead><tbody>
<?php if ($rows === []): ?><tr><td colspan="4"><div class="empty-state">No proforma drafts have been created yet.</div></td></tr><?php endif; ?>
<?php foreach ($rows as $row): ?><tr><td class="quote-customer"><?= htmlspecialchars((string)($row['proforma_no'] ?? ''), ENT_QUOTES) ?></td><td><span class="status-badge status-badge--<?= htmlspecialchars(strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)($row['status'] ?? 'draft'))), ENT_QUOTES) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)($row['status'] ?? 'draft'))), ENT_QUOTES) ?></span></td><td><?= htmlspecialchars((string)($row['linked_quote_id'] ?? ''), ENT_QUOTES) ?></td><td><a class="btn secondary" href="admin-proformas.php?id=<?= urlencode((string)($row['id'] ?? '')) ?>">View JSON</a></td></tr><?php endforeach; ?>
</tbody></table></div></section>
</main></body></html>
