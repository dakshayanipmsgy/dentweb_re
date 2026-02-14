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
if ($viewerType === 'employee' && ((string) ($quote['created_by_id'] ?? '') !== $viewerId || (string) ($quote['created_by_type'] ?? '') !== 'employee')) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
$tplSets = json_load(documents_templates_dir() . '/template_sets.json', []);
$template = null;
foreach ((array) $tplSets as $row) {
    if (is_array($row) && (string) ($row['id'] ?? '') === (string) ($quote['template_set_id'] ?? '')) {
        $template = $row;
        break;
    }
}
$background = (string) ($quote['rendering']['background_image'] ?? '');
$bgOpacity = (float) ($quote['rendering']['background_opacity'] ?? 1);
$isPmTemplate = str_contains(strtolower((string) ($quote['template_set_id'] ?? '')), 'pm') && (string) ($quote['segment'] ?? '') === 'RES';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Print <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></title>
<style>
@page { size: A4; margin: 16mm 12mm; }
body{font-family:Arial,sans-serif;color:#111;font-size:12px;line-height:1.4;margin:0}
.page{position:relative;min-height:260mm;padding:2mm}
.page:before{content:'';position:fixed;inset:0;background-image:url('<?= htmlspecialchars($background, ENT_QUOTES) ?>');background-repeat:no-repeat;background-size:cover;opacity:<?= htmlspecialchars((string)$bgOpacity, ENT_QUOTES) ?>;z-index:-1}
.header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #1d4ed8;padding-bottom:8px;margin-bottom:12px}
.title{text-align:right}.muted{color:#555}
table{width:100%;border-collapse:collapse;margin:8px 0}th,td{border:1px solid #999;padding:6px;text-align:left;vertical-align:top}th{background:#f2f4f8}
.section{margin-top:10px}.annexure{page-break-inside:avoid}
</style></head>
<body><div class="page">
<div class="header">
<div>
<?php if ((string)($company['logo_path'] ?? '') !== ''): ?><img src="<?= htmlspecialchars((string)$company['logo_path'], ENT_QUOTES) ?>" alt="logo" style="max-height:60px"><br><?php endif; ?>
<strong><?= htmlspecialchars((string)($company['brand_name'] ?: $company['company_name']), ENT_QUOTES) ?></strong><br>
<?= htmlspecialchars((string)$company['address_line'], ENT_QUOTES) ?>, <?= htmlspecialchars((string)$company['city'], ENT_QUOTES) ?><br>
<?= htmlspecialchars((string)$company['district'], ENT_QUOTES) ?>, <?= htmlspecialchars((string)$company['state'], ENT_QUOTES) ?> - <?= htmlspecialchars((string)$company['pin'], ENT_QUOTES) ?><br>
GSTIN: <?= htmlspecialchars((string)$company['gstin'], ENT_QUOTES) ?> | UDYAM: <?= htmlspecialchars((string)$company['udyam'], ENT_QUOTES) ?><br>
Lic: JREDA <?= htmlspecialchars((string)$company['jreda_license'], ENT_QUOTES) ?>, DWSD <?= htmlspecialchars((string)$company['dwsd_license'], ENT_QUOTES) ?>
</div>
<div class="title"><h2 style="margin:0">Quotation</h2><div><strong><?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></strong></div><div>Valid Until: <?= htmlspecialchars((string)$quote['valid_until'], ENT_QUOTES) ?></div></div>
</div>

<div class="section"><strong>To:</strong> <?= htmlspecialchars((string)$quote['customer_name'], ENT_QUOTES) ?> (<?= htmlspecialchars((string)$quote['customer_mobile'], ENT_QUOTES) ?>)<br>
<?= nl2br(htmlspecialchars((string)$quote['billing_address'], ENT_QUOTES)) ?><br>
<?= htmlspecialchars((string)$quote['district'], ENT_QUOTES) ?>, <?= htmlspecialchars((string)$quote['city'], ENT_QUOTES) ?>, <?= htmlspecialchars((string)$quote['state'], ENT_QUOTES) ?> - <?= htmlspecialchars((string)$quote['pin'], ENT_QUOTES) ?><br>
System: <?= htmlspecialchars((string)$quote['system_type'], ENT_QUOTES) ?> | Capacity: <?= htmlspecialchars((string)$quote['capacity_kwp'], ENT_QUOTES) ?> kWp
</div>

<div class="section"><table><thead><tr><th>Description</th><th>Basic Amount</th><th><?= $quote['tax_type'] === 'IGST' ? 'IGST' : 'CGST' ?></th><?php if ($quote['tax_type'] !== 'IGST'): ?><th>SGST</th><?php endif; ?><th>Total</th></tr></thead><tbody>
<tr><td>Solar Power Generation System (5%)</td><td><?= number_format((float)$quote['calc']['bucket_5_basic'],2) ?></td>
<?php if ($quote['tax_type'] === 'IGST'): ?><td><?= number_format((float)$quote['calc']['gst_split']['igst_5'],2) ?></td><?php else: ?><td><?= number_format((float)$quote['calc']['gst_split']['cgst_5'],2) ?></td><td><?= number_format((float)$quote['calc']['gst_split']['sgst_5'],2) ?></td><?php endif; ?>
<td><?= number_format((float)$quote['calc']['bucket_5_basic'] + (float)$quote['calc']['bucket_5_gst'],2) ?></td></tr>
<tr><td>Solar Power Generation System (18%)</td><td><?= number_format((float)$quote['calc']['bucket_18_basic'],2) ?></td>
<?php if ($quote['tax_type'] === 'IGST'): ?><td><?= number_format((float)$quote['calc']['gst_split']['igst_18'],2) ?></td><?php else: ?><td><?= number_format((float)$quote['calc']['gst_split']['cgst_18'],2) ?></td><td><?= number_format((float)$quote['calc']['gst_split']['sgst_18'],2) ?></td><?php endif; ?>
<td><?= number_format((float)$quote['calc']['bucket_18_basic'] + (float)$quote['calc']['bucket_18_gst'],2) ?></td></tr>
<tr><th colspan="<?= $quote['tax_type'] === 'IGST' ? '3' : '4' ?>">Grand Total</th><th><?= number_format((float)$quote['calc']['grand_total'],2) ?></th></tr>
</tbody></table>
<div class="muted">Detailed System Inclusions are provided in Annexures.</div></div>

<div class="section"><h3>Special Requests From Customer (Inclusive in the rate)</h3>
<div><?= nl2br(htmlspecialchars((string)$quote['special_requests_inclusive'], ENT_QUOTES)) ?></div>
<div><em>In case of conflict between Annexure inclusions and Special Requests, Special Requests will be given priority.</em></div>
</div>

<?php if ($isPmTemplate && trim((string)($quote['annexures_overrides']['pm_subsidy_info'] ?? '')) !== ''): ?>
<div class="section annexure"><h3>PM Surya Ghar Subsidy Information</h3><div><?= nl2br(htmlspecialchars((string)$quote['annexures_overrides']['pm_subsidy_info'], ENT_QUOTES)) ?></div></div>
<?php endif; ?>

<?php foreach (['system_inclusions'=>'System Inclusions','payment_terms'=>'Payment Terms','warranty'=>'Warranty','transportation'=>'Transportation','system_type_explainer'=>'System Type Explainer','terms_conditions'=>'Terms & Conditions'] as $k=>$heading): ?>
<div class="section annexure"><h3><?= $heading ?></h3><div><?= nl2br(htmlspecialchars((string)($quote['annexures_overrides'][$k] ?? ''), ENT_QUOTES)) ?></div></div>
<?php endforeach; ?>
</div>
<script>window.onload=function(){if(location.search.indexOf('autoprint=1')!==-1){window.print();}}</script>
</body></html>
