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
$globalStyle = documents_get_document_style_settings();
$styleOverride = is_array($quote['style_override'] ?? null) ? $quote['style_override'] : [];
$style = documents_merge_style_with_override($globalStyle, $styleOverride);
$snapshot = documents_quote_resolve_snapshot($quote);
$financial = is_array($quote['financial_inputs'] ?? null) ? $quote['financial_inputs'] : [];

$segmentRateKey = [
    'RES' => 'residential_unit_rate_rs_per_kwh',
    'COM' => 'commercial_unit_rate_rs_per_kwh',
    'IND' => 'industrial_unit_rate_rs_per_kwh',
    'INST' => 'institutional_unit_rate_rs_per_kwh',
];
$segment = (string) ($quote['segment'] ?? 'RES');
$defaultUnitRate = (float) ($style['defaults'][$segmentRateKey[$segment] ?? 'residential_unit_rate_rs_per_kwh'] ?? 7);
$unitRate = (float) (($style['defaults']['unit_rate_rs_per_kwh'] ?? 0) ?: $defaultUnitRate);
$interestRate = (float) ($style['defaults']['default_bank_interest_rate_percent'] ?? 10);
$tenureYears = (int) ($style['defaults']['default_loan_tenure_years'] ?? 10);
$annualGenerationPerKw = (float) (($financial['annual_generation_per_kw'] ?? '') !== '' ? $financial['annual_generation_per_kw'] : ($style['defaults']['default_annual_generation_per_kw'] ?? 1450));
$emissionFactor = (float) (($financial['emission_factor_kg_per_kwh'] ?? '') !== '' ? $financial['emission_factor_kg_per_kwh'] : ($style['defaults']['default_emission_factor_kg_per_kwh'] ?? 0.82));
$treeFactor = (float) (($financial['kg_co2_absorbed_per_tree_per_year'] ?? '') !== '' ? $financial['kg_co2_absorbed_per_tree_per_year'] : ($style['defaults']['kg_co2_absorbed_per_tree_per_year'] ?? 20));

$capacity = (float) ($quote['capacity_kwp'] ?? 0);
$grandTotal = (float) ($quote['calc']['grand_total'] ?? 0);
$subsidy = (float) ($financial['subsidy_expected_rs'] ?? 0);
$monthlyBill = (float) ($financial['estimated_monthly_bill_rs'] ?? 0);
$annualKwh = $capacity * $annualGenerationPerKw;
$annualSavings = $annualKwh * $unitRate;
$upfrontNetCost = max(0, $grandTotal - $subsidy);

$noSolar10y = $monthlyBill * 12 * 10;
$annualBill = $monthlyBill * 12;
$annualAfterSolar = max($annualBill - $annualSavings, 0);
$self10y = $upfrontNetCost + ($annualAfterSolar * 10);

$nMonths = max(1, $tenureYears * 12);
$monthlyRate = ($interestRate / 12) / 100;
$emi = $monthlyRate > 0
    ? ($upfrontNetCost * $monthlyRate * pow(1 + $monthlyRate, $nMonths)) / (pow(1 + $monthlyRate, $nMonths) - 1)
    : ($upfrontNetCost / $nMonths);
$loan10y = ($emi * $nMonths) + ($annualAfterSolar * 10);

$paybackYear = null;
for ($y = 1; $y <= 15; $y++) {
    if (($annualSavings * $y) >= $upfrontNetCost) {
        $paybackYear = $y;
        break;
    }
}

$co2KgYear = $annualKwh * $emissionFactor;
$co2TonYear = $co2KgYear / 1000;
$co2Ton25 = $co2TonYear * 25;
$treesYear = $treeFactor > 0 ? ($co2KgYear / $treeFactor) : 0;
$trees25 = $treesYear * 25;

