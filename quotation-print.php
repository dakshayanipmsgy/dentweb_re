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
$snapshot = documents_quote_resolve_snapshot($quote);
$theme = documents_get_effective_doc_theme((string) ($quote['template_set_id'] ?? ''));
$assumptions = array_merge(documents_quote_assumptions_defaults(), is_array($quote['assumptions'] ?? null) ? $quote['assumptions'] : []);
$fontScale = (float) ($theme['font_scale'] ?? 1);
$showBackground = !empty($theme['enable_background']);
$background = $showBackground ? (string) (($theme['background_media_path'] ?? '') ?: ($quote['rendering']['background_image'] ?? '')) : '';
$annex = is_array($quote['annexures_overrides'] ?? null) ? $quote['annexures_overrides'] : [];

$f = static fn(string $k): float => (float) ($assumptions[$k] ?? 0);
$total = (float) ($quote['calc']['grand_total'] ?? 0);
$tariff = $f('tariff_rs_per_unit');
$units = $f('avg_monthly_units');
$bill = $f('avg_monthly_bill_rs');
if ($units <= 0 && $bill > 0 && $tariff > 0) { $units = $bill / $tariff; }
if ($bill <= 0 && $units > 0 && $tariff > 0) { $bill = $units * $tariff; }
$loanSelected = strtolower((string) ($assumptions['loan_selected'] ?? '')) === 'yes';
$emi = 0.0;
$emiReady = false;
if ($loanSelected && $total > 0 && $f('loan_interest_pct_annual') > 0 && $f('loan_tenure_months') > 0) {
    $p = $total * (1 - ($f('down_payment_pct') / 100));
    $r = ($f('loan_interest_pct_annual') / 12) / 100;
    $n = (int) $f('loan_tenure_months');
    if ($p > 0 && $r > 0 && $n > 0) {
        $pow = pow(1 + $r, $n);
        $emi = $p * $r * $pow / ($pow - 1);
        $emiReady = is_finite($emi) && $emi > 0;
    }
}
$annualSavings = ($units > 0 && $tariff > 0) ? ($units * $tariff * 12) : 0;
$subsidy = $f('expected_subsidy_rs');
$netCostAfterSubsidy = max(0, $total - $subsidy);
$paybackYears = $annualSavings > 0 ? $total / $annualSavings : 0;
$paybackAfterSubsidy = ($annualSavings > 0 && $subsidy > 0) ? $netCostAfterSubsidy / $annualSavings : 0;
$gen = $f('expected_annual_generation_kwh');
if ($gen <= 0 && $f('generation_kwh_per_kwp_per_year') > 0 && (float) ($quote['capacity_kwp'] ?? 0) > 0) {
    $gen = $f('generation_kwh_per_kwp_per_year') * (float) $quote['capacity_kwp'];
}
$co2Factor = $f('co2_factor_kg_per_kwh') ?: (float) ($theme['co2_factor_kg_per_kwh'] ?? 0);
$treeFactor = $f('trees_factor_kg_per_tree_per_year') ?: (float) ($theme['trees_factor_kg_per_tree_per_year'] ?? 0);
$co2 = ($gen > 0 && $co2Factor > 0) ? ($gen * $co2Factor) : 0;
$trees = ($co2 > 0 && $treeFactor > 0) ? ($co2 / $treeFactor) : 0;

$chartSvg = '';
if ($loanSelected && $emiReady && $bill > 0) {
    $max = max($emi, $bill, $f('post_solar_bill_rs'));
    $v1 = (int) round(($emi / $max) * 180);
    $v2 = (int) round(($bill / $max) * 180);
    $post = $f('post_solar_bill_rs');
    $v3 = $post > 0 ? (int) round(($post / $max) * 180) : 0;
    $chartSvg = '<svg viewBox="0 0 500 240" width="100%"><rect x="90" y="30" width="80" height="'.$v1.'" y="'.(210-$v1).'" fill="#1F7A6B"/><rect x="220" y="30" width="80" height="'.$v2.'" y="'.(210-$v2).'" fill="#0B3A6A"/>' . ($v3>0?'<rect x="350" y="30" width="80" height="'.$v3.'" y="'.(210-$v3).'" fill="#F2B705"/>':'') . '<text x="92" y="225" font-size="12">EMI</text><text x="216" y="225" font-size="12">Current Bill</text>'.($v3>0?'<text x="332" y="225" font-size="12">Post-Solar Bill</text>':'').'</svg>';
} elseif (!$loanSelected && $annualSavings > 0) {
    $years = min(8, max(3, (int) ceil($paybackYears + 1)));
    $bars = '';
    $max = max($total, $netCostAfterSubsidy, $annualSavings * $years);
    for ($i=1;$i<=$years;$i++) {
        $cum = $annualSavings * $i;
        $h = (int) round(($cum/$max)*170);
        $x = 40 + ($i-1)*50;
        $bars .= '<rect x="'.$x.'" y="'.(200-$h).'" width="30" height="'.$h.'" fill="#1F7A6B"/><text x="'.$x.'" y="215" font-size="10">Y'.$i.'</text>';
    }
    $chartSvg = '<svg viewBox="0 0 500 230" width="100%">'.$bars.'<line x1="30" y1="200" x2="480" y2="200" stroke="#999"/></svg>';
}
$showFinancial = $chartSvg !== '';
$showImpact = $co2 > 0 || $trees > 0;

