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
    $sales['invoice_date'] = documents_invoice_authoritative_date($doc);
    $summary = documents_invoice_payment_summary($doc);
    $sales['amount'] = $summary['invoice_total'];
    $sales['status'] = documents_invoice_normalize_status((string) ($doc['status'] ?? 'draft'));
    $sales['document_status'] = $sales['status'];
    $sales['payment_status'] = $summary['payment_status'];
    $sales['received_total'] = $summary['total_received'];
    $sales['outstanding'] = $summary['outstanding'];
    $sales['overpayment'] = $summary['overpayment'];
    $sales['revision_no'] = (int) ($doc['revision_no'] ?? 0);
    $sales['finalized_at'] = (string) ($doc['finalized_at'] ?? '');
    $sales['cancelled_flag'] = documents_invoice_is_cancelled($doc);
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
    if ($action === 'create_standalone_invoice') {
        $created=documents_create_standalone_invoice(['segment'=>'RES']);
        if(empty($created['ok'])) invoice_workspace_redirect('', 'error', (string)($created['error']??'Unable to create invoice.'));
        invoice_workspace_redirect((string)$created['invoice_id'], 'success', 'Standalone invoice draft created.');
    }
    if ($action === 'add_standalone_payment') {
        $invoiceId=safe_text($_POST['invoice_id']??'');$paymentInvoice=documents_get_invoice($invoiceId);
        if(!is_array($paymentInvoice))invoice_workspace_redirect('', 'error', 'Invoice not found.');
        $result=documents_add_standalone_invoice_payment($paymentInvoice,$_POST,invoice_archive_actor());
        invoice_workspace_redirect($invoiceId,empty($result['ok'])?'error':'success',empty($result['ok'])?(string)$result['error']:'Payment receipt added.');
    }
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
        if (!documents_invoice_is_draft($doc)) {
            invoice_workspace_redirect($invoiceId, 'error', 'Only draft invoices can be edited here.');
        }

        $snap = array_merge(documents_customer_snapshot_defaults(), is_array($doc['customer_snapshot'] ?? null) ? $doc['customer_snapshot'] : []);
        $snap['name'] = safe_text((string) ($_POST['customer_name'] ?? $snap['name'] ?? ''));
        $snap['mobile'] = normalize_customer_mobile((string) ($_POST['customer_mobile'] ?? $doc['customer_mobile'] ?? $snap['mobile'] ?? ''));
        $snap['address'] = safe_multiline_text((string) ($_POST['customer_address'] ?? $snap['address'] ?? ''));
        foreach(['city','district','state','pin_code','meter_number','meter_serial_number','jbvnl_account_number','application_id'] as $field){$snap[$field]=safe_text((string)($_POST['customer_'.$field]??$snap[$field]??''));}
        $doc['customer_snapshot'] = $snap;
        $doc['customer_mobile'] = (string) $snap['mobile'];
        if(documents_invoice_is_standalone($doc)){
            $match=documents_standalone_match_customer($doc,(string)$snap['mobile'],new CustomerFsStore(),true);$doc=$match['invoice'];foreach($snap as $key=>$value){if(trim((string)$value)!=='')$doc['customer_snapshot'][$key]=$value;}
            $doc['manual_reference']=['quotation_no'=>safe_text((string)($_POST['manual_quotation_no']??'')),'quotation_date'=>safe_text((string)($_POST['manual_quotation_date']??'')),'external_reference'=>safe_text((string)($_POST['external_reference']??'')),'quotation_amount'=>($_POST['manual_quotation_amount']??'')===''?null:(float)($_POST['manual_quotation_amount']??0)];
            $doc['system_type']=documents_quote_normalize_system_type((string)($_POST['system_type']??'ongrid'));
            $doc['main_solar_kwp']=max(0,(float)($_POST['main_solar_kwp']??0));$doc['complimentary_non_dcr_kwp']=max(0,(float)($_POST['complimentary_non_dcr_kwp']??0));$doc['capacity_kwp']=$doc['main_solar_kwp']+$doc['complimentary_non_dcr_kwp'];
            $doc['site_address']=safe_multiline_text((string)($_POST['site_address']??''));$doc['billing_address']=safe_multiline_text((string)($_POST['customer_address']??''));$doc['place_of_supply_state']=safe_text((string)($_POST['place_of_supply_state']??''));
            foreach(['application_submitted_date','sanction_load_kwp','installed_pv_module_capacity_kwp','circle_name','division_name','sub_division_name','project_summary_line'] as $field)$doc[$field]=safe_text((string)($_POST[$field]??''));
            $model=safe_text((string)($_POST['selected_model_number']??''));$doc['selected_model_number']=$model;$doc['rate_chart_snapshot']=['system_type'=>$doc['system_type'],'model_number'=>$model,'solar_size_kwp'=>$doc['capacity_kwp'],'dcr_size_kwp'=>$doc['main_solar_kwp'],'non_dcr_size_kwp'=>$doc['complimentary_non_dcr_kwp'],'total_system_size_kwp'=>$doc['capacity_kwp'],'hybrid_inverter_kva'=>max(0,(float)($_POST['hybrid_inverter_kva']??0)),'hybrid_phase'=>safe_text((string)($_POST['hybrid_phase']??'')),'hybrid_battery_count'=>max(0,(int)($_POST['hybrid_battery_count']??0)),'captured_at'=>date('c')];
            $itemResult=documents_standalone_apply_master_items($doc,(array)($_POST['items']??[]),safe_text((string)($_POST['tax_profile_id']??'')));if(empty($itemResult['ok']))invoice_workspace_redirect($invoiceId,'error',(string)$itemResult['error']);$doc=$itemResult['invoice'];
        }
        $dateResult = documents_invoice_set_date($doc, (string) ($_POST['invoice_date'] ?? ''), invoice_archive_actor());
        if (empty($dateResult['ok'])) { invoice_workspace_redirect($invoiceId, 'error', (string) $dateResult['error']); }
        $doc = $dateResult['invoice'];
        $doc['invoice_no'] = safe_text((string) ($_POST['invoice_no'] ?? $doc['invoice_no'] ?? ''));
        if(!documents_invoice_is_standalone($doc))$doc['capacity_kwp'] = safe_text((string) ($_POST['capacity_kwp'] ?? $doc['capacity_kwp'] ?? ''));
        $doc['pricing_mode'] = safe_text((string) ($_POST['pricing_mode'] ?? $doc['pricing_mode'] ?? ''));
        $moneyParsed = documents_invoice_parse_money($_POST['final_invoice_total_incl_gst'] ?? $_POST['input_total_gst_inclusive'] ?? $doc['input_total_gst_inclusive'] ?? 0);
        if (empty($moneyParsed['ok'])) { invoice_workspace_redirect($invoiceId, 'error', (string) $moneyParsed['error']); }
        if(documents_invoice_is_standalone($doc)){$moneyParsed=['ok'=>true,'value'=>documents_invoice_final_total($doc),'error'=>''];}
        $reason = safe_multiline_text((string) ($_POST['adjustment_reason'] ?? $doc['pricing']['adjustment_reason'] ?? ''));
        $quoteReferenceTotal = documents_invoice_quotation_reference_total($doc);
        if (documents_invoice_has_quotation_reference($doc) && abs((float) $moneyParsed['value'] - $quoteReferenceTotal) > DOCUMENTS_INVOICE_MONEY_TOLERANCE && $reason === '') {
            invoice_workspace_redirect($invoiceId, 'error', 'Adjustment reason is required when the final invoice total differs from the quotation.');
        }
        $submittedType = safe_text((string) ($_POST['adjustment_type'] ?? ''));
        $previousTotal = documents_invoice_final_total($doc);
        $recalculated = documents_invoice_recalculate_pricing($doc, (float) $moneyParsed['value'], $reason);
        $doc = (array) ($recalculated['invoice'] ?? $doc);
        if ($submittedType !== '' && $submittedType !== documents_invoice_adjustment_type($doc)) {
            invoice_workspace_redirect($invoiceId, 'error', 'Submitted adjustment type does not match the final invoice total.');
        }
        $doc = documents_invoice_record_pricing_history($doc, $previousTotal, invoice_archive_actor(), $reason);
        $doc['internal_notes'] = safe_multiline_text((string) ($_POST['internal_notes'] ?? $doc['internal_notes'] ?? ''));
        $doc['updated_at'] = date('c');

        $saved = documents_save_invoice($doc);
        if (empty($saved['ok'])) {
            invoice_workspace_redirect($invoiceId, 'error', 'Unable to save invoice draft.');
        }

        invoice_sync_sales_record($doc);

        $pricing = is_array($doc['pricing'] ?? null) ? $doc['pricing'] : [];
        $message = documents_invoice_is_standalone($doc) ? 'Standalone invoice draft saved.' : (!documents_invoice_has_quotation_reference($doc) ? 'Legacy project invoice draft saved.' : (documents_invoice_has_price_adjustment($doc)
            ? 'Invoice draft saved. Final invoice total is ₹' . number_format(documents_invoice_final_total($doc), 2) . ', which is ₹' . number_format(documents_invoice_adjustment_amount($doc), 2) . ' ' . (documents_invoice_adjustment_type($doc) === DOCUMENTS_INVOICE_ADJUSTMENT_DISCOUNT ? 'lower than' : 'higher than') . ' quotation ' . (string) ($doc['quotation_no'] ?? $doc['linked_quote_id'] ?? '') . '.'
            : 'Invoice draft saved. Final invoice total matches the linked quotation.'));
        invoice_workspace_redirect($invoiceId, 'success', $message);
    }

    if (in_array($action, ['finalize_invoice','start_invoice_revision','cancel_invoice'], true)) {
        $invoiceId = safe_text($_POST['invoice_id'] ?? '');
        $doc = $invoiceId !== '' ? documents_get_invoice($invoiceId) : null;
        if ($doc === null) { invoice_workspace_redirect('', 'error', 'Invoice not found.'); }
        if ($action === 'finalize_invoice') {
            $result = documents_invoice_finalize($doc, invoice_archive_actor());
            if (empty($result['ok'])) { invoice_workspace_redirect($invoiceId, 'error', implode(' ', (array)($result['errors'] ?? ['Unable to finalize invoice.']))); }
            $doc = $result['invoice']; $saved = documents_save_invoice($doc);
            if (empty($saved['ok'])) { invoice_workspace_redirect($invoiceId, 'error', 'Unable to save finalized invoice.'); }
            invoice_sync_sales_record($doc); invoice_workspace_redirect($invoiceId, 'success', 'Invoice finalized / issued. Payment status: ' . documents_invoice_payment_status_label(documents_invoice_payment_status($doc)) . '.');
        }
        if ($action === 'start_invoice_revision') {
            $result = documents_invoice_start_revision($doc, invoice_archive_actor(), (string)($_POST['revision_reason'] ?? ''));
            if (empty($result['ok'])) { invoice_workspace_redirect($invoiceId, 'error', (string)($result['error'] ?? 'Unable to start revision.')); }
            $doc = $result['invoice']; $saved = documents_save_invoice($doc);
            if (empty($saved['ok'])) { invoice_workspace_redirect($invoiceId, 'error', 'Unable to save revision draft.'); }
            invoice_sync_sales_record($doc); invoice_workspace_redirect($invoiceId, 'success', 'Revision draft created. The previous finalized revision remains in history until this revision is finalized.');
        }
        if ($action === 'cancel_invoice') {
            if (safe_text($_POST['confirm_cancel'] ?? '') !== '1') { invoice_workspace_redirect($invoiceId, 'error', 'Confirm cancellation before cancelling the invoice.'); }
            $result = documents_invoice_cancel($doc, invoice_archive_actor(), (string)($_POST['cancel_reason'] ?? ''));
            if (empty($result['ok'])) { invoice_workspace_redirect($invoiceId, 'error', (string)($result['error'] ?? 'Unable to cancel invoice.')); }
            $doc = $result['invoice']; $saved = documents_save_invoice($doc);
            if (empty($saved['ok'])) { invoice_workspace_redirect($invoiceId, 'error', 'Unable to save cancelled invoice.'); }
            invoice_sync_sales_record($doc); invoice_workspace_redirect($invoiceId, 'success', 'Invoice cancelled. Receipts were preserved and not unallocated.');
        }
    }

}

