<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/commercial_lifecycle.php';

require_admin();
documents_ensure_structure();


function invoice_archive_actor(): array
{
    $user = current_user();
    return ['type' => 'admin', 'id' => (string) ($user['id'] ?? ''), 'name' => (string) ($user['full_name'] ?? 'Admin')];
}

function invoice_sync_sales_record(array $doc): void
{
    $invoiceId = (string) ($doc['id'] ?? '');
    if ($invoiceId === '') {
        return;
    }
    $snap = array_merge(documents_customer_snapshot_defaults(), is_array($doc['customer_snapshot'] ?? null) ? $doc['customer_snapshot'] : []);
    $sales = documents_get_sales_document('invoice', $invoiceId) ?: documents_sales_document_defaults('invoice');
    $sales['id'] = $invoiceId;
    $sales['quotation_id'] = (string) ($doc['linked_quote_id'] ?? $doc['quotation_id'] ?? '');
    $sales['customer_mobile'] = (string) ($doc['customer_mobile'] ?? $snap['mobile'] ?? '');
    $sales['customer_name'] = (string) ($snap['name'] ?? '');
    $sales['invoice_no'] = (string) ($doc['invoice_no'] ?? '');
    $sales['invoice_date'] = (string) ($doc['invoice_date'] ?? substr((string) ($doc['created_at'] ?? ''), 0, 10));
    $sales['amount'] = (float) ($doc['input_total_gst_inclusive'] ?? $doc['calc']['grand_total'] ?? $doc['calc']['gross_payable'] ?? 0);
    $sales['status'] = (string) ($doc['status'] ?? 'Draft');
    $sales['archived_flag'] = !empty($doc['archived_flag']);
    $sales['archived_at'] = (string) ($doc['archived_at'] ?? '');
    $sales['archived_by'] = is_array($doc['archived_by'] ?? null) ? $doc['archived_by'] : ['type' => '', 'id' => '', 'name' => ''];
    $sales['created_at'] = (string) ($sales['created_at'] ?: ($doc['created_at'] ?? date('c')));
    $sales['updated_at'] = (string) ($doc['updated_at'] ?? date('c'));
    documents_save_sales_document('invoice', $sales);
}

function invoice_workspace_redirect(string $id, string $status, string $message): void
{
    $query = $id !== '' ? ['id' => $id] : [];
    $query['status'] = $status;
    $query['message'] = $message;
    header('Location: admin-invoices.php?' . http_build_query($query));
    exit;
}

$isDebug = safe_text($_GET['debug'] ?? '') === '1';
$id = safe_text($_GET['id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        invoice_workspace_redirect($id, 'error', 'Security token expired. Please try again.');
    }

    $action = safe_text($_POST['action'] ?? '');
    if (in_array($action, ['archive_invoice', 'unarchive_invoice'], true)) {
        $invoiceId = safe_text($_POST['invoice_id'] ?? '');
        $doc = $invoiceId !== '' ? documents_get_invoice($invoiceId) : null;
        if ($doc === null) { invoice_workspace_redirect('', 'error', 'Invoice not found.'); }
        if ($action === 'archive_invoice') {
            $doc = documents_set_archived($doc, invoice_archive_actor());
            $message = 'Invoice archived.';
        } else {
            $doc = documents_set_unarchived($doc);
            if (strtolower((string) ($doc['status'] ?? '')) === 'active') { $doc['status'] = 'Draft'; }
            $message = 'Invoice unarchived.';
        }
        $doc['updated_at'] = date('c');
        $saved = documents_save_invoice($doc);
        if (empty($saved['ok'])) { invoice_workspace_redirect($invoiceId, 'error', 'Unable to update invoice archive state.'); }
        invoice_sync_sales_record($doc);
        invoice_workspace_redirect($invoiceId, 'success', $message);
    }

    if ($action === 'save_invoice_draft') {
        $invoiceId = safe_text($_POST['invoice_id'] ?? '');
        $doc = $invoiceId !== '' ? documents_get_invoice($invoiceId) : null;
        if ($doc === null) {
            invoice_workspace_redirect('', 'error', 'Invoice not found.');
        }
        if (strtolower((string) ($doc['status'] ?? 'draft')) !== 'draft') {
            invoice_workspace_redirect($invoiceId, 'error', 'Only draft invoices can be edited here.');
        }

        $snap = array_merge(documents_customer_snapshot_defaults(), is_array($doc['customer_snapshot'] ?? null) ? $doc['customer_snapshot'] : []);
        $snap['name'] = safe_text((string) ($_POST['customer_name'] ?? $snap['name'] ?? ''));
        $snap['mobile'] = normalize_customer_mobile((string) ($_POST['customer_mobile'] ?? $doc['customer_mobile'] ?? $snap['mobile'] ?? ''));
        $snap['address'] = safe_multiline_text((string) ($_POST['customer_address'] ?? $snap['address'] ?? ''));
        $doc['customer_snapshot'] = $snap;
        $doc['customer_mobile'] = (string) $snap['mobile'];
        $doc['invoice_no'] = safe_text((string) ($_POST['invoice_no'] ?? $doc['invoice_no'] ?? ''));
        $doc['capacity_kwp'] = safe_text((string) ($_POST['capacity_kwp'] ?? $doc['capacity_kwp'] ?? ''));
        $doc['pricing_mode'] = safe_text((string) ($_POST['pricing_mode'] ?? $doc['pricing_mode'] ?? ''));
        $doc['input_total_gst_inclusive'] = max(0, (float) ($_POST['input_total_gst_inclusive'] ?? $doc['input_total_gst_inclusive'] ?? 0));
        $doc['internal_notes'] = safe_multiline_text((string) ($_POST['internal_notes'] ?? $doc['internal_notes'] ?? ''));
        $doc['updated_at'] = date('c');

        $saved = documents_save_invoice($doc);
        if (empty($saved['ok'])) {
            invoice_workspace_redirect($invoiceId, 'error', 'Unable to save invoice draft.');
        }

        invoice_sync_sales_record($doc);

        invoice_workspace_redirect($invoiceId, 'success', 'Invoice draft saved.');
    }
}