$safeHtml = static fn(string $v): string => strip_tags($v, '<p><br><ul><ol><li><strong><em><b><i><u><table><thead><tbody><tr><td><th>');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Quotation <?= htmlspecialchars((string) $quote['quote_no'], ENT_QUOTES) ?></title>
<style>
@page{size:A4;margin:10mm} body{font-family:Arial,sans-serif;color:<?= htmlspecialchars((string)$theme['text_color'], ENT_QUOTES) ?>;font-size:<?= 13*$fontScale ?>px;margin:0}
.page{min-height:270mm;page-break-after:always;position:relative;padding:12mm;background:#fff}.page:last-child{page-break-after:auto}
.bg{position:absolute;inset:0;z-index:0;opacity:.14;background:url('<?= htmlspecialchars($background, ENT_QUOTES) ?>') center/cover no-repeat}.content{position:relative;z-index:1}
.h1{font-size:<?= 30*$fontScale ?>px;margin:0}.h2{font-size:<?= 22*$fontScale ?>px;margin:0 0 8px;color:<?= htmlspecialchars((string)$theme['primary_color'], ENT_QUOTES) ?>}
.card{background:<?= htmlspecialchars((string)$theme['box_bg'], ENT_QUOTES) ?>;border-radius:12px;padding:10px;margin:8px 0}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
table{width:100%;border-collapse:collapse}th,td{border:1px solid #d0d7e2;padding:6px}th{background:<?= htmlspecialchars((string)$theme['primary_color'], ENT_QUOTES) ?>;color:#fff}
.timeline li{margin:10px 0}
</style></head><body>
<?php $showBg = $background !== ''; $pageToggles = $theme; ?>
<?php if (($pageToggles['show_cover_page'] ?? true)): ?><section class="page"><?php if ($showBg): ?><div class="bg"></div><?php endif; ?><div class="content"><h1 class="h1" style="color:<?= htmlspecialchars((string)$theme['primary_color'], ENT_QUOTES) ?>">Solar Energy Proposal</h1><p>PM Surya Ghar: Muft Bijli Yojana – Rooftop Solar</p><div class="card"><strong><?= htmlspecialchars((string)($snapshot['name'] ?: $quote['customer_name']), ENT_QUOTES) ?></strong><br><?= htmlspecialchars((string)($snapshot['mobile'] ?: $quote['customer_mobile']), ENT_QUOTES) ?><br><?= htmlspecialchars((string)($quote['district'] ?: $snapshot['district']), ENT_QUOTES) ?></div><p>Quote: <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?> | Date: <?= htmlspecialchars(substr((string)$quote['created_at'],0,10), ENT_QUOTES) ?> | Valid: <?= htmlspecialchars((string)$quote['valid_until'], ENT_QUOTES) ?></p></div></section><?php endif; ?>

<?php if (($pageToggles['show_system_overview_page'] ?? true)): ?><section class="page"><?php if ($showBg): ?><div class="bg"></div><?php endif; ?><div class="content"><h2 class="h2">Your Solar System</h2><div class="grid"><div class="card"><strong>Capacity</strong><br><?= htmlspecialchars((string)$quote['capacity_kwp'], ENT_QUOTES) ?> kWp</div><div class="card"><strong>System Type</strong><br><?= htmlspecialchars((string)$quote['system_type'], ENT_QUOTES) ?></div><div class="card"><strong>Warranty</strong><br><?= $safeHtml((string)($annex['warranty'] ?? 'As per selected package.')) ?></div></div><p>What you get: Detailed inclusions are in Annexure.</p></div></section><?php endif; ?>

<section class="page"><?php if ($showBg): ?><div class="bg"></div><?php endif; ?><div class="content"><h2 class="h2">Pricing Summary</h2><table><thead><tr><th>Description</th><th>Basic</th><th>GST</th><th>Total</th></tr></thead><tbody><tr><td>Solar (5%)</td><td><?= number_format((float)$quote['calc']['bucket_5_basic'],2) ?></td><td><?= number_format((float)$quote['calc']['bucket_5_gst'],2) ?></td><td><?= number_format((float)$quote['calc']['bucket_5_basic'] + (float)$quote['calc']['bucket_5_gst'],2) ?></td></tr><tr><td>Solar (18%)</td><td><?= number_format((float)$quote['calc']['bucket_18_basic'],2) ?></td><td><?= number_format((float)$quote['calc']['bucket_18_gst'],2) ?></td><td><?= number_format((float)$quote['calc']['bucket_18_basic'] + (float)$quote['calc']['bucket_18_gst'],2) ?></td></tr></tbody></table><div class="card"><strong>Total (GST inclusive): ₹<?= number_format($total,2) ?></strong></div><div class="card"><strong>Payment Milestones:</strong><div><?= $safeHtml((string)($annex['payment_terms'] ?? '')) ?></div></div><?php if (trim((string)($annex['pm_subsidy_info'] ?? '')) !== ''): ?><div class="card"><strong>Subsidy Note:</strong> <?= $safeHtml((string)$annex['pm_subsidy_info']) ?></div><?php endif; ?></div></section>

<?php if (($pageToggles['show_financials_page'] ?? true) && $showFinancial): ?><section class="page"><div class="content"><h2 class="h2">Financial Comparison</h2><?= $chartSvg ?><?php if($loanSelected): ?><p>Estimated EMI: ₹<?= number_format($emi,2) ?><?php if($bill>0): ?> vs current bill ₹<?= number_format($bill,2) ?><?php endif; ?></p><?php else: ?><p>Estimated payback: <?= number_format($paybackYears,1) ?> years<?php if($subsidy>0): ?> (after subsidy: <?= number_format($paybackAfterSubsidy,1) ?> years)<?php endif; ?></p><?php endif; ?></div></section><?php endif; ?>

<?php if (($pageToggles['show_impact_page'] ?? true) && $showImpact): ?><section class="page"><div class="content"><h2 class="h2">Impact Metrics</h2><div class="grid"><div class="card"><strong>Annual Generation</strong><br><?= number_format($gen,0) ?> kWh</div><div class="card"><strong>CO₂ Offset</strong><br><?= number_format($co2,0) ?> kg/year</div><div class="card"><strong>Trees Equivalent</strong><br><?= number_format($trees,0) ?> /year</div></div></div></section><?php endif; ?>

<?php if (($pageToggles['show_next_steps_page'] ?? true)): ?><section class="page"><div class="content"><h2 class="h2">Project Timeline / Next Steps</h2><ol class="timeline"><li>Agreement & Permitting</li><li>Installation</li><li>Inspection & Net Meter</li><li>Activation / Commissioning</li></ol></div></section><?php endif; ?>
<?php if (($pageToggles['show_contact_page'] ?? true)): ?><section class="page"><div class="content"><h2 class="h2">Contact & Support</h2><p><strong><?= htmlspecialchars((string)($company['brand_name'] ?: $company['company_name']), ENT_QUOTES) ?></strong></p><p><?= htmlspecialchars((string)($company['phone_primary'] ?? ''), ENT_QUOTES) ?> | <?= htmlspecialchars((string)($company['email'] ?? ''), ENT_QUOTES) ?><br><?= htmlspecialchars((string)($company['website'] ?? ''), ENT_QUOTES) ?><br><?= htmlspecialchars((string)($company['address_line'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars((string)($company['city'] ?? ''), ENT_QUOTES) ?></p></div></section><?php endif; ?>

<section class="page"><div class="content"><h2 class="h2">Annexure</h2><?php foreach (['system_inclusions'=>'System Inclusions','warranty'=>'Warranty','system_type_explainer'=>'System Type','transportation'=>'Transportation','terms_conditions'=>'Terms'] as $k=>$label): ?><div class="card"><strong><?= htmlspecialchars($label, ENT_QUOTES) ?></strong><div><?= $safeHtml((string)($annex[$k] ?? '')) ?></div></div><?php endforeach; ?><div class="card"><strong>Special Requests From Customer (Inclusive in the rate)</strong><div><?= nl2br(htmlspecialchars((string)$quote['special_requests_inclusive'], ENT_QUOTES)) ?></div><em>In case of conflict between Annexure inclusions and Special Requests, Special Requests will be given priority.</em></div></div></section>

<script>window.onload=function(){if(location.search.indexOf('autoprint=1')!==-1){window.print();}}</script>
</body></html>
