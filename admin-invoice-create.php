<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/standalone_invoice_quotation_builder.php';

require_admin();
documents_ensure_structure();

$id = safe_text((string)($_GET['id'] ?? $_POST['invoice_id'] ?? ''));
$invoice = $id !== '' ? documents_get_invoice($id) : null;
if (!is_array($invoice) || !documents_invoice_is_standalone($invoice)) {
    http_response_code(404);
    exit('Standalone invoice not found.');
}
if (!documents_invoice_is_draft($invoice)) {
    header('Location: admin-invoices.php?id=' . urlencode((string)$invoice['id']) . '&status=error&message=' . urlencode('Finalized invoices are read-only. Start a revision from the invoice workspace first.'));
    exit;
}

$flashStatus = safe_text((string)($_GET['status'] ?? ''));
$flashMessage = safe_text((string)($_GET['message'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        header('Location: admin-invoice-create.php?id=' . urlencode($id) . '&status=error&message=' . urlencode('Security token expired. Please try again.'));
        exit;
    }
    $result = standalone_invoice_quote_builder_apply($invoice, $_POST, new CustomerFsStore());
    if (empty($result['ok'])) {
        header('Location: admin-invoice-create.php?id=' . urlencode($id) . '&status=error&message=' . urlencode((string)($result['error'] ?? 'Unable to save invoice.')));
        exit;
    }
    $invoice = (array)$result['invoice'];
    $saved = documents_save_invoice($invoice);
    if (empty($saved['ok'])) {
        header('Location: admin-invoice-create.php?id=' . urlencode($id) . '&status=error&message=' . urlencode('Unable to save standalone invoice draft.'));
        exit;
    }
    standalone_invoice_quote_builder_sync_sales_record($invoice);
    if (safe_text((string)($_POST['action'] ?? '')) === 'save_finalize') {
        $user = current_user();
        $final = documents_invoice_finalize($invoice, ['id'=>(string)($user['id']??''),'name'=>(string)($user['full_name']??'Admin')]);
        if (empty($final['ok'])) {
            header('Location: admin-invoice-create.php?id=' . urlencode($id) . '&status=error&message=' . urlencode(implode(' ', (array)($final['errors'] ?? ['Unable to finalize invoice.']))));
            exit;
        }
        $invoice = (array)$final['invoice'];
        $saved = documents_save_invoice($invoice);
        if (empty($saved['ok'])) {
            header('Location: admin-invoice-create.php?id=' . urlencode($id) . '&status=error&message=' . urlencode('Unable to save finalized invoice.'));
            exit;
        }
        standalone_invoice_quote_builder_sync_sales_record($invoice);
        header('Location: admin-invoices.php?id=' . urlencode($id) . '&status=success&message=' . urlencode('Standalone invoice finalized / issued.'));
        exit;
    }
    header('Location: admin-invoice-create.php?id=' . urlencode($id) . '&status=success&message=' . urlencode('Quotation-style standalone invoice draft saved.'));
    exit;
}

$catalog = standalone_invoice_quote_builder_catalog();
$customers = standalone_invoice_quote_builder_customers(new CustomerFsStore());
$quoteDefaults = function_exists('load_quote_defaults') ? load_quote_defaults() : documents_quote_defaults_settings();
if (!is_array($quoteDefaults)) $quoteDefaults = documents_quote_defaults_settings();
$snapshot = array_merge(documents_customer_snapshot_defaults(), is_array($invoice['customer_snapshot'] ?? null) ? $invoice['customer_snapshot'] : []);
$source = is_array($invoice['standalone_quote_snapshot'] ?? null) ? $invoice['standalone_quote_snapshot'] : [];
$quoteItems = is_array($source['quote_items'] ?? null) ? $source['quote_items'] : [];
$finance = is_array($source['finance_inputs'] ?? null) ? $source['finance_inputs'] : [];
$scenarioPrices = is_array($source['scenario_prices'] ?? null) ? $source['scenario_prices'] : [];
$rateSnapshot = is_array($source['rate_chart_snapshot'] ?? null) ? $source['rate_chart_snapshot'] : [];
$manualRef = is_array($invoice['manual_reference'] ?? null) ? $invoice['manual_reference'] : [];
$mainSolar = (string)($source['main_solar_kwp'] ?? '');
$nonDcr = (string)($source['complimentary_non_dcr_kwp'] ?? '0');
$totalCapacity = (string)($source['capacity_kwp'] ?? $invoice['capacity_kwp'] ?? '');
$systemType = (string)($source['system_type'] ?? 'ongrid');
$primaryScenario = (string)($source['primary_finance_scenario'] ?? 'self_funded');

$rateRows = [];
foreach (['on_grid','hybrid'] as $kind) {
    foreach ((array)($quoteDefaults['rate_chart'][$kind] ?? []) as $row) {
        if (!is_array($row)) continue;
        $rateRows[] = [
            'system_type'=>$kind === 'hybrid' ? 'hybrid' : 'ongrid',
            'model_number'=>safe_text((string)($row['model_number'] ?? '')),
            'variant'=>safe_text((string)($row['variant'] ?? '')),
            'battery_code'=>safe_text((string)($row['battery_code'] ?? '')),
            'inverter_code'=>safe_text((string)($row['inverter_code'] ?? '')),
        ];
    }
}

$esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$jsonFlags = JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create / Edit Standalone Invoice</title>
<link rel="stylesheet" href="assets/css/admin-unified.css">
<?php require_once __DIR__ . '/includes/pwa_head.php'; ?>
<style>
.builder-shell{max-width:1500px;margin:0 auto;padding:18px}.builder-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.builder-grid .full{grid-column:1/-1}.section-card{background:#fff;border:1px solid #dbe4ef;border-radius:16px;padding:18px;margin:14px 0}.section-card h2{margin:0 0 5px}.hint{color:#64748b;font-size:13px}.customer-search{position:relative}.customer-results{position:absolute;z-index:30;left:0;right:0;top:100%;background:#fff;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 18px 40px rgba(15,23,42,.15);max-height:300px;overflow:auto;display:none}.customer-option{padding:10px 12px;border-bottom:1px solid #eef2f7;cursor:pointer}.customer-option:hover{background:#eff6ff}.customer-option strong{display:block}.items-table select,.items-table input,.items-table textarea{min-width:110px}.items-table textarea{min-height:58px}.inline-actions{display:flex;gap:8px;flex-wrap:wrap}.linked-ok{color:#166534}.linked-no{color:#9a3412}.sticky-builder-actions{position:sticky;bottom:0;z-index:20;background:rgba(255,255,255,.96);border:1px solid #dbe4ef;border-radius:14px;padding:12px;display:flex;gap:10px;justify-content:flex-end;box-shadow:0 -8px 26px rgba(15,23,42,.08)}.inactive-choice{opacity:.55}@media(max-width:850px){.builder-grid{grid-template-columns:1fr}.builder-grid .full{grid-column:auto}.items-table{display:block;overflow:auto}}
</style>
</head>
<body class="admin-shell commercial-admin">
<?php require_once __DIR__ . '/includes/mobile_app_nav.php'; ?>
<main class="builder-shell">
<header class="card commercial-header"><div><p class="admin-kicker">Commercial workspace</p><h1>Standalone Invoice Builder</h1><p>Uses the same customer, DCR/non-DCR, Items Master, tax-profile and pricing concepts as quotation creation, but saves only an invoice.</p></div><nav class="commercial-header__actions"><a class="btn secondary" href="admin-invoices.php">Invoices</a><a class="btn secondary" href="admin-invoices.php?id=<?=urlencode((string)$invoice['id'])?>">Invoice workspace</a><a class="btn" target="_blank" rel="noopener" href="invoice-view.php?id=<?=urlencode((string)$invoice['id'])?>">View / Print</a></nav></header>
<?php if($flashMessage!==''): ?><div class="flash <?=$flashStatus==='error'?'error':'success'?>"><?=$esc($flashMessage)?></div><?php endif; ?>
<form method="post" id="standalone-builder-form">
<input type="hidden" name="csrf_token" value="<?=$esc(csrf_token())?>"><input type="hidden" name="invoice_id" value="<?=$esc($invoice['id'])?>">

<section class="section-card"><h2>Invoice identity & external reference</h2><p class="hint">Invoice number is automatically allotted by the normal invoice numbering rule but remains editable while Draft.</p><div class="builder-grid">
<div><label>Invoice number</label><input name="invoice_no" required value="<?=$esc($invoice['invoice_no']??'')?>"></div>
<div><label>Invoice date</label><input type="date" name="invoice_date" required value="<?=$esc(documents_invoice_authoritative_date($invoice))?>"></div>
<div><label>Quotation / reference number (optional)</label><input name="manual_quotation_no" value="<?=$esc($manualRef['quotation_no']??'')?>"></div>
<div><label>Reference date</label><input type="date" name="manual_quotation_date" value="<?=$esc($manualRef['quotation_date']??'')?>"></div>
<div><label>External reference</label><input name="external_reference" value="<?=$esc($manualRef['external_reference']??'')?>"></div>
<div><label>Reference amount (informational only)</label><input type="number" min="0" step="0.01" name="manual_quotation_amount" value="<?=$esc($manualRef['quotation_amount']??'')?>"></div>
</div></section>

<section class="section-card"><h2>Customer & site details</h2><p class="hint">Type either customer name or mobile. Select a Customer User from the dropdown and every available field below is filled automatically.</p>
<div class="customer-search"><label>Find Customer User by name or mobile</label><input id="customer-search" autocomplete="off" placeholder="Start typing name or mobile"><div id="customer-results" class="customer-results"></div></div>
<p id="customer-link-state" class="hint <?=!empty($invoice['customer_ref'])?'linked-ok':'linked-no'?>"><?=!empty($invoice['customer_ref'])?'Linked to Customer User':'Not linked to Customer User — manual invoice is still allowed.'?></p>
<div class="builder-grid">
<div><label>Customer name</label><input id="customer_name" name="customer_name" required value="<?=$esc($snapshot['name']??'')?>"></div>
<div><label>Customer mobile</label><input id="customer_mobile" name="customer_mobile" required value="<?=$esc($invoice['customer_mobile']??$snapshot['mobile']??'')?>"></div>
<div class="full"><label>Billing address</label><textarea id="billing_address" name="billing_address" rows="2"><?=$esc($source['billing_address']??$snapshot['address']??'')?></textarea></div>
<div class="full"><label>Site address</label><textarea id="site_address" name="site_address" rows="2"><?=$esc($snapshot['address']??'')?></textarea></div>
<div><label>City</label><input id="city" name="city" value="<?=$esc($snapshot['city']??'')?>"></div><div><label>District</label><input id="district" name="district" value="<?=$esc($snapshot['district']??'')?>"></div>
<div><label>State</label><input id="state" name="state" value="<?=$esc($snapshot['state']??'Jharkhand')?>"></div><div><label>PIN</label><input id="pin" name="pin" value="<?=$esc($snapshot['pin_code']??'')?>"></div>
<div><label>Meter number</label><input id="meter_number" name="meter_number" value="<?=$esc($snapshot['meter_number']??'')?>"></div><div><label>Meter serial number</label><input id="meter_serial_number" name="meter_serial_number" value="<?=$esc($snapshot['meter_serial_number']??'')?>"></div>
<div><label>Consumer account no.</label><input id="consumer_account_no" name="consumer_account_no" value="<?=$esc($snapshot['consumer_account_no']??'')?>"></div><div><label>Application ID</label><input id="application_id" name="application_id" value="<?=$esc($snapshot['application_id']??'')?>"></div>
<div><label>Application submitted date</label><input id="application_submitted_date" type="date" name="application_submitted_date" value="<?=$esc($snapshot['application_submitted_date']??'')?>"></div><div><label>Sanction load (kWp)</label><input id="sanction_load_kwp" name="sanction_load_kwp" value="<?=$esc($snapshot['sanction_load_kwp']??'')?>"></div>
<div><label>Installed PV module capacity (kWp)</label><input id="installed_pv_module_capacity_kwp" name="installed_pv_module_capacity_kwp" value="<?=$esc($snapshot['installed_pv_module_capacity_kwp']??'')?>"></div><div><label>Circle</label><input id="circle_name" name="circle_name" value="<?=$esc($snapshot['circle_name']??'')?>"></div>
<div><label>Division</label><input id="division_name" name="division_name" value="<?=$esc($snapshot['division_name']??'')?>"></div><div><label>Sub Division</label><input id="sub_division_name" name="sub_division_name" value="<?=$esc($snapshot['sub_division_name']??'')?>"></div>
</div></section>

<section class="section-card"><h2>System configuration</h2><p class="hint">Same DCR + complimentary Non-DCR concept as quotation creation. Total capacity is always their sum.</p><div class="builder-grid">
<div><label>System type</label><select id="system_type" name="system_type"><option value="ongrid" <?=$systemType==='ongrid'?'selected':''?>>On-grid</option><option value="hybrid" <?=$systemType==='hybrid'?'selected':''?>>Hybrid</option></select></div>
<div><label>Pricing mode</label><select name="pricing_mode"><option value="solar_split_70_30" <?=($invoice['pricing_mode']??'')==='solar_split_70_30'?'selected':''?>>Solar split 70/30</option><option value="flat_5" <?=($invoice['pricing_mode']??'')==='flat_5'?'selected':''?>>Flat 5%</option></select></div>
<div><label>Main Solar Size / DCR (kWp)</label><input id="main_solar_kwp" type="number" min="0.001" step="0.001" name="main_solar_kwp" required value="<?=$esc($mainSolar)?>"></div>
<div><label>Complimentary Non-DCR (kWp)</label><input id="complimentary_non_dcr_kwp" type="number" min="0" step="0.001" name="complimentary_non_dcr_kwp" value="<?=$esc($nonDcr)?>"></div>
<div><label>Total System Capacity (kWp)</label><input id="capacity_kwp_display" readonly value="<?=$esc($totalCapacity)?>"></div>
<div><label>Place of supply state</label><input name="place_of_supply_state" value="<?=$esc($source['place_of_supply_state']??$snapshot['state']??'Jharkhand')?>"></div>
<div><label>Tax profile</label><select name="tax_profile_id"><option value="">Default / infer from selected kit</option><?php foreach((array)$catalog['tax_profiles'] as $profile): $pid=(string)($profile['id']??''); ?><option value="<?=$esc($pid)?>" <?=($source['tax_profile_id']??'')===$pid?'selected':''?>><?=$esc($profile['name']??$pid)?></option><?php endforeach; ?></select></div>
<div><label><input type="checkbox" name="show_tax_breakup" value="1" <?=!empty($source['show_tax_breakup'])?'checked':''?>> Show tax breakup</label></div>
<div class="full"><label>Project summary line</label><input name="project_summary_line" value="<?=$esc($source['project_summary_line']??'')?>"></div>
<div><label>Rate-chart / model number</label><select id="selected_model_number" name="selected_model_number"><option value="">None / manual</option></select></div>
<div><label>Hybrid inverter (kVA)</label><input type="number" min="0" step="0.01" name="hybrid_inverter_kva" value="<?=$esc($rateSnapshot['hybrid_inverter_kva']??'')?>"></div>
<div><label>Hybrid phase</label><select name="hybrid_phase"><option value="">Select</option><option value="Single Phase" <?=($rateSnapshot['hybrid_phase']??'')==='Single Phase'?'selected':''?>>Single Phase</option><option value="Three Phase" <?=($rateSnapshot['hybrid_phase']??'')==='Three Phase'?'selected':''?>>Three Phase</option></select></div>
<div><label>Hybrid battery count</label><input type="number" min="0" step="1" name="hybrid_battery_count" value="<?=$esc($rateSnapshot['hybrid_battery_count']??'0')?>"></div>
</div></section>

<section class="section-card"><h2>Items Master</h2><p class="hint">Exactly like quotations: choose active kits/components/variants from Items Master. Name, HSN, master description and default unit are sourced from the master record; only quantity/unit and description override are editable.</p>
<div class="responsive-table items-table"><table><thead><tr><th>Type</th><th>Kit</th><th>Component</th><th>Variant</th><th>Qty</th><th>Unit</th><th>Description mode</th><th>Description</th><th></th></tr></thead><tbody id="item-rows"></tbody></table></div>
<div class="inline-actions"><button type="button" class="btn secondary" id="add-item-row">Add item</button></div></section>

<section class="section-card"><h2>Pricing & finance</h2><p class="hint">Uses the quotation pricing/tax concepts. The selected primary scenario becomes the invoice commercial value; no quotation record is created.</p><div class="builder-grid">
<div><label>Primary finance scenario</label><select name="primary_finance_scenario"><option value="self_funded" <?=$primaryScenario==='self_funded'?'selected':''?>>Self funded</option><option value="loan_upto_2_lacs" <?=str_contains($primaryScenario,'upto_2')?'selected':''?>>Loan up to ₹2 lacs</option><option value="loan_above_2_lacs" <?=str_contains($primaryScenario,'above_2')?'selected':''?>>Loan above ₹2 lacs</option></select></div>
<div><label>Self funded price</label><input type="number" min="0" step="0.01" name="scenario_price_self_funded" value="<?=$esc($scenarioPrices['self_funded']['price']??$invoice['input_total_gst_inclusive']??'')?>"></div>
<div><label>Loan up to ₹2 lacs price</label><input type="number" min="0" step="0.01" name="scenario_price_loan_upto_2_lacs" value="<?=$esc($scenarioPrices['loan_upto_2_lacs']['price']??$scenarioPrices['self_funded']['price']??'')?>"></div>
<div><label>Loan above ₹2 lacs price</label><input type="number" min="0" step="0.01" name="scenario_price_loan_above_2_lacs" value="<?=$esc($scenarioPrices['loan_above_2_lacs']['price']??$scenarioPrices['self_funded']['price']??'')?>"></div>
<div><label>Transportation</label><input type="number" min="0" step="0.01" name="transportation_rs" value="<?=$esc($finance['transportation_rs']??'0')?>"></div><div><label>Expected subsidy</label><input type="number" min="0" step="0.01" name="subsidy_expected_rs" value="<?=$esc($finance['subsidy_expected_rs']??'0')?>"></div>
<div><label>Discount</label><input type="number" min="0" step="0.01" name="discount_rs" value="<?=$esc($finance['discount_rs']??'0')?>"></div><div><label>Discount note</label><input name="discount_note" value="<?=$esc($finance['discount_note']??'')?>"></div>
<div><label>Monthly bill</label><input type="number" min="0" step="0.01" name="monthly_bill_rs" value="<?=$esc($finance['monthly_bill_rs']??'')?>"></div><div><label>Unit rate ₹/kWh</label><input type="number" min="0" step="0.01" name="unit_rate_rs_per_kwh" value="<?=$esc($finance['unit_rate_rs_per_kwh']??'')?>"></div>
<div><label>Annual generation / kW</label><input type="number" min="0" step="0.01" name="annual_generation_per_kw" value="<?=$esc($finance['annual_generation_per_kw']??'')?>"></div>
<div class="full"><strong>Loan up to ₹2 lacs</strong></div><div><label>Margin %</label><input name="loan_upto_2_lacs_margin_ratio_pct" value="<?=$esc($finance['loan']['upto_2_lacs_margin_pct']??'10')?>"></div><div><label>Loan %</label><input name="loan_upto_2_lacs_loan_ratio_pct" value="<?=$esc($finance['loan']['upto_2_lacs_loan_pct']??'90')?>"></div><div><label>Interest %</label><input name="loan_upto_2_lacs_interest_pct" value="<?=$esc($finance['loan']['upto_2_lacs_interest_pct']??'5.75')?>"></div><div><label>Tenure years</label><input name="loan_upto_2_lacs_tenure_years" value="<?=$esc($finance['loan']['upto_2_lacs_tenure_years']??'10')?>"></div>
<div class="full"><strong>Loan above ₹2 lacs</strong></div><div><label>Margin %</label><input name="loan_above_2_lacs_margin_ratio_pct" value="<?=$esc($finance['loan']['above_2_lacs_margin_pct']??'20')?>"></div><div><label>Loan %</label><input name="loan_above_2_lacs_loan_ratio_pct" value="<?=$esc($finance['loan']['above_2_lacs_loan_pct']??'80')?>"></div><div><label>Interest %</label><input name="loan_above_2_lacs_interest_pct" value="<?=$esc($finance['loan']['above_2_lacs_interest_pct']??'8.15')?>"></div><div><label>Tenure years</label><input name="loan_above_2_lacs_tenure_years" value="<?=$esc($finance['loan']['above_2_lacs_tenure_years']??'10')?>"></div>
<div class="full"><label>Special requests / commercial notes</label><textarea name="special_requests_text" rows="3"><?=$esc($source['special_requests_text']??'')?></textarea></div>
<div class="full"><label>Internal notes</label><textarea name="internal_notes" rows="3"><?=$esc($invoice['internal_notes']??'')?></textarea></div>
</div></section>

<div class="sticky-builder-actions"><a class="btn secondary" href="admin-invoices.php?id=<?=urlencode((string)$invoice['id'])?>">Invoice workspace</a><button class="btn" type="submit" name="action" value="save_builder">Save Draft</button><button class="btn commercial-header__primary" type="submit" name="action" value="save_finalize">Save & Finalize / Issue</button></div>
</form>
</main>
<script>
const customers = <?=json_encode($customers,$jsonFlags)?:'[]'?>;
const components = <?=json_encode($catalog['components'],$jsonFlags)?:'[]'?>;
const kits = <?=json_encode($catalog['kits'],$jsonFlags)?:'[]'?>;
const variants = <?=json_encode($catalog['variants'],$jsonFlags)?:'[]'?>;
const initialItems = <?=json_encode($quoteItems,$jsonFlags)?:'[]'?>;
const rateRows = <?=json_encode($rateRows,$jsonFlags)?:'[]'?>;
const selectedModel = <?=json_encode((string)($rateSnapshot['model_number']??''),$jsonFlags)?>;
let itemRowCounter = 0;
const escHtml = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));

