<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

documents_ensure_structure();
$employeeStore = new EmployeeFsStore();

$viewerType = '';
$viewerId = '';
$user = current_user();
if (is_array($user) && (($user['role_name'] ?? '') === 'admin')) {
    $viewerType = 'admin';
    $viewerId = (string) ($user['id'] ?? '');
} else {
    $employee = employee_portal_current_employee($employeeStore);
    if ($employee !== null) {
        $viewerType = 'employee';
        $viewerId = (string) ($employee['id'] ?? '');
    }
}
if ($viewerType === '') {
    header('Location: login.php');
    exit;
}

$id = safe_text($_GET['id'] ?? '');
$quote = documents_get_quote($id);
if ($quote === null) {
    http_response_code(404);
    echo 'Quotation not found.';
    exit;
}
if ($viewerType === 'employee' && ((string) ($quote['created_by_type'] ?? '') !== 'employee' || (string) ($quote['created_by_id'] ?? '') !== $viewerId)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        header('Location: quotation-view.php?id=' . urlencode($id) . '&err=csrf');
        exit;
    }

    $action = safe_text($_POST['action'] ?? '');
    if ($action === 'approve_quote' && $viewerType === 'admin') {
        $admin = current_user();
        $quote['approval']['approved_flag'] = true;
        $quote['approval']['approved_by'] = (string) ($admin['full_name'] ?? 'Admin');
        $quote['approval']['approved_at'] = date('c');
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        header('Location: quotation-view.php?id=' . urlencode($id) . '&ok=1');
        exit;
    }
    if ($action === 'convert_proforma' && $viewerType === 'admin') {
        $existingId = safe_text($quote['links']['proforma_id'] ?? '');
        if ($existingId !== '' && documents_get_proforma($existingId) !== null) {
            header('Location: proforma-view.php?id=' . urlencode($existingId));
            exit;
        }

        $num = documents_generate_document_number('proforma', (string) ($quote['segment'] ?? 'RES'));
        if (!$num['ok']) {
            header('Location: quotation-view.php?id=' . urlencode($id) . '&err=proforma_numbering');
            exit;
        }
        $admin = current_user();
        $proforma = documents_proforma_defaults();
        $proforma['id'] = 'pi_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $proforma['proforma_no'] = (string) ($num['number'] ?? '');
        $proforma['source_quote_id'] = (string) ($quote['id'] ?? '');
        $proforma['source_quote_no'] = (string) ($quote['quote_no'] ?? '');
        $proforma['template_set_id'] = (string) ($quote['template_set_id'] ?? '');
        $proforma['segment'] = (string) ($quote['segment'] ?? 'RES');
        $proforma['created_by_id'] = (string) ($admin['id'] ?? '');
        $proforma['created_by_name'] = (string) ($admin['full_name'] ?? 'Admin');
        $proforma['created_at'] = date('c');
        $proforma['updated_at'] = date('c');
        foreach (['customer_mobile','customer_name','billing_address','site_address','district','city','state','pin','system_type','capacity_kwp','pricing_mode','tax_type','input_total_gst_inclusive','calc','rendering'] as $field) {
            $proforma[$field] = $quote[$field] ?? $proforma[$field];
        }
        $proforma['annexures_snapshot'] = is_array($quote['annexures_overrides'] ?? null) ? $quote['annexures_overrides'] : documents_template_block_defaults();
        documents_save_proforma($proforma);

        $quote['links']['proforma_id'] = (string) $proforma['id'];
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        header('Location: proforma-view.php?id=' . urlencode((string) $proforma['id']) . '&ok=1');
        exit;
    }
    if ($action === 'generate_agreement' && $viewerType === 'admin') {
        $existingId = safe_text($quote['links']['agreement_id'] ?? '');
        if ($existingId !== '' && documents_get_agreement($existingId) !== null) {
            header('Location: agreement-view.php?id=' . urlencode($existingId));
            exit;
        }

        $num = documents_generate_document_number('agreement', (string) ($quote['segment'] ?? 'RES'));
        if (!$num['ok']) {
            header('Location: quotation-view.php?id=' . urlencode($id) . '&err=agreement_numbering');
            exit;
        }
        $admin = current_user();
        $agreement = documents_agreement_defaults();
        $agreement['id'] = 'agr_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $agreement['agreement_no'] = (string) ($num['number'] ?? '');
        $agreement['source_quote_id'] = (string) ($quote['id'] ?? '');
        $agreement['source_quote_no'] = (string) ($quote['quote_no'] ?? '');
        $agreement['source_proforma_id'] = safe_text($quote['links']['proforma_id'] ?? '');
        $proforma = $agreement['source_proforma_id'] !== '' ? documents_get_proforma($agreement['source_proforma_id']) : null;
        $agreement['source_proforma_no'] = is_array($proforma) ? (string) ($proforma['proforma_no'] ?? '') : '';
        $agreement['template_set_id'] = (string) ($quote['template_set_id'] ?? '');
        $agreement['segment'] = (string) ($quote['segment'] ?? 'RES');
        $agreement['created_by_id'] = (string) ($admin['id'] ?? '');
        $agreement['created_by_name'] = (string) ($admin['full_name'] ?? 'Admin');
        $agreement['created_at'] = date('c');
        $agreement['updated_at'] = date('c');
        foreach (['customer_mobile','customer_name','district','state','pin','system_type','capacity_kwp','rendering'] as $field) {
            $agreement[$field] = $quote[$field] ?? $agreement[$field];
        }
        $agreement['address'] = (string) ($quote['billing_address'] ?? '');
        $blocks = documents_get_template_blocks();
        $entry = $blocks[(string) ($quote['template_set_id'] ?? '')] ?? [];
        $rawBlocks = is_array($entry['blocks'] ?? null) ? $entry['blocks'] : (is_array($entry) ? $entry : []);
        $agreement['agreement_text'] = (string) ($rawBlocks['vendor_consumer_agreement'] ?? '');
        documents_save_agreement($agreement);

        $quote['links']['agreement_id'] = (string) $agreement['id'];
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        header('Location: agreement-view.php?id=' . urlencode((string) $agreement['id']) . '&ok=1');
        exit;
    }
    if ($action === 'archive_quote') {
        $quote['status'] = 'Archived';
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        header('Location: quotation-view.php?id=' . urlencode($id) . '&ok=1');
        exit;
    }
    if ($action === 'mark_final') {
        $quote['status'] = 'Final';
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        header('Location: quotation-view.php?id=' . urlencode($id) . '&ok=1');
        exit;
    }
}

