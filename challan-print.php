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
if ($viewerType === 'employee' && ((string) ($challan['created_by_type'] ?? '') !== 'employee' || (string) ($challan['created_by_id'] ?? '') !== $viewerId)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
$resolvedTheme = documents_resolve_rendering_theme(is_array($challan['rendering'] ?? null) ? $challan['rendering'] : []);
$bg = (string) $resolvedTheme['background_image'];
$opacity = (float) $resolvedTheme['background_opacity'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Delivery Challan <?= htmlspecialchars((string) $challan['challan_no'], ENT_QUOTES) ?></title>
<style>
@page { size:A4; margin:14mm; }
body { font-family: <?= htmlspecialchars((string) $resolvedTheme['font_family'], ENT_QUOTES) ?>; font-size:<?= (int) $resolvedTheme['base_font_px'] ?>px; color:#111; }
.page-bg { position:fixed; inset:0; z-index:-1; opacity:<?= htmlspecialchars((string) $opacity, ENT_QUOTES) ?>; background: <?= $bg !== '' ? 'url(' . htmlspecialchars($bg, ENT_QUOTES) . ') center/cover no-repeat' : 'none' ?>; }
.header { display:flex; justify-content:space-between; border-bottom:2px solid #111; margin-bottom:10px; padding-bottom:8px; }
.title { font-size:24px; font-weight:700; margin:8px 0; text-align:center; }
.grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.box { border:1px solid #444; padding:8px; min-height:42px; }
table { width:100%; border-collapse:collapse; margin-top:10px; }
th,td { border:1px solid #444; padding:6px; vertical-align:top; }
.sign { margin-top:40px; display:grid; grid-template-columns:1fr 1fr; gap:24px; }
</style></head><body>
<?php if (!empty($resolvedTheme['background_enabled']) && $bg !== ''): ?><div class="page-bg"></div><?php endif; ?>
<div class="header"><div><strong><?= htmlspecialchars((string) ($company['brand_name'] ?: $company['company_name'] ?: 'Dakshayani Enterprises'), ENT_QUOTES) ?></strong><br><?= htmlspecialchars((string) ($company['address_line'] ?? ''), ENT_QUOTES) ?><br><?= htmlspecialchars((string) ($company['phone_primary'] ?? ''), ENT_QUOTES) ?></div><div><strong>Challan No:</strong> <?= htmlspecialchars((string) $challan['challan_no'], ENT_QUOTES) ?><br><strong>Date:</strong> <?= htmlspecialchars((string) $challan['delivery_date'], ENT_QUOTES) ?></div></div>
<div class="title">Delivery Challan</div>
<div class="grid"><div class="box"><strong>Customer:</strong> <?= htmlspecialchars((string) ($challan['customer_snapshot']['name'] ?? ''), ENT_QUOTES) ?><br><strong>Mobile:</strong> <?= htmlspecialchars((string) ($challan['customer_snapshot']['mobile'] ?? ''), ENT_QUOTES) ?><br><strong>Consumer Account No:</strong> <?= htmlspecialchars((string) ($challan['customer_snapshot']['consumer_account_no'] ?? ''), ENT_QUOTES) ?></div><div class="box"><strong>Delivery Address:</strong><br><?= nl2br(htmlspecialchars((string) $challan['delivery_address'], ENT_QUOTES)) ?><br><strong>Vehicle No:</strong> <?= htmlspecialchars((string) $challan['vehicle_no'], ENT_QUOTES) ?><br><strong>Driver:</strong> <?= htmlspecialchars((string) $challan['driver_name'], ENT_QUOTES) ?></div></div>
<table><thead><tr><th>Sr</th><th>Item name + description</th><th>Unit</th><th>Qty</th><th>Remarks</th></tr></thead><tbody><?php foreach ($challan['items'] as $i => $it): ?><tr><td><?= $i + 1 ?></td><td><strong><?= htmlspecialchars((string) ($it['name'] ?? ''), ENT_QUOTES) ?></strong><br><?= htmlspecialchars((string) ($it['description'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($it['unit'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($it['qty'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($it['remarks'] ?? ''), ENT_QUOTES) ?></td></tr><?php endforeach; if ($challan['items']===[]): ?><tr><td colspan="5">No items listed.</td></tr><?php endif; ?></tbody></table>
<?php if ((string) ($challan['delivery_notes'] ?? '') !== ''): ?><p><strong>Notes:</strong> <?= nl2br(htmlspecialchars((string) $challan['delivery_notes'], ENT_QUOTES)) ?></p><?php endif; ?>
<div class="sign"><div><strong>Delivered by (Dakshayani)</strong><br><br>_______________________</div><div><strong>Received by (Customer)</strong><br><br>_______________________</div></div>
<?php if (!isset($_GET['pdf'])): ?><script>window.print();</script><?php endif; ?>
</body></html>