const search = document.getElementById('customer-search');
const results = document.getElementById('customer-results');
function fillCustomer(c){
  const map={customer_name:'name',customer_mobile:'mobile',billing_address:'address',site_address:'address',city:'city',district:'district',state:'state',pin:'pin_code',meter_number:'meter_number',meter_serial_number:'meter_serial_number',consumer_account_no:'consumer_account_no',application_id:'application_id',application_submitted_date:'application_submitted_date',sanction_load_kwp:'sanction_load_kwp',installed_pv_module_capacity_kwp:'installed_pv_module_capacity_kwp',circle_name:'circle_name',division_name:'division_name',sub_division_name:'sub_division_name'};
  Object.entries(map).forEach(([id,key])=>{const el=document.getElementById(id);if(el)el.value=c[key]??'';});
  search.value=((c.name||'')+' · '+(c.mobile||'')).trim();results.style.display='none';
  const state=document.getElementById('customer-link-state');state.textContent='Selected Customer User: '+(c.name||'')+' · '+(c.mobile||'');state.className='hint linked-ok';
}
function showCustomerMatches(q){
  q=String(q||'').trim().toLowerCase(); if(q.length<1){results.style.display='none';return;}
  const digits=q.replace(/\D/g,'');
  const matches=customers.filter(c=>(c.name||'').toLowerCase().includes(q)||(digits&&String(c.mobile||'').replace(/\D/g,'').includes(digits))).slice(0,12);
  results.innerHTML=matches.map((c,i)=>`<div class="customer-option" data-i="${i}"><strong>${escHtml(c.name||'Unnamed customer')}</strong><span>${escHtml(c.mobile||'')} · ${escHtml(c.city||c.district||'')}</span></div>`).join('') || '<div class="customer-option">No matching Customer User</div>';
  results.style.display='block';
  [...results.querySelectorAll('[data-i]')].forEach((el)=>el.onclick=()=>fillCustomer(matches[Number(el.dataset.i)]));
}
search.addEventListener('input',e=>showCustomerMatches(e.target.value)); document.addEventListener('click',e=>{if(!results.contains(e.target)&&e.target!==search)results.style.display='none';});

