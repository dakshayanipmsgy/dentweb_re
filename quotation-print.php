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

$capacity = (float) ($quote['capacity_kwp'] ?? 0);
$quoteTotalCost = (float) ($quote['input_total_gst_inclusive'] ?? ($quote['calc']['grand_total'] ?? 0));
$grandTotal = $quoteTotalCost;
$financialSubsidy = (float) ($financial['subsidy_expected_rs'] ?? 0);
$rootSubsidy = (float) ($quote['subsidy_expected_rs'] ?? ($quote['subsidy_amount_rs'] ?? 0));
$subsidy = $financialSubsidy > 0 ? $financialSubsidy : $rootSubsidy;
$monthlyBill = (float) ($financial['estimated_monthly_bill_rs'] ?? 0);

$unitRate = (float) (($financial['unit_rate_rs_per_kwh'] ?? '') !== '' ? $financial['unit_rate_rs_per_kwh'] : (($style['defaults']['unit_rate_rs_per_kwh'] ?? 0) ?: $defaultUnitRate));
$interestRate = (float) (($financial['interest_rate_percent'] ?? '') !== '' ? $financial['interest_rate_percent'] : ($style['defaults']['default_bank_interest_rate_percent'] ?? 10));
$tenureYears = (int) (($financial['loan_tenure_years'] ?? '') !== '' ? $financial['loan_tenure_years'] : ($style['defaults']['default_loan_tenure_years'] ?? 10));
$annualGenerationPerKw = (float) (($financial['annual_generation_per_kw'] ?? '') !== '' ? $financial['annual_generation_per_kw'] : ($style['defaults']['default_annual_generation_per_kw'] ?? 1450));
$minMonthlyBillAfterSolar = (float) (($financial['min_monthly_bill_after_solar_rs'] ?? '') !== '' ? $financial['min_monthly_bill_after_solar_rs'] : 300);
$analysisMode = (string) ($financial['analysis_mode'] ?? 'simple_monthly');
if (!in_array($analysisMode, ['simple_monthly', 'advanced_10y_monthly'], true)) {
    $analysisMode = 'simple_monthly';
}
$yearsForCumulative = (int) ($financial['years_for_cumulative_chart'] ?? 25);
if ($yearsForCumulative < 1) {
    $yearsForCumulative = 25;
}

$annualBill = $monthlyBill * 12;
$annualKwh = $capacity * $annualGenerationPerKw;
$annualSavings = $annualKwh * $unitRate;
$residualBill = max($monthlyBill - ($annualSavings / 12), $minMonthlyBillAfterSolar);
$netProjectCost = max(0, $quoteTotalCost - $subsidy);
$upfrontNetCost = $netProjectCost;

$nMonths = max(1, $tenureYears * 12);
$monthlyRate = ($interestRate / 100) / 12;
$emi = $monthlyRate > 0
    ? ($netProjectCost * $monthlyRate * pow(1 + $monthlyRate, $nMonths)) / (pow(1 + $monthlyRate, $nMonths) - 1)
    : ($netProjectCost / $nMonths);

$case1Outflow = $monthlyBill;
$case2Outflow = $emi + $residualBill;
$case3Outflow = $minMonthlyBillAfterSolar;
$graphsEnabled = $monthlyBill > 0;

$annualSavingsEffective = max(min($annualSavings, $annualBill - ($minMonthlyBillAfterSolar * 12)), 0);
$paybackYears = $annualSavingsEffective > 0 ? ($netProjectCost / $annualSavingsEffective) : null;

