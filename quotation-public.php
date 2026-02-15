<?php
declare(strict_types=1);
require_once __DIR__ . '/admin/includes/documents_helpers.php';
documents_ensure_structure();
$token = safe_text($_GET['token'] ?? '');
$quote = null;
foreach (documents_list_quotes() as $q) {
    if ((string) ($q['share']['public_token'] ?? '') === $token && !empty($q['share']['public_enabled'])) { $quote = $q; break; }
}
if ($token === '' || $quote === null) { http_response_code(404); echo '<h1>Link invalid or expired.</h1>'; exit; }
$quoteDefaults = documents_get_quote_defaults_settings();
$segment = (string) ($quote['segment'] ?? 'RES');
$segmentDefaults = is_array($quoteDefaults['segments'][$segment] ?? null) ? $quoteDefaults['segments'][$segment] : [];
$company = documents_get_company_profile();
$badges = is_array($quoteDefaults['global']['branding']['header_badges'] ?? null) ? $quoteDefaults['global']['branding']['header_badges'] : [];
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quotation <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></title>
<style>body{margin:0;background:#ecfeff;font-family:Arial,sans-serif}.wrap{max-width:1120px;margin:auto;padding:14px}.card{background:#fff;border:1px solid #bae6fd;border-radius:16px;padding:14px;margin-bottom:12px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.metric{background:#f0fdfa;border:1px solid #99f6e4;border-radius:12px;padding:10px}.h{font-weight:800}.head{display:grid;grid-template-columns:1fr 1fr;gap:10px}.chips{display:flex;gap:8px;flex-wrap:wrap;border-top:1px solid #bae6fd;padding-top:8px}.chip{background:#ccfbf1;border:1px solid #5eead4;padding:4px 10px;border-radius:999px;font-size:12px}.small{font-size:12px;color:#475569}.bar{display:flex;gap:12px;align-items:flex-end;height:170px}.bar>div{width:72px;border-radius:8px 8px 0 0}.right{text-align:right}.foot{font-size:12px;color:#334155}</style></head><body><main class="wrap">
<div class="card"><div class="head"><div><div style="display:flex;gap:10px;align-items:center"><?php if (($company['logo_path'] ?? '') !== ''): ?><img src="<?= htmlspecialchars((string)$company['logo_path'], ENT_QUOTES) ?>" style="height:52px"><?php endif; ?><div><div class="h" style="font-size:22px"><?= htmlspecialchars((string)($company['brand_name'] ?: $company['company_name']), ENT_QUOTES) ?></div><div class="small">Solar Proposal <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></div></div></div></div><div class="right small"><?php foreach (array_filter([(string)($company['address_line'] ?? ''),(string)($company['phone_primary'] ?? ''),(string)($company['email_primary'] ?? ''),(string)($company['website'] ?? '')]) as $line): ?><div><?= htmlspecialchars($line, ENT_QUOTES) ?></div><?php endforeach; ?></div></div><div class="chips"><?php foreach ($badges as $b): ?><span class="chip"><?= htmlspecialchars((string)$b, ENT_QUOTES) ?></span><?php endforeach; ?></div></div>
<div class="card"><div class="h">Solar Benefit Snapshot</div><div class="grid"><div class="metric">Current bill<br><b id="billCard"></b></div><div class="metric">Bill after solar<br><b id="afterCard"></b></div><div class="metric">Monthly saving<br><b id="saveCard"></b></div><div class="metric">Total cost<br><b>₹<?= number_format((float)$quote['calc']['grand_total'],2) ?></b></div><div class="metric">Subsidy<br><b id="subsidyCard"></b></div><div class="metric">Net cost<br><b id="netCard"></b></div></div></div>
<div class="card"><div class="h">Monthly Comparison</div><div id="monthlyChart"></div></div>
<div class="card"><div class="h">10 Year Cumulative Spend</div><div id="lineChart"></div><div class="small">Subsidy considered in calculation.</div></div>
<div class="card foot">Thank you for choosing <?= htmlspecialchars((string)($company['brand_name'] ?: 'our team'), ENT_QUOTES) ?><?php if (($company['bank_name'] ?? '') !== ''): ?> · Bank: <?= htmlspecialchars((string)$company['bank_name'], ENT_QUOTES) ?> <?= htmlspecialchars((string)($company['bank_account_no'] ?? ''), ENT_QUOTES) ?> · IFSC <?= htmlspecialchars((string)($company['bank_ifsc'] ?? ''), ENT_QUOTES) ?><?php endif; ?></div>
</main>
<script>
const q=<?= json_encode([
  'grand' => (float)$quote['calc']['grand_total'],
  'segment' => $segment,
  'monthly' => (float)($quote['finance_inputs']['monthly_bill_rs'] ?: 0),
  'after' => (float)($quote['finance_inputs']['estimated_bill_after_solar_rs'] ?? ($segmentDefaults['estimated_bill_after_solar_rs'] ?? 200)),
  'subsidy' => (float)($quote['finance_inputs']['subsidy_expected_rs'] ?: 0),
  'interest' => (float)($quote['finance_inputs']['bank_financed']['interest_pct'] ?? 0),
  'tenure' => (float)($quote['finance_inputs']['bank_financed']['tenure_years'] ?? ($segmentDefaults['loan_defaults']['tenure_years'] ?? 10)),
  'loan' => (float)($quote['finance_inputs']['bank_financed']['loan_amount_rs'] ?? $quote['calc']['grand_total']),
]) ?>;
const n=v=>'₹'+Math.max(0,Number(v||0)).toLocaleString('en-IN',{maximumFractionDigits:0});
const subsidy=(q.subsidy>0)?q.subsidy:(q.segment==='RES'?78000:0);const principal=Math.max(0,q.loan-subsidy);const N=Math.max(1,Math.round(q.tenure*12));const m=q.interest/1200;const emi=(m<=0)?principal/N:(principal*m*Math.pow(1+m,N)/(Math.pow(1+m,N)-1));const bankMonthly=emi+q.after;const saving=Math.max(0,q.monthly-q.after);
document.getElementById('billCard').textContent=n(q.monthly);document.getElementById('afterCard').textContent=n(q.after);document.getElementById('saveCard').textContent=n(saving);document.getElementById('subsidyCard').textContent=n(subsidy);document.getElementById('netCard').textContent=n(q.grand-subsidy);
const max=Math.max(q.monthly,bankMonthly,q.after,1);const h=v=>Math.round((v/max)*150);document.getElementById('monthlyChart').innerHTML=`<div class='bar'><div style='height:${h(q.monthly)}px;background:#fb7185'></div><div style='height:${h(bankMonthly)}px;background:#60a5fa'></div><div style='height:${h(q.after)}px;background:#34d399'></div></div><div class='small'>No Solar / With Solar (Bank EMI) / With Solar (Self)</div>`;
let rows='';for(let y=0;y<=10;y++){const no=q.monthly*12*y;const self=(y===0?q.grand:q.grand+(q.after*12*y)-subsidy);const months=y*12;const emiMonths=Math.min(months,Math.max(0,q.tenure*12));const bank=(q.after*12*y)+(emi*emiMonths);rows+=`<tr><td>${y}</td><td>${Math.round(no)}</td><td>${Math.round(bank)}</td><td>${Math.round(self)}</td></tr>`;}document.getElementById('lineChart').innerHTML=`<table style='width:100%;font-size:12px'><tr><th>Year</th><th>No Solar</th><th>Bank Financed</th><th>Self Financed</th></tr>${rows}</table>`;
</script></body></html>