$doc = $id !== '' ? documents_get_invoice($id) : null;
$showArchived = (string) ($_GET['show_archived'] ?? '') === '1';
$rows = [];
foreach (glob(documents_invoices_dir() . '/*.json') ?: [] as $file) {
    $row = json_load((string) $file, []);
    if (is_array($row)) {
        $merged = documents_invoice_normalize_date(documents_invoice_normalize_commercial_snapshot(array_merge(documents_invoice_defaults(), $row)));
        $merged['status'] = documents_invoice_normalize_status((string)($merged['status'] ?? 'draft'));
        if ($showArchived || !documents_is_archived($merged)) { $rows[] = $merged; }
    }
}
usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? $b['created_at'] ?? ''), (string) ($a['updated_at'] ?? $a['created_at'] ?? '')));

$flashStatus = safe_text($_GET['status'] ?? '');
$flashMessage = safe_text($_GET['message'] ?? '');
$selectedQuote = $doc !== null ? documents_get_quote((string) ($doc['linked_quote_id'] ?? $doc['quotation_id'] ?? '')) : null;
$selectedSnap = $doc !== null ? array_merge(documents_customer_snapshot_defaults(), is_array($doc['customer_snapshot'] ?? null) ? $doc['customer_snapshot'] : []) : documents_customer_snapshot_defaults();
$selectedAmount = $doc !== null ? documents_invoice_final_total($doc) : 0.0;
$selectedQuotationTotal = $doc !== null ? documents_invoice_quotation_reference_total($doc) : 0.0;
$selectedAdjustmentType = $doc !== null ? documents_invoice_adjustment_type($doc) : DOCUMENTS_INVOICE_ADJUSTMENT_NONE;
$selectedAdjustmentAmount = $doc !== null ? documents_invoice_adjustment_amount($doc) : 0.0;
$selectedAdjustmentPercent = $doc !== null ? (float) ($doc['pricing']['adjustment_percent'] ?? 0) : 0.0;
$selectedAdjustmentReason = $doc !== null ? (string) ($doc['pricing']['adjustment_reason'] ?? '') : '';
$selectedInvoiceDate = $doc !== null ? documents_invoice_authoritative_date($doc) : date('Y-m-d');
$selectedPaymentSummary = $doc !== null ? documents_invoice_payment_summary($doc) : null;
$isDraft = $doc !== null && documents_invoice_is_draft($doc);
$isStandaloneWorkspace = $doc !== null && documents_invoice_is_standalone($doc);
$invoiceCustomers = [];
$invoiceKits = [];
$invoiceComponents = [];
$invoiceTaxProfiles = [];
$invoiceVariantsByComponent = [];
$invoiceQuoteDefaults = [];
if ($isStandaloneWorkspace) {
    // These stores can be large and CustomerFsStore initializes its filesystem. Do not touch
    // either while merely listing existing quotation/legacy invoices.
    $invoiceCustomers = (new CustomerFsStore())->listActiveCustomers();
    $invoiceKits = documents_inventory_kits(false);
    $invoiceComponents = documents_inventory_components(false);
    $invoiceTaxProfiles = documents_inventory_tax_profiles(false);
    foreach (documents_inventory_component_variants(false) as $variant) {
        $invoiceVariantsByComponent[(string) ($variant['component_id'] ?? '')][] = $variant;
    }
    $invoiceQuoteDefaults = load_quote_defaults();
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Invoices</title><link rel="stylesheet" href="assets/css/admin-unified.css"><?php require_once __DIR__ . '/includes/pwa_head.php'; ?></head><body class="admin-shell commercial-admin"><?php require_once __DIR__ . '/includes/mobile_app_nav.php'; ?><main class="commercial-shell">
<header class="card commercial-header"><div><p class="admin-kicker">Commercial workspace</p><h1>Invoices</h1><p>Create, review, and maintain quotation, legacy-project, and standalone invoices through one lifecycle.</p></div><nav class="commercial-header__actions"><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>"><input type="hidden" name="action" value="create_standalone_invoice"><button class="btn commercial-header__primary" type="submit">Create Invoice</button></form><a class="btn secondary" href="admin-dashboard.php">Dashboard</a><a class="btn secondary" href="admin-documents.php">Document Center</a><a class="btn secondary" href="admin-documents.php?tab=accepted_customers">Accepted Customers</a></nav></header>
<?= render_commercial_lifecycle('invoice') ?>
<?php if ($flashMessage !== ''): ?><div class="flash <?= $flashStatus === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flashMessage, ENT_QUOTES) ?></div><?php endif; ?>
<section class="card"><div class="commercial-toolbar"><div><h2>Invoice list</h2><p class="muted-helper">Open an invoice to view its quotation/customer link, delivery references, and editable draft fields.</p></div><form method="get"><label class="checkbox-field"><input type="checkbox" name="show_archived" value="1" <?= $showArchived ? 'checked' : '' ?>> Show Archived</label><button class="btn secondary" type="submit">Apply</button></form></div><div class="responsive-table"><table><thead><tr><th>Invoice</th><th>Invoice date</th><th>Customer</th><th>Linked quotation</th><th>Document status</th><th>Payment status</th><th>Amount / received</th><th>Balance</th><th>Updated</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($rows as $row): $quote=documents_get_quote((string)($row['linked_quote_id']??$row['quotation_id']??'')); $snap=array_merge(documents_customer_snapshot_defaults(), is_array($row['customer_snapshot']??null)?$row['customer_snapshot']:[]); $amount=documents_invoice_final_total($row); $pay=documents_invoice_payment_summary($row); $standalone=documents_invoice_is_standalone($row); ?><tr><td><strong><?=htmlspecialchars((string)($row['invoice_no']?:$row['id']),ENT_QUOTES)?></strong><br><span class="muted-helper"><?=htmlspecialchars((string)($row['id']??''),ENT_QUOTES)?></span></td><td><?=htmlspecialchars(documents_invoice_authoritative_date($row),ENT_QUOTES)?></td><td><span class="quote-customer"><?=htmlspecialchars((string)($snap['name']??$quote['customer_name']??''),ENT_QUOTES)?></span><br><span class="muted-helper"><?=htmlspecialchars((string)($row['customer_mobile']??$snap['mobile']??''),ENT_QUOTES)?></span></td><td><?= $standalone ? 'Standalone'.(!empty($row['manual_reference']['quotation_no'])?'<br><span class="muted-helper">Ref: '.htmlspecialchars((string)$row['manual_reference']['quotation_no'],ENT_QUOTES).'</span>':'') : htmlspecialchars((string)($quote['quote_no']??$row['quotation_no']??$row['linked_quote_id']??''),ENT_QUOTES) ?></td><td><span class="status-badge status-badge--<?=strtolower(htmlspecialchars((string)($row['status']??''),ENT_QUOTES))?>"><?=htmlspecialchars(documents_invoice_status_label((string)($row['status']??'')),ENT_QUOTES)?></span><?php if (documents_is_archived($row)): ?><br><span class="pill archived">Archived</span><?php endif; ?></td><td><span class="status-badge"><?=htmlspecialchars(documents_invoice_payment_status_label($pay['payment_status']),ENT_QUOTES)?></span></td><td class="quote-amount">₹<?=number_format($amount,2)?><br><span class="muted-helper">Received ₹<?=number_format((float)$pay['total_received'],2)?></span></td><td><?= $pay['overpayment'] > 0 ? 'Credit ₹'.number_format((float)$pay['overpayment'],2) : 'Due ₹'.number_format((float)$pay['outstanding'],2) ?></td><td><?=htmlspecialchars((string)($row['updated_at']??$row['created_at']??''),ENT_QUOTES)?></td><td><div class="row-action-group"><a class="btn" href="admin-invoices.php?id=<?=urlencode((string)($row['id']??''))?>">Open/Edit</a><a class="btn secondary" href="invoice-view.php?id=<?=urlencode((string)($row['id']??''))?>" target="_blank" rel="noopener">View/Print</a><?php if(!$standalone): ?><a class="btn secondary" href="admin-documents.php?tab=accepted_customers&amp;view=<?=urlencode((string)($row['linked_quote_id']??$row['quotation_id']??''))?>">Document Pack</a><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>"><input type="hidden" name="action" value="<?= documents_is_archived($row) ? 'unarchive_invoice' : 'archive_invoice' ?>"><input type="hidden" name="invoice_id" value="<?= htmlspecialchars((string)($row['id']??''), ENT_QUOTES) ?>"><button class="btn <?= documents_is_archived($row) ? 'secondary' : 'warn' ?>" type="submit"><?= documents_is_archived($row) ? 'Unarchive' : 'Archive' ?></button></form></div></td></tr><?php endforeach; if ($rows===[]): ?><tr><td colspan="10" class="empty-state">No invoices found. Create one from an accepted customer's document pack.</td></tr><?php endif; ?></tbody></table></div></section>
<?php if ($doc !== null): ?>
<section class="card"><div class="commercial-toolbar"><div><h2>Invoice workspace: <?= htmlspecialchars((string)($doc['invoice_no'] ?: $doc['id']), ENT_QUOTES) ?></h2><p class="muted-helper">Invoice date: <?= htmlspecialchars($selectedInvoiceDate, ENT_QUOTES) ?> · <?= documents_invoice_is_standalone($doc) ? 'Standalone invoice'.(!empty($doc['manual_reference']['quotation_no'])?' · Reference: '.htmlspecialchars((string)$doc['manual_reference']['quotation_no'],ENT_QUOTES):'') : (documents_invoice_has_quotation_reference($doc) ? 'Linked quotation: '.htmlspecialchars((string)($selectedQuote['quote_no'] ?? $doc['quotation_no'] ?? $doc['linked_quote_id'] ?? ''), ENT_QUOTES) : 'Linked project: Legacy Project '.htmlspecialchars((string)($doc['commercial_ref']['id']??''),ENT_QUOTES)) ?>.</p></div><div class="row-action-group"><?php if (documents_is_archived($doc)): ?><span class="pill archived">Archived</span><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>"><input type="hidden" name="action" value="<?= documents_is_archived($doc) ? 'unarchive_invoice' : 'archive_invoice' ?>"><input type="hidden" name="invoice_id" value="<?= htmlspecialchars((string)$doc['id'], ENT_QUOTES) ?>"><button class="btn <?= documents_is_archived($doc) ? 'secondary' : 'warn' ?>" type="submit"><?= documents_is_archived($doc) ? 'Unarchive' : 'Archive' ?></button></form><a class="btn" href="invoice-view.php?id=<?= urlencode((string)$doc['id']) ?>" target="_blank" rel="noopener">View/Print Invoice</a><?php if(!documents_invoice_is_standalone($doc)): ?><a class="btn secondary" href="admin-documents.php?tab=accepted_customers&amp;view=<?= urlencode((string)($doc['linked_quote_id'] ?? $doc['quotation_id'] ?? '')) ?>">Open document pack</a><?php endif; ?><a class="btn secondary" href="?id=<?= urlencode((string)$doc['id']) ?>&amp;debug=1">Debug record</a></div></div>
<form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>"><input type="hidden" name="action" value="save_invoice_draft"><input type="hidden" name="invoice_id" value="<?= htmlspecialchars((string)$doc['id'], ENT_QUOTES) ?>">
<?php if ($selectedPaymentSummary): ?><div class="form-section-card"><h3>Payment reconciliation</h3><?php if(($selectedPaymentSummary['allocation_attention_count']??0)>0): $repairPlan=documents_payment_allocation_repair_plan((string)($doc['linked_quote_id']??$doc['quotation_id']??'')); ?><div class="flash error"><strong>Administrator allocation required:</strong> <?= (int)$selectedPaymentSummary['allocation_attention_count'] ?> receipt(s) contain unallocated or stale project payment. <?php if(!empty($repairPlan['can_repair'])):?><a class="btn" href="admin-payment-allocation.php?quotation_id=<?=urlencode((string)$repairPlan['quote_id'])?>">Review payment allocation</a><?php else:?>Automatic repair is unavailable: <?=htmlspecialchars(implode(' ',array_values($repairPlan['blocked'])),ENT_QUOTES)?> Review the manual allocation workflow in the Accepted Customers document pack.<?php endif;?></div><?php endif; ?><div class="form-grid"><div><label>Invoice total</label><input type="text" value="₹<?= htmlspecialchars(number_format((float)$selectedPaymentSummary['invoice_total'],2), ENT_QUOTES) ?>" readonly></div><div><label>Payments received</label><input type="text" value="₹<?= htmlspecialchars(number_format((float)$selectedPaymentSummary['total_received'],2), ENT_QUOTES) ?>" readonly></div><div><label>Remaining amount</label><input type="text" value="₹<?= htmlspecialchars(number_format((float)$selectedPaymentSummary['outstanding'],2), ENT_QUOTES) ?>" readonly></div><div><label>Customer credit / overpayment</label><input type="text" value="₹<?= htmlspecialchars(number_format((float)$selectedPaymentSummary['overpayment'],2), ENT_QUOTES) ?>" readonly></div><div><label>Payment status</label><input type="text" value="<?= htmlspecialchars(documents_invoice_payment_status_label($selectedPaymentSummary['payment_status']), ENT_QUOTES) ?>" readonly></div><div><label>Receipt count</label><input type="text" value="<?= (int)$selectedPaymentSummary['receipt_count'] ?>" readonly></div></div><?php if (!$isDraft && documents_invoice_is_finalized($doc)): ?><p class="muted-helper">Finalized at <?= htmlspecialchars((string)($doc['finalized_at'] ?? ''), ENT_QUOTES) ?> · Revision <?= (int)($doc['revision_no'] ?? 1) ?></p><?php endif; ?></div><?php endif; ?><div class="form-section-card"><h3>Invoice details</h3><p class="muted-helper">Draft fields are editable until the invoice is issued or finalized.</p><div class="form-grid"><div><label>Invoice number</label><input type="text" name="invoice_no" value="<?= htmlspecialchars((string)($doc['invoice_no'] ?? ''), ENT_QUOTES) ?>" <?= $isDraft ? '' : 'readonly' ?>></div><div><label>Status</label><input type="text" value="<?= htmlspecialchars((string)($doc['status'] ?? ''), ENT_QUOTES) ?>" readonly></div><div><label>Invoice date</label><input type="date" name="invoice_date" value="<?= htmlspecialchars($selectedInvoiceDate, ENT_QUOTES) ?>" <?= $isDraft ? '' : 'readonly' ?> required></div><?php if(documents_invoice_has_quotation_reference($doc)):?><div><label>Quotation total incl GST</label><input type="text" value="₹<?= htmlspecialchars(number_format($selectedQuotationTotal, 2), ENT_QUOTES) ?>" readonly></div><?php else:?><div><label>Historical project value</label><input type="text" value="Unknown" readonly></div><?php endif;?><div><label>Final invoice total incl GST</label><input type="number" step="0.01" min="0" name="final_invoice_total_incl_gst" value="<?= htmlspecialchars(number_format($selectedAmount, 2, '.', ''), ENT_QUOTES) ?>" <?= $isDraft ? '' : 'readonly' ?>></div><div><label>Adjustment type</label><select name="adjustment_type" <?= $isDraft ? '' : 'disabled' ?>><option value="none" <?= $selectedAdjustmentType==='none'?'selected':'' ?>>None</option><option value="discount" <?= $selectedAdjustmentType==='discount'?'selected':'' ?>>Final discount</option><option value="surcharge" <?= $selectedAdjustmentType==='surcharge'?'selected':'' ?>>Additional charge</option></select></div><div><label>Adjustment amount</label><input type="text" value="₹<?= htmlspecialchars(number_format($selectedAdjustmentAmount, 2), ENT_QUOTES) ?> (<?= htmlspecialchars(number_format($selectedAdjustmentPercent, 2), ENT_QUOTES) ?>%)" readonly></div><div class="full-span"><label>Adjustment reason</label><textarea name="adjustment_reason" rows="2" <?= $isDraft ? '' : 'readonly' ?>><?= htmlspecialchars($selectedAdjustmentReason, ENT_QUOTES) ?></textarea></div><div><label>Capacity (kWp)</label><input type="text" name="capacity_kwp" value="<?= htmlspecialchars((string)($doc['capacity_kwp'] ?? ''), ENT_QUOTES) ?>" <?= $isDraft ? '' : 'readonly' ?>></div><div><label>Pricing mode</label><input type="text" name="pricing_mode" value="<?= htmlspecialchars((string)($doc['pricing_mode'] ?? ''), ENT_QUOTES) ?>" <?= $isDraft ? '' : 'readonly' ?>></div><div><label><?=documents_invoice_is_standalone($doc)?'Standalone reference':(documents_invoice_has_quotation_reference($doc)?'Quotation ID':'Legacy Project ID')?></label><input type="text" value="<?= htmlspecialchars(documents_invoice_has_quotation_reference($doc)?(string)($doc['linked_quote_id'] ?? $doc['quotation_id'] ?? ''):(string)($doc['commercial_ref']['id']??''), ENT_QUOTES) ?>" readonly></div></div></div>
<div class="form-section-card"><h3>Customer snapshot</h3><p class="muted-helper">Search existing Customer Users by name or mobile; selecting one fills available customer details.</p><div class="form-grid"><div><label>Name</label><input type="text" name="customer_name" id="invoiceCustomerSearch" list="invoiceCustomerOptions" autocomplete="off" value="<?= htmlspecialchars((string)($selectedSnap['name'] ?? ''), ENT_QUOTES) ?>" <?= $isDraft ? '' : 'readonly' ?>></div><div><label>Mobile</label><input type="text" name="customer_mobile" value="<?= htmlspecialchars((string)($doc['customer_mobile'] ?? $selectedSnap['mobile'] ?? ''), ENT_QUOTES) ?>" <?= $isDraft ? '' : 'readonly' ?>></div><div class="full-span"><label>Address</label><textarea name="customer_address" rows="3" <?= $isDraft ? '' : 'readonly' ?>><?= htmlspecialchars((string)($selectedSnap['address'] ?? ''), ENT_QUOTES) ?></textarea></div><?php if(documents_invoice_is_standalone($doc)): foreach([['City','city'],['District','district'],['State','state'],['PIN','pin_code'],['Meter number','meter_number'],['Meter serial number','meter_serial_number'],['Consumer / account number','jbvnl_account_number'],['Application ID','application_id']] as [$label,$field]): ?><div><label><?=htmlspecialchars($label,ENT_QUOTES)?></label><input name="customer_<?=$field?>" value="<?=htmlspecialchars((string)($selectedSnap[$field]??''),ENT_QUOTES)?>" <?= $isDraft?'':'readonly' ?>></div><?php endforeach; endif; ?></div></div>
<?php if(documents_invoice_is_standalone($doc)): ?>
<div class="form-section-card"><h3>Customer login</h3><p><strong><?= !empty($doc['customer_ref']) ? 'Linked to Customer User' : 'Not linked' ?></strong></p><p class="muted-helper">Matching is re-run from the normalized mobile whenever this draft is saved. The invoice snapshot does not update the Customer User.</p></div>
<div class="form-section-card"><h3>Manual quotation / external reference</h3><div class="form-grid"><div><label>Quotation / reference number</label><input name="manual_quotation_no" value="<?=htmlspecialchars((string)($doc['manual_reference']['quotation_no']??''),ENT_QUOTES)?>" <?= $isDraft?'':'readonly' ?>></div><div><label>Reference date</label><input type="date" name="manual_quotation_date" value="<?=htmlspecialchars((string)($doc['manual_reference']['quotation_date']??''),ENT_QUOTES)?>" <?= $isDraft?'':'readonly' ?>></div><div><label>External reference</label><input name="external_reference" value="<?=htmlspecialchars((string)($doc['manual_reference']['external_reference']??''),ENT_QUOTES)?>" <?= $isDraft?'':'readonly' ?>></div><div><label>Reference amount (optional)</label><input type="number" step="0.01" min="0" name="manual_quotation_amount" value="<?=htmlspecialchars((string)($doc['manual_reference']['quotation_amount']??''),ENT_QUOTES)?>" <?= $isDraft?'':'readonly' ?>></div></div><p class="muted-helper">Display metadata only; this never creates or links a quotation.</p></div>
<div class="form-section-card"><h3>Site and system details</h3><div class="form-grid"><div><label>System Type</label><select name="system_type" id="invoiceSystemType"><?php foreach(['ongrid'=>'Ongrid','hybrid'=>'Hybrid','offgrid'=>'Offgrid','product'=>'Product'] as $v=>$l):?><option value="<?=$v?>" <?=documents_quote_normalize_system_type((string)($doc['system_type']??'ongrid'))===$v?'selected':''?>><?=$l?></option><?php endforeach;?></select></div><div><label>Rate Chart Model</label><select name="selected_model_number" id="invoiceRateModel"><option value="">-- select model --</option></select></div><div><label>Main Solar Size / DCR (kWp)</label><input type="number" min="0" step="0.01" name="main_solar_kwp" value="<?=htmlspecialchars((string)($doc['main_solar_kwp']??''),ENT_QUOTES)?>"></div><div><label>Complimentary Non-DCR (kWp)</label><input type="number" min="0" step="0.01" name="complimentary_non_dcr_kwp" value="<?=htmlspecialchars((string)($doc['complimentary_non_dcr_kwp']??''),ENT_QUOTES)?>"></div><div><label>Hybrid inverter (kVA)</label><input type="number" min="0" step="0.1" name="hybrid_inverter_kva" value="<?=htmlspecialchars((string)($doc['rate_chart_snapshot']['hybrid_inverter_kva']??''),ENT_QUOTES)?>"></div><div><label>Hybrid phase</label><select name="hybrid_phase"><option value="">--</option><option value="1" <?=($doc['rate_chart_snapshot']['hybrid_phase']??'')==='1'?'selected':''?>>1 Phase</option><option value="3" <?=($doc['rate_chart_snapshot']['hybrid_phase']??'')==='3'?'selected':''?>>3 Phase</option></select></div><div><label>Hybrid battery count</label><input type="number" min="0" name="hybrid_battery_count" value="<?=htmlspecialchars((string)($doc['rate_chart_snapshot']['hybrid_battery_count']??''),ENT_QUOTES)?>"></div><div><label>Place of Supply State</label><input name="place_of_supply_state" value="<?=htmlspecialchars((string)($doc['place_of_supply_state']??$selectedSnap['state']??''),ENT_QUOTES)?>"></div><div class="full-span"><label>Site Address</label><textarea name="site_address"><?=htmlspecialchars((string)($doc['site_address']??$selectedSnap['address']??''),ENT_QUOTES)?></textarea></div><?php foreach([['Application submitted date','application_submitted_date'],['Sanction load (kWp)','sanction_load_kwp'],['Installed PV capacity (kWp)','installed_pv_module_capacity_kwp'],['Circle','circle_name'],['Division','division_name'],['Sub Division','sub_division_name'],['Project summary','project_summary_line']] as [$l,$f]):?><div><label><?=htmlspecialchars($l,ENT_QUOTES)?></label><input name="<?=$f?>" value="<?=htmlspecialchars((string)($doc[$f]??''),ENT_QUOTES)?>"></div><?php endforeach;?></div></div>
<div class="form-section-card"><h3>Invoice items — Quotation Items Master</h3><p class="muted-helper">Kits, components, variants, HSN, descriptions, units and tax profiles are resolved from the same active master records as quotation creation. Enter only quantity, invoice price and an optional invoice-specific description.</p><div><label>Tax Profile</label><select name="tax_profile_id"><option value="">Kit/default profile</option><?php foreach($invoiceTaxProfiles as $p):?><option value="<?=htmlspecialchars((string)$p['id'],ENT_QUOTES)?>" <?=($doc['tax_profile_id']??'')===$p['id']?'selected':''?>><?=htmlspecialchars((string)$p['name'],ENT_QUOTES)?></option><?php endforeach;?></select></div><div class="responsive-table"><table id="invoiceMasterItems"><thead><tr><th>Type</th><th>Kit</th><th>Component</th><th>Variant</th><th>Qty</th><th>Unit price incl GST</th><th>Invoice-specific description</th><th></th></tr></thead><tbody><?php $masterLines=(array)($doc['quote_items']??[]);foreach($masterLines as $i=>$line):?><tr><?php $type=($line['type']??'component')==='kit'?'kit':'component';?><td><select name="items[<?=$i?>][type]" class="invoice-item-type"><option value="kit" <?=$type==='kit'?'selected':''?>>Kit</option><option value="component" <?=$type==='component'?'selected':''?>>Component</option></select></td><td><select name="items[<?=$i?>][kit_id]" class="invoice-item-kit"><option value="">-- kit --</option><?php foreach($invoiceKits as $k):?><option value="<?=htmlspecialchars((string)$k['id'],ENT_QUOTES)?>" <?=($line['kit_id']??'')===$k['id']?'selected':''?>><?=htmlspecialchars((string)$k['name'],ENT_QUOTES)?></option><?php endforeach;?></select></td><td><select name="items[<?=$i?>][component_id]" class="invoice-item-component"><option value="">-- component --</option><?php foreach($invoiceComponents as $c):?><option value="<?=htmlspecialchars((string)$c['id'],ENT_QUOTES)?>" <?=($line['component_id']??'')===$c['id']?'selected':''?>><?=htmlspecialchars((string)$c['name'],ENT_QUOTES)?></option><?php endforeach;?></select></td><td><select name="items[<?=$i?>][variant_id]" class="invoice-item-variant"><option value="">-- none --</option><?php foreach(($invoiceVariantsByComponent[(string)($line['component_id']??'')]??[]) as $v):?><option value="<?=htmlspecialchars((string)$v['id'],ENT_QUOTES)?>" <?=($line['variant_id']??'')===$v['id']?'selected':''?>><?=htmlspecialchars((string)$v['display_name'],ENT_QUOTES)?></option><?php endforeach;?></select></td><td><input type="number" min="0" step="0.01" name="items[<?=$i?>][quantity]" value="<?=htmlspecialchars((string)($line['qty']??''),ENT_QUOTES)?>"></td><td><input type="number" min="0" step="0.01" name="items[<?=$i?>][unit_price_incl_gst]" value="<?=htmlspecialchars((string)($doc['commercial_items'][$i]['unit_price_incl_gst']??''),ENT_QUOTES)?>"></td><td><textarea name="items[<?=$i?>][custom_description]"><?=htmlspecialchars((string)($line['custom_description']??''),ENT_QUOTES)?></textarea></td><td><button type="button" class="btn secondary invoice-remove-item">Remove</button></td></tr><?php endforeach;?></tbody></table></div><button type="button" class="btn secondary" id="invoiceAddItem">Add Item</button></div>
<?php endif; ?>
<div class="form-section-card"><h3>Delivery references</h3><p class="muted-helper">These references are informational only. An invoice can be created and remain linked to the quotation/customer even when no challan exists.</p><div class="responsive-table"><table><thead><tr><th>Challan</th><th>Date</th><th>Dispatch advice</th><th>Status</th></tr></thead><tbody><?php $deliveryRows=is_array($doc['delivery_details']??null)?$doc['delivery_details']:[]; foreach($deliveryRows as $delivery): ?><tr><td><?= htmlspecialchars((string)($delivery['challan_no'] ?? $delivery['challan_id'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)($delivery['delivery_date'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)($delivery['dispatch_advice_no'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)($delivery['delivery_status'] ?? ''), ENT_QUOTES) ?></td></tr><?php endforeach; if($deliveryRows===[]): ?><tr><td colspan="4" class="empty-state">No challan is linked. This does not block invoice work.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="form-section-card"><h3>Internal notes</h3><textarea name="internal_notes" rows="4" <?= $isDraft ? '' : 'readonly' ?>><?= htmlspecialchars((string)($doc['internal_notes'] ?? ''), ENT_QUOTES) ?></textarea></div>
<div class="sticky-action-footer"><a class="btn secondary" href="admin-invoices.php">Back to list</a><a class="btn secondary" href="invoice-view.php?id=<?= urlencode((string)$doc['id']) ?>" target="_blank" rel="noopener">Open/View Invoice</a><?php if ($isDraft): ?><button class="btn" type="submit">Save draft</button><button class="btn commercial-header__primary" type="submit" name="action" value="finalize_invoice">Finalize / Issue invoice (<?= htmlspecialchars($selectedInvoiceDate, ENT_QUOTES) ?>)</button><?php else: ?><span class="muted-helper">Issued/final invoices are read-only in this workspace.</span><?php endif; ?></div></form><?php if(documents_invoice_is_standalone($doc)): ?><div class="form-section-card"><h3>Add payment receipt</h3><form method="post"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars(csrf_token(),ENT_QUOTES)?>"><input type="hidden" name="action" value="add_standalone_payment"><input type="hidden" name="invoice_id" value="<?=htmlspecialchars((string)$doc['id'],ENT_QUOTES)?>"><div class="form-grid"><div><label>Amount</label><input type="number" step="0.01" min="0.01" name="amount" required></div><div><label>Date received</label><input type="date" name="date_received" value="<?=date('Y-m-d')?>" required></div><div><label>Payment mode</label><input name="mode"></div><div><label>Transaction / reference</label><input name="transaction_reference"></div><div><label>Receipt number (optional)</label><input name="receipt_number"></div><div class="full-span"><label>Note</label><textarea name="note"></textarea></div></div><button class="btn" type="submit">Add canonical payment receipt</button></form></div><?php endif; ?><div class="form-section-card"><h3>Lifecycle actions</h3><?php if (!$isDraft && documents_invoice_is_finalized($doc)): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>"><input type="hidden" name="action" value="start_invoice_revision"><input type="hidden" name="invoice_id" value="<?= htmlspecialchars((string)$doc['id'], ENT_QUOTES) ?>"><label>Revision reason (required)</label><textarea name="revision_reason" rows="2" required></textarea><button class="btn" type="submit">Revise finalized invoice</button></form><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>"><input type="hidden" name="action" value="cancel_invoice"><input type="hidden" name="invoice_id" value="<?= htmlspecialchars((string)$doc['id'], ENT_QUOTES) ?>"><label>Cancellation reason (required)</label><textarea name="cancel_reason" rows="2" required></textarea><label class="checkbox-field"><input type="checkbox" name="confirm_cancel" value="1" required> I understand receipts are preserved and a replacement invoice is optional/manual.</label><button class="btn warn" type="submit">Cancel invoice</button></form></div></section>
<?php elseif ($id !== ''): ?><section class="card empty-state">Invoice not found.</section><?php endif; ?>
<?php if($isDebug && $doc!==null):?><details class="card advanced-fields" open><summary>Admin-only raw invoice debug record</summary><pre class="debug-record"><?=htmlspecialchars(json_encode($doc,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)?:'',ENT_QUOTES)?></pre></details><?php endif;?><datalist id="invoiceCustomerOptions"><?php foreach($invoiceCustomers as $c):?><option value="<?=htmlspecialchars((string)($c['name']??''),ENT_QUOTES)?>" label="<?=htmlspecialchars((string)($c['mobile']??''),ENT_QUOTES)?>"></option><option value="<?=htmlspecialchars((string)($c['mobile']??''),ENT_QUOTES)?>" label="<?=htmlspecialchars((string)($c['name']??''),ENT_QUOTES)?>"></option><?php endforeach;?></datalist>
<script>
const invoiceCustomers=<?=json_encode($invoiceCustomers,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>, invoiceVariants=<?=json_encode($invoiceVariantsByComponent,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>, invoiceRates=<?=json_encode($invoiceQuoteDefaults['rate_chart']??[],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const search=document.getElementById('invoiceCustomerSearch');if(search)search.addEventListener('change',()=>{const q=search.value.trim().toLowerCase(),c=invoiceCustomers.find(x=>String(x.name||'').toLowerCase()===q||String(x.mobile||'').replace(/\D/g,'')===q.replace(/\D/g,''));if(!c)return;const map={customer_name:'name',customer_mobile:'mobile',customer_address:'address',customer_city:'city',customer_district:'district',customer_state:'state',customer_pin_code:'pin_code',customer_meter_number:'meter_number',customer_meter_serial_number:'meter_serial_number',customer_jbvnl_account_number:'jbvnl_account_number',customer_application_id:'application_id'};Object.entries(map).forEach(([n,k])=>{const e=document.querySelector(`[name="${n}"]`);if(e&&c[k]!=null)e.value=c[k]});});
function fillModels(){const t=document.getElementById('invoiceSystemType'),m=document.getElementById('invoiceRateModel');if(!t||!m)return;const current=m.value||<?=json_encode((string)($doc['rate_chart_snapshot']['model_number']??''))?>,rows=invoiceRates[t.value==='hybrid'?'hybrid':'on_grid']||[];m.innerHTML='<option value="">-- select model --</option>'+rows.map(r=>`<option value="${String(r.model_number||'').replace(/"/g,'&quot;')}">${r.model_number||''}</option>`).join('');m.value=current;}document.getElementById('invoiceSystemType')?.addEventListener('change',fillModels);fillModels();
let itemIndex=document.querySelectorAll('#invoiceMasterItems tbody tr').length;document.getElementById('invoiceAddItem')?.addEventListener('click',()=>{const tr=document.createElement('tr'),i=itemIndex++;tr.innerHTML=`<td><select name="items[${i}][type]" class="invoice-item-type"><option value="kit">Kit</option><option value="component" selected>Component</option></select></td><td><select name="items[${i}][kit_id]" class="invoice-item-kit"><option value="">-- kit --</option><?php foreach($invoiceKits as $k):?><option value="<?=htmlspecialchars((string)$k['id'],ENT_QUOTES)?>"><?=htmlspecialchars((string)$k['name'],ENT_QUOTES)?></option><?php endforeach;?></select></td><td><select name="items[${i}][component_id]" class="invoice-item-component"><option value="">-- component --</option><?php foreach($invoiceComponents as $c):?><option value="<?=htmlspecialchars((string)$c['id'],ENT_QUOTES)?>"><?=htmlspecialchars((string)$c['name'],ENT_QUOTES)?></option><?php endforeach;?></select></td><td><select name="items[${i}][variant_id]" class="invoice-item-variant"><option value="">-- none --</option></select></td><td><input type="number" min="0" step="0.01" name="items[${i}][quantity]" value="1"></td><td><input type="number" min="0" step="0.01" name="items[${i}][unit_price_incl_gst]"></td><td><textarea name="items[${i}][custom_description]"></textarea></td><td><button type="button" class="btn secondary invoice-remove-item">Remove</button></td>`;document.querySelector('#invoiceMasterItems tbody').append(tr)});document.addEventListener('click',e=>{if(e.target.matches('.invoice-remove-item'))e.target.closest('tr').remove()});document.addEventListener('change',e=>{if(!e.target.matches('.invoice-item-component'))return;const v=e.target.closest('tr').querySelector('.invoice-item-variant'),rows=invoiceVariants[e.target.value]||[];v.innerHTML='<option value="">-- none --</option>'+rows.map(x=>`<option value="${x.id}">${x.display_name}</option>`).join('')});
</script></main></body></html>
