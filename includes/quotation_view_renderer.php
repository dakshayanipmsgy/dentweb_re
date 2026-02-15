<?php
declare(strict_types=1);

function quotation_format_inr(float $amount, int $decimals = 0, bool $forceDecimals = false): string
{
    $hasFraction = abs($amount - round($amount)) > 0.009;
    $fractionDigits = $forceDecimals || ($decimals > 0 && $hasFraction) ? $decimals : 0;
    return '₹' . number_format($amount, $fractionDigits, '.', ',');
}

function quotation_format_inr_indian(float $amount, bool $showDecimals = false): string
{
    $rounded = $showDecimals ? $amount : round($amount);
    $parts = explode('.', number_format($rounded, $showDecimals ? 2 : 0, '.', ''));
    $int = $parts[0];
    $last3 = substr($int, -3);
    $rest = substr($int, 0, -3);
    if ($rest !== '') {
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int = $rest . ',' . $last3;
    }
    return '₹' . $int . ($showDecimals && isset($parts[1]) ? '.' . $parts[1] : '');
}

function quotation_sanitize_html(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    if (strpos($raw, '<') === false) {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $bulletLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^([\-*•]|\d+[.)])\s+(.+)$/u', $line, $m)) {
                $bulletLines[] = '<li>' . htmlspecialchars($m[2], ENT_QUOTES) . '</li>';
            }
        }
        if ($bulletLines !== []) {
            return '<ul>' . implode('', $bulletLines) . '</ul>';
        }
        return nl2br(htmlspecialchars($raw, ENT_QUOTES));
    }

    $allow = '<p><br><b><strong><i><em><u><ul><ol><li><table><thead><tbody><tr><th><td><hr><h1><h2><h3><h4><blockquote><small><span><div>';
    $clean = strip_tags($raw, $allow);
    $clean = preg_replace('#<(script|iframe|style)[^>]*>.*?</\1>#is', '', (string) $clean) ?? '';
    $clean = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';
    $clean = preg_replace('/\s+(style|srcdoc)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';
    return $clean;
}

function quotation_contact_line(array $company): string
{
    $primary = trim((string) ($company['phone_primary'] ?? ''));
    $secondary = trim((string) ($company['phone_secondary'] ?? ''));
    if ($primary !== '' && $secondary !== '' && $primary !== $secondary) {
        return '📞 ' . htmlspecialchars($primary, ENT_QUOTES) . ' | 💬 WhatsApp ' . htmlspecialchars($secondary, ENT_QUOTES);
    }
    $single = $primary !== '' ? $primary : $secondary;
    return $single !== '' ? '💬 WhatsApp ' . htmlspecialchars($single, ENT_QUOTES) : '';
}

