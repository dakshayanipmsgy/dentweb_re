<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/includes/documents_quotations.php';

$employeeStore = new EmployeeFsStore();
$employee = null;
if (!empty($_SESSION['employee_logged_in'])) {
    $employee = employee_portal_current_employee($employeeStore);
}
$user = current_user();
$isAdmin = is_array($user) && (($user['role_name'] ?? '') === 'admin');
$isEmployeeUser = is_array($user) && (($user['role_name'] ?? '') === 'employee');
if (!$isAdmin && !$employee && !$isEmployeeUser) {
    header('Location: login.php');
    exit;
}

$id = safe_text($_GET['id'] ?? '');
$quote = documents_load_quote($id);
if (!$quote) {
    http_response_code(404);
    echo 'Quotation not found.';
    exit;
}

$canAccess = $isAdmin;
$actorEmployeeId = $employee['id'] ?? ($isEmployeeUser ? ($user['id'] ?? '') : '');
if (!$canAccess) {
    $canAccess = ((string) ($quote['created_by_type'] ?? '') === 'employee' && (string) ($quote['created_by_id'] ?? '') === (string) $actorEmployeeId);
}
if (!$canAccess) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$statusMsg = '';
$statusType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $statusMsg = 'Security validation failed.';
        $statusType = 'error';
    } else {
        $action = safe_text($_POST['action'] ?? '');
        if ($action === 'archive_quote') {
            $quote['status'] = 'Archived';
            $quote['updated_at'] = date('c');
            documents_save_quote($quote);
            $statusMsg = 'Quotation archived.';
        }
        if ($action === 'update_quote' && (string) ($quote['status'] ?? '') === 'Draft') {
            $quote['customer_name'] = safe_text($_POST['customer_name'] ?? $quote['customer_name'] ?? '');
            $quote['billing_address'] = safe_text($_POST['billing_address'] ?? $quote['billing_address'] ?? '');
            $quote['site_address'] = safe_text($_POST['site_address'] ?? $quote['site_address'] ?? '');
            $quote['capacity_kwp'] = safe_text($_POST['capacity_kwp'] ?? $quote['capacity_kwp'] ?? '');
            $quote['valid_until'] = safe_text($_POST['valid_until'] ?? $quote['valid_until'] ?? '');
            $quote['special_requests_inclusive'] = safe_text($_POST['special_requests_inclusive'] ?? $quote['special_requests_inclusive'] ?? '');
            $quote['input_total_gst_inclusive'] = documents_round2((float) ($_POST['input_total_gst_inclusive'] ?? $quote['input_total_gst_inclusive'] ?? 0));
            $quote['pricing_mode'] = in_array(safe_text($_POST['pricing_mode'] ?? ''), ['solar_split_70_30', 'flat_5'], true) ? safe_text($_POST['pricing_mode']) : (string) ($quote['pricing_mode'] ?? 'solar_split_70_30');
            $quote['tax_type'] = safe_text($_POST['tax_type'] ?? $quote['tax_type'] ?? 'CGST_SGST') === 'IGST' ? 'IGST' : 'CGST_SGST';
            $quote['calc'] = documents_calculate_quote((string) $quote['pricing_mode'], (float) $quote['input_total_gst_inclusive'], (string) $quote['tax_type']);
            foreach (['system_inclusions','payment_terms','warranty','system_type_explainer','transportation','terms_conditions'] as $k) {
                $quote['annexures_overrides'][$k] = safe_text($_POST['annex_' . $k] ?? $quote['annexures_overrides'][$k] ?? '');
            }
            $quote['updated_at'] = date('c');
            documents_save_quote($quote);
            $statusMsg = 'Quotation updated.';
        }
    }
}

