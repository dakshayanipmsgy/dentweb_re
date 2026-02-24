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
if ($viewerType === '') { header('Location: login.php'); exit; }

$challan = documents_get_challan(safe_text($_GET['id'] ?? ''));
if ($challan === null) { http_response_code(404); echo 'Challan not found.'; exit; }
if ($viewerType === 'employee' && ((string) ($challan['created_by']['role'] ?? $challan['created_by_type'] ?? '') !== 'employee' || (string) ($challan['created_by']['id'] ?? $challan['created_by_id'] ?? '') !== $viewerId)) {
    http_response_code(403); echo 'Access denied.'; exit;
}

$company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
$lines = documents_normalize_challan_lines((array) ($challan['lines'] ?? []));
$quotationLines = [];
$extraLines = [];
foreach ($lines as $line) {
    if ((string) ($line['line_origin'] ?? 'extra') === 'quotation') { $quotationLines[] = $line; }
    else { $extraLines[] = $line; }
}
$renderRows = static function (array $rows): void {
    if ($rows === []) {
        echo '<tr><td colspan="5">No lines.</td></tr>';
        return;
    }
    foreach ($rows as $idx => $line) {
        $component = (string) ($line['component_name_snapshot'] ?? '');
        $variant = (string) ($line['variant_name_snapshot'] ?? '');
        $notes = (string) ($line['notes'] ?? '');
        $isCuttable = !empty($line['is_cuttable_snapshot']);
        $lotAllocations = is_array($line['lot_allocations'] ?? null) ? $line['lot_allocations'] : [];
        $qtyOrPieces = $isCuttable ? ((int) ($line['pieces'] ?? 0)) : ((float) ($line['qty'] ?? 0));
        $length = $isCuttable ? (string) ((float) ($line['length_ft'] ?? 0)) : '';
        if ($isCuttable && $lotAllocations !== []) {
            $qtyOrPieces = 0;
            $parts = [];
            foreach ($lotAllocations as $allocation) {
                if (!is_array($allocation)) {
                    continue;
                }
                $pieceLength = max(0, (float) ($allocation['piece_length_ft'] ?? $allocation['cut_length_ft'] ?? 0));
                $pieces = max(1, (int) ($allocation['pieces'] ?? $allocation['cut_pieces'] ?? 1));
                $totalFt = round($pieceLength * $pieces, 4);
                if ($pieceLength <= 0 || $totalFt <= 0) {
                    continue;
                }
                $qtyOrPieces += $pieces;
                $parts[] = documents_inventory_format_number($pieceLength, 2) . 'ft × ' . $pieces;
            }
            if ($parts !== []) {
                $length = implode(', ', $parts);
            }
        }
        echo '<tr>';
        echo '<td>' . ($idx + 1) . '</td>';
        echo '<td><div><strong>' . htmlspecialchars($component, ENT_QUOTES) . '</strong></div>';
        echo '<div class="muted">' . htmlspecialchars($variant, ENT_QUOTES) . '</div>';
        echo '<div class="muted">' . nl2br(htmlspecialchars($notes, ENT_QUOTES)) . '</div>';
        if ($isCuttable && $lotAllocations !== []) {
            $parts = [];
            foreach ($lotAllocations as $allocation) {
                if (!is_array($allocation)) {
                    continue;
                }
                $lotId = (string) ($allocation['lot_id'] ?? '');
                $usedFt = max(0, (float) ($allocation['cut_length_ft'] ?? 0));
                if ($lotId === '' || $usedFt <= 0) {
                    continue;
                }
                $parts[] = $lotId . ': ' . documents_inventory_format_number($usedFt, 2) . 'ft';
            }
            if ($parts !== []) {
                echo '<div class="muted">From lot: ' . htmlspecialchars(implode(', ', $parts), ENT_QUOTES) . '</div>';
            }
        }
        echo '</td>';
        echo '<td>' . htmlspecialchars((string) ($line['hsn_snapshot'] ?? ''), ENT_QUOTES) . '</td>';
        echo '<td>' . htmlspecialchars((string) $qtyOrPieces, ENT_QUOTES) . '</td>';
        echo '<td>' . htmlspecialchars((string) $length, ENT_QUOTES) . '</td>';
        echo '</tr>';
    }
};
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Delivery Challan <?= htmlspecialchars((string) ($challan['dc_number'] ?: $challan['challan_no']), ENT_QUOTES) ?></title>
<style>
@page { size: A4; margin: 10mm; }
html,body{margin:0;padding:0}body{font-family:Arial,sans-serif;font-size:12px;color:#111}.doc{width:100%}.header{display:flex;justify-content:space-between;gap:14px;border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:10px}.title{text-align:center;font-size:22px;font-weight:700;margin:8px 0}.meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px}.box{border:1px solid #444;padding:8px;min-height:52px}table{width:100%;border-collapse:collapse;margin-bottom:14px}th,td{border:1px solid #444;padding:6px;vertical-align:top}th{background:#f8fafc}.muted{color:#5b6472;font-size:11px}.section-title{font-size:14px;font-weight:700;margin:12px 0 6px}.footer{margin-top:16px;border-top:1px solid #444;padding-top:8px}.sign{display:grid;grid-template-columns:1fr 1fr;gap:28px;margin-top:26px}
</style></head><body><div class="doc">
<div class="header"><div><strong><?= htmlspecialchars((string) ($company['brand_name'] ?: $company['company_name'] ?: 'Dakshayani Enterprises'), ENT_QUOTES) ?></strong><br><?= htmlspecialchars((string) ($company['address_line'] ?? ''), ENT_QUOTES) ?><br><?= htmlspecialchars((string) ($company['phone_primary'] ?? ''), ENT_QUOTES) ?></div>
<div><strong>DC No:</strong> <?= htmlspecialchars((string) ($challan['dc_number'] ?: $challan['challan_no']), ENT_QUOTES) ?><br><strong>Date:</strong> <?= htmlspecialchars((string) ($challan['delivery_date'] ?? ''), ENT_QUOTES) ?><br><strong>Quotation:</strong> <?= htmlspecialchars((string) ($challan['quote_id'] ?: $challan['linked_quote_id']), ENT_QUOTES) ?></div></div>
<div class="title">Delivery Challan</div>
<div class="meta"><div class="box"><strong>Customer</strong><br><?= htmlspecialchars((string) ($challan['customer_name_snapshot'] ?: ($challan['customer_snapshot']['name'] ?? '')), ENT_QUOTES) ?><br><strong>Mobile:</strong> <?= htmlspecialchars((string) ($challan['customer_mobile'] ?: ($challan['customer_snapshot']['mobile'] ?? '')), ENT_QUOTES) ?></div>
<div class="box"><strong>Site/Delivery Address</strong><br><?= nl2br(htmlspecialchars((string) ($challan['site_address_snapshot'] ?: $challan['delivery_address']), ENT_QUOTES)) ?></div></div>

<div class="section-title">Quotation Items</div>
<table><thead><tr><th>Sr.</th><th>Components</th><th>HSN</th><th>Quantity / Pieces</th><th>length</th></tr></thead><tbody><?php $renderRows($quotationLines); ?></tbody></table>

<div class="section-title">Extra items not present in quotation</div>
<table><thead><tr><th>Sr.</th><th>Components</th><th>HSN</th><th>Quantity / Pieces</th><th>length</th></tr></thead><tbody><?php $renderRows($extraLines); ?></tbody></table>

<div class="footer"><div class="sign"><div><strong>Prepared by</strong><br><br><br>_________________________</div><div><strong>Received by</strong><br><br><br>_________________________</div></div></div>
</div></body></html>
