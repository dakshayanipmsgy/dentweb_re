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

$challan = documents_get_challan(safe_text($_GET['id'] ?? ''));
if ($challan === null) {
    http_response_code(404);
    echo 'Challan not found.';
    exit;
}
if ($viewerType === 'employee' && ((string) ($challan['created_by']['role'] ?? $challan['created_by_type'] ?? '') !== 'employee' || (string) ($challan['created_by']['id'] ?? $challan['created_by_id'] ?? '') !== $viewerId)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
$lines = documents_normalize_challan_lines((array) ($challan['lines'] ?? []));
$totalQty = 0.0;
$totalFt = 0.0;
$kitBuckets = [];
$extras = [];
foreach ($lines as $line) {
    if (!empty($line['is_cuttable_snapshot'])) {
        $totalFt += (float) ($line['length_ft'] ?? 0);
    } else {
        $totalQty += (float) ($line['qty'] ?? 0);
    }
    if ((string) ($line['source_type'] ?? 'extra') === 'packing') {
        $k = (string) ($line['kit_id'] ?? '');
        if ($k === '') {
            $k = '__direct';
        }
        if (!isset($kitBuckets[$k])) {
            $kitBuckets[$k] = ['name' => (string) ($line['kit_name_snapshot'] ?? ''), 'items' => []];
            if ($k === '__direct') {
                $kitBuckets[$k]['name'] = 'Direct quotation components';
            }
        }
        $kitBuckets[$k]['items'][] = $line;
    } else {
        $extras[] = $line;
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Delivery Challan <?= htmlspecialchars((string) ($challan['dc_number'] ?: $challan['challan_no']), ENT_QUOTES) ?></title>
<style>
@page { size: A4; margin: 12mm; }html,body{margin:0;padding:0}body{font-family:Arial,sans-serif;font-size:12px;color:#111;line-height:1.35}.doc{width:100%}.header{display:flex;justify-content:space-between;gap:18px;border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:10px}.title{text-align:center;font-size:22px;font-weight:700;margin:8px 0}.meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px}.box{border:1px solid #444;padding:8px;min-height:52px}table{width:100%;border-collapse:collapse;margin-bottom:12px}th,td{border:1px solid #444;padding:6px;vertical-align:top}.footer{margin-top:16px;border-top:1px solid #444;padding-top:8px}.sign{display:grid;grid-template-columns:1fr 1fr;gap:28px;margin-top:26px}h3{margin:14px 0 6px}
</style></head><body><div class="doc">
<div class="header"><div><strong><?= htmlspecialchars((string) ($company['brand_name'] ?: $company['company_name'] ?: 'Dakshayani Enterprises'), ENT_QUOTES) ?></strong><br><?= htmlspecialchars((string) ($company['address_line'] ?? ''), ENT_QUOTES) ?><br><?= htmlspecialchars((string) ($company['phone_primary'] ?? ''), ENT_QUOTES) ?></div><div><strong>DC No:</strong> <?= htmlspecialchars((string) ($challan['dc_number'] ?: $challan['challan_no']), ENT_QUOTES) ?><br><strong>Date:</strong> <?= htmlspecialchars((string) ($challan['delivery_date'] ?? ''), ENT_QUOTES) ?><br><strong>Quotation:</strong> <?= htmlspecialchars((string) ($challan['quote_id'] ?: $challan['linked_quote_id']), ENT_QUOTES) ?></div></div>
<div class="title">Delivery Challan</div>
<div class="meta"><div class="box"><strong>Customer</strong><br><?= htmlspecialchars((string) ($challan['customer_name_snapshot'] ?: ($challan['customer_snapshot']['name'] ?? '')), ENT_QUOTES) ?><br><strong>Mobile:</strong> <?= htmlspecialchars((string) ($challan['customer_mobile'] ?: ($challan['customer_snapshot']['mobile'] ?? '')), ENT_QUOTES) ?></div><div class="box"><strong>Site/Delivery Address</strong><br><?= nl2br(htmlspecialchars((string) ($challan['site_address_snapshot'] ?: $challan['delivery_address']), ENT_QUOTES)) ?></div></div>

<?php foreach ($kitBuckets as $bucket): ?>
<h3>Kit: <?= htmlspecialchars((string) ($bucket['name'] ?: 'Quotation Kit'), ENT_QUOTES) ?></h3>
<table><thead><tr><th>Sr No</th><th>Item</th><th>Description/Notes</th><th>HSN</th><th>Qty/Length</th><th>Unit</th></tr></thead><tbody>
<?php foreach ((array) ($bucket['items'] ?? []) as $i => $line): $itemName=(string)($line['component_name_snapshot']??''); if((string)($line['variant_name_snapshot']??'')!==''){$itemName.=' ('.(string)($line['variant_name_snapshot']??'').')';} ?>
<tr><td><?= $i + 1 ?></td><td><?= htmlspecialchars($itemName, ENT_QUOTES) ?></td><td><?= nl2br(htmlspecialchars((string) ($line['notes'] ?? ''), ENT_QUOTES)) ?></td><td><?= htmlspecialchars((string) ($line['hsn_snapshot'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) (!empty($line['is_cuttable_snapshot']) ? (float) ($line['length_ft'] ?? 0) : (float) ($line['qty'] ?? 0)), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($line['unit_snapshot'] ?: (!empty($line['is_cuttable_snapshot']) ? 'ft' : 'Nos')), ENT_QUOTES) ?></td></tr>
<?php endforeach; ?></tbody></table>
<?php endforeach; ?>

<?php if ($extras !== []): ?>
<h3>Not part of the quotation</h3>
<table><thead><tr><th>Sr No</th><th>Item</th><th>Description/Notes</th><th>HSN</th><th>Qty/Length</th><th>Unit</th></tr></thead><tbody>
<?php foreach ($extras as $i => $line): $itemName=(string)($line['component_name_snapshot']??''); if((string)($line['variant_name_snapshot']??'')!==''){$itemName.=' ('.(string)($line['variant_name_snapshot']??'').')';} ?>
<tr><td><?= $i + 1 ?></td><td><?= htmlspecialchars($itemName, ENT_QUOTES) ?></td><td><?= nl2br(htmlspecialchars((string) ($line['notes'] ?? ''), ENT_QUOTES)) ?></td><td><?= htmlspecialchars((string) ($line['hsn_snapshot'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) (!empty($line['is_cuttable_snapshot']) ? (float) ($line['length_ft'] ?? 0) : (float) ($line['qty'] ?? 0)), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($line['unit_snapshot'] ?: (!empty($line['is_cuttable_snapshot']) ? 'ft' : 'Nos')), ENT_QUOTES) ?></td></tr>
<?php endforeach; ?></tbody></table>
<?php endif; ?>

<?php if ($kitBuckets === [] && $extras === []): ?><table><tbody><tr><td>No lines added.</td></tr></tbody></table><?php endif; ?>

<div class="footer"><strong>Totals:</strong> Qty <?= htmlspecialchars((string) $totalQty, ENT_QUOTES) ?> | Length <?= htmlspecialchars((string) $totalFt, ENT_QUOTES) ?> ft<div class="sign"><div><strong>Prepared by</strong><br><br><br>_________________________</div><div><strong>Received by</strong><br><br><br>_________________________</div></div></div>
</div></body></html>
