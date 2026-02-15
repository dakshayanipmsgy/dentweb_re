<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

documents_ensure_structure();
$employeeStore = new EmployeeFsStore();
$user = current_user();
$viewerType = '';
$viewerId = '';
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
if ($viewerType === '') { header('Location: login.php'); exit; }

$id = safe_text($_GET['id'] ?? '');
$quote = documents_get_quote($id);
if ($quote === null) { http_response_code(404); echo 'Quotation not found.'; exit; }
if ($viewerType === 'employee' && ((string) ($quote['created_by_type'] ?? '') !== 'employee' || (string) ($quote['created_by_id'] ?? '') !== $viewerId)) {
    http_response_code(403); echo 'Access denied.'; exit;
}
$redirect = static function (string $type, string $message) use ($id): void {
    header('Location: quotation-view.php?' . http_build_query(['id' => $id, 'status' => $type, 'message' => $message]));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) { $redirect('error', 'Security validation failed.'); }
    $action = safe_text($_POST['action'] ?? '');

    if ($action === 'approve_quote' && $viewerType === 'admin') {
        $quote['status'] = 'Approved';
        $quote['approval']['approved_by_id'] = (string) ($user['id'] ?? '');
        $quote['approval']['approved_by_name'] = (string) ($user['full_name'] ?? 'Admin');
        $quote['approval']['approved_at'] = date('c');
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        $redirect('success', 'Quotation approved.');
    }

    if ($action === 'accept_quote' && $viewerType === 'admin') {
        if ((string) ($quote['status'] ?? '') !== 'Approved' && (string) ($quote['status'] ?? '') !== 'Accepted') {
            $redirect('error', 'Quotation must be Approved first.');
        }
        $customer = documents_upsert_customer_from_quote($quote);
        if (!($customer['ok'] ?? false)) { $redirect('error', (string) ($customer['error'] ?? 'Customer creation failed.')); }
        $agreement = documents_create_agreement_from_quote($quote, is_array($user) ? $user : []);
        $proforma = documents_create_proforma_from_quote($quote);
        $invoice = documents_create_invoice_from_quote($quote);
        $quote['links']['agreement_id'] = (string) ($agreement['agreement_id'] ?? ($quote['links']['agreement_id'] ?? ''));
        $quote['links']['proforma_id'] = (string) ($proforma['proforma_id'] ?? ($quote['links']['proforma_id'] ?? ''));
        $quote['links']['invoice_id'] = (string) ($invoice['invoice_id'] ?? ($quote['links']['invoice_id'] ?? ''));
        $quote['status'] = 'Accepted';
        $quote['acceptance']['accepted_by_admin_id'] = (string) ($user['id'] ?? '');
        $quote['acceptance']['accepted_by_admin_name'] = (string) ($user['full_name'] ?? 'Admin');
        $quote['acceptance']['accepted_at'] = date('c');
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        $redirect('success', 'Quotation accepted and linked documents generated.');
    }

    if ($action === 'archive_quote' && $viewerType === 'admin') {
        $quote['status'] = 'Archived';
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        $redirect('success', 'Quotation archived.');
    }

    if ($action === 'share_update') {
        $status = (string) ($quote['status'] ?? '');
        if (!in_array($status, ['Approved', 'Accepted'], true)) {
            $redirect('error', 'Public sharing is allowed only for Approved/Accepted quotations.');
        }
        $enable = isset($_POST['public_enabled']);
        if (isset($_POST['generate_token']) || ((string) ($quote['share']['public_token'] ?? '')) === '') {
            $quote['share']['public_token'] = bin2hex(random_bytes(16));
            $quote['share']['public_created_at'] = date('c');
        }
        $quote['share']['public_enabled'] = $enable;
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        $redirect('success', 'Share settings updated.');
    }
}