$editable = ($quote['status'] ?? 'Draft') === 'Draft';
$editLink = ($viewerType === 'admin' ? 'admin-quotations.php' : 'employee-quotations.php') . '?edit=' . urlencode((string) $quote['id']);
$backLink = $viewerType === 'admin' ? 'admin-quotations.php' : 'employee-quotations.php';
$ok = isset($_GET['ok']);
$pdfError = safe_text($_GET['err'] ?? '') === 'pdf_failed';
$conversionError = safe_text($_GET['err'] ?? '');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quotation <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></title>
<style>body{font-family:Arial,sans-serif;background:#f4f6fa;margin:0}.wrap{padding:16px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe1ea;padding:8px;text-align:left;font-size:13px}h3{margin:8px 0}.btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;border:none;border-radius:8px;padding:8px 12px;cursor:pointer}.btn.secondary{background:#fff;color:#1f2937;border:1px solid #cbd5e1}</style></head>
<body><main class="wrap">
<?php if ($ok): ?><div class="card" style="background:#ecfdf5">Saved successfully.</div><?php endif; ?>
<?php if ($pdfError): ?><div class="card" style="background:#fef2f2;color:#991b1b">PDF generation failed. Please retry or contact admin.</div><?php endif; ?>
<?php if ($conversionError === 'proforma_numbering' || $conversionError === 'agreement_numbering'): ?><div class="card" style="background:#fef2f2;color:#991b1b">Numbering rule missing for <?= $conversionError === 'proforma_numbering' ? 'proforma' : 'agreement' ?>. Configure in Admin Documents.</div><?php endif; ?>
<div class="card"><h1>Quotation View</h1>
<a class="btn secondary" href="<?= htmlspecialchars($backLink, ENT_QUOTES) ?>">Back</a>
<a class="btn" href="quotation-print.php?id=<?= urlencode((string)$quote['id']) ?>" target="_blank">Print</a>
<a class="btn" href="quotation-pdf.php?id=<?= urlencode((string)$quote['id']) ?>">Download PDF</a>
<?php if ($editable): ?><a class="btn secondary" href="<?= htmlspecialchars($editLink, ENT_QUOTES) ?>">Edit</a><?php endif; ?>
<?php if ($viewerType === 'admin'): ?>
    <form method="post" style="display:inline-block"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="approve_quote"><button class="btn secondary" type="submit">Approve Quotation</button></form>
    <form method="post" style="display:inline-block"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="convert_proforma"><button class="btn" type="submit">Convert Quotation → Proforma Invoice</button></form>
    <form method="post" style="display:inline-block"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="generate_agreement"><button class="btn" type="submit">Generate Vendor–Consumer Agreement</button></form>
<?php endif; ?>
</div>
<div class="card"><table><tr><th>Quote No</th><td><?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></td><th>Status</th><td><?= htmlspecialchars((string)$quote['status'], ENT_QUOTES) ?></td></tr><tr><th>Created By</th><td><?= htmlspecialchars((string)$quote['created_by_name'], ENT_QUOTES) ?> (<?= htmlspecialchars((string)$quote['created_by_type'], ENT_QUOTES) ?>)</td><th>Valid Until</th><td><?= htmlspecialchars((string)$quote['valid_until'], ENT_QUOTES) ?></td></tr><tr><th>Created At</th><td><?= htmlspecialchars((string)$quote['created_at'], ENT_QUOTES) ?></td><th>Updated At</th><td><?= htmlspecialchars((string)$quote['updated_at'], ENT_QUOTES) ?></td></tr></table></div>
<div class="card"><table><tr><th>Approval</th><td><?= !empty($quote['approval']['approved_flag']) ? 'Approved' : 'Pending' ?></td><th>Approved By</th><td><?= htmlspecialchars((string)($quote['approval']['approved_by'] ?? ''), ENT_QUOTES) ?></td></tr><tr><th>Approved At</th><td><?= htmlspecialchars((string)($quote['approval']['approved_at'] ?? ''), ENT_QUOTES) ?></td><th>Linked Proforma</th><td><?php if((string)($quote['links']['proforma_id'] ?? '')!==''): ?><a href="proforma-view.php?id=<?= urlencode((string)$quote['links']['proforma_id']) ?>">Open Proforma</a><?php else: ?>—<?php endif; ?></td></tr><tr><th>Linked Agreement</th><td colspan="3"><?php if((string)($quote['links']['agreement_id'] ?? '')!==''): ?><a href="agreement-view.php?id=<?= urlencode((string)$quote['links']['agreement_id']) ?>">Open Agreement</a><?php else: ?>—<?php endif; ?></td></tr></table></div>
<div class="card"><h3>Customer</h3><p><strong><?= htmlspecialchars((string)$quote['customer_name'], ENT_QUOTES) ?></strong> (<?= htmlspecialchars((string)$quote['customer_mobile'], ENT_QUOTES) ?>)</p><p><?= nl2br(htmlspecialchars((string)$quote['billing_address'], ENT_QUOTES)) ?></p><p><?= htmlspecialchars((string)$quote['district'], ENT_QUOTES) ?>, <?= htmlspecialchars((string)$quote['city'], ENT_QUOTES) ?>, <?= htmlspecialchars((string)$quote['state'], ENT_QUOTES) ?> - <?= htmlspecialchars((string)$quote['pin'], ENT_QUOTES) ?></p></div>
<div class="card"><h3>System</h3><p><?= htmlspecialchars((string)$quote['system_type'], ENT_QUOTES) ?> | <?= htmlspecialchars((string)$quote['capacity_kwp'], ENT_QUOTES) ?> kWp</p><p><?= htmlspecialchars((string)$quote['project_summary_line'], ENT_QUOTES) ?></p></div>
<div class="card"><h3>Pricing Summary</h3>
<table><thead><tr><th>Description</th><th>Basic</th><th>GST</th><th>Total</th></tr></thead><tbody>
<tr><td>Solar Power Generation System (5%)</td><td><?= number_format((float)$quote['calc']['bucket_5_basic'],2) ?></td><td><?= number_format((float)$quote['calc']['bucket_5_gst'],2) ?></td><td><?= number_format((float)$quote['calc']['bucket_5_basic'] + (float)$quote['calc']['bucket_5_gst'],2) ?></td></tr>
<tr><td>Solar Power Generation System (18%)</td><td><?= number_format((float)$quote['calc']['bucket_18_basic'],2) ?></td><td><?= number_format((float)$quote['calc']['bucket_18_gst'],2) ?></td><td><?= number_format((float)$quote['calc']['bucket_18_basic'] + (float)$quote['calc']['bucket_18_gst'],2) ?></td></tr>
<tr><th colspan="3">Grand Total</th><th><?= number_format((float)$quote['calc']['grand_total'],2) ?></th></tr>
</tbody></table></div>
<div class="card"><h3>Special Requests From Customer (Inclusive in the rate)</h3><p><?= nl2br(htmlspecialchars((string)$quote['special_requests_inclusive'], ENT_QUOTES)) ?></p><p><em>In case of conflict between Annexure inclusions and Special Requests, Special Requests will be given priority.</em></p></div>
<div class="card"><h3>Annexures</h3>
<?php foreach (['system_inclusions'=>'System Inclusions','payment_terms'=>'Payment Terms','warranty'=>'Warranty','transportation'=>'Transportation','system_type_explainer'=>'System Type Explainer','terms_conditions'=>'Terms & Conditions'] as $k=>$label): ?><h4><?= $label ?></h4><p><?= nl2br(htmlspecialchars((string)($quote['annexures_overrides'][$k] ?? ''), ENT_QUOTES)) ?></p><?php endforeach; ?>
</div>
<div class="card">
<form method="post" style="display:inline-block"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="mark_final"><button class="btn" type="submit">Mark Final</button></form>
<form method="post" style="display:inline-block"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="archive_quote"><button class="btn secondary" type="submit">Archive</button></form>
</div>
</main></body></html>
