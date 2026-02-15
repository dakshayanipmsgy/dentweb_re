<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

documents_ensure_structure();
$employeeStore = new EmployeeFsStore();
$user = current_user();
$viewerType = '';
$viewerId = '';
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
if ($viewerType === '') { header('Location: login.php'); exit; }

$id = safe_text($_GET['id'] ?? '');
$quote = documents_get_quote($id);
if ($quote === null) { http_response_code(404); echo 'Quotation not found.'; exit; }
if ($viewerType === 'employee' && ((string) ($quote['created_by_type'] ?? '') !== 'employee' || (string) ($quote['created_by_id'] ?? '') !== $viewerId)) {
    http_response_code(403); echo 'Access denied.'; exit;
}

$redirect = static function (string $type, string $message) use ($id): void {
    header('Location: quotation-view.php?' . http_build_query(['id' => $id, 'status' => $type, 'message' => $message]));
    exit;
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) { $redirect('error', 'Security validation failed.'); }
    $action = safe_text($_POST['action'] ?? '');
    if ($action === 'approve_quote' && $viewerType === 'admin') {
        $quote['status'] = 'Approved';
        $quote['approval']['approved_by_id'] = (string) ($user['id'] ?? '');
        $quote['approval']['approved_by_name'] = (string) ($user['full_name'] ?? 'Admin');
        $quote['approval']['approved_at'] = date('c');
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        $redirect('success', 'Quotation approved.');
    }
    if ($action === 'accept_quote' && $viewerType === 'admin') {
        if (!in_array((string)($quote['status'] ?? ''), ['Approved', 'Accepted'], true)) { $redirect('error', 'Quotation must be Approved first.'); }
        $customer = documents_upsert_customer_from_quote($quote);
        if (!($customer['ok'] ?? false)) { $redirect('error', (string) ($customer['error'] ?? 'Customer creation failed.')); }
        $agreement = documents_create_agreement_from_quote($quote, is_array($user) ? $user : []);
        $proforma = documents_create_proforma_from_quote($quote);
        $invoice = documents_create_invoice_from_quote($quote);
        $quote['links']['agreement_id'] = (string) ($agreement['agreement_id'] ?? ($quote['links']['agreement_id'] ?? ''));
        $quote['links']['proforma_id'] = (string) ($proforma['proforma_id'] ?? ($quote['links']['proforma_id'] ?? ''));
        $quote['links']['invoice_id'] = (string) ($invoice['invoice_id'] ?? ($quote['links']['invoice_id'] ?? ''));
        $quote['status'] = 'Accepted';
        $quote['acceptance']['accepted_by_admin_id'] = (string) ($user['id'] ?? '');
        $quote['acceptance']['accepted_by_admin_name'] = (string) ($user['full_name'] ?? 'Admin');
        $quote['acceptance']['accepted_at'] = date('c');
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        $redirect('success', 'Quotation accepted and linked documents generated.');
    }
    if ($action === 'share_update') {
        if (!in_array((string)($quote['status'] ?? ''), ['Approved', 'Accepted'], true)) { $redirect('error', 'Public sharing is allowed only for Approved/Accepted quotations.'); }
        $quote['share']['public_enabled'] = isset($_POST['public_enabled']);
        if (isset($_POST['generate_token']) || ((string)($quote['share']['public_token'] ?? '')) === '') {
            $quote['share']['public_token'] = bin2hex(random_bytes(16));
            $quote['share']['public_created_at'] = date('c');
        }
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        $redirect('success', 'Share settings updated.');
    }
}

$quoteDefaults = documents_get_quote_defaults_settings();
$segment = (string)($quote['segment'] ?? 'RES');
$segmentDefaults = is_array($quoteDefaults['segments'][$segment] ?? null) ? $quoteDefaults['segments'][$segment] : [];
$company = documents_get_company_profile_for_quotes();
$shareUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/quotation-public.php?token=' . urlencode((string)($quote['share']['public_token'] ?? ''));