$quoteDefaults = documents_get_quote_defaults_settings();
$segment = (string) ($quote['segment'] ?? 'RES');
$segmentDefaults = is_array($quoteDefaults['segments'][$segment] ?? null) ? $quoteDefaults['segments'][$segment] : [];
$snapshot = documents_quote_resolve_snapshot($quote);
$links = is_array($quote['links'] ?? null) ? $quote['links'] : [];
$typo = $quoteDefaults['global']['typography'] ?? [];
$styleTypo = is_array($quote['style_overrides']['typography'] ?? null) ? $quote['style_overrides']['typography'] : [];
$baseFont = (int) (($styleTypo['base_font_px'] !== '' ? $styleTypo['base_font_px'] : ($typo['base_font_px'] ?? 14)));
$headingScale = (float) (($styleTypo['heading_scale'] !== '' ? $styleTypo['heading_scale'] : ($typo['heading_scale'] ?? 1)));
$density = (string) (($styleTypo['density'] !== '' ? $styleTypo['density'] : ($typo['density'] ?? 'comfortable')));
$wmGlobal = $quoteDefaults['global']['branding']['watermark'] ?? ['enabled'=>true,'image_path'=>'','opacity'=>0.08];
$wmOverride = is_array($quote['style_overrides']['watermark'] ?? null) ? $quote['style_overrides']['watermark'] : [];
$wmEnabled = (($wmOverride['enabled'] ?? '') === '') ? !empty($wmGlobal['enabled']) : (($wmOverride['enabled'] ?? '') === '1');
$wmImage = (string) (($wmOverride['image_path'] ?? '') !== '' ? $wmOverride['image_path'] : ($wmGlobal['image_path'] ?? ''));
$wmOpacity = (float) (($wmOverride['opacity'] ?? '') !== '' ? $wmOverride['opacity'] : ($wmGlobal['opacity'] ?? 0.08));
$shareUrl = ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['PHP_SELF'] ?? '/'), '/') . '/quotation-public.php?token=' . urlencode((string)($quote['share']['public_token'] ?? '')));
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quotation <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></title>
<style>
:root{--base:<?= max(12, min(18, $baseFont)) ?>px;--heading:<?= max(0.8,min(1.5,$headingScale)) ?>;--p:<?= $density==='compact'?'10px':($density==='spacious'?'20px':'14px') ?>;--pri:#0f766e;--sec:#22c55e;--acc:#f59e0b}
body{margin:0;font-family:Arial,sans-serif;font-size:var(--base);background:#f4f8ff;color:#0f172a}.wrap{max-width:1100px;margin:auto;padding:16px}.card{background:#fff;border:1px solid #dbeafe;border-radius:16px;padding:var(--p);margin-bottom:14px;box-shadow:0 4px 18px rgba(15,23,42,.05)}
.h{font-size:calc(1.1rem * var(--heading));font-weight:800}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.pill{display:inline-block;padding:4px 10px;border-radius:999px;background:#ecfeff;color:#155e75;font-weight:700;font-size:.82em}.btn{display:inline-block;background:#0f766e;color:#fff;text-decoration:none;border:none;border-radius:10px;padding:8px 12px;cursor:pointer}.btn.s{background:#fff;color:#0f172a;border:1px solid #cbd5e1}
.metric{background:linear-gradient(135deg,#ecfeff,#f0fdf4);border:1px solid #bbf7d0;border-radius:12px;padding:10px}.metric b{font-size:1.1em}.timeline{display:flex;gap:8px;overflow:auto}.timeline div{min-width:120px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:8px;text-align:center}
.chart{height:220px;border:1px dashed #cbd5e1;border-radius:12px;padding:10px}
.watermark{display:none}
@media print {.btn,.noprint{display:none!important}.wrap{max-width:none}.card{break-inside:avoid}.watermark{display:block;position:fixed;inset:0;pointer-events:none;z-index:0;background-repeat:no-repeat;background-position:center;background-size:65%;opacity:<?= htmlspecialchars((string)$wmOpacity, ENT_QUOTES) ?>}.content{position:relative;z-index:2}}
</style></head><body>
<?php if ($wmEnabled && $wmImage !== ''): ?><div class="watermark" style="background-image:url('<?= htmlspecialchars($wmImage, ENT_QUOTES) ?>')"></div><?php endif; ?>
<main class="wrap content">
<?php if (safe_text($_GET['message'] ?? '') !== ''): ?><div class="card"><?= htmlspecialchars((string)($_GET['message'] ?? ''), ENT_QUOTES) ?></div><?php endif; ?>
<div class="card"><div class="h">🌞 Solar Sales Proposal · <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></div><p><?= htmlspecialchars((string)($snapshot['name'] ?: $quote['customer_name']), ENT_QUOTES) ?> · <?= htmlspecialchars((string)($snapshot['mobile'] ?: $quote['customer_mobile']), ENT_QUOTES) ?> · <?= htmlspecialchars((string)$quote['city'], ENT_QUOTES) ?></p><span class="pill">Status: <?= htmlspecialchars(documents_status_label($quote, $viewerType), ENT_QUOTES) ?></span> <span class="pill">Next steps: Accept → Agreement → PI → Installation</span></div>
<div class="card grid" id="metrics"></div>
<div class="card"><div class="h">⚡ Pricing Summary</div><div class="grid"><div class="metric"><div>Grand Total</div><b>₹<?= number_format((float)$quote['calc']['grand_total'],2) ?></b></div><div class="metric"><div>Transportation</div><b>₹<?= number_format((float)($quote['finance_inputs']['transportation_rs'] ?? 0),2) ?></b></div><div class="metric"><div>Expected Subsidy</div><b id="subsidyCard">₹0</b></div><div class="metric"><div>Net Cost</div><b id="netCostCard">₹0</b></div></div><details><summary>GST breakup</summary><p>5% bucket total: ₹<?= number_format((float)$quote['calc']['bucket_5_basic'] + (float)$quote['calc']['bucket_5_gst'],2) ?> · 18% bucket total: ₹<?= number_format((float)$quote['calc']['bucket_18_basic'] + (float)$quote['calc']['bucket_18_gst'],2) ?></p></details></div>
<div class="card"><div class="h">📊 Savings & EMI</div><div class="grid"><div class="metric"><div>Monthly bill</div><b id="billCard"></b></div><div class="metric"><div>Estimated EMI</div><b id="emiCard"></b></div><div class="metric"><div>Monthly saving</div><b id="saveCard"></b></div><div class="metric"><div>Payback</div><b id="paybackCard"></b></div></div><div class="grid"><div class="chart" id="chart1"></div><div class="chart" id="chart2"></div><div class="chart" id="chart3"></div></div></div>
<div class="card"><div class="h">🌳 CO₂ & Trees Impact</div><div class="grid"><div class="metric"><div>CO₂ saved / year</div><b id="co2Y"></b></div><div class="metric"><div>CO₂ saved / 25 years</div><b id="co225"></b></div><div class="metric"><div>Trees equivalent / year</div><b id="treesY"></b></div><div class="metric"><div>Trees equivalent / 25 years</div><b id="trees25"></b></div></div></div>
<div class="card"><div class="h">🧰 Inclusions & Terms</div><ul><li><?= nl2br(htmlspecialchars((string)($quote['annexures_overrides']['system_inclusions'] ?? ''), ENT_QUOTES)) ?></li><li><?= nl2br(htmlspecialchars((string)($quote['annexures_overrides']['warranty'] ?? ''), ENT_QUOTES)) ?></li><li><?= nl2br(htmlspecialchars((string)($quote['annexures_overrides']['pm_subsidy_info'] ?? ''), ENT_QUOTES)) ?></li></ul></div>
<div class="card"><div class="h">🗓️ Timeline</div><div class="timeline"><div>Application</div><div>Survey</div><div>Material</div><div>Installation</div><div>Net meter</div><div>Commissioning</div><div>Subsidy</div></div></div>
<div class="card noprint"><a class="btn s" href="<?= htmlspecialchars($viewerType === 'admin' ? 'admin-quotations.php' : 'employee-quotations.php', ENT_QUOTES) ?>">Back</a> <?php if (documents_quote_can_edit($quote,$viewerType,$viewerId)): ?><a class="btn s" href="<?= htmlspecialchars(($viewerType==='admin'?'admin-quotations.php':'employee-quotations.php') . '?edit=' . urlencode((string)$quote['id']), ENT_QUOTES) ?>">Edit</a><?php endif; ?> <?php if ($viewerType==='admin' && (string)($quote['status']??'')==='Draft'): ?><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token']??''),ENT_QUOTES) ?>"><input type="hidden" name="action" value="approve_quote"><button class="btn" type="submit">Approve</button></form><?php endif; ?> <?php if ($viewerType==='admin' && in_array((string)($quote['status']??''),['Approved','Accepted'],true)): ?><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token']??''),ENT_QUOTES) ?>"><input type="hidden" name="action" value="accept_quote"><button class="btn" type="submit">Accepted by Customer</button></form><?php endif; ?></div>
<div class="card noprint"><div class="h">🔗 Share Proposal</div><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token']??''),ENT_QUOTES) ?>"><input type="hidden" name="action" value="share_update"><label><input type="checkbox" name="public_enabled" <?= !empty($quote['share']['public_enabled'])?'checked':'' ?>> Enable public sharing</label> <button class="btn s" name="generate_token" value="1" type="submit">Generate New Token</button> <button class="btn" type="submit">Save Share Settings</button></form><?php if (!empty($quote['share']['public_token'])): ?><p>Public URL: <a href="<?= htmlspecialchars($shareUrl, ENT_QUOTES) ?>" target="_blank"><?= htmlspecialchars($shareUrl, ENT_QUOTES) ?></a></p><?php endif; ?></div>
</main>
<script>
const quote={grand:<?= json_encode((float)$quote['calc']['grand_total']) ?>,cap:<?= json_encode((float)$quote['capacity_kwp']) ?>,monthlyBill:<?= json_encode((float)($quote['finance_inputs']['monthly_bill_rs'] ?: 0)) ?>,unitRate:<?= json_encode((float)(($quote['finance_inputs']['unit_rate_rs_per_kwh'] ?: ($segmentDefaults['unit_rate_rs_per_kwh'] ?? 8))) ) ?>,annGen:<?= json_encode((float)(($quote['finance_inputs']['annual_generation_per_kw'] ?: ($quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450))) ) ?>,emiRate:<?= json_encode((float)(($quote['finance_inputs']['loan']['interest_pct'] ?: 8.15))) ?>,tenure:<?= json_encode((float)(($quote['finance_inputs']['loan']['tenure_years'] ?: (($segmentDefaults['loan_defaults']['tenure_years'] ?? 10)))) ) ?>,loanEnabled:<?= json_encode(!empty($quote['finance_inputs']['loan']['enabled'])) ?>,loanAmount:<?= json_encode((float)($quote['finance_inputs']['loan']['loan_amount'] ?: $quote['calc']['grand_total'])) ?>,subsidyOverride:<?= json_encode((float)($quote['finance_inputs']['subsidy_expected_rs'] ?: 0)) ?>,emission:<?= json_encode((float)($quoteDefaults['global']['energy_defaults']['emission_factor_kg_per_kwh'] ?? 0.82)) ?>,tree:<?= json_encode((float)($quoteDefaults['global']['energy_defaults']['tree_absorption_kg_per_tree_per_year'] ?? 20)) ?>,segment:<?= json_encode($segment) ?>};
function n(v){return '₹'+Number(v).toLocaleString('en-IN',{maximumFractionDigits:0})}
function subsidy(){ if(quote.subsidyOverride>0) return quote.subsidyOverride; if(quote.segment!=='RES') return 0; if(quote.cap>=3) return 78000; if(quote.cap>=2) return 60000; return 0; }
function emi(p,r,y){const m=r/1200,N=y*12; if(m<=0||N<=0) return p/N; return p*m*Math.pow(1+m,N)/(Math.pow(1+m,N)-1);}
const yearly=quote.cap*quote.annGen, monthlySolar=yearly/12, monthlySaving=monthlySolar*quote.unitRate, s=subsidy(), net=quote.grand-s, emiV=quote.loanEnabled?emi(quote.loanAmount,quote.emiRate,quote.tenure):0, benefit=quote.loanEnabled?(monthlySaving-emiV):monthlySaving, payback=monthlySaving>0?(net/(monthlySaving*12)):0;
document.getElementById('subsidyCard').textContent=n(s); document.getElementById('netCostCard').textContent=n(net); document.getElementById('billCard').textContent=n(quote.monthlyBill||monthlySaving); document.getElementById('emiCard').textContent=n(emiV); document.getElementById('saveCard').textContent=n(benefit); document.getElementById('paybackCard').textContent=(payback?payback.toFixed(1):'-')+' yrs';
const co2y=yearly*quote.emission, co225=co2y*25; document.getElementById('co2Y').textContent=co2y.toFixed(0)+' kg'; document.getElementById('co225').textContent=co225.toFixed(0)+' kg'; document.getElementById('treesY').textContent=(co2y/quote.tree).toFixed(0); document.getElementById('trees25').textContent=(co225/quote.tree).toFixed(0);
const metrics=[["System",quote.cap.toFixed(2)+" kWp"],["Generation",yearly.toFixed(0)+" kWh/yr"],["Monthly saving",n(monthlySaving)],["Subsidy",n(s)],["Payback",(payback?payback.toFixed(1):'-')+" yrs"]];document.getElementById('metrics').innerHTML=metrics.map(m=>`<div class='metric'><div>${m[0]}</div><b>${m[1]}</b></div>`).join('');
function barChart(id,a,b,c){const max=Math.max(a,b,c,1),w=[a,b,c].map(v=>Math.round((v/max)*150));document.getElementById(id).innerHTML=`<b>Monthly Spend Comparison</b><div style='display:flex;gap:8px;align-items:flex-end;height:170px'><div style='width:60px;height:${w[0]}px;background:#fb7185'></div><div style='width:60px;height:${w[1]}px;background:#34d399'></div><div style='width:60px;height:${w[2]}px;background:#60a5fa'></div></div><small>No solar / Solar+EMI / Solar+self</small>`}
function lineChart(id,a,b){let x='';for(let i=1;i<=10;i++){const na=a*i*12,sa=b*i*12;x+=`<tr><td>${i}</td><td>${Math.round(na)}</td><td>${Math.round(sa)}</td></tr>`;}document.getElementById(id).innerHTML=`<b>10-year Cumulative Spend</b><table style='width:100%;font-size:12px'><tr><th>Year</th><th>No Solar</th><th>With Solar</th></tr>${x}</table>`}
function donut(id,p){const cl=Math.max(0,Math.min(100,p));document.getElementById(id).innerHTML=`<b>Payback Meter</b><svg viewBox='0 0 36 36' width='170'><path stroke='#e2e8f0' stroke-width='3.8' fill='none' d='M18 2.1a15.9 15.9 0 1 1 0 31.8a15.9 15.9 0 1 1 0-31.8'/><path stroke='#22c55e' stroke-width='3.8' fill='none' stroke-dasharray='${cl},100' d='M18 2.1a15.9 15.9 0 1 1 0 31.8a15.9 15.9 0 1 1 0-31.8'/><text x='18' y='20.35' text-anchor='middle' font-size='6'>${cl.toFixed(0)}%</text></svg>`}
barChart('chart1',quote.monthlyBill||monthlySaving,emiV,Math.max(0,(quote.monthlyBill||monthlySaving)-monthlySaving)); lineChart('chart2',quote.monthlyBill||monthlySaving,Math.max(0,(quote.monthlyBill||monthlySaving)-benefit)); donut('chart3',payback>0?(100-Math.min(100,(payback/10)*100)):0);
</script>
</body></html>