$emissionFactor = (float) (($financial['emission_factor_kg_per_kwh'] ?? '') !== '' ? $financial['emission_factor_kg_per_kwh'] : ($style['defaults']['default_emission_factor_kg_per_kwh'] ?? 0.82));
$treeFactor = (float) (($financial['kg_co2_absorbed_per_tree_per_year'] ?? '') !== '' ? $financial['kg_co2_absorbed_per_tree_per_year'] : ($style['defaults']['kg_co2_absorbed_per_tree_per_year'] ?? 20));

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
canvas{width:100%;max-width:700px;height:260px;background:#fff;border:1px solid var(--border);border-radius:10px;display:block}.stat-chips{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin:10px 0}.stat-chip{background:#fff;border:1px solid var(--border);border-radius:10px;padding:10px;font-weight:600}.payback-table td,.payback-table th{border:1px solid var(--border);padding:8px}
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

<div class="card"><h2>📈 Savings & Payback</h2>
<div class="stat-chips"><div class="stat-chip">Monthly Bill: ₹<?= number_format($case1Outflow,0) ?></div><div class="stat-chip">EMI: ₹<?= number_format($emi,0) ?></div><div class="stat-chip">After Loan: ₹<?= number_format($case3Outflow,0) ?>/month</div><div class="stat-chip">Estimated annual savings: ₹<?= number_format($annualSavings,0) ?></div></div>
<?php if (!$graphsEnabled): ?>
<div class="muted">Enter monthly bill to unlock savings graphs.</div>
<?php else: ?>
<h3>📉 Monthly Outflow</h3><div class="muted">Without solar you pay the bill forever. With solar, EMI replaces bill—and after loan it drops to minimum.</div><label class="muted" style="display:block;margin-bottom:6px"><input id="advancedToggle" type="checkbox" <?= $analysisMode === 'advanced_10y_monthly' ? 'checked' : '' ?>> Show advanced (Year 1–10)</label><canvas id="monthlyOutflowChart"></canvas><div id="monthlyModeText" class="muted"></div><div class="muted">Minimum bill shown as ₹<?= number_format($minMonthlyBillAfterSolar,0) ?> (typical fixed charges).</div>
<h3>📈 Lifetime Cost</h3><canvas id="lifetimeCostChart"></canvas><div class="muted">Solar savings assumed from estimated bill and generation.</div>
<h3>✅ Payback Snapshot</h3>
<table class="payback-table" style="width:100%;border-collapse:collapse;background:#fff"><tr><th>System cost (net after subsidy)</th><td>₹<?= number_format($netProjectCost,2) ?></td></tr><tr><th>Estimated annual savings</th><td>₹<?= number_format($annualSavingsEffective,2) ?></td></tr><tr><th>Payback period (years)</th><td><?= $paybackYears === null ? 'N/A' : number_format($paybackYears,1) ?></td></tr></table>
<?php endif; ?>
</div>

<div class="card"><h2>🌿 Environmental Impact</h2><div class="grid"><div class="pill">🌿 CO₂/year: <strong><?= number_format($co2TonYear,2) ?> tons</strong></div><div class="pill">🌳 Trees/year: <strong><?= number_format($treesYear,0) ?></strong></div><div class="pill">🌿 CO₂/25y: <strong><?= number_format($co2Ton25,2) ?> tons</strong></div><div class="pill">🌳 Trees/25y: <strong><?= number_format($trees25,0) ?></strong></div></div></div>

<div class="card"><h2>🧰 Equipment & Installation</h2><div><?= $safeHtml((string)($quote['annexures_overrides']['system_inclusions'] ?? '')) ?></div></div>
<div class="card"><h2>🗓️ Project Timeline</h2><div class="timeline"><div class="step">1️⃣ Application</div><div class="step">2️⃣ Installation</div><div class="step">3️⃣ Inspection + Net-meter</div><div class="step">4️⃣ Commissioning</div></div></div>
<div class="card"><h2>✅ Terms & Next Steps</h2><ul><li>✅ Review quotation</li><li>✅ Confirm capacity and site readiness</li><li>✅ Pay booking amount</li><li>✅ Schedule installation</li></ul></div>
<div class="card highlight"><h3>Special Requests From Customer (Inclusive in the rate)</h3><div><?= nl2br(htmlspecialchars((string)$quote['special_requests_inclusive'], ENT_QUOTES)) ?></div><div><em>In case of conflict between Annexure inclusions and Special Requests, Special Requests will be given priority.</em></div></div>
<?php foreach (['payment_terms'=>'Payment Terms','warranty'=>'Warranty','transportation'=>'Transportation','system_type_explainer'=>'System Type Explainer','terms_conditions'=>'Terms & Conditions'] as $k=>$heading): ?><div class="card"><h3><?= $heading ?></h3><div><?= $safeHtml((string)($quote['annexures_overrides'][$k] ?? '')) ?></div></div><?php endforeach; ?>

</div>
<script>
(function(){
const data = {
  graphsEnabled: <?= json_encode($graphsEnabled) ?>,
  analysisMode: <?= json_encode($analysisMode) ?>,
  yearsForCumulative: <?= json_encode($yearsForCumulative) ?>,
  loanTenureYears: <?= json_encode($tenureYears) ?>,
  monthlyBill: <?= json_encode(round($case1Outflow, 2)) ?>,
  solarLoanOutflow: <?= json_encode(round($case2Outflow, 2)) ?>,
  afterLoanOutflow: <?= json_encode(round($case3Outflow, 2)) ?>,
  annualBill: <?= json_encode(round($annualBill, 2)) ?>,
  annualLoanSpend: <?= json_encode(round($case2Outflow * 12, 2)) ?>,
  annualAfterLoanSpend: <?= json_encode(round($case3Outflow * 12, 2)) ?>,
  netProjectCost: <?= json_encode(round($netProjectCost, 2)) ?>,
  emi: <?= json_encode(round($emi, 2)) ?>,
  residualBill: <?= json_encode(round($residualBill, 2)) ?>,
};
const rupees=v=>'₹'+Math.round(v).toLocaleString('en-IN');
function setupCanvas(canvas){if(!canvas)return null;const ratio=window.devicePixelRatio||1;const w=Math.min(700,canvas.clientWidth||700);const h=260;canvas.width=Math.floor(w*ratio);canvas.height=Math.floor(h*ratio);const ctx=canvas.getContext('2d');ctx.setTransform(ratio,0,0,ratio,0,0);return {ctx,w,h};}
function drawBars(){const el=document.getElementById('monthlyOutflowChart');const c=setupCanvas(el);if(!c)return;const {ctx,w,h}=c;const adv=document.getElementById('advancedToggle');const isAdvanced=!!(adv&&adv.checked);const labels=['No Solar','With Solar + Loan','After Loan'];const values=[data.monthlyBill,data.solarLoanOutflow,data.afterLoanOutflow];const colors=['#dc2626','#f59e0b','#16a34a'];const max=Math.max(...values,1)*1.2;const left=56,bottom=h-42,top=22,barW=80,gap=36;ctx.clearRect(0,0,w,h);ctx.font='12px Arial';ctx.fillStyle='#111';ctx.fillText('Monthly Outflow Comparison (₹/month)',left,14);
values.forEach((v,i)=>{const x=left+i*(barW+gap);const bh=((bottom-top)*v)/max;ctx.fillStyle=colors[i];ctx.fillRect(x,bottom-bh,barW,bh);ctx.fillStyle='#111';ctx.fillText(rupees(v),x,bottom-bh-8);ctx.fillText(labels[i],x,bottom+16);if(i===1){ctx.fillStyle='#6b7280';ctx.fillText(`EMI ${rupees(data.emi)} + Bill ${rupees(data.residualBill)}`,x-10,bottom+30);}});const t=document.getElementById('monthlyModeText');if(t){t.textContent=isAdvanced?'Year 1–10 (Loan Period) grouped comparison shown.':'Typical Month comparison shown.';}
}
function drawLines(){const el=document.getElementById('lifetimeCostChart');const c=setupCanvas(el);if(!c)return;const {ctx,w,h}=c;const years=data.yearsForCumulative;const x0=44,y0=h-30,plotW=w-68,plotH=h-56;const no=[],bank=[],self=[];let bankCum=0;for(let y=0;y<=years;y++){no.push(data.annualBill*y);if(y===0){bank.push(0);}else{bankCum += y<=data.loanTenureYears ? data.annualLoanSpend : data.annualAfterLoanSpend;bank.push(bankCum);}self.push(data.netProjectCost + (data.annualAfterLoanSpend*y));}
const max=Math.max(...no,...bank,...self,1);ctx.clearRect(0,0,w,h);ctx.strokeStyle='#d1d5db';ctx.beginPath();ctx.moveTo(x0,16);ctx.lineTo(x0,y0);ctx.lineTo(w-20,y0);ctx.stroke();
for(let g=1;g<=4;g++){const gy=16+(plotH*g/4);ctx.strokeStyle='#eef2f7';ctx.beginPath();ctx.moveTo(x0,gy);ctx.lineTo(w-20,gy);ctx.stroke();}
function line(series,color){ctx.strokeStyle=color;ctx.beginPath();series.forEach((v,i)=>{const x=x0+(plotW*(i/years));const y=y0-(plotH*(v/max));if(i===0)ctx.moveTo(x,y);else ctx.lineTo(x,y);});ctx.stroke();}
line(no,'#dc2626');line(bank,'#f59e0b');line(self,'#16a34a');ctx.fillStyle='#111';ctx.fillText('Total Money Spent Over Time (₹)',x0,12);ctx.fillStyle='#dc2626';ctx.fillText('■ Without solar',x0,y0+16);ctx.fillStyle='#f59e0b';ctx.fillText('■ Solar + bank',x0+120,y0+16);ctx.fillStyle='#16a34a';ctx.fillText('■ Solar self financed',x0+230,y0+16);
}
function render(){if(!data.graphsEnabled)return;drawBars();drawLines();}
window.addEventListener('load',render);window.addEventListener('resize',render);const adv=document.getElementById('advancedToggle');if(adv){adv.addEventListener('change',drawBars);}
window.onload=function(){render();if(location.search.indexOf('autoprint=1')!==-1){window.print();}};
})();
</script>
</body></html>