$doc = $id !== '' ? documents_get_invoice($id) : null;
if ($doc !== null) { invoice_sync_sales_record($doc); }
$showArchived = (string) ($_GET['show_archived'] ?? '') === '1';
$rows = [];
foreach (glob(documents_invoices_dir() . '/*.json') ?: [] as $file) {
    $row = json_load((string) $file, []);
    if (is_array($row)) {
        $merged = array_merge(documents_invoice_defaults(), $row);
        invoice_sync_sales_record($merged);
        if ($showArchived || !documents_is_archived($merged)) { $rows[] = $merged; }
    }
}
usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? $b['created_at'] ?? ''), (string) ($a['updated_at'] ?? $a['created_at'] ?? '')));

$flashStatus = safe_text($_GET['status'] ?? '');
$flashMessage = safe_text($_GET['message'] ?? '');
$selectedQuote = $doc !== null ? documents_get_quote((string) ($doc['linked_quote_id'] ?? $doc['quotation_id'] ?? '')) : null;
$selectedSnap = $doc !== null ? array_merge(documents_customer_snapshot_defaults(), is_array($doc['customer_snapshot'] ?? null) ? $doc['customer_snapshot'] : []) : documents_customer_snapshot_defaults();
$selectedAmount = $doc !== null ? (float) ($doc['input_total_gst_inclusive'] ?? $doc['calc']['grand_total'] ?? $doc['calc']['gross_payable'] ?? 0) : 0.0;
$isDraft = $doc !== null && strtolower((string) ($doc['status'] ?? 'draft')) === 'draft';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Invoices</title><link rel="stylesheet" href="assets/css/admin-unified.css"></head><body class="admin-shell commercial-admin"><main class="commercial-shell">
<header class="card commercial-header"><div><p class="admin-kicker">Commercial workspace</p><h1>Invoices</h1><p>Create, review, and maintain invoice drafts directly from accepted current quotations. Challans remain available for delivery tracking but are not required for invoice creation.</p></div><nav class="commercial-header__actions"><a class="btn secondary" href="admin-dashboard.php">Dashboard</a><a class="btn secondary" href="admin-documents.php">Document Center</a><a class="btn commercial-header__primary" href="admin-documents.php?tab=accepted_customers">Accepted Customers</a></nav></header>
<?= render_commercial_lifecycle('invoice') ?>
<?php if ($flashMessage !== ''): ?><div class="flash <?= $flashStatus === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flashMessage, ENT_QUOTES) ?></div><?php endif; ?>
<section class="card"><div class="commercial-toolbar"><div><h2>Invoice list</h2><p class="muted-helper">Open an invoice to view its quotation/customer link, delivery references, and editable draft fields.</p></div><form method="get"><label class="checkbox-field"><input type="checkbox" name="show_archived" value="1" <?= $showArchived ? 'checked' : '' ?>> Show Archived</label><button class="btn secondary" type="submit">Apply</button></form></div><div class="responsive-table"><table><thead><tr><th>Invoice</th><th>Customer</th><th>Linked quotation</th><th>Status</th><th>Amount</th><th>Updated</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($rows as $row): $quote=documents_get_quote((string)($row['linked_quote_id']??$row['quotation_id']??'')); $snap=array_merge(documents_customer_snapshot_defaults(), is_array($row['customer_snapshot']??null)?$row['customer_snapshot']:[]); $amount=(float)($row['calc']['grand_total']??$row['calc']['gross_payable']??$row['input_total_gst_inclusive']??0); ?><tr><td><strong><?=htmlspecialchars((string)($row['invoice_no']?:$row['id']),ENT_QUOTES)?></strong><br><span class="muted-helper"><?=htmlspecialchars((string)($row['id']??''),ENT_QUOTES)?></span></td><td><span class="quote-customer"><?=htmlspecialchars((string)($snap['name']??$quote['customer_name']??''),ENT_QUOTES)?></span><br><span class="muted-helper"><?=htmlspecialchars((string)($row['customer_mobile']??$snap['mobile']??''),ENT_QUOTES)?></span></td><td><?=htmlspecialchars((string)($quote['quote_no']??$row['quotation_no']??$row['linked_quote_id']??''),ENT_QUOTES)?></td><td><span class="status-badge status-badge--<?=strtolower(htmlspecialchars((string)($row['status']??''),ENT_QUOTES))?>"><?=htmlspecialchars((string)($row['status']??''),ENT_QUOTES)?></span><?php if (documents_is_archived($row)): ?><br><span class="pill archived">Archived</span><?php endif; ?></td><td class="quote-amount">₹<?=number_format($amount,2)?></td><td><?=htmlspecialchars((string)($row['updated_at']??$row['created_at']??''),ENT_QUOTES)?></td><td><div class="row-action-group"><a class="btn" href="admin-invoices.php?id=<?=urlencode((string)($row['id']??''))?>">Open/Edit</a><a class="btn secondary" href="invoice-view.php?id=<?=urlencode((string)($row['id']??''))?>" target="_blank" rel="noopener">View/Print</a><a class="btn secondary" href="admin-documents.php?tab=accepted_customers&amp;view=<?=urlencode((string)($row['linked_quote_id']??$row['quotation_id']??''))?>">Document Pack</a><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>"><input type="hidden" name="action" value="<?= documents_is_archived($row) ? 'unarchive_invoice' : 'archive_invoice' ?>"><input type="hidden" name="invoice_id" value="<?= htmlspecialchars((string)($row['id']??''), ENT_QUOTES) ?>"><button class="btn <?= documents_is_archived($row) ? 'secondary' : 'warn' ?>" type="submit"><?= documents_is_archived($row) ? 'Unarchive' : 'Archive' ?></button></form></div></td></tr><?php endforeach; if ($rows===[]): ?><tr><td colspan="7" class="empty-state">No invoices found. Create one from an accepted customer's document pack.</td></tr><?php endif; ?></tbody></table></div></section>
<?php if ($doc !== null): ?>
<section class="card"><div class="commercial-toolbar"><div><h2>Invoice workspace: <?= htmlspecialchars((string)($doc['invoice_no'] ?: $doc['id']), ENT_QUOTES) ?></h2><p class="muted-helper">Linked quotation: <?= htmlspecialchars((string)($selectedQuote['quote_no'] ?? $doc['quotation_no'] ?? $doc['linked_quote_id'] ?? ''), ENT_QUOTES) ?>. Invoice data is sourced from the accepted quotation and can stand without a challan.</p></div><div class="row-action-group"><?php if (documents_is_archived($doc)): ?><span class="pill archived">Archived</span><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>"><input type="hidden" name="action" value="<?= documents_is_archived($doc) ? 'unarchive_invoice' : 'archive_invoice' ?>"><input type="hidden" name="invoice_id" value="<?= htmlspecialchars((string)$doc['id'], ENT_QUOTES) ?>"><button class="btn <?= documents_is_archived($doc) ? 'secondary' : 'warn' ?>" type="submit"><?= documents_is_archived($doc) ? 'Unarchive' : 'Archive' ?></button></form><a class="btn" href="invoice-view.php?id=<?= urlencode((string)$doc['id']) ?>" target="_blank" rel="noopener">View/Print Invoice</a><a class="btn secondary" href="admin-documents.php?tab=accepted_customers&amp;view=<?= urlencode((string)($doc['linked_quote_id'] ?? $doc['quotation_id'] ?? '')) ?>">Open document pack</a><a class="btn secondary" href="?id=<?= urlencode((string)$doc['id']) ?>&amp;debug=1">Debug record</a></div></div>
<form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>"><input type="hidden" name="action" value="save_invoice_draft"><input type="hidden" name="invoice_id" value="<?= htmlspecialchars((string)$doc['id'], ENT_QUOTES) ?>">
<div class="form-section-card"><h3>Invoice details</h3><p class="muted-helper">Draft fields are editable until the invoice is issued or finalized.</p><div class="form-grid"><div><label>Invoice number</label><input type="text" name="invoice_no" value="<?= htmlspecialchars((string)($doc['invoice_no'] ?? ''), ENT_QUOTES) ?>" <?= $isDraft ? '' : 'readonly' ?>></div><div><label>Status</label><input type="text" value="<?= htmlspecialchars((string)($doc['status'] ?? ''), ENT_QUOTES) ?>" readonly></div><div><label>Amount</label><input type="number" step="0.01" min="0" name="input_total_gst_inclusive" value="<?= htmlspecialchars((string)$selectedAmount, ENT_QUOTES) ?>" <?= $isDraft ? '' : 'readonly' ?>></div><div><label>Capacity (kWp)</label><input type="text" name="capacity_kwp" value="<?= htmlspecialchars((string)($doc['capacity_kwp'] ?? ''), ENT_QUOTES) ?>" <?= $isDraft ? '' : 'readonly' ?>></div><div><label>Pricing mode</label><input type="text" name="pricing_mode" value="<?= htmlspecialchars((string)($doc['pricing_mode'] ?? ''), ENT_QUOTES) ?>" <?= $isDraft ? '' : 'readonly' ?>></div><div><label>Quotation ID</label><input type="text" value="<?= htmlspecialchars((string)($doc['linked_quote_id'] ?? $doc['quotation_id'] ?? ''), ENT_QUOTES) ?>" readonly></div></div></div>
<div class="form-section-card"><h3>Customer snapshot</h3><div class="form-grid"><div><label>Name</label><input type="text" name="customer_name" value="<?= htmlspecialchars((string)($selectedSnap['name'] ?? ''), ENT_QUOTES) ?>" <?= $isDraft ? '' : 'readonly' ?>></div><div><label>Mobile</label><input type="text" name="customer_mobile" value="<?= htmlspecialchars((string)($doc['customer_mobile'] ?? $selectedSnap['mobile'] ?? ''), ENT_QUOTES) ?>" <?= $isDraft ? '' : 'readonly' ?>></div><div class="full-span"><label>Address</label><textarea name="customer_address" rows="3" <?= $isDraft ? '' : 'readonly' ?>><?= htmlspecialchars((string)($selectedSnap['address'] ?? ''), ENT_QUOTES) ?></textarea></div></div></div>
<div class="form-section-card"><h3>Delivery references</h3><p class="muted-helper">These references are informational only. An invoice can be created and remain linked to the quotation/customer even when no challan exists.</p><div class="responsive-table"><table><thead><tr><th>Challan</th><th>Date</th><th>Dispatch advice</th><th>Status</th></tr></thead><tbody><?php $deliveryRows=is_array($doc['delivery_details']??null)?$doc['delivery_details']:[]; foreach($deliveryRows as $delivery): ?><tr><td><?= htmlspecialchars((string)($delivery['challan_no'] ?? $delivery['challan_id'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)($delivery['delivery_date'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)($delivery['dispatch_advice_no'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)($delivery['delivery_status'] ?? ''), ENT_QUOTES) ?></td></tr><?php endforeach; if($deliveryRows===[]): ?><tr><td colspan="4" class="empty-state">No challan is linked. This does not block invoice work.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="form-section-card"><h3>Internal notes</h3><textarea name="internal_notes" rows="4" <?= $isDraft ? '' : 'readonly' ?>><?= htmlspecialchars((string)($doc['internal_notes'] ?? ''), ENT_QUOTES) ?></textarea></div>
<div class="sticky-action-footer"><a class="btn secondary" href="admin-invoices.php">Back to list</a><a class="btn secondary" href="invoice-view.php?id=<?= urlencode((string)$doc['id']) ?>" target="_blank" rel="noopener">Open/View Invoice</a><?php if ($isDraft): ?><button class="btn" type="submit">Save draft</button><?php else: ?><span class="muted-helper">Issued/final invoices are read-only in this workspace.</span><?php endif; ?></div></form></section>
<?php elseif ($id !== ''): ?><section class="card empty-state">Invoice not found.</section><?php endif; ?>
<?php if($isDebug && $doc!==null):?><details class="card advanced-fields" open><summary>Admin-only raw invoice debug record</summary><pre class="debug-record"><?=htmlspecialchars(json_encode($doc,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)?:'',ENT_QUOTES)?></pre></details><?php endif;?></main></body></html>
