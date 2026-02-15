<?php
declare(strict_types=1);
require_once __DIR__ . '/admin/includes/documents_helpers.php';
documents_ensure_structure();
$token = safe_text($_GET['token'] ?? '');
$quote = null;
foreach (documents_list_quotes() as $q) {
    if ((string)($q['share']['public_token'] ?? '') === $token && !empty($q['share']['public_enabled'])) { $quote = $q; break; }
}
if ($token === '' || $quote === null) { http_response_code(404); echo '<h1>Link invalid or expired.</h1>'; exit; }

$quoteDefaults = documents_get_quote_defaults_settings();
$segment = (string)($quote['segment'] ?? 'RES');
$segmentDefaults = is_array($quoteDefaults['segments'][$segment] ?? null) ? $quoteDefaults['segments'][$segment] : [];
$company = documents_get_company_profile_for_quotes();
$templateSets = json_load(documents_templates_dir() . '/template_sets.json', []);
$template = [];
foreach ($templateSets as $row) {
    if ((string)($row['id'] ?? '') === (string)($quote['template_set_id'] ?? '')) { $template = is_array($row) ? $row : []; break; }
}
$templateTags = is_array($template['tags'] ?? null) ? $template['tags'] : [];

$primaryPhone = trim((string)($company['phone_primary'] ?? ''));
$secondaryPhone = trim((string)($company['phone_secondary'] ?? ''));
$email = trim((string)($company['email_primary'] ?? ''));
$website = trim((string)($company['website'] ?? ''));
$brandName = trim((string)($company['brand_name'] ?? '')) ?: 'Dakshayani Enterprises';
$companyName = trim((string)($company['company_name'] ?? '')) ?: $brandName;
$addressParts = array_filter([trim((string)($company['address_line'] ?? '')), trim((string)($company['city'] ?? '')), trim((string)($company['district'] ?? '')), trim((string)($company['state'] ?? '')), trim((string)($company['pin'] ?? ''))]);
$fullAddress = implode(', ', $addressParts);
$preparedBy = (string)($quote['created_by_name'] ?? 'Team');

