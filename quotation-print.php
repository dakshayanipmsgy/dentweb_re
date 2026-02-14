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
$defaults = is_array($style['defaults'] ?? null) ? $style['defaults'] : [];
$defaultUnitRate = (float) ($defaults[$segmentRateKey[$segment] ?? 'residential_unit_rate_rs_per_kwh'] ?? ($defaults['unit_rate_rs_per_kwh'] ?? 7));
$unitRate = (float) (($financial['unit_rate_rs_per_kwh'] ?? '') !== '' ? $financial['unit_rate_rs_per_kwh'] : $defaultUnitRate);
$interestRate = (float) (($financial['interest_rate_percent'] ?? '') !== '' ? $financial['interest_rate_percent'] : ($defaults['default_bank_interest_rate_percent'] ?? 10));
$tenureYears = (int) (($financial['loan_tenure_years'] ?? '') !== '' ? $financial['loan_tenure_years'] : ($defaults['default_loan_tenure_years'] ?? 10));
$annualGenerationPerKw = (float) (($financial['annual_generation_per_kw'] ?? '') !== '' ? $financial['annual_generation_per_kw'] : ($defaults['default_annual_generation_per_kw'] ?? 1450));
$marginMoneyPercent = (float) (($financial['margin_money_percent'] ?? '') !== '' ? $financial['margin_money_percent'] : 10);
$minFixedCharge = (float) ($defaults['minimum_fixed_charges_rs'] ?? 200);
$analysisMode = (string) ($financial['analysis_mode'] ?? 'simple_monthly');
if (!in_array($analysisMode, ['simple_monthly', 'advanced_10y_yearly'], true)) {
    $analysisMode = 'simple_monthly';
}
$yearsForCumulative = (int) ($financial['years_for_cumulative_chart'] ?? 25);
$yearsForCumulative = $yearsForCumulative > 0 ? $yearsForCumulative : 25;

$capacity = (float) ($quote['capacity_kwp'] ?? 0);
$quoteTotalCost = (float) ($quote['input_total_gst_inclusive'] ?? ($quote['calc']['grand_total'] ?? 0));
$financialSubsidy = (float) ($financial['subsidy_expected_rs'] ?? 0);
$rootSubsidy = (float) ($quote['subsidy_expected_rs'] ?? ($quote['subsidy_amount_rs'] ?? 0));
$subsidy = $financialSubsidy > 0 ? $financialSubsidy : $rootSubsidy;
if ($subsidy <= 0 && documents_quote_is_pm_surya_ghar($quote)) {
    $subsidy = documents_calculate_pm_surya_subsidy($capacity);
}

$monthlyBillInput = (float) ($financial['estimated_monthly_bill_rs'] ?? 0);
$monthlyUnitsInput = (float) ($financial['estimated_monthly_units_kwh'] ?? 0);
$monthlyBill = 0.0;
$monthlyUnitsBefore = 0.0;
if ($monthlyBillInput > 0) {
    $monthlyBill = $monthlyBillInput;
    $monthlyUnitsBefore = $unitRate > 0 ? ($monthlyBill / $unitRate) : 0;
} elseif ($monthlyUnitsInput > 0) {
    $monthlyUnitsBefore = $monthlyUnitsInput;
    $monthlyBill = $monthlyUnitsBefore * $unitRate;
}

$monthlySolarUnits = ($capacity * $annualGenerationPerKw) / 12;
$monthlyUnitsAfter = max($monthlyUnitsBefore - $monthlySolarUnits, 0);
$computedMonthlyBillAfter = $monthlyUnitsAfter * $unitRate;
$manualMinOverride = (float) ($financial['min_monthly_bill_rs_override'] ?? 0);
$minimumBill = $manualMinOverride > 0 ? $manualMinOverride : max($computedMonthlyBillAfter, $minFixedCharge);
$residualBill = $minimumBill;

$netCostAfterSubsidy = max(0, $quoteTotalCost - $subsidy);
$marginMoneyAmount = max(0, $netCostAfterSubsidy * ($marginMoneyPercent / 100));
$principal = max($netCostAfterSubsidy - $marginMoneyAmount, 0);

$nMonths = max(1, $tenureYears * 12);
$monthlyRate = ($interestRate / 100) / 12;
$emi = $principal > 0
    ? ($monthlyRate > 0
        ? ($principal * $monthlyRate * pow(1 + $monthlyRate, $nMonths)) / (pow(1 + $monthlyRate, $nMonths) - 1)
        : ($principal / $nMonths))
    : 0;

