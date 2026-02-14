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
if (is_array($user) && (($user['role_name'] ?? '') === 'admin')) { $viewerType = 'admin'; $viewerId = (string) ($user['id'] ?? ''); }
else { $employee = employee_portal_current_employee($employeeStore); if ($employee !== null) { $viewerType = 'employee'; $viewerId = (string) ($employee['id'] ?? ''); } }
if ($viewerType === '') { header('Location: login.php'); exit; }
$challan = documents_get_challan(safe_text($_GET['id'] ?? ''));
if ($challan === null) { http_response_code(404); echo 'Challan not found.'; exit; }
if ($viewerType === 'employee' && ((string) ($challan['created_by_type'] ?? '') !== 'employee' || (string) ($challan['created_by_id'] ?? '') !== $viewerId)) { http_response_code(403); echo 'Access denied.'; exit; }
$company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
$theme = documents_get_effective_doc_theme((string) ($challan['template_set_id'] ?? ''));
$font = (float) ($theme['font_scale'] ?? 1);
$bg = !empty($theme['enable_background']) ? (string) (($theme['background_media_path'] ?? '') ?: ($challan['rendering']['background_image'] ?? '')) : '';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Delivery Challan <?= htmlspecialchars((string) $challan['challan_no'], ENT_QUOTES) ?></title>
<style>@page{size:A4;margin:14mm}body{font-family:Arial,sans-serif;font-size:<?= 12*$font ?>px;color:<?= htmlspecialchars((string)$theme['text_color'], ENT_QUOTES) ?>} .bg{position:fixed;inset:0;z-index:-1;opacity:.1;background:<?= $bg!=='' ? 'url(' . htmlspecialchars($bg, ENT_QUOTES) . ') center/cover no-repeat' : 'none' ?>}.header{display:flex;justify-content:space-between;border-bottom:2px solid <?= htmlspecialchars((string)$theme['primary_color'], ENT_QUOTES) ?>;padding-bottom:6px;margin-bottom:8px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #444;padding:6px}th{background:<?= htmlspecialchars((string)$theme['box_bg'], ENT_QUOTES) ?>}</style></head><body><?php if($bg!==''): ?><div class="bg"></div><?php endif; ?><div class="header"><div><strong><?= htmlspecialchars((string) ($company['brand_name'] ?: $company['company_name'] ?: 'Dakshayani Enterprises'), ENT_QUOTES) ?></strong></div><div><strong>Challan:</strong> <?= htmlspecialchars((string)$challan['challan_no'], ENT_QUOTES) ?></div></div><table><tr><th>Customer</th><td><?= htmlspecialchars((string)($challan['customer_snapshot']['name'] ?? ''), ENT_QUOTES) ?></td><th>Date</th><td><?= htmlspecialchars((string)$challan['delivery_date'], ENT_QUOTES) ?></td></tr></table><table><thead><tr><th>#</th><th>Name</th><th>Description</th><th>Unit</th><th>Qty</th><th>Remarks</th></tr></thead><tbody><?php foreach (($challan['items'] ?? []) as $i=>$it): ?><tr><td><?= $i+1 ?></td><td><?= htmlspecialchars((string)($it['name'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)($it['description'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)($it['unit'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)($it['qty'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)($it['remarks'] ?? ''), ENT_QUOTES) ?></td></tr><?php endforeach; ?></tbody></table><script>window.print();</script></body></html>
