<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/public_document_security.php';
protect_customer_document_response();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/challan_view_renderer.php';

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
$company = load_company_profile();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow,noarchive,nosnippet"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Delivery Challan <?= htmlspecialchars((string) ($challan['dc_number'] ?: $challan['challan_no']), ENT_QUOTES) ?></title><style>@page{size:A4;margin:10mm}body{font:12px Arial;color:#111}.document-header{display:flex;justify-content:space-between;border-bottom:2px solid #111}.document-title{text-align:right}.company-logo{max-width:120px;max-height:60px}.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:12px 0}table{width:100%;border-collapse:collapse}th,td{border:1px solid #444;padding:6px;text-align:left}footer{text-align:right;margin-top:40px;font-weight:bold}@media print{.actions{display:none}}</style></head><body><p class="actions"><button onclick="print()">Print</button></p><?php render_challan_document($challan, $company, true); ?></body></html>