$annualBill = $monthlyBill * 12;
$annualLoanSpend = ($emi + $residualBill) * 12;
$annualAfterLoanSpend = $minimumBill * 12;
$monthlySavings = max($monthlyBill - $minimumBill, 0);
$annualSavings = $monthlySavings * 12;
$paybackYears = $annualSavings > 0 ? ($netCostAfterSubsidy / $annualSavings) : null;

$graphsEnabled = $monthlyBill > 0 || $monthlyUnitsBefore > 0;
$safeHtml = static function (string $value): string {
    $clean = strip_tags($value, '<p><br><ul><ol><li><strong><em><b><i><u><table><thead><tbody><tr><td><th>');
    return $clean !== '' ? $clean : '<span style="color:#666">—</span>';
};

$companyName = (string) ($company['company_name'] ?: $company['brand_name']);
$licenses = array_filter([(string) ($company['jreda_license'] ?? ''), (string) ($company['dwsd_license'] ?? '')]);
$fields = [
    'Name' => (string) ($snapshot['name'] ?: $quote['customer_name']),
    'Mobile' => (string) ($snapshot['mobile'] ?: $quote['customer_mobile']),
    'Address' => (string) ($quote['site_address'] ?: $snapshot['address']),
    'City' => (string) ($snapshot['city'] ?: $quote['city']),
    'District' => (string) ($snapshot['district'] ?: $quote['district']),
    'Pin' => (string) ($snapshot['pin_code'] ?: $quote['pin']),
    'State' => (string) ($snapshot['state'] ?: $quote['state']),
    'Meter number' => (string) ($snapshot['meter_number'] ?: $quote['meter_number']),
    'Meter serial' => (string) ($snapshot['meter_serial_number'] ?: $quote['meter_serial_number']),
    'Consumer account no' => (string) ($snapshot['consumer_account_no'] ?: $quote['consumer_account_no']),
    'Application ID' => (string) ($snapshot['application_id'] ?: $quote['application_id']),
    'Submission date' => (string) ($snapshot['application_submitted_date'] ?: $quote['application_submitted_date']),
    'Circle' => (string) ($snapshot['circle_name'] ?: $quote['circle_name']),
    'Division' => (string) ($snapshot['division_name'] ?: $quote['division_name']),
    'Sub Division' => (string) ($snapshot['sub_division_name'] ?: $quote['sub_division_name']),
    'Sanction load' => (string) ($snapshot['sanction_load_kwp'] ?: $quote['sanction_load_kwp']),
    'Installed PV capacity' => (string) ($snapshot['installed_pv_module_capacity_kwp'] ?: $quote['installed_pv_module_capacity_kwp']),
];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Print <?= htmlspecialchars((string) $quote['quote_no'], ENT_QUOTES) ?></title>
<style>
body{font-family:Arial,sans-serif;color:#1f2937;margin:0;background:#f8fafc}.wrap{max-width:980px;margin:0 auto;padding:14px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:12px;margin-bottom:12px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #e2e8f0;padding:7px;font-size:13px;text-align:left}.muted{color:#64748b;font-size:12px}.pill{display:inline-block;background:#eff6ff;border-radius:999px;padding:7px 10px;margin:5px 5px 0 0}.charts .card{break-inside:avoid}canvas{width:100%;height:280px;border:1px solid #e2e8f0;border-radius:10px;background:#fff}h1,h2,h3{margin:6px 0}.footer-strip{font-size:12px;background:#eef2ff;border:1px solid #c7d2fe;padding:8px;border-radius:8px}
</style></head>
<body><div class="wrap">
<div class="card"><div style="display:flex;justify-content:space-between;gap:12px;align-items:center"><div><h1><?= htmlspecialchars($companyName, ENT_QUOTES) ?></h1><div class="muted">Quotation No: <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></div></div><?php if ((string)($company['logo_path'] ?? '') !== ''): ?><img src="<?= htmlspecialchars((string)$company['logo_path'], ENT_QUOTES) ?>" alt="Logo" style="max-height:70px;max-width:180px"><?php endif; ?></div></div>

<div class="grid">
<div class="card"><h2>Customer Details</h2><table><?php foreach ($fields as $label=>$value): if (trim((string)$value) === '') { continue; } ?><tr><th style="width:42%"><?= htmlspecialchars($label, ENT_QUOTES) ?></th><td><?= nl2br(htmlspecialchars((string)$value, ENT_QUOTES)) ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><h2>System & Financial Snapshot</h2><table>
<tr><th>System</th><td><?= htmlspecialchars((string)$quote['system_type'], ENT_QUOTES) ?> (<?= htmlspecialchars((string)$quote['capacity_kwp'], ENT_QUOTES) ?> kWp)</td></tr>
<tr><th>Project cost</th><td>₹<?= number_format($quoteTotalCost,2) ?></td></tr>
<tr><th>Subsidy</th><td>₹<?= number_format($subsidy,2) ?></td></tr>
<tr><th>Net cost after subsidy</th><td>₹<?= number_format($netCostAfterSubsidy,2) ?></td></tr>
<tr><th>Margin money</th><td>₹<?= number_format($marginMoneyAmount,2) ?> (<?= number_format($marginMoneyPercent,2) ?>%)</td></tr>
<tr><th>Loan principal</th><td>₹<?= number_format($principal,2) ?></td></tr>
<tr><th>EMI / month</th><td>₹<?= number_format($emi,2) ?></td></tr>
<tr><th>Minimum bill after solar</th><td>₹<?= number_format($minimumBill,2) ?></td></tr>
</table></div>
</div>

<div class="charts">
<div class="card"><h3>Graph 1: Monthly Comparison</h3><canvas id="graph1Monthly"></canvas><div class="muted">Margin: ₹<?= number_format($marginMoneyAmount,0) ?> (<?= number_format($marginMoneyPercent,1) ?>%), Loan: ₹<?= number_format($principal,0) ?></div></div>
<div class="card"><h3>Graph 2: Cumulative Spending</h3><canvas id="graph2Cumulative"></canvas></div>
<div class="card" id="graph3Card"><h3>Graph 3: Payback Snapshot</h3><canvas id="graph3Payback"></canvas><table>
<tr><th>Monthly savings</th><td>₹<?= number_format($monthlySavings,2) ?></td></tr>
<tr><th>Annual savings</th><td>₹<?= number_format($annualSavings,2) ?></td></tr>
<tr><th>Payback (self-finance)</th><td><?= $paybackYears === null ? 'N/A' : number_format($paybackYears,2) . ' years' ?></td></tr>
</table></div>
<?php if (!$graphsEnabled): ?><div class="card" style="background:#fffbeb;border-color:#fde68a">Please provide previous monthly bill OR monthly units in quotation financial inputs to render all graphs.</div><?php endif; ?>
</div>

<div class="card"><h2>Company Details</h2><table>
<?php if ($companyName !== ''): ?><tr><th>Firm name</th><td><?= htmlspecialchars($companyName, ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php $addr = trim(implode(', ', array_filter([(string)($company['address_line'] ?? ''),(string)($company['city'] ?? ''),(string)($company['district'] ?? ''),(string)($company['state'] ?? ''),(string)($company['pin'] ?? '')]))); if ($addr !== ''): ?><tr><th>Address</th><td><?= htmlspecialchars($addr, ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php $phones = trim(implode(', ', array_filter([(string)($company['phone_primary'] ?? ''),(string)($company['phone_secondary'] ?? '')]))); if ($phones !== ''): ?><tr><th>Phone</th><td><?= htmlspecialchars($phones, ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php $emails = trim(implode(', ', array_filter([(string)($company['email_primary'] ?? ''),(string)($company['email_secondary'] ?? '')]))); if ($emails !== ''): ?><tr><th>Email</th><td><?= htmlspecialchars($emails, ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php if ((string)($company['website'] ?? '') !== ''): ?><tr><th>Website</th><td><?= htmlspecialchars((string)$company['website'], ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php if ((string)($company['default_cta_line'] ?? '') !== ''): ?><tr><th>CTA line</th><td><?= htmlspecialchars((string)$company['default_cta_line'], ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php if ((string)($company['gstin'] ?? '') !== ''): ?><tr><th>GSTIN</th><td><?= htmlspecialchars((string)$company['gstin'], ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php if ((string)($company['udyam'] ?? '') !== ''): ?><tr><th>Udyam</th><td><?= htmlspecialchars((string)$company['udyam'], ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php if ((string)($company['pan'] ?? '') !== ''): ?><tr><th>PAN</th><td><?= htmlspecialchars((string)$company['pan'], ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php if (!empty($licenses)): ?><tr><th>Licences</th><td><ul><?php foreach ($licenses as $license): ?><li><?= htmlspecialchars($license, ENT_QUOTES) ?></li><?php endforeach; ?></ul></td></tr><?php endif; ?>
<?php if ((string)($company['bank_account_name'] ?? '') !== '' || (string)($company['bank_account_no'] ?? '') !== ''): ?><tr><th>Bank details</th><td><?= htmlspecialchars((string)($company['bank_account_name'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars((string)($company['bank_name'] ?? ''), ENT_QUOTES) ?>, A/C <?= htmlspecialchars((string)($company['bank_account_no'] ?? ''), ENT_QUOTES) ?>, IFSC <?= htmlspecialchars((string)($company['bank_ifsc'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars((string)($company['bank_branch'] ?? ''), ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php if ((string)($company['upi_id'] ?? '') !== ''): ?><tr><th>UPI</th><td><?= htmlspecialchars((string)$company['upi_id'], ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php if ((string)($company['upi_qr_path'] ?? '') !== ''): ?><tr><th>UPI QR</th><td><img src="<?= htmlspecialchars((string)$company['upi_qr_path'], ENT_QUOTES) ?>" alt="UPI QR" style="max-height:110px"></td></tr><?php endif; ?>
</table></div>

<div class="footer-strip">For support and booking assistance, contact <?= htmlspecialchars($companyName, ENT_QUOTES) ?>.</div>
</div>
<script>
(function(){
const data = {
  graphsEnabled: <?= json_encode($graphsEnabled) ?>,
  analysisMode: <?= json_encode($analysisMode) ?>,
  yearsForCumulative: <?= json_encode($yearsForCumulative) ?>,
  tenureYears: <?= json_encode($tenureYears) ?>,
  monthlyBill: <?= json_encode(round($monthlyBill,2)) ?>,
  monthlySolarLoan: <?= json_encode(round($emi + $residualBill,2)) ?>,
  monthlyAfterLoan: <?= json_encode(round($minimumBill,2)) ?>,
  annualBill: <?= json_encode(round($annualBill,2)) ?>,
  annualLoanSpend: <?= json_encode(round($annualLoanSpend,2)) ?>,
  annualAfterLoanSpend: <?= json_encode(round($annualAfterLoanSpend,2)) ?>,
  marginMoney: <?= json_encode(round($marginMoneyAmount,2)) ?>,
  netCostAfterSubsidy: <?= json_encode(round($netCostAfterSubsidy,2)) ?>,
  paybackYears: <?= json_encode($paybackYears) ?>,
  emi: <?= json_encode(round($emi,2)) ?>,
  loanPrincipal: <?= json_encode(round($principal,2)) ?>,
  monthlySavings: <?= json_encode(round($monthlySavings,2)) ?>,
  annualSavings: <?= json_encode(round($annualSavings,2)) ?>,
};
const rupees=v=>'₹'+Math.round(v).toLocaleString('en-IN');
function setupCanvas(id,h){const c=document.getElementById(id);if(!c){return null;}const r=window.devicePixelRatio||1;const w=Math.max(620,Math.floor(c.clientWidth||620));c.width=w*r;c.height=h*r;const ctx=c.getContext('2d');ctx.setTransform(r,0,0,r,0,0);return {ctx,w,h};}
function axes(ctx,left,top,right,bottom,xLabel,yLabel){ctx.strokeStyle='#94a3b8';ctx.beginPath();ctx.moveTo(left,top);ctx.lineTo(left,bottom);ctx.lineTo(right,bottom);ctx.stroke();ctx.fillStyle='#334155';ctx.font='12px Arial';ctx.fillText(xLabel,(left+right)/2-20,bottom+24);ctx.save();ctx.translate(left-36,(top+bottom)/2+20);ctx.rotate(-Math.PI/2);ctx.fillText(yLabel,0,0);ctx.restore();}
function drawGraph1(){const p=setupCanvas('graph1Monthly',290);if(!p)return;const {ctx,w,h}=p;ctx.clearRect(0,0,w,h);const left=70,right=w-22,top=25,bottom=h-45;axes(ctx,left,top,right,bottom,'Months','₹ per month');
const groups=data.analysisMode==='advanced_10y_yearly' ? ['Years 1-Tenure','After Tenure'] : ['Typical Month'];
const bars=[
  {name:'Without Solar',color:'#dc2626',values:groups.map((_,i)=>data.monthlyBill)},
  {name:'Solar + Loan',color:'#f59e0b',values:groups.map((_,i)=>i===0?data.monthlySolarLoan:data.monthlyAfterLoan)},
  {name:'After Loan',color:'#16a34a',values:groups.map((_,i)=>data.monthlyAfterLoan)},
];
const max=Math.max(1,...bars.flatMap(b=>b.values))*1.2;const gw=(right-left)/groups.length;
bars.forEach((b,bi)=>{b.values.forEach((v,gi)=>{const bw=Math.min(30,gw/4);const x=left+gi*gw+(bi+0.5)*(gw/4);const bh=(bottom-top)*v/max;ctx.fillStyle=b.color;ctx.fillRect(x,bottom-bh,bw,bh);ctx.fillStyle='#111827';ctx.font='11px Arial';ctx.fillText(rupees(v),x-8,bottom-bh-6);});});
ctx.fillStyle='#475569';ctx.font='11px Arial';groups.forEach((g,i)=>ctx.fillText(g,left+i*gw+8,bottom+14));
ctx.fillText('Margin: '+rupees(data.marginMoney)+'  Loan: '+rupees(data.loanPrincipal),left,16);
}
function drawGraph2(){const p=setupCanvas('graph2Cumulative',290);if(!p)return;const {ctx,w,h}=p;ctx.clearRect(0,0,w,h);const left=70,right=w-20,top=25,bottom=h-45;axes(ctx,left,top,right,bottom,'Years','Total money spent (₹)');
const years=Math.max(1,data.yearsForCumulative);const red=[],yellow=[],green=[];let ycum=data.marginMoney;for(let y=0;y<=years;y++){red.push(data.annualBill*y);if(y===0){yellow.push(data.marginMoney);green.push(data.netCostAfterSubsidy);}else{ycum += y<=data.tenureYears ? data.annualLoanSpend : data.annualAfterLoanSpend;yellow.push(ycum);green.push(data.netCostAfterSubsidy + data.annualAfterLoanSpend*y);}}
const max=Math.max(1,...red,...yellow,...green);for(let i=0;i<=5;i++){const gy=bottom-((bottom-top)*i/5);ctx.strokeStyle='#e2e8f0';ctx.beginPath();ctx.moveTo(left,gy);ctx.lineTo(right,gy);ctx.stroke();}
function line(arr,color){ctx.strokeStyle=color;ctx.lineWidth=2;ctx.beginPath();arr.forEach((v,i)=>{const x=left+(right-left)*(i/years);const y=bottom-(bottom-top)*(v/max);if(i===0)ctx.moveTo(x,y);else ctx.lineTo(x,y);});ctx.stroke();}
line(red,'#dc2626');line(yellow,'#f59e0b');line(green,'#16a34a');ctx.fillStyle='#dc2626';ctx.fillText('■ Without solar',left,bottom+18);ctx.fillStyle='#f59e0b';ctx.fillText('■ Solar + Loan',left+120,bottom+18);ctx.fillStyle='#16a34a';ctx.fillText('■ Self finance',left+230,bottom+18);
}
function drawGraph3(){const p=setupCanvas('graph3Payback',220);if(!p)return;const {ctx,w,h}=p;ctx.clearRect(0,0,w,h);ctx.fillStyle='#111827';ctx.font='12px Arial';ctx.fillText('Net cost: '+rupees(data.netCostAfterSubsidy),20,30);ctx.fillText('Margin: '+rupees(data.marginMoney),20,52);ctx.fillText('Loan: '+rupees(data.loanPrincipal)+' | EMI: '+rupees(data.emi),20,74);ctx.fillText('Monthly savings: '+rupees(data.monthlySavings)+' | Annual: '+rupees(data.annualSavings),20,96);
const left=20,right=w-20,y=145;ctx.strokeStyle='#cbd5e1';ctx.lineWidth=8;ctx.beginPath();ctx.moveTo(left,y);ctx.lineTo(right,y);ctx.stroke();ctx.fillStyle='#64748b';ctx.fillText('0y',left-2,y+18);ctx.fillText('10y',right-16,y+18);
if(data.paybackYears!==null){const pos=Math.min(10,Math.max(0,data.paybackYears));const x=left+(right-left)*(pos/10);ctx.fillStyle='#22c55e';ctx.beginPath();ctx.arc(x,y,7,0,Math.PI*2);ctx.fill();ctx.fillStyle='#111827';ctx.fillText('Payback '+Number(data.paybackYears).toFixed(2)+'y',x-40,y-14);}else{ctx.fillText('Payback: N/A',left,y-14);} 
}
function renderAll(){if(!data.graphsEnabled){return;}drawGraph1();drawGraph2();drawGraph3();}
window.addEventListener('load',renderAll);window.addEventListener('resize',renderAll);
window.onload=function(){renderAll();if(location.search.indexOf('autoprint=1')!==-1){window.print();}};
})();
</script>
</body></html>