function totalCapacity(){const a=parseFloat(document.getElementById('main_solar_kwp').value||0),b=parseFloat(document.getElementById('complimentary_non_dcr_kwp').value||0);document.getElementById('capacity_kwp_display').value=(Math.max(0,a)+Math.max(0,b)).toFixed(3).replace(/\.000$/,'');}
document.getElementById('main_solar_kwp').addEventListener('input',totalCapacity);document.getElementById('complimentary_non_dcr_kwp').addEventListener('input',totalCapacity);

function optionList(rows,valueKey,labelFn,selected=''){return '<option value="">Select</option>'+rows.map(r=>`<option value="${escHtml(r[valueKey]||'')}" ${(r[valueKey]||'')===selected?'selected':''}>${escHtml(labelFn(r))}</option>`).join('');}
function addItemRow(item={}){
  const idx=itemRowCounter++;
  const tbody=document.getElementById('item-rows'); const tr=document.createElement('tr');
  const type=item.type==='kit'?'kit':'component'; const kitId=item.kit_id||''; const componentId=item.component_id||''; const variantId=item.variant_id||''; const qty=item.qty||1; const unit=item.unit||''; const mode=item.description_mode==='manual'?'manual':'auto'; const desc=item.custom_description||item.auto_description||item.description_snapshot||'';
  tr.innerHTML=`<td><select name="quote_item_type[${idx}]" class="item-type"><option value="component" ${type==='component'?'selected':''}>Component</option><option value="kit" ${type==='kit'?'selected':''}>Kit</option></select></td><td><select name="quote_item_kit_id[${idx}]" class="item-kit">${optionList(kits,'id',r=>r.name||r.id,kitId)}</select></td><td><select name="quote_item_component_id[${idx}]" class="item-component">${optionList(components,'id',r=>r.name||r.id,componentId)}</select></td><td><select name="quote_item_variant_id[${idx}]" class="item-variant"></select></td><td><input type="number" min="0.001" step="0.001" name="quote_item_qty[${idx}]" value="${escHtml(qty)}"></td><td><input name="quote_item_unit[${idx}]" class="item-unit" value="${escHtml(unit)}"></td><td><select name="quote_item_description_mode[${idx}]" class="desc-mode"><option value="auto" ${mode==='auto'?'selected':''}>Auto</option><option value="manual" ${mode==='manual'?'selected':''}>Manual</option></select><input type="hidden" name="quote_item_auto_description[${idx}]" class="auto-desc" value="${escHtml(item.auto_description||'')}"></td><td><textarea name="quote_item_custom_description[${idx}]" class="custom-desc">${escHtml(desc)}</textarea></td><td><button type="button" class="btn warn remove-item">Remove</button></td>`;
  tbody.appendChild(tr);
  const typeEl=tr.querySelector('.item-type'),kitEl=tr.querySelector('.item-kit'),compEl=tr.querySelector('.item-component'),variantEl=tr.querySelector('.item-variant'),unitEl=tr.querySelector('.item-unit'),descEl=tr.querySelector('.custom-desc'),autoEl=tr.querySelector('.auto-desc');
  function rebuildVariants(selected=variantId){const list=variants.filter(v=>String(v.component_id||'')===String(compEl.value||''));variantEl.innerHTML=optionList(list,'id',r=>r.display_name||r.model_no||r.id,selected);}
  function syncMaster(){
    const isKit=typeEl.value==='kit';kitEl.classList.toggle('inactive-choice',!isKit);compEl.classList.toggle('inactive-choice',isKit);variantEl.classList.toggle('inactive-choice',isKit);
    if(isKit){compEl.value='';variantEl.innerHTML='<option value="">Not applicable</option>';const r=kits.find(x=>String(x.id)===String(kitEl.value));if(r){if(!unitEl.value)unitEl.value='set';autoEl.value=r.description||'';if(tr.querySelector('.desc-mode').value==='auto')descEl.value=r.description||'';}}
    else{kitEl.value='';const r=components.find(x=>String(x.id)===String(compEl.value));rebuildVariants();if(r){if(!unitEl.value)unitEl.value=r.default_unit||'pcs';autoEl.value=r.description||r.notes||'';if(tr.querySelector('.desc-mode').value==='auto')descEl.value=r.description||r.notes||'';}}
  }
  typeEl.onchange=syncMaster;kitEl.onchange=syncMaster;compEl.onchange=()=>{rebuildVariants('');syncMaster();};tr.querySelector('.desc-mode').onchange=e=>{if(e.target.value==='auto')descEl.value=autoEl.value||'';};tr.querySelector('.remove-item').onclick=()=>tr.remove();rebuildVariants();syncMaster();
}
(initialItems.length?initialItems:[{}]).forEach(addItemRow);document.getElementById('add-item-row').onclick=()=>addItemRow({});

function rebuildModels(){const system=document.getElementById('system_type').value;const select=document.getElementById('selected_model_number');const list=rateRows.filter(r=>r.system_type===system);select.innerHTML=optionList(list,'model_number',r=>[r.model_number,r.variant,r.inverter_code,r.battery_code].filter(Boolean).join(' · '),selectedModel);}
document.getElementById('system_type').addEventListener('change',rebuildModels);rebuildModels();totalCapacity();
</script>
</body></html>
