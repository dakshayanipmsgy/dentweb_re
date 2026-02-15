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

$redirect = static function (string $id, string $type, string $message): void {
    header('Location: quotation-view.php?' . http_build_query(['id' => $id, 'status' => $type, 'message' => $message]));
    exit;
};

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
        $redirect($id, 'error', 'Security validation failed.');
    }

    $action = safe_text($_POST['action'] ?? '');
    if ($viewerType !== 'admin' && in_array($action, ['approve_quote', 'accept_quote'], true)) {
        $redirect($id, 'error', 'Only admin can perform this action.');
    }

    if ($action === 'archive_quote') {
        $quote['status'] = 'Archived';
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        $redirect($id, 'success', 'Quotation archived.');
    }

    if ($action === 'approve_quote') {
        $status = (string) ($quote['status'] ?? 'Draft');
        if (in_array($status, ['Approved', 'Accepted'], true)) {
            $redirect($id, 'success', 'Quotation already approved.');
        }

        $quote['status'] = 'Approved';
        $quote['approval']['approved_by_id'] = (string) ($user['id'] ?? '');
        $quote['approval']['approved_by_name'] = (string) ($user['full_name'] ?? 'Admin');
        $quote['approval']['approved_at'] = date('c');
        $quote['updated_at'] = date('c');
        $saved = documents_save_quote($quote);
        if (!$saved['ok']) {
            documents_log('file save failed during approve quote ' . (string) ($quote['id'] ?? ''));
            $redirect($id, 'error', 'Failed to approve quotation.');
        }
        $redirect($id, 'success', 'Quotation approved.');
    }


    if ($action === 'save_financial_inputs') {
        if (!$editable) {
            $redirect($id, 'error', 'This quotation is not editable.');
        }

        $estimatedMonthlyBill = (string) safe_text($_POST['estimated_monthly_bill_rs'] ?? '');
        $subsidyExpectedInput = (string) safe_text($_POST['subsidy_expected_rs'] ?? ($_POST['subsidy_amount_rs'] ?? ''));
        $subsidyExpected = (float) ($subsidyExpectedInput !== '' ? $subsidyExpectedInput : ($quote['subsidy_amount_rs'] ?? ($quote['financial_inputs']['subsidy_expected_rs'] ?? 0)));

        $quote['financial_inputs']['estimated_monthly_bill_rs'] = $estimatedMonthlyBill;
        $quote['financial_inputs']['subsidy_expected_rs'] = $subsidyExpected > 0 ? (string) round($subsidyExpected, 2) : '';
        $quote['financial_inputs']['unit_rate_rs_per_kwh'] = (string) safe_text($_POST['unit_rate_rs_per_kwh'] ?? '');
        $quote['financial_inputs']['interest_rate_percent'] = (string) safe_text($_POST['interest_rate_percent'] ?? '');
        $quote['financial_inputs']['loan_tenure_years'] = (string) safe_text($_POST['loan_tenure_years'] ?? '');
        $quote['financial_inputs']['annual_generation_per_kw'] = (string) safe_text($_POST['annual_generation_per_kw'] ?? '');
        $quote['financial_inputs']['min_monthly_bill_after_solar_rs'] = (string) safe_text($_POST['min_monthly_bill_after_solar_rs'] ?? '300');
        $analysisMode = safe_text($_POST['analysis_mode'] ?? 'simple_monthly');
        $quote['financial_inputs']['analysis_mode'] = in_array($analysisMode, ['simple_monthly', 'advanced_10y_monthly'], true) ? $analysisMode : 'simple_monthly';
        $yearsForCumulative = (int) ($_POST['years_for_cumulative_chart'] ?? 25);
        $quote['financial_inputs']['years_for_cumulative_chart'] = $yearsForCumulative > 0 ? $yearsForCumulative : 25;
        $quote['subsidy_expected_rs'] = $subsidyExpected > 0 ? round($subsidyExpected, 2) : 0;
        $quote['updated_at'] = date('c');

        $saved = documents_save_quote($quote);
        if (!($saved['ok'] ?? false)) {
            $redirect($id, 'error', 'Failed to save financial inputs.');
        }

        $redirect($id, 'success', 'Financial inputs saved.');
    }

    if ($action === 'accept_quote') {
        if ((string) ($quote['status'] ?? '') !== 'Approved' && (string) ($quote['status'] ?? '') !== 'Accepted') {
            $redirect($id, 'error', 'Quotation must be Approved before acceptance.');
        }

        $valid = documents_quote_has_valid_acceptance_data($quote);
        if (!($valid['ok'] ?? false)) {
            $redirect($id, 'error', (string) ($valid['error'] ?? 'Validation failed.'));
        }

        $customer = documents_upsert_customer_from_quote($quote);
        if (!($customer['ok'] ?? false)) {
            $redirect($id, 'error', (string) ($customer['error'] ?? 'Customer creation failed.'));
        }

        $agreement = documents_create_agreement_from_quote($quote, is_array($user) ? $user : []);
        if (!($agreement['ok'] ?? false)) {
            $redirect($id, 'error', (string) ($agreement['error'] ?? 'Agreement creation failed.'));
        }

        $proforma = documents_create_proforma_from_quote($quote);
        if (!($proforma['ok'] ?? false)) {
            $redirect($id, 'error', (string) ($proforma['error'] ?? 'Proforma creation failed.'));
        }

        $invoice = documents_create_invoice_from_quote($quote);
        if (!($invoice['ok'] ?? false)) {
            $redirect($id, 'error', (string) ($invoice['error'] ?? 'Invoice creation failed.'));
        }

        $snapshot = documents_quote_resolve_snapshot($quote);
        $quote['links']['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
        $quote['links']['agreement_id'] = (string) ($agreement['agreement_id'] ?? ($quote['links']['agreement_id'] ?? ''));
        $quote['links']['proforma_id'] = (string) ($proforma['proforma_id'] ?? ($quote['links']['proforma_id'] ?? ''));
        $quote['links']['invoice_id'] = (string) ($invoice['invoice_id'] ?? ($quote['links']['invoice_id'] ?? ''));
        $quote['status'] = 'Accepted';
        $quote['acceptance']['accepted_by_admin_id'] = (string) ($user['id'] ?? '');
        $quote['acceptance']['accepted_by_admin_name'] = (string) ($user['full_name'] ?? 'Admin');
        $quote['acceptance']['accepted_at'] = date('c');
        $quote['acceptance']['accepted_note'] = safe_text($_POST['accepted_note'] ?? (string) ($quote['acceptance']['accepted_note'] ?? ''));
        $quote['updated_at'] = date('c');
        $saved = documents_save_quote($quote);
        if (!$saved['ok']) {
            documents_log('file save failed during accept quote ' . (string) ($quote['id'] ?? ''));
            $redirect($id, 'error', 'Generated documents but failed to update quotation.');
        }

        $redirect($id, 'success', 'Quotation accepted and linked drafts generated.');
    }
}