$calc = is_array($quote['calc'] ?? null) ? $quote['calc'] : [];
$split = is_array($calc['gst_split'] ?? null) ? $calc['gst_split'] : [];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quotation View</title>
<style>body{font-family:Arial,sans-serif;background:#f5f7fb;margin:0}.wrap{padding:18px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}input,select,textarea{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}textarea{min-height:80px}.btn{background:#1d4ed8;color:#fff;border:none;padding:8px 12px;border-radius:8px;text-decoration:none;display:inline-block;cursor:pointer}.btn.warn{background:#b91c1c}.muted{color:#64748b;font-size:13px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe1ea;padding:8px;text-align:left}th{background:#f8fafc}</style>
</head><body><main class="wrap">
<div class="card"><h1><?= htmlspecialchars((string) ($quote['quote_no'] ?? ''), ENT_QUOTES) ?></h1><p>Status: <strong><?= htmlspecialchars((string) ($quote['status'] ?? ''), ENT_QUOTES) ?></strong> | Created by: <?= htmlspecialchars((string) ($quote['created_by_name'] ?? ''), ENT_QUOTES) ?></p>
<a class="btn" href="quotation-print.php?id=<?= urlencode((string) ($quote['id'] ?? '')) ?>" target="_blank">Print</a>
<?php if ((string) ($quote['status'] ?? '') === 'Draft'): ?><form method="post" style="display:inline-block"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>"><input type="hidden" name="action" value="archive_quote"><button class="btn warn" type="submit">Archive</button></form><?php endif; ?>
</div>
<?php if ($statusMsg !== ''): ?><div class="card" style="color:<?= $statusType === 'error' ? '#b91c1c' : '#065f46' ?>"><?= htmlspecialchars($statusMsg, ENT_QUOTES) ?></div><?php endif; ?>
<div class="card"><h3>Customer & Project</h3><div class="grid"><div><strong>Name:</strong> <?= htmlspecialchars((string) ($quote['customer_name'] ?? ''), ENT_QUOTES) ?></div><div><strong>Mobile:</strong> <?= htmlspecialchars((string) ($quote['customer_mobile'] ?? ''), ENT_QUOTES) ?></div><div><strong>System:</strong> <?= htmlspecialchars((string) ($quote['system_type'] ?? ''), ENT_QUOTES) ?></div><div><strong>Capacity:</strong> <?= htmlspecialchars((string) ($quote['capacity_kwp'] ?? ''), ENT_QUOTES) ?> kWp</div><div><strong>Valid Until:</strong> <?= htmlspecialchars((string) ($quote['valid_until'] ?? ''), ENT_QUOTES) ?></div><div><strong>Tax Type:</strong> <?= htmlspecialchars((string) ($quote['tax_type'] ?? ''), ENT_QUOTES) ?></div></div></div>
<div class="card"><h3>Pricing summary</h3><table><thead><tr><th>Line</th><th>Basic</th><th>GST</th><th>Total</th></tr></thead><tbody><tr><td>Solar Power Generation System (5%)</td><td><?= number_format((float) ($calc['bucket_5_basic'] ?? 0),2) ?></td><td><?= number_format((float) ($calc['bucket_5_gst'] ?? 0),2) ?></td><td><?= number_format((float) (($calc['bucket_5_basic'] ?? 0)+($calc['bucket_5_gst'] ?? 0)),2) ?></td></tr><tr><td>Solar Power Generation System (18%)</td><td><?= number_format((float) ($calc['bucket_18_basic'] ?? 0),2) ?></td><td><?= number_format((float) ($calc['bucket_18_gst'] ?? 0),2) ?></td><td><?= number_format((float) (($calc['bucket_18_basic'] ?? 0)+($calc['bucket_18_gst'] ?? 0)),2) ?></td></tr></tbody></table><p><strong>Grand Total:</strong> ₹<?= number_format((float) ($calc['grand_total'] ?? 0),2) ?></p><p class="muted">Detailed System Inclusions are provided in Annexures.</p><p class="muted">CGST 5: <?= number_format((float) ($split['cgst_5'] ?? 0),2) ?> | SGST 5: <?= number_format((float) ($split['sgst_5'] ?? 0),2) ?> | IGST 5: <?= number_format((float) ($split['igst_5'] ?? 0),2) ?></p><p class="muted">CGST 18: <?= number_format((float) ($split['cgst_18'] ?? 0),2) ?> | SGST 18: <?= number_format((float) ($split['sgst_18'] ?? 0),2) ?> | IGST 18: <?= number_format((float) ($split['igst_18'] ?? 0),2) ?></p></div>
<div class="card"><h3>Special Requests From Customer (Inclusive in the rate)</h3><p><?= nl2br(htmlspecialchars((string) ($quote['special_requests_inclusive'] ?? ''), ENT_QUOTES)) ?></p><p class="muted">In case of conflict between Annexure inclusions and Special Requests, Special Requests will be given priority.</p></div>
<?php if ((string) ($quote['status'] ?? '') === 'Draft'): ?><div class="card"><h3>Edit draft quote</h3><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>"><input type="hidden" name="action" value="update_quote"><div class="grid"><div><label>Name</label><input name="customer_name" value="<?= htmlspecialchars((string) ($quote['customer_name'] ?? ''), ENT_QUOTES) ?>"></div><div><label>Capacity</label><input name="capacity_kwp" value="<?= htmlspecialchars((string) ($quote['capacity_kwp'] ?? ''), ENT_QUOTES) ?>"></div><div><label>Valid Until</label><input type="date" name="valid_until" value="<?= htmlspecialchars((string) ($quote['valid_until'] ?? ''), ENT_QUOTES) ?>"></div><div><label>Pricing Mode</label><select name="pricing_mode"><option value="solar_split_70_30" <?= (($quote['pricing_mode'] ?? '') === 'solar_split_70_30') ? 'selected' : '' ?>>Solar split 70/30</option><option value="flat_5" <?= (($quote['pricing_mode'] ?? '') === 'flat_5') ? 'selected' : '' ?>>Flat 5%</option></select></div><div><label>Tax Type</label><select name="tax_type"><option value="CGST_SGST" <?= (($quote['tax_type'] ?? '') === 'CGST_SGST') ? 'selected' : '' ?>>CGST+SGST</option><option value="IGST" <?= (($quote['tax_type'] ?? '') === 'IGST') ? 'selected' : '' ?>>IGST</option></select></div><div><label>Total Inclusive</label><input type="number" step="0.01" name="input_total_gst_inclusive" value="<?= htmlspecialchars((string) ($quote['input_total_gst_inclusive'] ?? 0), ENT_QUOTES) ?>"></div><div style="grid-column:1/-1"><label>Billing Address</label><textarea name="billing_address"><?= htmlspecialchars((string) ($quote['billing_address'] ?? ''), ENT_QUOTES) ?></textarea></div><div style="grid-column:1/-1"><label>Site Address</label><textarea name="site_address"><?= htmlspecialchars((string) ($quote['site_address'] ?? ''), ENT_QUOTES) ?></textarea></div><div style="grid-column:1/-1"><label>Special Requests</label><textarea name="special_requests_inclusive"><?= htmlspecialchars((string) ($quote['special_requests_inclusive'] ?? ''), ENT_QUOTES) ?></textarea></div><?php foreach (['system_inclusions','payment_terms','warranty','system_type_explainer','transportation','terms_conditions'] as $k): ?><div style="grid-column:1/-1"><label><?= htmlspecialchars(ucwords(str_replace('_', ' ', $k)), ENT_QUOTES) ?></label><textarea name="annex_<?= htmlspecialchars($k, ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($quote['annexures_overrides'][$k] ?? ''), ENT_QUOTES) ?></textarea></div><?php endforeach; ?></div><p><button class="btn" type="submit">Save Draft Changes</button></p></form></div><?php endif; ?>
</main></body></html>
