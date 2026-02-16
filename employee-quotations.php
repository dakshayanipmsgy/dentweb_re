<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

$employeeStore = new EmployeeFsStore();
employee_portal_require_login();
$employee = employee_portal_current_employee($employeeStore);
if ($employee === null) {
    header('Location: login.php?login_type=employee');
    exit;
}

documents_ensure_structure();
documents_seed_template_sets_if_empty();
$templates = array_values(array_filter(json_load(documents_templates_dir() . '/template_sets.json', []), static function ($row): bool {
    return is_array($row) && !($row['archived_flag'] ?? false);
}));
$templateBlocks = documents_sync_template_block_entries($templates);
$company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));

$redirectWith = static function (string $type, string $msg): void {
    header('Location: employee-quotations.php?' . http_build_query(['status' => $type, 'message' => $msg]));
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
        if ($existing !== null && (($existing['created_by_type'] ?? '') !== 'employee' || (string) ($existing['created_by_id'] ?? '') !== (string) ($employee['id'] ?? ''))) {
            $redirectWith('error', 'You can only edit your own quotations.');
        }
        if ($existing !== null) {
            $existingStatus = documents_quote_normalize_status((string) ($existing['status'] ?? 'draft'));
            if ($existingStatus !== 'draft') {
                $redirectWith('error', 'This quotation is not editable because its status is: ' . ucfirst(str_replace('_', ' ', $existingStatus)) . '.');
            }
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
        $capacity = safe_text($_POST['capacity_kwp'] ?? '');
        if ($mobile === '' || $customerName === '' || $capacity === '') {
            $redirectWith('error', 'Please fill required fields (mobile, name, capacity).');
        }

        $pricingMode = safe_text($_POST['pricing_mode'] ?? 'solar_split_70_30');
        if (!in_array($pricingMode, ['solar_split_70_30', 'flat_5'], true)) {
            $pricingMode = 'solar_split_70_30';
        }
        $placeOfSupply = safe_text($_POST['place_of_supply_state'] ?? 'Jharkhand');
        $companyState = strtolower(trim((string) ($company['state'] ?? 'Jharkhand')));
        $taxType = strtolower($placeOfSupply) === $companyState ? 'CGST_SGST' : 'IGST';

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

            $quote = documents_quote_defaults();
            $quote['id'] = 'qtn_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
            $quote['quote_no'] = (string) $number['quote_no'];
            $quote['created_at'] = date('c');
            $quote['created_by_type'] = 'employee';
            $quote['created_by_id'] = (string) ($employee['id'] ?? '');
            $quote['created_by_name'] = (string) ($employee['name'] ?? 'Employee');
            $quote['segment'] = (string) ($selectedTemplate['segment'] ?? 'RES');
        } else {
            $quote = $existing;
        }

        $quote['template_set_id'] = $templateSetId;
        $sourceType = safe_text($_POST['source_type'] ?? (string) ($quote['source']['type'] ?? ''));
        if ($sourceType === 'lead') {
            $quote['source'] = [
                'type' => 'lead',
                'lead_id' => safe_text($_POST['source_lead_id'] ?? (string) ($quote['source']['lead_id'] ?? '')),
                'lead_mobile' => normalize_customer_mobile((string) ($_POST['source_lead_mobile'] ?? ($quote['source']['lead_mobile'] ?? ''))),
            ];
        }
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
        $itemNames = is_array($_POST['item_name'] ?? null) ? $_POST['item_name'] : [];
        $itemDescs = is_array($_POST['item_description'] ?? null) ? $_POST['item_description'] : [];
        $itemHsns = is_array($_POST['item_hsn'] ?? null) ? $_POST['item_hsn'] : [];
        $itemQtys = is_array($_POST['item_qty'] ?? null) ? $_POST['item_qty'] : [];
        $itemUnits = is_array($_POST['item_unit'] ?? null) ? $_POST['item_unit'] : [];
        $rawItems = [];
        $count = count($itemNames);
        for ($i=0; $i<$count; $i++) {
            $rawItems[] = [
                'name' => safe_text((string) ($itemNames[$i] ?? '')),
                'description' => safe_text((string) ($itemDescs[$i] ?? '')),
                'hsn' => safe_text((string) ($itemHsns[$i] ?? '')),
                'qty' => (float) ($itemQtys[$i] ?? 0),
                'unit' => safe_text((string) ($itemUnits[$i] ?? '')),
                'gst_slab' => '5',
                'basic_amount' => 0,
            ];
        }
        $defaultHsn = safe_text((string) ($quoteDefaults['defaults']['hsn_solar'] ?? '8541')) ?: '8541';
        $quote['items'] = documents_normalize_quote_items($rawItems, $quote['system_type'], (float) $quote['capacity_kwp'], $defaultHsn);
        $systemTotalInclGstRs = (float) ($_POST['system_total_incl_gst_rs'] ?? 0);
        $transportationRs = (float) ($_POST['transportation_rs'] ?? 0);
        $subsidyExpectedRs = (float) ($_POST['subsidy_expected_rs'] ?? 0);
        $quote['calc'] = documents_calc_pricing_from_items($quote['items'], $pricingMode, $taxType, $transportationRs, $subsidyExpectedRs, $systemTotalInclGstRs);
        $quote['input_total_gst_inclusive'] = $systemTotalInclGstRs;
        $quote['cover_note_text'] = trim((string) ($_POST['cover_note_text'] ?? ''));
        $quote['special_requests_text'] = trim((string) ($_POST['special_requests_text'] ?? ''));
        $quote['special_requests_inclusive'] = $quote['special_requests_text'];
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
        $quote['finance_inputs']['funding_mode_show_both'] = !isset($_POST['funding_mode_show_both']) || $_POST['funding_mode_show_both'] === '1';
        $quote['finance_inputs']['customer_plans_bank_loan'] = isset($_POST['customer_plans_bank_loan']);
        $quote['finance_inputs']['subsidy_expected_rs'] = (string) $subsidyExpectedRs;
        $quote['finance_inputs']['transportation_rs'] = (string) $transportationRs;
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

$quotes = array_values(array_filter(documents_list_quotes(), static function (array $q) use ($employee): bool {
    return (string) ($q['created_by_type'] ?? '') === 'employee' && (string) ($q['created_by_id'] ?? '') === (string) ($employee['id'] ?? '');
}));
$editingId = safe_text($_GET['edit'] ?? '');
$editing = $editingId !== '' ? documents_get_quote($editingId) : null;
$quoteDefaults = load_quote_defaults();
$resolveSegmentDefaults = static function (string $segmentCode) use ($quoteDefaults): array {
    $segments = is_array($quoteDefaults['segments'] ?? null) ? $quoteDefaults['segments'] : [];
    $segmentCode = strtoupper(trim($segmentCode));
    $segment = is_array($segments[$segmentCode] ?? null) ? $segments[$segmentCode] : [];
    $fallbackRes = is_array($segments['RES'] ?? null) ? $segments['RES'] : [];

    $loanSegment = is_array($segment['loan_bestcase'] ?? null) ? $segment['loan_bestcase'] : [];
    $loanRes = is_array($fallbackRes['loan_bestcase'] ?? null) ? $fallbackRes['loan_bestcase'] : [];

    return [
        'unit_rate_rs_per_kwh' => (float) ($segment['unit_rate_rs_per_kwh'] ?? ($fallbackRes['unit_rate_rs_per_kwh'] ?? 0)),
        'annual_generation_per_kw' => (float) ($segment['annual_generation_per_kw'] ?? ($quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450)),
        'loan_bestcase' => [
            'max_loan_rs' => (float) ($loanSegment['max_loan_rs'] ?? ($loanRes['max_loan_rs'] ?? 200000)),
            'interest_pct' => (float) ($loanSegment['interest_pct'] ?? ($loanRes['interest_pct'] ?? 6.0)),
            'tenure_years' => (int) ($loanSegment['tenure_years'] ?? ($loanRes['tenure_years'] ?? (($segment['loan_defaults']['tenure_years'] ?? ($fallbackRes['loan_defaults']['tenure_years'] ?? 10))))),
            'min_margin_pct' => (float) ($loanSegment['min_margin_pct'] ?? ($loanRes['min_margin_pct'] ?? 10)),
        ],
    ];
};
$editingSegment = safe_text((string) ($editing['segment'] ?? ''));
if ($editingSegment === '' && $editing['id'] === '') {
    $selectedTemplateId = safe_text((string) ($editing['template_set_id'] ?? ''));
    foreach ($templates as $tpl) {
        if ((string) ($tpl['id'] ?? '') === $selectedTemplateId) {
            $editingSegment = safe_text((string) ($tpl['segment'] ?? ''));
            break;
        }
    }
}
$segmentDefaults = $resolveSegmentDefaults($editingSegment !== '' ? $editingSegment : 'RES');
$autofillSegments = [];
foreach (['RES', 'COM', 'IND', 'INST'] as $segCode) {
    $autofillSegments[$segCode] = $resolveSegmentDefaults($segCode);
}
if ($editing !== null && ((string) ($editing['created_by_type'] ?? '') !== 'employee' || (string) ($editing['created_by_id'] ?? '') !== (string) ($employee['id'] ?? ''))) {
    $editing = null;
}
if ($editing !== null && documents_quote_normalize_status((string) ($editing['status'] ?? 'draft')) !== 'draft') {
    $editing = null;
}
if ($editing === null) {
    $editing = documents_quote_defaults();
    $editing['valid_until'] = date('Y-m-d', strtotime('+7 days'));
}

$prefillMessage = '';
$fromLeadId = safe_text($_GET['from_lead_id'] ?? '');
if ($editing['id'] === '' && $fromLeadId !== '') {
    $leadPrefill = documents_get_quote_prefill_from_lead($fromLeadId);
    if (($leadPrefill['ok'] ?? false) === true) {
        $prefill = $leadPrefill['prefill'];
        $editing['party_type'] = 'lead';
        $editing['customer_name'] = (string) ($prefill['customer_name'] ?? '');
        $editing['customer_mobile'] = (string) ($prefill['customer_mobile'] ?? '');
        $editing['city'] = (string) ($prefill['city'] ?? '');
        $editing['district'] = (string) ($prefill['district'] ?? '');
        $editing['state'] = (string) ($prefill['state'] ?? '');
        $editing['billing_address'] = (string) ($prefill['locality'] ?? '');
        $editing['site_address'] = (string) ($prefill['locality'] ?? '');
        $editing['area_or_locality'] = (string) ($prefill['locality'] ?? '');
        $editing['customer_snapshot']['city'] = (string) ($prefill['city'] ?? '');
        $editing['customer_snapshot']['district'] = (string) ($prefill['district'] ?? '');
        $editing['customer_snapshot']['state'] = (string) ($prefill['state'] ?? '');
        $editing['customer_snapshot']['address'] = (string) ($prefill['locality'] ?? '');
        if ($editing['cover_note_text'] === '' && (string) ($prefill['notes'] ?? '') !== '') {
            $editing['cover_note_text'] = (string) ($prefill['notes'] ?? '');
        }
        $editing['source'] = is_array($prefill['source'] ?? null) ? $prefill['source'] : ['type' => '', 'lead_id' => '', 'lead_mobile' => ''];
        $prefillMessage = 'Prefilled from Lead: ' . (string) ($prefill['customer_name'] ?? '');
    } else {
        $prefillMessage = (string) ($leadPrefill['error'] ?? 'Lead prefill was not possible.');
    }
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
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Employee Quotations</title>
<style>body{font-family:Arial,sans-serif;background:#f4f6fa;margin:0}.wrap{padding:16px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}label{font-size:12px;font-weight:700;display:block;margin-bottom:4px}input,select,textarea{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}textarea{min-height:70px}.btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;border:none;border-radius:8px;padding:8px 12px;cursor:pointer}.btn.secondary{background:#fff;color:#1f2937;border:1px solid #cbd5e1}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe1ea;padding:8px;text-align:left;font-size:13px}.muted{color:#64748b}.alert{padding:8px;border-radius:8px;margin-bottom:12px}.ok{background:#ecfdf5}.err{background:#fef2f2}</style></head>
<body><main class="wrap">
<div class="card"><h1>My Quotations</h1><a class="btn secondary" href="employee-documents.php">Back to Documents</a> <a class="btn" href="employee-quotations.php">Create New</a></div>
<?php if ($message !== ''): ?><div class="alert <?= $status === 'success' ? 'ok' : 'err' ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>
<?php if ($prefillMessage !== ''): ?><div class="alert ok"><?= htmlspecialchars($prefillMessage, ENT_QUOTES) ?></div><?php endif; ?>
<div class="card"><h2><?= $editing['id'] === '' ? 'Create Quotation' : 'Edit Quotation' ?></h2>
<form method="get" style="margin-bottom:10px"><label>Customer Lookup by Mobile</label><div style="display:flex;gap:8px"><input type="text" name="lookup_mobile" value="<?= htmlspecialchars($lookupMobile, ENT_QUOTES) ?>"><button class="btn secondary" type="submit">Lookup</button></div></form>
<form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="save_quote"><input type="hidden" name="quote_id" value="<?= htmlspecialchars((string)$editing['id'], ENT_QUOTES) ?>">
<input type="hidden" name="source_type" value="<?= htmlspecialchars((string) ($editing['source']['type'] ?? ''), ENT_QUOTES) ?>">
<input type="hidden" name="source_lead_id" value="<?= htmlspecialchars((string) ($editing['source']['lead_id'] ?? ''), ENT_QUOTES) ?>">
<input type="hidden" name="source_lead_mobile" value="<?= htmlspecialchars((string) ($editing['source']['lead_mobile'] ?? ''), ENT_QUOTES) ?>">
<div class="grid">
<div><label>Template Set</label><select name="template_set_id" required><?php foreach ($templates as $tpl): ?><option value="<?= htmlspecialchars((string)$tpl['id'], ENT_QUOTES) ?>" data-segment="<?= htmlspecialchars((string)($tpl['segment'] ?? 'RES'), ENT_QUOTES) ?>" <?= ((string)$editing['template_set_id']===(string)$tpl['id'])?'selected':'' ?>><?= htmlspecialchars((string)$tpl['name'], ENT_QUOTES) ?> (<?= htmlspecialchars((string)($tpl['segment'] ?? 'RES'), ENT_QUOTES) ?>)</option><?php endforeach; ?></select></div>
<div><label>Party Type</label><select name="party_type"><option value="customer" <?= $editing['party_type']==='customer'?'selected':'' ?>>Customer</option><option value="lead" <?= $editing['party_type']!=='customer'?'selected':'' ?>>Lead</option></select></div>
<div><label>Mobile</label><input name="customer_mobile" required value="<?= htmlspecialchars((string)($lookupMobile !== '' ? $lookupMobile : $editing['customer_mobile']), ENT_QUOTES) ?>"></div>
<div><label>Name</label><input name="customer_name" required value="<?= htmlspecialchars((string)($quoteSnapshot['name'] ?? $editing['customer_name']), ENT_QUOTES) ?>"></div>
<div><label>Consumer Account No. (JBVNL)</label><input name="consumer_account_no" value="<?= htmlspecialchars((string)(($editing['consumer_account_no'] !== '') ? $editing['consumer_account_no'] : ($quoteSnapshot['consumer_account_no'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Meter Number</label><input name="meter_number" value="<?= htmlspecialchars((string)(($editing['meter_number'] !== '') ? $editing['meter_number'] : ($quoteSnapshot['meter_number'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Meter Serial Number</label><input name="meter_serial_number" value="<?= htmlspecialchars((string)(($editing['meter_serial_number'] !== '') ? $editing['meter_serial_number'] : ($quoteSnapshot['meter_serial_number'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>System Type</label><select name="system_type"><?php foreach (['Ongrid','Hybrid','Offgrid','Product'] as $t): ?><option value="<?= $t ?>" <?= $editing['system_type']===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></div>
<div><label>Capacity kWp</label><input name="capacity_kwp" required value="<?= htmlspecialchars((string)$editing['capacity_kwp'], ENT_QUOTES) ?>"></div>
<div><label>Valid Until</label><input type="date" name="valid_until" value="<?= htmlspecialchars((string)$editing['valid_until'], ENT_QUOTES) ?>"></div>
<div><label>Cover note paragraph</label><textarea name="cover_note_text"><?= htmlspecialchars((string)($editing['cover_note_text'] ?: ($quoteDefaults['defaults']['cover_note_template'] ?? '')), ENT_QUOTES) ?></textarea></div>
<div><label>Pricing Mode</label><select name="pricing_mode"><option value="solar_split_70_30" <?= $editing['pricing_mode']==='solar_split_70_30'?'selected':'' ?>>solar_split_70_30</option><option value="flat_5" <?= $editing['pricing_mode']==='flat_5'?'selected':'' ?>>flat_5</option></select></div><div><label>Total system price (including GST) ₹</label><input type="number" step="0.01" required name="system_total_incl_gst_rs" value="<?= htmlspecialchars((string)($editing['input_total_gst_inclusive'] ?? 0), ENT_QUOTES) ?>"></div>
<div><label>Place of Supply State</label><input name="place_of_supply_state" value="<?= htmlspecialchars((string)$editing['place_of_supply_state'], ENT_QUOTES) ?>"></div>
<div><label>District</label><input name="district" value="<?= htmlspecialchars((string)($quoteSnapshot['district'] ?? $editing['district']), ENT_QUOTES) ?>"></div><div><label>City</label><input name="city" value="<?= htmlspecialchars((string)($quoteSnapshot['city'] ?? $editing['city']), ENT_QUOTES) ?>"></div><div><label>State</label><input name="state" value="<?= htmlspecialchars((string)($quoteSnapshot['state'] ?? $editing['state']), ENT_QUOTES) ?>"></div><div><label>PIN</label><input name="pin" value="<?= htmlspecialchars((string)($quoteSnapshot['pin_code'] ?? $editing['pin']), ENT_QUOTES) ?>"></div>
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
<div style="grid-column:1/-1"><label>Special Requests From Consumer (Inclusive in the rate)</label><textarea name="special_requests_text"><?= htmlspecialchars((string)($editing['special_requests_text'] ?: $editing['special_requests_inclusive']), ENT_QUOTES) ?></textarea><div class="muted">In case of conflict between annexures and special requests, special requests will be prioritized.</div></div>
<div style="grid-column:1/-1"><h3>Items Table</h3><table id="itemsTable"><thead><tr><th>Sr No</th><th>Item Name</th><th>Description/Specs</th><th>HSN</th><th>Qty</th><th>Unit</th><th></th></tr></thead><tbody><?php $qItems = is_array($editing['items'] ?? null) && $editing['items'] !== [] ? $editing['items'] : documents_normalize_quote_items([], (string)$editing['system_type'], (float)$editing['capacity_kwp'], (string)($quoteDefaults['defaults']['hsn_solar'] ?? '8541')); foreach ($qItems as $ix => $item): ?><tr><td><?= $ix+1 ?></td><td><input name="item_name[]" value="<?= htmlspecialchars((string)($item['name'] ?? ''), ENT_QUOTES) ?>"></td><td><input name="item_description[]" value="<?= htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES) ?>"></td><td><input name="item_hsn[]" value="<?= htmlspecialchars((string)($item['hsn'] ?? ($quoteDefaults['defaults']['hsn_solar'] ?? '8541')), ENT_QUOTES) ?>"></td><td><input type="number" step="0.01" name="item_qty[]" value="<?= htmlspecialchars((string)($item['qty'] ?? 1), ENT_QUOTES) ?>"></td><td><input name="item_unit[]" value="<?= htmlspecialchars((string)($item['unit'] ?? 'set'), ENT_QUOTES) ?>"></td><td><button type="button" class="btn secondary rm-item">Remove</button></td></tr><?php endforeach; ?></tbody></table><button type="button" class="btn secondary" id="addItemBtn">Add item</button></div><div style="grid-column:1/-1"><h3>Customer Savings Inputs</h3></div>
<div><label>Monthly electricity bill (₹)</label><input type="number" step="0.01" name="monthly_bill_rs" value="<?= htmlspecialchars((string)($editing['finance_inputs']['monthly_bill_rs'] ?? ''), ENT_QUOTES) ?>"><div class="muted">Suggested bill based on generation & tariff. You can change it. <a href="#" id="resetMonthlySuggestion">Reset suggestion</a></div></div>
<div><label>Unit rate (₹/kWh)</label><input type="number" step="0.01" name="unit_rate_rs_per_kwh" value="<?= htmlspecialchars((string)($editing['finance_inputs']['unit_rate_rs_per_kwh'] ?: ($segmentDefaults['unit_rate_rs_per_kwh'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Annual generation per kW</label><input type="number" step="0.01" name="annual_generation_per_kw" value="<?= htmlspecialchars((string)($editing['finance_inputs']['annual_generation_per_kw'] ?: ($quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Funding Mode Display</label><select name="funding_mode_show_both"><option value="1" <?= !isset($editing['finance_inputs']['funding_mode_show_both']) || !empty($editing['finance_inputs']['funding_mode_show_both']) ? 'selected' : '' ?>>Show both self + bank comparison</option><option value="0" <?= isset($editing['finance_inputs']['funding_mode_show_both']) && empty($editing['finance_inputs']['funding_mode_show_both']) ? 'selected' : '' ?>>Hide narrative emphasis</option></select></div>
<div><label><input type="checkbox" name="customer_plans_bank_loan" <?= !empty($editing['finance_inputs']['customer_plans_bank_loan']) ? 'checked' : '' ?>> Customer is planning bank loan</label></div>
<div><label>Transportation ₹</label><input type="number" step="0.01" name="transportation_rs" value="<?= htmlspecialchars((string)($editing['finance_inputs']['transportation_rs'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Subsidy ₹</label><input type="number" step="0.01" name="subsidy_expected_rs" value="<?= htmlspecialchars((string)($editing['finance_inputs']['subsidy_expected_rs'] ?? ''), ENT_QUOTES) ?>"><div class="muted"><a href="#" id="resetSubsidyDefault">Reset to scheme default</a></div></div>
<div><label>Loan amount ₹</label><input type="number" step="0.01" name="loan_amount" value="<?= htmlspecialchars((string)($editing['finance_inputs']['loan']['loan_amount'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label><input type="checkbox" name="loan_enabled" <?= !empty($editing['finance_inputs']['loan']['enabled']) ? 'checked' : '' ?>> Loan enabled</label></div>
<div><label>Loan interest %</label><input type="number" step="0.01" name="loan_interest_pct" value="<?= htmlspecialchars((string)($editing['finance_inputs']['loan']['interest_pct'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Loan tenure years</label><input type="number" step="1" name="loan_tenure_years" value="<?= htmlspecialchars((string)($editing['finance_inputs']['loan']['tenure_years'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Margin money ₹</label><input type="number" step="0.01" name="loan_margin_pct" value="<?= htmlspecialchars((string)($editing['finance_inputs']['loan']['margin_pct'] ?? ''), ENT_QUOTES) ?>"><div class="muted"><a href="#" id="resetLoanDefaults">Reset to defaults</a></div></div>
<div style="grid-column:1/-1"><label>Notes for customer</label><textarea name="notes_for_customer"><?= htmlspecialchars((string)($editing['finance_inputs']['notes_for_customer'] ?? ''), ENT_QUOTES) ?></textarea></div>
<div style="grid-column:1/-1"><div class="muted">Annexures are based on template snapshot; edit below.</div></div><?php foreach (['cover_notes'=>'Cover Notes','system_inclusions'=>'System Inclusions','payment_terms'=>'Payment Terms','warranty'=>'Warranty','system_type_explainer'=>'System Type Explainer','transportation'=>'Transportation','terms_conditions'=>'Terms & Conditions','pm_subsidy_info'=>'PM Subsidy Info'] as $key=>$label): ?><div style="grid-column:1/-1"><label><?= $label ?></label><textarea name="ann_<?= $key ?>"><?= htmlspecialchars((string)($editing['annexures_overrides'][$key] ?? ''), ENT_QUOTES) ?></textarea></div><?php endforeach; ?>
</div><br><button class="btn" type="submit">Save Quotation</button></form></div>
<div class="card"><h2>My Quote List</h2><table><thead><tr><th>Quote No</th><th>Name</th><th>Status</th><th>Amount</th><th>Updated</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($quotes as $q): ?><tr><td><?= htmlspecialchars((string)$q['quote_no'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$q['customer_name'], ENT_QUOTES) ?></td><td><?= htmlspecialchars(documents_status_label($q, 'employee'), ENT_QUOTES) ?></td><td>₹<?= number_format((float)$q['calc']['grand_total'],2) ?></td><td><?= htmlspecialchars((string)$q['updated_at'], ENT_QUOTES) ?></td><td><a class="btn secondary" href="quotation-view.php?id=<?= urlencode((string)$q['id']) ?>">View</a> <?php if (documents_quote_can_edit($q, 'employee', (string) ($employee['id'] ?? ''))): ?><a class="btn secondary" href="employee-quotations.php?edit=<?= urlencode((string)$q['id']) ?>">Edit</a><?php endif; ?></td></tr><?php endforeach; if ($quotes===[]): ?><tr><td colspan="6">No quotations yet.</td></tr><?php endif; ?></tbody></table></div>
<script>document.addEventListener('click',function(e){if(e.target&&e.target.id==='addItemBtn'){const tb=document.querySelector('#itemsTable tbody');if(!tb)return;const tr=document.createElement('tr');const dH='<?= htmlspecialchars((string)($quoteDefaults['defaults']['hsn_solar'] ?? '8541'), ENT_QUOTES) ?>';tr.innerHTML='<td></td><td><input name="item_name[]"></td><td><input name="item_description[]"></td><td><input name="item_hsn[]" value="'+dH+'"></td><td><input type="number" step="0.01" name="item_qty[]" value="1"></td><td><input name="item_unit[]" value="set"></td><td><button type="button" class="btn secondary rm-item">Remove</button></td>';tb.appendChild(tr);ren();}if(e.target&&e.target.classList.contains('rm-item')){e.target.closest('tr')?.remove();ren();}});function ren(){document.querySelectorAll('#itemsTable tbody tr').forEach((tr,i)=>{const td=tr.querySelector('td');if(td)td.textContent=String(i+1);});}ren();</script>
<script>window.quoteFormAutofillConfig={settingsBySegment:<?= json_encode($autofillSegments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,defaultEnergy:<?= json_encode((float)($quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450)) ?>};</script><script src="assets/js/quote-form-autofill.js"></script></main></body></html>
