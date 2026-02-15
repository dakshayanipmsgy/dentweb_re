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
    if ($employee !== null) { $viewerType = 'employee'; $viewerId = (string) ($employee['id'] ?? ''); }
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

$canEditFinance = ($viewerType === 'admin') || ($viewerType === 'employee' && (string)($quote['status'] ?? '') === 'Draft');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) { $redirect('error', 'Security validation failed.'); }
    $action = safe_text($_POST['action'] ?? '');

    if ($action === 'finance_update' && $canEditFinance) {
        $quote['finance_inputs']['monthly_bill_rs'] = safe_text($_POST['monthly_bill_rs'] ?? '');
        $quote['finance_inputs']['unit_rate_rs_per_kwh'] = safe_text($_POST['unit_rate_rs_per_kwh'] ?? '');
        $quote['finance_inputs']['annual_generation_per_kw'] = safe_text($_POST['annual_generation_per_kw'] ?? '');
        $quote['finance_inputs']['estimated_bill_after_solar_rs'] = safe_text($_POST['estimated_bill_after_solar_rs'] ?? '200');
        $quote['finance_inputs']['subsidy_expected_rs'] = safe_text($_POST['subsidy_expected_rs'] ?? '');
        $quote['finance_inputs']['self_financed']['enabled'] = isset($_POST['self_enabled']);
        $quote['finance_inputs']['bank_financed']['enabled'] = isset($_POST['bank_enabled']);
        $quote['finance_inputs']['bank_financed']['interest_pct'] = safe_text($_POST['bank_interest_pct'] ?? '');
        $quote['finance_inputs']['bank_financed']['tenure_years'] = safe_text($_POST['bank_tenure_years'] ?? '');
        $quote['finance_inputs']['bank_financed']['loan_amount_rs'] = safe_text($_POST['bank_loan_amount_rs'] ?? '');
        $quote['finance_inputs']['bank_financed']['margin_pct'] = safe_text($_POST['bank_margin_pct'] ?? '');
        $quote['finance_inputs']['bank_financed']['margin_amount_rs'] = safe_text($_POST['bank_margin_amount_rs'] ?? '');
        $quote['finance_inputs']['bank_financed']['slab_hint'] = safe_text($_POST['bank_slab_hint'] ?? '');
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        $redirect('success', 'Savings & finance updated.');
    }

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
        if (!in_array((string)($quote['status'] ?? ''), ['Approved', 'Accepted'], true)) { $redirect('error', 'Quotation must be Approved first.'); }
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

    if ($action === 'share_update') {
        if (!in_array((string)($quote['status'] ?? ''), ['Approved', 'Accepted'], true)) { $redirect('error', 'Public sharing only for Approved/Accepted.'); }
        $quote['share']['public_enabled'] = isset($_POST['public_enabled']);
        if (isset($_POST['generate_token']) || ((string) ($quote['share']['public_token'] ?? '')) === '') {
            $quote['share']['public_token'] = bin2hex(random_bytes(16));
            $quote['share']['public_created_at'] = date('c');
        }
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        $redirect('success', 'Share settings updated.');
    }
}