$safeHtml = static function (string $value): string {
    $clean = strip_tags($value, '<p><br><ul><ol><li><strong><em><b><i><u><table><thead><tbody><tr><td><th>');
    return $clean !== '' ? $clean : '<span style="color:#666">—</span>';
};
$bgImg = (string) ($style['layout']['page_background_image'] ?? '');
$bgEnabled = !empty($style['layout']['page_background_enabled']);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Print <?= htmlspecialchars((string) $quote['quote_no'], ENT_QUOTES) ?></title>
<style>
@page { size: A4; margin: 10mm; }
:root{--primary:<?= htmlspecialchars((string)$style['theme']['primary_color'], ENT_QUOTES) ?>;--accent:<?= htmlspecialchars((string)$style['theme']['accent_color'], ENT_QUOTES) ?>;--text:<?= htmlspecialchars((string)$style['theme']['text_color'], ENT_QUOTES) ?>;--muted:<?= htmlspecialchars((string)$style['theme']['muted_text_color'], ENT_QUOTES) ?>;--card:<?= htmlspecialchars((string)$style['theme']['card_bg'], ENT_QUOTES) ?>;--bg:<?= htmlspecialchars((string)$style['theme']['bg_color'], ENT_QUOTES) ?>;--border:<?= htmlspecialchars((string)$style['theme']['border_color'], ENT_QUOTES) ?>;--base:<?= (int)$style['typography']['base_font_size_px'] ?>px;}
*{box-sizing:border-box}body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:var(--base);line-height:<?= htmlspecialchars((string)$style['typography']['line_height'], ENT_QUOTES) ?>;color:var(--text);margin:0;background:var(--bg)}
.page{position:relative;padding:8px} .bg{position:fixed;inset:0;z-index:-1;background:url('<?= htmlspecialchars($bgImg, ENT_QUOTES) ?>') center/cover no-repeat;opacity:<?= htmlspecialchars((string)$style['layout']['page_background_opacity'], ENT_QUOTES) ?>}
.hero{background:linear-gradient(130deg,var(--primary),var(--accent));padding:18px;border-radius:16px;color:#fff;display:flex;justify-content:space-between;gap:10px}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:12px;margin-top:10px;break-inside:avoid}
h1{font-size:<?= (int)$style['typography']['h1_px'] ?>px;margin:0}h2{font-size:<?= (int)$style['typography']['h2_px'] ?>px;margin:0 0 8px}h3{font-size:<?= (int)$style['typography']['h3_px'] ?>px;margin:0 0 6px}.muted{color:var(--muted)}
.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.pill{padding:8px;border-radius:10px;background:#fff;border:1px solid var(--border)}
table{width:100%;border-collapse:collapse}th,td{border:1px solid var(--border);padding:8px}th{background:#fff}
.highlight{background:#fff7ed;border:1px solid #fb923c;padding:10px;border-radius:10px}
.timeline{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.step{background:#fff;padding:10px;border-radius:10px;border:1px dashed var(--primary);text-align:center}
canvas{width:100%;max-width:100%;height:220px;background:#fff;border:1px solid var(--border);border-radius:10px}
@media print{.noprint{display:none}}
</style></head><body><div class="page"><?php if ($bgEnabled && $bgImg !== ''): ?><div class="bg"></div><?php endif; ?>

<?php if (!empty($style['layout']['show_cover_page'])): ?>
<div class="hero">
  <div><h1>☀️ Solar Quotation / Proposal</h1><div><?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?> · <?= htmlspecialchars((string)$quote['created_at'], ENT_QUOTES) ?></div></div>
  <div style="text-align:right"><?php if ((string)($company['logo_path'] ?? '') !== ''): ?><img src="<?= htmlspecialchars((string)$company['logo_path'], ENT_QUOTES) ?>" style="max-height:56px"><?php endif; ?><div><?= htmlspecialchars((string)($company['brand_name'] ?: $company['company_name']), ENT_QUOTES) ?></div></div>
</div>
<div class="card"><strong>Customer:</strong> <?= htmlspecialchars((string)($snapshot['name'] ?: $quote['customer_name']), ENT_QUOTES) ?> · <?= htmlspecialchars((string)($snapshot['mobile'] ?: $quote['customer_mobile']), ENT_QUOTES) ?><br><strong>Site:</strong> <?= nl2br(htmlspecialchars((string)($quote['site_address'] ?: $snapshot['address']), ENT_QUOTES)) ?></div>
<?php endif; ?>

<div class="card"><h2>🔎 Your System at a Glance</h2><div class="grid"><div class="pill">⚡ <?= htmlspecialchars((string)$quote['system_type'], ENT_QUOTES) ?></div><div class="pill">🔋 <?= htmlspecialchars((string)$quote['capacity_kwp'], ENT_QUOTES) ?> kWp</div><div class="pill">🛡️ Warranty Included</div><div class="pill">☀️ <?= number_format($annualKwh, 0) ?> kWh/year</div></div></div>

<div class="card"><h2>💰 Pricing Summary</h2><table><tr><th>Final Price (GST)</th><td>₹<?= number_format($grandTotal,2) ?></td><th>Subsidy Expected</th><td>₹<?= number_format($subsidy,2) ?></td></tr><tr><th>Net Cost After Subsidy</th><td colspan="3">₹<?= number_format($upfrontNetCost,2) ?></td></tr></table></div>

<div class="card"><h2>📈 Savings & Payback</h2><div class="muted">Assumptions: monthly bill ₹<?= number_format($monthlyBill,2) ?>, unit rate ₹<?= number_format($unitRate,2) ?>/kWh, tenure <?= $tenureYears ?>y, interest <?= number_format($interestRate,2) ?>%.</div><canvas id="bars"></canvas><div>No Solar (10y): ₹<?= number_format($noSolar10y,2) ?> · Solar-EMI (10y): ₹<?= number_format($loan10y,2) ?> · Solar-Self (10y): ₹<?= number_format($self10y,2) ?></div><br><canvas id="line"></canvas><div><strong>Estimated payback:</strong> <?= $paybackYear === null ? '&gt; 15 years' : $paybackYear . ' years' ?></div></div>

<div class="card"><h2>🌿 Environmental Impact</h2><div class="grid"><div class="pill">🌿 CO₂/year: <strong><?= number_format($co2TonYear,2) ?> tons</strong></div><div class="pill">🌳 Trees/year: <strong><?= number_format($treesYear,0) ?></strong></div><div class="pill">🌿 CO₂/25y: <strong><?= number_format($co2Ton25,2) ?> tons</strong></div><div class="pill">🌳 Trees/25y: <strong><?= number_format($trees25,0) ?></strong></div></div></div>

<div class="card"><h2>🧰 Equipment & Installation</h2><div><?= $safeHtml((string)($quote['annexures_overrides']['system_inclusions'] ?? '')) ?></div></div>
<div class="card"><h2>🗓️ Project Timeline</h2><div class="timeline"><div class="step">1️⃣ Application</div><div class="step">2️⃣ Installation</div><div class="step">3️⃣ Inspection + Net-meter</div><div class="step">4️⃣ Commissioning</div></div></div>
<div class="card"><h2>✅ Terms & Next Steps</h2><ul><li>✅ Review quotation</li><li>✅ Confirm capacity and site readiness</li><li>✅ Pay booking amount</li><li>✅ Schedule installation</li></ul></div>
<div class="card highlight"><h3>Special Requests From Customer (Inclusive in the rate)</h3><div><?= nl2br(htmlspecialchars((string)$quote['special_requests_inclusive'], ENT_QUOTES)) ?></div><div><em>In case of conflict between Annexure inclusions and Special Requests, Special Requests will be given priority.</em></div></div>
<?php foreach (['payment_terms'=>'Payment Terms','warranty'=>'Warranty','transportation'=>'Transportation','system_type_explainer'=>'System Type Explainer','terms_conditions'=>'Terms & Conditions'] as $k=>$heading): ?><div class="card"><h3><?= $heading ?></h3><div><?= $safeHtml((string)($quote['annexures_overrides'][$k] ?? '')) ?></div></div><?php endforeach; ?>

</div>
<script>
(function(){
const bars=document.getElementById('bars');if(bars){const c=bars.getContext('2d');const vals=[<?= json_encode(round($noSolar10y,2)) ?>,<?= json_encode(round($loan10y,2)) ?>,<?= json_encode(round($self10y,2)) ?>];const labels=['No Solar','Solar EMI','Solar Self'];const w=bars.width=bars.clientWidth*2,h=bars.height=220*2;c.scale(2,2);const max=Math.max(...vals,1);vals.forEach((v,i)=>{const bw=80,g=40,x=30+i*(bw+g),bh=(v/max)*140;c.fillStyle=['#ef4444','#2563eb','#16a34a'][i];c.fillRect(x,180-bh,bw,bh);c.fillStyle='#111';c.fillText(labels[i],x,200);});}
const line=document.getElementById('line');if(line){const c=line.getContext('2d');const w=line.width=line.clientWidth*2,h=line.height=220*2;c.scale(2,2);const net=<?= json_encode(round($upfrontNetCost,2)) ?>,annual=<?= json_encode(round($annualSavings,2)) ?>;c.strokeStyle='#999';c.beginPath();c.moveTo(20,20);c.lineTo(20,190);c.lineTo(520,190);c.stroke();let max=Math.max(net,annual*15,1);c.strokeStyle='#10b981';c.beginPath();for(let y=0;y<=15;y++){let x=20+y*30,val=annual*y,py=190-(val/max)*160;if(y===0)c.moveTo(x,py);else c.lineTo(x,py);}c.stroke();c.strokeStyle='#ef4444';let netY=190-(net/max)*160;c.beginPath();c.moveTo(20,netY);c.lineTo(500,netY);c.stroke();}
})();
window.onload=function(){if(location.search.indexOf('autoprint=1')!==-1){window.print();}};
</script>
</body></html>