$editable = documents_quote_can_edit($quote, $viewerType, $viewerId);
$editLink = ($viewerType === 'admin' ? 'admin-quotations.php' : 'employee-quotations.php') . '?edit=' . urlencode((string) $quote['id']);
$backLink = $viewerType === 'admin' ? 'admin-quotations.php' : 'employee-quotations.php';
$statusMsg = safe_text($_GET['status'] ?? '');
$message = safe_text($_GET['message'] ?? '');
$snapshot = documents_quote_resolve_snapshot($quote);
$links = is_array($quote['links'] ?? null) ? $quote['links'] : [];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quotation <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></title>
<style>body{font-family:Arial,sans-serif;background:#f4f6fa;margin:0}.wrap{padding:16px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe1ea;padding:8px;text-align:left;font-size:13px}h3{margin:8px 0}.btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;border:none;border-radius:8px;padding:8px 12px;cursor:pointer}.btn.secondary{background:#fff;color:#1f2937;border:1px solid #cbd5e1}.ok{background:#ecfdf5}.err{background:#fef2f2}</style></head>
<body><main class="wrap">
<?php if ($message !== ''): ?><div class="card <?= $statusMsg === 'success' ? 'ok' : 'err' ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>
<div class="card"><h1>Quotation View</h1>
<a class="btn secondary" href="<?= htmlspecialchars($backLink, ENT_QUOTES) ?>">Back</a>
<a class="btn" href="quotation-print.php?id=<?= urlencode((string)$quote['id']) ?>" target="_blank">Print</a>

<?php if ($editable): ?><a class="btn secondary" href="<?= htmlspecialchars($editLink, ENT_QUOTES) ?>">Edit</a><?php endif; ?>
</div>
<div class="card"><table><tr><th>Quote No</th><td><?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></td><th>Status</th><td><?= htmlspecialchars(documents_status_label($quote, $viewerType), ENT_QUOTES) ?></td></tr><tr><th>Created By</th><td><?= htmlspecialchars((string)$quote['created_by_name'], ENT_QUOTES) ?> (<?= htmlspecialchars((string)$quote['created_by_type'], ENT_QUOTES) ?>)</td><th>Valid Until</th><td><?= htmlspecialchars((string)$quote['valid_until'], ENT_QUOTES) ?></td></tr><tr><th>Created At</th><td><?= htmlspecialchars((string)$quote['created_at'], ENT_QUOTES) ?></td><th>Updated At</th><td><?= htmlspecialchars((string)$quote['updated_at'], ENT_QUOTES) ?></td></tr></table></div>
<?php if ($viewerType === 'admin'): ?><div class="card"><h3>Admin Actions</h3>
<?php if ((string)($quote['status'] ?? '') === 'Draft'): ?><form method="post" style="display:inline-block"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="approve_quote"><button class="btn" type="submit">Approve Quotation</button></form><?php endif; ?>
<?php if ((string)($quote['status'] ?? '') === 'Approved' || (string)($quote['status'] ?? '') === 'Accepted'): ?><form method="post" style="display:inline-block"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="accept_quote"><input type="hidden" name="accepted_note" value=""><button class="btn" type="submit">Accepted by Customer</button></form><?php endif; ?>
</div><?php endif; ?>
<div class="card"><h3>Linked Documents</h3>
<p>Agreement: <?php if (safe_text((string)($links['agreement_id'] ?? '')) !== ''): ?><a href="agreement-view.php?id=<?= urlencode((string)$links['agreement_id']) ?>">View Agreement</a><?php else: ?>Not linked<?php endif; ?></p>
<p>Proforma: <?php if (safe_text((string)($links['proforma_id'] ?? '')) !== ''): ?><a href="admin-proformas.php?id=<?= urlencode((string)$links['proforma_id']) ?>">View Proforma</a><?php else: ?>Not linked<?php endif; ?></p>
<p>Invoice: <?php if (safe_text((string)($links['invoice_id'] ?? '')) !== ''): ?><a href="admin-invoices.php?id=<?= urlencode((string)$links['invoice_id']) ?>">View Invoice</a><?php else: ?>Not linked<?php endif; ?></p>
<p><a class="btn secondary" href="admin-challans.php?quote_id=<?= urlencode((string)$quote['id']) ?>">Create Delivery Challan</a></p>
</div>
<div class="card"><h3>Customer</h3><p><strong><?= htmlspecialchars((string)($snapshot['name'] ?: $quote['customer_name']), ENT_QUOTES) ?></strong> (<?= htmlspecialchars((string)($snapshot['mobile'] ?: $quote['customer_mobile']), ENT_QUOTES) ?>)</p><p><strong>Site Address:</strong><br><?= nl2br(htmlspecialchars((string)($quote['site_address'] ?: $snapshot['address']), ENT_QUOTES)) ?></p></div>
<div class="card"><h3>System</h3><p><?= htmlspecialchars((string)$quote['system_type'], ENT_QUOTES) ?> | <?= htmlspecialchars((string)$quote['capacity_kwp'], ENT_QUOTES) ?> kWp</p></div>

<div class="card"><h3>Savings & EMI Calculator Inputs (required for graphs)</h3>
<?php $financialInputs = is_array($quote['financial_inputs'] ?? null) ? $quote['financial_inputs'] : []; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="save_financial_inputs">
<table>
<tr><th>Estimated Monthly Electricity Bill (₹) *</th><td><input type="number" step="0.01" min="0" name="estimated_monthly_bill_rs" value="<?= htmlspecialchars((string)($financialInputs['estimated_monthly_bill_rs'] ?? ''), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></td></tr>
<tr><th>Unit Rate (₹/kWh)</th><td><input type="number" step="0.01" min="0" name="unit_rate_rs_per_kwh" value="<?= htmlspecialchars((string)($financialInputs['unit_rate_rs_per_kwh'] ?? ''), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></td></tr>
<tr><th>Interest Rate (%)</th><td><input type="number" step="0.01" min="0" name="interest_rate_percent" value="<?= htmlspecialchars((string)($financialInputs['interest_rate_percent'] ?? ''), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></td></tr>
<tr><th>Loan Tenure (years)</th><td><input type="number" min="1" name="loan_tenure_years" value="<?= htmlspecialchars((string)($financialInputs['loan_tenure_years'] ?? ''), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></td></tr>
<tr><th>Annual Generation per kW (kWh/year)</th><td><input type="number" step="0.01" min="0" name="annual_generation_per_kw" value="<?= htmlspecialchars((string)($financialInputs['annual_generation_per_kw'] ?? ''), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></td></tr>
<tr><th>Minimum Monthly Bill after Solar (₹)</th><td><input type="number" step="0.01" min="0" name="min_monthly_bill_after_solar_rs" value="<?= htmlspecialchars((string)($financialInputs['min_monthly_bill_after_solar_rs'] ?? 300), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></td></tr>
<tr><th>Mode</th><td><select name="analysis_mode" <?= $editable ? '' : 'disabled' ?>><option value="simple_monthly" <?= (($financialInputs['analysis_mode'] ?? 'simple_monthly') === 'simple_monthly') ? 'selected' : '' ?>>Simple (Typical month comparison)</option><option value="advanced_10y_monthly" <?= (($financialInputs['analysis_mode'] ?? '') === 'advanced_10y_monthly') ? 'selected' : '' ?>>Advanced (120 months view)</option></select></td></tr>
<tr><th>Years for cumulative chart</th><td><input type="number" min="1" max="40" name="years_for_cumulative_chart" value="<?= htmlspecialchars((string)($financialInputs['years_for_cumulative_chart'] ?? 25), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></td></tr>
<tr><th>Expected subsidy (₹)</th><td><input type="number" step="0.01" min="0" name="subsidy_expected_rs" value="<?= htmlspecialchars((string)($financialInputs['subsidy_expected_rs'] ?? ($quote['subsidy_expected_rs'] ?? '')), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></td></tr>
</table>
<?php if ($editable): ?><p><button class="btn" type="submit">Save Financial Inputs</button></p><?php else: ?><p>Financial inputs are locked after quotation leaves Draft status.</p><?php endif; ?>
</form></div>

<div class="card"><h3>Pricing Summary</h3><table><thead><tr><th>Description</th><th>Basic</th><th>GST</th><th>Total</th></tr></thead><tbody>
<tr><td>Solar Power Generation System (5%)</td><td><?= number_format((float)$quote['calc']['bucket_5_basic'],2) ?></td><td><?= number_format((float)$quote['calc']['bucket_5_gst'],2) ?></td><td><?= number_format((float)$quote['calc']['bucket_5_basic'] + (float)$quote['calc']['bucket_5_gst'],2) ?></td></tr>
<tr><td>Solar Power Generation System (18%)</td><td><?= number_format((float)$quote['calc']['bucket_18_basic'],2) ?></td><td><?= number_format((float)$quote['calc']['bucket_18_gst'],2) ?></td><td><?= number_format((float)$quote['calc']['bucket_18_basic'] + (float)$quote['calc']['bucket_18_gst'],2) ?></td></tr>
<tr><th colspan="3">Grand Total</th><th><?= number_format((float)$quote['calc']['grand_total'],2) ?></th></tr>
</tbody></table></div>
<?php if ($viewerType === 'admin'): ?><div class="card"><form method="post" style="display:inline-block"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="archive_quote"><button class="btn secondary" type="submit">Archive</button></form></div><?php endif; ?>
</main></body></html>