$primaryPhone = trim((string)($company['phone_primary'] ?? ''));
$secondaryPhone = trim((string)($company['phone_secondary'] ?? ''));
$email = trim((string)($company['email_primary'] ?? ''));
$website = trim((string)($company['website'] ?? ''));
$brandName = trim((string)($company['brand_name'] ?? '')) ?: 'Dakshayani Enterprises';
$companyName = trim((string)($company['company_name'] ?? '')) ?: $brandName;

$lineItems = documents_normalize_line_items($quote['line_items'] ?? [], (string)($quote['system_type'] ?? 'Ongrid'), safe_text($quoteDefaults['global']['quotation_defaults']['default_hsn'] ?? '8541'));
$calc = is_array($quote['calc'] ?? null) ? $quote['calc'] : [];
$transportation = (float)($calc['transportation_rs'] ?? ($quote['pricing_extras']['transportation_rs'] ?? ($quote['finance_inputs']['transportation_rs'] ?? 0)));
$roundoff = (float)($calc['roundoff_rs'] ?? ($quote['pricing_extras']['roundoff_rs'] ?? 0));
$finalPrice = (float)($calc['final_price_incl_gst'] ?? ($quote['input_total_gst_inclusive'] ?? 0));
$grossPayable = (float)($calc['gross_payable'] ?? ($finalPrice + $transportation));
$subsidy = (float)($quote['finance_inputs']['subsidy_expected_rs'] ?? 0);
if ($subsidy <= 0 && $segment === 'RES') {
    $cap = (float)($quote['capacity_kwp'] ?? 0);
    $subsidy = $cap >= 3 ? (float)($segmentDefaults['subsidy']['cap_3kw_plus'] ?? 78000) : ($cap >= 2 ? (float)($segmentDefaults['subsidy']['cap_2kw'] ?? 60000) : 0);
}
$showSubsidy = $subsidy > 0 || $segment === 'RES';
$netAfterSubsidy = max(0, $grossPayable - $subsidy);

$monthlyBill = max(0, (float)($quote['finance_inputs']['monthly_bill_rs'] ?? 0));
$unitRate = max(0.0001, (float)($quote['finance_inputs']['unit_rate_rs_per_kwh'] ?: ($segmentDefaults['unit_rate_rs_per_kwh'] ?? 8)));
$annualGeneration = max(0, (float)($quote['finance_inputs']['annual_generation_per_kw'] ?: ($segmentDefaults['annual_generation_per_kw'] ?? ($quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450))));
$capacity = max(0, (float)($quote['capacity_kwp'] ?? 0));
$bestMaxLoan = max(0, (float)($segmentDefaults['loan_bestcase']['max_loan_rs'] ?? 200000));
$interestPct = max(0, (float)($segmentDefaults['loan_bestcase']['interest_pct'] ?? 6.0));
$tenureYears = max(0, (int)($segmentDefaults['loan_bestcase']['tenure_years'] ?? 10));
$minMarginPct = max(0, (float)($segmentDefaults['loan_bestcase']['min_margin_pct'] ?? 10));
$marginRs = $grossPayable * ($minMarginPct / 100);
$loanRs = min(max(0, $grossPayable - $marginRs), $bestMaxLoan);
$marginRs = max(0, $grossPayable - $loanRs);
$loanEffective = max(0, $loanRs - $subsidy);
$r = ($interestPct / 100) / 12;
$n = $tenureYears * 12;
$emi = ($loanEffective > 0 && $n > 0) ? ($r == 0 ? $loanEffective / $n : (($loanEffective * $r * pow(1 + $r, $n)) / (pow(1 + $r, $n) - 1))) : 0;
$monthlyUnits = $monthlyBill / $unitRate;
$monthlySolarUnits = ($capacity * $annualGeneration) / 12;
$residualBill = max(0, ($monthlyUnits - $monthlySolarUnits) * $unitRate);

