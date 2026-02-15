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

function quotation_normalize_whatsapp(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) < 10) {
        return '';
    }
    if (strlen($digits) > 12) {
        $digits = substr($digits, -12);
    }
    return $digits;
}

function quotation_shadow_css(string $preset): string
{
    $map = [
        'none' => 'none',
        'soft' => '0 8px 18px rgba(15, 23, 42, 0.06)',
        'medium' => '0 10px 24px rgba(15, 23, 42, 0.10)',
        'strong' => '0 14px 30px rgba(15, 23, 42, 0.16)',
    ];
    return $map[$preset] ?? $map['soft'];
}

function quotation_render(array $quote, array $quoteDefaults, array $company, bool $showAdmin = false, string $shareUrl = ''): void
{
    $segment = (string) ($quote['segment'] ?? 'RES');
    $segmentDefaults = is_array($quoteDefaults['segments'][$segment] ?? null) ? $quoteDefaults['segments'][$segment] : [];
    $transport = (float) ($quote['calc']['transportation_rs'] ?? $quote['finance_inputs']['transportation_rs'] ?? 0);
    $subsidy = (float) ($quote['calc']['subsidy_expected_rs'] ?? $quote['finance_inputs']['subsidy_expected_rs'] ?? 0);
    $calc = documents_calc_pricing_from_items((array) ($quote['items'] ?? []), (string) ($quote['pricing_mode'] ?? 'solar_split_70_30'), (string) ($quote['tax_type'] ?? 'CGST_SGST'), $transport, $subsidy, (float) ($quote['input_total_gst_inclusive'] ?? 0));
    $ann = is_array($quote['annexures_overrides'] ?? null) ? $quote['annexures_overrides'] : [];
    $coverNote = trim((string) ($quote['cover_note_text'] ?? '')) ?: trim((string) ($quoteDefaults['defaults']['cover_note_template'] ?? ''));
    $specialReq = trim((string) ($quote['special_requests_text'] ?? $quote['special_requests_inclusive'] ?? ''));

    $tokens = is_array($quoteDefaults['global']['ui_tokens'] ?? null) ? $quoteDefaults['global']['ui_tokens'] : [];
    $colors = is_array($tokens['colors'] ?? null) ? $tokens['colors'] : [];
    $grads = is_array($tokens['gradients'] ?? null) ? $tokens['gradients'] : [];
    $headerFooter = is_array($tokens['header_footer'] ?? null) ? $tokens['header_footer'] : [];
    $typo = is_array($tokens['typography'] ?? null) ? $tokens['typography'] : [];

    $showDecimals = !empty($quoteDefaults['global']['quotation_ui']['show_decimals']);

    $loanDefaults = is_array($segmentDefaults['loan_bestcase'] ?? null) ? $segmentDefaults['loan_bestcase'] : [];
    $loanOverrides = is_array($quote['finance_inputs']['loan'] ?? null) ? $quote['finance_inputs']['loan'] : [];
    $loanInterest = (float) ($loanOverrides['interest_pct'] ?? $loanDefaults['interest_pct'] ?? $quoteDefaults['segments']['RES']['loan_bestcase']['interest_pct'] ?? 6.0);
    $loanTenureYears = (float) ($loanOverrides['tenure_years'] ?? $loanDefaults['tenure_years'] ?? $quoteDefaults['segments']['RES']['loan_bestcase']['tenure_years'] ?? 10);
    $loanTenureMonths = max(1, (int) round($loanTenureYears * 12));

    $annualGeneration = (float) ($quote['finance_inputs']['annual_generation_per_kw'] ?? $segmentDefaults['annual_generation_per_kw'] ?? $quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450);
    $monthlyPerKwp = $annualGeneration / 12;
    $unitRate = (float) ($quote['finance_inputs']['unit_rate_rs_per_kwh'] ?? $segmentDefaults['unit_rate_rs_per_kwh'] ?? $quoteDefaults['segments']['RES']['unit_rate_rs_per_kwh'] ?? 8);
    $emissionFactor = (float) ($quoteDefaults['global']['energy_defaults']['emission_factor_kg_per_kwh'] ?? 0.82);
    $treeAbsorption = (float) ($quoteDefaults['global']['energy_defaults']['tree_absorption_kg_per_tree_per_year'] ?? 20);

    $rawSubsidyInput = $quote['finance_inputs']['subsidy_expected_rs'] ?? $quote['calc']['subsidy_expected_rs'] ?? null;
    $subsidyProvided = !( $rawSubsidyInput === null || (is_string($rawSubsidyInput) && trim($rawSubsidyInput) === ''));

    $companyName = (string) ($company['brand_name'] ?: ($company['company_name'] ?? 'Dakshayani Enterprises'));
    $website = trim((string) ($company['website'] ?? ''));
    $phonePrimary = trim((string) ($company['phone_primary'] ?? ''));
    $whatsappRaw = trim((string) ($company['whatsapp_number'] ?? ''));
    $whatsappDisplay = $whatsappRaw !== '' ? $whatsappRaw : trim((string) ($company['phone_secondary'] ?? ''));
    $phoneBits = [];
    if ($phonePrimary !== '') {
        $phoneBits[] = '📞 ' . htmlspecialchars($phonePrimary, ENT_QUOTES);
    }
    if ($whatsappDisplay !== '' && $whatsappDisplay !== $phonePrimary) {
        $phoneBits[] = '💬 WhatsApp ' . htmlspecialchars($whatsappDisplay, ENT_QUOTES);
    }
    $whatsappDigits = quotation_normalize_whatsapp($whatsappDisplay);
    $waLink = $whatsappDigits !== '' ? 'https://wa.me/' . $whatsappDigits : '';

    $whyPoints = $quoteDefaults['global']['quotation_ui']['why_dakshayani_points'] ?? ['Local Jharkhand EPC team'];
    if (!is_array($whyPoints) || $whyPoints === []) {
        $whyPoints = ['Local Jharkhand EPC team'];
    }

    $headerGrad = is_array($grads['header'] ?? null) ? $grads['header'] : [];
    $footerGrad = is_array($grads['footer'] ?? null) ? $grads['footer'] : [];
    $headerBg = !empty($headerGrad['enabled'])
        ? 'linear-gradient(' . ((string) ($headerGrad['direction'] ?? 'to right')) . ', ' . ((string) ($headerGrad['a'] ?? '#0ea5e9')) . ', ' . ((string) ($headerGrad['b'] ?? '#22c55e')) . ')'
        : (string) ($quoteDefaults['global']['branding']['header_bg'] ?? '#0f766e');
    $footerBg = !empty($footerGrad['enabled'])
        ? 'linear-gradient(' . ((string) ($footerGrad['direction'] ?? 'to right')) . ', ' . ((string) ($footerGrad['a'] ?? '#0ea5e9')) . ', ' . ((string) ($footerGrad['b'] ?? '#22c55e')) . ')'
        : (string) ($quoteDefaults['global']['branding']['footer_bg'] ?? '#0f172a');

    $headerText = (string) ($headerFooter['header_text_color'] ?? '#ffffff');
    $footerText = (string) ($headerFooter['footer_text_color'] ?? '#ffffff');
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quotation</title>
<style>
:root{--color-primary:<?= htmlspecialchars((string)($colors['primary'] ?? '#0ea5e9'), ENT_QUOTES) ?>;--color-accent:<?= htmlspecialchars((string)($colors['accent'] ?? '#22c55e'), ENT_QUOTES) ?>;--color-text:<?= htmlspecialchars((string)($colors['text'] ?? '#0f172a'), ENT_QUOTES) ?>;--color-muted:<?= htmlspecialchars((string)($colors['muted_text'] ?? '#475569'), ENT_QUOTES) ?>;--page-bg:<?= htmlspecialchars((string)($colors['page_bg'] ?? '#f8fafc'), ENT_QUOTES) ?>;--card-bg:<?= htmlspecialchars((string)($colors['card_bg'] ?? '#ffffff'), ENT_QUOTES) ?>;--border-color:<?= htmlspecialchars((string)($colors['border'] ?? '#e2e8f0'), ENT_QUOTES) ?>;--base-font-size:<?= (int)($typo['base_px'] ?? 14) ?>px;--h1-size:<?= (int)($typo['h1_px'] ?? 24) ?>px;--h2-size:<?= (int)($typo['h2_px'] ?? 18) ?>px;--h3-size:<?= (int)($typo['h3_px'] ?? 16) ?>px;--line-height:<?= (float)($typo['line_height'] ?? 1.6) ?>;--shadow-preset:<?= quotation_shadow_css((string)($tokens['shadow'] ?? 'soft')) ?>;--header-bg:<?= htmlspecialchars($headerBg, ENT_QUOTES) ?>;--footer-bg:<?= htmlspecialchars($footerBg, ENT_QUOTES) ?>;--header-text-color:<?= htmlspecialchars($headerText, ENT_QUOTES) ?>;--footer-text-color:<?= htmlspecialchars($footerText, ENT_QUOTES) ?>}
body{font-family:Inter,Arial,sans-serif;background:var(--page-bg);margin:0;color:var(--color-text);font-size:var(--base-font-size);line-height:var(--line-height)}
h1{font-size:var(--h1-size)}h2{font-size:var(--h2-size)}h3{font-size:var(--h3-size)}.wrap{max-width:1100px;margin:0 auto;padding:12px}.card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:14px;padding:14px;margin-bottom:12px;box-shadow:var(--shadow-preset)}.h{font-weight:700}.sec{border-bottom:2px solid var(--color-primary);padding-bottom:5px;margin-bottom:8px}.grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.metric{background:var(--page-bg);border:1px solid var(--border-color);border-radius:10px;padding:8px}.hero{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.hero .metric b{display:block;font-size:1.2rem;margin-top:4px}.save-line{margin-top:10px;padding:10px 12px;border:1px solid #86efac;background:#f0fdf4;color:#166534;border-radius:10px;font-weight:700}.chip{background:#ccfbf1;color:#134e4a;border-radius:99px;padding:5px 10px;display:inline-block;margin:3px 6px 0 0}.muted{color:var(--color-muted)}table{width:100%;border-collapse:collapse}th,td{border:1px solid var(--border-color);padding:8px;text-align:left}th{background:#f8fafc}.right{text-align:right}.center{text-align:center}.footer{background:var(--footer-bg);color:var(--footer-text-color)}.footer a,.footer a:visited{color:var(--footer-text-color)}.header{background:var(--header-bg);color:var(--header-text-color)}.header a,.header a:visited{color:var(--header-text-color)}.screen-only{display:block}.hide-print{display:block}@media print{.hide-print,.screen-only{display:none!important}.card{break-inside:avoid;box-shadow:none}}</style>
</head><body><main class="wrap">
<div class="card screen-only" style="border-style:dashed">For clean printing: disable ‘Headers and footers’ in Print settings.</div>
<section class="card header"><div class="h"><?= htmlspecialchars($companyName, ENT_QUOTES) ?></div><div><?= htmlspecialchars((string)($company['address_line'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars((string)($company['city'] ?? ''), ENT_QUOTES) ?></div><div><?= implode(' | ', $phoneBits) ?><?= $phoneBits !== [] ? ' | ' : '' ?>✉️ <?= htmlspecialchars((string)($company['email_primary'] ?? ''), ENT_QUOTES) ?> · 🌐 <?= htmlspecialchars($website, ENT_QUOTES) ?><?= $waLink !== '' ? ' · <a href="' . htmlspecialchars($waLink, ENT_QUOTES) . '">Chat</a>' : '' ?></div><div>GSTIN <?= htmlspecialchars((string)($company['gstin'] ?? ''), ENT_QUOTES) ?> · UDYAM <?= htmlspecialchars((string)($company['udyam'] ?? ''), ENT_QUOTES) ?> · PAN <?= htmlspecialchars((string)($company['pan'] ?? ''), ENT_QUOTES) ?></div><div>Quote No <b><?= htmlspecialchars((string)($quote['quote_no'] ?? ''), ENT_QUOTES) ?></b></div></section>
<section class="card"><div class="h sec">At a glance</div><div class="hero"><div class="metric">System Size<b><?= htmlspecialchars((string)($quote['capacity_kwp'] ?? '0'), ENT_QUOTES) ?> kWp</b></div><div class="metric">Monthly Bill (Without Solar)<b><?= quotation_format_inr_indian((float)($quote['finance_inputs']['monthly_bill_rs'] ?? 0), $showDecimals) ?></b></div><div class="metric">Monthly Outflow (With Solar – Bank Finance)<b id="heroOutflowBank">-</b></div><div class="metric">Monthly Outflow (With Solar – Self Funded)<b id="heroOutflowSelf">-</b></div></div><div class="save-line">🟢 You save approx <span id="heroSaving">-</span> every month</div></section>
<section class="card"><span class="chip">✅ MNRE compliant</span><span class="chip"><?= $segment === 'RES' ? '✅ PM Surya Ghar eligible' : 'ℹ️ Segment specific policy' ?></span><span class="chip">🔌 Net metering supported</span><span class="chip">🛡️ 25+ year life / warranty</span></section>
<section class="card"><div class="h sec">Customer & Site</div><div class="grid2"><div class="metric"><b>Customer</b><div><?= htmlspecialchars((string)($quote['customer_name'] ?? ''), ENT_QUOTES) ?></div><div class="muted"><?= htmlspecialchars((string)($quote['customer_mobile'] ?? ''), ENT_QUOTES) ?></div></div><div class="metric"><b>Site</b><div><?= htmlspecialchars((string)($quote['site_address'] ?? ''), ENT_QUOTES) ?></div><div class="muted"><?= htmlspecialchars((string)($quote['district'] ?? ''), ENT_QUOTES) ?></div></div></div></section>
<section class="card"><div class="h sec">Pricing</div><table><thead><tr><th>#</th><th>Particular</th><th class="right">Amount</th></tr></thead><tbody><tr><td>1</td><td>Gross payable</td><td class="right" id="upfront"></td></tr><tr><td>2</td><td>Subsidy expected</td><td class="right"><?= quotation_format_inr_indian((float)($calc['subsidy_expected_rs'] ?? 0), $showDecimals) ?></td></tr><tr><td>3</td><td>Net upfront</td><td class="right" id="upfrontNet"></td></tr></tbody></table></section>
<section class="card"><div class="h sec">Finance snapshot</div><div class="grid4"><div class="metric">Interest<b><?= htmlspecialchars((string)$loanInterest, ENT_QUOTES) ?>%</b></div><div class="metric">Tenure<b><?= htmlspecialchars((string)$loanTenureYears, ENT_QUOTES) ?> yrs</b></div><div class="metric">Annual generation/kW<b><?= htmlspecialchars((string)$annualGeneration, ENT_QUOTES) ?></b></div><div class="metric">Unit rate<b><?= quotation_format_inr_indian($unitRate, $showDecimals) ?></b></div></div><div class="grid4" style="margin-top:8px"><div class="metric">Margin<b id="margin">-</b></div><div class="metric">Loan eligible<b id="loan">-</b></div><div class="metric">Effective loan<b id="loanEff">-</b></div><div class="metric">EMI<b id="emi">-</b></div></div></section>
<section class="card"><div class="h sec">Estimated generation & environment</div><div class="grid4"><div class="metric">Monthly generation<b id="genMonthly">-</b></div><div class="metric">Annual generation<b id="genAnnual">-</b></div><div class="metric">CO₂/year<b id="co2y">-</b></div><div class="metric">Trees/year<b id="treey">-</b></div></div><div class="grid4" style="margin-top:8px"><div class="metric">CO₂ over 25 years<b id="co225">-</b></div><div class="metric">Trees over 25 years<b id="tree25">-</b></div><div class="metric">Payback<b id="payback">-</b></div><div class="metric">Monthly per kWp<b><?= quotation_format_inr_indian($monthlyPerKwp, $showDecimals) ?> units</b></div></div></section>
<section class="card"><div class="h sec">Why <?= htmlspecialchars($companyName, ENT_QUOTES) ?></div><ul><?php foreach ($whyPoints as $point): ?><li><?= htmlspecialchars((string)$point, ENT_QUOTES) ?></li><?php endforeach; ?></ul></section>
<?php if($specialReq!==''): ?><section class="card"><div class="h sec">Special Requests From Consumer (Inclusive in the rate)</div><div><?= quotation_sanitize_html($specialReq) ?></div><div><i>In case of conflict between annexures and special requests, special requests will be prioritized.</i></div></section><?php endif; ?>
<section class="card"><div class="h sec">Annexures</div><?php foreach(['warranty'=>'Warranty','system_inclusions'=>'System inclusions','pm_subsidy_info'=>'PM subsidy info','completion_milestones'=>'Completion milestones','payment_terms'=>'Payment terms','system_type_explainer'=>'System Type explainer (ongrid vs hybrid vs offgrid)','transportation'=>'Transportation','terms_conditions'=>'Terms and conditions'] as $k=>$label): ?><div class="metric"><div class="h"><?= htmlspecialchars($label, ENT_QUOTES) ?></div><div><?= quotation_sanitize_html((string)($ann[$k] ?? '')) ?></div></div><?php endforeach; ?></section>
<section class="card footer"><div class="h">Footer Details</div><div>Registered office: <?= htmlspecialchars((string)($company['address_line'] ?? ''), ENT_QUOTES) ?></div><div>📞 <?= htmlspecialchars((string)($company['phone_primary'] ?? ''), ENT_QUOTES) ?><?= $whatsappDisplay !== '' ? ' · 💬 ' . htmlspecialchars($whatsappDisplay, ENT_QUOTES) : '' ?> · ✉️ <?= htmlspecialchars((string)($company['email_primary'] ?? ''), ENT_QUOTES) ?> · 🌐 <?= htmlspecialchars($website, ENT_QUOTES) ?></div><div>GSTIN: <?= htmlspecialchars((string)($company['gstin'] ?? ''), ENT_QUOTES) ?> · UDYAM: <?= htmlspecialchars((string)($company['udyam'] ?? ''), ENT_QUOTES) ?> · PAN: <?= htmlspecialchars((string)($company['pan'] ?? ''), ENT_QUOTES) ?></div><div>JREDA: <?= htmlspecialchars((string)($company['jreda_license'] ?? ''), ENT_QUOTES) ?> · DWSD: <?= htmlspecialchars((string)($company['dwsd_license'] ?? ''), ENT_QUOTES) ?></div><div><small><?= htmlspecialchars((string)($quoteDefaults['global']['quotation_ui']['footer_disclaimer'] ?? 'Values are indicative and subject to site conditions, DISCOM approvals, and policy updates.'), ENT_QUOTES) ?></small></div></section>
<?php if ($showAdmin): ?><div class="card hide-print"><div class="h">Admin Actions</div><a class="chip" href="admin-quotations.php?tab=settings">Quotation Settings</a><div><?= htmlspecialchars($shareUrl, ENT_QUOTES) ?></div></div><?php endif; ?>
</main>
<script>
const q={gross:<?= json_encode((float)$calc['gross_payable']) ?>,subsidy:<?= json_encode((float)$calc['subsidy_expected_rs']) ?>,subsidyProvided:<?= json_encode($subsidyProvided) ?>,monthly:<?= json_encode((float)($quote['finance_inputs']['monthly_bill_rs'] ?? 0)) ?>,unit:<?= json_encode($unitRate) ?>,cap:<?= json_encode((float)($quote['capacity_kwp'] ?? 0)) ?>,gen:<?= json_encode($annualGeneration) ?>};
const showDecimals=<?= json_encode($showDecimals) ?>;
const r=x=>'₹'+Number(x).toLocaleString('en-IN',{minimumFractionDigits:showDecimals?2:0,maximumFractionDigits:showDecimals?2:0});
const rr=(<?= json_encode($loanInterest) ?>)/100/12,n=<?= json_encode($loanTenureMonths) ?>;
const minMargin=q.gross*0.10,loan=Math.max(0,q.gross-minMargin),margin=q.gross-loan,loanEff=Math.max(0,loan-q.subsidy),emi=loanEff>0?(loanEff*rr*Math.pow(1+rr,n))/((Math.pow(1+rr,n))-1):0,mUnits=q.monthly/Math.max(0.1,q.unit),solar=(q.cap*q.gen)/12,res=Math.max(0,mUnits-solar)*q.unit,out=emi+res;
['margin','loan','loanEff','emi','residual','outflow','upfront','upfrontNet','selfResidual'].forEach((id)=>{const map={margin,loan,loanEff,emi,residual:res,outflow:out,upfront:q.gross,upfrontNet:q.gross-q.subsidy,selfResidual:res};const el=document.getElementById(id);if(el)el.textContent=r(map[id]);});
const heroSaving=Math.max(0,q.monthly-res);document.getElementById('heroOutflowBank').textContent=r(out);document.getElementById('heroOutflowSelf').textContent=r(res);document.getElementById('heroSaving').textContent=r(heroSaving);
const paybackEl=document.getElementById('payback');
const invested=q.subsidyProvided?Math.max(0,q.gross-q.subsidy):q.gross;
const annualValue=q.cap*q.gen*q.unit;
if(!Number.isFinite(annualValue)||annualValue<=0){paybackEl.textContent='—';}else{const paybackYears=invested/annualValue;paybackEl.textContent='~'+(Number.isFinite(paybackYears)?paybackYears.toFixed(1):'—')+' years';}
const yearly=q.cap*q.gen,co2=yearly*<?= json_encode($emissionFactor) ?>,tree=co2/Math.max(0.1,<?= json_encode($treeAbsorption) ?>);
document.getElementById('co2y').textContent=co2.toFixed(0)+' kg';document.getElementById('treey').textContent=tree.toFixed(1);document.getElementById('co225').textContent=(co2*25).toFixed(0)+' kg';document.getElementById('tree25').textContent=(tree*25).toFixed(1);
document.getElementById('genMonthly').textContent=Math.round(solar)+' units';document.getElementById('genAnnual').textContent=Math.round(yearly)+' units';
</script>
</body></html>
<?php
}