$quoteDefaults = documents_get_quote_defaults_settings();
$segment = (string) ($quote['segment'] ?? 'RES');
$segmentDefaults = is_array($quoteDefaults['segments'][$segment] ?? null) ? $quoteDefaults['segments'][$segment] : [];
$snapshot = documents_quote_resolve_snapshot($quote);
$company = documents_get_company_profile();
$brand = $quoteDefaults['global']['branding'] ?? [];
$badges = is_array($brand['header_badges'] ?? null) ? $brand['header_badges'] : [];
$shareUrl = ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/quotation-public.php?token=' . urlencode((string)($quote['share']['public_token'] ?? '')));
$wmGlobal = $quoteDefaults['global']['branding']['watermark'] ?? ['enabled'=>true,'image_path'=>'','opacity'=>0.08];
$wmOverride = is_array($quote['style_overrides']['watermark'] ?? null) ? $quote['style_overrides']['watermark'] : [];
$wmEnabled = (($wmOverride['enabled'] ?? '') === '') ? !empty($wmGlobal['enabled']) : (($wmOverride['enabled'] ?? '') === '1');
$wmImage = (string) (($wmOverride['image_path'] ?? '') !== '' ? $wmOverride['image_path'] : ($wmGlobal['image_path'] ?? ''));
$wmOpacity = (float) (($wmOverride['opacity'] ?? '') !== '' ? $wmOverride['opacity'] : ($wmGlobal['opacity'] ?? 0.08));
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quotation <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></title>
<style>
:root{--p:#0f766e;--s:#22c55e;--a:#f59e0b}body{margin:0;background:#ecfeff;font-family:Arial,sans-serif;color:#0f172a}.wrap{max-width:1120px;margin:auto;padding:14px}.card{background:#fff;border:1px solid #bae6fd;border-radius:16px;padding:14px;margin-bottom:12px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.metric{background:#f0fdfa;border:1px solid #99f6e4;border-radius:12px;padding:10px}.h{font-weight:800}.head{display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:start}.chips{display:flex;flex-wrap:wrap;gap:8px;padding-top:8px;border-top:1px solid #bae6fd;margin-top:8px}.chip{background:#ccfbf1;border:1px solid #5eead4;padding:4px 10px;border-radius:999px;font-size:12px}.sep{height:1px;background:#bae6fd;margin:8px 0}.bar{display:flex;gap:12px;align-items:flex-end;height:170px}.bar>div{width:72px;border-radius:8px 8px 0 0}.small{font-size:12px;color:#475569}.foot{font-size:12px;color:#334155}.noprint .btn{padding:8px 12px;border:none;background:#0ea5e9;color:#fff;border-radius:8px;text-decoration:none;display:inline-block;cursor:pointer}.btn.s{background:#0f766e}.tab{display:inline-block;padding:6px 10px;border:1px solid #bae6fd;border-radius:999px;background:#f8fafc;margin-right:6px}.right{text-align:right}.watermark{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;z-index:-1;opacity:<?= htmlspecialchars((string)$wmOpacity,ENT_QUOTES) ?>}.watermark img{max-width:70vw;max-height:70vh;object-fit:contain}
@media print{.noprint{display:none!important}.wrap{max-width:none;padding:0}.card{break-inside:avoid}}
</style></head><body>
<?php if ($wmEnabled && $wmImage !== ''): ?><div class="watermark"><img src="<?= htmlspecialchars($wmImage, ENT_QUOTES) ?>" alt=""></div><?php endif; ?>
<main class="wrap">
<div class="card">
  <div class="head">
    <div><div style="display:flex;gap:10px;align-items:center"><?php if (($company['logo_path'] ?? '') !== ''): ?><img src="<?= htmlspecialchars((string)$company['logo_path'], ENT_QUOTES) ?>" style="height:52px"><?php endif; ?><div><div class="h" style="font-size:22px"><?= htmlspecialchars((string)($company['brand_name'] ?: $company['company_name']), ENT_QUOTES) ?></div><div class="small">Quotation <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></div></div></div></div>
    <div class="right small">
      <?php foreach (array_filter([(string)($company['address_line'] ?? ''),(string)($company['city'] ?? ''),(string)($company['district'] ?? ''),(string)($company['state'] ?? ''),(string)($company['pin'] ?? '')]) as $line): ?><div><?= htmlspecialchars($line, ENT_QUOTES) ?></div><?php endforeach; ?>
      <?php foreach (array_filter([(string)($company['phone_primary'] ?? ''),(string)($company['phone_secondary'] ?? '')]) as $line): ?><div>📞 <?= htmlspecialchars($line, ENT_QUOTES) ?></div><?php endforeach; ?>
      <?php foreach (array_filter([(string)($company['email_primary'] ?? ''),(string)($company['email_secondary'] ?? '')]) as $line): ?><div>✉️ <?= htmlspecialchars($line, ENT_QUOTES) ?></div><?php endforeach; ?>
      <?php if (($company['website'] ?? '') !== ''): ?><div>🌐 <?= htmlspecialchars((string)$company['website'], ENT_QUOTES) ?></div><?php endif; ?>
      <?php foreach (['gstin'=>'GSTIN','pan'=>'PAN','udyam'=>'UDYAM','jreda_license'=>'JREDA'] as $k=>$lbl): if (!empty($company[$k])): ?><div><?= $lbl ?>: <?= htmlspecialchars((string)$company[$k], ENT_QUOTES) ?></div><?php endif; endforeach; ?>
    </div>
  </div>
  <div class="chips"><?php foreach ($badges as $b): ?><span class="chip"><?= htmlspecialchars((string)$b, ENT_QUOTES) ?></span><?php endforeach; ?></div>
</div>

<div class="card"><div class="h">Solar Benefit Snapshot</div><div class="grid">
<div class="metric">Current monthly bill<br><b id="billCard">₹0</b></div><div class="metric">Estimated bill after solar<br><b id="afterCard">₹0</b></div><div class="metric">Estimated monthly saving<br><b id="saveCard">₹0</b></div><div class="metric">Total cost (with GST)<br><b>₹<?= number_format((float)$quote['calc']['grand_total'],2) ?></b></div><div class="metric">Subsidy expected<br><b id="subsidyCard">₹0</b></div><div class="metric">Net cost after subsidy<br><b id="netCard">₹0</b></div><div class="metric">Payback (self)<br><b id="paybackCard">-</b></div>
</div></div>

<div class="card"><div class="h">Savings & EMI Comparison</div><div class="grid"><div class="metric"><div class="h">Bank Financed</div><div>EMI: <b id="emiCard">₹0</b></div><div>Net monthly benefit: <b id="benefitCard">₹0</b></div><div class="small">Quick Connect: <?= htmlspecialchars((string)(($company['phone_secondary'] ?: $company['phone_primary'])), ENT_QUOTES) ?></div></div><div class="metric"><div class="h">Self Financed</div><div>Upfront paid: <b id="upfrontCard">₹0</b></div><div>Net after subsidy: <b id="selfNetCard">₹0</b></div></div></div></div>

<div class="card"><div class="h">Monthly Comparison</div><div id="monthlyChart"></div></div>
<div class="card"><div class="h">10 Year Cumulative Spend</div><div id="lineChart"></div><div class="small">Subsidy considered in calculation.</div></div>

<?php if ($canEditFinance): ?>
<div class="card noprint"><div class="h">Savings & Finance</div>
<form method="post" class="grid" id="financeForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>"><input type="hidden" name="action" value="finance_update">
<div style="grid-column:1/-1"><span class="tab">Self Financed</span></div>
<div><label>Monthly bill ₹</label><input type="number" step="0.01" name="monthly_bill_rs" id="f_monthly" value="<?= htmlspecialchars((string)($quote['finance_inputs']['monthly_bill_rs'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Estimated bill after solar ₹</label><input type="number" step="0.01" name="estimated_bill_after_solar_rs" id="f_after" value="<?= htmlspecialchars((string)($quote['finance_inputs']['estimated_bill_after_solar_rs'] ?? ($segmentDefaults['estimated_bill_after_solar_rs'] ?? 200)), ENT_QUOTES) ?>"></div>
<div><label>Unit rate ₹/kWh</label><input type="number" step="0.01" name="unit_rate_rs_per_kwh" value="<?= htmlspecialchars((string)($quote['finance_inputs']['unit_rate_rs_per_kwh'] ?: ($segmentDefaults['unit_rate_rs_per_kwh'] ?? 8)), ENT_QUOTES) ?>"></div>
<div><label>Annual generation per kW</label><input type="number" step="0.01" name="annual_generation_per_kw" value="<?= htmlspecialchars((string)($quote['finance_inputs']['annual_generation_per_kw'] ?: ($quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450)), ENT_QUOTES) ?>"></div>
<div><label>Subsidy expected ₹</label><input type="number" step="0.01" name="subsidy_expected_rs" id="f_subsidy" value="<?= htmlspecialchars((string)($quote['finance_inputs']['subsidy_expected_rs'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label><input type="checkbox" name="self_enabled" <?= !isset($quote['finance_inputs']['self_financed']['enabled']) || !empty($quote['finance_inputs']['self_financed']['enabled']) ? 'checked' : '' ?>> Enable self financed</label></div>
<div style="grid-column:1/-1"><span class="tab">Bank Financed</span><input type="hidden" name="bank_slab_hint" id="f_slab" value="<?= htmlspecialchars((string)($quote['finance_inputs']['bank_financed']['slab_hint'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label><input type="checkbox" name="bank_enabled" <?= !isset($quote['finance_inputs']['bank_financed']['enabled']) || !empty($quote['finance_inputs']['bank_financed']['enabled']) ? 'checked' : '' ?>> Enable bank financed</label></div>
<div><label>Interest %</label><input type="number" step="0.01" name="bank_interest_pct" id="f_interest" value="<?= htmlspecialchars((string)($quote['finance_inputs']['bank_financed']['interest_pct'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Tenure years</label><input type="number" step="1" name="bank_tenure_years" id="f_tenure" value="<?= htmlspecialchars((string)($quote['finance_inputs']['bank_financed']['tenure_years'] ?? ($segmentDefaults['loan_defaults']['tenure_years'] ?? 10)), ENT_QUOTES) ?>"></div>
<div><label>Margin %</label><input type="number" step="0.01" name="bank_margin_pct" id="f_margin_pct" value="<?= htmlspecialchars((string)($quote['finance_inputs']['bank_financed']['margin_pct'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Margin amount ₹</label><input type="number" step="0.01" name="bank_margin_amount_rs" id="f_margin_amt" value="<?= htmlspecialchars((string)($quote['finance_inputs']['bank_financed']['margin_amount_rs'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Loan amount ₹</label><input type="number" step="0.01" name="bank_loan_amount_rs" id="f_loan" value="<?= htmlspecialchars((string)($quote['finance_inputs']['bank_financed']['loan_amount_rs'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Principal for EMI (loan - subsidy)</label><input type="text" id="f_principal" readonly></div>
<div><label>EMI (read-only)</label><input type="text" id="f_emi" readonly></div>
<div><button class="btn s" type="submit">Save Savings & Finance</button></div>
</form></div>
<?php endif; ?>

<div class="card foot">
  <div><b>Thank you for choosing <?= htmlspecialchars((string)($company['brand_name'] ?: 'our team'), ENT_QUOTES) ?></b></div>
  <div><?= htmlspecialchars((string)($company['default_cta_line'] ?: 'All values are estimates and subject to site/utility approval.'), ENT_QUOTES) ?></div>
  <?php if (($company['bank_name'] ?? '') !== '' || ($company['bank_account_no'] ?? '') !== '' || ($company['bank_ifsc'] ?? '') !== ''): ?><div>Bank: <?= htmlspecialchars((string)($company['bank_name'] ?? ''), ENT_QUOTES) ?> · A/C: <?= htmlspecialchars((string)($company['bank_account_name'] ?? ''), ENT_QUOTES) ?> · <?= htmlspecialchars((string)($company['bank_account_no'] ?? ''), ENT_QUOTES) ?> · IFSC <?= htmlspecialchars((string)($company['bank_ifsc'] ?? ''), ENT_QUOTES) ?></div><?php endif; ?>
</div>

<div class="noprint" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap">
<?php if ($viewerType==='admin' && (string)($quote['status']??'')==='NeedsApproval'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token']??''),ENT_QUOTES) ?>"><input type="hidden" name="action" value="approve_quote"><button class="btn" type="submit">Approve</button></form><?php endif; ?>
<?php if ($viewerType==='admin' && in_array((string)($quote['status']??''),['Approved','Accepted'],true)): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token']??''),ENT_QUOTES) ?>"><input type="hidden" name="action" value="accept_quote"><button class="btn" type="submit">Accepted by Customer</button></form><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token']??''),ENT_QUOTES) ?>"><input type="hidden" name="action" value="share_update"><label><input type="checkbox" name="public_enabled" <?= !empty($quote['share']['public_enabled'])?'checked':'' ?>> Public</label> <button class="btn s" name="generate_token" value="1" type="submit">New Token</button> <button class="btn" type="submit">Save Share</button></form>
<?php if (!empty($quote['share']['public_token'])): ?><a class="btn" target="_blank" href="<?= htmlspecialchars($shareUrl, ENT_QUOTES) ?>">Open Public View</a><?php endif; ?>
</div>
</main>
<script>
const q={grand:<?= json_encode((float)$quote['calc']['grand_total']) ?>,segment:<?= json_encode($segment) ?>,monthly:<?= json_encode((float)($quote['finance_inputs']['monthly_bill_rs'] ?: 0)) ?>,after:<?= json_encode((float)(($quote['finance_inputs']['estimated_bill_after_solar_rs'] ?? ($segmentDefaults['estimated_bill_after_solar_rs'] ?? 200)))) ?>,subsidy:<?= json_encode((float)($quote['finance_inputs']['subsidy_expected_rs'] ?: 0)) ?>,interest:<?= json_encode((float)(($quote['finance_inputs']['bank_financed']['interest_pct'] ?? 0))) ?>,tenure:<?= json_encode((float)(($quote['finance_inputs']['bank_financed']['tenure_years'] ?? ($segmentDefaults['loan_defaults']['tenure_years'] ?? 10)))) ?>,loan:<?= json_encode((float)(($quote['finance_inputs']['bank_financed']['loan_amount_rs'] ?? 0))) ?>,marginPct:<?= json_encode((float)(($quote['finance_inputs']['bank_financed']['margin_pct'] ?? 0))) ?>,marginAmt:<?= json_encode((float)(($quote['finance_inputs']['bank_financed']['margin_amount_rs'] ?? 0))) ?>,slabs:<?= json_encode(($segmentDefaults['loan_defaults']['slabs'] ?? [])) ?>};
const n=v=>'₹'+Math.max(0,Number(v||0)).toLocaleString('en-IN',{maximumFractionDigits:0});
function subsidyDefault(){if(q.subsidy>0)return q.subsidy;if(q.segment!=='RES')return 0;return 78000;}
function emi(P,r,y){const N=Math.max(0,Math.round(y*12));if(N===0)return 0;const m=r/1200;if(m<=0)return P/N;const p=Math.pow(1+m,N);return (P*m*p)/(p-1);}
function recalc(inputMode='auto'){const total=q.grand;const subsidy=subsidyDefault();let monthly=Number(document.getElementById('f_monthly')?.value||q.monthly||0);let after=Number(document.getElementById('f_after')?.value||q.after||200);let interest=Number(document.getElementById('f_interest')?.value||q.interest||0);let tenure=Number(document.getElementById('f_tenure')?.value||q.tenure||10);let marginPct=Number(document.getElementById('f_margin_pct')?.value||q.marginPct||0);let marginAmt=Number(document.getElementById('f_margin_amt')?.value||q.marginAmt||0);let loan=Number(document.getElementById('f_loan')?.value||q.loan||0);if(loan<=0&&marginPct<=0&&marginAmt<=0){loan=total;}if(inputMode==='pct'){marginPct=Math.min(100,Math.max(0,marginPct));marginAmt=total*marginPct/100;loan=total-marginAmt;}if(inputMode==='amt'){marginAmt=Math.max(0,marginAmt);marginPct=total>0?(marginAmt/total*100):0;marginPct=Math.min(100,Math.max(0,marginPct));loan=total-marginAmt;}if(inputMode==='loan'){loan=Math.max(0,loan);marginAmt=total-loan;marginPct=total>0?(marginAmt/total*100):0;}
if(loan<=200000){if(!document.getElementById('f_interest')?.value)interest=6; if(!document.getElementById('f_margin_pct')?.value&&!document.getElementById('f_margin_amt')?.value){marginPct=10;marginAmt=total*0.1;loan=total-marginAmt;} document.getElementById('f_slab')&&(document.getElementById('f_slab').value='upto_2l');}
else if(loan<=600000){if(!document.getElementById('f_interest')?.value)interest=8.15; if(!document.getElementById('f_margin_pct')?.value&&!document.getElementById('f_margin_amt')?.value){marginPct=20;marginAmt=total*0.2;loan=total-marginAmt;} document.getElementById('f_slab')&&(document.getElementById('f_slab').value='2_to_6l');}
else {document.getElementById('f_slab')&&(document.getElementById('f_slab').value='custom');}
loan=Math.max(0,loan);const principal=Math.max(0,loan-subsidy);const emiVal=emi(principal,interest,tenure);const monthlySaving=Math.max(0,monthly-after);const payback=monthlySaving>0?((total-subsidy)/(monthlySaving*12)):0;const bankMonthly=emiVal+after;const bankBenefit=monthly-bankMonthly;
if(document.getElementById('f_margin_pct'))document.getElementById('f_margin_pct').value=marginPct.toFixed(2);if(document.getElementById('f_margin_amt'))document.getElementById('f_margin_amt').value=marginAmt.toFixed(2);if(document.getElementById('f_loan'))document.getElementById('f_loan').value=loan.toFixed(2);if(document.getElementById('f_principal'))document.getElementById('f_principal').value=n(principal);if(document.getElementById('f_emi'))document.getElementById('f_emi').value=n(emiVal);
['billCard','afterCard','saveCard','subsidyCard','netCard','paybackCard','emiCard','benefitCard','upfrontCard','selfNetCard'].forEach(()=>{});document.getElementById('billCard').textContent=n(monthly);document.getElementById('afterCard').textContent=n(after);document.getElementById('saveCard').textContent=n(monthlySaving);document.getElementById('subsidyCard').textContent=n(subsidy);document.getElementById('netCard').textContent=n(total-subsidy);document.getElementById('paybackCard').textContent=(payback>0?payback.toFixed(1):'-')+' yrs';document.getElementById('emiCard').textContent=n(emiVal);document.getElementById('benefitCard').textContent=n(bankBenefit);document.getElementById('upfrontCard').textContent=n(total);document.getElementById('selfNetCard').textContent=n(total-subsidy);
const max=Math.max(monthly,bankMonthly,after,1);const h=v=>Math.round((v/max)*150);document.getElementById('monthlyChart').innerHTML=`<div class='bar'><div style='height:${h(monthly)}px;background:#fb7185'></div><div style='height:${h(bankMonthly)}px;background:#60a5fa'></div><div style='height:${h(after)}px;background:#34d399'></div></div><div class='small'>No Solar / With Solar (Bank EMI) / With Solar (Self)</div>`;
let rows='';for(let y=0;y<=10;y++){const no=monthly*12*y;const self=(y===0?total:total+(after*12*y)-subsidy);const months=y*12;const emiMonths=Math.min(months,Math.max(0,tenure*12));const bank=(after*12*y)+(emiVal*emiMonths);rows+=`<tr><td>${y}</td><td>${Math.round(no)}</td><td>${Math.round(bank)}</td><td>${Math.round(self)}</td></tr>`;}document.getElementById('lineChart').innerHTML=`<table style='width:100%;font-size:12px'><tr><th>Year</th><th>No Solar</th><th>Bank Financed</th><th>Self Financed</th></tr>${rows}</table>`;
}
recalc('auto');
['f_margin_pct','f_margin_amt','f_loan'].forEach((id,i)=>{const el=document.getElementById(id);if(el)el.addEventListener('input',()=>recalc(i===0?'pct':(i===1?'amt':'loan')));});['f_monthly','f_after','f_interest','f_tenure','f_subsidy'].forEach(id=>{const el=document.getElementById(id);if(el)el.addEventListener('input',()=>recalc('auto'));});
</script>
</body></html>
