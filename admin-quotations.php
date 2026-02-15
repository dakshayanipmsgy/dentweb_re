<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();
documents_seed_template_sets_if_empty();

$templates = array_values(array_filter(json_load(documents_templates_dir() . '/template_sets.json', []), static function ($row): bool {
    return is_array($row) && !($row['archived_flag'] ?? false);
}));
$templateBlocks = documents_sync_template_block_entries($templates);
$company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));

$redirectWith = static function (string $type, string $msg): void {
    header('Location: admin-quotations.php?' . http_build_query(['status' => $type, 'message' => $msg]));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirectWith('error', 'Security validation failed.');
    }

    $action = safe_text($_POST['action'] ?? '');
    if ($action === 'save_quote') {
        $quoteId = safe_text($_POST['quote_id'] ?? '');
$existing = $quoteId !== '' ? documents_get_quote($quoteId) : null;
        if ($existing !== null && (string) ($existing['status'] ?? 'Draft') !== 'Draft') {
            $redirectWith('error', 'Only Draft quotations can be edited.');
        }

        $templateSetId = safe_text($_POST['template_set_id'] ?? '');
        $selectedTemplate = null;
        foreach ($templates as $tpl) {
            if ((string) ($tpl['id'] ?? '') === $templateSetId) {
                $selectedTemplate = $tpl;
                break;
            }
        }
        if ($selectedTemplate === null) {
            $redirectWith('error', 'Please select a template set.');
        }

        $partyType = safe_text($_POST['party_type'] ?? 'lead');
        $mobile = normalize_customer_mobile((string) ($_POST['customer_mobile'] ?? ''));
        $customerName = safe_text($_POST['customer_name'] ?? '');
        $customerRecord = $partyType === 'customer' ? documents_find_customer_by_mobile($mobile) : null;

        if ($mobile === '' || $customerName === '') {
            $redirectWith('error', 'Customer mobile and name are required.');
        }

        $capacity = safe_text($_POST['capacity_kwp'] ?? '');
        if ($capacity === '') {
            $redirectWith('error', 'Capacity kWp is required.');
        }

        $inputTotal = (float) ($_POST['input_total_gst_inclusive'] ?? 0);
        if ($inputTotal <= 0) {
            $redirectWith('error', 'Total amount must be greater than zero.');
        }

        $pricingMode = safe_text($_POST['pricing_mode'] ?? 'solar_split_70_30');
        if (!in_array($pricingMode, ['solar_split_70_30', 'flat_5', 'itemized'], true)) {
            $pricingMode = 'solar_split_70_30';
        }
        if ($pricingMode === 'itemized') {
            $redirectWith('error', 'Itemized mode is reserved for Phase 3.');
        }

        $placeOfSupply = safe_text($_POST['place_of_supply_state'] ?? 'Jharkhand');
        $companyState = strtolower(trim((string) ($company['state'] ?? 'Jharkhand')));
        $taxType = strtolower($placeOfSupply) === $companyState ? 'CGST_SGST' : 'IGST';
        $calc = documents_calc_pricing($inputTotal, $pricingMode, $taxType);

        $annexure = [
            'cover_notes' => safe_text($_POST['ann_cover_notes'] ?? ''),
            'system_inclusions' => safe_text($_POST['ann_system_inclusions'] ?? ''),
            'payment_terms' => safe_text($_POST['ann_payment_terms'] ?? ''),
            'warranty' => safe_text($_POST['ann_warranty'] ?? ''),
            'system_type_explainer' => safe_text($_POST['ann_system_type_explainer'] ?? ''),
            'transportation' => safe_text($_POST['ann_transportation'] ?? ''),
            'terms_conditions' => safe_text($_POST['ann_terms_conditions'] ?? ''),
            'pm_subsidy_info' => safe_text($_POST['ann_pm_subsidy_info'] ?? ''),
        ];

        if ($existing === null) {
            $number = documents_generate_quote_number((string) ($selectedTemplate['segment'] ?? 'RES'));
            if (!$number['ok']) {
                $redirectWith('error', (string) $number['error']);
            }

            $blockDefaults = documents_quote_annexure_from_template($templateBlocks, $templateSetId);
            foreach ($annexure as $k => $v) {
                if ($v === '' && $blockDefaults[$k] !== '') {
                    $annexure[$k] = $blockDefaults[$k];
                }
            }

            $id = 'qtn_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
            $quote = documents_quote_defaults();
            $quote['id'] = $id;
            $quote['quote_no'] = (string) $number['quote_no'];
            $quote['created_at'] = date('c');
            $quote['created_by_type'] = 'admin';
            $user = current_user();
            $quote['created_by_id'] = (string) ($user['id'] ?? '');
            $quote['created_by_name'] = (string) ($user['full_name'] ?? 'Admin');
            $quote['segment'] = (string) ($selectedTemplate['segment'] ?? 'RES');
            $quote['status'] = 'Draft';
        } else {
            $quote = $existing;
        }

        $quote['template_set_id'] = $templateSetId;
        $quote['party_type'] = in_array($partyType, ['customer', 'lead'], true) ? $partyType : 'lead';

        $snapshot = documents_build_quote_snapshot_from_request($_POST, $quote['party_type'], $customerRecord);
        $quote['customer_snapshot'] = $snapshot;
        $quote['customer_mobile'] = $mobile;
        $quote['customer_name'] = $customerName;
        $quote['billing_address'] = safe_text($_POST['billing_address'] ?? '') ?: $snapshot['address'];
        $quote['site_address'] = safe_text($_POST['site_address'] ?? '') ?: $snapshot['address'];
        $quote['district'] = safe_text($_POST['district'] ?? '') ?: $snapshot['district'];
        $quote['city'] = safe_text($_POST['city'] ?? '') ?: $snapshot['city'];
        $quote['state'] = safe_text($_POST['state'] ?? 'Jharkhand') ?: $snapshot['state'];
        $quote['pin'] = safe_text($_POST['pin'] ?? '') ?: $snapshot['pin_code'];
        $quote['meter_number'] = safe_text($_POST['meter_number'] ?? '') ?: $snapshot['meter_number'];
        $quote['meter_serial_number'] = safe_text($_POST['meter_serial_number'] ?? '') ?: $snapshot['meter_serial_number'];
        $quote['consumer_account_no'] = safe_text($_POST['consumer_account_no'] ?? '') ?: $snapshot['consumer_account_no'];
        $quote['application_id'] = safe_text($_POST['application_id'] ?? '') ?: $snapshot['application_id'];
        $quote['application_submitted_date'] = safe_text($_POST['application_submitted_date'] ?? '') ?: $snapshot['application_submitted_date'];
        $quote['sanction_load_kwp'] = safe_text($_POST['sanction_load_kwp'] ?? '') ?: $snapshot['sanction_load_kwp'];
        $quote['installed_pv_module_capacity_kwp'] = safe_text($_POST['installed_pv_module_capacity_kwp'] ?? '') ?: $snapshot['installed_pv_module_capacity_kwp'];
        $quote['circle_name'] = safe_text($_POST['circle_name'] ?? '') ?: $snapshot['circle_name'];
        $quote['division_name'] = safe_text($_POST['division_name'] ?? '') ?: $snapshot['division_name'];
        $quote['sub_division_name'] = safe_text($_POST['sub_division_name'] ?? '') ?: $snapshot['sub_division_name'];

        $quote['customer_snapshot'] = array_merge(documents_customer_snapshot_defaults(), $snapshot, [
            'mobile' => $quote['customer_mobile'],
            'name' => $quote['customer_name'],
            'address' => $quote['site_address'],
            'city' => $quote['city'],
            'district' => $quote['district'],
            'pin_code' => $quote['pin'],
            'state' => $quote['state'],
            'meter_number' => $quote['meter_number'],
            'meter_serial_number' => $quote['meter_serial_number'],
            'consumer_account_no' => $quote['consumer_account_no'],
            'application_id' => $quote['application_id'],
            'application_submitted_date' => $quote['application_submitted_date'],
            'sanction_load_kwp' => $quote['sanction_load_kwp'],
            'installed_pv_module_capacity_kwp' => $quote['installed_pv_module_capacity_kwp'],
            'circle_name' => $quote['circle_name'],
            'division_name' => $quote['division_name'],
            'sub_division_name' => $quote['sub_division_name'],
        ]);

        $quote['system_type'] = safe_text($_POST['system_type'] ?? 'Ongrid');
        $quote['capacity_kwp'] = $capacity;
        $quote['project_summary_line'] = safe_text($_POST['project_summary_line'] ?? '');
        $quote['valid_until'] = safe_text($_POST['valid_until'] ?? '');
        $quote['pricing_mode'] = $pricingMode;
        $quote['place_of_supply_state'] = $placeOfSupply;
        $quote['tax_type'] = $taxType;
        $quote['input_total_gst_inclusive'] = round($inputTotal, 2);
        $quote['calc'] = $calc;
        $quote['special_requests_inclusive'] = trim((string) ($_POST['special_requests_inclusive'] ?? ''));
        $quote['special_requests_override_note'] = true;
        $quote['annexures_overrides'] = $annexure;
        $quote['template_attachments'] = (($templateBlocks[$templateSetId]['attachments'] ?? null) && is_array($templateBlocks[$templateSetId]['attachments'])) ? $templateBlocks[$templateSetId]['attachments'] : documents_template_attachment_defaults();
        $quote['finance_inputs']['monthly_bill_rs'] = safe_text($_POST['monthly_bill_rs'] ?? '');
        $quote['finance_inputs']['unit_rate_rs_per_kwh'] = safe_text($_POST['unit_rate_rs_per_kwh'] ?? '');
        $quote['finance_inputs']['annual_generation_per_kw'] = safe_text($_POST['annual_generation_per_kw'] ?? '');
        $quote['finance_inputs']['loan']['enabled'] = isset($_POST['loan_enabled']);
        $quote['finance_inputs']['loan']['interest_pct'] = safe_text($_POST['loan_interest_pct'] ?? '');
        $quote['finance_inputs']['loan']['tenure_years'] = safe_text($_POST['loan_tenure_years'] ?? '');
        $quote['finance_inputs']['loan']['margin_pct'] = safe_text($_POST['loan_margin_pct'] ?? '');
        $quote['finance_inputs']['loan']['loan_amount'] = safe_text($_POST['loan_amount'] ?? '');
        $quote['finance_inputs']['subsidy_expected_rs'] = safe_text($_POST['subsidy_expected_rs'] ?? '');
        $quote['finance_inputs']['transportation_rs'] = safe_text($_POST['transportation_rs'] ?? '');
        $quote['finance_inputs']['notes_for_customer'] = trim((string) ($_POST['notes_for_customer'] ?? ''));
        $quote['style_overrides']['typography']['base_font_px'] = safe_text($_POST['style_base_font_px'] ?? '');
        $quote['style_overrides']['typography']['heading_scale'] = safe_text($_POST['style_heading_scale'] ?? '');
        $quote['style_overrides']['typography']['density'] = safe_text($_POST['style_density'] ?? '');
        $quote['style_overrides']['watermark']['enabled'] = isset($_POST['watermark_enabled']) ? '1' : '';
        $quote['style_overrides']['watermark']['opacity'] = safe_text($_POST['watermark_opacity'] ?? '');
        $quote['style_overrides']['watermark']['image_path'] = safe_text($_POST['watermark_image_path'] ?? '');
        $quote['updated_at'] = date('c');

        $saved = documents_save_quote($quote);
        if (!$saved['ok']) {
            $redirectWith('error', 'Failed to save quotation.');
        }

        header('Location: quotation-view.php?id=' . urlencode((string) $quote['id']) . '&ok=1');
        exit;
    }
}

