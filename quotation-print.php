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

$renderForPdf = safe_text($_GET['pdf'] ?? '') === '1';
$company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
$background = (string) ($quote['rendering']['background_image'] ?? '');
$bgOpacity = max(0.1, min(1.0, (float) ($quote['rendering']['background_opacity'] ?? 1)));
$backgroundResolved = $renderForPdf ? (resolve_public_image_to_absolute($background) ?? $background) : $background;
$isPmTemplate = str_contains(strtolower((string) ($quote['template_set_id'] ?? '')), 'pm') && (string) ($quote['segment'] ?? '') === 'RES';
$library = documents_get_media_library();
$mediaMap = [];
foreach ($library as $item) {
    if (!is_array($item)) {
        continue;
    }
    $mediaMap[(string) ($item['id'] ?? '')] = $item;
}
$attachments = is_array($quote['template_attachments'] ?? null) ? $quote['template_attachments'] : documents_template_attachment_defaults();
$snapshot = documents_quote_resolve_snapshot($quote);
$safeHtml = static function (string $value): string {
    $clean = strip_tags($value, '<p><br><ul><ol><li><strong><em><b><i><u><table><thead><tbody><tr><td><th>');
    return $clean !== '' ? $clean : '<span style="color:#666">—</span>';
};
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Print <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></title>
<style>
@page { size: A4; margin: 16mm 12mm; }
body{font-family:Arial,sans-serif;color:#111;font-size:12px;line-height:1.4;margin:0}
.page{position:relative;min-height:260mm;padding:2mm}
.page-bg-img{position:fixed;inset:0;width:100%;height:100%;object-fit:cover;opacity:<?= htmlspecialchars((string)$bgOpacity, ENT_QUOTES) ?>;z-index:-1}
.header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #1d4ed8;padding-bottom:8px;margin-bottom:12px}
.title{text-align:right}.muted{color:#555}
table{width:100%;border-collapse:collapse;margin:8px 0}th,td{border:1px solid #999;padding:6px;text-align:left;vertical-align:top}th{background:#f2f4f8}
.section{margin-top:10px}.annexure{page-break-inside:avoid}.diagram{max-width:100%;max-height:240px;border:1px solid #dbe1ea;padding:4px;border-radius:4px}
</style></head>
<body><div class="page">
<?php if ($backgroundResolved !== ''): ?><img class="page-bg-img" src="<?= htmlspecialchars($backgroundResolved, ENT_QUOTES) ?>" alt="background"><?php endif; ?>
<div class="header">
<div>
<?php if ((string)($company['logo_path'] ?? '') !== ''): ?><img src="<?= htmlspecialchars((string)$company['logo_path'], ENT_QUOTES) ?>" alt="logo" style="max-height:60px"><br><?php endif; ?>
<strong><?= htmlspecialchars((string)($company['brand_name'] ?: $company['company_name']), ENT_QUOTES) ?></strong><br>
<?= htmlspecialchars((string)$company['address_line'], ENT_QUOTES) ?>, <?= htmlspecialchars((string)$company['city'], ENT_QUOTES) ?><br>
<?= htmlspecialchars((string)$company['district'], ENT_QUOTES) ?>, <?= htmlspecialchars((string)$company['state'], ENT_QUOTES) ?> - <?= htmlspecialchars((string)$company['pin'], ENT_QUOTES) ?><br>
GSTIN: <?= htmlspecialchars((string)$company['gstin'], ENT_QUOTES) ?> | UDYAM: <?= htmlspecialchars((string)$company['udyam'], ENT_QUOTES) ?>
</div>
<div class="title"><h2 style="margin:0">Quotation</h2><div><strong><?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></strong></div><div>Valid Until: <?= htmlspecialchars((string)$quote['valid_until'], ENT_QUOTES) ?></div></div>
</div>
<div class="section"><strong>To:</strong> <?= htmlspecialchars((string)($snapshot['name'] ?: $quote['customer_name']), ENT_QUOTES) ?> (<?= htmlspecialchars((string)($snapshot['mobile'] ?: $quote['customer_mobile']), ENT_QUOTES) ?>)<br>
<strong>Site Address:</strong> <?= nl2br(htmlspecialchars((string)($quote['site_address'] ?: $snapshot['address']), ENT_QUOTES)) ?><br>
<?= htmlspecialchars((string)($quote['district'] ?: $snapshot['district']), ENT_QUOTES) ?>, <?= htmlspecialchars((string)($quote['city'] ?: $snapshot['city']), ENT_QUOTES) ?>, <?= htmlspecialchars((string)($quote['state'] ?: $snapshot['state']), ENT_QUOTES) ?> - <?= htmlspecialchars((string)($quote['pin'] ?: $snapshot['pin_code']), ENT_QUOTES) ?><br>
<strong>Consumer Account No. (JBVNL):</strong> <?= htmlspecialchars((string)($quote['consumer_account_no'] ?: $snapshot['consumer_account_no']), ENT_QUOTES) ?><br>
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
</tbody></table><div class="muted">Detailed System Inclusions are provided in Annexures.</div><div class="muted" style="margin-top:6px">Application ID: <?= htmlspecialchars((string)($quote['application_id'] ?: $snapshot['application_id']), ENT_QUOTES) ?> | Circle/Division/Sub Division: <?= htmlspecialchars((string)($quote['circle_name'] ?: $snapshot['circle_name']), ENT_QUOTES) ?> / <?= htmlspecialchars((string)($quote['division_name'] ?: $snapshot['division_name']), ENT_QUOTES) ?> / <?= htmlspecialchars((string)($quote['sub_division_name'] ?: $snapshot['sub_division_name']), ENT_QUOTES) ?> | Sanction/Installed kWp: <?= htmlspecialchars((string)($quote['sanction_load_kwp'] ?: $snapshot['sanction_load_kwp']), ENT_QUOTES) ?> / <?= htmlspecialchars((string)($quote['installed_pv_module_capacity_kwp'] ?: $snapshot['installed_pv_module_capacity_kwp']), ENT_QUOTES) ?></div></div>

<?php if (trim((string) ($quote['annexures_overrides']['cover_notes'] ?? '')) !== ''): ?><div class="section annexure"><h3>Cover Notes</h3><div><?= $safeHtml((string)$quote['annexures_overrides']['cover_notes']) ?></div></div><?php endif; ?>
<div class="section"><h3>Special Requests From Customer (Inclusive in the rate)</h3>
<div><?= nl2br(htmlspecialchars((string)$quote['special_requests_inclusive'], ENT_QUOTES)) ?></div>
<div><em>In case of conflict between Annexure inclusions and Special Requests, Special Requests will be given priority.</em></div>
</div>
<?php if ($isPmTemplate && trim((string)($quote['annexures_overrides']['pm_subsidy_info'] ?? '')) !== ''): ?>
<div class="section annexure"><h3>PM Surya Ghar Subsidy Information</h3><div><?= $safeHtml((string)$quote['annexures_overrides']['pm_subsidy_info']) ?></div></div>
<?php endif; ?>
<?php foreach (['system_inclusions'=>'System Inclusions','payment_terms'=>'Payment Terms','warranty'=>'Warranty','transportation'=>'Transportation','system_type_explainer'=>'System Type Explainer','terms_conditions'=>'Terms & Conditions'] as $k=>$heading): ?>
<div class="section annexure"><h3><?= $heading ?></h3><div><?= $safeHtml((string)($quote['annexures_overrides'][$k] ?? '')) ?></div></div>
<?php endforeach; ?>

<?php
$diagramSections = [
    ['include_ongrid_diagram', 'ongrid_diagram_media_id', 'Ongrid Diagram'],
    ['include_hybrid_diagram', 'hybrid_diagram_media_id', 'Hybrid Diagram'],
    ['include_offgrid_diagram', 'offgrid_diagram_media_id', 'Offgrid Diagram'],
];
$printedDiagramHeader = false;
foreach ($diagramSections as [$flagKey, $idKey, $title]) {
    if (empty($attachments[$flagKey])) {
        continue;
    }
    $mediaId = safe_text($attachments[$idKey] ?? '');
    if ($mediaId === '' || !isset($mediaMap[$mediaId])) {
        continue;
    }
    $media = $mediaMap[$mediaId];
    if (($media['archived_flag'] ?? false)) {
        continue;
    }
    $path = (string) ($media['file_path'] ?? '');
    if ($path === '') {
        continue;
    }
    $resolved = $renderForPdf ? (resolve_public_image_to_absolute($path) ?? $path) : $path;
    if (!$printedDiagramHeader) {
        echo '<div class="section annexure"><h3>Visual Representation</h3>';
        $printedDiagramHeader = true;
    }
    echo '<p><strong>' . htmlspecialchars($title, ENT_QUOTES) . '</strong></p>';
    echo '<img class="diagram" src="' . htmlspecialchars($resolved, ENT_QUOTES) . '" alt="' . htmlspecialchars($title, ENT_QUOTES) . '">';
}
if ($printedDiagramHeader) {
    echo '</div>';
}
?>
</div>
<script>window.onload=function(){if(location.search.indexOf('autoprint=1')!==-1){window.print();}}</script>
</body></html>