$wmGlobal = $quoteDefaults['global']['branding']['watermark'] ?? ['enabled'=>true,'image_path'=>'','opacity'=>0.08];
$wmEnabled = !empty($wmGlobal['enabled']);
$wmImage = (string)($wmGlobal['image_path'] ?? '');
$wmOpacity = (float)($wmGlobal['opacity'] ?? 0.08);
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quotation <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></title>
<style>
body{margin:0;background:#eef3f9;font-family:Inter,Arial,sans-serif;color:#0f172a} .wrap{max-width:1080px;margin:0 auto;padding:14px}
.card{background:#fff;border:1px solid #d7e2ef;border-radius:14px;padding:14px;margin-bottom:12px} .grid{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(210px,1fr))}
.h{font-weight:700;font-size:18px}.muted{color:#64748b;font-size:13px}.metric{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:10px}
.header{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:start}.chips{display:flex;flex-wrap:wrap;gap:6px}.chip{font-size:11px;background:#e0f2fe;color:#0c4a6e;border-radius:999px;padding:4px 8px}
.footer{font-size:13px;background:#0f172a;color:#e2e8f0} .footer a{color:#93c5fd}
.chart{border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#fff}.chart h4{margin:0 0 8px 0}
canvas{width:100%;height:220px}
@media print{body{background:#fff}.wrap{max-width:none;padding:0}.card{break-inside:avoid;border-color:#cbd5e1}<?php if ($wmEnabled && $wmImage !== ''): ?>body::before{content:"";position:fixed;inset:0;background:url('<?= htmlspecialchars($wmImage, ENT_QUOTES) ?>') center/40% no-repeat;opacity:<?= htmlspecialchars((string)$wmOpacity, ENT_QUOTES) ?>;z-index:-1}<?php endif; ?>}
</style></head><body>
<main class="wrap">
<div class="card header"><div><div class="h"><?= htmlspecialchars($companyName, ENT_QUOTES) ?></div><div class="muted"><?= htmlspecialchars($fullAddress, ENT_QUOTES) ?></div><div class="muted"><?= htmlspecialchars(trim($primaryPhone . ($secondaryPhone !== '' ? ' / ' . $secondaryPhone : '')), ENT_QUOTES) ?><?= $email!==''?' · '.htmlspecialchars($email, ENT_QUOTES):'' ?><?= $website!==''?' · '.htmlspecialchars($website, ENT_QUOTES):'' ?></div><div class="muted"><?php if(!empty($company['gstin'])): ?>GSTIN: <?= htmlspecialchars((string)$company['gstin'], ENT_QUOTES) ?> · <?php endif; ?><?php if(!empty($company['udyam'])): ?>UDYAM: <?= htmlspecialchars((string)$company['udyam'], ENT_QUOTES) ?><?php endif; ?><?php if(!empty($company['jreda_license'])): ?> · JREDA: <?= htmlspecialchars((string)$company['jreda_license'], ENT_QUOTES) ?><?php endif; ?></div><?php if ($templateTags !== []): ?><div class="chips"><?php foreach ($templateTags as $tag): ?><span class="chip"><?= htmlspecialchars((string)$tag, ENT_QUOTES) ?></span><?php endforeach; ?></div><?php endif; ?></div><div class="metric"><div>Quote No: <b><?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></b></div><div>Prepared by: <b><?= htmlspecialchars($preparedBy, ENT_QUOTES) ?></b></div><div>Valid till: <b><?= htmlspecialchars((string)($quote['valid_until'] ?: '-'), ENT_QUOTES) ?></b></div></div></div>
<div class="card"><div class="h">Customer</div><div class="muted"><?= htmlspecialchars((string)$quote['customer_name'], ENT_QUOTES) ?> · <?= htmlspecialchars((string)$quote['city'], ENT_QUOTES) ?></div></div>
<div class="card"><div class="h">Pricing Summary</div><div class="grid"><div class="metric">Final price<br><b id="finalPrice"></b></div><div class="metric">Transportation<br><b id="transportationRs"></b></div><div class="metric">Subsidy expected<br><b id="subsidyRs"></b></div><div class="metric">Net cost after subsidy<br><b id="netCostRs"></b></div></div><p class="muted">Customer pays full amount to vendor. Subsidy is credited later after commissioning (as per scheme process).</p></div>
<div class="card"><div class="h">Finance Clarity</div><p class="muted" id="clarityText"></p><p class="muted">Graph assumes best-case bank loan up to ₹2,00,000 at 6% for 10 years. Higher loan slabs may have higher interest (e.g., 8.15%+) and higher margin (20%).</p><div class="grid"><div class="metric"><b>Bank Loan Snapshot</b><div>Margin money: <span id="marginRs"></span></div><div>Loan amount: <span id="loanRs"></span></div><div>EMI: <span id="emiRs"></span>/month</div><div>Residual bill: <span id="residualRs"></span>/month</div><div>Total monthly outflow: <span id="loanOutflowRs"></span>/month</div></div><div class="metric"><b>Self-finance Snapshot</b><div>Upfront: <span id="selfUpfrontRs"></span></div><div>Residual bill: <span id="selfResidualRs"></span>/month</div></div><div class="metric"><b>No Solar Snapshot</b><div>Current bill: <span id="noSolarRs"></span>/month</div></div></div><div class="metric" style="margin-top:10px"><b>Loan Notes</b><div id="higherSlabInfo" class="muted"></div></div></div>
<div class="card"><div class="grid"><div class="chart"><h4>Monthly Spend Comparison</h4><canvas id="chart1" width="480" height="220"></canvas></div><div class="chart"><h4>Cumulative Spend Over 10 Years</h4><canvas id="chart2" width="480" height="220"></canvas></div><div class="chart"><h4>Payback Meter</h4><div id="paybackText" class="muted"></div></div></div></div>
<div class="card footer"><div>For <?= htmlspecialchars($brandName, ENT_QUOTES) ?></div><div><?= htmlspecialchars(trim($primaryPhone . ($secondaryPhone !== '' ? ' / ' . $secondaryPhone : '')), ENT_QUOTES) ?><?= $email!==''?' · '.htmlspecialchars($email, ENT_QUOTES):'' ?><?= $website!==''?' · '.htmlspecialchars($website, ENT_QUOTES):'' ?></div><div><?= htmlspecialchars($fullAddress, ENT_QUOTES) ?></div><?php if(!empty($company['bank_account_name']) || !empty($company['bank_name']) || !empty($company['bank_ifsc'])): ?><div>Bank: <?= htmlspecialchars((string)$company['bank_account_name'], ENT_QUOTES) ?><?= !empty($company['bank_name'])?' · '.htmlspecialchars((string)$company['bank_name'], ENT_QUOTES):'' ?><?= !empty($company['bank_ifsc'])?' · IFSC: '.htmlspecialchars((string)$company['bank_ifsc'], ENT_QUOTES):'' ?></div><?php endif; ?><div class="muted">This proposal is indicative and subject to site verification and final execution conditions.</div></div>
</main>
<script>
const quoteData = {
  quotation_total_rs: <?= json_encode((float)($quote['calc']['grand_total'] ?? 0)) ?>,
  subsidy_rs: <?= json_encode((float)($quote['finance_inputs']['subsidy_expected_rs'] ?: 0)) ?>,
  monthly_bill_rs: <?= json_encode((float)($quote['finance_inputs']['monthly_bill_rs'] ?: 0)) ?>,
  unit_rate: <?= json_encode((float)(($quote['finance_inputs']['unit_rate_rs_per_kwh'] ?: ($segmentDefaults['unit_rate_rs_per_kwh'] ?? 8))) ) ?>,
  capacity_kwp: <?= json_encode((float)($quote['capacity_kwp'] ?: 0)) ?>,
  annual_generation_per_kw: <?= json_encode((float)(($quote['finance_inputs']['annual_generation_per_kw'] ?: ($segmentDefaults['annual_generation_per_kw'] ?? $quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450))) ) ?>,
  transportation_rs: <?= json_encode((float)($quote['finance_inputs']['transportation_rs'] ?: 0)) ?>,
  segment: <?= json_encode($segment) ?>,
  subsidy_cap_2kw: <?= json_encode((float)($segmentDefaults['subsidy']['cap_2kw'] ?? 60000)) ?>,
  subsidy_cap_3kw_plus: <?= json_encode((float)($segmentDefaults['subsidy']['cap_3kw_plus'] ?? 78000)) ?>,
  bestcase_max_loan: <?= json_encode((float)($segmentDefaults['loan_bestcase']['max_loan_rs'] ?? 200000)) ?>,
  bestcase_interest_pct: <?= json_encode((float)($segmentDefaults['loan_bestcase']['interest_pct'] ?? 6.0)) ?>,
  bestcase_tenure_years: <?= json_encode((float)($segmentDefaults['loan_bestcase']['tenure_years'] ?? 10)) ?>,
  min_margin_pct: <?= json_encode((float)($segmentDefaults['loan_bestcase']['min_margin_pct'] ?? 10)) ?>,
  slab2_interest_pct: <?= json_encode((float)($segmentDefaults['loan_info']['slab2_interest_pct'] ?? 8.15)) ?>,
  slab2_min_margin_pct: <?= json_encode((float)($segmentDefaults['loan_info']['slab2_min_margin_pct'] ?? 20)) ?>,
  slab2_range: <?= json_encode((string)($segmentDefaults['loan_info']['slab2_range'] ?? '₹2L–₹6L')) ?>
};
function formatRs(v){return '₹'+Number(v).toLocaleString('en-IN',{maximumFractionDigits:0});}
function computeFinanceModel(i){const quotation_total_rs=Math.max(0,Number(i.quotation_total_rs||0)),subsidy_rs=Math.max(0,Number(i.subsidy_rs||0)),monthly_bill_rs=Math.max(0,Number(i.monthly_bill_rs||0)),unit_rate=Math.max(0.0001,Number(i.unit_rate||0)),capacity_kwp=Math.max(0,Number(i.capacity_kwp||0)),annual_generation_per_kw=Math.max(0,Number(i.annual_generation_per_kw||0)),bestcase_max_loan=Math.max(0,Number(i.bestcase_max_loan||0)),interest_pct=Math.max(0,Number(i.bestcase_interest_pct||0)),tenure_years=Math.max(0,Number(i.bestcase_tenure_years||0)),min_margin_pct=Math.max(0,Number(i.min_margin_pct||0));const min_margin_rs=quotation_total_rs*(min_margin_pct/100),desired_loan_rs=quotation_total_rs-min_margin_rs,loan_rs=Math.min(desired_loan_rs,bestcase_max_loan),margin_rs=quotation_total_rs-loan_rs,loan_effective_principal=Math.max(0,loan_rs-subsidy_rs),r=(interest_pct/100)/12,n=tenure_years*12;let EMI=0;if(loan_effective_principal>0&&n>0){EMI=r===0?loan_effective_principal/n:(loan_effective_principal*r*Math.pow(1+r,n))/((Math.pow(1+r,n))-1);}const monthly_units=monthly_bill_rs/unit_rate,monthly_solar_units=(capacity_kwp*annual_generation_per_kw)/12,residual_units=Math.max(0,monthly_units-monthly_solar_units),residual_bill_rs=residual_units*unit_rate;return {margin_rs,loan_rs,EMI,residual_bill_rs,loan_monthly_outflow:EMI+residual_bill_rs,self_monthly_outflow:residual_bill_rs,no_solar_monthly_outflow:monthly_bill_rs};}
function drawBars(canvas, values, labels, colors){const c=canvas.getContext('2d');const w=canvas.width,h=canvas.height;c.clearRect(0,0,w,h);const pad=30;const max=Math.max(...values,1);const bw=(w-pad*2)/values.length*0.6;values.forEach((v,idx)=>{const x=pad+idx*((w-pad*2)/values.length)+15;const bh=(v/max)*(h-70);const y=h-40-bh;c.fillStyle=colors[idx];c.fillRect(x,y,bw,bh);c.fillStyle='#334155';c.font='11px Arial';c.fillText(labels[idx],x,h-22);c.fillText(Math.round(v).toLocaleString('en-IN'),x,y-5);});}
function drawLines(canvas,noSolar,bank,self){const c=canvas.getContext('2d');const w=canvas.width,h=canvas.height;c.clearRect(0,0,w,h);const pad=30;const months=120;const points=[];for(let m=0;m<=months;m+=12){points.push([m,m*noSolar,m*bank,quoteData.quotation_total_rs+m*self]);}const max=Math.max(...points.map(p=>Math.max(p[1],p[2],p[3])),1);const toXY=(m,v)=>[pad + (m/months)*(w-pad*2), h-pad - (v/max)*(h-pad*2)];for(let y=0;y<5;y++){const yy=pad+y*(h-pad*2)/4;c.strokeStyle='#e2e8f0';c.beginPath();c.moveTo(pad,yy);c.lineTo(w-pad,yy);c.stroke();}[['#ef4444',1],['#2563eb',2],['#16a34a',3]].forEach(([color,i])=>{c.strokeStyle=color;c.lineWidth=2;c.beginPath();points.forEach((p,idx)=>{const [x,y]=toXY(p[0],p[i]);if(idx===0)c.moveTo(x,y);else c.lineTo(x,y);});c.stroke();});c.fillStyle='#334155';c.font='11px Arial';c.fillText('No Solar',pad,12);c.fillStyle='#2563eb';c.fillText('Bank-financed',pad+70,12);c.fillStyle='#16a34a';c.fillText('Self-financed',pad+170,12);}
let subsidy = quoteData.subsidy_rs;
if (subsidy <= 0 && quoteData.segment === 'RES') subsidy = quoteData.capacity_kwp >= 3 ? quoteData.subsidy_cap_3kw_plus : (quoteData.capacity_kwp >= 2 ? quoteData.subsidy_cap_2kw : 0);
quoteData.subsidy_rs = subsidy;
const out = computeFinanceModel(quoteData);
if (quoteData.monthly_bill_rs <= 0) { document.querySelector('#chart1').insertAdjacentHTML('afterend','<div class="muted">Enter bill to see charts.</div>'); }
document.getElementById('finalPrice').textContent = formatRs(quoteData.quotation_total_rs);
document.getElementById('transportationRs').textContent = formatRs(quoteData.transportation_rs);
document.getElementById('subsidyRs').textContent = formatRs(quoteData.subsidy_rs);
document.getElementById('netCostRs').textContent = formatRs(quoteData.quotation_total_rs - quoteData.subsidy_rs);
document.getElementById('clarityText').textContent = `Total project cost remains ${formatRs(quoteData.quotation_total_rs)}. Bank finance means you pay margin + loan; self finance means you pay the full amount upfront.`;
document.getElementById('marginRs').textContent = formatRs(out.margin_rs);
document.getElementById('loanRs').textContent = formatRs(out.loan_rs);
document.getElementById('emiRs').textContent = formatRs(out.EMI);
document.getElementById('residualRs').textContent = formatRs(out.residual_bill_rs);
document.getElementById('loanOutflowRs').textContent = formatRs(out.loan_monthly_outflow);
document.getElementById('selfUpfrontRs').textContent = formatRs(quoteData.quotation_total_rs);
document.getElementById('selfResidualRs').textContent = formatRs(out.self_monthly_outflow);
document.getElementById('noSolarRs').textContent = formatRs(out.no_solar_monthly_outflow);
document.getElementById('higherSlabInfo').textContent = `For loans above ₹2,00,000 up to ₹6,00,000, typical interest may be ${quoteData.slab2_interest_pct}%+ with ${quoteData.slab2_min_margin_pct}% margin (varies by bank).`;
drawBars(document.getElementById('chart1'), [out.no_solar_monthly_outflow, out.loan_monthly_outflow, out.self_monthly_outflow], ['No Solar','Bank-financed','Self-financed'], ['#ef4444','#2563eb','#16a34a']);
drawLines(document.getElementById('chart2'), out.no_solar_monthly_outflow, out.loan_monthly_outflow, out.self_monthly_outflow);
const annual_savings_rs=(out.no_solar_monthly_outflow-out.residual_bill_rs)*12,payback_years=quoteData.quotation_total_rs/Math.max(annual_savings_rs,0.01),payback_after_subsidy_years=(quoteData.quotation_total_rs-quoteData.subsidy_rs)/Math.max(annual_savings_rs,0.01);
document.getElementById('paybackText').textContent=`Estimated Payback: ${payback_years.toFixed(1)} years. Net-after-subsidy payback: ${payback_after_subsidy_years.toFixed(1)} years.`;
</script>
</body></html>