$allQuotes = documents_list_quotes();
$statusFilter = safe_text($_GET['status_filter'] ?? '');
if ($statusFilter !== '') {
    $allQuotes = array_values(array_filter($allQuotes, static function (array $q) use ($statusFilter): bool {
        $status = (string) ($q['status'] ?? 'Draft');
        if ($statusFilter === 'needs_approval') {
            return $status === 'Draft' && (string) ($q['created_by_type'] ?? '') === 'employee';
        }
        return strtolower($status) === strtolower($statusFilter);
    }));
}
$editingId = safe_text($_GET['edit'] ?? '');
$editing = $editingId !== '' ? documents_get_quote($editingId) : null;
$quoteDefaults = documents_get_quote_defaults_settings();
$segmentDefaults = is_array($quoteDefaults['segments'][$editing['segment'] ?? ''] ?? null) ? $quoteDefaults['segments'][$editing['segment']] : [];
if ($editing !== null && (string) ($editing['status'] ?? 'Draft') !== 'Draft') {
    $editing = null;
}

if ($editing === null) {
    $editing = documents_quote_defaults();
    $editing['valid_until'] = date('Y-m-d', strtotime('+7 days'));
}

$status = safe_text($_GET['status'] ?? '');
$message = safe_text($_GET['message'] ?? '');
$lookupMobile = safe_text($_GET['lookup_mobile'] ?? '');
$lookup = $lookupMobile !== '' ? documents_find_customer_by_mobile($lookupMobile) : null;
$quoteSnapshot = documents_quote_resolve_snapshot($editing);
if ($lookup !== null) {
    $quoteSnapshot = array_merge($quoteSnapshot, $lookup);
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Quotations</title>
<style>body{font-family:Arial,sans-serif;background:#f4f6fa;margin:0}.wrap{padding:16px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}label{font-size:12px;font-weight:700;display:block;margin-bottom:4px}input,select,textarea{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}textarea{min-height:70px}.btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;border:none;border-radius:8px;padding:8px 12px;cursor:pointer}.btn.secondary{background:#fff;color:#1f2937;border:1px solid #cbd5e1}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe1ea;padding:8px;text-align:left;font-size:13px}.muted{color:#64748b}.alert{padding:8px;border-radius:8px;margin-bottom:12px}.ok{background:#ecfdf5}.err{background:#fef2f2}</style></head>
<body><main class="wrap">
<div class="card"><h1>Quotations</h1><a class="btn secondary" href="admin-documents.php">Back to Documents</a> <a class="btn" href="admin-quotations.php">Create New</a></div>
<?php if ($message !== ''): ?><div class="alert <?= $status === 'success' ? 'ok' : 'err' ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>
<div class="card">
<h2><?= $editing['id'] === '' ? 'Create Quotation' : 'Edit Quotation' ?></h2>
<form method="get" style="margin-bottom:10px">
<label>Customer Lookup by Mobile</label><div style="display:flex;gap:8px"><input type="text" name="lookup_mobile" value="<?= htmlspecialchars($lookupMobile, ENT_QUOTES) ?>"><button class="btn secondary" type="submit">Lookup</button></div>
</form>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
<input type="hidden" name="action" value="save_quote"><input type="hidden" name="quote_id" value="<?= htmlspecialchars((string) $editing['id'], ENT_QUOTES) ?>">
<div class="grid">
<div><label>Template Set</label><select name="template_set_id" required><?php foreach ($templates as $tpl): ?><option value="<?= htmlspecialchars((string)$tpl['id'], ENT_QUOTES) ?>" <?= ((string)$editing['template_set_id']===(string)$tpl['id'])?'selected':'' ?>><?= htmlspecialchars((string)$tpl['name'], ENT_QUOTES) ?> (<?= htmlspecialchars((string)$tpl['segment'], ENT_QUOTES) ?>)</option><?php endforeach; ?></select></div>
<div><label>Party Type</label><select name="party_type"><option value="customer" <?= $editing['party_type']==='customer'?'selected':'' ?>>Customer</option><option value="lead" <?= $editing['party_type']!=='customer'?'selected':'' ?>>Lead</option></select></div>
<div><label>Mobile</label><input name="customer_mobile" required value="<?= htmlspecialchars((string)(($lookupMobile !== '' && $lookup !== null) ? $lookupMobile : $editing['customer_mobile']), ENT_QUOTES) ?>"></div>
<div><label>Name</label><input name="customer_name" required value="<?= htmlspecialchars((string)($quoteSnapshot['name'] ?? $editing['customer_name']), ENT_QUOTES) ?>"></div>
<div><label>Consumer Account No. (JBVNL)</label><input name="consumer_account_no" value="<?= htmlspecialchars((string)(($editing['consumer_account_no'] !== '') ? $editing['consumer_account_no'] : ($quoteSnapshot['consumer_account_no'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Meter Number</label><input name="meter_number" value="<?= htmlspecialchars((string)(($editing['meter_number'] !== '') ? $editing['meter_number'] : ($quoteSnapshot['meter_number'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Meter Serial Number</label><input name="meter_serial_number" value="<?= htmlspecialchars((string)(($editing['meter_serial_number'] !== '') ? $editing['meter_serial_number'] : ($quoteSnapshot['meter_serial_number'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>System Type</label><select name="system_type"><?php foreach (['Ongrid','Hybrid','Offgrid','Product'] as $t): ?><option value="<?= $t ?>" <?= $editing['system_type']===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></div>
<div><label>Capacity kWp</label><input name="capacity_kwp" required value="<?= htmlspecialchars((string)$editing['capacity_kwp'], ENT_QUOTES) ?>"></div>
<div><label>Valid Until</label><input type="date" name="valid_until" value="<?= htmlspecialchars((string)$editing['valid_until'], ENT_QUOTES) ?>"></div>
<div><label>Total (GST Inclusive)</label><input type="number" step="0.01" min="0" required name="input_total_gst_inclusive" value="<?= htmlspecialchars((string)$editing['input_total_gst_inclusive'], ENT_QUOTES) ?>"></div>
<div><label>Pricing Mode</label><select name="pricing_mode"><option value="solar_split_70_30" <?= $editing['pricing_mode']==='solar_split_70_30'?'selected':'' ?>>solar_split_70_30</option><option value="flat_5" <?= $editing['pricing_mode']==='flat_5'?'selected':'' ?>>flat_5</option></select></div>
<div><label>Place of Supply State</label><input name="place_of_supply_state" value="<?= htmlspecialchars((string)$editing['place_of_supply_state'], ENT_QUOTES) ?>"></div>
<div><label>District</label><input name="district" value="<?= htmlspecialchars((string)($quoteSnapshot['district'] ?? $editing['district']), ENT_QUOTES) ?>"></div>
<div><label>City</label><input name="city" value="<?= htmlspecialchars((string)($quoteSnapshot['city'] ?? $editing['city']), ENT_QUOTES) ?>"></div>
<div><label>State</label><input name="state" value="<?= htmlspecialchars((string)($quoteSnapshot['state'] ?? $editing['state']), ENT_QUOTES) ?>"></div>
<div><label>PIN</label><input name="pin" value="<?= htmlspecialchars((string)($quoteSnapshot['pin_code'] ?? $editing['pin']), ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><label>Billing Address</label><textarea name="billing_address"><?= htmlspecialchars((string)((($editing['billing_address'] !== '') ? $editing['billing_address'] : ($quoteSnapshot['address'] ?? ''))), ENT_QUOTES) ?></textarea></div>
<div style="grid-column:1/-1"><label>Site Address</label><textarea name="site_address"><?= htmlspecialchars((string)((($editing['site_address'] !== '') ? $editing['site_address'] : ($quoteSnapshot['address'] ?? ''))), ENT_QUOTES) ?></textarea></div>
<div><label>Application ID</label><input name="application_id" value="<?= htmlspecialchars((string)(($editing['application_id'] !== '') ? $editing['application_id'] : ($quoteSnapshot['application_id'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Application Submitted Date</label><input name="application_submitted_date" value="<?= htmlspecialchars((string)(($editing['application_submitted_date'] !== '') ? $editing['application_submitted_date'] : ($quoteSnapshot['application_submitted_date'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Sanction Load (kWp)</label><input name="sanction_load_kwp" value="<?= htmlspecialchars((string)(($editing['sanction_load_kwp'] !== '') ? $editing['sanction_load_kwp'] : ($quoteSnapshot['sanction_load_kwp'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Installed PV Capacity (kWp)</label><input name="installed_pv_module_capacity_kwp" value="<?= htmlspecialchars((string)(($editing['installed_pv_module_capacity_kwp'] !== '') ? $editing['installed_pv_module_capacity_kwp'] : ($quoteSnapshot['installed_pv_module_capacity_kwp'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Circle</label><input name="circle_name" value="<?= htmlspecialchars((string)(($editing['circle_name'] !== '') ? $editing['circle_name'] : ($quoteSnapshot['circle_name'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Division</label><input name="division_name" value="<?= htmlspecialchars((string)(($editing['division_name'] !== '') ? $editing['division_name'] : ($quoteSnapshot['division_name'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Sub Division</label><input name="sub_division_name" value="<?= htmlspecialchars((string)(($editing['sub_division_name'] !== '') ? $editing['sub_division_name'] : ($quoteSnapshot['sub_division_name'] ?? '')), ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><label>Project Summary</label><input name="project_summary_line" value="<?= htmlspecialchars((string)$editing['project_summary_line'], ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><label>Special Requests From Customer (Inclusive in the rate)</label><textarea name="special_requests_inclusive"><?= htmlspecialchars((string)$editing['special_requests_inclusive'], ENT_QUOTES) ?></textarea><div class="muted">In case of conflict, Special Requests will be given priority over Annexure inclusions.</div></div>
<div style="grid-column:1/-1"><h3>Customer Savings Inputs</h3><div class="muted">Used for dynamic savings/EMI charts in proposal view.</div></div>
<div><label>Monthly electricity bill (₹)</label><input type="number" step="0.01" name="monthly_bill_rs" value="<?= htmlspecialchars((string)($editing['finance_inputs']['monthly_bill_rs'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Unit rate (₹/kWh)</label><input type="number" step="0.01" name="unit_rate_rs_per_kwh" value="<?= htmlspecialchars((string)($editing['finance_inputs']['unit_rate_rs_per_kwh'] ?: ($segmentDefaults['unit_rate_rs_per_kwh'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Annual generation per kW</label><input type="number" step="0.01" name="annual_generation_per_kw" value="<?= htmlspecialchars((string)($editing['finance_inputs']['annual_generation_per_kw'] ?: ($quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Transportation ₹</label><input type="number" step="0.01" name="transportation_rs" value="<?= htmlspecialchars((string)($editing['finance_inputs']['transportation_rs'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Subsidy expected ₹</label><input type="number" step="0.01" name="subsidy_expected_rs" value="<?= htmlspecialchars((string)($editing['finance_inputs']['subsidy_expected_rs'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Loan amount ₹</label><input type="number" step="0.01" name="loan_amount" value="<?= htmlspecialchars((string)($editing['finance_inputs']['loan']['loan_amount'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label><input type="checkbox" name="loan_enabled" <?= !empty($editing['finance_inputs']['loan']['enabled']) ? 'checked' : '' ?>> Loan enabled</label></div>
<div><label>Loan interest %</label><input type="number" step="0.01" name="loan_interest_pct" value="<?= htmlspecialchars((string)($editing['finance_inputs']['loan']['interest_pct'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Loan tenure years</label><input type="number" step="1" name="loan_tenure_years" value="<?= htmlspecialchars((string)($editing['finance_inputs']['loan']['tenure_years'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Margin money %</label><input type="number" step="0.01" name="loan_margin_pct" value="<?= htmlspecialchars((string)($editing['finance_inputs']['loan']['margin_pct'] ?? ''), ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><label>Notes for customer</label><textarea name="notes_for_customer"><?= htmlspecialchars((string)($editing['finance_inputs']['notes_for_customer'] ?? ''), ENT_QUOTES) ?></textarea></div>
<div style="grid-column:1/-1"><h3>Typography & Watermark Overrides</h3></div>
<div><label>Base font px</label><input type="number" step="1" name="style_base_font_px" value="<?= htmlspecialchars((string)($editing['style_overrides']['typography']['base_font_px'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Heading scale</label><input type="number" step="0.1" name="style_heading_scale" value="<?= htmlspecialchars((string)($editing['style_overrides']['typography']['heading_scale'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Density</label><select name="style_density"><option value="">Default</option><option value="compact" <?= (($editing['style_overrides']['typography']['density'] ?? '')==='compact')?'selected':'' ?>>Compact</option><option value="comfortable" <?= (($editing['style_overrides']['typography']['density'] ?? '')==='comfortable')?'selected':'' ?>>Comfortable</option><option value="spacious" <?= (($editing['style_overrides']['typography']['density'] ?? '')==='spacious')?'selected':'' ?>>Spacious</option></select></div>
<div><label><input type="checkbox" name="watermark_enabled" <?= (($editing['style_overrides']['watermark']['enabled'] ?? '')==='1') ? 'checked' : '' ?>> Enable watermark override</label></div>
<div><label>Watermark image path</label><input name="watermark_image_path" value="<?= htmlspecialchars((string)($editing['style_overrides']['watermark']['image_path'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Watermark opacity</label><input type="number" step="0.01" min="0" max="1" name="watermark_opacity" value="<?= htmlspecialchars((string)($editing['style_overrides']['watermark']['opacity'] ?? ''), ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><h3>Annexure Overrides</h3><div class="muted">Annexures are based on template snapshot; edit below.</div></div>
<?php foreach (['cover_notes'=>'Cover Notes','system_inclusions'=>'System Inclusions','payment_terms'=>'Payment Terms','warranty'=>'Warranty','system_type_explainer'=>'System Type Explainer','transportation'=>'Transportation','terms_conditions'=>'Terms & Conditions','pm_subsidy_info'=>'PM Subsidy Info'] as $key=>$label): ?>
<div style="grid-column:1/-1"><label><?= $label ?></label><textarea name="ann_<?= $key ?>"><?= htmlspecialchars((string)($editing['annexures_overrides'][$key] ?? ''), ENT_QUOTES) ?></textarea></div>
<?php endforeach; ?>
</div><br><button class="btn" type="submit">Save Quotation</button>
</form></div>
<div class="card"><h2>Quotation List</h2><form method="get" style="margin-bottom:10px;display:flex;gap:8px;align-items:end"><div><label>Status Filter</label><select name="status_filter"><option value="">All</option><option value="needs_approval" <?= $statusFilter==='needs_approval'?'selected':'' ?>>Needs Approval</option><option value="Approved" <?= $statusFilter==='Approved'?'selected':'' ?>>Approved</option><option value="Accepted" <?= $statusFilter==='Accepted'?'selected':'' ?>>Accepted</option></select></div><button class="btn secondary" type="submit">Apply</button></form>
<table><thead><tr><th>Quote No</th><th>Name</th><th>Created By</th><th>Status</th><th>Amount</th><th>Updated</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($allQuotes as $q): ?><tr>
<td><?= htmlspecialchars((string)$q['quote_no'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$q['customer_name'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$q['created_by_name'], ENT_QUOTES) ?></td><td><?= htmlspecialchars(documents_status_label($q, 'admin'), ENT_QUOTES) ?></td><td>₹<?= number_format((float)$q['calc']['grand_total'],2) ?></td><td><?= htmlspecialchars((string)$q['updated_at'], ENT_QUOTES) ?></td>
<td><a class="btn secondary" href="quotation-view.php?id=<?= urlencode((string)$q['id']) ?>">View</a> <?php if (documents_quote_can_edit($q, 'admin')): ?><a class="btn secondary" href="admin-quotations.php?edit=<?= urlencode((string)$q['id']) ?>">Edit</a><?php endif; ?></td>
</tr><?php endforeach; if ($allQuotes===[]): ?><tr><td colspan="7">No quotations yet.</td></tr><?php endif; ?></tbody></table>
</div>
</main></body></html>
