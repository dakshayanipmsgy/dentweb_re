<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/customer_records.php';
require_once __DIR__ . '/includes/leads.php';
require_once __DIR__ . '/includes/docs/quotation.php';
require_once __DIR__ . '/includes/docs/layout-quotation.php';

require_login();
$user = current_user() ?? [];
$role = (string) ($user['role_name'] ?? '');
if (!in_array($role, ['admin', 'employee'], true)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$store = new CustomerRecordStore();
$customers = $store->customers();
$customerLeads = $store->leads();
$legacyLeads = load_all_leads();

$templateSets = quotation_load_template_sets();
$segments = ['RES', 'COM', 'IND', 'INST', 'PROD'];

$messages = [];
$errors = [];
$current = quotation_default_record();
$editingId = trim((string) ($_GET['id'] ?? ''));

if ($editingId !== '') {
    $existing = quotation_load_json(quotation_data_dir() . '/' . $editingId . '.json', []);
    if ($existing !== []) {
        $current = array_replace_recursive($current, $existing);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;
    if (!verify_csrf_token(is_string($csrf) ? $csrf : null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = (string) ($_POST['action'] ?? 'generate');
        $current = array_replace_recursive($current, [
            'customer_type' => trim((string) ($_POST['customer_type'] ?? 'customer')),
            'customer_id_or_mobile' => trim((string) ($_POST['customer_id_or_mobile'] ?? '')),
            'customer_name' => trim((string) ($_POST['customer_name'] ?? '')),
            'billing_address' => trim((string) ($_POST['billing_address'] ?? '')),
            'shipping_address' => trim((string) ($_POST['shipping_address'] ?? '')),
            'gstin' => trim((string) ($_POST['gstin'] ?? '')),
            'place_of_supply_state_code' => trim((string) ($_POST['place_of_supply_state_code'] ?? '20')),
            'segment' => trim((string) ($_POST['segment'] ?? 'RES')),
            'system_type' => trim((string) ($_POST['system_type'] ?? 'ongrid')),
            'capacity_kwp' => (float) ($_POST['capacity_kwp'] ?? 0),
            'template_set_id' => trim((string) ($_POST['template_set_id'] ?? '')),
            'valid_until' => trim((string) ($_POST['valid_until'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
            'status' => trim((string) ($_POST['status'] ?? 'draft')),
        ]);

        $finalAmount = (float) ($_POST['final_gst_inclusive'] ?? 0);
        if ($current['customer_name'] === '') {
            $errors[] = 'Customer name is required.';
        }
        if (!in_array($current['segment'], $segments, true)) {
            $errors[] = 'Invalid segment.';
        }
        if ($finalAmount <= 0) {
            $errors[] = 'Final GST inclusive amount must be greater than zero.';
        }

        $loaded = null;
        if ($current['customer_id_or_mobile'] !== '') {
            foreach ($customers as $record) {
                if ((string) ($record['id'] ?? '') === $current['customer_id_or_mobile']) {
                    $loaded = $record;
                    break;
                }
            }
            if ($loaded === null) {
                foreach ($customerLeads as $record) {
                    if ((string) ($record['id'] ?? '') === $current['customer_id_or_mobile']) {
                        $loaded = $record;
                        break;
                    }
                }
            }
        }

        if ($loaded !== null) {
            if ($current['customer_name'] === '') {
                $current['customer_name'] = (string) ($loaded['full_name'] ?? '');
            }
            if ($current['billing_address'] === '') {
                $current['billing_address'] = trim((string) (($loaded['address_line'] ?? '') . ' ' . ($loaded['district'] ?? '')));
            }
        }

        if ($errors === []) {
            $current['pricing'] = quotation_calculate_split_70_30($finalAmount, (string) $current['place_of_supply_state_code']);

            if ($action === 'revision') {
                $sourceId = trim((string) ($_POST['source_id'] ?? ''));
                $source = quotation_load_json(quotation_data_dir() . '/' . $sourceId . '.json', []);
                $revisionNo = (int) ($source['revision_no'] ?? 0) + 1;

                $current['id'] = quotation_generate_id();
                $current['revision_no'] = $revisionNo;
                $current['quote_number'] = quotation_create_revision_number((string) ($source['quote_number'] ?? ''), $revisionNo);
                $current['status'] = 'draft';
                $current['created_at'] = quotation_now_iso();
            } else {
                if ((string) ($current['status'] ?? 'draft') === 'finalized' && !empty($current['id'])) {
                    $errors[] = 'Finalized quotation is locked. Please create revision.';
                } else {
                    if (trim((string) ($current['id'] ?? '')) === '') {
                        $current['id'] = quotation_generate_id();
                        $current['quote_number'] = next_doc_number('quotation', (string) $current['segment']);
                        $current['created_at'] = quotation_now_iso();
                    }
                }
            }
        }

        if ($errors === []) {
            $current['created_by'] = (string) ($user['username'] ?? $user['name'] ?? 'system');
            $current['updated_at'] = quotation_now_iso();

            $template = quotation_find_template_set((string) $current['template_set_id']) ?? [];
            $company = quotation_load_company_profile();
            $html = quotation_render_layout($current, $template, $company);
            $htmlPathAbs = quotation_html_output_path((string) $current['id']);
            $pdfPathAbs = quotation_pdf_output_path((string) $current['id']);

            file_put_contents($htmlPathAbs, $html, LOCK_EX);
            quotation_generate_pdf_from_html($html, $pdfPathAbs);

            $current['html_path'] = '/data/docs/quotations/' . $current['id'] . '.html';
            $current['pdf_path'] = '/data/docs/quotations/' . $current['id'] . '.pdf';

            if (quotation_save_record($current)) {
                $messages[] = 'Quotation saved and generated successfully.';
                $editingId = (string) $current['id'];
            } else {
                $errors[] = 'Unable to save quotation JSON.';
            }
        }
    }
}

$previewPricing = quotation_calculate_split_70_30((float) ($current['pricing']['final_gst_inclusive'] ?? 0), (string) ($current['place_of_supply_state_code'] ?? '20'));
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Quotation</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f5f7fb; margin:0; }
        .wrap { max-width:1100px; margin:24px auto; background:#fff; border:1px solid #dbe3ef; border-radius:10px; padding:20px; }
        h1 { margin-top:0; }
        h2 { margin-bottom:10px; font-size:18px; }
        .grid { display:grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap:12px; }
        label { display:block; font-size:12px; color:#475569; margin-bottom:4px; }
        input, select, textarea { width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; }
        textarea { min-height:80px; }
        .full { grid-column: 1 / -1; }
        .box { border:1px solid #e2e8f0; padding:12px; border-radius:8px; margin-bottom:16px; }
        table { width:100%; border-collapse:collapse; margin-top:8px; }
        th,td { border:1px solid #e2e8f0; padding:8px; font-size:13px; text-align:right; }
        th:first-child, td:first-child { text-align:left; }
        .msg { padding:10px; border-radius:8px; margin-bottom:10px; }
        .ok { background:#e7f7ed; color:#166534; }
        .err { background:#fee2e2; color:#991b1b; }
        .actions { display:flex; gap:10px; margin-top:12px; }
        button { padding:10px 14px; border:0; background:#1d4ed8; color:#fff; border-radius:8px; cursor:pointer; }
        .secondary { background:#334155; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Quotation Generator (Phase 2)</h1>
    <?php foreach ($messages as $msg): ?><div class="msg ok"><?= htmlspecialchars($msg, ENT_QUOTES) ?></div><?php endforeach; ?>
    <?php foreach ($errors as $err): ?><div class="msg err"><?= htmlspecialchars($err, ENT_QUOTES) ?></div><?php endforeach; ?>

    <form method="post" id="quotationForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>">
        <input type="hidden" name="source_id" value="<?= htmlspecialchars((string) ($current['id'] ?? ''), ENT_QUOTES) ?>">

        <div class="box">
            <h2>Section A – Party Details</h2>
            <div class="grid">
                <div>
                    <label>Source Mode</label>
                    <select id="party_mode" name="customer_type">
                        <option value="customer" <?= (string) ($current['customer_type'] ?? '') === 'customer' ? 'selected' : '' ?>>Existing Customer</option>
                        <option value="lead" <?= (string) ($current['customer_type'] ?? '') === 'lead' ? 'selected' : '' ?>>Existing Lead</option>
                        <option value="manual" <?= (string) ($current['customer_type'] ?? '') === 'manual' ? 'selected' : '' ?>>Manual Entry</option>
                    </select>
                </div>
                <div>
                    <label>Customer/Lead</label>
                    <select id="customer_select" name="customer_id_or_mobile">
                        <option value="">-- Select --</option>
                        <?php foreach ($customers as $row): ?>
                            <option data-kind="customer" data-name="<?= htmlspecialchars((string) ($row['full_name'] ?? ''), ENT_QUOTES) ?>" data-address="<?= htmlspecialchars(trim((string) (($row['address_line'] ?? '') . ' ' . ($row['district'] ?? ''))), ENT_QUOTES) ?>" data-mobile="<?= htmlspecialchars((string) ($row['phone'] ?? ''), ENT_QUOTES) ?>" value="<?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?>" <?= (string) ($current['customer_id_or_mobile'] ?? '') === (string) ($row['id'] ?? '') ? 'selected' : '' ?>>[Customer] <?= htmlspecialchars((string) ($row['full_name'] ?? 'Unknown') . ' - ' . (string) ($row['phone'] ?? ''), ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                        <?php foreach ($customerLeads as $row): ?>
                            <option data-kind="lead" data-name="<?= htmlspecialchars((string) ($row['full_name'] ?? ''), ENT_QUOTES) ?>" data-address="<?= htmlspecialchars(trim((string) (($row['address_line'] ?? '') . ' ' . ($row['district'] ?? ''))), ENT_QUOTES) ?>" data-mobile="<?= htmlspecialchars((string) ($row['phone'] ?? ''), ENT_QUOTES) ?>" value="<?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?>">[Lead] <?= htmlspecialchars((string) ($row['full_name'] ?? 'Unknown') . ' - ' . (string) ($row['phone'] ?? ''), ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                        <?php foreach ($legacyLeads as $lead): ?>
                            <option data-kind="lead" data-name="<?= htmlspecialchars((string) ($lead['name'] ?? ''), ENT_QUOTES) ?>" data-address="<?= htmlspecialchars((string) (($lead['area_or_locality'] ?? '') . ' ' . ($lead['city'] ?? '')), ENT_QUOTES) ?>" data-mobile="<?= htmlspecialchars((string) ($lead['mobile'] ?? ''), ENT_QUOTES) ?>" value="<?= htmlspecialchars((string) ($lead['id'] ?? ''), ENT_QUOTES) ?>">[Lead] <?= htmlspecialchars((string) ($lead['name'] ?? 'Unknown') . ' - ' . (string) ($lead['mobile'] ?? ''), ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Customer Name</label><input type="text" id="customer_name" name="customer_name" value="<?= htmlspecialchars((string) ($current['customer_name'] ?? ''), ENT_QUOTES) ?>"></div>
                <div><label>GSTIN</label><input type="text" name="gstin" value="<?= htmlspecialchars((string) ($current['gstin'] ?? ''), ENT_QUOTES) ?>"></div>
                <div class="full"><label>Billing Address</label><textarea id="billing_address" name="billing_address"><?= htmlspecialchars((string) ($current['billing_address'] ?? ''), ENT_QUOTES) ?></textarea></div>
                <div class="full"><label>Shipping Address</label><textarea name="shipping_address"><?= htmlspecialchars((string) ($current['shipping_address'] ?? ''), ENT_QUOTES) ?></textarea></div>
            </div>
        </div>

        <div class="box">
            <h2>Section B – Project Details</h2>
            <div class="grid">
                <div><label>Segment</label><select name="segment" id="segment"><?php foreach ($segments as $seg): ?><option value="<?= $seg ?>" <?= (string) ($current['segment'] ?? 'RES') === $seg ? 'selected' : '' ?>><?= $seg ?></option><?php endforeach; ?></select></div>
                <div><label>System Type</label><select name="system_type"><option value="ongrid">ongrid</option><option value="hybrid" <?= (string) ($current['system_type'] ?? '') === 'hybrid' ? 'selected' : '' ?>>hybrid</option><option value="offgrid" <?= (string) ($current['system_type'] ?? '') === 'offgrid' ? 'selected' : '' ?>>offgrid</option></select></div>
                <div><label>Capacity (kWp)</label><input type="number" min="0" step="0.01" name="capacity_kwp" value="<?= htmlspecialchars((string) ($current['capacity_kwp'] ?? '3'), ENT_QUOTES) ?>"></div>
                <div><label>Place of Supply State Code</label><input type="text" id="state_code" name="place_of_supply_state_code" value="<?= htmlspecialchars((string) ($current['place_of_supply_state_code'] ?? '20'), ENT_QUOTES) ?>"></div>
                <div class="full"><label>Template Set</label>
                    <select name="template_set_id" id="template_set_id">
                        <option value="">-- Select Template --</option>
                        <?php foreach ($templateSets as $tpl): ?>
                            <?php $seg = (string) ($tpl['segment'] ?? ''); ?>
                            <option data-segment="<?= htmlspecialchars($seg, ENT_QUOTES) ?>" value="<?= htmlspecialchars((string) ($tpl['id'] ?? ''), ENT_QUOTES) ?>" <?= (string) ($current['template_set_id'] ?? '') === (string) ($tpl['id'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars((string) ($tpl['name'] ?? '') . ' [' . $seg . ']', ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="box">
            <h2>Section C – Pricing (SPLIT_70_30)</h2>
            <div class="grid">
                <div><label>Final GST Inclusive Amount</label><input type="number" min="0" step="0.01" id="final_gst_inclusive" name="final_gst_inclusive" value="<?= htmlspecialchars((string) ($current['pricing']['final_gst_inclusive'] ?? ''), ENT_QUOTES) ?>"></div>
                <div><label>Valid Until</label><input type="date" name="valid_until" value="<?= htmlspecialchars((string) ($current['valid_until'] ?? ''), ENT_QUOTES) ?>"></div>
                <div class="full"><label>Notes</label><textarea name="notes"><?= htmlspecialchars((string) ($current['notes'] ?? ''), ENT_QUOTES) ?></textarea></div>
                <div><label>Status</label><select name="status"><option value="draft" <?= (string) ($current['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option><option value="finalized" <?= (string) ($current['status'] ?? '') === 'finalized' ? 'selected' : '' ?>>Finalized</option></select></div>
            </div>

            <table id="pricing_preview">
                <thead><tr><th>Component</th><th>Amount (₹)</th></tr></thead>
                <tbody>
                    <tr><td>Basic Total</td><td id="pv_basic"><?= number_format((float) ($previewPricing['basic_total'] ?? 0), 2) ?></td></tr>
                    <tr><td>CGST 2.5%</td><td id="pv_cgst25"><?= number_format((float) ($previewPricing['bucket_5']['cgst'] ?? 0), 2) ?></td></tr>
                    <tr><td>SGST 2.5%</td><td id="pv_sgst25"><?= number_format((float) ($previewPricing['bucket_5']['sgst'] ?? 0), 2) ?></td></tr>
                    <tr><td>CGST 9%</td><td id="pv_cgst9"><?= number_format((float) ($previewPricing['bucket_18']['cgst'] ?? 0), 2) ?></td></tr>
                    <tr><td>SGST 9%</td><td id="pv_sgst9"><?= number_format((float) ($previewPricing['bucket_18']['sgst'] ?? 0), 2) ?></td></tr>
                    <tr><td>IGST (if outside Jharkhand)</td><td id="pv_igst"><?= number_format((float) (($previewPricing['bucket_5']['igst'] ?? 0) + ($previewPricing['bucket_18']['igst'] ?? 0)), 2) ?></td></tr>
                    <tr><td>Round Off</td><td id="pv_round"><?= number_format((float) ($previewPricing['round_off'] ?? 0), 2) ?></td></tr>
                    <tr><td><strong>Grand Total</strong></td><td id="pv_total"><strong><?= number_format((float) ($previewPricing['grand_total'] ?? 0), 2) ?></strong></td></tr>
                </tbody>
            </table>
        </div>

        <div class="actions">
            <button type="submit" name="action" value="generate">Generate Quotation</button>
            <?php if ((string) ($current['status'] ?? '') === 'finalized' && (string) ($current['id'] ?? '') !== ''): ?>
                <button type="submit" name="action" value="revision" class="secondary">Create Revision</button>
            <?php endif; ?>
            <?php if ((string) ($current['id'] ?? '') !== ''): ?>
                <a href="<?= htmlspecialchars((string) ($current['html_path'] ?? ''), ENT_QUOTES) ?>" target="_blank">View HTML</a>
                <a href="<?= htmlspecialchars((string) ($current['pdf_path'] ?? ''), ENT_QUOTES) ?>" target="_blank">View PDF</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
(function(){
    function round2(v){ return Math.round(v * 100) / 100; }
    function fmt(v){ return Number(v).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}); }

    function recalc(){
        var finalAmt = parseFloat(document.getElementById('final_gst_inclusive').value || '0');
        var stateCode = (document.getElementById('state_code').value || '').trim();
        var taxFactor = (0.70 * 1.05) + (0.30 * 1.18);
        var basicTotal = finalAmt > 0 ? (finalAmt / taxFactor) : 0;
        var b70 = basicTotal * 0.70;
        var b30 = basicTotal * 0.30;
        var gst5 = b70 * 0.05;
        var gst18 = b30 * 0.18;
        var intra = stateCode === '20';
        var cgst25 = intra ? gst5 / 2 : 0;
        var sgst25 = intra ? gst5 / 2 : 0;
        var cgst9 = intra ? gst18 / 2 : 0;
        var sgst9 = intra ? gst18 / 2 : 0;
        var igst = intra ? 0 : gst5 + gst18;

        var gross = b70 + b30 + gst5 + gst18;
        var grand = Math.round(finalAmt);
        var roundOff = grand - gross;

        document.getElementById('pv_basic').innerText = fmt(round2(b70 + b30));
        document.getElementById('pv_cgst25').innerText = fmt(round2(cgst25));
        document.getElementById('pv_sgst25').innerText = fmt(round2(sgst25));
        document.getElementById('pv_cgst9').innerText = fmt(round2(cgst9));
        document.getElementById('pv_sgst9').innerText = fmt(round2(sgst9));
        document.getElementById('pv_igst').innerText = fmt(round2(igst));
        document.getElementById('pv_round').innerText = fmt(round2(roundOff));
        document.getElementById('pv_total').innerHTML = '<strong>' + fmt(round2(grand)) + '</strong>';
    }

    function autofillFromSelection(){
        var select = document.getElementById('customer_select');
        var option = select.options[select.selectedIndex];
        if (!option) return;
        if ((document.getElementById('customer_name').value || '').trim() === '' && option.dataset.name) {
            document.getElementById('customer_name').value = option.dataset.name;
        }
        if ((document.getElementById('billing_address').value || '').trim() === '' && option.dataset.address) {
            document.getElementById('billing_address').value = option.dataset.address;
        }
    }

    function filterTemplatesBySegment(){
        var segment = document.getElementById('segment').value;
        var select = document.getElementById('template_set_id');
        Array.prototype.forEach.call(select.options, function(opt, idx){
            if (idx === 0) return;
            var seg = opt.dataset.segment || '';
            opt.hidden = seg !== segment;
        });
    }

    document.getElementById('final_gst_inclusive').addEventListener('input', recalc);
    document.getElementById('state_code').addEventListener('input', recalc);
    document.getElementById('customer_select').addEventListener('change', autofillFromSelection);
    document.getElementById('segment').addEventListener('change', filterTemplatesBySegment);
    recalc();
    filterTemplatesBySegment();
})();
</script>
</body>
</html>