$coverNote = (string)($quote['cover_note_html'] ?? '');
if ($coverNote === '') {
    $coverNote = (string)($quote['annexures_overrides']['cover_notes'] ?? '');
}
$specialReq = (string)($quote['special_requests_text'] ?: ($quote['special_requests_inclusive'] ?? ''));
$ann = is_array($quote['annexures_overrides'] ?? null) ? $quote['annexures_overrides'] : [];
$annOrder = [
    'warranty' => 'Warranty',
    'system_inclusions' => 'System inclusions',
    'pm_subsidy_info' => 'PM subsidy info',
    'completion_milestones' => 'Completion milestones',
    'payment_terms' => 'Payment terms',
    'system_type_explainer' => 'System Type explainer (ongrid/hybrid/offgrid)',
    'transportation' => 'Transportation',
    'terms_conditions' => 'Terms and conditions',
];

function fmt(float $v): string { return '₹' . number_format($v, 2); }
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quotation <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></title>
<style>body{font-family:Arial,sans-serif;background:#eef2f7;margin:0}.wrap{max-width:1100px;margin:0 auto;padding:14px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:12px}.h{font-size:18px;font-weight:700}.muted{color:#64748b}.row{display:flex;justify-content:space-between;border-bottom:1px dashed #e2e8f0;padding:6px 0}.row:last-child{border-bottom:none}details{margin-top:8px}.table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe1ea;padding:8px;text-align:left}</style></head><body><main class="wrap">
<div class="card"><div class="h"><?= htmlspecialchars($companyName, ENT_QUOTES) ?></div><div class="muted"><?= htmlspecialchars($quote['customer_name'] ?? '', ENT_QUOTES) ?> · Quote <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></div></div>
<?php if ($coverNote !== ''): ?><div class="card"><?= $coverNote ?></div><?php endif; ?>
<div class="card"><div class="h">Items / Price Table</div><table class="table"><thead><tr><th>Item Title</th><th>HSN</th><th>Qty</th><th>Unit</th><th>Tax Profile</th><th>Amount (incl GST)</th></tr></thead><tbody><?php foreach($lineItems as $li): ?><tr><td><?= htmlspecialchars((string)$li['title'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$li['hsn'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$li['qty'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$li['unit'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$li['tax_profile'], ENT_QUOTES) ?></td><td><?= fmt((float)$li['amount_incl_gst']) ?></td></tr><?php endforeach; ?></tbody></table><div class="muted" style="margin-top:8px">Detailed inclusions are listed in Annexures. Price table lists commercial items only.</div></div>
<div class="card"><div class="h">Pricing Summary + Subsidy + Transportation</div>
<div class="row"><span>Final Price (incl. GST)</span><b><?= fmt($finalPrice) ?></b></div>
<div class="row"><span>Transportation (as applicable)</span><b><?= fmt($transportation) ?></b></div>
<div class="row"><span>Gross (incl. GST) payable</span><b><?= fmt($grossPayable) ?></b></div>
<details><summary>GST Breakup</summary>
<div class="row"><span>Basic @5% portion</span><b><?= fmt((float)($calc['bucket_5_basic'] ?? 0)) ?></b></div>
<div class="row"><span>GST @5%</span><b><?= fmt((float)($calc['bucket_5_gst'] ?? 0)) ?></b></div>
<?php if (((float)($calc['bucket_18_basic'] ?? 0)) > 0 || !empty($calc['has_solar_split'])): ?>
<div class="row"><span>Basic @18% portion</span><b><?= fmt((float)($calc['bucket_18_basic'] ?? 0)) ?></b></div>
<div class="row"><span>GST @18%</span><b><?= fmt((float)($calc['bucket_18_gst'] ?? 0)) ?></b></div>
<?php endif; ?>
<div class="row"><span>Total GST</span><b><?= fmt((float)($calc['total_gst'] ?? ((float)($calc['bucket_5_gst'] ?? 0) + (float)($calc['bucket_18_gst'] ?? 0))) ) ?></b></div>
</details>
<?php if ($showSubsidy): ?><div class="row"><span>Subsidy expected</span><b><?= fmt($subsidy) ?></b></div><div class="row"><span>Net after subsidy</span><b><?= fmt($netAfterSubsidy) ?></b></div><?php endif; ?>
</div>
<div class="card"><div class="h">Bank Loan Snapshot</div>
<div class="row"><span>Margin Money (₹)</span><b><?= fmt($marginRs) ?></b></div>
<div class="row"><span>Initial Loan Amount (₹)</span><b><?= fmt($loanRs) ?></b></div>
<div class="row"><span>Loan Amount after discounting subsidy (₹)</span><b><?= fmt($loanEffective) ?></b></div>
<div class="row"><span>EMI on discounted loan amount (₹/month)</span><b><?= fmt($emi) ?></b></div>
<div class="row"><span>Residual bill after solar (₹/month)</span><b><?= fmt($residualBill) ?></b></div>
<div class="row"><span>Total monthly outflow (₹/month)</span><b><?= fmt($emi + $residualBill) ?></b></div>
<div class="muted">Total payable to vendor remains <?= fmt($grossPayable) ?>. Funding changes how you pay (margin + loan vs upfront).</div>
</div>
<div class="card"><div class="h">Self-Financed Snapshot</div>
<div class="row"><span>Upfront investment (₹)</span><b><?= fmt($grossPayable) ?></b></div>
<div class="row"><span>Investment minus subsidy (₹)</span><b><?= fmt(max(0, $grossPayable - $subsidy)) ?></b></div>
<div class="row"><span>Residual bill after solar (₹/month)</span><b><?= fmt($residualBill) ?></b></div></div>
<div class="card"><div class="h">No Solar Snapshot</div><div class="row"><span>Current bill (₹/month)</span><b><?= fmt($monthlyBill) ?></b></div></div>
<div class="card"><div class="h">Special Requests From Consumer (Inclusive in the rate)</div><div><?= nl2br(htmlspecialchars($specialReq, ENT_QUOTES)) ?></div><div class="muted" style="margin-top:8px">In case of any conflict between Annexures and Special Requests, Special Requests will be prioritised.</div></div>
<div class="card"><div class="h">Annexures</div><?php foreach($annOrder as $k=>$label): ?><details><summary><?= htmlspecialchars($label, ENT_QUOTES) ?></summary><div><?= nl2br(htmlspecialchars((string)($ann[$k] ?? ''), ENT_QUOTES)) ?></div></details><?php endforeach; ?></div>
<?php if ($viewerType==='admin' || $viewerType==='employee'): ?>
<div class="card"><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>"><?php if ($viewerType==='admin' && (string)($quote['status']??'')==='Draft'): ?><button name="action" value="approve_quote">Approve</button><?php endif; ?><?php if ($viewerType==='admin' && in_array((string)($quote['status']??''), ['Approved','Accepted'], true)): ?><button name="action" value="accept_quote">Accepted by Customer</button><?php endif; ?></form></div>
<div class="card"><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>"><input type="hidden" name="action" value="share_update"><label><input type="checkbox" name="public_enabled" <?= !empty($quote['share']['public_enabled'])?'checked':'' ?>> Enable public sharing</label> <button name="generate_token" value="1">Generate New Token</button> <button type="submit">Save Share Settings</button><?php if (!empty($quote['share']['public_token'])): ?><p>Public URL: <a href="<?= htmlspecialchars($shareUrl, ENT_QUOTES) ?>" target="_blank"><?= htmlspecialchars($shareUrl, ENT_QUOTES) ?></a></p><?php endif; ?></form></div>
<?php endif; ?>
</main></body></html>
