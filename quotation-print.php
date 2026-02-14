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

$quote = documents_load_quote(safe_text($_GET['id'] ?? ''));
if (!$quote) {
    http_response_code(404);
    echo 'Quotation not found.';
    exit;
}

$actorEmployeeId = $employee['id'] ?? ($isEmployeeUser ? ($user['id'] ?? '') : '');
$canAccess = $isAdmin || ((string) ($quote['created_by_type'] ?? '') === 'employee' && (string) ($quote['created_by_id'] ?? '') === (string) $actorEmployeeId);
if (!$canAccess) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$company = json_load(documents_settings_dir() . '/company_profile.json', documents_company_profile_defaults());
$company = array_merge(documents_company_profile_defaults(), is_array($company) ? $company : []);
$calc = is_array($quote['calc'] ?? null) ? $quote['calc'] : [];
$split = is_array($calc['gst_split'] ?? null) ? $calc['gst_split'] : [];
$ann = is_array($quote['annexures_overrides'] ?? null) ? $quote['annexures_overrides'] : [];
$blocks = documents_load_template_blocks();
$templateBlock = is_array($blocks[$quote['template_set_id'] ?? ''] ?? null) ? $blocks[$quote['template_set_id'] ?? ''] : [];
$bg = (string) ($quote['rendering']['background_image'] ?? '');
$opacity = (float) ($quote['rendering']['background_opacity'] ?? 1);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Print <?= htmlspecialchars((string) ($quote['quote_no'] ?? ''), ENT_QUOTES) ?></title>
<style>
@page{size:A4;margin:16mm}
body{font-family:Arial,sans-serif;color:#111;margin:0}
.page{position:relative;min-height:1120px;padding:6mm 0}
.bg{position:fixed;inset:0;background-image:url('<?= htmlspecialchars($bg, ENT_QUOTES) ?>');background-size:cover;background-repeat:no-repeat;opacity:<?= htmlspecialchars((string) $opacity, ENT_QUOTES) ?>;z-index:-1}
h1,h2,h3{margin:0 0 8px}table{width:100%;border-collapse:collapse;margin:8px 0 10px}th,td{border:1px solid #cbd5e1;padding:6px;font-size:12px}th{background:#f8fafc}p{margin:4px 0;font-size:12px}.small{font-size:11px;color:#334155}.section{margin-top:14px}.head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.logo{max-height:70px}
</style>
</head><body>
<?php if ($bg !== ''): ?><div class="bg"></div><?php endif; ?>
<div class="page">
<div class="head"><div><h1><?= htmlspecialchars((string) ($company['brand_name'] ?: $company['company_name']), ENT_QUOTES) ?></h1><p><?= htmlspecialchars((string) $company['address_line'], ENT_QUOTES) ?>, <?= htmlspecialchars((string) $company['city'], ENT_QUOTES) ?>, <?= htmlspecialchars((string) $company['state'], ENT_QUOTES) ?> - <?= htmlspecialchars((string) $company['pin'], ENT_QUOTES) ?></p><p>GSTIN: <?= htmlspecialchars((string) $company['gstin'], ENT_QUOTES) ?> | UDYAM: <?= htmlspecialchars((string) $company['udyam'], ENT_QUOTES) ?></p><p>JREDA: <?= htmlspecialchars((string) $company['jreda_license'], ENT_QUOTES) ?> | DWSD: <?= htmlspecialchars((string) $company['dwsd_license'], ENT_QUOTES) ?></p></div><?php if ((string) $company['logo_path'] !== ''): ?><img class="logo" src="<?= htmlspecialchars((string) $company['logo_path'], ENT_QUOTES) ?>" alt="logo"><?php endif; ?></div>
<hr>
<h2>Quotation: <?= htmlspecialchars((string) ($quote['quote_no'] ?? ''), ENT_QUOTES) ?></h2>
<p><strong>Date:</strong> <?= htmlspecialchars(substr((string) ($quote['created_at'] ?? ''), 0, 10), ENT_QUOTES) ?> | <strong>Validity:</strong> <?= htmlspecialchars((string) ($quote['valid_until'] ?? ''), ENT_QUOTES) ?></p>
<p><strong>Customer:</strong> <?= htmlspecialchars((string) ($quote['customer_name'] ?? ''), ENT_QUOTES) ?> (<?= htmlspecialchars((string) ($quote['customer_mobile'] ?? ''), ENT_QUOTES) ?>)</p>
<p><strong>System:</strong> <?= htmlspecialchars((string) ($quote['system_type'] ?? ''), ENT_QUOTES) ?> | <strong>Capacity:</strong> <?= htmlspecialchars((string) ($quote['capacity_kwp'] ?? ''), ENT_QUOTES) ?> kWp</p>
<p><strong>Site Address:</strong> <?= htmlspecialchars((string) ($quote['site_address'] ?? ''), ENT_QUOTES) ?></p>
<div class="section"><h3>Price Summary</h3>
<table><thead><tr><th>Description</th><th>Basic Amount</th><th><?= (($quote['tax_type'] ?? 'CGST_SGST') === 'IGST') ? 'IGST' : 'CGST+SGST' ?></th><th>Total</th></tr></thead><tbody>
<tr><td>Solar Power Generation System (5%)</td><td><?= number_format((float) ($calc['bucket_5_basic'] ?? 0), 2) ?></td><td><?= number_format((float) (($quote['tax_type'] ?? 'CGST_SGST') === 'IGST' ? ($split['igst_5'] ?? 0) : (($split['cgst_5'] ?? 0)+($split['sgst_5'] ?? 0))), 2) ?></td><td><?= number_format((float) (($calc['bucket_5_basic'] ?? 0)+($calc['bucket_5_gst'] ?? 0)),2) ?></td></tr>
<tr><td>Solar Power Generation System (18%)</td><td><?= number_format((float) ($calc['bucket_18_basic'] ?? 0), 2) ?></td><td><?= number_format((float) (($quote['tax_type'] ?? 'CGST_SGST') === 'IGST' ? ($split['igst_18'] ?? 0) : (($split['cgst_18'] ?? 0)+($split['sgst_18'] ?? 0))), 2) ?></td><td><?= number_format((float) (($calc['bucket_18_basic'] ?? 0)+($calc['bucket_18_gst'] ?? 0)),2) ?></td></tr>
<tr><th colspan="3" style="text-align:right">Grand Total</th><th>₹<?= number_format((float) ($calc['grand_total'] ?? 0),2) ?></th></tr>
</tbody></table>
<p class="small">Detailed System Inclusions are provided in Annexures.</p></div>

<div class="section"><h3>Special Requests From Customer (Inclusive in the rate)</h3><p><?= nl2br(htmlspecialchars((string) ($quote['special_requests_inclusive'] ?? ''), ENT_QUOTES)) ?></p><p class="small">In case of conflict between Annexure inclusions and Special Requests, Special Requests will be given priority.</p></div>

<?php if (($quote['segment'] ?? '') === 'RES' && str_contains((string) ($quote['template_set_id'] ?? ''), 'pm')): ?><div class="section"><h3>PM Surya Ghar Subsidy Information</h3><p><?= nl2br(htmlspecialchars((string) ($templateBlock['pm_surya_ghar_info'] ?? ''), ENT_QUOTES)) ?></p></div><?php endif; ?>

<div class="section"><h3>Annexures</h3>
<?php foreach ([
'System Inclusions' => 'system_inclusions',
'Payment Terms' => 'payment_terms',
'Warranty' => 'warranty',
'Transportation' => 'transportation',
'System Type Explainer' => 'system_type_explainer',
'Terms & Conditions' => 'terms_conditions',
] as $label => $key): ?>
<h4><?= htmlspecialchars($label, ENT_QUOTES) ?></h4><p><?= nl2br(htmlspecialchars((string) ($ann[$key] ?? ''), ENT_QUOTES)) ?></p>
<?php endforeach; ?>
</div>
</div>
<script>window.addEventListener('load',()=>{if(new URLSearchParams(window.location.search).get('autoprint')==='1'){window.print();}});</script>
</body></html>
