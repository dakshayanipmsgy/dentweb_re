<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
documents_ensure_structure();
$employeeStore = new EmployeeFsStore();
$viewerType='';$viewerId='';$user=current_user();
if (is_array($user) && (($user['role_name'] ?? '') === 'admin')) {$viewerType='admin';$viewerId=(string)($user['id']??'');} else {$employee=employee_portal_current_employee($employeeStore); if($employee!==null){$viewerType='employee';$viewerId=(string)($employee['id']??'');}}
if($viewerType===''){header('Location: login.php');exit;}
$challan=documents_get_challan(safe_text($_GET['id'] ?? '')); if($challan===null){http_response_code(404);echo 'Challan not found.';exit;}
if ($viewerType === 'employee' && ((string) ($challan['created_by_type'] ?? '') !== 'employee' || (string) ($challan['created_by_id'] ?? '') !== $viewerId)) {http_response_code(403);echo 'Access denied.';exit;}
$company=array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
$appearance = documents_load_document_appearance();
$fontScale = max(0.8, min(1.3, (float) ($appearance['global']['font_scale'] ?? 1)));
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Challan <?= htmlspecialchars((string)$challan['challan_no'], ENT_QUOTES) ?></title>
<style>html{font-size:calc(16px * <?= htmlspecialchars((string)$fontScale, ENT_QUOTES) ?>)}body{font-family:Arial,sans-serif;color:#111;padding:14px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #333;padding:6px}.top{display:flex;justify-content:space-between;border-bottom:2px solid #111;margin-bottom:10px;padding-bottom:8px}@media print{.print-watermark{display:block;position:fixed;inset:0;z-index:0;background-image:url('<?= htmlspecialchars((string)($appearance['print_watermark']['image_path'] ?? ''), ENT_QUOTES) ?>');background-position:center;background-repeat:<?= htmlspecialchars((string)($appearance['print_watermark']['repeat'] ?? 'no-repeat'), ENT_QUOTES) ?>;background-size:<?= (int)($appearance['print_watermark']['size_percent'] ?? 70) ?>%;opacity:<?= htmlspecialchars((string)($appearance['print_watermark']['opacity'] ?? 0.08), ENT_QUOTES) ?>}body>*{position:relative;z-index:1}}</style>
</head><body><div class="print-watermark"></div><div class="top"><div><strong><?= htmlspecialchars((string)($company['brand_name'] ?: $company['company_name']), ENT_QUOTES) ?></strong></div><div><?= htmlspecialchars((string)$challan['challan_no'], ENT_QUOTES) ?></div></div>
<table><thead><tr><th>Sr</th><th>Item</th><th>Unit</th><th>Qty</th><th>Remarks</th></tr></thead><tbody><?php foreach (($challan['items'] ?? []) as $i=>$it): ?><tr><td><?= $i+1 ?></td><td><?= htmlspecialchars((string)($it['name'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)($it['unit'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)($it['qty'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)($it['remarks'] ?? ''), ENT_QUOTES) ?></td></tr><?php endforeach; ?></tbody></table></body></html>