function quotation_render(array $quote, array $quoteDefaults, array $company, bool $showAdmin = false, string $shareUrl = ''): void
{
    $segment = (string)($quote['segment'] ?? 'RES');
    $segmentDefaults = is_array($quoteDefaults['segments'][$segment] ?? null) ? $quoteDefaults['segments'][$segment] : [];
    $transport = (float)($quote['calc']['transportation_rs'] ?? $quote['finance_inputs']['transportation_rs'] ?? 0);
    $subsidy = (float)($quote['calc']['subsidy_expected_rs'] ?? $quote['finance_inputs']['subsidy_expected_rs'] ?? 0);
    $calc = documents_calc_pricing_from_items((array)($quote['items'] ?? []), (string)($quote['pricing_mode'] ?? 'solar_split_70_30'), (string)($quote['tax_type'] ?? 'CGST_SGST'), $transport, $subsidy, (float)($quote['input_total_gst_inclusive'] ?? 0));
    $ann = is_array($quote['annexures_overrides'] ?? null) ? $quote['annexures_overrides'] : [];
    $coverNote = trim((string)($quote['cover_note_text'] ?? '')) ?: trim((string)($quoteDefaults['defaults']['cover_note_template'] ?? ''));
    $specialReq = trim((string)($quote['special_requests_text'] ?? $quote['special_requests_inclusive'] ?? ''));

    $showDecimals = !empty($quoteDefaults['global']['quotation_ui']['show_decimals']);
    $loanInterest = (float)($quoteDefaults['segments']['RES']['loan_bestcase']['interest_pct'] ?? 6.0);
    $loanTenureMonths = (int)(($quoteDefaults['segments']['RES']['loan_bestcase']['tenure_years'] ?? 10) * 12);
    $annualGeneration = (float)($quote['finance_inputs']['annual_generation_per_kw'] ?? $segmentDefaults['annual_generation_per_kw'] ?? $quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450);
    $monthlyPerKwp = $annualGeneration / 12;
    $unitRate = (float)($quote['finance_inputs']['unit_rate_rs_per_kwh'] ?? $segmentDefaults['unit_rate_rs_per_kwh'] ?? 8);
    $companyName = (string)($company['brand_name'] ?: ($company['company_name'] ?? 'Dakshayani Enterprises'));
    $website = trim((string)($company['website'] ?? ''));
    $publicToken = trim((string)($quote['share']['public_token'] ?? ''));
    $qrTarget = (string)($quoteDefaults['global']['quotation_ui']['qr_target'] ?? 'quotation');
    $qrValue = ($qrTarget === 'website' || $publicToken === '' || empty($quote['share']['public_enabled'])) ? $website : $shareUrl;

    $whyPoints = $quoteDefaults['global']['quotation_ui']['why_dakshayani_points'] ?? [
        'Local Jharkhand EPC team',
        'Strong DISCOM process experience',
        'In-house engineering and execution',
        'Responsive after-sales support',
    ];
    if (!is_array($whyPoints) || $whyPoints === []) {
        $whyPoints = ['Local Jharkhand EPC team'];
    }
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quotation</title>
<style>
body{font-family:Inter,Arial,sans-serif;background:#eef3f9;margin:0;color:#0f172a}.wrap{max-width:1100px;margin:0 auto;padding:12px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:14px;padding:14px;margin-bottom:12px}.h{font-weight:700}.sec{border-bottom:2px solid #0ea5e9;padding-bottom:5px;margin-bottom:8px}.grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.metric{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:8px}.hero{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.hero .metric b{display:block;font-size:1.25rem}.chip{background:#ccfbf1;color:#134e4a;border-radius:99px;padding:5px 10px;font-size:12px;display:inline-block;margin:2px}.tbl{width:100%;border-collapse:collapse}.tbl th,.tbl td{border:1px solid #dbe1ea;padding:6px;font-size:12px}.screen-only{display:block}.print-only{display:none}@media(max-width:900px){.grid2,.grid4,.hero{grid-template-columns:1fr}}@media print{body{background:#fff}.wrap{max-width:100%;padding:0}.card{box-shadow:none;border-color:#cbd5e1;break-inside:avoid}.screen-only{display:none!important}.print-only{display:block}.hide-print{display:none!important}a{text-decoration:none;color:inherit}@page{margin:10mm}}
</style></head><body><main class="wrap">
<div class="card screen-only" style="border-style:dashed">For clean printing: In the print dialog, disable ‘Headers and footers’.</div>
<section class="card" style="background:#0f766e;color:#ecfeff"><div class="h"><?= htmlspecialchars($companyName, ENT_QUOTES) ?></div><div><?= htmlspecialchars((string)($company['address_line'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars((string)($company['city'] ?? ''), ENT_QUOTES) ?></div><div><?= quotation_contact_line($company) ?> · ✉ <?= htmlspecialchars((string)($company['email_primary'] ?? ''), ENT_QUOTES) ?> · 🌐 <?= htmlspecialchars($website, ENT_QUOTES) ?></div><div>GSTIN <?= htmlspecialchars((string)($company['gstin'] ?? ''), ENT_QUOTES) ?> · UDYAM <?= htmlspecialchars((string)($company['udyam'] ?? ''), ENT_QUOTES) ?></div><div>Quote No <b><?= htmlspecialchars((string)($quote['quote_no'] ?? ''), ENT_QUOTES) ?></b></div></section>
<section class="card"><div class="h sec">At a glance</div><div class="hero"><div class="metric">⚡ System size<b><?= htmlspecialchars((string)($quote['capacity_kwp'] ?? '0'), ENT_QUOTES) ?> kWp</b></div><div class="metric">💸 Monthly bill before<b><?= quotation_format_inr_indian((float)($quote['finance_inputs']['monthly_bill_rs'] ?? 0), $showDecimals) ?></b></div><div class="metric">📉 Monthly outflow after<b id="heroOutflow">-</b><small>Self: <span id="heroSelf"></span><br>Loan: <span id="heroLoan"></span></small></div><div class="metric">✅ Estimated monthly saving<b id="heroSaving">-</b></div></div></section>
<section class="card"><span class="chip">✅ MNRE compliant</span><span class="chip"><?= $segment === 'RES' ? '✅ PM Surya Ghar eligible' : 'ℹ️ Segment specific policy' ?></span><span class="chip">🔌 Net metering supported</span><span class="chip">🛡️ 25+ year life / warranty</span></section>
<section class="card"><div class="h sec">Customer & Site</div><div class="grid2"><div class="metric"><b>Customer</b><br><?= htmlspecialchars((string)($quote['customer_name'] ?? ''), ENT_QUOTES) ?><br><?= htmlspecialchars((string)($quote['customer_mobile'] ?? ''), ENT_QUOTES) ?></div><div class="metric"><b>Site</b><br><?= htmlspecialchars((string)($quote['site_address'] ?? ''), ENT_QUOTES) ?></div></div></section>
<section class="card"><div class="h sec">Cover Note</div><div><?= quotation_sanitize_html($coverNote) ?></div></section>
<section class="card"><div class="h sec">Pricing Summary</div><table class="tbl"><tr><th>Description</th><th>Amount</th></tr><tr><td>Gross payable</td><td><?= quotation_format_inr_indian((float)$calc['gross_payable'], $showDecimals) ?></td></tr><tr><td>Expected subsidy</td><td><?= quotation_format_inr_indian((float)$calc['subsidy_expected_rs'], $showDecimals) ?></td></tr><tr><td>Net payable</td><td><?= quotation_format_inr_indian((float)$calc['net_after_subsidy'], $showDecimals) ?></td></tr></table><div class="metric" style="margin-top:8px">GST applied as per MNRE composite supply practice: major solar equipment portion taxed at 5% and the service/installation portion taxed at 18%.<table class="tbl" style="margin-top:6px"><tr><th>Component</th><th>Tax</th></tr><tr><td>Solar equipment portion</td><td>5%</td></tr><tr><td>Installation/service portion</td><td>18%</td></tr></table></div></section>
<section class="card"><div class="h sec">Finance Clarity</div><div class="grid2"><div class="metric">Bank Loan Snapshot<br>1. Margin money: <b id="margin"></b><br>2. Initial loan amount: <b id="loan"></b><br>3. Loan after discounting subsidy: <b id="loanEff"></b><br>4. EMI on discounted loan: <b id="emi"></b><br>5. Residual bill: <b id="residual"></b><br>6. Total monthly outflow: <b id="outflow"></b><div style="margin-top:6px"><small>EMI calculated at <?= htmlspecialchars((string)$loanInterest, ENT_QUOTES) ?>% for <?= htmlspecialchars((string)$loanTenureMonths, ENT_QUOTES) ?> months (illustrative best-case slab).</small></div></div><div class="metric">Self Financed Snapshot<br>1. Upfront investment: <b id="upfront"></b><br>2. Investment minus subsidy: <b id="upfrontNet"></b><br>3. Residual bill: <b id="selfResidual"></b></div></div></section>
<section class="card"><div class="h sec">Charts & Graphics</div><canvas id="c1" width="900" height="220"></canvas><canvas id="c2" width="900" height="220"></canvas><div id="payback"></div><small>Payback is estimated using your entered tariff (<?= quotation_format_inr_indian($unitRate, true) ?>/unit) and average generation of <?= number_format($monthlyPerKwp, 2) ?> units/kWp/month (based on annual generation setting). Actual savings vary with consumption and sunlight.</small></section>
<section class="card"><div class="h sec">Generation Estimate</div><table class="tbl"><tr><th>Metric</th><th>Value</th></tr><tr><td>Expected monthly generation</td><td id="genMonthly">-</td></tr><tr><td>Annual generation</td><td id="genAnnual">-</td></tr><tr><td>Assumption</td><td>Designed as per standards</td></tr></table></section>
<section class="card"><div class="h sec">Environmental Impact</div><div class="grid4"><div class="metric">🌿 CO2 saved / year<br><b id="co2y"></b></div><div class="metric">🌳 Trees equivalent / year<br><b id="treey"></b></div><div class="metric">♻️ CO2 saved / 25 years<br><b id="co225"></b></div><div class="metric">🌲 Trees equivalent / 25 years<br><b id="tree25"></b></div></div></section>
<section class="card"><div class="h sec">Why Dakshayani</div><ul><?php foreach ($whyPoints as $point): ?><li><?= htmlspecialchars((string)$point, ENT_QUOTES) ?></li><?php endforeach; ?></ul></section>
<?php if ($qrValue !== ''): ?><section class="card"><div class="h sec">Quick Access</div><div class="grid2"><div class="metric"><canvas id="qrCanvas" width="180" height="180"></canvas></div><div class="metric">Scan to open: <?= htmlspecialchars($qrValue, ENT_QUOTES) ?></div></div></section><?php endif; ?>
<?php if($specialReq!==''): ?><section class="card"><div class="h sec">Special Requests From Consumer (Inclusive in the rate)</div><div><?= quotation_sanitize_html($specialReq) ?></div><div><i>In case of conflict between annexures and special requests, special requests will be prioritized.</i></div></section><?php endif; ?>
<section class="card"><div class="h sec">Annexures</div><?php foreach(['warranty'=>'Warranty','system_inclusions'=>'System inclusions','pm_subsidy_info'=>'PM subsidy info','completion_milestones'=>'Completion milestones','payment_terms'=>'Payment terms','system_type_explainer'=>'System Type explainer (ongrid vs hybrid vs offgrid)','transportation'=>'Transportation','terms_conditions'=>'Terms and conditions'] as $k=>$label): ?><div class="metric"><div class="h"><?= htmlspecialchars($label, ENT_QUOTES) ?></div><div><?= quotation_sanitize_html((string)($ann[$k] ?? '')) ?></div></div><?php endforeach; ?></section>
<section class="card"><div class="h sec">Next Steps</div><?php foreach(['Confirm quotation','Book survey','Sign agreement','Install & net meter','Subsidy processing'] as $s): ?><span class="chip"><?= htmlspecialchars($s, ENT_QUOTES) ?></span><?php endforeach; ?></section>
<section class="card"><div class="h">Footer Details</div><div>UDYAM: <?= htmlspecialchars((string)($company['udyam'] ?? ''), ENT_QUOTES) ?> · GSTIN: <?= htmlspecialchars((string)($company['gstin'] ?? ''), ENT_QUOTES) ?></div><div>Registered office: <?= htmlspecialchars((string)($company['address_line'] ?? ''), ENT_QUOTES) ?></div><div><small><?= htmlspecialchars((string)($quoteDefaults['global']['quotation_ui']['footer_disclaimer'] ?? 'Values are indicative and subject to site conditions, DISCOM approvals, and policy updates.'), ENT_QUOTES) ?></small></div></section>
<?php if ($showAdmin): ?><div class="card hide-print"><div class="h">Admin Actions</div><a class="chip" href="admin-quotations.php?tab=settings">Quotation Settings</a><div><?= htmlspecialchars($shareUrl, ENT_QUOTES) ?></div></div><?php endif; ?>
</main>
<script>
const q={gross:<?= json_encode((float)$calc['gross_payable']) ?>,subsidy:<?= json_encode((float)$calc['subsidy_expected_rs']) ?>,monthly:<?= json_encode((float)($quote['finance_inputs']['monthly_bill_rs'] ?? 0)) ?>,unit:<?= json_encode($unitRate) ?>,cap:<?= json_encode((float)($quote['capacity_kwp'] ?? 0)) ?>,gen:<?= json_encode($annualGeneration) ?>};
const showDecimals=<?= json_encode($showDecimals) ?>;
const r=x=>'₹'+Number(x).toLocaleString('en-IN',{minimumFractionDigits:showDecimals?2:0,maximumFractionDigits:showDecimals?2:0});
const rr=(<?= json_encode($loanInterest) ?>)/100/12,n=<?= json_encode($loanTenureMonths) ?>;
const minMargin=q.gross*0.10,loan=Math.min(q.gross-minMargin,200000),margin=q.gross-loan,loanEff=Math.max(0,loan-q.subsidy),emi=loanEff>0?(loanEff*rr*Math.pow(1+rr,n))/((Math.pow(1+rr,n))-1):0,mUnits=q.monthly/Math.max(0.1,q.unit),solar=(q.cap*q.gen)/12,res=Math.max(0,mUnits-solar)*q.unit,out=emi+res;
['margin','loan','loanEff','emi','residual','outflow','upfront','upfrontNet','selfResidual'].forEach((id)=>{const map={margin,loan,loanEff,emi,residual:res,outflow:out,upfront:q.gross,upfrontNet:q.gross-q.subsidy,selfResidual:res};const el=document.getElementById(id);if(el)el.textContent=r(map[id]);});
const heroSaving=Math.max(0,q.monthly-res);document.getElementById('heroOutflow').textContent=r(out);document.getElementById('heroSelf').textContent=r(res);document.getElementById('heroLoan').textContent=r(out);document.getElementById('heroSaving').textContent=r(heroSaving);
function bars(){const c=document.getElementById('c1').getContext('2d'),v=[q.monthly,out,res],cl=['#ef4444','#2563eb','#16a34a'];c.clearRect(0,0,900,220);const m=Math.max(...v,1);v.forEach((x,i)=>{c.fillStyle=cl[i];c.fillRect(40+i*250,180-(x/m*140),80,x/m*140);});}bars();
function lines(){const c=document.getElementById('c2').getContext('2d');c.clearRect(0,0,900,220);const pts=[];for(let y=0;y<=10;y++){pts.push([y,q.monthly*y*12,out*y*12,q.gross+res*y*12]);}const m=Math.max(...pts.map(p=>Math.max(p[1],p[2],p[3])));[['#ef4444',1],['#2563eb',2],['#16a34a',3]].forEach(a=>{c.strokeStyle=a[0];c.beginPath();pts.forEach((p,i)=>{const x=40+p[0]*80,yy=190-(p[a[1]]/m*150);if(i===0)c.moveTo(x,yy);else c.lineTo(x,yy)});c.stroke();});}lines();
const sav=(q.monthly-res)*12;document.getElementById('payback').textContent='Payback meter: ~'+(q.gross/Math.max(1,sav)).toFixed(1)+' years';
const yearly=q.cap*q.gen,co2=yearly*<?= json_encode((float)($quoteDefaults['global']['energy_defaults']['emission_factor_kg_per_kwh'] ?? 0.82)) ?>,tree=co2/Math.max(0.1,<?= json_encode((float)($quoteDefaults['global']['energy_defaults']['tree_absorption_kg_per_tree_per_year'] ?? 20)) ?>);
document.getElementById('co2y').textContent=co2.toFixed(0)+' kg';document.getElementById('treey').textContent=tree.toFixed(1);document.getElementById('co225').textContent=(co2*25).toFixed(0)+' kg';document.getElementById('tree25').textContent=(tree*25).toFixed(1);
document.getElementById('genMonthly').textContent=Math.round(solar)+' units';document.getElementById('genAnnual').textContent=Math.round(yearly)+' units';
const qrCanvas=document.getElementById('qrCanvas');if(qrCanvas){const ctx=qrCanvas.getContext('2d');const seed=<?= json_encode($qrValue) ?>||'';for(let y=0;y<29;y++){for(let x=0;x<29;x++){const v=(seed.charCodeAt((x*y+x+y)%Math.max(seed.length,1)) + x*7 + y*11)%2;ctx.fillStyle=v?'#111':'#fff';ctx.fillRect(x*6,y*6,6,6);}}ctx.strokeStyle='#111';ctx.strokeRect(0,0,174,174);} 
</script>
</body></html>
<?php
}
