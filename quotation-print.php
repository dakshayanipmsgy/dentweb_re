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
$appearance = documents_load_document_appearance();
$assumptionDefaults = documents_load_quotation_assumptions();
$segment = (string) ($quote['segment'] ?? 'RES');
$segmentDefaults = is_array($assumptionDefaults['defaults_by_segment'][$segment] ?? null) ? $assumptionDefaults['defaults_by_segment'][$segment] : $assumptionDefaults['defaults_by_segment']['RES'];
$quoteAss = is_array($quote['assumptions'] ?? null) ? $quote['assumptions'] : [];
$ass = [];
foreach ($segmentDefaults as $k => $v) {
    $ass[$k] = ($quoteAss[$k] ?? null) === null ? $v : (float) $quoteAss[$k];
}
$fontScale = (float) (($quote['rendering']['font_scale'] ?? null) === null ? ($appearance['global']['font_scale'] ?? 1) : $quote['rendering']['font_scale']);
$fontScale = max(0.8, min(1.3, $fontScale));
$snapshot = documents_quote_resolve_snapshot($quote);
$safeHtml = static function (string $value): string {
    $clean = strip_tags($value, '<p><br><ul><ol><li><strong><em><b><i><u><table><thead><tbody><tr><td><th>');
    return $clean !== '' ? $clean : '<span style="color:#666">—</span>';
};
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quotation <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></title>
<style>
:root{--primary:<?= htmlspecialchars((string)($appearance['global']['primary_color'] ?? '#0b5'), ENT_QUOTES) ?>;--accent:<?= htmlspecialchars((string)($appearance['global']['accent_color'] ?? '#0a58ca'), ENT_QUOTES) ?>;--muted:<?= htmlspecialchars((string)($appearance['global']['muted_color'] ?? '#6c757d'), ENT_QUOTES) ?>}
html{font-size:calc(16px * <?= htmlspecialchars((string)$fontScale, ENT_QUOTES) ?>)}
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#0f172a;margin:0}.wrap{max-width:1024px;margin:0 auto;padding:14px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-bottom:12px;break-inside:avoid;page-break-inside:avoid}.top{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}.chips span{display:inline-block;background:#eef2ff;color:#1e40af;padding:4px 10px;border-radius:999px;margin:4px 6px 0 0;font-size:.82rem}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.kpi{background:#f8fafc;padding:10px;border-radius:10px}.muted{color:var(--muted)}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe1ea;padding:8px;text-align:left;vertical-align:top}th{background:#f8fafc}.big{font-size:1.3rem;font-weight:700}.stepper{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}.step{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:6px;text-align:center;font-size:.78rem}
@media print{body{background:#fff}.print-watermark{display:block;position:fixed;inset:0;z-index:0;pointer-events:none;background-image:url('<?= htmlspecialchars((string)($appearance['print_watermark']['image_path'] ?? ''), ENT_QUOTES) ?>');background-position:center;background-size:<?= (int)($appearance['print_watermark']['size_percent'] ?? 70) ?>%;background-repeat:<?= htmlspecialchars((string)($appearance['print_watermark']['repeat'] ?? 'no-repeat'), ENT_QUOTES) ?>;opacity:<?= htmlspecialchars((string)($appearance['print_watermark']['opacity'] ?? 0.08), ENT_QUOTES) ?>}.content{position:relative;z-index:1}}
</style></head>
<body><div class="print-watermark"></div><main class="wrap content">
<div class="card top"><div><?php if ((string)($company['logo_path'] ?? '') !== ''): ?><img src="<?= htmlspecialchars((string)$company['logo_path'], ENT_QUOTES) ?>" style="max-height:56px"><br><?php endif; ?><strong><?= htmlspecialchars((string)($company['brand_name'] ?: $company['company_name']), ENT_QUOTES) ?></strong><br><span class="muted">📞 <?= htmlspecialchars((string)$company['phone_primary'], ENT_QUOTES) ?> · ✉️ <?= htmlspecialchars((string)$company['email'], ENT_QUOTES) ?> · 🌐 <?= htmlspecialchars((string)$company['website'], ENT_QUOTES) ?></span></div><div><h2 style="margin:0">Quotation</h2><div><strong><?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></strong></div><div>Date: <?= htmlspecialchars(substr((string)$quote['created_at'],0,10), ENT_QUOTES) ?></div><div>Valid: <?= htmlspecialchars((string)$quote['valid_until'], ENT_QUOTES) ?></div></div></div>
<div class="card"><strong><?= htmlspecialchars((string)($snapshot['name'] ?: $quote['customer_name']), ENT_QUOTES) ?></strong> · <?= htmlspecialchars((string)($snapshot['mobile'] ?: $quote['customer_mobile']), ENT_QUOTES) ?><br>📍 <?= nl2br(htmlspecialchars((string)($quote['site_address'] ?: $snapshot['address']), ENT_QUOTES)) ?><br>Consumer Account No (JBVNL): <?= htmlspecialchars((string)($quote['consumer_account_no'] ?: $snapshot['consumer_account_no']), ENT_QUOTES) ?></div>
<div class="card chips"><span>MNRE/PM Surya Ghar vendor</span><span>Net metering assistance</span><span>After-sales support</span><span>Local service Jharkhand</span></div>
<div class="card"><h3>Solar Benefit Snapshot</h3><div class="grid"><div class="kpi"><div class="muted">System capacity</div><div class="big"><?= htmlspecialchars((string)$quote['capacity_kwp'], ENT_QUOTES) ?> kWp</div></div><div class="kpi"><div class="muted">Est. generation</div><div class="big" id="annualGeneration"></div></div><div class="kpi"><div class="muted">Est. savings/month</div><div class="big" id="savingsMonth"></div></div><div class="kpi"><div class="muted">Net cost after subsidy</div><div class="big" id="netCost"></div></div><div class="kpi"><div class="muted">Payback (self-financed)</div><div class="big" id="payback"></div></div></div></div>
<div class="card"><h3>Pricing Summary</h3><table><tr><th>Final price (GST incl.)</th><td>₹<?= number_format((float)$quote['calc']['grand_total'],2) ?></td></tr><tr><th>Subsidy expected</th><td id="subsidyExpected"></td></tr><tr><th>Net cost</th><td id="netCost2"></td></tr></table><p class="muted">GST split summary: 5% bucket ₹<?= number_format((float)$quote['calc']['bucket_5_basic'] + (float)$quote['calc']['bucket_5_gst'],2) ?> · 18% bucket ₹<?= number_format((float)$quote['calc']['bucket_18_basic'] + (float)$quote['calc']['bucket_18_gst'],2) ?></p></div>
<div class="card"><h3>EMI vs Bill Psychology</h3><div class="grid"><div class="kpi"><div class="muted">Monthly bill</div><div class="big" id="monthlyBill"></div></div><div class="kpi"><div class="muted">Loan EMI</div><div class="big" id="emi"></div></div><div class="kpi"><div class="muted">Remaining bill after solar</div><div class="big" id="remainingBill"></div></div><div class="kpi"><div class="muted">Net monthly benefit</div><div class="big" id="netBenefit"></div></div></div></div>
<div class="card"><h3>Savings Graphs</h3><svg id="barGraph" viewBox="0 0 600 220" style="width:100%;height:auto"></svg><svg id="lineGraph" viewBox="0 0 600 240" style="width:100%;height:auto"></svg></div>
<div class="card"><h3>Environmental Impact 🌱</h3><div class="grid"><div class="kpi"><div class="muted">CO₂ saved / year</div><div class="big" id="co2Year"></div></div><div class="kpi"><div class="muted">CO₂ saved / 25 years</div><div class="big" id="co225"></div></div><div class="kpi"><div class="muted">Trees equivalent / year</div><div class="big" id="treesYear"></div></div><div class="kpi"><div class="muted">Trees equivalent / 25 years</div><div class="big" id="trees25"></div></div></div></div>
<div class="card"><h3>Special Requests From Customer</h3><?= nl2br(htmlspecialchars((string)$quote['special_requests_inclusive'], ENT_QUOTES)) ?></div>
<?php foreach (['system_inclusions'=>'System Inclusions','payment_terms'=>'Payment Terms','warranty'=>'Warranty','transportation'=>'Transportation','terms_conditions'=>'Terms & Conditions'] as $k=>$heading): ?><div class="card"><h3><?= $heading ?></h3><div><?= $safeHtml((string)($quote['annexures_overrides'][$k] ?? '')) ?></div></div><?php endforeach; ?>
<div class="card"><h3>Timeline & Next Steps</h3><div class="stepper"><div class="step">📝<br>Application</div><div class="step">🔎<br>Survey</div><div class="step">📦<br>Material</div><div class="step">🛠️<br>Installation</div><div class="step">🔌<br>Net Meter</div><div class="step">✅<br>Commissioning</div><div class="step">💸<br>Subsidy</div></div></div>
</main>
<script>
const d={capacity:<?= json_encode((float)($quote['capacity_kwp'] ?: 0)) ?>,total:<?= json_encode((float)$quote['calc']['grand_total']) ?>,ass:<?= json_encode($ass) ?>};
const annualGeneration=d.capacity*d.ass.annual_generation_per_kw; const annualSavings=annualGeneration*d.ass.unit_rate_rs_per_kwh; const monthlySavings=annualSavings/12;
const subsidy=Math.min(d.total*0.3,d.total); const net=Math.max(0,d.total-subsidy); const down=d.total*(d.ass.downpayment_percent/100); const principal=Math.max(0,d.total-down);
const r=(d.ass.loan_interest_percent/100)/12,n=Math.max(1,d.ass.loan_tenure_months); const emi=r===0?principal/n:(principal*r*Math.pow(1+r,n))/(Math.pow(1+r,n)-1);
const remBill=Math.max(0,d.ass.monthly_bill_rs-monthlySavings); const benefit=d.ass.monthly_bill_rs-(emi+remBill); const payback=annualSavings>0?net/annualSavings:0;
const fmt=(v)=>'₹'+v.toLocaleString(undefined,{maximumFractionDigits:0});
document.getElementById('annualGeneration').textContent=Math.round(annualGeneration).toLocaleString()+' kWh/yr';document.getElementById('savingsMonth').textContent=fmt(monthlySavings);document.getElementById('netCost').textContent=fmt(net);document.getElementById('netCost2').textContent=fmt(net);document.getElementById('payback').textContent=(payback>0?payback.toFixed(1):'—')+' years';document.getElementById('subsidyExpected').textContent=fmt(subsidy);document.getElementById('monthlyBill').textContent=fmt(d.ass.monthly_bill_rs);document.getElementById('emi').textContent=fmt(emi);document.getElementById('remainingBill').textContent=fmt(remBill);document.getElementById('netBenefit').textContent=fmt(benefit);
const co2=annualGeneration*d.ass.emission_factor_kg_per_kwh, trees=co2/d.ass.tree_absorption_kg_per_year;document.getElementById('co2Year').textContent=Math.round(co2).toLocaleString()+' kg';document.getElementById('co225').textContent=Math.round(co2*25).toLocaleString()+' kg';document.getElementById('treesYear').textContent=Math.round(trees).toLocaleString();document.getElementById('trees25').textContent=Math.round(trees*25).toLocaleString();
const bar=document.getElementById('barGraph'); const vals=[d.ass.monthly_bill_rs,emi+remBill,remBill], labels=['No Solar','With Loan','Self-funded']; const max=Math.max(...vals,1); let b=''; vals.forEach((v,i)=>{const h=(v/max)*150; const x=70+i*170; b+=`<rect x="${x}" y="${180-h}" width="90" height="${h}" fill="${i===0?'#ef4444':i===1?'#f59e0b':'#16a34a'}"/>`; b+=`<text x="${x+45}" y="198" text-anchor="middle" font-size="12">${labels[i]}</text>`; b+=`<text x="${x+45}" y="${170-h}" text-anchor="middle" font-size="11">₹${Math.round(v)}</text>`}); bar.innerHTML='<line x1="40" y1="180" x2="580" y2="180" stroke="#94a3b8"/>'+b;
let no=0,loan=0,self=net; const ptsNo=[],ptsLoan=[],ptsSelf=[]; for(let y=1;y<=10;y++){const esc=Math.pow(1+d.ass.electricity_escalation_percent/100,y-1); const annualBill=d.ass.monthly_bill_rs*12*esc; const annRem=remBill*12*esc; no+=annualBill; loan+=Math.min(y*12,n)*emi + annRem - (y===1?subsidy:0); self+=annRem; ptsNo.push(no);ptsLoan.push(loan);ptsSelf.push(self);} const maxL=Math.max(...ptsNo,...ptsLoan,...ptsSelf,1);
function path(arr,col){return `<polyline fill="none" stroke="${col}" stroke-width="2" points="${arr.map((v,i)=>`${50+i*50},${200-(v/maxL)*150}`).join(' ')}"/>`;}
document.getElementById('lineGraph').innerHTML='<line x1="40" y1="200" x2="580" y2="200" stroke="#94a3b8"/>'+path(ptsNo,'#ef4444')+path(ptsLoan,'#f59e0b')+path(ptsSelf,'#16a34a')+'<text x="460" y="20" font-size="12" fill="#ef4444">No solar</text><text x="460" y="38" font-size="12" fill="#f59e0b">With loan</text><text x="460" y="56" font-size="12" fill="#16a34a">Self-funded</text>';
</script>
</body></html>
