<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/employee_admin.php';

require_login_any_role(['admin', 'employee']);

$user = current_user();
$isAdmin = (($user['role_name'] ?? '') === 'admin');
$isEmployee = (($user['role_name'] ?? '') === 'employee');

if (!$isAdmin && !$isEmployee) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}


$employeeDcAccessAllowed = false;
if ($isEmployee) {
    $employeeStore = new EmployeeFsStore();
    $employeeRecord = $employeeStore->findById((string) ($user['id'] ?? ''));
    $employeeDcAccessAllowed = !empty($employeeRecord['can_access_admin_created_dcs']);
}

$docTypes = ['quotation', 'proforma', 'agreement', 'challan', 'invoice_public', 'invoice_internal', 'receipt', 'sales_return'];
$segments = ['RES', 'COM', 'IND', 'INST', 'PROD'];

$companyPath = documents_company_profile_path();
$numberingPath = documents_settings_dir() . '/numbering_rules.json';
$templatePath = documents_templates_dir() . '/template_sets.json';

documents_ensure_structure();
documents_seed_template_sets_if_empty();

$redirectWith = static function (string $tab, string $type, string $msg): void {
    $query = http_build_query([
        'tab' => $tab,
        'status' => $type,
        'message' => $msg,
    ]);
    header('Location: admin-documents.php?' . $query);
    exit;
};

$redirectDocuments = static function (string $tab, string $type, string $msg, array $extra = []): void {
    $query = array_merge([
        'tab' => $tab,
        'status' => $type,
        'message' => $msg,
    ], $extra);
    header('Location: admin-documents.php?' . http_build_query($query));
    exit;
};


$redirectCsvImport = static function (string $type, string $message, array $report = []): void {
    $_SESSION['items_csv_import_report'] = $report;
    $query = http_build_query([
        'tab' => 'items',
        'items_subtab' => 'csv_import',
        'status' => $type,
        'message' => $message,
    ]);
    header('Location: admin-documents.php?' . $query);
    exit;
};

$generateInventoryEntityId = static function (string $prefix, array $rows): string {
    $existingIds = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        if ($id !== '') {
            $existingIds[$id] = true;
        }
    }

    do {
        $candidate = strtoupper($prefix) . '-' . date('Y') . '-' . bin2hex(random_bytes(2));
    } while (isset($existingIds[$candidate]));

    return $candidate;
};

$isArchivedRecord = static function (array $row): bool {
    return documents_is_archived($row);
};


$csvBoolean = static function (string $raw): ?bool {
    $value = strtolower(trim($raw));
    if ($value === '') {
        return null;
    }
    if (in_array($value, ['1', 'yes', 'y', 'true'], true)) {
        return true;
    }
    if (in_array($value, ['0', 'no', 'n', 'false'], true)) {
        return false;
    }
    return null;
};

$csvToTempRows = static function (array $file): array {
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Upload a valid CSV file.', 'rows' => []];
    }
    $handle = @fopen($tmp, 'rb');
    if (!$handle) {
        return ['ok' => false, 'error' => 'Unable to read uploaded CSV file.', 'rows' => []];
    }

    $rows = [];
    $rowNo = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $rowNo++;
        if (!is_array($row)) {
            continue;
        }
        $rows[] = ['row_no' => $rowNo, 'cells' => array_map(static fn($v): string => trim((string) $v), $row)];
    }
    fclose($handle);
    return ['ok' => true, 'rows' => $rows, 'error' => ''];
};

$csvParseAssoc = static function (array $file, array $requiredHeaders): array {
    $parsed = $csvToTempRows($file);
    if (!($parsed['ok'] ?? false)) {
        return ['ok' => false, 'error' => (string) ($parsed['error'] ?? 'CSV read failed.'), 'rows' => []];
    }

    $rows = (array) ($parsed['rows'] ?? []);
    if ($rows === []) {
        return ['ok' => false, 'error' => 'CSV file is empty.', 'rows' => []];
    }

    $headerRow = (array) ($rows[0]['cells'] ?? []);
    $headers = array_map(static fn($h): string => trim((string) $h), $headerRow);
    if ($headers !== $requiredHeaders) {
        return ['ok' => false, 'error' => 'CSV header mismatch. Required: ' . implode(', ', $requiredHeaders), 'rows' => []];
    }

    $assocRows = [];
    foreach (array_slice($rows, 1) as $rowData) {
        $cells = (array) ($rowData['cells'] ?? []);
        if ($cells === [] || count(array_filter($cells, static fn($v): bool => trim((string) $v) !== '')) === 0) {
            continue;
        }
        $assoc = [];
        foreach ($headers as $ix => $header) {
            $assoc[$header] = trim((string) ($cells[$ix] ?? ''));
        }
        $assocRows[] = ['row_no' => (int) ($rowData['row_no'] ?? 0), 'data' => $assoc];
    }

    return ['ok' => true, 'rows' => $assocRows, 'error' => ''];
};

$inr = static function (float $amount): string {
    $negative = $amount < 0;
    $amount = abs($amount);
    $parts = explode('.', number_format($amount, 2, '.', ''));
    $int = $parts[0];
    $decimal = $parts[1] ?? '00';
    $last3 = substr($int, -3);
    $rest = substr($int, 0, -3);
    if ($rest !== '') {
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) ?? $rest;
        $int = $rest . ',' . $last3;
    }
    return ($negative ? '-₹' : '₹') . $int . '.' . $decimal;
};

$documentTypeMap = [
    'agreement' => 'agreement',
    'receipt' => 'receipt',
    'delivery_challan' => 'delivery_challan',
    'proforma' => 'proforma',
    'invoice' => 'invoice',
];

$documentTypeLabel = [
    'quotation' => 'Quotation',
    'agreement' => 'Agreement',
    'receipt' => 'Receipt',
    'delivery_challan' => 'Delivery Challan',
    'proforma' => 'Proforma Invoice',
    'invoice' => 'Invoice',
];

$archiveByAdmin = static function (array $record, array $viewer): array {
    $record['archived_flag'] = true;
    $record['archived_at'] = date('c');
    $record['archived_by'] = [
        'type' => 'admin',
        'id' => (string) ($viewer['id'] ?? ''),
        'name' => (string) ($viewer['name'] ?? 'Admin'),
    ];
    return $record;
};

$unarchiveRecord = static function (array $record): array {
    $record['archived_flag'] = false;
    $record['archived_at'] = '';
    $record['archived_by'] = ['type' => '', 'id' => '', 'name' => ''];
    return $record;
};

$resolveAgreementTemplateId = static function (array $quote, array $templates): string {
    $activeTemplates = array_filter($templates, static fn($row): bool => is_array($row) && !documents_is_archived($row));
    if ($activeTemplates === []) {
        $activeTemplates = documents_agreement_template_defaults();
    }

    foreach ($activeTemplates as $row) {
        if (is_array($row) && !empty($row['is_default'])) {
            return (string) ($row['id'] ?? 'default_pm_surya_ghar_agreement');
        }
    }

    $schemeSignals = [
        (string) ($quote['project_type'] ?? ''),
        (string) ($quote['scheme_type'] ?? ''),
        (string) ($quote['customer_type'] ?? ''),
        (string) ($quote['project_summary_line'] ?? ''),
    ];
    $isPm = false;
    foreach ($schemeSignals as $signal) {
        if (str_contains(strtolower($signal), 'pm surya')) {
            $isPm = true;
            break;
        }
    }

    if ($isPm && isset($activeTemplates['default_pm_surya_ghar_agreement'])) {
        return 'default_pm_surya_ghar_agreement';
    }

    if (isset($activeTemplates['default_agreement'])) {
        return 'default_agreement';
    }

    if (isset($activeTemplates['default_pm_surya_ghar_agreement'])) {
        return 'default_pm_surya_ghar_agreement';
    }

    $first = reset($activeTemplates);
    return is_array($first) ? (string) ($first['id'] ?? 'default_pm_surya_ghar_agreement') : 'default_pm_surya_ghar_agreement';
};



$indianStates = ['Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Goa','Gujarat','Haryana','Himachal Pradesh','Jharkhand','Karnataka','Kerala','Madhya Pradesh','Maharashtra','Manipur','Meghalaya','Mizoram','Nagaland','Odisha','Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura','Uttar Pradesh','Uttarakhand','West Bengal','Andaman and Nicobar Islands','Chandigarh','Dadra and Nagar Haveli and Daman and Diu','Delhi','Jammu and Kashmir','Ladakh','Lakshadweep','Puducherry'];

$piPricingSnapshotFromQuote = static function (array $quote): array {
    $quote = documents_quote_prepare($quote);
    $calc = is_array($quote['calc'] ?? null) ? $quote['calc'] : [];
    $tb = is_array($quote['tax_breakdown'] ?? null) ? $quote['tax_breakdown'] : [];
    if ($tb === [] && is_array($calc['tax_breakdown'] ?? null)) {
        $tb = (array) $calc['tax_breakdown'];
    }
    return [
        'basic_total' => (float) ($tb['basic_total'] ?? $calc['basic_total'] ?? 0),
        'gst_breakup_slabs' => is_array($tb['slabs'] ?? null) ? array_values($tb['slabs']) : [],
        'system_gross_incl_gst' => (float) ($tb['gross_incl_gst'] ?? $calc['final_price_incl_gst'] ?? $calc['grand_total'] ?? 0),
        'transportation' => (float) ($calc['transportation_rs'] ?? 0),
        'discount' => (float) ($calc['discount_rs'] ?? 0),
        'gross_payable_after_discount' => (float) ($calc['gross_payable'] ?? $tb['gross_incl_gst'] ?? 0),
    ];
};

$buildPiItemsSnapshot = static function (array $quoteItems): array {
    $rows = [];
    $sr = 1;
    foreach (documents_normalize_quote_structured_items($quoteItems) as $item) {
        $rows[] = [
            'sr_no' => $sr++,
            'item_name' => safe_text((string) ($item['name_snapshot'] ?? 'Item')),
            'description' => safe_multiline_text((string) ($item['master_description_snapshot'] ?? $item['description_snapshot'] ?? '')),
            'hsn' => safe_text((string) ($item['hsn_snapshot'] ?? '')),
            'qty' => max(0, (float) ($item['qty'] ?? 0)),
            'unit' => safe_text((string) ($item['unit'] ?? 'Nos')),
        ];
    }
    return $rows;
};

$preparePiDocument = static function (?array $row, array $quote = []) use ($piPricingSnapshotFromQuote, $buildPiItemsSnapshot): array {
    $base = documents_sales_document_defaults('proforma');
    $doc = array_merge($base, is_array($row) ? $row : []);
    $doc['pi_id'] = safe_text((string) ($doc['pi_id'] ?? $doc['id'] ?? ''));
    $doc['id'] = $doc['pi_id'];
    $doc['pi_number'] = safe_text((string) ($doc['pi_number'] ?? $doc['proforma_no'] ?? ''));
    $doc['proforma_no'] = $doc['pi_number'];
    $doc['quote_id'] = safe_text((string) ($doc['quote_id'] ?? $doc['quotation_id'] ?? ''));
    $doc['quotation_id'] = $doc['quote_id'];
    $doc['customer_name_snapshot'] = safe_text((string) ($doc['customer_name_snapshot'] ?? $doc['customer_name'] ?? ''));
    $doc['site_address_snapshot'] = safe_multiline_text((string) ($doc['site_address_snapshot'] ?? ''));
    $doc['customer_gstin'] = strtoupper(safe_text((string) ($doc['customer_gstin'] ?? '')));
    $doc['place_of_supply_state'] = safe_text((string) ($doc['place_of_supply_state'] ?? ''));
    $doc['status'] = strtolower((string) ($doc['status'] ?? 'draft')) === 'final' ? 'final' : 'draft';
    $doc['issue_date'] = safe_text((string) ($doc['issue_date'] ?? $doc['pi_date'] ?? date('Y-m-d')));
    $doc['pi_date'] = $doc['issue_date'];
    $doc['items_snapshot'] = is_array($doc['items_snapshot'] ?? null) ? array_values($doc['items_snapshot']) : [];
    $doc['pricing_snapshot'] = is_array($doc['pricing_snapshot'] ?? null) ? $doc['pricing_snapshot'] : [];
    $doc['created_by'] = is_array($doc['created_by'] ?? null) ? $doc['created_by'] : ['type' => '', 'id' => '', 'name' => ''];
    $doc['finalized_by'] = is_array($doc['finalized_by'] ?? null) ? $doc['finalized_by'] : ['type' => '', 'id' => '', 'name' => ''];

    if ($quote !== []) {
        $quote = documents_quote_prepare($quote);
        $snapshot = documents_quote_resolve_snapshot($quote);
        if ($doc['quote_id'] === '') {
            $doc['quote_id'] = (string) ($quote['id'] ?? '');
            $doc['quotation_id'] = $doc['quote_id'];
        }
        if ($doc['customer_mobile'] === '') {
            $doc['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
        }
        if ($doc['customer_name_snapshot'] === '') {
            $doc['customer_name_snapshot'] = safe_text((string) ($snapshot['name'] ?? $quote['customer_name'] ?? ''));
            $doc['customer_name'] = $doc['customer_name_snapshot'];
        }
        if ($doc['site_address_snapshot'] === '') {
            $doc['site_address_snapshot'] = safe_multiline_text((string) ($quote['site_address'] ?? $snapshot['address'] ?? ''));
        }
        if ($doc['place_of_supply_state'] === '') {
            $doc['place_of_supply_state'] = safe_text((string) ($quote['state'] ?? 'Jharkhand')) ?: 'Jharkhand';
        }
        if ($doc['items_snapshot'] === []) {
            $doc['items_snapshot'] = $buildPiItemsSnapshot(is_array($quote['quote_items'] ?? null) ? $quote['quote_items'] : []);
        }
        if ($doc['pricing_snapshot'] === []) {
            $doc['pricing_snapshot'] = $piPricingSnapshotFromQuote($quote);
        }
    }

    return $doc;
};

if (($_GET['action'] ?? '') === 'view_pi_html') {
    $piId = safe_text((string) ($_GET['pi_id'] ?? ''));
    if ($piId === '') {
        http_response_code(400);
        echo 'PI not found.';
        exit;
    }
    $pi = $preparePiDocument(documents_get_sales_document('proforma', $piId));
    if ($pi['pi_id'] === '') {
        http_response_code(404);
        echo 'PI not found.';
        exit;
    }
    $quote = documents_get_quote((string) ($pi['quote_id'] ?? ''));
    $quote = is_array($quote) ? documents_quote_prepare($quote) : [];
    $company = load_company_profile();
    $items = is_array($pi['items_snapshot'] ?? null) ? $pi['items_snapshot'] : [];
    $pricing = is_array($pi['pricing_snapshot'] ?? null) ? $pi['pricing_snapshot'] : [];
    ?>
<!doctype html><html><head><meta charset="utf-8"><title>Proforma Invoice <?= htmlspecialchars((string) ($pi['pi_number'] ?? ''), ENT_QUOTES) ?></title>
<style>body{font-family:Arial,sans-serif;color:#111;margin:0;padding:20px} .sheet{max-width:900px;margin:0 auto} table{width:100%;border-collapse:collapse} th,td{border:1px solid #d1d5db;padding:8px;vertical-align:top} th{background:#f3f4f6;text-align:left}.head{display:flex;justify-content:space-between;gap:12px;margin-bottom:16px}.muted{color:#6b7280}.totals{margin-top:12px;margin-left:auto;max-width:420px}.totals td:first-child{font-weight:600}.no-border td{border:none;padding:2px 0}.logo{max-height:70px}@media print{.no-print{display:none!important}body{padding:0}.sheet{max-width:none}}</style>
</head><body><div class="sheet">
<div class="head"><div><?php if ((string)($company['logo_path'] ?? '')!==''): ?><img class="logo" src="<?= htmlspecialchars((string)$company['logo_path'], ENT_QUOTES) ?>" alt="logo"><?php endif; ?><h2 style="margin:6px 0 2px"><?= htmlspecialchars((string) ($company['company_name'] ?? 'Company'), ENT_QUOTES) ?></h2><div class="muted"><?= nl2br(htmlspecialchars((string) ($company['address'] ?? ''), ENT_QUOTES)) ?></div><div><?= htmlspecialchars((string) ($company['phone'] ?? ''), ENT_QUOTES) ?></div></div><div style="text-align:right"><h1 style="margin:0">PROFORMA INVOICE</h1><div><strong>No:</strong> <?= htmlspecialchars((string) ($pi['pi_number'] ?? ''), ENT_QUOTES) ?></div><div><strong>Date:</strong> <?= htmlspecialchars((string) ($pi['issue_date'] ?? ''), ENT_QUOTES) ?></div><div><strong>Status:</strong> <?= htmlspecialchars(strtoupper((string) ($pi['status'] ?? 'draft')), ENT_QUOTES) ?></div></div></div>
<table class="no-border"><tr><td style="width:50%"><strong>Customer:</strong> <?= htmlspecialchars((string) ($pi['customer_name_snapshot'] ?? ''), ENT_QUOTES) ?><br><strong>Mobile:</strong> <?= htmlspecialchars((string) ($pi['customer_mobile'] ?? ''), ENT_QUOTES) ?><br><strong>Address:</strong><br><?= nl2br(htmlspecialchars((string) ($pi['site_address_snapshot'] ?? ''), ENT_QUOTES)) ?></td><td><strong>Place of Supply:</strong> <?= htmlspecialchars((string) ($pi['place_of_supply_state'] ?? ''), ENT_QUOTES) ?><br><strong>Customer GSTIN:</strong> <?= htmlspecialchars((string) ($pi['customer_gstin'] ?? ''), ENT_QUOTES) ?></td></tr></table>
<table><thead><tr><th style="width:60px">Sr</th><th>Item + Description</th><th style="width:110px">HSN</th><th style="width:90px">Qty</th><th style="width:90px">Unit</th></tr></thead><tbody><?php foreach($items as $item): ?><tr><td><?= (int) ($item['sr_no'] ?? 0) ?></td><td><strong><?= htmlspecialchars((string) ($item['item_name'] ?? ''), ENT_QUOTES) ?></strong><br><?= nl2br(htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES)) ?></td><td><?= htmlspecialchars((string) ($item['hsn'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($item['qty'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES) ?></td></tr><?php endforeach; ?></tbody></table>
<table class="totals"><tbody><tr><td>Basic Total</td><td style="text-align:right"><?= htmlspecialchars($inr((float) ($pricing['basic_total'] ?? 0)), ENT_QUOTES) ?></td></tr><?php foreach ((array)($pricing['gst_breakup_slabs'] ?? []) as $slab): ?><tr><td>GST <?= htmlspecialchars((string) ($slab['label'] ?? (($slab['rate'] ?? '').'%')), ENT_QUOTES) ?></td><td style="text-align:right"><?= htmlspecialchars($inr((float) ($slab['gst'] ?? 0)), ENT_QUOTES) ?></td></tr><?php endforeach; ?><tr><td>System Gross (incl GST)</td><td style="text-align:right"><?= htmlspecialchars($inr((float) ($pricing['system_gross_incl_gst'] ?? 0)), ENT_QUOTES) ?></td></tr><tr><td>Transportation</td><td style="text-align:right"><?= htmlspecialchars($inr((float) ($pricing['transportation'] ?? 0)), ENT_QUOTES) ?></td></tr><tr><td>Discount</td><td style="text-align:right">-<?= htmlspecialchars($inr((float) ($pricing['discount'] ?? 0)), ENT_QUOTES) ?></td></tr><tr><td><strong>Total Payable</strong></td><td style="text-align:right"><strong><?= htmlspecialchars($inr((float) ($pricing['gross_payable_after_discount'] ?? 0)), ENT_QUOTES) ?></strong></td></tr></tbody></table>
<?php if ((string)($company['bank_name'] ?? '') !== '' || (string)($company['bank_account_no'] ?? '') !== ''): ?><h3>Bank Details</h3><div><strong>Bank:</strong> <?= htmlspecialchars((string) ($company['bank_name'] ?? ''), ENT_QUOTES) ?>, <strong>A/C:</strong> <?= htmlspecialchars((string) ($company['bank_account_no'] ?? ''), ENT_QUOTES) ?>, <strong>IFSC:</strong> <?= htmlspecialchars((string) ($company['ifsc_code'] ?? ''), ENT_QUOTES) ?></div><?php endif; ?>
<?php if ((string) ($pi['notes'] ?? '') !== ''): ?><p><strong>Notes:</strong><br><?= nl2br(htmlspecialchars((string) ($pi['notes'] ?? ''), ENT_QUOTES)) ?></p><?php endif; ?>
<p class="muted" style="margin-top:24px">Generated by <?= htmlspecialchars((string) ($company['company_name'] ?? 'Company'), ENT_QUOTES) ?>. This is a computer generated proforma invoice.</p>
<div class="no-print" style="margin-top:10px"><button onclick="window.print()">Print</button></div>
</div></body></html>
<?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirectWith('company', 'error', 'Security validation failed. Please retry.');
    }

    $action = safe_text($_POST['action'] ?? '');

    $employeeAllowedActions = ['create_inventory_tx', 'edit_inventory_tx', 'save_inventory_edits', 'import_inventory_stock_in_csv', 'create_receipt', 'save_receipt_draft', 'finalize_receipt', 'create_agreement', 'create_delivery_challan', 'create_pi', 'create_invoice', 'save_pi_draft'];
    if (!$isAdmin && !in_array($action, $employeeAllowedActions, true)) {
        $redirectDocuments('items', 'error', 'Access denied.', ['items_subtab' => 'inventory']);
    }

    if ($action === 'save_company_profile') {
        $profile = load_company_profile();

        $fields = array_keys(documents_company_profile_defaults());
        foreach ($fields as $field) {
            if ($field === 'logo_path' || $field === 'updated_at') {
                continue;
            }
            $profile[$field] = safe_text($_POST[$field] ?? '');
        }

        if (isset($_FILES['company_logo_upload']) && (int) ($_FILES['company_logo_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = documents_handle_image_upload($_FILES['company_logo_upload'], documents_public_branding_dir(), 'logo_');
            if (!$upload['ok']) {
                $redirectWith('company', 'error', (string) $upload['error']);
            }
            $profile['logo_path'] = '/images/documents/branding/' . $upload['filename'];
        }

        $waDigits = preg_replace('/\D+/', '', (string) ($profile['whatsapp_number'] ?? '')) ?? '';
        if ($waDigits !== '' && (strlen($waDigits) < 10 || strlen($waDigits) > 12)) {
            $redirectWith('company', 'error', 'WhatsApp number must contain 10 to 12 digits.');
        }

        $pan = strtoupper((string) ($profile['pan'] ?? ''));
        $panWarning = ($pan !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan));

        $saved = save_company_profile($profile);
        if (!$saved['ok']) {
            $redirectWith('company', 'error', 'Unable to save company profile.');
        }

        $msg = $panWarning
            ? 'Company profile saved. Warning: PAN format looks unusual (expected ABCDE1234F).'
            : 'Company profile saved successfully.';
        $redirectWith('company', 'success', $msg);
    }

    if (in_array($action, ['create_agreement', 'create_receipt', 'create_delivery_challan', 'create_pi', 'create_invoice'], true)) {
        $tab = safe_text($_POST['return_tab'] ?? 'accepted_customers');
        $view = safe_text($_POST['quotation_id'] ?? safe_text($_POST['return_view'] ?? ''));
        if ($view === '') {
            $redirectDocuments($tab, 'error', 'Quotation is required.');
        }

        $quote = documents_get_quote($view);
        if ($quote === null) {
            $redirectDocuments($tab, 'error', 'Quotation not found.', ['view' => $view]);
        }
        $quote = documents_quote_prepare($quote);
        $snapshot = documents_quote_resolve_snapshot($quote);
        $companyProfile = load_company_profile();
        $viewer = [
            'role' => $isAdmin ? 'admin' : 'employee',
            'id' => (string) ($user['id'] ?? ''),
            'name' => (string) ($user['full_name'] ?? ($isAdmin ? 'Admin' : 'Employee')),
        ];

        if ($action === 'create_agreement') {
            $existingAgreementId = safe_text((string) ($quote['workflow']['agreement_id'] ?? ''));
            if ($existingAgreementId !== '') {
                $existingAgreement = documents_get_sales_document('agreement', $existingAgreementId);
                if ($existingAgreement !== null && !documents_is_archived($existingAgreement)) {
                    header('Location: agreement-view.php?id=' . urlencode($existingAgreementId) . '&mode=edit&status=success&message=' . urlencode('Agreement already exists.'));
                    exit;
                }
            }

            $number = documents_generate_agreement_number(safe_text((string) ($quote['segment'] ?? 'RES')) ?: 'RES');
            if (!$number['ok']) {
                $redirectDocuments($tab, 'error', (string) ($number['error'] ?? 'Unable to generate agreement number.'), ['view' => $view]);
            }

            $templates = documents_get_agreement_templates();
            $templateId = $resolveAgreementTemplateId($quote, $templates);
            $templateRow = $templates[$templateId] ?? null;
            if (!is_array($templateRow)) {
                $defaults = documents_agreement_template_defaults();
                $templateRow = $defaults['default_pm_surya_ghar_agreement'] ?? [];
                $templateId = (string) ($templateRow['id'] ?? 'default_pm_surya_ghar_agreement');
            }

            $agreement = documents_agreement_defaults();
            $agreement['id'] = 'agr_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
            $agreement['agreement_no'] = (string) ($number['agreement_no'] ?? '');
            $agreement['status'] = 'Draft';
            $agreement['template_id'] = $templateId;
            $agreement['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
            $agreement['customer_name'] = safe_text((string) ($snapshot['name'] ?? $quote['customer_name'] ?? ''));
            $agreement['consumer_account_no'] = safe_text((string) ($quote['consumer_account_no'] ?? $snapshot['consumer_account_no'] ?? ''));
            $agreement['consumer_address'] = safe_text((string) ($snapshot['address'] ?? ''));
            $agreement['site_address'] = safe_text((string) ($quote['site_address'] ?? $snapshot['address'] ?? ''));
            $agreement['execution_date'] = date('Y-m-d');
            $agreement['system_capacity_kwp'] = safe_text((string) ($quote['capacity_kwp'] ?? ''));
            $agreement['total_cost'] = documents_format_money_indian((float) ($quote['calc']['gross_payable'] ?? $quote['input_total_gst_inclusive'] ?? 0));
            $agreement['linked_quote_id'] = (string) ($quote['id'] ?? '');
            $agreement['linked_quote_no'] = (string) ($quote['quote_no'] ?? '');
            $agreement['district'] = safe_text((string) ($quote['district'] ?? $snapshot['district'] ?? ''));
            $agreement['city'] = safe_text((string) ($quote['city'] ?? $snapshot['city'] ?? ''));
            $agreement['state'] = safe_text((string) ($quote['state'] ?? $snapshot['state'] ?? ''));
            $agreement['pin_code'] = safe_text((string) ($quote['pin'] ?? $snapshot['pin_code'] ?? ''));
            $agreement['created_by_type'] = (string) ($viewer['role'] ?? 'admin');
            $agreement['created_by_id'] = $viewer['id'];
            $agreement['created_by_name'] = $viewer['name'];
            $agreement['created_at'] = date('c');
            $agreement['updated_at'] = date('c');

            $savedAgreement = documents_save_agreement($agreement);
            if (!$savedAgreement['ok']) {
                $redirectDocuments($tab, 'error', 'Failed to create agreement draft.', ['view' => $view]);
            }
            documents_append_document_action_log($viewer, 'create_draft', 'agreement', (string) ($agreement['id'] ?? ''), $view, 'Agreement created from accepted customer pack.');

            $renderedAgreementHtml = documents_render_agreement_body_html($agreement, $companyProfile);

            $salesAgreement = documents_sales_document_defaults('agreement');
            $salesAgreement['id'] = (string) $agreement['id'];
            $salesAgreement['quotation_id'] = (string) ($quote['id'] ?? '');
            $salesAgreement['customer_mobile'] = (string) $agreement['customer_mobile'];
            $salesAgreement['customer_name'] = (string) $agreement['customer_name'];
            $salesAgreement['execution_date'] = (string) $agreement['execution_date'];
            $salesAgreement['agreement_no'] = (string) $agreement['agreement_no'];
            $salesAgreement['template_id'] = $templateId;
            $salesAgreement['template_name'] = (string) ($templateRow['name'] ?? 'Agreement Template');
            $salesAgreement['html_rendered'] = $renderedAgreementHtml;
            $salesAgreement['status'] = 'draft';
            $salesAgreement['created_by'] = $viewer;
            $salesAgreement['created_at'] = (string) $agreement['created_at'];
            $savedSalesAgreement = documents_save_sales_document('agreement', $salesAgreement);
            if (!$savedSalesAgreement['ok']) {
                $redirectDocuments($tab, 'error', 'Failed to update agreement workflow.', ['view' => $view]);
            }

            documents_quote_link_workflow_doc($quote, 'agreement', (string) $agreement['id']);
            $quote['updated_at'] = date('c');
            $savedQuote = documents_save_quote($quote);
            if (!$savedQuote['ok']) {
                $redirectDocuments($tab, 'error', 'Agreement created, but quotation workflow update failed.', ['view' => $view]);
            }

            header('Location: agreement-view.php?id=' . urlencode((string) $agreement['id']) . '&mode=edit&status=success&message=' . urlencode('Agreement created from default template.'));
            exit;
        }

        if ($action === 'create_receipt') {
            $receipt = documents_sales_document_defaults('receipt');
            $receipt['id'] = documents_generate_simple_document_id('RCPT');
            $receipt['receipt_id'] = (string) $receipt['id'];
            $receipt['quotation_id'] = (string) ($quote['id'] ?? '');
            $receipt['quote_id'] = (string) ($quote['id'] ?? '');
            $receipt['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
            $receipt['customer_name'] = safe_text((string) ($snapshot['name'] ?? $quote['customer_name'] ?? ''));
            $receipt['customer_name_snapshot'] = (string) $receipt['customer_name'];
            $receipt['site_address_snapshot'] = safe_text((string) ($quote['site_address'] ?? $snapshot['address'] ?? ''));
            $receipt['receipt_number'] = 'PR-' . date('Ymd') . '-' . strtoupper(substr((string) $receipt['id'], -4));
            $receipt['date_received'] = date('Y-m-d');
            $receipt['receipt_date'] = (string) $receipt['date_received'];
            $receipt['amount_rs'] = 0;
            $receipt['amount_received'] = 0;
            $receipt['mode'] = 'Cash';
            $receipt['txn_ref'] = '';
            $receipt['reference'] = '';
            $receipt['notes'] = '';
            $receipt['status'] = 'draft';
            $receipt['created_by'] = ['role' => (string) ($viewer['role'] ?? ''), 'id' => (string) ($viewer['id'] ?? ''), 'name' => (string) ($viewer['name'] ?? '')];
            $receipt['created_at'] = date('c');
            $receipt['updated_at'] = (string) $receipt['created_at'];
            $receipt['archived_flag'] = false;
            $receipt['archived_at'] = '';
            $receipt['archived_by'] = ['role' => '', 'id' => '', 'name' => ''];

            $saved = documents_save_sales_document('receipt', $receipt);
            if (!$saved['ok']) {
                $redirectDocuments($tab, 'error', 'Unable to create receipt draft.', ['view' => $view]);
            }
            documents_append_document_action_log($viewer, 'create_draft', 'receipt', (string) ($receipt['id'] ?? ''), $view, 'Receipt draft created from accepted customer pack.');
            documents_quote_link_workflow_doc($quote, 'receipt', (string) $receipt['id']);
            $quote['updated_at'] = date('c');
            documents_save_quote($quote);
            $redirectDocuments($tab, 'success', 'Receipt draft created.', ['view' => $view, 'action' => 'edit_receipt', 'receipt_id' => (string) $receipt['id']]);
        }

        if ($action === 'create_delivery_challan') {
            $challan = documents_challan_defaults();
            $number = documents_generate_challan_number(safe_text((string) ($quote['segment'] ?? 'RES')) ?: 'RES');
            if (!$number['ok']) {
                $redirectDocuments($tab, 'error', (string) ($number['error'] ?? 'Unable to create challan number.'), ['view' => $view]);
            }
            $challan['id'] = 'dc_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
            $challan['challan_no'] = (string) ($number['challan_no'] ?? '');
            $challan['dc_id'] = (string) $challan['id'];
            $challan['dc_number'] = (string) $challan['challan_no'];
            $challan['status'] = 'draft';
            $challan['linked_quote_id'] = (string) ($quote['id'] ?? '');
            $challan['linked_quote_no'] = (string) ($quote['quote_no'] ?? '');
            $challan['quote_id'] = (string) ($quote['id'] ?? '');
            $challan['segment'] = safe_text((string) ($quote['segment'] ?? 'RES')) ?: 'RES';
            $challan['customer_snapshot'] = $snapshot;
            $challan['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
            $challan['customer_name_snapshot'] = safe_text((string) ($snapshot['name'] ?? $quote['customer_name'] ?? ''));
            $challan['site_address'] = safe_text((string) ($quote['site_address'] ?? $snapshot['address'] ?? ''));
            $challan['site_address_snapshot'] = $challan['site_address'];
            $challan['delivery_address'] = $challan['site_address'];
            $challan['delivery_date'] = date('Y-m-d');
            $challan['items'] = [];
            $challan['lines'] = [];
            $challan['inventory_txn_ids'] = [];
            $packForQuote = documents_get_packing_list_for_quote((string) ($quote['id'] ?? ''), false);
            if ($packForQuote !== null) {
                $challan['packing_list_id'] = (string) ($packForQuote['id'] ?? '');
            }
            $challan['created_by_type'] = (string) ($viewer['role'] ?? 'admin');
            $challan['created_by_id'] = $viewer['id'];
            $challan['created_by_name'] = $viewer['name'];
            $challan['created_by'] = ['role' => (string) ($viewer['role'] ?? ''), 'id' => (string) $viewer['id'], 'name' => (string) $viewer['name']];
            $challan['created_at'] = date('c');
            $challan['updated_at'] = date('c');

            $savedChallan = documents_save_challan($challan);
            if (!$savedChallan['ok']) {
                $redirectDocuments($tab, 'error', 'Unable to create delivery challan draft.', ['view' => $view]);
            }

            $salesChallan = documents_sales_document_defaults('delivery_challan');
            $salesChallan['id'] = (string) $challan['id'];
            $salesChallan['quotation_id'] = (string) ($quote['id'] ?? '');
            $salesChallan['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
            $salesChallan['customer_name'] = safe_text((string) ($snapshot['name'] ?? $quote['customer_name'] ?? ''));
            $salesChallan['challan_no'] = (string) $challan['challan_no'];
            $salesChallan['challan_date'] = (string) $challan['delivery_date'];
            $salesChallan['items'] = [];
            $salesChallan['status'] = 'draft';
            $salesChallan['created_by'] = $viewer;
            $salesChallan['created_at'] = (string) $challan['created_at'];
            $savedSales = documents_save_sales_document('delivery_challan', $salesChallan);
            if (!$savedSales['ok']) {
                $redirectDocuments($tab, 'error', 'Delivery challan created, but pack workflow update failed.', ['view' => $view]);
            }
            documents_append_document_action_log($viewer, 'create_draft', 'dc', (string) ($challan['id'] ?? ''), $view, 'Delivery challan draft created from accepted customer pack.');

            documents_quote_link_workflow_doc($quote, 'delivery_challan', (string) $challan['id']);
            $quote['updated_at'] = date('c');
            documents_save_quote($quote);
            header('Location: challan-view.php?id=' . urlencode((string) $challan['id']) . '&status=success&message=' . urlencode('Delivery challan draft created.'));
            exit;
        }

        if ($action === 'create_pi') {
            $existingId = safe_text((string) ($quote['workflow']['proforma_invoice_id'] ?? ''));
            if ($existingId !== '') {
                $existing = $preparePiDocument(documents_get_sales_document('proforma', $existingId), $quote);
                if ($existing['pi_id'] !== '' && !documents_is_archived($existing)) {
                    $redirectDocuments($tab, 'success', 'PI already exists.', ['view' => $view, 'pi_id' => $existingId, 'pack_action' => 'edit_pi']);
                }
            }

            $segment = safe_text((string) ($quote['segment'] ?? 'RES')) ?: 'RES';
            $number = documents_generate_proforma_number($segment);
            if (!($number['ok'] ?? false)) {
                $redirectDocuments($tab, 'error', (string) ($number['error'] ?? 'Unable to generate PI number.'), ['view' => $view]);
            }
            $piId = 'pi_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
            $salesPi = $preparePiDocument([], $quote);
            $salesPi['pi_id'] = $piId;
            $salesPi['id'] = $piId;
            $salesPi['pi_number'] = (string) ($number['proforma_no'] ?? '');
            $salesPi['proforma_no'] = $salesPi['pi_number'];
            $salesPi['quote_id'] = (string) ($quote['id'] ?? '');
            $salesPi['quotation_id'] = $salesPi['quote_id'];
            $salesPi['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
            $salesPi['customer_name_snapshot'] = safe_text((string) ($snapshot['name'] ?? $quote['customer_name'] ?? ''));
            $salesPi['customer_name'] = $salesPi['customer_name_snapshot'];
            $salesPi['site_address_snapshot'] = safe_multiline_text((string) ($quote['site_address'] ?? $snapshot['address'] ?? ''));
            $salesPi['place_of_supply_state'] = safe_text((string) ($quote['state'] ?? 'Jharkhand')) ?: 'Jharkhand';
            $salesPi['status'] = 'draft';
            $salesPi['issue_date'] = date('Y-m-d');
            $salesPi['pi_date'] = $salesPi['issue_date'];
            $salesPi['notes'] = safe_multiline_text((string) ($salesPi['notes'] ?? ''));
            $salesPi['created_by'] = ['type' => (string) ($viewer['role'] ?? ''), 'id' => (string) ($viewer['id'] ?? ''), 'name' => (string) ($viewer['name'] ?? '')];
            $salesPi['created_at'] = date('c');
            $salesPi['updated_at'] = date('c');
            $savedSalesPi = documents_save_sales_document('proforma', $salesPi);
            if (!$savedSalesPi['ok']) {
                $redirectDocuments($tab, 'error', 'Unable to create PI draft.', ['view' => $view]);
            }
            documents_append_document_action_log($viewer, 'create_draft', 'pi', $piId, $view, 'Proforma draft created from accepted customer pack.');

            documents_quote_link_workflow_doc($quote, 'proforma', $piId);
            $quote['updated_at'] = date('c');
            documents_save_quote($quote);
            $redirectDocuments($tab, 'success', 'PI draft created.', ['view' => $view, 'pi_id' => $piId, 'pack_action' => 'edit_pi']);
        }

        if ($action === 'create_invoice') {
            $existingId = safe_text((string) ($quote['workflow']['invoice_id'] ?? ''));
            if ($existingId !== '') {
                $existing = documents_get_sales_document('invoice', $existingId);
                if ($existing !== null && !documents_is_archived($existing)) {
                    header('Location: admin-invoices.php?id=' . urlencode($existingId));
                    exit;
                }
            }

            $created = documents_create_invoice_from_quote($quote);
            if (!($created['ok'] ?? false)) {
                $redirectDocuments($tab, 'error', (string) ($created['error'] ?? 'Unable to create invoice.'), ['view' => $view]);
            }
            $invoiceId = (string) ($created['invoice_id'] ?? '');
            $invoiceDoc = $invoiceId !== '' ? documents_get_invoice($invoiceId) : null;
            if ($invoiceDoc === null || $invoiceId === '') {
                $redirectDocuments($tab, 'error', 'Invoice created but could not be loaded.', ['view' => $view]);
            }

            $salesInvoice = documents_sales_document_defaults('invoice');
            $salesInvoice['id'] = $invoiceId;
            $salesInvoice['quotation_id'] = (string) ($quote['id'] ?? '');
            $salesInvoice['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
            $salesInvoice['customer_name'] = safe_text((string) ($snapshot['name'] ?? $quote['customer_name'] ?? ''));
            $salesInvoice['invoice_no'] = (string) ($invoiceDoc['invoice_no'] ?? '');
            $salesInvoice['invoice_date'] = date('Y-m-d');
            $salesInvoice['amount'] = (float) ($invoiceDoc['input_total_gst_inclusive'] ?? 0);
            $salesInvoice['tax_profile_id'] = (string) ($quote['tax_profile_id'] ?? '');
            $salesInvoice['tax_breakdown'] = is_array($quote['tax_breakdown'] ?? null) ? $quote['tax_breakdown'] : (array) ($quote['calc']['tax_breakdown'] ?? []);
            $salesInvoice['status'] = 'draft';
            $salesInvoice['created_by'] = $viewer;
            $salesInvoice['created_at'] = (string) ($invoiceDoc['created_at'] ?? date('c'));
            $savedSalesInvoice = documents_save_sales_document('invoice', $salesInvoice);
            if (!$savedSalesInvoice['ok']) {
                $redirectDocuments($tab, 'error', 'Invoice created, but workflow update failed.', ['view' => $view]);
            }
            documents_append_document_action_log($viewer, 'create_draft', 'invoice', $invoiceId, $view, 'Invoice draft created from accepted customer pack.');

            documents_quote_link_workflow_doc($quote, 'invoice', $invoiceId);
            $quote['updated_at'] = date('c');
            documents_save_quote($quote);
            header('Location: admin-invoices.php?id=' . urlencode($invoiceId));
            exit;
        }
    }

    if ($action === 'save_numbering_rule') {
        $payload = json_load($numberingPath, documents_numbering_defaults());
        $payload = array_merge(documents_numbering_defaults(), is_array($payload) ? $payload : []);
        $payload['rules'] = is_array($payload['rules']) ? $payload['rules'] : [];

        $docType = safe_text($_POST['doc_type'] ?? '');
        $segment = safe_text($_POST['segment'] ?? '');
        if (!in_array($docType, $docTypes, true) || !in_array($segment, $segments, true)) {
            $redirectWith('numbering', 'error', 'Invalid document type or segment.');
        }

        $seqDigits = max(2, min(6, (int) ($_POST['seq_digits'] ?? 4)));
        $seqStart = max(1, (int) ($_POST['seq_start'] ?? 1));
        $seqCurrent = max(1, (int) ($_POST['seq_current'] ?? $seqStart));

        $payload['rules'][] = [
            'id' => safe_slug($docType . '-' . $segment . '-' . bin2hex(random_bytes(3))),
            'doc_type' => $docType,
            'segment' => $segment,
            'prefix' => safe_text($_POST['prefix'] ?? ''),
            'format' => safe_text($_POST['format'] ?? '{{prefix}}/{{segment}}/{{fy}}/{{seq}}'),
            'seq_digits' => $seqDigits,
            'seq_start' => $seqStart,
            'seq_current' => $seqCurrent,
            'active' => isset($_POST['active']),
            'archived_flag' => false,
        ];
        $payload['updated_at'] = date('c');

        $saved = json_save($numberingPath, $payload);
        if (!$saved['ok']) {
            $redirectWith('numbering', 'error', 'Unable to save numbering rule.');
        }

        $redirectWith('numbering', 'success', 'Numbering rule added.');
    }

    if ($action === 'update_numbering_rule' || $action === 'archive_numbering_rule' || $action === 'reset_counter') {
        $payload = json_load($numberingPath, documents_numbering_defaults());
        $payload = array_merge(documents_numbering_defaults(), is_array($payload) ? $payload : []);
        $payload['rules'] = is_array($payload['rules']) ? $payload['rules'] : [];

        $ruleId = safe_text($_POST['rule_id'] ?? '');
        $found = false;
        foreach ($payload['rules'] as &$rule) {
            if ((string) ($rule['id'] ?? '') !== $ruleId) {
                continue;
            }
            $found = true;

            if ($action === 'archive_numbering_rule') {
                $rule['archived_flag'] = true;
                $rule['active'] = false;
                break;
            }

            if ($action === 'reset_counter') {
                $rule['seq_current'] = (int) ($rule['seq_start'] ?? 1);
                break;
            }

            $docType = safe_text($_POST['doc_type'] ?? '');
            $segment = safe_text($_POST['segment'] ?? '');
            if (!in_array($docType, $docTypes, true) || !in_array($segment, $segments, true)) {
                unset($rule);
                $redirectWith('numbering', 'error', 'Invalid rule update values.');
            }

            $rule['doc_type'] = $docType;
            $rule['segment'] = $segment;
            $rule['prefix'] = safe_text($_POST['prefix'] ?? '');
            $rule['format'] = safe_text($_POST['format'] ?? '{{prefix}}/{{segment}}/{{fy}}/{{seq}}');
            $rule['seq_digits'] = max(2, min(6, (int) ($_POST['seq_digits'] ?? 4)));
            $rule['seq_start'] = max(1, (int) ($_POST['seq_start'] ?? 1));
            $rule['seq_current'] = max(1, (int) ($_POST['seq_current'] ?? 1));
            $rule['active'] = isset($_POST['active']);
        }
        unset($rule);

        if (!$found) {
            $redirectWith('numbering', 'error', 'Rule not found.');
        }

        $payload['updated_at'] = date('c');
        $saved = json_save($numberingPath, $payload);
        if (!$saved['ok']) {
            $redirectWith('numbering', 'error', 'Unable to save numbering updates.');
        }

        $msg = 'Numbering rule updated.';
        if ($action === 'archive_numbering_rule') {
            $msg = 'Numbering rule archived.';
        } elseif ($action === 'reset_counter') {
            $msg = 'Counter reset to start value.';
        }
        $redirectWith('numbering', 'success', $msg);
    }

    if ($action === 'save_template_set' || $action === 'archive_template_set' || $action === 'unarchive_template_set') {
        $rows = json_load($templatePath, []);
        $rows = is_array($rows) ? $rows : [];

        if ($action === 'save_template_set') {
            $templateId = safe_text($_POST['template_id'] ?? '');
            $name = safe_text($_POST['name'] ?? '');
            $segment = safe_text($_POST['segment'] ?? 'RES');
            $notes = safe_text($_POST['notes'] ?? '');
            $opacity = (float) ($_POST['page_background_opacity'] ?? 1);
            $opacity = max(0.1, min(1.0, $opacity));

            if ($name === '' || !in_array($segment, $segments, true)) {
                $redirectWith('templates', 'error', 'Template name and segment are required.');
            }

            $newBgPath = '';
            if (isset($_FILES['template_background_upload']) && (int) ($_FILES['template_background_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $upload = documents_handle_image_upload($_FILES['template_background_upload'], documents_public_backgrounds_dir(), 'background_');
                if (!$upload['ok']) {
                    $redirectWith('templates', 'error', (string) $upload['error']);
                }
                $newBgPath = '/images/documents/backgrounds/' . $upload['filename'];
            }

            $matched = false;
            foreach ($rows as &$row) {
                if ((string) ($row['id'] ?? '') !== $templateId || $templateId === '') {
                    continue;
                }
                $matched = true;
                $row['name'] = $name;
                $row['segment'] = $segment;
                $row['notes'] = $notes;
                $theme = is_array($row['default_doc_theme'] ?? null) ? $row['default_doc_theme'] : [];
                if ($newBgPath !== '') {
                    $theme['page_background_image'] = $newBgPath;
                }
                $theme['page_background_opacity'] = $opacity;
                $row['default_doc_theme'] = $theme;
                $row['updated_at'] = date('c');
            }
            unset($row);

            if (!$matched) {
                $idBase = safe_slug($name);
                if ($idBase === '') {
                    $idBase = 'template-' . time();
                }
                $id = $idBase;
                $counter = 1;
                $existingIds = array_map(static fn(array $r): string => (string) ($r['id'] ?? ''), $rows);
                while (in_array($id, $existingIds, true)) {
                    $id = $idBase . '-' . $counter;
                    $counter++;
                }

                $now = date('c');
                $rows[] = [
                    'id' => $id,
                    'name' => $name,
                    'segment' => $segment,
                    'default_doc_theme' => [
                        'page_background_image' => $newBgPath,
                        'page_background_opacity' => $opacity,
                    ],
                    'notes' => $notes,
                    'archived_flag' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $saved = json_save($templatePath, $rows);
            if (!$saved['ok']) {
                $redirectWith('templates', 'error', 'Unable to save template set.');
            }

            $redirectWith('templates', 'success', 'Template set saved successfully.');
        }

        $templateId = safe_text($_POST['template_id'] ?? '');
        $found = false;
        foreach ($rows as &$row) {
            if ((string) ($row['id'] ?? '') !== $templateId) {
                continue;
            }
            $found = true;
            $row['archived_flag'] = ($action === 'archive_template_set');
            $row['updated_at'] = date('c');
        }
        unset($row);

        if (!$found) {
            $redirectWith('templates', 'error', 'Template set not found.');
        }

        $saved = json_save($templatePath, $rows);
        if (!$saved['ok']) {
            $redirectWith('templates', 'error', 'Unable to update template archive status.');
        }

        $redirectWith('templates', 'success', $action === 'archive_template_set' ? 'Template archived.' : 'Template unarchived.');
    }


    if (in_array($action, ['save_receipt_draft', 'finalize_receipt'], true)) {
        $tab = safe_text($_POST['return_tab'] ?? 'accepted_customers');
        $view = safe_text($_POST['quotation_id'] ?? safe_text($_POST['return_view'] ?? ''));
        $receiptId = safe_text($_POST['receipt_id'] ?? '');
        if ($view === '' || $receiptId === '') {
            $redirectDocuments($tab, 'error', 'Receipt context missing.', ['view' => $view]);
        }

        $quote = documents_get_quote($view);
        if ($quote === null) {
            $redirectDocuments($tab, 'error', 'Quotation not found.', ['view' => $view]);
        }

        $receipt = documents_get_sales_document('receipt', $receiptId);
        if ($receipt === null || (string) ($receipt['quotation_id'] ?? '') !== (string) ($quote['id'] ?? '')) {
            $redirectDocuments($tab, 'error', 'Receipt not found.', ['view' => $view]);
        }

        if (documents_is_archived($receipt)) {
            $redirectDocuments($tab, 'error', 'Archived receipts cannot be edited.', ['view' => $view]);
        }

        $currentStatus = strtolower(trim((string) ($receipt['status'] ?? 'draft')));
        if ($currentStatus === 'final') {
            $redirectDocuments($tab, 'error', 'Finalized receipts are locked.', ['view' => $view, 'action' => 'edit_receipt', 'receipt_id' => $receiptId]);
        }

        $dateReceived = safe_text($_POST['date_received'] ?? ($_POST['receipt_date'] ?? (string) ($receipt['date_received'] ?? $receipt['receipt_date'] ?? date('Y-m-d'))));
        if ($dateReceived === '') {
            $dateReceived = date('Y-m-d');
        }
        $dateObj = DateTime::createFromFormat('Y-m-d', $dateReceived);
        if (!($dateObj instanceof DateTime) || $dateObj->format('Y-m-d') !== $dateReceived) {
            $redirectDocuments($tab, 'error', 'Invalid receipt date.', ['view' => $view, 'action' => 'edit_receipt', 'receipt_id' => $receiptId]);
        }

        $amount = (float) ($_POST['amount_rs'] ?? $_POST['amount_received'] ?? $receipt['amount_rs'] ?? $receipt['amount_received'] ?? 0);
        $mode = safe_text($_POST['mode'] ?? (string) ($receipt['mode'] ?? ''));
        $txnRef = safe_text($_POST['txn_ref'] ?? $_POST['reference'] ?? (string) ($receipt['txn_ref'] ?? $receipt['reference'] ?? ''));
        $notes = safe_text($_POST['notes'] ?? (string) ($receipt['notes'] ?? ''));

        if ($action === 'finalize_receipt' && $amount <= 0) {
            $redirectDocuments($tab, 'error', 'Amount must be greater than 0 to finalize receipt.', ['view' => $view, 'action' => 'edit_receipt', 'receipt_id' => $receiptId]);
        }

        $receipt['date_received'] = $dateReceived;
        $receipt['receipt_date'] = $dateReceived;
        $receipt['amount_rs'] = round($amount, 2);
        $receipt['amount_received'] = round($amount, 2);
        $receipt['mode'] = $mode;
        $receipt['txn_ref'] = $txnRef;
        $receipt['reference'] = $txnRef;
        $receipt['notes'] = $notes;
        $receipt['status'] = ($action === 'finalize_receipt') ? 'final' : 'draft';

        $saved = documents_save_sales_document('receipt', $receipt);
        if (!$saved['ok']) {
            $redirectDocuments($tab, 'error', 'Unable to save receipt.', ['view' => $view, 'action' => 'edit_receipt', 'receipt_id' => $receiptId]);
        }
        $receiptActor = [
            'role' => $isAdmin ? 'admin' : 'employee',
            'id' => (string) ($user['id'] ?? ''),
            'name' => (string) ($user['full_name'] ?? ($isAdmin ? 'Admin' : 'Employee')),
        ];
        documents_append_document_action_log($receiptActor, $action === 'finalize_receipt' ? 'finalize' : 'update_draft', 'receipt', $receiptId, $view, $action === 'finalize_receipt' ? 'Receipt finalized.' : 'Receipt draft updated.');

        $msg = $action === 'finalize_receipt' ? 'Receipt finalized.' : 'Receipt draft saved.';
        $redirectDocuments($tab, 'success', $msg, ['view' => $view, 'action' => 'edit_receipt', 'receipt_id' => $receiptId]);
    }


    if (in_array($action, ['save_pi_draft', 'finalize_pi'], true)) {
        $tab = safe_text($_POST['return_tab'] ?? 'accepted_customers');
        $view = safe_text($_POST['quotation_id'] ?? safe_text($_POST['return_view'] ?? ''));
        $piId = safe_text($_POST['pi_id'] ?? '');
        if ($view === '' || $piId === '') {
            $redirectDocuments($tab, 'error', 'PI context missing.', ['view' => $view]);
        }
        if ($action === 'finalize_pi' && !$isAdmin) {
            $redirectDocuments($tab, 'error', 'Only admin can finalize PI.', ['view' => $view, 'pi_id' => $piId, 'pack_action' => 'edit_pi']);
        }
        $quote = documents_get_quote($view);
        if ($quote === null) {
            $redirectDocuments($tab, 'error', 'Quotation not found.', ['view' => $view]);
        }
        $quote = documents_quote_prepare($quote);
        $pi = $preparePiDocument(documents_get_sales_document('proforma', $piId), $quote);
        if ($pi['pi_id'] === '' || (string) ($pi['quote_id'] ?? '') !== (string) ($quote['id'] ?? '')) {
            $redirectDocuments($tab, 'error', 'PI not found.', ['view' => $view]);
        }
        if (documents_is_archived($pi)) {
            $redirectDocuments($tab, 'error', 'Archived PI cannot be edited.', ['view' => $view]);
        }
        if ((string) ($pi['status'] ?? 'draft') === 'final') {
            $redirectDocuments($tab, 'error', 'Finalized PI is locked.', ['view' => $view, 'pi_id' => $piId, 'pack_action' => 'edit_pi']);
        }

        $issueDate = safe_text((string) ($_POST['issue_date'] ?? $pi['issue_date'] ?? date('Y-m-d')));
        if ($issueDate === '') { $issueDate = date('Y-m-d'); }
        $d = DateTime::createFromFormat('Y-m-d', $issueDate);
        if (!($d instanceof DateTime) || $d->format('Y-m-d') !== $issueDate) {
            $redirectDocuments($tab, 'error', 'Invalid PI issue date.', ['view' => $view, 'pi_id' => $piId, 'pack_action' => 'edit_pi']);
        }

        $pi['issue_date'] = $issueDate;
        $pi['pi_date'] = $issueDate;
        $pi['customer_name_snapshot'] = safe_text((string) ($_POST['customer_name_snapshot'] ?? $pi['customer_name_snapshot'] ?? ''));
        $pi['customer_name'] = $pi['customer_name_snapshot'];
        $pi['customer_mobile'] = normalize_customer_mobile((string) ($_POST['customer_mobile'] ?? $pi['customer_mobile'] ?? ''));
        $pi['site_address_snapshot'] = safe_multiline_text((string) ($_POST['site_address_snapshot'] ?? $pi['site_address_snapshot'] ?? ''));
        $pi['customer_gstin'] = strtoupper(safe_text((string) ($_POST['customer_gstin'] ?? $pi['customer_gstin'] ?? '')));
        $pi['place_of_supply_state'] = safe_text((string) ($_POST['place_of_supply_state'] ?? $pi['place_of_supply_state'] ?? ''));
        $pi['notes'] = safe_multiline_text((string) ($_POST['notes'] ?? $pi['notes'] ?? ''));

        $descriptions = is_array($_POST['item_description'] ?? null) ? (array) $_POST['item_description'] : [];
        $items = is_array($pi['items_snapshot'] ?? null) ? array_values($pi['items_snapshot']) : [];
        foreach ($items as $ix => $row) {
            if (!is_array($row)) { continue; }
            $items[$ix]['description'] = safe_multiline_text((string) ($descriptions[(string) $ix] ?? $row['description'] ?? ''));
        }
        $pi['items_snapshot'] = $items;

        if ($isAdmin) {
            $pi['pricing_snapshot']['basic_total'] = (float) ($_POST['pricing_basic_total'] ?? ($pi['pricing_snapshot']['basic_total'] ?? 0));
            $pi['pricing_snapshot']['system_gross_incl_gst'] = (float) ($_POST['pricing_system_gross_incl_gst'] ?? ($pi['pricing_snapshot']['system_gross_incl_gst'] ?? 0));
            $pi['pricing_snapshot']['transportation'] = (float) ($_POST['pricing_transportation'] ?? ($pi['pricing_snapshot']['transportation'] ?? 0));
            $pi['pricing_snapshot']['discount'] = (float) ($_POST['pricing_discount'] ?? ($pi['pricing_snapshot']['discount'] ?? 0));
            $pi['pricing_snapshot']['gross_payable_after_discount'] = (float) ($_POST['pricing_gross_payable_after_discount'] ?? ($pi['pricing_snapshot']['gross_payable_after_discount'] ?? 0));
        }

        $actor = ['role' => $isAdmin ? 'admin' : 'employee', 'id' => (string) ($user['id'] ?? ''), 'name' => (string) ($user['full_name'] ?? ($isAdmin ? 'Admin' : 'Employee'))];
        if ($action === 'finalize_pi') {
            $pi['status'] = 'final';
            $pi['finalized_at'] = date('c');
            $pi['finalized_by'] = ['type' => 'admin', 'id' => (string) ($actor['id'] ?? ''), 'name' => (string) ($actor['name'] ?? 'Admin')];
        } else {
            $pi['status'] = 'draft';
        }

        $saved = documents_save_sales_document('proforma', $pi);
        if (!($saved['ok'] ?? false)) {
            $redirectDocuments($tab, 'error', 'Unable to save PI.', ['view' => $view, 'pi_id' => $piId, 'pack_action' => 'edit_pi']);
        }
        $logAction = $action === 'finalize_pi' ? 'finalize' : 'update_draft';
        documents_append_document_action_log($actor, $logAction, 'pi', $piId, $view, $action === 'finalize_pi' ? 'PI finalized.' : 'PI draft updated.');

        if ($action === 'finalize_pi') {
            $verifyRows = documents_load_document_verification();
            foreach ($verifyRows as &$vRow) {
                if (!is_array($vRow)) { continue; }
                if ((string) ($vRow['status'] ?? '') !== 'not_verified') { continue; }
                $logId = (string) ($vRow['log_id'] ?? '');
                if ($logId === '') { continue; }
                $allLogs = documents_load_document_actions_log();
                foreach ($allLogs as $logRow) {
                    if ((string) ($logRow['id'] ?? '') === $logId && (string) ($logRow['doc_type'] ?? '') === 'pi' && (string) ($logRow['doc_id'] ?? '') === $piId) {
                        $vRow['status'] = 'verified';
                        $vRow['verified_at'] = date('c');
                        $vRow['verified_by'] = ['role' => 'admin', 'id' => (string) ($actor['id'] ?? ''), 'name' => (string) ($actor['name'] ?? '')];
                        $vRow['admin_note'] = 'Auto-verified on finalize.';
                        break;
                    }
                }
            }
            unset($vRow);
            documents_save_document_verification($verifyRows);
        }

        $redirectDocuments($tab, 'success', $action === 'finalize_pi' ? 'PI finalized.' : 'PI draft saved.', ['view' => $view, 'pi_id' => $piId, 'pack_action' => 'edit_pi']);
    }


    if ($action === 'set_archive_state') {
        if (!$isAdmin) {
            $redirectDocuments('accepted_customers', 'error', 'Access denied.');
        }
        $tab = safe_text($_POST['return_tab'] ?? 'accepted_customers');
        $view = safe_text($_POST['return_view'] ?? '');
        $docType = safe_text($_POST['doc_type'] ?? '');
        $docId = safe_text($_POST['doc_id'] ?? '');
        $archiveState = safe_text($_POST['archive_state'] ?? 'archive');
        $shouldArchive = $archiveState !== 'unarchive';

        if ($docType === 'quotation') {
            $quote = documents_get_quote($docId);
            if ($quote === null) {
                $redirectDocuments($tab, 'error', 'Quotation not found.', $view !== '' ? ['view' => $view] : []);
            }
            if ($shouldArchive) {
                $quote = $archiveByAdmin($quote, [
                    'id' => (string) ($user['id'] ?? ''),
                    'name' => (string) ($user['full_name'] ?? 'Admin'),
                ]);
            } else {
                $quote = $unarchiveRecord($quote);
            }
            $quote['updated_at'] = date('c');
            $saved = documents_save_quote($quote);
            if (!$saved['ok']) {
                $redirectDocuments($tab, 'error', 'Unable to update quotation archive state.', $view !== '' ? ['view' => $view] : []);
            }
            $redirectDocuments($tab, 'success', $shouldArchive ? 'Quotation archived.' : 'Quotation unarchived.', $view !== '' ? ['view' => $view] : []);
        }

        if ($docType === 'agreement') {
            $agreement = documents_get_agreement($docId);
            if ($agreement !== null) {
                if ($shouldArchive) {
                    $agreement = documents_set_archived($agreement, [
                        'type' => 'admin',
                        'id' => (string) ($user['id'] ?? ''),
                        'name' => (string) ($user['full_name'] ?? 'Admin'),
                    ]);
                    $agreement['status'] = 'Archived';
                } else {
                    $agreement = documents_set_unarchived($agreement);
                    $agreement['status'] = 'Draft';
                }
                $agreement['updated_at'] = date('c');
                $savedAgreement = documents_save_agreement($agreement);
                if (!$savedAgreement['ok']) {
                    $redirectDocuments($tab, 'error', 'Unable to update agreement archive state.', $view !== '' ? ['view' => $view] : []);
                }
                $redirectDocuments($tab, 'success', $shouldArchive ? 'Agreement archived.' : 'Agreement unarchived.', $view !== '' ? ['view' => $view] : []);
            }
        }

        $mappedType = $documentTypeMap[$docType] ?? '';
        if ($mappedType === '') {
            $redirectDocuments($tab, 'error', 'Invalid document type.', $view !== '' ? ['view' => $view] : []);
        }

        $document = documents_get_sales_document($mappedType, $docId);
        if ($document === null) {
            $redirectDocuments($tab, 'error', 'Document not found.', $view !== '' ? ['view' => $view] : []);
        }

        if ($shouldArchive) {
            $document = documents_set_archived($document, [
                'type' => 'admin',
                'id' => (string) ($user['id'] ?? ''),
                'name' => (string) ($user['full_name'] ?? 'Admin'),
            ]);
        } else {
            $document = documents_set_unarchived($document);
        }
        $document['updated_at'] = date('c');
        $saved = documents_save_sales_document($mappedType, $document);
        if (!$saved['ok']) {
            $redirectDocuments($tab, 'error', 'Unable to update archive state.', $view !== '' ? ['view' => $view] : []);
        }
        $redirectDocuments($tab, 'success', $shouldArchive ? 'Document archived.' : 'Document unarchived.', $view !== '' ? ['view' => $view] : []);
    }

    if (in_array($action, ['document_verification_update', 'document_verification_bulk_update'], true)) {
        if (!$isAdmin) {
            $redirectDocuments('accepted_customers', 'error', 'Access denied.');
        }
        $verificationRows = documents_load_document_verification();
        $allowedStatuses = ['not_verified', 'verified', 'needs_clarification'];
        $newStatus = safe_text((string) ($_POST['verification_status'] ?? ''));
        if (!in_array($newStatus, $allowedStatuses, true)) {
            $redirectDocuments('document_verification', 'error', 'Invalid verification status.');
        }
        $adminNote = safe_text((string) ($_POST['admin_note'] ?? ''));
        if ($newStatus === 'needs_clarification' && $adminNote === '') {
            $redirectDocuments('document_verification', 'error', 'Admin note is required for needs clarification.');
        }

        $targetLogIds = [];
        if ($action === 'document_verification_update') {
            $singleLogId = safe_text((string) ($_POST['log_id'] ?? ''));
            if ($singleLogId !== '') {
                $targetLogIds[] = $singleLogId;
            }
        } else {
            foreach ((array) ($_POST['log_ids'] ?? []) as $logIdRaw) {
                $cleanId = safe_text((string) $logIdRaw);
                if ($cleanId !== '') {
                    $targetLogIds[] = $cleanId;
                }
            }
        }
        if ($targetLogIds === []) {
            $redirectDocuments('document_verification', 'error', 'Select at least one log entry.');
        }

        $targetSet = array_flip($targetLogIds);
        $updatedCount = 0;
        $adminActor = ['role' => 'admin', 'id' => (string) ($user['id'] ?? ''), 'name' => (string) ($user['full_name'] ?? 'Admin')];
        foreach ($verificationRows as &$verificationRow) {
            $logId = (string) ($verificationRow['log_id'] ?? '');
            if (!isset($targetSet[$logId])) {
                continue;
            }
            $verificationRow['status'] = $newStatus;
            $verificationRow['admin_note'] = $adminNote;
            $verificationRow['verified_by'] = $adminActor;
            $verificationRow['verified_at'] = date('c');
            $updatedCount++;
        }
        unset($verificationRow);

        if ($updatedCount < 1) {
            $redirectDocuments('document_verification', 'error', 'No verification record updated.');
        }

        $savedVerification = documents_save_document_verification($verificationRows);
        $redirectDocuments(
            'document_verification',
            ($savedVerification['ok'] ?? false) ? 'success' : 'error',
            ($savedVerification['ok'] ?? false) ? ('Updated verification for ' . $updatedCount . ' action(s).') : 'Failed to save verification updates.'
        );
    }

    if ($action === 'archive_accepted_customer' || $action === 'unarchive_accepted_customer') {
        $quotationId = safe_text($_POST['quotation_id'] ?? '');
        if ($quotationId === '') {
            $redirectDocuments('accepted_customers', 'error', 'Accepted quotation is required.');
        }

        $quote = documents_get_quote($quotationId);
        if ($quote === null) {
            $redirectDocuments('accepted_customers', 'error', 'Accepted quotation not found.');
        }

        $statusNormalized = documents_quote_normalize_status((string) ($quote['status'] ?? 'draft'));
        if ($statusNormalized !== 'accepted') {
            $redirectDocuments('accepted_customers', 'error', 'Only accepted quotations can be archived from this action.');
        }

        if ($action === 'archive_accepted_customer') {
            $quote = $archiveByAdmin($quote, [
                'id' => (string) ($user['id'] ?? ''),
                'name' => (string) ($user['full_name'] ?? 'Admin'),
            ]);
            $messageText = 'Accepted customer archived.';
        } else {
            $quote = $unarchiveRecord($quote);
            $messageText = 'Accepted customer unarchived.';
        }

        $quote['updated_at'] = date('c');
        $saved = documents_save_quote($quote);
        if (!$saved['ok']) {
            $redirectDocuments('accepted_customers', 'error', 'Unable to update accepted customer archive state.');
        }

        $returnTab = safe_text($_POST['return_tab'] ?? 'accepted_customers');
        if (!in_array($returnTab, ['accepted_customers', 'archived'], true)) {
            $returnTab = 'accepted_customers';
        }
        $redirectDocuments($returnTab, 'success', $messageText);
    }


    if (in_array($action, ['save_component','save_component_edit','toggle_component_archive','save_kit_create','save_kit_edit','toggle_kit_archive','save_tax_profile','save_tax_profile_edit','toggle_tax_profile_archive','save_variant','save_variant_edit','toggle_variant_archive','save_location','save_location_edit','toggle_location_archive','create_inventory_tx','edit_inventory_tx','save_inventory_edits','inventory_verification_update','inventory_verification_bulk_update','import_components_csv','import_variants_csv','import_locations_csv','import_kits_csv','import_inventory_stock_in_csv'], true)) {
        $masterActions = ['save_component','save_component_edit','toggle_component_archive','save_kit_create','save_kit_edit','toggle_kit_archive','save_tax_profile','save_tax_profile_edit','toggle_tax_profile_archive','save_variant','save_variant_edit','toggle_variant_archive','save_location','save_location_edit','toggle_location_archive'];
        if (in_array($action, $masterActions, true) && !$isAdmin) {
            $redirectDocuments('items', 'error', 'Access denied.', ['items_subtab' => 'components']);
        }


        if (in_array($action, ['import_components_csv','import_variants_csv','import_locations_csv','import_kits_csv','import_inventory_stock_in_csv'], true)) {
            $adminOnly = ['import_components_csv','import_variants_csv','import_locations_csv','import_kits_csv'];
            if (in_array($action, $adminOnly, true) && !$isAdmin) {
                $redirectCsvImport('error', 'Access denied for this import type.');
            }
            if (!isset($_FILES['csv_file']) && $action !== 'import_kits_csv') {
                $redirectCsvImport('error', 'Please upload a CSV file.');
            }

            $componentsAll = documents_inventory_components(true);
            $componentsActive = documents_inventory_components(false);
            $variantsAll = documents_inventory_component_variants(true);
            $locationsAll = documents_inventory_locations(true);
            $kitsAll = documents_inventory_kits(true);
            $taxProfiles = documents_inventory_tax_profiles(false);
            $taxByName = [];
            foreach ($taxProfiles as $tp) {
                if (!is_array($tp)) { continue; }
                $taxByName[strtolower(trim((string) ($tp['name'] ?? '')))] = (string) ($tp['id'] ?? '');
            }
            $componentByName = [];
            foreach ($componentsActive as $cmp) {
                if (!is_array($cmp)) { continue; }
                $componentByName[strtolower(trim((string) ($cmp['name'] ?? '')))] = $cmp;
            }
            $locationByName = [];
            foreach (documents_inventory_locations(false) as $loc) {
                if (!is_array($loc)) { continue; }
                $locationByName[strtolower(trim((string) ($loc['name'] ?? '')))] = $loc;
            }

            $report = ['type' => $action, 'rows_read' => 0, 'rows_succeeded' => 0, 'rows_failed' => 0, 'errors' => []];

            if ($action === 'import_components_csv') {
                $parsed = $csvParseAssoc($_FILES['csv_file'], ['name','category','hsn','default_unit','is_cuttable','standard_length_ft','has_variants','tax_profile_name']);
                if (!($parsed['ok'] ?? false)) { $redirectCsvImport('error', (string) ($parsed['error'] ?? 'Invalid CSV.'), $report); }
                $rows = (array) ($parsed['rows'] ?? []);
                $report['rows_read'] = count($rows);
                foreach ($rows as $rowWrap) {
                    $rowNo = (int) ($rowWrap['row_no'] ?? 0);
                    $row = (array) ($rowWrap['data'] ?? []);
                    $name = safe_text((string) ($row['name'] ?? ''));
                    $defaultUnit = strtolower(safe_text((string) ($row['default_unit'] ?? '')));
                    $isCuttableParsed = $csvBoolean((string) ($row['is_cuttable'] ?? ''));
                    $hasVariantsParsed = $csvBoolean((string) ($row['has_variants'] ?? ''));
                    $errors = [];
                    if ($name === '') { $errors[] = 'name is required'; }
                    if ($defaultUnit === '') { $errors[] = 'default_unit is required'; }
                    if ($isCuttableParsed === null) { $errors[] = 'is_cuttable must be yes/no or 1/0'; }
                    if ($hasVariantsParsed === null) { $errors[] = 'has_variants must be yes/no or 1/0'; }
                    if ($isCuttableParsed === true && $defaultUnit !== 'ft') { $errors[] = 'cuttable component default_unit must be ft'; }
                    $taxProfileId = '';
                    $taxName = strtolower(trim((string) ($row['tax_profile_name'] ?? '')));
                    if ($taxName !== '') {
                        $taxProfileId = (string) ($taxByName[$taxName] ?? '');
                        if ($taxProfileId === '') { $errors[] = 'tax_profile_name not found'; }
                    }
                    if ($errors !== []) {
                        $report['rows_failed']++;
                        $report['errors'][] = ['row' => $rowNo, 'message' => implode('; ', $errors)];
                        continue;
                    }
                    $new = documents_inventory_component_defaults();
                    $new['id'] = $generateInventoryEntityId('CMP', $componentsAll);
                    $new['name'] = $name;
                    $new['category'] = safe_text((string) ($row['category'] ?? ''));
                    $new['hsn'] = safe_text((string) ($row['hsn'] ?? ''));
                    $new['default_unit'] = $defaultUnit;
                    $new['is_cuttable'] = (bool) $isCuttableParsed;
                    $new['standard_length_ft'] = max(0, (float) ($row['standard_length_ft'] ?? 0));
                    $new['has_variants'] = (bool) $hasVariantsParsed;
                    $new['tax_profile_id'] = $taxProfileId;
                    $new['created_at'] = date('c');
                    $new['updated_at'] = date('c');
                    $componentsAll[] = $new;
                    $report['rows_succeeded']++;
                }
                $saved = documents_inventory_save_components($componentsAll);
                if (!($saved['ok'] ?? false)) { $redirectCsvImport('error', 'Failed to save imported components.', $report); }
                $redirectCsvImport($report['rows_failed'] === 0 ? 'success' : 'error', 'Components CSV import completed.', $report);
            }

            if ($action === 'import_variants_csv') {
                $parsed = $csvParseAssoc($_FILES['csv_file'], ['component_name','display_name','brand','technology','wattage_wp','model_no','hsn_override','tax_profile_name_override']);
                if (!($parsed['ok'] ?? false)) { $redirectCsvImport('error', (string) ($parsed['error'] ?? 'Invalid CSV.'), $report); }
                $rows = (array) ($parsed['rows'] ?? []);
                $report['rows_read'] = count($rows);
                foreach ($rows as $rowWrap) {
                    $rowNo = (int) ($rowWrap['row_no'] ?? 0);
                    $row = (array) ($rowWrap['data'] ?? []);
                    $cmpName = strtolower(trim((string) ($row['component_name'] ?? '')));
                    $cmp = is_array($componentByName[$cmpName] ?? null) ? $componentByName[$cmpName] : null;
                    $errors = [];
                    if ($cmpName === '' || $cmp === null) { $errors[] = 'component_name not found'; }
                    if (safe_text((string) ($row['display_name'] ?? '')) === '') { $errors[] = 'display_name is required'; }
                    $taxId = '';
                    $taxName = strtolower(trim((string) ($row['tax_profile_name_override'] ?? '')));
                    if ($taxName !== '') {
                        $taxId = (string) ($taxByName[$taxName] ?? '');
                        if ($taxId === '') { $errors[] = 'tax_profile_name_override not found'; }
                    }
                    if ($errors !== []) {
                        $report['rows_failed']++;
                        $report['errors'][] = ['row' => $rowNo, 'message' => implode('; ', $errors)];
                        continue;
                    }
                    $new = documents_component_variant_defaults();
                    $new['id'] = $generateInventoryEntityId('VAR', $variantsAll);
                    $new['component_id'] = (string) ($cmp['id'] ?? '');
                    $new['display_name'] = safe_text((string) ($row['display_name'] ?? ''));
                    $new['brand'] = safe_text((string) ($row['brand'] ?? ''));
                    $new['technology'] = safe_text((string) ($row['technology'] ?? ''));
                    $new['wattage_wp'] = max(0, (float) ($row['wattage_wp'] ?? 0));
                    $new['model_no'] = safe_text((string) ($row['model_no'] ?? ''));
                    $new['hsn_override'] = safe_text((string) ($row['hsn_override'] ?? ''));
                    $new['tax_profile_id_override'] = $taxId;
                    $new['created_at'] = date('c');
                    $new['updated_at'] = date('c');
                    $variantsAll[] = $new;
                    $report['rows_succeeded']++;
                }
                $saved = documents_inventory_save_component_variants($variantsAll);
                if (!($saved['ok'] ?? false)) { $redirectCsvImport('error', 'Failed to save imported variants.', $report); }
                $redirectCsvImport($report['rows_failed'] === 0 ? 'success' : 'error', 'Variants CSV import completed.', $report);
            }

            if ($action === 'import_locations_csv') {
                $parsed = $csvParseAssoc($_FILES['csv_file'], ['name','type','notes']);
                if (!($parsed['ok'] ?? false)) { $redirectCsvImport('error', (string) ($parsed['error'] ?? 'Invalid CSV.'), $report); }
                $rows = (array) ($parsed['rows'] ?? []);
                $report['rows_read'] = count($rows);
                $existingNames = [];
                foreach ($locationsAll as $loc) { if (is_array($loc)) { $existingNames[strtolower(trim((string) ($loc['name'] ?? '')))] = true; } }
                foreach ($rows as $rowWrap) {
                    $rowNo = (int) ($rowWrap['row_no'] ?? 0);
                    $row = (array) ($rowWrap['data'] ?? []);
                    $name = safe_text((string) ($row['name'] ?? ''));
                    $errs = [];
                    if ($name === '') { $errs[] = 'name is required'; }
                    if ($name !== '' && isset($existingNames[strtolower($name)])) { $errs[] = 'duplicate location name'; }
                    if ($errs !== []) {
                        $report['rows_failed']++;
                        $report['errors'][] = ['row' => $rowNo, 'message' => implode('; ', $errs)];
                        continue;
                    }
                    $new = documents_inventory_location_defaults();
                    $new['id'] = $generateInventoryEntityId('LOC', $locationsAll);
                    $new['name'] = $name;
                    $new['type'] = safe_text((string) ($row['type'] ?? ''));
                    $new['notes'] = safe_text((string) ($row['notes'] ?? ''));
                    $new['created_at'] = date('c');
                    $new['updated_at'] = date('c');
                    $locationsAll[] = $new;
                    $existingNames[strtolower($name)] = true;
                    $report['rows_succeeded']++;
                }

                $saved = documents_inventory_save_locations($locationsAll);
                if (!($saved['ok'] ?? false)) { $redirectCsvImport('error', 'Failed to save imported locations.', $report); }
                $redirectCsvImport($report['rows_failed'] === 0 ? 'success' : 'error', 'Locations CSV import completed.', $report);
            }

            if ($action === 'import_kits_csv') {
                if (!isset($_FILES['kits_csv']) || !isset($_FILES['kit_items_csv'])) {
                    $redirectCsvImport('error', 'Upload both kits.csv and kit_items.csv files.', $report);
                }
                $kitsParsed = $csvParseAssoc($_FILES['kits_csv'], ['kit_name','category','description','tax_profile_name']);
                $itemsParsed = $csvParseAssoc($_FILES['kit_items_csv'], ['kit_name','component_name','mode','unit','fixed_qty','capacity_type','capacity_expr','slab_json','rule_type','target_expr','allow_overbuild_pct','manual_note','remarks']);
                if (!($kitsParsed['ok'] ?? false)) { $redirectCsvImport('error', (string) ($kitsParsed['error'] ?? 'Invalid kits CSV.'), $report); }
                if (!($itemsParsed['ok'] ?? false)) { $redirectCsvImport('error', (string) ($itemsParsed['error'] ?? 'Invalid kit items CSV.'), $report); }
                $kitRows = (array) ($kitsParsed['rows'] ?? []);
                $itemRows = (array) ($itemsParsed['rows'] ?? []);
                $report['rows_read'] = count($kitRows) + count($itemRows);
                $kitMap = [];
                foreach ($kitRows as $rowWrap) {
                    $rowNo = (int) ($rowWrap['row_no'] ?? 0);
                    $row = (array) ($rowWrap['data'] ?? []);
                    $name = safe_text((string) ($row['kit_name'] ?? ''));
                    $errs = [];
                    if ($name === '') { $errs[] = 'kit_name is required'; }
                    $taxName = strtolower(trim((string) ($row['tax_profile_name'] ?? '')));
                    $taxId = '';
                    if ($taxName !== '') { $taxId = (string) ($taxByName[$taxName] ?? ''); if ($taxId === '') { $errs[] = 'tax_profile_name not found'; } }
                    if ($errs !== []) {
                        $report['rows_failed']++;
                        $report['errors'][] = ['row' => $rowNo, 'message' => implode('; ', $errs)];
                        continue;
                    }
                    $kit = documents_inventory_kit_defaults();
                    $kit['id'] = $generateInventoryEntityId('KIT', $kitsAll);
                    $kit['name'] = $name;
                    $kit['category'] = safe_text((string) ($row['category'] ?? ''));
                    $kit['description'] = safe_text((string) ($row['description'] ?? ''));
                    $kit['tax_profile_id'] = $taxId;
                    $kit['created_at'] = date('c');
                    $kit['updated_at'] = date('c');
                    $kitsAll[] = $kit;
                    $kitMap[strtolower($name)] = $kit;
                    $report['rows_succeeded']++;
                }
                foreach ($itemRows as $rowWrap) {
                    $rowNo = (int) ($rowWrap['row_no'] ?? 0);
                    $row = (array) ($rowWrap['data'] ?? []);
                    $kitName = strtolower(trim((string) ($row['kit_name'] ?? '')));
                    $componentName = strtolower(trim((string) ($row['component_name'] ?? '')));
                    $mode = safe_text((string) ($row['mode'] ?? 'fixed_qty'));
                    $errs = [];
                    $kit = is_array($kitMap[$kitName] ?? null) ? $kitMap[$kitName] : null;
                    $cmp = is_array($componentByName[$componentName] ?? null) ? $componentByName[$componentName] : null;
                    if ($kit === null) { $errs[] = 'kit_name not found in kits.csv'; }
                    if ($cmp === null) { $errs[] = 'component_name not found'; }
                    if (!in_array($mode, ['fixed_qty','capacity_qty','rule_fulfillment','unfixed_manual'], true)) { $errs[] = 'invalid mode'; }
                    if ($mode === 'fixed_qty' && (float) ($row['fixed_qty'] ?? 0) <= 0) { $errs[] = 'fixed_qty required for fixed_qty mode'; }
                    if ($mode === 'capacity_qty' && safe_text((string) ($row['capacity_expr'] ?? '')) === '' && safe_text((string) ($row['slab_json'] ?? '')) === '') { $errs[] = 'capacity_expr or slab_json required'; }
                    if ($mode === 'rule_fulfillment' && safe_text((string) ($row['target_expr'] ?? '')) === '') { $errs[] = 'target_expr required'; }
                    $slabs = [];
                    $slabJson = trim((string) ($row['slab_json'] ?? ''));
                    if ($slabJson !== '') {
                        $decoded = json_decode($slabJson, true);
                        if (!is_array($decoded)) { $errs[] = 'slab_json must be a valid JSON array'; }
                        else { $slabs = $decoded; }
                    }
                    if ($errs !== []) {
                        $report['rows_failed']++;
                        $report['errors'][] = ['row' => $rowNo, 'message' => implode('; ', $errs)];
                        continue;
                    }
                    $line = documents_inventory_kit_bom_line_defaults();
                    $line['line_id'] = 'kline_' . bin2hex(random_bytes(4));
                    $line['component_id'] = (string) ($cmp['id'] ?? '');
                    $line['mode'] = $mode;
                    $line['unit'] = safe_text((string) ($row['unit'] ?? ''));
                    $line['fixed_qty'] = max(0, (float) ($row['fixed_qty'] ?? 0));
                    $line['capacity_rule'] = ['type' => safe_text((string) ($row['capacity_type'] ?? 'formula')) ?: 'formula', 'expr' => safe_text((string) ($row['capacity_expr'] ?? '')), 'slabs' => $slabs];
                    $line['rule'] = ['rule_type' => safe_text((string) ($row['rule_type'] ?? 'min_total_wp')) ?: 'min_total_wp', 'target_expr' => safe_text((string) ($row['target_expr'] ?? '')), 'allow_overbuild_pct' => max(0, (float) ($row['allow_overbuild_pct'] ?? 0)), 'requires_variants' => true];
                    $line['manual_note'] = safe_text((string) ($row['manual_note'] ?? ''));
                    $line['remarks'] = safe_text((string) ($row['remarks'] ?? ''));
                    $kitIdx = null;
                    foreach ($kitsAll as $ix => $k) { if ((string) ($k['id'] ?? '') === (string) ($kit['id'] ?? '')) { $kitIdx = $ix; break; } }
                    if ($kitIdx !== null) {
                        $currentItems = is_array($kitsAll[$kitIdx]['items'] ?? null) ? $kitsAll[$kitIdx]['items'] : [];
                        $currentItems[] = documents_normalize_kit_bom_line($line, $cmp);
                        $kitsAll[$kitIdx]['items'] = $currentItems;
                        $kitsAll[$kitIdx]['updated_at'] = date('c');
                    }
                    $report['rows_succeeded']++;
                }
                $saved = documents_inventory_save_kits($kitsAll);
                if (!($saved['ok'] ?? false)) { $redirectCsvImport('error', 'Failed to save imported kits.', $report); }
                $redirectCsvImport($report['rows_failed'] === 0 ? 'success' : 'error', 'Kits CSV import completed.', $report);
            }

            if ($action === 'import_inventory_stock_in_csv') {
                $parsed = $csvParseAssoc($_FILES['csv_file'], ['component_name','variant_display_name','qty','piece_count','piece_length_ft','location_name','notes']);
                if (!($parsed['ok'] ?? false)) { $redirectCsvImport('error', (string) ($parsed['error'] ?? 'Invalid CSV.'), $report); }
                $rows = (array) ($parsed['rows'] ?? []);
                $report['rows_read'] = count($rows);
                $stockState = documents_inventory_load_stock();
                foreach ($rows as $rowWrap) {
                    $rowNo = (int) ($rowWrap['row_no'] ?? 0);
                    $row = (array) ($rowWrap['data'] ?? []);
                    $errs = [];
                    $cmpName = strtolower(trim((string) ($row['component_name'] ?? '')));
                    $cmp = is_array($componentByName[$cmpName] ?? null) ? $componentByName[$cmpName] : null;
                    if ($cmp === null) { $errs[] = 'component_name not found'; }
                    $variantId = '';
                    if (is_array($cmp) && !empty($cmp['has_variants'])) {
                        $needleVar = strtolower(trim((string) ($row['variant_display_name'] ?? '')));
                        if ($needleVar === '') { $errs[] = 'variant_display_name is required for variant components'; }
                        else {
                            foreach ($variantsAll as $v) {
                                if (!is_array($v) || !empty($v['archived_flag'])) { continue; }
                                if ((string) ($v['component_id'] ?? '') === (string) ($cmp['id'] ?? '') && strtolower(trim((string) ($v['display_name'] ?? ''))) === $needleVar) {
                                    $variantId = (string) ($v['id'] ?? '');
                                    break;
                                }
                            }
                            if ($variantId === '') { $errs[] = 'variant_display_name not found under selected component'; }
                        }
                    }
                    $locationName = strtolower(trim((string) ($row['location_name'] ?? '')));
                    $locationId = '';
                    if ($locationName !== '') {
                        $loc = is_array($locationByName[$locationName] ?? null) ? $locationByName[$locationName] : null;
                        if ($loc === null) { $errs[] = 'location_name not found'; } else { $locationId = (string) ($loc['id'] ?? ''); }
                    }
                    if (is_array($cmp) && !empty($cmp['is_cuttable'])) {
                        if (trim((string) ($row['piece_count'] ?? '')) === '' || trim((string) ($row['piece_length_ft'] ?? '')) === '') { $errs[] = 'piece_count and piece_length_ft are required for cuttable components'; }
                    } else {
                        if (max(0, (float) ($row['qty'] ?? 0)) <= 0) { $errs[] = 'qty is required for non-cuttable components'; }
                    }
                    if ($errs !== []) {
                        $report['rows_failed']++;
                        $report['errors'][] = ['row' => $rowNo, 'message' => implode('; ', $errs)];
                        continue;
                    }
                    $componentId = (string) ($cmp['id'] ?? '');
                    $entry = documents_inventory_component_stock($stockState, $componentId, $variantId);
                    if (!empty($cmp['is_cuttable'])) {
                        $pieceCount = (int) ($row['piece_count'] ?? 0);
                        $pieceLength = round(max(0, (float) str_replace(',', '.', (string) ($row['piece_length_ft'] ?? 0))), 2);
                        for ($i = 0; $i < $pieceCount; $i++) {
                            $entry['lots'][] = [
                                'lot_id' => 'LOT-' . date('YmdHis') . '-' . bin2hex(random_bytes(2)),
                                'received_at' => date('c'),
                                'source_ref' => 'csv_import_in',
                                'original_length_ft' => $pieceLength,
                                'remaining_length_ft' => $pieceLength,
                                'location_id' => $locationId,
                                'notes' => safe_text((string) ($row['notes'] ?? '')),
                                'created_at' => date('c'),
                                'created_by' => documents_inventory_actor($user ?? []),
                            ];
                        }
                    } else {
                        $qty = max(0, (float) ($row['qty'] ?? 0));
                        $batch = documents_inventory_create_batch($locationId, $qty, documents_inventory_actor($user ?? []), '');
                        $batches = is_array($entry['batches'] ?? null) ? $entry['batches'] : [];
                        $batches[] = $batch;
                        $entry['batches'] = $batches;
                        $entry['location_breakdown'] = documents_inventory_normalize_location_breakdown(array_merge((array) ($entry['location_breakdown'] ?? []), [['location_id' => $locationId, 'qty' => $qty]]));
                        $entry['on_hand_qty'] = documents_inventory_location_breakdown_total($entry['location_breakdown']);
                    }
                    $entry['updated_at'] = date('c');
                    documents_inventory_set_component_stock($stockState, $componentId, $variantId, $entry);
                    $tx = array_merge(documents_inventory_transaction_defaults(), [
                        'id' => 'txn_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)),
                        'type' => 'IN',
                        'component_id' => $componentId,
                        'variant_id' => $variantId,
                        'qty' => max(0, (float) ($row['qty'] ?? 0)),
                        'unit' => !empty($cmp['is_cuttable']) ? 'ft' : (string) ($cmp['default_unit'] ?? 'qty'),
                        'length_ft' => !empty($cmp['is_cuttable']) ? ((int) ($row['piece_count'] ?? 0) * (float) str_replace(',', '.', (string) ($row['piece_length_ft'] ?? 0))) : 0,
                        'location_id' => $locationId,
                        'notes' => safe_text((string) ($row['notes'] ?? '')),
                        'ref_type' => 'csv_import_in',
                        'ref_id' => '',
                        'created_at' => date('c'),
                        'created_by' => documents_inventory_actor($user ?? []),
                    ]);
                    documents_inventory_append_transaction($tx);
                    $report['rows_succeeded']++;
                }
                $saved = documents_inventory_save_stock($stockState);
                if (!($saved['ok'] ?? false)) { $redirectCsvImport('error', 'Failed to save stock import.', $report); }
                $redirectCsvImport($report['rows_failed'] === 0 ? 'success' : 'error', 'Stock IN CSV import completed.', $report);
            }
        }

        if (in_array($action, ['save_component', 'save_component_edit'], true)) {
            $editing = $action === 'save_component_edit';
            $componentId = safe_text((string) ($_POST['component_id'] ?? ''));
            $components = documents_inventory_components(true);
            if ($editing && $componentId === '') {
                $redirectDocuments('items', 'error', 'Component not found for edit.', ['items_subtab' => 'components']);
            }
            $isCuttable = isset($_POST['is_cuttable']);
            $defaultUnit = safe_text((string) ($_POST['default_unit'] ?? 'pcs'));
            if ($isCuttable && strtolower($defaultUnit) !== 'ft') {
                $redirectDocuments('items', 'error', 'Cuttable component must use ft as default unit.', ['items_subtab' => 'components']);
            }
            $row = documents_inventory_component_defaults();
            if ($componentId !== '') {
                foreach ($components as $existing) {
                    if ((string) ($existing['id'] ?? '') === $componentId) {
                        $row = array_merge($row, $existing);
                        break;
                    }
                }
            } else {
                $row['id'] = $generateInventoryEntityId('CMP', $components);
                $row['created_at'] = date('c');
            }
            if ($editing && (string) ($row['id'] ?? '') !== $componentId) {
                $redirectDocuments('items', 'error', 'Component not found for edit.', ['items_subtab' => 'components']);
            }
            $row['name'] = safe_text((string) ($_POST['name'] ?? ''));
            if ($row['name'] === '') {
                $redirectDocuments('items', 'error', 'Component name is required.', ['items_subtab' => 'components']);
            }
            $row['category'] = safe_text((string) ($_POST['category'] ?? ''));
            $row['hsn'] = safe_text((string) ($_POST['hsn'] ?? ''));
            $row['default_unit'] = $defaultUnit;
            $row['tax_profile_id'] = safe_text((string) ($_POST['tax_profile_id'] ?? ''));
            $row['has_variants'] = isset($_POST['has_variants']);
            $row['is_cuttable'] = $isCuttable;
            $row['standard_length_ft'] = max(0, (float) ($_POST['standard_length_ft'] ?? 0));
            $row['min_issue_ft'] = max(0.01, (float) ($_POST['min_issue_ft'] ?? 1));
            $row['description'] = safe_text((string) ($_POST['description'] ?? ''));
            $row['notes'] = safe_text((string) ($_POST['notes'] ?? ''));
            $row['updated_at'] = date('c');

            $savedRows = [];
            $updated = false;
            foreach ($components as $component) {
                if ((string) ($component['id'] ?? '') === (string) ($row['id'] ?? '')) {
                    $savedRows[] = $row;
                    $updated = true;
                } else {
                    $savedRows[] = $component;
                }
            }
            if (!$updated) {
                $savedRows[] = $row;
            }
            $result = documents_inventory_save_components($savedRows);
            $msg = $editing ? 'Component updated.' : 'Component saved.';
            $redirectDocuments('items', $result['ok'] ? 'success' : 'error', $result['ok'] ? $msg : 'Failed to save component.', ['items_subtab' => 'components']);
        }

        if ($action === 'toggle_component_archive') {
            $componentId = safe_text((string) ($_POST['component_id'] ?? ''));
            $archiveState = safe_text((string) ($_POST['archive_state'] ?? 'archive'));
            $components = documents_inventory_components(true);
            foreach ($components as &$component) {
                if ((string) ($component['id'] ?? '') === $componentId) {
                    $component['archived_flag'] = $archiveState === 'archive';
                    $component['updated_at'] = date('c');
                }
            }
            unset($component);
            $result = documents_inventory_save_components($components);
            $redirectDocuments('items', $result['ok'] ? 'success' : 'error', $result['ok'] ? 'Component archive state updated.' : 'Failed to update component.', ['items_subtab' => 'components']);
        }

        if (in_array($action, ['save_kit_create', 'save_kit_edit'], true)) {
            $editing = $action === 'save_kit_edit';
            $kitId = safe_text((string) ($_POST['kit_id'] ?? ''));
            $kits = documents_inventory_kits(true);
            $row = documents_inventory_kit_defaults();
            if ($kitId !== '') {
                foreach ($kits as $existing) {
                    if ((string) ($existing['id'] ?? '') === $kitId) {
                        $row = array_merge($row, $existing);
                        break;
                    }
                }
            } else {
                $row['id'] = $generateInventoryEntityId('KIT', $kits);
                $row['created_at'] = date('c');
            }
            if ($editing && ((string) ($row['id'] ?? '') !== $kitId || $kitId === '')) {
                $redirectDocuments('items', 'error', 'Kit not found for edit.', ['items_subtab' => 'kits']);
            }
            $row['name'] = safe_text((string) ($_POST['name'] ?? ''));
            if ($row['name'] === '') {
                $redirectDocuments('items', 'error', 'Kit name is required.', ['items_subtab' => 'kits']);
            }
            $row['category'] = safe_text((string) ($_POST['category'] ?? ''));
            $row['description'] = safe_text((string) ($_POST['description'] ?? ''));
            $row['tax_profile_id'] = safe_text((string) ($_POST['tax_profile_id'] ?? ''));
            $row['updated_at'] = date('c');
            $selectedComponentIds = is_array($_POST['selected_component_ids'] ?? null) ? $_POST['selected_component_ids'] : [];
            $bomQty = is_array($_POST['bom_qty'] ?? null) ? $_POST['bom_qty'] : [];
            $bomUnit = is_array($_POST['bom_unit'] ?? null) ? $_POST['bom_unit'] : [];
            $bomRemarks = is_array($_POST['bom_remarks'] ?? null) ? $_POST['bom_remarks'] : [];
            $bomMode = is_array($_POST['bom_mode'] ?? null) ? $_POST['bom_mode'] : [];
            $bomCapacityType = is_array($_POST['bom_capacity_type'] ?? null) ? $_POST['bom_capacity_type'] : [];
            $bomCapacityExpr = is_array($_POST['bom_capacity_expr'] ?? null) ? $_POST['bom_capacity_expr'] : [];
            $bomRuleType = is_array($_POST['bom_rule_type'] ?? null) ? $_POST['bom_rule_type'] : [];
            $bomRuleTargetExpr = is_array($_POST['bom_rule_target_expr'] ?? null) ? $_POST['bom_rule_target_expr'] : [];
            $bomRuleOverbuild = is_array($_POST['bom_rule_overbuild'] ?? null) ? $_POST['bom_rule_overbuild'] : [];
            $bomManualNote = is_array($_POST['bom_manual_note'] ?? null) ? $_POST['bom_manual_note'] : [];
            $bomLineId = is_array($_POST['bom_line_id'] ?? null) ? $_POST['bom_line_id'] : [];
            $bomSlabMin = is_array($_POST['bom_capacity_slab_min'] ?? null) ? $_POST['bom_capacity_slab_min'] : [];
            $bomSlabMax = is_array($_POST['bom_capacity_slab_max'] ?? null) ? $_POST['bom_capacity_slab_max'] : [];
            $bomSlabQty = is_array($_POST['bom_capacity_slab_qty'] ?? null) ? $_POST['bom_capacity_slab_qty'] : [];
            $items = [];
            foreach ($selectedComponentIds as $compIdRaw) {
                $compId = safe_text((string) $compIdRaw);
                if ($compId === '') {
                    continue;
                }
                $component = documents_inventory_get_component($compId);
                if (!is_array($component) || !empty($component['archived_flag'])) {
                    continue;
                }
                $mode = safe_text((string) ($bomMode[$compId] ?? 'fixed_qty'));
                if (!in_array($mode, ['fixed_qty', 'capacity_qty', 'rule_fulfillment', 'unfixed_manual'], true)) {
                    $mode = 'fixed_qty';
                }
                $qty = (float) ($bomQty[$compId] ?? 0);
                $unitInput = safe_text((string) ($bomUnit[$compId] ?? ''));
                $remarks = safe_text((string) ($bomRemarks[$compId] ?? ''));
                $unit = !empty($component['is_cuttable']) ? 'ft' : ($unitInput !== '' ? $unitInput : (string) ($component['default_unit'] ?? ''));
                if ($unit === '') {
                    $redirectDocuments('items', 'error', 'Unit is required for all selected components.', ['items_subtab' => 'kits', 'edit' => $editing ? $kitId : '']);
                }

                $line = documents_normalize_kit_bom_line([
                    'line_id' => safe_text((string) ($bomLineId[$compId] ?? '')),
                    'component_id' => $compId,
                    'mode' => $mode,
                    'unit' => $unit,
                    'fixed_qty' => $qty,
                    'remarks' => $remarks,
                    'capacity_rule' => [
                        'type' => safe_text((string) ($bomCapacityType[$compId] ?? 'formula')),
                        'expr' => safe_text((string) ($bomCapacityExpr[$compId] ?? '')),
                        'slabs' => [],
                    ],
                    'rule' => [
                        'rule_type' => safe_text((string) ($bomRuleType[$compId] ?? 'min_total_wp')),
                        'target_expr' => safe_text((string) ($bomRuleTargetExpr[$compId] ?? 'kwp * 1000')),
                        'allow_overbuild_pct' => (float) ($bomRuleOverbuild[$compId] ?? 0),
                        'requires_variants' => true,
                    ],
                    'manual_note' => safe_text((string) ($bomManualNote[$compId] ?? '')),
                ], $component);

                if ($line['mode'] === 'fixed_qty' && (float) ($line['fixed_qty'] ?? 0) <= 0) {
                    $redirectDocuments('items', 'error', 'Fixed quantity must be greater than 0.', ['items_subtab' => 'kits', 'edit' => $editing ? $kitId : '']);
                }
                if ($line['mode'] === 'capacity_qty') {
                    $capType = (string) (($line['capacity_rule']['type'] ?? 'formula'));
                    if ($capType === 'formula') {
                        $expr = (string) ($line['capacity_rule']['expr'] ?? '');
                        if ($expr === '') {
                            $redirectDocuments('items', 'error', 'Capacity formula is required.', ['items_subtab' => 'kits', 'edit' => $editing ? $kitId : '']);
                        }
                        $exprCheck = documents_evaluate_safe_expression($expr, 1.0);
                        if (!($exprCheck['ok'] ?? false)) {
                            $redirectDocuments('items', 'error', 'Capacity formula is invalid.', ['items_subtab' => 'kits', 'edit' => $editing ? $kitId : '']);
                        }
                    } else {
                        $mins = is_array($bomSlabMin[$compId] ?? null) ? $bomSlabMin[$compId] : [];
                        $maxs = is_array($bomSlabMax[$compId] ?? null) ? $bomSlabMax[$compId] : [];
                        $qtys = is_array($bomSlabQty[$compId] ?? null) ? $bomSlabQty[$compId] : [];
                        $slabs = [];
                        $count = max(count($mins), count($maxs), count($qtys));
                        for ($si = 0; $si < $count; $si++) {
                            $sq = (float) ($qtys[$si] ?? 0);
                            if ($sq <= 0) {
                                continue;
                            }
                            $slabs[] = [
                                'kwp_min' => (float) ($mins[$si] ?? 0),
                                'kwp_max' => (float) ($maxs[$si] ?? 0),
                                'qty' => $sq,
                            ];
                        }
                        if ($slabs === []) {
                            $redirectDocuments('items', 'error', 'At least one slab is required for slab capacity mode.', ['items_subtab' => 'kits', 'edit' => $editing ? $kitId : '']);
                        }
                        $line['capacity_rule']['slabs'] = $slabs;
                    }
                }
                if ($line['mode'] === 'rule_fulfillment') {
                    $targetExpr = (string) ($line['rule']['target_expr'] ?? '');
                    if ($targetExpr === '') {
                        $redirectDocuments('items', 'error', 'Rule target expression is required.', ['items_subtab' => 'kits', 'edit' => $editing ? $kitId : '']);
                    }
                    $exprCheck = documents_evaluate_safe_expression($targetExpr, 1.0);
                    if (!($exprCheck['ok'] ?? false)) {
                        $redirectDocuments('items', 'error', 'Rule target expression is invalid.', ['items_subtab' => 'kits', 'edit' => $editing ? $kitId : '']);
                    }
                }

                $items[] = $line;
            }
            $row['items'] = $items;
            $savedRows = [];
            $updated = false;
            foreach ($kits as $kit) {
                if ((string) ($kit['id'] ?? '') === (string) ($row['id'] ?? '')) {
                    $savedRows[] = $row;
                    $updated = true;
                } else {
                    $savedRows[] = $kit;
                }
            }
            if (!$updated) {
                $savedRows[] = $row;
            }
            $result = documents_inventory_save_kits($savedRows);
            $msg = $editing ? 'Kit updated.' : 'Kit created.';
            $redirectDocuments('items', $result['ok'] ? 'success' : 'error', $result['ok'] ? $msg : 'Failed to save kit.', ['items_subtab' => 'kits']);
        }

        if (in_array($action, ['save_tax_profile', 'save_tax_profile_edit'], true)) {
            $editing = $action === 'save_tax_profile_edit';
            $profileId = safe_text((string) ($_POST['tax_profile_id'] ?? ''));
            $rows = documents_inventory_tax_profiles(true);
            $row = documents_tax_profile_defaults();
            if ($profileId !== '') {
                foreach ($rows as $existing) {
                    if ((string) ($existing['id'] ?? '') === $profileId) {
                        $row = array_merge($row, $existing);
                        break;
                    }
                }
            } else {
                $row['id'] = 'TAX-' . date('Y') . '-' . bin2hex(random_bytes(2));
                $row['created_at'] = date('c');
            }
            if ($editing && ((string) ($row['id'] ?? '') !== $profileId || $profileId === '')) {
                $redirectDocuments('items', 'error', 'Tax profile not found for edit.', ['items_subtab' => 'tax_profiles']);
            }
            $row['name'] = safe_text((string) ($_POST['name'] ?? ''));
            $row['mode'] = safe_text((string) ($_POST['mode'] ?? 'single'));
            $row['notes'] = safe_text((string) ($_POST['notes'] ?? ''));
            $slabShares = is_array($_POST['slab_share_pct'] ?? null) ? $_POST['slab_share_pct'] : [];
            $slabRates = is_array($_POST['slab_rate_pct'] ?? null) ? $_POST['slab_rate_pct'] : [];
            $slabs = [];
            $count = max(count($slabShares), count($slabRates));
            for ($i = 0; $i < $count; $i++) {
                if ($row['mode'] === 'single' && $i > 0) {
                    break;
                }
                $slabs[] = [
                    'share_pct' => $row['mode'] === 'single' ? 100 : (float) ($slabShares[$i] ?? 0),
                    'rate_pct' => (float) ($slabRates[$i] ?? 0),
                ];
            }
            $row['slabs'] = $slabs;
            $row['updated_at'] = date('c');
            $validated = documents_validate_tax_profile($row);
            if (!($validated['ok'] ?? false)) {
                $redirectDocuments('items', 'error', (string) ($validated['error'] ?? 'Invalid tax profile.'), ['items_subtab' => 'tax_profiles', 'edit' => $editing ? $profileId : '']);
            }
            $row = (array) ($validated['profile'] ?? $row);

            $savedRows = [];
            $updated = false;
            foreach ($rows as $existing) {
                if ((string) ($existing['id'] ?? '') === (string) ($row['id'] ?? '')) {
                    $savedRows[] = $row;
                    $updated = true;
                } else {
                    $savedRows[] = $existing;
                }
            }
            if (!$updated) {
                $savedRows[] = $row;
            }
            $result = documents_inventory_save_tax_profiles($savedRows);
            $msg = $editing ? 'Tax profile updated.' : 'Tax profile saved.';
            $redirectDocuments('items', $result['ok'] ? 'success' : 'error', $result['ok'] ? $msg : 'Failed to save tax profile.', ['items_subtab' => 'tax_profiles']);
        }

        if ($action === 'toggle_tax_profile_archive') {
            $profileId = safe_text((string) ($_POST['tax_profile_id'] ?? ''));
            $archiveState = safe_text((string) ($_POST['archive_state'] ?? 'archive'));
            $rows = documents_inventory_tax_profiles(true);
            foreach ($rows as &$row) {
                if ((string) ($row['id'] ?? '') === $profileId) {
                    $row['archived_flag'] = $archiveState === 'archive';
                    $row['updated_at'] = date('c');
                }
            }
            unset($row);
            $result = documents_inventory_save_tax_profiles($rows);
            $redirectDocuments('items', $result['ok'] ? 'success' : 'error', $result['ok'] ? 'Tax profile archive state updated.' : 'Failed to update tax profile.', ['items_subtab' => 'tax_profiles']);
        }

        if (in_array($action, ['save_variant', 'save_variant_edit'], true)) {
            $editing = $action === 'save_variant_edit';
            $variantId = safe_text((string) ($_POST['variant_id'] ?? ''));
            $rows = documents_inventory_component_variants(true);
            $row = documents_component_variant_defaults();
            if ($variantId !== '') {
                foreach ($rows as $existing) {
                    if ((string) ($existing['id'] ?? '') === $variantId) {
                        $row = array_merge($row, $existing);
                        break;
                    }
                }
            } else {
                $row['id'] = $generateInventoryEntityId('VAR', $rows);
                $row['created_at'] = date('c');
            }
            if ($editing && ((string) ($row['id'] ?? '') !== $variantId || $variantId === '')) {
                $redirectDocuments('items', 'error', 'Variant not found for edit.', ['items_subtab' => 'variants']);
            }
            $row['component_id'] = safe_text((string) ($_POST['component_id'] ?? ''));
            if ($row['component_id'] === '') {
                $redirectDocuments('items', 'error', 'Component is required for variant.', ['items_subtab' => 'variants']);
            }
            $row['brand'] = safe_text((string) ($_POST['brand'] ?? ''));
            $row['technology'] = safe_text((string) ($_POST['technology'] ?? ''));
            $row['wattage_wp'] = max(0, (float) ($_POST['wattage_wp'] ?? 0));
            $row['model_no'] = safe_text((string) ($_POST['model_no'] ?? ''));
            $row['display_name'] = safe_text((string) ($_POST['display_name'] ?? ''));
            if ($row['display_name'] === '') {
                $bits = array_filter([$row['brand'], $row['technology'], $row['wattage_wp'] > 0 ? (string) ($row['wattage_wp'] . 'Wp') : ''], static fn($v): bool => (string) $v !== '');
                $row['display_name'] = $bits !== [] ? implode(' ', $bits) : 'Variant';
            }
            $row['hsn_override'] = safe_text((string) ($_POST['hsn_override'] ?? ''));
            $row['tax_profile_id_override'] = safe_text((string) ($_POST['tax_profile_id_override'] ?? ''));
            $row['default_unit_override'] = safe_text((string) ($_POST['default_unit_override'] ?? ''));
            $row['notes'] = safe_text((string) ($_POST['notes'] ?? ''));
            $row['updated_at'] = date('c');

            $savedRows = [];
            $updated = false;
            foreach ($rows as $existing) {
                if ((string) ($existing['id'] ?? '') === (string) ($row['id'] ?? '')) {
                    $savedRows[] = $row;
                    $updated = true;
                } else {
                    $savedRows[] = $existing;
                }
            }
            if (!$updated) {
                $savedRows[] = $row;
            }
            $result = documents_inventory_save_component_variants($savedRows);
            $msg = $editing ? 'Variant updated.' : 'Variant saved.';
            $redirectDocuments('items', $result['ok'] ? 'success' : 'error', $result['ok'] ? $msg : 'Failed to save variant.', ['items_subtab' => 'variants', 'component_filter' => (string) $row['component_id']]);
        }

        if ($action === 'toggle_variant_archive') {
            $variantId = safe_text((string) ($_POST['variant_id'] ?? ''));
            $archiveState = safe_text((string) ($_POST['archive_state'] ?? 'archive'));
            $rows = documents_inventory_component_variants(true);
            $componentFilter = '';
            foreach ($rows as &$row) {
                if ((string) ($row['id'] ?? '') === $variantId) {
                    $row['archived_flag'] = $archiveState === 'archive';
                    $row['updated_at'] = date('c');
                    $componentFilter = (string) ($row['component_id'] ?? '');
                }
            }
            unset($row);
            $result = documents_inventory_save_component_variants($rows);
            $redirectDocuments('items', $result['ok'] ? 'success' : 'error', $result['ok'] ? 'Variant archive state updated.' : 'Failed to update variant.', ['items_subtab' => 'variants', 'component_filter' => $componentFilter]);
        }

        if (in_array($action, ['save_location', 'save_location_edit'], true)) {
            $editing = $action === 'save_location_edit';
            $locationId = safe_text((string) ($_POST['location_id'] ?? ''));
            $rows = documents_inventory_locations(true);
            $row = documents_inventory_location_defaults();
            if ($locationId !== '') {
                foreach ($rows as $existing) {
                    if ((string) ($existing['id'] ?? '') === $locationId) {
                        $row = array_merge($row, $existing);
                        break;
                    }
                }
            } else {
                $row['id'] = $generateInventoryEntityId('LOC', $rows);
                $row['created_at'] = date('c');
            }
            if ($editing && ((string) ($row['id'] ?? '') !== $locationId || $locationId === '')) {
                $redirectDocuments('items', 'error', 'Location not found for edit.', ['items_subtab' => 'locations']);
            }
            $row['name'] = safe_text((string) ($_POST['name'] ?? ''));
            if ($row['name'] === '') {
                $redirectDocuments('items', 'error', 'Location name is required.', ['items_subtab' => 'locations']);
            }
            $row['type'] = safe_text((string) ($_POST['type'] ?? ''));
            $row['notes'] = safe_text((string) ($_POST['notes'] ?? ''));
            $row['updated_at'] = date('c');

            $savedRows = [];
            $updated = false;
            foreach ($rows as $existing) {
                if ((string) ($existing['id'] ?? '') === (string) ($row['id'] ?? '')) {
                    $savedRows[] = $row;
                    $updated = true;
                } else {
                    $savedRows[] = $existing;
                }
            }
            if (!$updated) {
                $savedRows[] = $row;
            }
            $result = documents_inventory_save_locations($savedRows);
            $msg = $editing ? 'Location updated.' : 'Location saved.';
            $redirectDocuments('items', $result['ok'] ? 'success' : 'error', $result['ok'] ? $msg : 'Failed to save location.', ['items_subtab' => 'locations']);
        }

        if ($action === 'toggle_location_archive') {
            $locationId = safe_text((string) ($_POST['location_id'] ?? ''));
            $archiveState = safe_text((string) ($_POST['archive_state'] ?? 'archive'));
            $rows = documents_inventory_locations(true);
            foreach ($rows as &$row) {
                if ((string) ($row['id'] ?? '') === $locationId) {
                    $row['archived_flag'] = $archiveState === 'archive';
                    $row['updated_at'] = date('c');
                }
            }
            unset($row);
            $result = documents_inventory_save_locations($rows);
            $redirectDocuments('items', $result['ok'] ? 'success' : 'error', $result['ok'] ? 'Location archive state updated.' : 'Failed to update location.', ['items_subtab' => 'locations']);
        }

        if ($action === 'toggle_kit_archive') {
            $kitId = safe_text((string) ($_POST['kit_id'] ?? ''));
            $archiveState = safe_text((string) ($_POST['archive_state'] ?? 'archive'));
            $kits = documents_inventory_kits(true);
            foreach ($kits as &$kit) {
                if ((string) ($kit['id'] ?? '') === $kitId) {
                    $kit['archived_flag'] = $archiveState === 'archive';
                    $kit['updated_at'] = date('c');
                }
            }
            unset($kit);
            $result = documents_inventory_save_kits($kits);
            $redirectDocuments('items', $result['ok'] ? 'success' : 'error', $result['ok'] ? 'Kit archive state updated.' : 'Failed to update kit.', ['items_subtab' => 'kits']);
        }



        if (in_array($action, ['inventory_verification_update', 'inventory_verification_bulk_update'], true)) {
            if (!$isAdmin) {
                $redirectDocuments('items', 'error', 'Access denied.', ['items_subtab' => 'inventory_verification']);
            }

            $transactions = documents_inventory_load_transactions();
            $sync = documents_inventory_sync_verification_log($transactions, true);
            $verificationRows = (array) ($sync['rows'] ?? []);
            $verificationByTxn = [];
            foreach ($verificationRows as $ix => $row) {
                $txnId = (string) ($row['txn_id'] ?? '');
                if ($txnId !== '') {
                    $verificationByTxn[$txnId] = $ix;
                }
            }
            $adminActor = documents_inventory_actor($user ?? []);
            $now = date('c');

            $applyStatus = static function (array &$record, string $status, string $note, array $actor, string $at): void {
                $record['status'] = $status;
                $record['admin_note'] = $note;
                if ($status === 'not_verified') {
                    $record['verified_by'] = null;
                    $record['verified_at'] = null;
                    return;
                }
                $record['verified_by'] = [
                    'role' => (string) ($actor['role'] ?? 'admin'),
                    'id' => (string) ($actor['id'] ?? ''),
                    'name' => (string) ($actor['name'] ?? 'Admin'),
                ];
                $record['verified_at'] = $at;
            };

            if ($action === 'inventory_verification_update') {
                $txnId = safe_text((string) ($_POST['txn_id'] ?? ''));
                $status = safe_text((string) ($_POST['verification_status'] ?? 'not_verified'));
                $adminNote = safe_text((string) ($_POST['admin_note'] ?? ''));
                if (!in_array($status, ['not_verified', 'verified', 'needs_clarification'], true)) {
                    $redirectDocuments('items', 'error', 'Invalid verification status.', ['items_subtab' => 'inventory_verification']);
                }
                if ($status === 'needs_clarification' && $adminNote === '') {
                    $redirectDocuments('items', 'error', 'Admin note is required for needs clarification.', ['items_subtab' => 'inventory_verification', 'transaction_id' => $txnId]);
                }
                if (!isset($verificationByTxn[$txnId])) {
                    $redirectDocuments('items', 'error', 'Verification record not found.', ['items_subtab' => 'inventory_verification']);
                }
                $idx = (int) $verificationByTxn[$txnId];
                $record = array_merge(documents_inventory_verification_defaults(), (array) ($verificationRows[$idx] ?? []));
                $applyStatus($record, $status, $adminNote, $adminActor, $now);
                $verificationRows[$idx] = $record;
                $saved = documents_inventory_save_verification_log($verificationRows);
                $redirectDocuments('items', ($saved['ok'] ?? false) ? 'success' : 'error', ($saved['ok'] ?? false) ? 'Verification status updated.' : 'Failed to save verification status.', ['items_subtab' => 'inventory_verification', 'transaction_id' => $txnId]);
            }

            $txnIds = isset($_POST['txn_ids']) && is_array($_POST['txn_ids']) ? array_values(array_unique(array_map(static fn($id): string => safe_text((string) $id), $_POST['txn_ids']))) : [];
            $txnIds = array_values(array_filter($txnIds, static fn(string $id): bool => $id !== ''));
            $status = safe_text((string) ($_POST['verification_status'] ?? 'verified'));
            $adminNote = safe_text((string) ($_POST['admin_note'] ?? ''));
            if ($txnIds === []) {
                $redirectDocuments('items', 'error', 'Select at least one transaction.', ['items_subtab' => 'inventory_verification']);
            }
            if (!in_array($status, ['not_verified', 'verified', 'needs_clarification'], true)) {
                $redirectDocuments('items', 'error', 'Invalid bulk status.', ['items_subtab' => 'inventory_verification']);
            }
            if ($status === 'needs_clarification' && $adminNote === '') {
                $redirectDocuments('items', 'error', 'Admin note is required for needs clarification.', ['items_subtab' => 'inventory_verification']);
            }

            $updated = 0;
            foreach ($txnIds as $txnId) {
                if (!isset($verificationByTxn[$txnId])) {
                    continue;
                }
                $idx = (int) $verificationByTxn[$txnId];
                $record = array_merge(documents_inventory_verification_defaults(), (array) ($verificationRows[$idx] ?? []));
                $applyStatus($record, $status, $adminNote, $adminActor, $now);
                $verificationRows[$idx] = $record;
                $updated++;
            }
            if ($updated <= 0) {
                $redirectDocuments('items', 'error', 'No employee verification records were updated.', ['items_subtab' => 'inventory_verification']);
            }
            $saved = documents_inventory_save_verification_log($verificationRows);
            $redirectDocuments('items', ($saved['ok'] ?? false) ? 'success' : 'error', ($saved['ok'] ?? false) ? ('Updated verification for ' . $updated . ' transaction(s).') : 'Failed to save bulk verification update.', ['items_subtab' => 'inventory_verification']);
        }

        if ($action === 'save_inventory_edits') {
            if (!$isAdmin && !$isEmployee) {
                $redirectDocuments('items', 'error', 'Access denied.', ['items_subtab' => 'inventory']);
            }

            $stock = documents_inventory_load_stock();
            $transactions = documents_inventory_load_transactions();
            $usageIndex = documents_inventory_build_usage_index($transactions);
            $componentBlocked = (array) ($usageIndex['component_blocked'] ?? []);
            $variantBlocked = (array) ($usageIndex['variant_blocked'] ?? []);
            $lotBlocked = (array) ($usageIndex['lot_blocked'] ?? []);
            $batchBlocked = (array) ($usageIndex['batch_blocked'] ?? []);
            $actor = documents_inventory_actor($user ?? []);

            $changed = [];
            $errors = [];

            $componentEdits = $isAdmin && isset($_POST['component_edits']) && is_array($_POST['component_edits']) ? $_POST['component_edits'] : [];
            foreach ($componentEdits as $componentId => $rows) {
                $componentId = safe_text((string) $componentId);
                if ($componentId === '' || !is_array($rows)) {
                    continue;
                }
                if (isset($componentBlocked[$componentId])) {
                    $errors[] = 'Component ' . $componentId . ' is used and cannot be edited.';
                    continue;
                }

                $entry = documents_inventory_component_stock($stock, $componentId, '');
                $newRows = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $locationId = safe_text((string) ($row['location_id'] ?? ''));
                    if ($locationId !== '' && documents_inventory_get_location($locationId) === null) {
                        $errors[] = 'Invalid location for component ' . $componentId . '.';
                        continue 2;
                    }
                    $qty = max(0, (float) ($row['qty'] ?? 0));
                    $newRows[] = ['location_id' => $locationId, 'qty' => $qty];
                }
                $newRows = documents_inventory_normalize_location_breakdown($newRows);
                $oldRows = documents_inventory_normalize_location_breakdown((array) ($entry['location_breakdown'] ?? []));
                if (json_encode($newRows) === json_encode($oldRows)) {
                    continue;
                }

                $entry['location_breakdown'] = $newRows;
                $entry['on_hand_qty'] = documents_inventory_location_breakdown_total($newRows);
                $entry['updated_at'] = date('c');
                documents_inventory_set_component_stock($stock, $componentId, '', $entry);

                $changed[] = [
                    'entity_type' => 'component',
                    'entity_id' => $componentId,
                    'field' => 'location_breakdown',
                    'from' => $oldRows,
                    'to' => $newRows,
                ];
            }

            $variantEdits = $isAdmin && isset($_POST['variant_edits']) && is_array($_POST['variant_edits']) ? $_POST['variant_edits'] : [];
            foreach ($variantEdits as $variantId => $payload) {
                $variantId = safe_text((string) $variantId);
                if ($variantId === '' || !is_array($payload)) {
                    continue;
                }
                if (isset($variantBlocked[$variantId])) {
                    $errors[] = 'Variant ' . $variantId . ' is used and cannot be edited.';
                    continue;
                }
                $componentId = safe_text((string) ($payload['component_id'] ?? ''));
                if ($componentId === '') {
                    $errors[] = 'Variant ' . $variantId . ' missing component.';
                    continue;
                }
                $rows = isset($payload['rows']) && is_array($payload['rows']) ? $payload['rows'] : [];
                $entry = documents_inventory_component_stock($stock, $componentId, $variantId);
                $newRows = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $locationId = safe_text((string) ($row['location_id'] ?? ''));
                    if ($locationId !== '' && documents_inventory_get_location($locationId) === null) {
                        $errors[] = 'Invalid location for variant ' . $variantId . '.';
                        continue 2;
                    }
                    $qty = max(0, (float) ($row['qty'] ?? 0));
                    $newRows[] = ['location_id' => $locationId, 'qty' => $qty];
                }
                $newRows = documents_inventory_normalize_location_breakdown($newRows);
                $oldRows = documents_inventory_normalize_location_breakdown((array) ($entry['location_breakdown'] ?? []));
                if (json_encode($newRows) === json_encode($oldRows)) {
                    continue;
                }

                $entry['location_breakdown'] = $newRows;
                $entry['on_hand_qty'] = documents_inventory_location_breakdown_total($newRows);
                $entry['updated_at'] = date('c');
                documents_inventory_set_component_stock($stock, $componentId, $variantId, $entry);

                $changed[] = [
                    'entity_type' => 'variant',
                    'entity_id' => $variantId,
                    'field' => 'location_breakdown',
                    'from' => $oldRows,
                    'to' => $newRows,
                ];
            }

            $lotEdits = isset($_POST['lot_edits']) && is_array($_POST['lot_edits']) ? $_POST['lot_edits'] : [];
            $bulkAction = safe_text((string) ($_POST['bulk_lot_action'] ?? ''));
            $bulkLotIds = array_values(array_filter(array_map(static fn($lotId): string => safe_text((string) $lotId), is_array($_POST['bulk_lot_ids'] ?? null) ? $_POST['bulk_lot_ids'] : []), static fn(string $lotId): bool => $lotId !== ''));
            $bulkLocationId = safe_text((string) ($_POST['bulk_lot_location_id'] ?? ''));
            $bulkVariantId = safe_text((string) ($_POST['bulk_lot_variant_id'] ?? ''));
            $bulkNotes = safe_text((string) ($_POST['bulk_lot_notes'] ?? ''));
            $bulkApplied = 0;
            $bulkSkipped = [];

            if ($bulkAction !== '' && $bulkLotIds !== []) {
                foreach ($stock['stock_by_component_id'] as $bulkComponentId => $componentStock) {
                    if (!is_array($componentStock) || !is_array($componentStock['stock_by_variant_id'] ?? null)) {
                        continue;
                    }
                    foreach ($componentStock['stock_by_variant_id'] as $bucketKey => $bucketEntry) {
                        $entryVariantId = $bucketKey === documents_inventory_stock_bucket_key('') ? '' : (string) $bucketKey;
                        $entry = documents_inventory_component_stock($stock, (string) $bulkComponentId, $entryVariantId);
                        $lots = is_array($entry['lots'] ?? null) ? $entry['lots'] : [];
                        $entryChanged = false;
                        foreach ($lots as $lotIdx => $lot) {
                            if (!is_array($lot)) {
                                continue;
                            }
                            $lotId = (string) ($lot['lot_id'] ?? '');
                            if ($lotId === '' || !in_array($lotId, $bulkLotIds, true)) {
                                continue;
                            }
                            $eligibility = is_lot_editable($lot, $usageIndex);
                            if (empty($eligibility['editable'])) {
                                $bulkSkipped[] = 'Lot ' . $lotId . ': ' . (string) ($eligibility['reason'] ?? 'Not eligible');
                                continue;
                            }
                            if (!$isAdmin) {
                                $lotCreator = is_array($lot['created_by'] ?? null) ? $lot['created_by'] : [];
                                if ((string) ($lotCreator['role'] ?? '') !== 'employee' || (string) ($lotCreator['id'] ?? '') !== (string) ($user['id'] ?? '')) {
                                    $bulkSkipped[] = 'Lot ' . $lotId . ': not owned by you.';
                                    continue;
                                }
                            }
                            $before = $lot;
                            if ($bulkAction === 'change_location') {
                                if ($bulkLocationId !== '' && documents_inventory_get_location($bulkLocationId) === null) {
                                    $bulkSkipped[] = 'Lot ' . $lotId . ': invalid location.';
                                    continue;
                                }
                                $lots[$lotIdx]['location_id'] = $bulkLocationId;
                            } elseif ($bulkAction === 'change_variant') {
                                if ($bulkVariantId === '') {
                                    $bulkSkipped[] = 'Lot ' . $lotId . ': variant required.';
                                    continue;
                                }
                                $variantRow = documents_inventory_get_component_variant($bulkVariantId);
                                if (!is_array($variantRow) || (string) ($variantRow['component_id'] ?? '') !== (string) $bulkComponentId) {
                                    $bulkSkipped[] = 'Lot ' . $lotId . ': invalid variant.';
                                    continue;
                                }
                                $targetEntry = documents_inventory_component_stock($stock, (string) $bulkComponentId, $bulkVariantId);
                                $lotMoved = $lots[$lotIdx];
                                unset($lots[$lotIdx]);
                                $targetLots = is_array($targetEntry['lots'] ?? null) ? $targetEntry['lots'] : [];
                                $targetLots[] = $lotMoved;
                                $targetEntry['lots'] = array_values($targetLots);
                                $targetEntry['updated_at'] = date('c');
                                documents_inventory_set_component_stock($stock, (string) $bulkComponentId, $bulkVariantId, $targetEntry);
                            }
                            if ($bulkNotes !== '') {
                                $lots[$lotIdx]['notes'] = $bulkNotes;
                            }
                            $changed[] = ['entity_type' => 'lot', 'entity_id' => $lotId, 'field' => 'bulk_edit', 'from' => $before, 'to' => $lots[$lotIdx] ?? ['moved_to_variant' => $bulkVariantId], 'bulk_action' => $bulkAction];
                            $entryChanged = true;
                            $bulkApplied++;
                        }
                        if ($entryChanged) {
                            $entry['lots'] = array_values($lots);
                            $entry['updated_at'] = date('c');
                            documents_inventory_set_component_stock($stock, (string) $bulkComponentId, $entryVariantId, $entry);
                        }
                    }
                }
            }

            foreach ($lotEdits as $key => $payload) {
                if (!is_array($payload)) {
                    continue;
                }
                $componentId = safe_text((string) ($payload['component_id'] ?? ''));
                $variantId = safe_text((string) ($payload['variant_id'] ?? ''));
                $lotId = safe_text((string) ($payload['lot_id'] ?? ''));
                $newLocationId = safe_text((string) ($payload['location_id'] ?? ''));
                $newVariantId = safe_text((string) ($payload['variant_id'] ?? ''));
                $newRemainingFt = max(0, (float) ($payload['remaining_length_ft'] ?? 0));
                $newOriginalFt = max(0, (float) ($payload['original_length_ft'] ?? 0));
                $newNotes = safe_text((string) ($payload['notes'] ?? ''));
                if ($componentId === '' || $lotId === '') {
                    continue;
                }
                if ($newLocationId !== '' && documents_inventory_get_location($newLocationId) === null) {
                    $errors[] = 'Invalid location for lot ' . $lotId . '.';
                    continue;
                }
                $entry = documents_inventory_component_stock($stock, $componentId, $variantId);
                $lots = is_array($entry['lots'] ?? null) ? $entry['lots'] : [];
                $found = false;
                foreach ($lots as $lotIdx => $lot) {
                    if (!is_array($lot)) {
                        continue;
                    }
                    if ((string) ($lot['lot_id'] ?? '') !== $lotId) {
                        continue;
                    }
                    $found = true;
                    $oldLocationId = (string) ($lot['location_id'] ?? '');
                    if ($oldLocationId === $newLocationId) {
                        break;
                    }
                    if (!$isAdmin) {
                        $lotCreator = is_array($lot['created_by'] ?? null) ? $lot['created_by'] : [];
                        if ((string) ($lotCreator['role'] ?? '') !== 'employee' || (string) ($lotCreator['id'] ?? '') !== (string) ($user['id'] ?? '')) {
                            $errors[] = 'Lot ' . $lotId . ' is not owned by you.';
                            continue 2;
                        }
                    }
                    $eligibility = is_lot_editable($lot, $usageIndex);
                    if (empty($eligibility['editable'])) {
                        $errors[] = 'Lot ' . $lotId . ' cannot be edited. ' . (string) ($eligibility['reason'] ?? '');
                        continue 2;
                    }
                    if (abs($newRemainingFt - $newOriginalFt) > 0.00001) {
                        $errors[] = 'Lot ' . $lotId . ' requires remaining ft equal to original ft.';
                        continue 2;
                    }
                    $lot['location_id'] = $newLocationId;
                    $lot['remaining_length_ft'] = $newRemainingFt;
                    $lot['original_length_ft'] = $newOriginalFt;
                    $lot['notes'] = $newNotes;
                    $changed[] = [
                        'entity_type' => 'lot',
                        'entity_id' => $lotId,
                        'field' => 'lot_metadata',
                        'from' => ['location_id' => $oldLocationId],
                        'to' => ['location_id' => $newLocationId, 'remaining_length_ft' => $newRemainingFt, 'original_length_ft' => $newOriginalFt, 'notes' => $newNotes],
                    ];

                    if ($newVariantId !== '' && $newVariantId !== $variantId) {
                        $variant = documents_inventory_get_component_variant($newVariantId);
                        if (!is_array($variant) || (string) ($variant['component_id'] ?? '') !== $componentId) {
                            $errors[] = 'Invalid variant for lot ' . $lotId . '.';
                            continue 2;
                        }
                        $targetEntry = documents_inventory_component_stock($stock, $componentId, $newVariantId);
                        $targetLots = is_array($targetEntry['lots'] ?? null) ? $targetEntry['lots'] : [];
                        $targetLots[] = $lot;
                        unset($lots[$lotIdx]);
                        $targetEntry['lots'] = array_values($targetLots);
                        $targetEntry['updated_at'] = date('c');
                        documents_inventory_set_component_stock($stock, $componentId, $newVariantId, $targetEntry);
                    } else {
                        $lots[$lotIdx] = $lot;
                    }
                    break;
                }
                if (!$found) {
                    $errors[] = 'Lot ' . $lotId . ' not found.';
                    continue;
                }

                $entry['lots'] = $lots;
                $entry['updated_at'] = date('c');
                documents_inventory_set_component_stock($stock, $componentId, $variantId, $entry);
            }


            $batchEdits = isset($_POST['batch_edits']) && is_array($_POST['batch_edits']) ? $_POST['batch_edits'] : [];
            foreach ($batchEdits as $batchKey => $payload) {
                if (!is_array($payload)) {
                    continue;
                }
                $componentId = safe_text((string) ($payload['component_id'] ?? ''));
                $variantId = safe_text((string) ($payload['variant_id'] ?? ''));
                $batchId = safe_text((string) ($payload['batch_id'] ?? ''));
                $newLocationId = safe_text((string) ($payload['location_id'] ?? ''));
                if ($componentId === '' || $batchId === '') {
                    continue;
                }
                if ($newLocationId !== '' && documents_inventory_get_location($newLocationId) === null) {
                    $errors[] = 'Invalid location for batch ' . $batchId . '.';
                    continue;
                }
                if (isset($batchBlocked[$batchId])) {
                    $errors[] = 'Batch ' . $batchId . ' has already been moved/consumed and cannot be edited.';
                    continue;
                }
                $entry = documents_inventory_component_stock($stock, $componentId, $variantId);
                $batches = is_array($entry['batches'] ?? null) ? $entry['batches'] : [];
                $found = false;
                foreach ($batches as &$batch) {
                    if (!is_array($batch) || (string) ($batch['batch_id'] ?? '') !== $batchId) {
                        continue;
                    }
                    $found = true;
                    if (!$isAdmin) {
                        $owner = is_array($batch['created_by'] ?? null) ? $batch['created_by'] : [];
                        if ((string) ($owner['role'] ?? '') !== 'employee' || (string) ($owner['id'] ?? '') !== (string) ($user['id'] ?? '')) {
                            $errors[] = 'Batch ' . $batchId . ' is not owned by you.';
                            continue 2;
                        }
                    }
                    $oldLocationId = (string) ($batch['location_id'] ?? '');
                    if ($oldLocationId === $newLocationId) {
                        break;
                    }
                    $batch['location_id'] = $newLocationId;
                    $changed[] = ['entity_type' => 'batch', 'entity_id' => $batchId, 'field' => 'location_id', 'from' => $oldLocationId, 'to' => $newLocationId];
                    break;
                }
                unset($batch);
                if (!$found) {
                    $errors[] = 'Batch ' . $batchId . ' not found.';
                    continue;
                }
                $entry['batches'] = $batches;
                $entry['updated_at'] = date('c');
                documents_inventory_set_component_stock($stock, $componentId, $variantId, $entry);
            }

            if ($changed !== []) {
                $saved = documents_inventory_save_stock($stock);
                if (!($saved['ok'] ?? false)) {
                    $redirectDocuments('items', 'error', 'Failed to save stock edits.', ['items_subtab' => 'inventory', 'edit_mode' => '1']);
                }
                $logEntry = [
                    'id' => 'ied_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)),
                    'at' => date('c'),
                    'by' => $actor,
                    'changes' => array_values($changed),
                    'note' => safe_text((string) ($_POST['edit_note'] ?? '')),
                ];
                $savedLog = documents_inventory_append_edits_log($logEntry);
                if (!($savedLog['ok'] ?? false)) {
                    $redirectDocuments('items', 'error', 'Stock updated but failed to write audit log.', ['items_subtab' => 'inventory']);
                }
            }

            $message = 'Inventory edits saved.';
            if ($changed === []) {
                $message = 'No eligible changes were detected.';
            }
            if ($errors !== []) {
                $message .= ' Some edits were blocked: ' . implode(' ', $errors);
            }
            if ($bulkAction !== '' && $bulkLotIds !== []) {
                $message .= ' Bulk edit applied to ' . $bulkApplied . ' lot(s).';
                if ($bulkSkipped !== []) {
                    $message .= ' Skipped ' . count($bulkSkipped) . ' lot(s): ' . implode(' ', $bulkSkipped);
                }
            }
            $status = $errors === [] ? 'success' : 'error';
            $redirectDocuments('items', $status, $message, ['items_subtab' => 'inventory', 'edit_mode' => '1']);
        }

        if (in_array($action, ['create_inventory_tx', 'edit_inventory_tx'], true)) {
            $transactionId = safe_text((string) ($_POST['transaction_id'] ?? ''));
            $txType = strtoupper(safe_text((string) ($_POST['tx_type'] ?? 'IN')));
            if (!in_array($txType, ['IN', 'OUT', 'MOVE', 'ADJUST'], true)) {
                $redirectDocuments('items', 'error', 'Invalid transaction type.', ['items_subtab' => 'inventory']);
            }
            if ($txType === 'ADJUST' && !$isAdmin) {
                $redirectDocuments('items', 'error', 'Access denied.', ['items_subtab' => 'inventory']);
            }

            $componentId = safe_text((string) ($_POST['component_id'] ?? ''));
            $component = documents_inventory_get_component($componentId);
            if ($component === null) {
                $redirectDocuments('items', 'error', 'Component not found.', ['items_subtab' => 'inventory']);
            }

            $variantId = safe_text((string) ($_POST['variant_id'] ?? ''));
            $variantNameSnapshot = '';
            if (!empty($component['has_variants'])) {
                if ($variantId === '') {
                    $redirectDocuments('items', 'error', 'Variant is required for this component.', ['items_subtab' => 'inventory']);
                }
                $variant = documents_inventory_get_component_variant($variantId);
                if ($variant === null || (string) ($variant['component_id'] ?? '') !== $componentId || !empty($variant['archived_flag'])) {
                    $redirectDocuments('items', 'error', 'Invalid variant selected.', ['items_subtab' => 'inventory']);
                }
                $variantNameSnapshot = trim((string) ($variant['display_name'] ?? ''));
                $variantWattage = max(0, (float) ($variant['wattage_wp'] ?? 0));
                if ($variantWattage > 0) {
                    $variantNameSnapshot .= ' (' . rtrim(rtrim((string) $variantWattage, '0'), '.') . 'Wp)';
                }
            } else {
                $variantId = '';
            }

            $allTransactions = documents_inventory_load_transactions();
            $editingIndex = -1;
            $existingTx = null;
            if ($action === 'edit_inventory_tx') {
                foreach ($allTransactions as $idx => $row) {
                    if ((string) ($row['id'] ?? '') === $transactionId) {
                        $editingIndex = $idx;
                        $existingTx = array_merge(documents_inventory_transaction_defaults(), is_array($row) ? $row : []);
                        break;
                    }
                }
                if ($editingIndex < 0 || !is_array($existingTx)) {
                    $redirectDocuments('items', 'error', 'Transaction not found.', ['items_subtab' => 'transactions']);
                }
                if (!$isAdmin) {
                    $isOwner = ((string) (($existingTx['created_by']['id'] ?? '')) === (string) ($user['id'] ?? ''));
                    $isManual = ((string) ($existingTx['ref_type'] ?? 'manual')) === 'manual' && (string) ($existingTx['ref_id'] ?? '') === '';
                    $createdAt = strtotime((string) ($existingTx['created_at'] ?? '')) ?: 0;
                    $withinWindow = $createdAt > 0 && (time() - $createdAt) <= 600;
                    if (!$isOwner || !$isManual || !$withinWindow) {
                        $redirectDocuments('items', 'error', 'You can edit only your own manual transactions within 10 minutes.', ['items_subtab' => 'transactions']);
                    }
                    if ((string) ($existingTx['type'] ?? '') === 'ADJUST' || $txType === 'ADJUST') {
                        $redirectDocuments('items', 'error', 'Access denied.', ['items_subtab' => 'transactions']);
                    }
                }
            }

            $stock = documents_inventory_load_stock();
            $actor = documents_inventory_actor($user ?? []);
            $unit = !empty($component['is_cuttable']) ? 'ft' : (string) ($component['default_unit'] ?? 'qty');
            $notes = safe_text((string) ($_POST['notes'] ?? ''));
            $reason = safe_text((string) ($_POST['reason'] ?? ''));
            $refType = safe_text((string) ($_POST['ref_type'] ?? 'manual'));
            $refId = safe_text((string) ($_POST['ref_id'] ?? ''));
            $locationId = safe_text((string) ($_POST['location_id'] ?? ''));
            $consumeLocationId = safe_text((string) ($_POST['consume_location_id'] ?? ''));
            $fromLocationId = safe_text((string) ($_POST['from_location_id'] ?? ''));
            $toLocationId = safe_text((string) ($_POST['to_location_id'] ?? ''));
            $moveBatchId = safe_text((string) ($_POST['move_batch_id'] ?? ''));
            $moveLotId = safe_text((string) ($_POST['move_lot_id'] ?? ''));
            if ($locationId !== '' && documents_inventory_get_location($locationId) === null) {
                $redirectDocuments('items', 'error', 'Invalid location selected.', ['items_subtab' => 'inventory']);
            }
            if ($consumeLocationId !== '' && documents_inventory_get_location($consumeLocationId) === null) {
                $redirectDocuments('items', 'error', 'Invalid consume location selected.', ['items_subtab' => 'inventory']);
            }
            if ($fromLocationId !== '' && documents_inventory_get_location($fromLocationId) === null) {
                $redirectDocuments('items', 'error', 'Invalid from location selected.', ['items_subtab' => 'inventory']);
            }
            if ($toLocationId !== '' && documents_inventory_get_location($toLocationId) === null) {
                $redirectDocuments('items', 'error', 'Invalid to location selected.', ['items_subtab' => 'inventory']);
            }

            $applyTx = static function (array $stockState, array $componentRow, string $variant, array $txRow): array {
                $entry = documents_inventory_component_stock($stockState, (string) ($componentRow['id'] ?? ''), $variant);
                $isCuttable = !empty($componentRow['is_cuttable']);
                $txLocationId = trim((string) ($txRow['location_id'] ?? ''));
                $txConsumeLocationId = trim((string) ($txRow['consume_location_id'] ?? ''));

                if ((string) ($txRow['type'] ?? '') === 'IN') {
                    if ($isCuttable) {
                        $pieceCountRaw = trim((string) ($txRow['piece_count'] ?? ''));
                        $pieceLengthRaw = trim((string) ($txRow['piece_length_ft'] ?? ''));
                        if ($pieceCountRaw === '' || $pieceLengthRaw === '') {
                            return ['ok' => false, 'error' => 'Piece count and piece length are required.'];
                        }
                        if (!preg_match('/^\d+$/', $pieceCountRaw)) {
                            return ['ok' => false, 'error' => 'Piece count must be an integer of at least 1.'];
                        }

                        $pieceCount = (int) $pieceCountRaw;
                        $pieceLength = (float) str_replace(',', '.', $pieceLengthRaw);
                        if ($pieceCount < 1) {
                            return ['ok' => false, 'error' => 'Piece count must be an integer of at least 1.'];
                        }
                        if ($pieceLength <= 0) {
                            return ['ok' => false, 'error' => 'Piece length must be greater than zero.'];
                        }
                        $pieceLength = round($pieceLength, 2);
                        $createdLots = [];
                        for ($i = 0; $i < $pieceCount; $i++) {
                            $lot = [
                                'lot_id' => 'LOT-' . date('YmdHis') . '-' . bin2hex(random_bytes(2)),
                                'received_at' => date('c'),
                                'source_ref' => (string) ($txRow['ref_id'] ?? ''),
                                'original_length_ft' => $pieceLength,
                                'remaining_length_ft' => $pieceLength,
                                'location_id' => $txLocationId,
                                'notes' => (string) ($txRow['notes'] ?? ''),
                                'created_at' => date('c'),
                                'created_by' => is_array($txRow['actor'] ?? null) ? $txRow['actor'] : ['role' => 'admin', 'id' => '', 'name' => 'Unknown'],
                            ];
                            $entry['lots'][] = $lot;
                            $createdLots[] = $lot;
                        }
                        $entry['updated_at'] = date('c');
                        documents_inventory_set_component_stock($stockState, (string) ($componentRow['id'] ?? ''), $variant, $entry);
                        return ['ok' => true, 'stock' => $stockState, 'tx' => ['length_ft' => $pieceCount * $pieceLength, 'lots_created' => $createdLots, 'lot_consumption' => [], 'location_consumption' => [], 'location_id' => $txLocationId, 'consume_location_id' => '', 'qty' => 0, 'unit' => 'ft']];
                    }

                    $qty = max(0, (float) ($txRow['qty'] ?? 0));
                    if ($qty <= 0) {
                        return ['ok' => false, 'error' => 'Quantity must be greater than zero.'];
                    }
                    $batch = documents_inventory_create_batch($txLocationId, $qty, is_array($txRow['actor'] ?? null) ? $txRow['actor'] : ['role' => 'admin', 'id' => '', 'name' => 'Unknown'], (string) ($txRow['source_txn_id'] ?? ''));
                    $entryBatches = is_array($entry['batches'] ?? null) ? $entry['batches'] : [];
                    $entryBatches[] = $batch;
                    $entry['batches'] = $entryBatches;
                    $entry['location_breakdown'] = [];
                    foreach ($entryBatches as $oneBatch) {
                        if (!is_array($oneBatch)) {
                            continue;
                        }
                        $entry['location_breakdown'][] = ['location_id' => (string) ($oneBatch['location_id'] ?? ''), 'qty' => (float) ($oneBatch['qty_remaining'] ?? 0)];
                    }
                    $entry['location_breakdown'] = documents_inventory_normalize_location_breakdown($entry['location_breakdown']);
                    $entry['on_hand_qty'] = documents_inventory_location_breakdown_total($entry['location_breakdown']);
                    $entry['updated_at'] = date('c');
                    documents_inventory_set_component_stock($stockState, (string) ($componentRow['id'] ?? ''), $variant, $entry);
                    return ['ok' => true, 'stock' => $stockState, 'tx' => ['qty' => $qty, 'unit' => (string) ($componentRow['default_unit'] ?? 'qty'), 'length_ft' => 0, 'lots_created' => [], 'lot_consumption' => [], 'location_consumption' => [], 'location_id' => $txLocationId, 'consume_location_id' => '', 'batches_created' => [$batch], 'batch_consumption' => [], 'batch_ids' => []]];
                }

                if ((string) ($txRow['type'] ?? '') === 'OUT') {
                    if ($isCuttable) {
                        $requiredFt = max(0, (float) ($txRow['length_ft'] ?? 0));
                        if ($requiredFt <= 0) {
                            return ['ok' => false, 'error' => 'Length is required.'];
                        }
                        if (!documents_inventory_has_sufficient(array_merge($componentRow, ['id' => (string) ($componentRow['id'] ?? '')]), ['stock_by_component_id' => [(string) ($componentRow['id'] ?? '') => ['stock_by_variant_id' => [documents_inventory_stock_bucket_key($variant) => $entry]]]], $requiredFt)) {
                            return ['ok' => false, 'error' => 'Insufficient stock.'];
                        }
                        $consumed = documents_inventory_consume_fifo_lots((array) ($entry['lots'] ?? []), $requiredFt);
                        if (!($consumed['ok'] ?? false)) {
                            return ['ok' => false, 'error' => 'Insufficient lot stock.'];
                        }
                        $entry['lots'] = (array) ($consumed['lots'] ?? []);
                        $entry['updated_at'] = date('c');
                        documents_inventory_set_component_stock($stockState, (string) ($componentRow['id'] ?? ''), $variant, $entry);
                        return ['ok' => true, 'stock' => $stockState, 'tx' => ['length_ft' => $requiredFt, 'lot_consumption' => (array) ($consumed['lot_consumption'] ?? []), 'lots_created' => [], 'location_consumption' => [], 'location_id' => (string) ($txConsumeLocationId !== '' ? $txConsumeLocationId : 'mixed'), 'consume_location_id' => $txConsumeLocationId, 'qty' => 0, 'unit' => 'ft']];
                    }

                    $qty = max(0, (float) ($txRow['qty'] ?? 0));
                    if ($qty <= 0) {
                        return ['ok' => false, 'error' => 'Quantity must be greater than zero.'];
                    }
                    $consumed = documents_inventory_consume_from_batches($entry, $qty, $txConsumeLocationId);
                    if (!($consumed['ok'] ?? false)) {
                        return ['ok' => false, 'error' => (string) ($consumed['error'] ?? 'Insufficient stock.')];
                    }
                    $entry = (array) ($consumed['entry'] ?? $entry);
                    $entry['updated_at'] = date('c');
                    documents_inventory_set_component_stock($stockState, (string) ($componentRow['id'] ?? ''), $variant, $entry);
                    return ['ok' => true, 'stock' => $stockState, 'tx' => ['qty' => $qty, 'unit' => (string) ($componentRow['default_unit'] ?? 'qty'), 'length_ft' => 0, 'lots_created' => [], 'lot_consumption' => [], 'batch_consumption' => (array) ($consumed['batch_consumption'] ?? []), 'location_consumption' => (array) ($consumed['location_consumption'] ?? []), 'location_id' => $txConsumeLocationId !== '' ? $txConsumeLocationId : 'mixed', 'consume_location_id' => $txConsumeLocationId, 'batch_ids' => []]];
                }


                if ((string) ($txRow['type'] ?? '') === 'MOVE') {
                    $fromLocation = trim((string) ($txRow['from_location_id'] ?? ''));
                    $toLocation = trim((string) ($txRow['to_location_id'] ?? ''));
                    if ($toLocation === '') {
                        return ['ok' => false, 'error' => 'To location is required for move.'];
                    }
                    if ($isCuttable) {
                        $lotId = trim((string) ($txRow['move_lot_id'] ?? ''));
                        if ($lotId === '') {
                            return ['ok' => false, 'error' => 'Select lot to move.'];
                        }
                        $found = false;
                        foreach ($entry['lots'] as &$lot) {
                            if (!is_array($lot) || (string) ($lot['lot_id'] ?? '') !== $lotId) {
                                continue;
                            }
                            if ($fromLocation !== '' && (string) ($lot['location_id'] ?? '') !== $fromLocation) {
                                continue;
                            }
                            $found = true;
                            $fromLocation = (string) ($lot['location_id'] ?? '');
                            $lot['location_id'] = $toLocation;
                            break;
                        }
                        unset($lot);
                        if (!$found) {
                            return ['ok' => false, 'error' => 'Lot not found for move.'];
                        }
                        $entry['updated_at'] = date('c');
                        documents_inventory_set_component_stock($stockState, (string) ($componentRow['id'] ?? ''), $variant, $entry);
                        return ['ok' => true, 'stock' => $stockState, 'tx' => ['qty' => 0, 'unit' => 'ft', 'length_ft' => 0, 'lots_created' => [], 'lot_consumption' => [['lot_id' => $lotId, 'used_ft' => 0]], 'location_consumption' => [], 'location_id' => $toLocation, 'from_location_id' => $fromLocation, 'to_location_id' => $toLocation, 'batch_ids' => [], 'batch_consumption' => []]];
                    }

                    $moveQty = max(0, (float) ($txRow['qty'] ?? 0));
                    if ($moveQty <= 0) {
                        return ['ok' => false, 'error' => 'Move quantity must be greater than zero.'];
                    }
                    $batchId = trim((string) ($txRow['move_batch_id'] ?? ''));
                    $batches = is_array($entry['batches'] ?? null) ? $entry['batches'] : [];
                    $found = false;
                    $movedBatchIds = [];
                    foreach ($batches as &$batch) {
                        if (!is_array($batch)) { continue; }
                        if ($batchId !== '' && (string) ($batch['batch_id'] ?? '') !== $batchId) { continue; }
                        if ($fromLocation !== '' && (string) ($batch['location_id'] ?? '') !== $fromLocation) { continue; }
                        $availableQty = max(0, (float) ($batch['qty_remaining'] ?? 0));
                        if ($availableQty + 0.00001 < $moveQty) { return ['ok' => false, 'error' => 'Insufficient qty in selected batch.']; }
                        $found = true;
                        $fromLocation = (string) ($batch['location_id'] ?? '');
                        if (abs($availableQty - $moveQty) <= 0.00001) {
                            $batch['location_id'] = $toLocation;
                            $movedBatchIds[] = (string) ($batch['batch_id'] ?? '');
                        } else {
                            $batch['qty_remaining'] = $availableQty - $moveQty;
                            $newBatch = documents_inventory_create_batch($toLocation, $moveQty, (array) ($batch['created_by'] ?? []), (string) ($batch['source_txn_id'] ?? ''));
                            $newBatch['created_at'] = (string) ($batch['created_at'] ?? date('c'));
                            $batches[] = $newBatch;
                            $movedBatchIds[] = (string) ($batch['batch_id'] ?? '');
                        }
                        break;
                    }
                    unset($batch);
                    if (!$found) {
                        return ['ok' => false, 'error' => 'Batch not found for move.'];
                    }
                    $entry['batches'] = $batches;
                    $entry['location_breakdown'] = [];
                    foreach ($batches as $oneBatch) {
                        if (!is_array($oneBatch)) { continue; }
                        $entry['location_breakdown'][] = ['location_id' => (string) ($oneBatch['location_id'] ?? ''), 'qty' => (float) ($oneBatch['qty_remaining'] ?? 0)];
                    }
                    $entry['location_breakdown'] = documents_inventory_normalize_location_breakdown($entry['location_breakdown']);
                    $entry['on_hand_qty'] = documents_inventory_location_breakdown_total($entry['location_breakdown']);
                    $entry['updated_at'] = date('c');
                    documents_inventory_set_component_stock($stockState, (string) ($componentRow['id'] ?? ''), $variant, $entry);
                    return ['ok' => true, 'stock' => $stockState, 'tx' => ['qty' => $moveQty, 'unit' => (string) ($componentRow['default_unit'] ?? 'qty'), 'length_ft' => 0, 'lots_created' => [], 'lot_consumption' => [], 'batch_consumption' => [], 'batch_ids' => $movedBatchIds, 'location_consumption' => [], 'location_id' => $toLocation, 'from_location_id' => $fromLocation, 'to_location_id' => $toLocation, 'consume_location_id' => '']];
                }

                return ['ok' => false, 'error' => 'Unsupported transaction type.'];
            };

            if ($action === 'edit_inventory_tx') {
                $rebuiltTransactions = $allTransactions;
                unset($rebuiltTransactions[$editingIndex]);
                $rebuiltTransactions = array_values($rebuiltTransactions);
                $rebuiltStock = documents_inventory_stock_defaults();
                foreach ($rebuiltTransactions as $rebuiltTx) {
                    $rebuiltTx = array_merge(documents_inventory_transaction_defaults(), is_array($rebuiltTx) ? $rebuiltTx : []);
                    $rebuiltComponent = documents_inventory_get_component((string) ($rebuiltTx['component_id'] ?? ''));
                    if ($rebuiltComponent === null) {
                        continue;
                    }
                    $applied = $applyTx($rebuiltStock, $rebuiltComponent, (string) ($rebuiltTx['variant_id'] ?? ''), [
                        'type' => (string) ($rebuiltTx['type'] ?? 'IN'),
                        'qty' => (float) ($rebuiltTx['qty'] ?? 0),
                        'length_ft' => (float) ($rebuiltTx['length_ft'] ?? 0),
                        'piece_count' => (int) round((float) (($rebuiltTx['length_ft'] ?? 0) > 0 && !empty($rebuiltComponent['is_cuttable']) ? ($rebuiltTx['length_ft'] / max(0.0001, (float) ($rebuiltComponent['standard_length_ft'] ?? 0))) : 0)),
                        'piece_length_ft' => (float) ($rebuiltComponent['standard_length_ft'] ?? 0),
                        'ref_id' => (string) ($rebuiltTx['ref_id'] ?? ''),
                        'notes' => (string) ($rebuiltTx['notes'] ?? ''),
                        'location_id' => (string) ($rebuiltTx['location_id'] ?? ''),
                        'consume_location_id' => (string) ($rebuiltTx['consume_location_id'] ?? ''),
                        'from_location_id' => (string) ($rebuiltTx['from_location_id'] ?? ''),
                        'to_location_id' => (string) ($rebuiltTx['to_location_id'] ?? ''),
                        'move_batch_id' => '',
                        'move_lot_id' => '',
                        'actor' => is_array($rebuiltTx['created_by'] ?? null) ? (array) ($rebuiltTx['created_by'] ?? []) : ['role' => 'admin', 'id' => '', 'name' => 'Unknown'],
                        'source_txn_id' => (string) ($rebuiltTx['id'] ?? ''),
                    ]);
                    if (!($applied['ok'] ?? false)) {
                        $redirectDocuments('items', 'error', 'Unable to rebuild stock for edit.', ['items_subtab' => 'transactions']);
                    }
                    $rebuiltStock = (array) ($applied['stock'] ?? $rebuiltStock);
                }
                $stock = $rebuiltStock;
                $allTransactions = $rebuiltTransactions;
            }

            $pieceCount = trim((string) ($_POST['cut_piece_count'] ?? $_POST['piece_count'] ?? ''));
            $pieceLengthFt = trim((string) ($_POST['cut_piece_length_ft'] ?? $_POST['piece_length_ft'] ?? ''));

            $txPayloadInput = [
                'type' => $txType,
                'qty' => max(0, (float) ($_POST['qty'] ?? 0)),
                'length_ft' => max(0, (float) ($_POST['length_ft'] ?? 0)),
                'piece_count' => $pieceCount,
                'piece_length_ft' => $pieceLengthFt,
                'ref_id' => $refId,
                'notes' => $notes,
                'location_id' => $locationId,
                'consume_location_id' => $consumeLocationId,
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'move_batch_id' => $moveBatchId,
                'move_lot_id' => $moveLotId,
                'actor' => $actor,
                'source_txn_id' => $transactionId,
            ];
            $applyResult = $applyTx($stock, $component, $variantId, $txPayloadInput);
            if (!($applyResult['ok'] ?? false)) {
                $redirectDocuments('items', 'error', (string) ($applyResult['error'] ?? 'Invalid transaction.'), ['items_subtab' => 'inventory']);
            }
            $stock = (array) ($applyResult['stock'] ?? $stock);

            $saveStock = documents_inventory_save_stock($stock);
            if (!($saveStock['ok'] ?? false)) {
                $redirectDocuments('items', 'error', 'Failed to save stock.', ['items_subtab' => 'inventory']);
            }

            $txGenerated = (array) ($applyResult['tx'] ?? []);
            $now = date('c');
            if ($action === 'edit_inventory_tx' && is_array($existingTx)) {
                $editedTx = array_merge($existingTx, $txGenerated, [
                    'type' => $txType,
                    'component_id' => $componentId,
                    'variant_id' => $variantId,
                    'variant_name_snapshot' => $variantNameSnapshot,
                    'ref_type' => $refType === '' ? 'manual' : $refType,
                    'ref_id' => $refId,
                    'reason' => $reason,
                    'notes' => $notes,
                    'updated_at' => $now,
                    'updated_by' => $actor,
                ]);
                $history = is_array($editedTx['edit_history'] ?? null) ? $editedTx['edit_history'] : [];
                $history[] = ['at' => $now, 'by' => $actor, 'changes_summary' => 'Transaction edited'];
                $editedTx['edit_history'] = $history;
                $allTransactions[] = $editedTx;
                $savedTx = documents_inventory_save_transactions($allTransactions);
                if (!($savedTx['ok'] ?? false)) {
                    $redirectDocuments('items', 'error', 'Failed to save transaction changes.', ['items_subtab' => 'transactions']);
                }
                if (documents_inventory_is_employee_actor((array) ($editedTx['created_by'] ?? []))) {
                    $sync = documents_inventory_sync_verification_log([$editedTx], true);
                    $verificationRows = (array) ($sync['rows'] ?? []);
                    foreach ($verificationRows as $idx => $verificationRow) {
                        if ((string) ($verificationRow['txn_id'] ?? '') !== (string) ($editedTx['id'] ?? '')) {
                            continue;
                        }
                        $verificationRows[$idx]['status'] = 'not_verified';
                        $verificationRows[$idx]['admin_note'] = '';
                        $verificationRows[$idx]['verified_by'] = null;
                        $verificationRows[$idx]['verified_at'] = null;
                        break;
                    }
                    documents_inventory_save_verification_log($verificationRows);
                }
                $redirectDocuments('items', 'success', 'Transaction updated.', ['items_subtab' => 'transactions']);
            }

            $tx = array_merge(documents_inventory_transaction_defaults(), $txGenerated, [
                'id' => 'txn_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)),
                'type' => $txType,
                'component_id' => $componentId,
                'variant_id' => $variantId,
                'variant_name_snapshot' => $variantNameSnapshot,
                'ref_type' => $refType === '' ? 'manual' : $refType,
                'ref_id' => $refId,
                'reason' => $reason,
                'notes' => $notes,
                'created_at' => $now,
                'created_by' => $actor,
            ]);
            $saveTx = documents_inventory_append_transaction($tx);
            if (!($saveTx['ok'] ?? false)) {
                $redirectDocuments('items', 'error', 'Failed to save transaction.', ['items_subtab' => 'inventory']);
            }
            if (documents_inventory_is_employee_actor($actor)) {
                $verificationSync = documents_inventory_sync_verification_log([$tx], false);
                $verificationRows = (array) ($verificationSync['rows'] ?? []);
                if ($verificationRows !== []) {
                    $saveVerification = documents_inventory_save_verification_log($verificationRows);
                    if (!($saveVerification['ok'] ?? false)) {
                        $redirectDocuments('items', 'error', 'Transaction saved but verification log update failed.', ['items_subtab' => 'transactions']);
                    }
                }
            }
            $redirectDocuments('items', 'success', 'Transaction created and stock updated.', ['items_subtab' => 'transactions']);
        }

    }

}

$activeTab = safe_text($_GET['tab'] ?? 'company');
if (!in_array($activeTab, ['company', 'numbering', 'templates', 'accepted_customers', 'items', 'archived', 'document_verification'], true)) {
    $activeTab = 'company';
}

$status = safe_text($_GET['status'] ?? '');
$message = safe_text($_GET['message'] ?? '');
$csvImportReport = null;
if (isset($_SESSION['items_csv_import_report']) && is_array($_SESSION['items_csv_import_report'])) {
    $csvImportReport = $_SESSION['items_csv_import_report'];
}
unset($_SESSION['items_csv_import_report']);

$company = load_company_profile();

$numbering = json_load($numberingPath, documents_numbering_defaults());
$numbering = array_merge(documents_numbering_defaults(), is_array($numbering) ? $numbering : []);
$numbering['rules'] = is_array($numbering['rules']) ? $numbering['rules'] : [];

$templates = json_load($templatePath, []);
$templates = is_array($templates) ? $templates : [];

$includeArchivedAccepted = isset($_GET['include_archived_accepted']) && $_GET['include_archived_accepted'] === '1';
$acceptedSearch = strtolower(trim(safe_text($_GET['accepted_q'] ?? '')));
$packViewId = safe_text($_GET['view'] ?? '');
$packAction = safe_text($_GET['action'] ?? '');
$packReceiptId = safe_text($_GET['receipt_id'] ?? '');
$includeArchivedPack = isset($_GET['include_archived_pack']) && $_GET['include_archived_pack'] === '1';
$archiveTypeFilter = safe_text($_GET['archive_type'] ?? 'all');
$archiveSearch = strtolower(trim(safe_text($_GET['archive_q'] ?? '')));
$itemsSubtab = safe_text($_GET['items_subtab'] ?? ($_GET['sub'] ?? 'components'));
if (!in_array($itemsSubtab, ['components', 'kits', 'tax_profiles', 'variants', 'locations', 'inventory', 'inventory_verification', 'transactions', 'csv_import', 'archived'], true)) {
    $itemsSubtab = 'components';
}

if ($isEmployee && !in_array($activeTab, ['accepted_customers', 'items'], true)) {
    $activeTab = 'accepted_customers';
    if ($status === '' && $message === '') {
        $status = 'error';
        $message = 'Access denied. Employees can access Accepted Customers and Inventory only.';
    }
}

if ($isEmployee && $activeTab === 'items' && !in_array($itemsSubtab, ['inventory', 'transactions', 'csv_import'], true)) {
    $itemsSubtab = 'inventory';
    if ($status === '' && $message === '') {
        $status = 'error';
        $message = 'Access denied. Your role can access Inventory, Transactions, and CSV Import only.';
    }
}


if ($activeTab === 'items' && $itemsSubtab === 'csv_import') {
    $sampleType = safe_text((string) ($_GET['download_sample'] ?? ''));
    if ($sampleType !== '') {
        $sampleHeaders = [
            'components' => ['name','category','hsn','default_unit','is_cuttable','standard_length_ft','has_variants','tax_profile_name'],
            'variants' => ['component_name','display_name','brand','technology','wattage_wp','model_no','hsn_override','tax_profile_name_override'],
            'locations' => ['name','type','notes'],
            'kits' => ['kit_name','category','description','tax_profile_name'],
            'kit_items' => ['kit_name','component_name','mode','unit','fixed_qty','capacity_type','capacity_expr','slab_json','rule_type','target_expr','allow_overbuild_pct','manual_note','remarks'],
            'stock_in' => ['component_name','variant_display_name','qty','piece_count','piece_length_ft','location_name','notes'],
        ];
        if (!isset($sampleHeaders[$sampleType])) {
            http_response_code(404);
            echo 'Sample type not found.';
            exit;
        }
        if ($sampleType !== 'stock_in' && !$isAdmin) {
            http_response_code(403);
            echo 'Access denied.';
            exit;
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="inventory-sample-' . $sampleType . '.csv"');
        $out = fopen('php://output', 'wb');
        if ($out !== false) {
            fputcsv($out, $sampleHeaders[$sampleType]);
            fclose($out);
        }
        exit;
    }
}

$inventoryEditMode = ($isAdmin || $isEmployee) && $itemsSubtab === 'inventory' && safe_text((string) ($_GET['edit_mode'] ?? '')) === '1';
$inventoryStockView = safe_text((string) ($_GET['inventory_view'] ?? 'active'));
if (!in_array($inventoryStockView, ['active', 'finished'], true)) {
    $inventoryStockView = 'active';
}
$itemsEditId = safe_text((string) ($_GET['edit'] ?? ''));
$componentFilter = safe_text((string) ($_GET['component_filter'] ?? ''));
$cloneId = safe_text((string) ($_GET['clone'] ?? ''));

if ($activeTab === 'items' && $cloneId !== '' && in_array($itemsSubtab, ['components', 'kits', 'variants'], true)) {
    if (!$isAdmin) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }

    $now = date('c');

    if ($itemsSubtab === 'components') {
        $components = documents_inventory_components(true);
        $source = null;
        foreach ($components as $component) {
            if ((string) ($component['id'] ?? '') === $cloneId) {
                $source = array_merge(documents_inventory_component_defaults(), $component);
                break;
            }
        }
        if ($source === null) {
            $redirectDocuments('items', 'error', 'Component not found for clone.', ['items_subtab' => 'components']);
        }

        $clone = $source;
        $clone['id'] = $generateInventoryEntityId('CMP', $components);
        $clone['name'] = safe_text((string) ($source['name'] ?? '')) . ' (Copy)';
        $clone['archived_flag'] = false;
        $clone['created_at'] = $now;
        $clone['updated_at'] = $now;

        $components[] = $clone;
        $result = documents_inventory_save_components($components);
        if (!($result['ok'] ?? false)) {
            $redirectDocuments('items', 'error', 'Failed to clone component.', ['items_subtab' => 'components']);
        }

        $redirectDocuments('items', 'success', 'Component cloned.', ['items_subtab' => 'components', 'edit' => (string) ($clone['id'] ?? '')]);
    }

    if ($itemsSubtab === 'kits') {
        $kits = documents_inventory_kits(true);
        $source = null;
        foreach ($kits as $kit) {
            if ((string) ($kit['id'] ?? '') === $cloneId) {
                $source = array_merge(documents_inventory_kit_defaults(), $kit);
                break;
            }
        }
        if ($source === null) {
            $redirectDocuments('items', 'error', 'Kit not found for clone.', ['items_subtab' => 'kits']);
        }

        $clone = $source;
        $clone['id'] = $generateInventoryEntityId('KIT', $kits);
        $clone['name'] = safe_text((string) ($source['name'] ?? '')) . ' (Copy)';
        $clone['archived_flag'] = false;
        $clone['created_at'] = $now;
        $clone['updated_at'] = $now;

        $kits[] = $clone;
        $result = documents_inventory_save_kits($kits);
        if (!($result['ok'] ?? false)) {
            $redirectDocuments('items', 'error', 'Failed to clone kit.', ['items_subtab' => 'kits']);
        }

        $redirectDocuments('items', 'success', 'Kit cloned.', ['items_subtab' => 'kits', 'edit' => (string) ($clone['id'] ?? '')]);
    }

    $rows = documents_inventory_component_variants(true);
    $source = null;
    foreach ($rows as $variant) {
        if ((string) ($variant['id'] ?? '') === $cloneId) {
            $source = array_merge(documents_component_variant_defaults(), $variant);
            break;
        }
    }
    if ($source === null) {
        $redirectDocuments('items', 'error', 'Variant not found for clone.', ['items_subtab' => 'variants']);
    }

    $clone = $source;
    $clone['id'] = $generateInventoryEntityId('VAR', $rows);
    $clone['display_name'] = safe_text((string) ($source['display_name'] ?? '')) . ' (Copy)';
    $clone['archived_flag'] = false;
    $clone['created_at'] = $now;
    $clone['updated_at'] = $now;

    $rows[] = $clone;
    $result = documents_inventory_save_component_variants($rows);
    if (!($result['ok'] ?? false)) {
        $redirectDocuments('items', 'error', 'Failed to clone variant.', ['items_subtab' => 'variants']);
    }

    $redirectDocuments('items', 'success', 'Variant cloned.', ['items_subtab' => 'variants', 'component_filter' => (string) ($clone['component_id'] ?? ''), 'edit' => (string) ($clone['id'] ?? '')]);
}

$quotes = documents_list_quotes();
$salesAgreements = documents_list_sales_documents('agreement');
$salesReceipts = documents_list_sales_documents('receipt');
$salesChallans = documents_list_sales_documents('delivery_challan');
$salesProformas = documents_list_sales_documents('proforma');
$salesInvoices = documents_list_sales_documents('invoice');
$documentActionLogs = documents_load_document_actions_log();
$documentVerificationRows = documents_load_document_verification();
$inventoryComponents = documents_inventory_components(true);
$activeInventoryComponents = get_active_components();
$inventoryKits = documents_inventory_cleanup_archived_kit_components(documents_inventory_kits(true));
$activeInventoryKits = get_active_kits();
$inventoryTaxProfiles = documents_inventory_tax_profiles(true);
$activeTaxProfiles = get_active_tax_profiles();
$inventoryVariants = documents_inventory_component_variants(true);
$activeInventoryVariants = get_active_variants();
$inventoryLocations = documents_inventory_locations(true);
$activeInventoryLocations = get_active_locations();

$archivedItemsType = safe_text((string) ($_GET['archived_type'] ?? 'all'));
$archivedItemsQuery = strtolower(trim(safe_text((string) ($_GET['archived_q'] ?? ''))));
$archivedItemsRows = [];
$appendArchivedRow = static function (string $type, array $row, string $label) use (&$archivedItemsRows): void {
    $archivedItemsRows[] = ['type' => $type, 'id' => (string) ($row['id'] ?? ''), 'name' => (string) ($row['name'] ?? $row['display_name'] ?? ''), 'label' => $label, 'archived_at' => (string) ($row['archived_at'] ?? '')];
};
foreach ($inventoryComponents as $row) { if (is_array($row) && !empty($row['archived_flag'])) { $appendArchivedRow('component', $row, 'Component'); } }
foreach ($inventoryKits as $row) { if (is_array($row) && !empty($row['archived_flag'])) { $appendArchivedRow('kit', $row, 'Kit'); } }
foreach ($inventoryVariants as $row) { if (is_array($row) && !empty($row['archived_flag'])) { $appendArchivedRow('variant', ['id'=>(string)($row['id']??''),'name'=>(string)($row['display_name']??''),'archived_at'=>(string)($row['archived_at']??'')], 'Variant'); } }
foreach ($inventoryTaxProfiles as $row) { if (is_array($row) && !empty($row['archived_flag'])) { $appendArchivedRow('tax_profile', $row, 'Tax Profile'); } }
foreach ($inventoryLocations as $row) { if (is_array($row) && !empty($row['archived_flag'])) { $appendArchivedRow('location', $row, 'Location'); } }
$archivedItemsRows = array_values(array_filter($archivedItemsRows, static function (array $row) use ($archivedItemsType, $archivedItemsQuery): bool {
    if ($archivedItemsType !== 'all' && (string) ($row['type'] ?? '') !== $archivedItemsType) { return false; }
    if ($archivedItemsQuery !== '' && strpos(strtolower((string) (($row['name'] ?? '') . ' ' . ($row['id'] ?? ''))), $archivedItemsQuery) === false) { return false; }
    return true;
}));
$inventoryLocationMap = [];
foreach ($inventoryLocations as $locationRow) {
    if (!is_array($locationRow)) {
        continue;
    }
    $inventoryLocationMap[(string) ($locationRow['id'] ?? '')] = (string) ($locationRow['name'] ?? '');
}
documents_inventory_resolve_location_name('', $inventoryLocationMap);
$variantMap = [];
$variantsByComponent = [];
foreach ($activeInventoryVariants as $variantRow) {
    if (!is_array($variantRow)) {
        continue;
    }
    $variantKey = (string) ($variantRow['id'] ?? '');
    $componentKey = (string) ($variantRow['component_id'] ?? '');
    if ($variantKey === '' || $componentKey === '') {
        continue;
    }
    $variantMap[$variantKey] = $variantRow;
    if (!isset($variantsByComponent[$componentKey]) || !is_array($variantsByComponent[$componentKey])) {
        $variantsByComponent[$componentKey] = [];
    }
    $variantsByComponent[$componentKey][] = [
        'id' => $variantKey,
        'display_name' => (string) ($variantRow['display_name'] ?? ''),
        'brand' => (string) ($variantRow['brand'] ?? ''),
        'technology' => (string) ($variantRow['technology'] ?? ''),
        'wattage_wp' => max(0, (float) ($variantRow['wattage_wp'] ?? 0)),
    ];
}
$inventoryStock = documents_inventory_load_stock();
$inventoryTransactions = documents_inventory_load_transactions();
$inventoryTransactions = array_map(static function ($tx): array {
    $row = array_merge(documents_inventory_transaction_defaults(), is_array($tx) ? $tx : []);
    if (!is_array($row['created_by'] ?? null)) {
        $row['created_by'] = ['role' => '', 'id' => '', 'name' => (string) ($row['created_by'] ?? '')];
    }
    if (!is_array($row['updated_by'] ?? null)) {
        $row['updated_by'] = ['role' => '', 'id' => '', 'name' => ''];
    }
    $row['edit_history'] = is_array($row['edit_history'] ?? null) ? $row['edit_history'] : [];
    return $row;
}, $inventoryTransactions);
usort($inventoryTransactions, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
$verificationSync = documents_inventory_sync_verification_log($inventoryTransactions, true);
$inventoryVerificationLog = (array) ($verificationSync['rows'] ?? []);
$inventoryVerificationByTxnId = [];
foreach ($inventoryVerificationLog as $verificationRow) {
    if (!is_array($verificationRow)) {
        continue;
    }
    $verificationTxnId = (string) ($verificationRow['txn_id'] ?? '');
    if ($verificationTxnId === '' || isset($inventoryVerificationByTxnId[$verificationTxnId])) {
        continue;
    }
    $inventoryVerificationByTxnId[$verificationTxnId] = array_merge(documents_inventory_verification_defaults(), $verificationRow);
}
$inventoryVerificationQueueRows = [];
$inventoryVerificationCounts = ['not_verified' => 0, 'verified' => 0, 'needs_clarification' => 0];
foreach ($inventoryTransactions as $txRow) {
    $tx = array_merge(documents_inventory_transaction_defaults(), is_array($txRow) ? $txRow : []);
    $creator = is_array($tx['created_by'] ?? null) ? $tx['created_by'] : ['role' => '', 'id' => '', 'name' => ''];
    if (!documents_inventory_is_employee_actor($creator)) {
        continue;
    }
    $txId = (string) ($tx['id'] ?? '');
    if ($txId === '') {
        continue;
    }
    $verification = array_merge(documents_inventory_verification_defaults(), (array) ($inventoryVerificationByTxnId[$txId] ?? [
        'txn_id' => $txId,
        'txn_type' => (string) ($tx['type'] ?? 'IN'),
        'created_by' => $creator,
        'created_at' => (string) ($tx['created_at'] ?? ''),
        'status' => 'not_verified',
    ]));
    $statusKey = (string) ($verification['status'] ?? 'not_verified');
    if (!isset($inventoryVerificationCounts[$statusKey])) {
        $statusKey = 'not_verified';
    }
    $inventoryVerificationCounts[$statusKey]++;
    $inventoryVerificationQueueRows[] = ['tx' => $tx, 'verification' => $verification];
}
$inventoryVerificationPendingRows = array_values(array_filter($inventoryVerificationQueueRows, static function (array $row): bool {
    $status = (string) (($row['verification']['status'] ?? 'not_verified'));
    return $status !== 'verified';
}));
$inventoryUsageIndex = documents_inventory_build_usage_index($inventoryTransactions);
$inventoryComponentBlocked = (array) ($inventoryUsageIndex['component_blocked'] ?? []);
$inventoryVariantBlocked = (array) ($inventoryUsageIndex['variant_blocked'] ?? []);
$inventoryLotBlocked = (array) ($inventoryUsageIndex['lot_blocked'] ?? []);
$inventoryBatchBlocked = (array) ($inventoryUsageIndex['batch_blocked'] ?? []);
$componentMap = [];
foreach ($inventoryComponents as $cmpRow) {
    if (is_array($cmpRow)) {
        $componentMap[(string) ($cmpRow['id'] ?? '')] = $cmpRow;
    }
}
$inventoryComponentJsMap = [];
foreach ($componentMap as $cmpId => $cmpRow) {
    $inventoryComponentJsMap[$cmpId] = [
        'id' => (string) ($cmpRow['id'] ?? ''),
        'has_variants' => !empty($cmpRow['has_variants']),
        'is_cuttable' => !empty($cmpRow['is_cuttable']),
        'default_unit' => (string) ($cmpRow['default_unit'] ?? 'qty'),
        'standard_length_ft' => (float) ($cmpRow['standard_length_ft'] ?? 0),
    ];
}

$editingComponent = null;
$editingKit = null;
$editingTaxProfile = null;
$editingVariant = null;
$editingLocation = null;

if ($itemsEditId !== '') {
    if ($itemsSubtab === 'components') {
        $editingComponent = documents_inventory_get_component($itemsEditId);
    } elseif ($itemsSubtab === 'kits') {
        $editingKit = documents_inventory_get_kit($itemsEditId);
    } elseif ($itemsSubtab === 'tax_profiles') {
        $editingTaxProfile = documents_inventory_get_tax_profile($itemsEditId);
    } elseif ($itemsSubtab === 'variants') {
        $editingVariant = documents_inventory_get_component_variant($itemsEditId);
    } elseif ($itemsSubtab === 'locations') {
        $editingLocation = documents_inventory_get_location($itemsEditId);
    }
}

$receiptsByQuote = [];
foreach ($salesReceipts as $receipt) {
    if (!is_array($receipt)) {
        continue;
    }
    $qid = (string) ($receipt['quotation_id'] ?? '');
    if ($qid === '') {
        continue;
    }
    $receiptsByQuote[$qid][] = $receipt;
}

$acceptedRows = [];
foreach ($quotes as $quote) {
    if (!is_array($quote)) {
        continue;
    }
    $statusNormalized = documents_quote_normalize_status((string) ($quote['status'] ?? 'draft'));
    if ($statusNormalized !== 'accepted' || !((bool) ($quote['is_current_version'] ?? false))) {
        continue;
    }
    $isArchived = $isArchivedRecord($quote);
    if ($isArchived && !$includeArchivedAccepted) {
        continue;
    }
    $mobile = normalize_customer_mobile((string) ($quote['customer_mobile'] ?? ''));
    $name = (string) ($quote['customer_name'] ?? '');
    $hay = strtolower($name . ' ' . $mobile);
    if ($acceptedSearch !== '' && !str_contains($hay, $acceptedSearch)) {
        continue;
    }
    $quotationAmount = (float) ($quote['calc']['gross_payable'] ?? $quote['calc']['final_price_incl_gst'] ?? $quote['calc']['grand_total'] ?? 0);
    $received = 0.0;
    foreach (($receiptsByQuote[(string) ($quote['id'] ?? '')] ?? []) as $receipt) {
        if ($isArchivedRecord($receipt)) {
            continue;
        }
        $receiptStatus = strtolower(trim((string) ($receipt['status'] ?? '')));
        if ($receiptStatus !== 'final') {
            continue;
        }
        $received += (float) ($receipt['amount_rs'] ?? $receipt['amount_received'] ?? $receipt['amount'] ?? 0);
    }
    $receivable = $quotationAmount - $received;
    $acceptedRows[] = [
        'quote' => $quote,
        'quotation_amount' => $quotationAmount,
        'payment_received' => $received,
        'receivables' => $receivable,
        'advance' => $received > $quotationAmount,
        'is_archived' => $isArchived,
    ];
}

$packQuote = null;
$packVersions = [];
$packCurrentVersionNo = 1;
$packIsOlderVersion = false;
if ($packViewId !== '') {
    $packQuote = documents_get_quote($packViewId);
    if ($packQuote !== null) {
        $packVersions = documents_quote_versions((string) ($packQuote['quote_series_id'] ?? ''));
        foreach ($packVersions as $versionRow) {
            if ((bool) ($versionRow['is_current_version'] ?? false)) {
                $packCurrentVersionNo = (int) ($versionRow['version_no'] ?? 1);
                break;
            }
        }
        $packIsOlderVersion = !((bool) ($packQuote['is_current_version'] ?? false));
    }
}


$packAction = safe_text((string) ($_GET['pack_action'] ?? ''));
$packPiId = safe_text((string) ($_GET['pi_id'] ?? ''));
$editingPi = null;
if ($packQuote !== null && $packPiId !== '') {
    $editingPiRow = documents_get_sales_document('proforma', $packPiId);
    if (is_array($editingPiRow) && (string) ($editingPiRow['quotation_id'] ?? $editingPiRow['quote_id'] ?? '') === (string) ($packQuote['id'] ?? '')) {
        $editingPi = $preparePiDocument($editingPiRow, $packQuote);
    }
}

$collectByQuote = static function (array $rows, string $quoteId, bool $includeArchived, bool $applyEmployeeDcFilter = false) use ($isArchivedRecord, $isEmployee, $user, $employeeDcAccessAllowed): array {
    $list = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string) ($row['quotation_id'] ?? '') !== $quoteId) {
            continue;
        }
        if ($isEmployee && $applyEmployeeDcFilter) {
            $creatorRole = (string) ($row['created_by']['role'] ?? $row['created_by_type'] ?? $row['created_by']['type'] ?? '');
            $creatorId = (string) ($row['created_by']['id'] ?? $row['created_by_id'] ?? '');
            $ownEmployeeDraft = ($creatorRole === 'employee' && $creatorId === (string) ($user['id'] ?? ''));
            $adminCreatedVisible = ($creatorRole === 'admin' && $employeeDcAccessAllowed);
            if (!$ownEmployeeDraft && !$adminCreatedVisible) {
                continue;
            }
        }
        if (!$includeArchived && $isArchivedRecord($row)) {
            continue;
        }
        $list[] = $row;
    }
    return $list;
};

$archivedRows = [];
foreach ($quotes as $quote) {
    if (!is_array($quote) || !$isArchivedRecord($quote)) {
        continue;
    }
    $archivedRows[] = [
        'type' => 'quotation',
        'doc_id' => (string) ($quote['id'] ?? ''),
        'quotation_status' => documents_quote_normalize_status((string) ($quote['status'] ?? 'draft')),
        'customer' => (string) ($quote['customer_name'] ?? ''),
        'mobile' => (string) ($quote['customer_mobile'] ?? ''),
        'quotation_id' => (string) ($quote['id'] ?? ''),
        'amount' => (float) ($quote['calc']['gross_payable'] ?? 0),
        'archived_at' => (string) ($quote['archived_at'] ?? ''),
    ];
}

$agreements = documents_list_agreements();
foreach ($agreements as $agreement) {
    if (!is_array($agreement) || !$isArchivedRecord($agreement)) {
        continue;
    }
    $archivedRows[] = [
        'type' => 'agreement',
        'doc_id' => (string) ($agreement['id'] ?? ''),
        'customer' => (string) ($agreement['customer_name'] ?? ''),
        'mobile' => (string) ($agreement['customer_mobile'] ?? ''),
        'quotation_id' => (string) ($agreement['linked_quote_id'] ?? ''),
        'amount' => (float) preg_replace('/[^0-9.]/', '', (string) ($agreement['total_cost'] ?? '')),
        'archived_at' => (string) ($agreement['archived_at'] ?? $agreement['updated_at'] ?? ''),
    ];
}

foreach (['agreement' => $salesAgreements, 'receipt' => $salesReceipts, 'delivery_challan' => $salesChallans, 'proforma' => $salesProformas, 'invoice' => $salesInvoices] as $type => $rows) {
    foreach ($rows as $row) {
        if (!is_array($row) || !$isArchivedRecord($row)) {
            continue;
        }
        $amount = (float) ($row['amount_received'] ?? $row['amount'] ?? $row['pricing_snapshot']['gross_payable'] ?? 0);
        $archivedRows[] = [
            'type' => $type,
            'doc_id' => (string) ($row['id'] ?? ''),
            'customer' => (string) ($row['customer_name'] ?? ''),
            'mobile' => (string) ($row['customer_mobile'] ?? ''),
            'quotation_id' => (string) ($row['quotation_id'] ?? ''),
            'amount' => $amount,
            'archived_at' => (string) ($row['archived_at'] ?? ''),
        ];
    }
}

$archivedRows = array_values(array_filter($archivedRows, static function (array $row) use ($archiveTypeFilter, $archiveSearch): bool {
    if ($archiveTypeFilter !== 'all' && $archiveTypeFilter !== (string) ($row['type'] ?? '')) {
        return false;
    }
    if ($archiveSearch === '') {
        return true;
    }
    $hay = strtolower((string) ($row['type'] ?? '') . ' ' . (string) ($row['doc_id'] ?? '') . ' ' . (string) ($row['customer'] ?? '') . ' ' . (string) ($row['mobile'] ?? '') . ' ' . (string) ($row['quotation_id'] ?? ''));
    return str_contains($hay, $archiveSearch);
}));
usort($archivedRows, static function (array $a, array $b): int {
    $typeCmp = strcmp((string) ($a['type'] ?? ''), (string) ($b['type'] ?? ''));
    if ($typeCmp !== 0) {
        return $typeCmp;
    }
    return strcmp((string) ($b['archived_at'] ?? ''), (string) ($a['archived_at'] ?? ''));
});

$docLogById = [];
foreach ($documentActionLogs as $logRow) {
    if (!is_array($logRow)) {
        continue;
    }
    $docLogById[(string) ($logRow['id'] ?? '')] = array_merge(documents_document_action_log_defaults(), $logRow);
}

$documentVerificationQueue = [];
foreach ($documentVerificationRows as $verificationRow) {
    if (!is_array($verificationRow)) {
        continue;
    }
    $verificationRow = array_merge(documents_document_verification_defaults(), $verificationRow);
    $logId = (string) ($verificationRow['log_id'] ?? '');
    $actionRow = $docLogById[$logId] ?? null;
    if (!is_array($actionRow)) {
        continue;
    }
    $statusVal = (string) ($verificationRow['status'] ?? 'not_verified');
    if (!in_array($statusVal, ['not_verified', 'needs_clarification'], true)) {
        continue;
    }
    $documentVerificationQueue[] = ['verification' => $verificationRow, 'log' => $actionRow];
}
usort($documentVerificationQueue, static function (array $a, array $b): int {
    return strcmp((string) ($b['log']['at'] ?? ''), (string) ($a['log']['at'] ?? ''));
});


?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Documents &amp; Billing Control Center</title>
  <style>
    body { margin: 0; font-family: Arial, sans-serif; background: #f4f6fa; color: #111827; }
    .page { width: 100%; max-width: none; padding: 1.25rem; box-sizing: border-box; }
    .top { display:flex; flex-wrap:wrap; gap:0.75rem; justify-content:space-between; align-items:center; margin-bottom:1rem; }
    .top h1 { margin:0; font-size:1.45rem; }
    .btn { display:inline-block; background:#1d4ed8; color:#fff; text-decoration:none; padding:0.55rem 0.8rem; border-radius:8px; border:none; cursor:pointer; font-size:0.92rem; }
    .btn.secondary { background:#fff; color:#1f2937; border:1px solid #cbd5e1; }
    .btn.warn { background:#b91c1c; }
    .banner { margin-bottom:1rem; padding:0.75rem 0.9rem; border-radius:8px; }
    .banner.success { background:#ecfdf5; border:1px solid #34d399; color:#065f46; }
    .banner.error { background:#fef2f2; border:1px solid #f87171; color:#991b1b; }
    .tabs { display:flex; flex-wrap:wrap; gap:0.5rem; margin-bottom:1rem; }
    .tab { text-decoration:none; background:#e2e8f0; color:#1f2937; padding:0.55rem 0.8rem; border-radius:8px; font-weight:600; }
    .tab.active { background:#1d4ed8; color:#fff; }
    .tab.disabled { opacity:0.55; pointer-events:none; }
    .panel { background:#fff; border:1px solid #dbe1ea; border-radius:12px; padding:1rem; }
    .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:0.8rem; }
    label { display:block; font-size:0.84rem; margin-bottom:0.3rem; font-weight:600; }
    input, select, textarea { width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box; }
    textarea { min-height:80px; }
    table { width:100%; border-collapse: collapse; margin-bottom:1rem; }
    th, td { border:1px solid #dbe1ea; padding:0.5rem; text-align:left; vertical-align:top; }
    th { background:#f8fafc; }
    .logo-preview { max-height:90px; display:block; margin-top:0.5rem; }
    .muted { color:#64748b; font-size:0.86rem; }
    .pill { display:inline-block; padding:0.15rem 0.45rem; border-radius:999px; font-size:0.72rem; font-weight:700; }
    .pill.archived { background:#fee2e2; color:#991b1b; }
    .pill.warn { background:#fef3c7; color:#92400e; }
    .row-actions { display:flex; flex-wrap:wrap; gap:0.35rem; align-items:center; }
    .inline-form { display:inline-block; margin:0; }
  </style>
</head>
<body>
  <main class="page">
    <div class="top">
      <div>
        <h1>Documents &amp; Billing Control Center</h1>
        <p class="muted">User: <?= htmlspecialchars((string) ($user['full_name'] ?? 'User'), ENT_QUOTES) ?> (<?= htmlspecialchars((string) ($user['role_name'] ?? ''), ENT_QUOTES) ?>)</p>
      </div>
      <div>
        <a class="btn" href="admin-quotations.php">Quotations</a>
        <a class="btn" href="admin-challans.php">Challans</a>
        <a class="btn" href="admin-agreements.php">Agreements</a>
        <a class="btn secondary" href="admin-templates.php">Template Blocks &amp; Media</a>
        <a class="btn secondary" href="<?= $isAdmin ? 'admin-dashboard.php' : 'employee-dashboard.php' ?>"><?= $isAdmin ? 'Back to Admin Dashboard' : 'Back to Employee Dashboard' ?></a>
      </div>
    </div>

    <?php if ($message !== '' && ($status === 'success' || $status === 'error')): ?>
      <div class="banner <?= htmlspecialchars($status, ENT_QUOTES) ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <nav class="tabs">
      <?php if ($isAdmin): ?>
        <a class="tab <?= $activeTab === 'company' ? 'active' : '' ?>" href="?tab=company">Company Profile &amp; Branding</a>
        <a class="tab <?= $activeTab === 'numbering' ? 'active' : '' ?>" href="?tab=numbering">Numbering Rules</a>
        <a class="tab <?= $activeTab === 'templates' ? 'active' : '' ?>" href="?tab=templates">Template Sets</a>
      <?php endif; ?>
      <a class="tab <?= $activeTab === 'accepted_customers' ? 'active' : '' ?>" href="?tab=accepted_customers">Accepted Customers</a>
      <a class="tab <?= $activeTab === 'items' ? 'active' : '' ?>" href="?tab=items">Items</a>
      <?php if ($isAdmin): ?>
        <a class="tab <?= $activeTab === 'document_verification' ? 'active' : '' ?>" href="?tab=document_verification">Document Verification</a>
        <a class="tab <?= $activeTab === 'archived' ? 'active' : '' ?>" href="?tab=archived">Archived</a>
        <a class="tab" href="admin-templates.php">Template Blocks &amp; Media</a>
        <a class="tab" href="admin-quotations.php">Quotation Manager</a>
        <a class="tab" href="admin-challans.php">Challans</a>
        <a class="tab" href="admin-agreements.php">Agreements</a>
      <?php endif; ?>
      <span class="tab disabled">CSV Import (Phase 2+)</span>
    </nav>

    <?php if ($activeTab === 'accepted_customers'): ?>
      <section class="panel">
        <?php if ($packQuote !== null): ?>
          <?php
            $packQuoteId = (string) ($packQuote['id'] ?? '');
            $packAgreements = $collectByQuote($salesAgreements, $packQuoteId, $includeArchivedPack);
            $packReceipts = $collectByQuote($salesReceipts, $packQuoteId, $includeArchivedPack);
            $packChallans = $collectByQuote($salesChallans, $packQuoteId, $includeArchivedPack, true);
            $packProformas = $collectByQuote($salesProformas, $packQuoteId, $includeArchivedPack);
            $packInvoices = $collectByQuote($salesInvoices, $packQuoteId, $includeArchivedPack);
            $packReceiptsActive = array_values(array_filter($packReceipts, static fn(array $r): bool => !documents_is_archived($r)));
            $packFinalReceived = 0.0;
            foreach ($packReceiptsActive as $receiptRow) {
                if (strtolower(trim((string) ($receiptRow['status'] ?? 'draft'))) !== 'final') {
                    continue;
                }
                $packFinalReceived += (float) ($receiptRow['amount_rs'] ?? $receiptRow['amount_received'] ?? $receiptRow['amount'] ?? 0);
            }
            $packQuoteAmount = (float) ($packQuote['calc']['gross_payable'] ?? $packQuote['calc']['final_price_incl_gst'] ?? $packQuote['calc']['grand_total'] ?? 0);
            $packRemainingReceivable = $packQuoteAmount - $packFinalReceived;
            $editingReceipt = null;
            if ($packAction === 'edit_receipt' && $packReceiptId !== '') {
                $editingReceipt = documents_get_sales_document('receipt', $packReceiptId);
                if (!is_array($editingReceipt) || (string) ($editingReceipt['quotation_id'] ?? '') !== $packQuoteId) {
                    $editingReceipt = null;
                }
            }
          ?>
          <p><a class="btn secondary" href="?tab=accepted_customers">&larr; Back to Accepted Customers</a></p>
          <h2 style="margin-top:0;">Document Pack: <?= htmlspecialchars((string) ($packQuote['customer_name'] ?? ''), ENT_QUOTES) ?></h2>
          <div class="card" style="padding:10px;margin-bottom:10px"><strong>Current Version: v<?= (int) $packCurrentVersionNo ?></strong><div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap"><?php foreach ($packVersions as $versionRow): $isCurrentVersionRow = (bool) ($versionRow['is_current_version'] ?? false); ?><a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'accepted_customers', 'view' => (string) ($versionRow['id'] ?? ''), 'include_archived_pack' => $includeArchivedPack ? '1' : '0']), ENT_QUOTES) ?>">v<?= (int) ($versionRow['version_no'] ?? 1) ?></a><?php if ($isCurrentVersionRow): ?><span class="pill" style="background:#dcfce7;color:#166534">CURRENT</span><?php endif; ?><?php endforeach; ?></div></div>
          <?php if ($packIsOlderVersion): ?><div class="alert err">You are viewing an older version.</div><?php endif; ?>
          <form method="get" style="margin-bottom:1rem;">
            <input type="hidden" name="tab" value="accepted_customers" />
            <input type="hidden" name="view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
            <label><input type="checkbox" name="include_archived_pack" value="1" <?= $includeArchivedPack ? 'checked' : '' ?> onchange="this.form.submit()" /> Include Archived in Pack</label>
          </form>

          <?php $packPackingList = documents_get_packing_list_for_quote($packQuoteId, $includeArchivedPack); ?>
          <h3>Packing &amp; Dispatch Status</h3>
          <?php if ($packPackingList !== null): ?>
            <table><thead><tr><th>Component</th><th>Mode</th><th>Required/Target</th><th>Dispatched</th><th>Status</th></tr></thead><tbody>
              <?php foreach ((array) ($packPackingList['required_items'] ?? []) as $line): ?>
                <?php $mode = (string) ($line['mode'] ?? 'fixed_qty'); ?>
                <tr>
                  <td><?= htmlspecialchars((string) ($line['component_name_snapshot'] ?? ''), ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($mode, ENT_QUOTES) ?></td>
                  <?php if ($mode === 'rule_fulfillment'): ?>
                    <td>Target <?= htmlspecialchars((string) ((float) ($line['target_wp'] ?? 0)), ENT_QUOTES) ?> Wp</td>
                    <td><?= htmlspecialchars((string) ((float) ($line['dispatched_wp'] ?? 0)), ENT_QUOTES) ?> Wp</td>
                    <td><?= !empty($line['fulfilled_flag']) ? '<span class="pill" style="background:#dcfce7;color:#166534">Fulfilled</span>' : ('Remaining ' . htmlspecialchars((string) max(0, (float) ($line['target_wp'] ?? 0) - (float) ($line['dispatched_wp'] ?? 0)), ENT_QUOTES) . ' Wp') ?></td>
                  <?php elseif ($mode === 'unfixed_manual'): ?>
                    <td><?= htmlspecialchars((string) (($line['planned_note'] ?? '') ?: 'planned at dispatch'), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars((string) (((float) ($line['dispatched_ft'] ?? 0) > 0) ? (($line['dispatched_ft'] ?? 0) . ' ft') : (($line['dispatched_qty'] ?? 0) . ' ' . ($line['unit'] ?? ''))), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars((string) ($line['dispatched_summary'] ?? ''), ENT_QUOTES) ?></td>
                  <?php else: ?>
                    <td><?= htmlspecialchars((string) (((float) ($line['required_ft'] ?? 0) > 0 ? ($line['required_ft'] . ' ft') : ($line['required_qty'] . ' ' . ($line['unit'] ?? '')))), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars((string) (((float) ($line['required_ft'] ?? 0) > 0 ? ($line['dispatched_ft'] . ' ft') : ($line['dispatched_qty'] . ' ' . ($line['unit'] ?? '')))), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars((string) (((float) ($line['required_ft'] ?? 0) > 0 ? ('Pending ' . $line['pending_ft'] . ' ft') : ('Pending ' . $line['pending_qty'] . ' ' . ($line['unit'] ?? '')))), ENT_QUOTES) ?></td>
                  <?php endif; ?>
                </tr>
                <?php if ($mode === 'rule_fulfillment' && (array) ($line['dispatch_variant_breakdown'] ?? []) !== []): ?>
                  <tr><td colspan="5"><table><thead><tr><th>Variant</th><th>Wattage</th><th>Qty</th><th>Total Wp</th></tr></thead><tbody><?php foreach ((array) ($line['dispatch_variant_breakdown'] ?? []) as $b): ?><tr><td><?= htmlspecialchars((string) ($b['variant_name_snapshot'] ?? $b['variant_id'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($b['wattage_wp'] ?? 0), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($b['dispatched_qty'] ?? 0), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($b['dispatched_wp'] ?? 0), ENT_QUOTES) ?></td></tr><?php endforeach; ?></tbody></table></td></tr>
                <?php endif; ?>
              <?php endforeach; ?>
              <?php if ((array) ($packPackingList['required_items'] ?? []) === []): ?><tr><td colspan="5" class="muted">No structured items in packing list.</td></tr><?php endif; ?>
            </tbody></table>
            <h4>Dispatch Log</h4>
            <table><thead><tr><th>Delivery Challan</th><th>Date</th><th>Items Count</th></tr></thead><tbody>
              <?php foreach ((array) ($packPackingList['dispatch_log'] ?? []) as $dispatchRow): ?>
                <tr><td><?= htmlspecialchars((string) ($dispatchRow['delivery_challan_id'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($dispatchRow['at'] ?? ''), ENT_QUOTES) ?></td><td><?= count((array) ($dispatchRow['items'] ?? [])) ?></td></tr>
              <?php endforeach; ?>
              <?php if ((array) ($packPackingList['dispatch_log'] ?? []) === []): ?><tr><td colspan="3" class="muted">No dispatch yet.</td></tr><?php endif; ?>
            </tbody></table>
          <?php else: ?>
            <p class="muted">No packing list available. It is created automatically only when quotation has structured items.</p>
          <?php endif; ?>

          <h3>A) Quotation</h3>
          <p>
            <a class="btn secondary" href="quotation-view.php?id=<?= urlencode($packQuoteId) ?>" target="_blank" rel="noopener">View Quotation</a>
            <?php if ($isAdmin && documents_quote_normalize_status((string) ($packQuote['status'] ?? 'draft')) === 'accepted'): ?>
              <form class="inline-form" method="post" style="display:inline-flex; margin-left:0.45rem;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                <input type="hidden" name="action" value="archive_accepted_customer" />
                <input type="hidden" name="quotation_id" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
                <input type="hidden" name="return_tab" value="accepted_customers" />
                <button class="btn warn" type="submit">Archive this accepted customer</button>
              </form>
            <?php endif; ?>
            <?php if ($isArchivedRecord($packQuote)): ?><span class="pill archived">ARCHIVED</span><?php endif; ?>
          </p>

          <h3>B) Vendor Consumer Agreement</h3>
          <?php if ($packAgreements === []): ?>
            <form method="post" class="inline-form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
              <input type="hidden" name="action" value="create_agreement" />
              <input type="hidden" name="quotation_id" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
              <input type="hidden" name="return_tab" value="accepted_customers" />
              <input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
              <p class="muted">No agreement found. <button class="btn" type="submit">Create Agreement</button></p>
            </form>
          <?php else: ?>
            <table>
              <thead><tr><th>ID</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach ($packAgreements as $row): ?>
                  <tr>
                    <td><?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?> <?= $isArchivedRecord($row) ? '<span class="pill archived">ARCHIVED</span>' : '' ?></td>
                    <td><?= htmlspecialchars((string) ($row['execution_date'] ?? $row['created_at'] ?? ''), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars((string) ($row['status'] ?? 'active'), ENT_QUOTES) ?></td>
                    <td class="row-actions">
                      <a class="btn secondary" href="agreement-view.php?id=<?= urlencode((string) ($row['id'] ?? '')) ?><?= $isAdmin ? '&mode=edit' : '' ?>" target="_blank" rel="noopener"><?= $isAdmin ? 'View / Edit' : 'View' ?></a>
                      <a class="btn secondary" href="agreement-view.php?id=<?= urlencode((string) ($row['id'] ?? '')) ?>" target="_blank" rel="noopener">View as HTML</a>
                      <?php if ($isAdmin): ?>
                        <form class="inline-form" method="post">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                          <input type="hidden" name="action" value="set_archive_state" />
                          <input type="hidden" name="doc_type" value="agreement" />
                          <input type="hidden" name="doc_id" value="<?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?>" />
                          <input type="hidden" name="archive_state" value="<?= $isArchivedRecord($row) ? 'unarchive' : 'archive' ?>" />
                          <input type="hidden" name="return_tab" value="accepted_customers" />
                          <input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
                          <button class="btn <?= $isArchivedRecord($row) ? 'secondary' : 'warn' ?>" type="submit"><?= $isArchivedRecord($row) ? 'Unarchive' : 'Archive' ?></button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

          <h3>C) Payment Receipts</h3>
          <div class="card" style="padding:10px;margin-bottom:10px;display:flex;gap:14px;flex-wrap:wrap">
            <div><strong>Total received (final):</strong> <?= htmlspecialchars($inr($packFinalReceived), ENT_QUOTES) ?></div>
            <div><strong>Remaining receivable:</strong> <?= htmlspecialchars($inr($packRemainingReceivable), ENT_QUOTES) ?></div>
          </div>
          <form method="post" class="inline-form" style="margin-bottom:0.75rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
            <input type="hidden" name="action" value="create_receipt" />
            <input type="hidden" name="quotation_id" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
            <input type="hidden" name="return_tab" value="accepted_customers" />
            <input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
            <button class="btn" type="submit">Add Payment Receipt</button>
          </form>

          <?php if ($editingReceipt !== null): ?>
            <?php $isReceiptFinal = strtolower(trim((string) ($editingReceipt['status'] ?? 'draft'))) === 'final'; ?>
            <div class="card" style="padding:12px;margin-bottom:12px;">
              <h4 style="margin:0 0 8px 0;">Edit Receipt: <?= htmlspecialchars((string) ($editingReceipt['receipt_number'] ?? $editingReceipt['id'] ?? ''), ENT_QUOTES) ?></h4>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                <input type="hidden" name="quotation_id" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
                <input type="hidden" name="receipt_id" value="<?= htmlspecialchars((string) ($editingReceipt['id'] ?? ''), ENT_QUOTES) ?>" />
                <input type="hidden" name="return_tab" value="accepted_customers" />
                <input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px;">
                  <label>Date received
                    <input type="date" name="date_received" value="<?= htmlspecialchars((string) ($editingReceipt['date_received'] ?? $editingReceipt['receipt_date'] ?? date('Y-m-d')), ENT_QUOTES) ?>" <?= $isReceiptFinal ? 'readonly' : '' ?> />
                  </label>
                  <label>Amount (₹)
                    <input type="number" min="0" step="0.01" name="amount_rs" value="<?= htmlspecialchars((string) ($editingReceipt['amount_rs'] ?? $editingReceipt['amount_received'] ?? ''), ENT_QUOTES) ?>" <?= $isReceiptFinal ? 'readonly' : '' ?> />
                  </label>
                  <label>Mode
                    <select name="mode" <?= $isReceiptFinal ? 'disabled' : '' ?>>
                      <?php $receiptMode=(string)($editingReceipt['mode'] ?? ''); foreach (['Cash','UPI','Bank Transfer','Cheque','Other'] as $modeOpt): ?>
                        <option value="<?= htmlspecialchars($modeOpt, ENT_QUOTES) ?>" <?= strcasecmp($receiptMode, $modeOpt) === 0 ? 'selected' : '' ?>><?= htmlspecialchars($modeOpt, ENT_QUOTES) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>Txn / Ref
                    <input type="text" name="txn_ref" value="<?= htmlspecialchars((string) ($editingReceipt['txn_ref'] ?? $editingReceipt['reference'] ?? ''), ENT_QUOTES) ?>" <?= $isReceiptFinal ? 'readonly' : '' ?> />
                  </label>
                </div>
                <label>Notes
                  <textarea name="notes" rows="3" <?= $isReceiptFinal ? 'readonly' : '' ?>><?= htmlspecialchars((string) ($editingReceipt['notes'] ?? ''), ENT_QUOTES) ?></textarea>
                </label>
                <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">
                  <?php if (!$isReceiptFinal): ?>
                    <button class="btn secondary" type="submit" name="action" value="save_receipt_draft">Save Draft</button>
                    <button class="btn" type="submit" name="action" value="finalize_receipt">Finalize</button>
                  <?php else: ?>
                    <span class="pill" style="background:#dcfce7;color:#166534">Finalized (Locked)</span>
                  <?php endif; ?>
                  <a class="btn secondary" href="receipt-view.php?rid=<?= urlencode((string) ($editingReceipt['id'] ?? '')) ?>" target="_blank" rel="noopener">View as HTML</a>
                  <a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'accepted_customers', 'view' => $packQuoteId, 'include_archived_pack' => $includeArchivedPack ? '1' : '0']), ENT_QUOTES) ?>">Close</a>
                </div>
              </form>
            </div>
          <?php endif; ?>

          <table>
            <thead><tr><th>Receipt No</th><th>Date</th><th>Amount</th><th>Mode</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($packReceipts as $row): ?>
                <tr>
                  <td><?= htmlspecialchars((string) ($row['receipt_number'] ?? $row['id'] ?? ''), ENT_QUOTES) ?> <?= $isArchivedRecord($row) ? '<span class="pill archived">ARCHIVED</span>' : '' ?></td>
                  <td><?= htmlspecialchars((string) ($row['date_received'] ?? $row['receipt_date'] ?? $row['created_at'] ?? ''), ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($inr((float) ($row['amount_rs'] ?? $row['amount_received'] ?? $row['amount'] ?? 0)), ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars((string) ($row['mode'] ?? ''), ENT_QUOTES) ?> <?= htmlspecialchars((string) ($row['txn_ref'] ?? $row['reference'] ?? ''), ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars((string) ($row['status'] ?? 'draft'), ENT_QUOTES) ?></td>
                  <td class="row-actions">
                    <a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'accepted_customers', 'view' => $packQuoteId, 'action' => 'edit_receipt', 'receipt_id' => (string) ($row['id'] ?? ''), 'include_archived_pack' => $includeArchivedPack ? '1' : '0']), ENT_QUOTES) ?>">Edit</a>
                    <a class="btn secondary" href="receipt-view.php?rid=<?= urlencode((string) ($row['id'] ?? '')) ?>" target="_blank" rel="noopener">View HTML</a>
                    <?php if ($isAdmin): ?>
                      <form class="inline-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="action" value="set_archive_state" /><input type="hidden" name="doc_type" value="receipt" /><input type="hidden" name="doc_id" value="<?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="archive_state" value="<?= $isArchivedRecord($row) ? 'unarchive' : 'archive' ?>" /><input type="hidden" name="return_tab" value="accepted_customers" /><input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" /><button class="btn <?= $isArchivedRecord($row) ? 'secondary' : 'warn' ?>" type="submit"><?= $isArchivedRecord($row) ? 'Unarchive' : 'Archive' ?></button></form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if ($packReceipts === []): ?><tr><td colspan="6" class="muted">No receipts available.</td></tr><?php endif; ?>
            </tbody>
          </table>

          <h3>D) Delivery Challans</h3>
          <form method="post" class="inline-form" style="margin-bottom:0.75rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
            <input type="hidden" name="action" value="create_delivery_challan" />
            <input type="hidden" name="quotation_id" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
            <input type="hidden" name="return_tab" value="accepted_customers" />
            <input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
            <button class="btn" type="submit">Create DC</button>
          </form>
          <table><thead><tr><th>ID</th><th>Date</th><th>Items</th><th>Actions</th></tr></thead><tbody>
          <?php foreach ($packChallans as $row): ?>
            <tr>
              <td><?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?> <?= $isArchivedRecord($row) ? '<span class="pill archived">ARCHIVED</span>' : '' ?></td>
              <td><?= htmlspecialchars((string) ($row['challan_date'] ?? $row['created_at'] ?? ''), ENT_QUOTES) ?></td>
              <td><?= count(is_array($row['items'] ?? null) ? $row['items'] : []) ?></td>
              <td class="row-actions"><a class="btn secondary" href="challan-view.php?id=<?= urlencode((string) ($row['id'] ?? '')) ?>" target="_blank" rel="noopener">Open Builder</a> <a class="btn secondary" href="challan-print.php?id=<?= urlencode((string) ($row['id'] ?? '')) ?>" target="_blank" rel="noopener">View HTML</a><?php if ($isAdmin): ?><form class="inline-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="action" value="set_archive_state" /><input type="hidden" name="doc_type" value="delivery_challan" /><input type="hidden" name="doc_id" value="<?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="archive_state" value="<?= $isArchivedRecord($row) ? 'unarchive' : 'archive' ?>" /><input type="hidden" name="return_tab" value="accepted_customers" /><input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" /><button class="btn <?= $isArchivedRecord($row) ? 'secondary' : 'warn' ?>" type="submit"><?= $isArchivedRecord($row) ? 'Unarchive' : 'Archive' ?></button></form><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($packChallans === []): ?><tr><td colspan="4" class="muted">No delivery challans available.</td></tr><?php endif; ?>
          </tbody></table>

          <h3>E) Proforma Invoice (PI)</h3>
          <?php $latestPi = $packProformas !== [] ? $preparePiDocument((array) $packProformas[0], $packQuote) : null; ?>
          <?php if ($isEmployee): ?><p class="muted">Finalize PI is admin-only.</p><?php endif; ?>
          <?php if ($latestPi === null): ?>
            <form method="post" class="inline-form" style="margin-bottom:0.75rem;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
              <input type="hidden" name="action" value="create_pi" />
              <input type="hidden" name="quotation_id" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
              <input type="hidden" name="return_tab" value="accepted_customers" />
              <input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
              <button class="btn" type="submit">Create PI</button>
            </form>
          <?php else: ?>
            <div style="margin-bottom:0.75rem;display:flex;gap:8px;flex-wrap:wrap;">
              <?php if ((string) ($latestPi['status'] ?? 'draft') === 'draft'): ?>
                <a class="btn" href="?<?= htmlspecialchars(http_build_query(['tab' => 'accepted_customers', 'view' => $packQuoteId, 'pack_action' => 'edit_pi', 'pi_id' => (string) ($latestPi['pi_id'] ?? ''), 'include_archived_pack' => $includeArchivedPack ? '1' : '0']), ENT_QUOTES) ?>">Edit PI</a>
              <?php endif; ?>
              <a class="btn secondary" href="admin-documents.php?action=view_pi_html&pi_id=<?= urlencode((string) ($latestPi['pi_id'] ?? '')) ?>" target="_blank" rel="noopener">View PI (HTML)</a>
            </div>
          <?php endif; ?>

          <?php if ($editingPi !== null && $packAction === 'edit_pi'): ?>
            <?php $piLocked = (string) ($editingPi['status'] ?? 'draft') === 'final'; $piPricing = is_array($editingPi['pricing_snapshot'] ?? null) ? $editingPi['pricing_snapshot'] : []; ?>
            <div class="card" style="margin-bottom:10px;">
              <h4 style="margin:0 0 8px;">PI Editor: <?= htmlspecialchars((string) ($editingPi['pi_number'] ?? ''), ENT_QUOTES) ?></h4>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                <input type="hidden" name="quotation_id" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
                <input type="hidden" name="return_tab" value="accepted_customers" />
                <input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
                <input type="hidden" name="pi_id" value="<?= htmlspecialchars((string) ($editingPi['pi_id'] ?? ''), ENT_QUOTES) ?>" />
                <div class="grid cols-2">
                  <label>Issue Date<input type="date" name="issue_date" value="<?= htmlspecialchars((string) ($editingPi['issue_date'] ?? date('Y-m-d')), ENT_QUOTES) ?>" <?= $piLocked ? 'readonly' : '' ?> /></label>
                  <label>Customer GSTIN<input type="text" name="customer_gstin" value="<?= htmlspecialchars((string) ($editingPi['customer_gstin'] ?? ''), ENT_QUOTES) ?>" <?= $piLocked ? 'readonly' : '' ?> /></label>
                  <label>Customer Name<input type="text" name="customer_name_snapshot" value="<?= htmlspecialchars((string) ($editingPi['customer_name_snapshot'] ?? ''), ENT_QUOTES) ?>" <?= $piLocked ? 'readonly' : '' ?> /></label>
                  <label>Mobile<input type="text" name="customer_mobile" value="<?= htmlspecialchars((string) ($editingPi['customer_mobile'] ?? ''), ENT_QUOTES) ?>" <?= $piLocked ? 'readonly' : '' ?> /></label>
                  <label>Place of Supply State<select name="place_of_supply_state" <?= $piLocked ? 'disabled' : '' ?>><?php foreach ($indianStates as $st): ?><option value="<?= htmlspecialchars($st, ENT_QUOTES) ?>" <?= strcasecmp($st, (string) ($editingPi['place_of_supply_state'] ?? '')) === 0 ? 'selected' : '' ?>><?= htmlspecialchars($st, ENT_QUOTES) ?></option><?php endforeach; ?></select></label>
                </div>
                <label>Site Address<textarea name="site_address_snapshot" rows="2" <?= $piLocked ? 'readonly' : '' ?>><?= htmlspecialchars((string) ($editingPi['site_address_snapshot'] ?? ''), ENT_QUOTES) ?></textarea></label>

                <table><thead><tr><th>Sr</th><th>Item + Description</th><th>HSN</th><th>Qty</th><th>Unit</th></tr></thead><tbody>
                <?php foreach ((array) ($editingPi['items_snapshot'] ?? []) as $ix => $item): ?>
                  <tr><td><?= (int) ($item['sr_no'] ?? ($ix + 1)) ?></td><td><strong><?= htmlspecialchars((string) ($item['item_name'] ?? ''), ENT_QUOTES) ?></strong><br><textarea name="item_description[<?= (int) $ix ?>]" rows="2" <?= $piLocked ? 'readonly' : '' ?>><?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES) ?></textarea></td><td><?= htmlspecialchars((string) ($item['hsn'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($item['qty'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>

                <div class="grid cols-2" style="margin-top:8px;">
                  <label>Basic Total<input type="number" step="0.01" name="pricing_basic_total" value="<?= htmlspecialchars((string) ($piPricing['basic_total'] ?? 0), ENT_QUOTES) ?>" <?= (!$isAdmin || $piLocked) ? 'readonly' : '' ?> /></label>
                  <label>System Gross incl GST<input type="number" step="0.01" name="pricing_system_gross_incl_gst" value="<?= htmlspecialchars((string) ($piPricing['system_gross_incl_gst'] ?? 0), ENT_QUOTES) ?>" <?= (!$isAdmin || $piLocked) ? 'readonly' : '' ?> /></label>
                  <label>Transportation<input type="number" step="0.01" name="pricing_transportation" value="<?= htmlspecialchars((string) ($piPricing['transportation'] ?? 0), ENT_QUOTES) ?>" <?= (!$isAdmin || $piLocked) ? 'readonly' : '' ?> /></label>
                  <label>Discount<input type="number" step="0.01" name="pricing_discount" value="<?= htmlspecialchars((string) ($piPricing['discount'] ?? 0), ENT_QUOTES) ?>" <?= (!$isAdmin || $piLocked) ? 'readonly' : '' ?> /></label>
                  <label>Total Payable<input type="number" step="0.01" name="pricing_gross_payable_after_discount" value="<?= htmlspecialchars((string) ($piPricing['gross_payable_after_discount'] ?? 0), ENT_QUOTES) ?>" <?= (!$isAdmin || $piLocked) ? 'readonly' : '' ?> /></label>
                </div>
                <label>Notes<textarea name="notes" rows="3" <?= $piLocked ? 'readonly' : '' ?>><?= htmlspecialchars((string) ($editingPi['notes'] ?? ''), ENT_QUOTES) ?></textarea></label>
                <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">
                  <?php if (!$piLocked): ?><button class="btn secondary" type="submit" name="action" value="save_pi_draft">Save Draft</button><?php if ($isAdmin): ?><button class="btn" type="submit" name="action" value="finalize_pi">Finalize</button><?php endif; ?><?php else: ?><span class="pill" style="background:#dcfce7;color:#166534">Finalized (Locked)</span><?php endif; ?>
                  <a class="btn secondary" href="admin-documents.php?action=view_pi_html&pi_id=<?= urlencode((string) ($editingPi['pi_id'] ?? '')) ?>" target="_blank" rel="noopener">View PI (HTML)</a>
                  <a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'accepted_customers', 'view' => $packQuoteId, 'include_archived_pack' => $includeArchivedPack ? '1' : '0']), ENT_QUOTES) ?>">Close</a>
                </div>
              </form>
            </div>
          <?php endif; ?>

          <table><thead><tr><th>PI Number</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>
          <?php foreach ($packProformas as $row): $piRow = $preparePiDocument($row, $packQuote); ?><tr><td><?= htmlspecialchars((string) ($piRow['pi_number'] ?? $piRow['id'] ?? ''), ENT_QUOTES) ?> <?= $isArchivedRecord($row) ? '<span class="pill archived">ARCHIVED</span>' : '' ?></td><td><?= htmlspecialchars((string) ($piRow['issue_date'] ?? $row['created_at'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($piRow['status'] ?? 'draft'), ENT_QUOTES) ?></td><td class="row-actions"><a class="btn secondary" href="admin-documents.php?action=view_pi_html&pi_id=<?= urlencode((string) ($piRow['pi_id'] ?? '')) ?>" target="_blank" rel="noopener">View PI (HTML)</a><?php if ((string) ($piRow['status'] ?? 'draft') === 'draft'): ?><a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'accepted_customers', 'view' => $packQuoteId, 'pack_action' => 'edit_pi', 'pi_id' => (string) ($piRow['pi_id'] ?? ''), 'include_archived_pack' => $includeArchivedPack ? '1' : '0']), ENT_QUOTES) ?>">Edit PI</a><?php endif; ?><?php if ($isAdmin): ?><form class="inline-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="action" value="set_archive_state" /><input type="hidden" name="doc_type" value="proforma" /><input type="hidden" name="doc_id" value="<?= htmlspecialchars((string) ($piRow['id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="archive_state" value="<?= $isArchivedRecord($row) ? 'unarchive' : 'archive' ?>" /><input type="hidden" name="return_tab" value="accepted_customers" /><input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" /><button class="btn <?= $isArchivedRecord($row) ? 'secondary' : 'warn' ?>" type="submit"><?= $isArchivedRecord($row) ? 'Unarchive' : 'Archive' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
          <?php if ($packProformas === []): ?><tr><td colspan="4" class="muted">No PI found.</td></tr><?php endif; ?>
          </tbody></table>

          <h3>F) Invoice</h3>
          <?php if ($isEmployee): ?><p class="muted">Finalize Invoice is admin-only.</p><?php endif; ?>
          <form method="post" class="inline-form" style="margin-bottom:0.75rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
            <input type="hidden" name="action" value="create_invoice" />
            <input type="hidden" name="quotation_id" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
            <input type="hidden" name="return_tab" value="accepted_customers" />
            <input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
            <button class="btn" type="submit">Create Invoice</button>
          </form>
          <table><thead><tr><th>ID</th><th>Date</th><th>Actions</th></tr></thead><tbody>
          <?php foreach ($packInvoices as $row): ?><tr><td><?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?> <?= $isArchivedRecord($row) ? '<span class="pill archived">ARCHIVED</span>' : '' ?></td><td><?= htmlspecialchars((string) ($row['invoice_date'] ?? $row['created_at'] ?? ''), ENT_QUOTES) ?></td><td class="row-actions"><a class="btn secondary" href="admin-invoices.php?id=<?= urlencode((string) ($row['id'] ?? '')) ?>" target="_blank" rel="noopener">View/Edit</a><?php if ($isAdmin): ?><form class="inline-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="action" value="set_archive_state" /><input type="hidden" name="doc_type" value="invoice" /><input type="hidden" name="doc_id" value="<?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="archive_state" value="<?= $isArchivedRecord($row) ? 'unarchive' : 'archive' ?>" /><input type="hidden" name="return_tab" value="accepted_customers" /><input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" /><button class="btn <?= $isArchivedRecord($row) ? 'secondary' : 'warn' ?>" type="submit"><?= $isArchivedRecord($row) ? 'Unarchive' : 'Archive' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
          <?php if ($packInvoices === []): ?><tr><td colspan="3" class="muted">No invoice found.</td></tr><?php endif; ?>
          </tbody></table>
        <?php else: ?>
          <h2 style="margin-top:0;">Accepted Customers</h2>
          <form method="get" class="grid" style="margin-bottom:1rem;">
            <input type="hidden" name="tab" value="accepted_customers" />
            <div><label>Search (name/mobile)</label><input type="text" name="accepted_q" value="<?= htmlspecialchars((string) ($_GET['accepted_q'] ?? ''), ENT_QUOTES) ?>" /></div>
            <div><label>&nbsp;</label><label><input type="checkbox" name="include_archived_accepted" value="1" <?= $includeArchivedAccepted ? 'checked' : '' ?> /> Show archived accepted customers</label></div>
            <div><label>&nbsp;</label><button class="btn" type="submit">Apply</button></div>
          </form>
          <table>
            <thead><tr><th>Sr No</th><th>Customer Name</th><th>Actions</th><th>Quotation Amount</th><th>Payment Received</th><th>Receivables</th></tr></thead>
            <tbody>
              <?php foreach ($acceptedRows as $index => $row): ?>
                <?php $quote = $row['quote']; ?>
                <tr>
                  <td><?= $index + 1 ?></td>
                  <td><?= htmlspecialchars((string) ($quote['customer_name'] ?? ''), ENT_QUOTES) ?><?php if (!empty($row['is_archived'])): ?> <span class="pill archived">ARCHIVED</span><?php endif; ?><br><span class="muted"><?= htmlspecialchars((string) ($quote['customer_mobile'] ?? ''), ENT_QUOTES) ?></span></td>
                  <td class="row-actions">
                    <a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'accepted_customers', 'view' => (string) ($quote['id'] ?? ''), 'include_archived_pack' => $includeArchivedPack ? '1' : '0']), ENT_QUOTES) ?>">View</a>
                    <?php if ($isAdmin && empty($row['is_archived'])): ?>
                      <form class="inline-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                        <input type="hidden" name="action" value="archive_accepted_customer" />
                        <input type="hidden" name="quotation_id" value="<?= htmlspecialchars((string) ($quote['id'] ?? ''), ENT_QUOTES) ?>" />
                        <input type="hidden" name="return_tab" value="accepted_customers" />
                        <button class="btn warn" type="submit">Archive</button>
                      </form>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($inr((float) $row['quotation_amount']), ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($inr((float) $row['payment_received']), ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($inr((float) $row['receivables']), ENT_QUOTES) ?><?php if (!empty($row['advance'])): ?><br><span class="muted">(Advance)</span><?php endif; ?><?php if (($row['receivables'] ?? 0) > 0): ?> <span class="pill warn">Due</span><?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if ($acceptedRows === []): ?><tr><td colspan="6" class="muted">No accepted customers found.</td></tr><?php endif; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if ($activeTab === 'document_verification' && $isAdmin): ?>
      <section class="panel">
        <h2 style="margin-top:0;">Document Verification Queue</h2>
        <p class="muted">Shows employee document actions pending verification.</p>
        <form method="post" style="margin-bottom:0.75rem;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
          <select name="verification_status">
            <option value="verified">Mark Verified</option>
            <option value="needs_clarification">Mark Needs Clarification</option>
            <option value="not_verified">Mark Not Verified</option>
          </select>
          <input type="text" name="admin_note" placeholder="Note (required for needs clarification)" style="min-width:320px;" />
          <button class="btn" type="submit" name="action" value="document_verification_bulk_update">Apply Bulk Action</button>
          <table><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.doc-log-select').forEach(cb => cb.checked = this.checked);" /></th><th>Time</th><th>Employee</th><th>Doc Type</th><th>Action</th><th>Quote Ref</th><th>Status</th><th>View Doc</th></tr></thead><tbody>
            <?php foreach ($documentVerificationQueue as $queueRow): ?>
              <?php $log = (array) ($queueRow['log'] ?? []); $verify = (array) ($queueRow['verification'] ?? []); $docType = (string) ($log['doc_type'] ?? ''); $docId = (string) ($log['doc_id'] ?? ''); $quoteId = (string) ($log['quote_id'] ?? ''); $viewHref = ''; if ($docType === 'agreement') { $viewHref = 'agreement-view.php?id=' . urlencode($docId); } elseif ($docType === 'dc') { $viewHref = 'challan-view.php?id=' . urlencode($docId); } elseif ($docType === 'pi') { $viewHref = 'admin-documents.php?action=view_pi_html&pi_id=' . urlencode($docId); } elseif ($docType === 'invoice') { $viewHref = 'admin-invoices.php?id=' . urlencode($docId); } elseif ($docType === 'quotation') { $viewHref = 'quotation-view.php?id=' . urlencode($docId); } elseif ($docType === 'receipt') { $viewHref = 'receipt-view.php?rid=' . urlencode($docId); } ?>
              <tr>
                <td><input class="doc-log-select" type="checkbox" name="log_ids[]" value="<?= htmlspecialchars((string) ($log['id'] ?? ''), ENT_QUOTES) ?>" /></td>
                <td><?= htmlspecialchars((string) ($log['at'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) (($log['actor']['name'] ?? '') . ' [' . ($log['actor']['id'] ?? '') . ']'), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars(strtoupper($docType), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($log['action'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($quoteId, ENT_QUOTES) ?></td>
                <td><span class="pill secondary"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($verify['status'] ?? 'not_verified'))), ENT_QUOTES) ?></span></td>
                <td><?php if ($viewHref !== ''): ?><a class="btn secondary" href="<?= htmlspecialchars($viewHref, ENT_QUOTES) ?>" target="_blank" rel="noopener">View</a><?php else: ?><span class="muted">—</span><?php endif; ?></td>

              </tr>
            <?php endforeach; ?>
            <?php if ($documentVerificationQueue === []): ?><tr><td colspan="8" class="muted">No pending verification records.</td></tr><?php endif; ?>
          </tbody></table>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($activeTab === 'items'): ?>
      <section class="panel">
        <h2 style="margin-top:0;">Items Master &amp; Inventory</h2>
        <nav class="tabs" style="margin-top:0.5rem;">
          <a class="tab <?= $itemsSubtab === 'components' ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'components']), ENT_QUOTES) ?>">Components</a>
          <a class="tab <?= $itemsSubtab === 'kits' ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'kits']), ENT_QUOTES) ?>">Kits</a>
          <a class="tab <?= $itemsSubtab === 'tax_profiles' ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'tax_profiles']), ENT_QUOTES) ?>">Tax Profiles</a>
          <a class="tab <?= $itemsSubtab === 'variants' ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'variants']), ENT_QUOTES) ?>">Variants</a>
          <a class="tab <?= $itemsSubtab === 'locations' ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'locations']), ENT_QUOTES) ?>">Locations</a>
          <a class="tab <?= $itemsSubtab === 'inventory' ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'inventory']), ENT_QUOTES) ?>">Inventory</a>
          <?php if ($isAdmin): ?><a class="tab <?= $itemsSubtab === 'inventory_verification' ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'inventory_verification']), ENT_QUOTES) ?>">Inventory Verification</a><?php endif; ?>
          <a class="tab <?= $itemsSubtab === 'transactions' ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'transactions']), ENT_QUOTES) ?>">Transactions</a>
          <a class="tab <?= $itemsSubtab === 'csv_import' ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'csv_import']), ENT_QUOTES) ?>">CSV Import</a><a class="tab <?= $itemsSubtab === 'archived' ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'archived']), ENT_QUOTES) ?>">Archived</a>
        </nav>

        <?php if ($itemsSubtab === 'components'): ?>
          <h3>Components</h3>
          <?php if ($isAdmin): ?>
            <?php $componentForm = is_array($editingComponent) ? $editingComponent : documents_inventory_component_defaults(); ?>
            <form method="post" class="grid" style="margin-bottom:1rem;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
              <input type="hidden" name="action" value="<?= is_array($editingComponent) ? 'save_component_edit' : 'save_component' ?>" />
              <?php if (is_array($editingComponent)): ?><input type="hidden" name="component_id" value="<?= htmlspecialchars((string) ($componentForm['id'] ?? ''), ENT_QUOTES) ?>" /><?php endif; ?>
              <div><label>Component ID</label><input value="<?= htmlspecialchars((string) ($componentForm['id'] ?? 'Auto'), ENT_QUOTES) ?>" readonly /></div>
              <div><label>Name</label><input name="name" required value="<?= htmlspecialchars((string) ($componentForm['name'] ?? ''), ENT_QUOTES) ?>" /></div>
              <div><label>Category</label><input name="category" value="<?= htmlspecialchars((string) ($componentForm['category'] ?? ''), ENT_QUOTES) ?>" /></div>
              <div><label>HSN</label><input name="hsn" value="<?= htmlspecialchars((string) ($componentForm['hsn'] ?? ''), ENT_QUOTES) ?>" /></div>
              <div><label>Default Unit</label><input name="default_unit" required value="<?= htmlspecialchars((string) ($componentForm['default_unit'] ?? ''), ENT_QUOTES) ?>" /></div>
              <div><label>Tax Profile</label><select name="tax_profile_id"><option value="">-- none --</option><?php foreach ($activeTaxProfiles as $profile): ?><option value="<?= htmlspecialchars((string) ($profile['id'] ?? ''), ENT_QUOTES) ?>" <?= ((string) ($componentForm['tax_profile_id'] ?? '') === (string) ($profile['id'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($profile['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?><?php $componentSelectedTaxProfileId = (string) ($componentForm['tax_profile_id'] ?? ''); $componentSelectedTaxProfile = $componentSelectedTaxProfileId !== '' ? documents_inventory_get_tax_profile($componentSelectedTaxProfileId) : null; if (is_array($componentSelectedTaxProfile) && !empty($componentSelectedTaxProfile['archived_flag'])): ?><option value="<?= htmlspecialchars($componentSelectedTaxProfileId, ENT_QUOTES) ?>" selected><?= htmlspecialchars((string) ($componentSelectedTaxProfile['name'] ?? $componentSelectedTaxProfileId), ENT_QUOTES) ?> (archived)</option><?php endif; ?></select></div>
              <div><label>Standard Length (ft)</label><input type="number" step="0.01" min="0" name="standard_length_ft" value="<?= htmlspecialchars((string) ($componentForm['standard_length_ft'] ?? 0), ENT_QUOTES) ?>" /></div>
              <div><label>Min Issue (ft)</label><input type="number" step="0.01" min="0.01" name="min_issue_ft" value="<?= htmlspecialchars((string) ($componentForm['min_issue_ft'] ?? 1), ENT_QUOTES) ?>" /></div>
              <div><label><input type="checkbox" name="is_cuttable" value="1" <?= !empty($componentForm['is_cuttable']) ? 'checked' : '' ?> /> Cuttable (feet)</label></div>
              <div><label><input type="checkbox" name="has_variants" value="1" <?= !empty($componentForm['has_variants']) ? 'checked' : '' ?> /> Has variants</label></div>
              <div style="grid-column:1/-1"><label>Description</label><textarea name="description"><?= htmlspecialchars((string) ($componentForm['description'] ?? ''), ENT_QUOTES) ?></textarea></div>
              <div style="grid-column:1/-1"><label>Notes</label><textarea name="notes"><?= htmlspecialchars((string) ($componentForm['notes'] ?? ''), ENT_QUOTES) ?></textarea></div>
              <div><label>&nbsp;</label><button class="btn" type="submit"><?= is_array($editingComponent) ? 'Update Component' : 'Save Component' ?></button></div>
            </form>
          <?php endif; ?>
          <table><thead><tr><th>ID</th><th>Name</th><th>Unit</th><th>Tax Profile</th><th>Variants</th><th>Cuttable</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($activeInventoryComponents as $component): ?>
              <tr>
                <td><?= htmlspecialchars((string) ($component['id'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($component['name'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($component['default_unit'] ?? ''), ENT_QUOTES) ?></td>
                <td><?php $cmpTax = documents_inventory_get_tax_profile((string) ($component['tax_profile_id'] ?? '')); ?><?= htmlspecialchars((string) ($cmpTax['name'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= !empty($component['has_variants']) ? 'Yes' : 'No' ?></td><td><?= !empty($component['is_cuttable']) ? 'Yes' : 'No' ?></td>
                <td><?= !empty($component['archived_flag']) ? '<span class="pill archived">Archived</span>' : 'Active' ?></td>
                <td class="row-actions"><?php if ($isAdmin): ?><a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'components', 'sub' => 'components', 'edit' => (string) ($component['id'] ?? '')]), ENT_QUOTES) ?>">Edit</a><a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'components', 'sub' => 'components', 'clone' => (string) ($component['id'] ?? '')]), ENT_QUOTES) ?>">Clone</a><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="action" value="toggle_component_archive" /><input type="hidden" name="component_id" value="<?= htmlspecialchars((string) ($component['id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="archive_state" value="<?= !empty($component['archived_flag']) ? 'unarchive' : 'archive' ?>" /><button class="btn secondary" type="submit"><?= !empty($component['archived_flag']) ? 'Unarchive' : 'Archive' ?></button></form><?php else: ?><span class="muted">View only</span><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table>
        <?php elseif ($itemsSubtab === 'kits'): ?>
          <h3>Kits</h3>
          <?php if ($isAdmin): ?>
            <?php $kitForm = is_array($editingKit) ? $editingKit : documents_inventory_kit_defaults(); ?>
            <?php $kitItemsByComponent = []; foreach ((array) ($kitForm['items'] ?? []) as $kitItem) { if (!is_array($kitItem)) { continue; } $cid = (string) ($kitItem['component_id'] ?? ''); if ($cid === '') { continue; } $kitItemsByComponent[$cid] = $kitItem; } ?>
            <form method="post" style="margin-bottom:1rem;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
              <input type="hidden" name="action" value="<?= is_array($editingKit) ? 'save_kit_edit' : 'save_kit_create' ?>" />
              <?php if (is_array($editingKit)): ?><input type="hidden" name="kit_id" value="<?= htmlspecialchars((string) ($kitForm['id'] ?? ''), ENT_QUOTES) ?>" /><?php endif; ?>
              <div class="grid">
                <div><label>Kit ID</label><input value="<?= htmlspecialchars((string) ($kitForm['id'] ?? 'Auto'), ENT_QUOTES) ?>" readonly /></div>
                <div><label>Name</label><input name="name" required value="<?= htmlspecialchars((string) ($kitForm['name'] ?? ''), ENT_QUOTES) ?>" /></div>
                <div><label>Category</label><input name="category" value="<?= htmlspecialchars((string) ($kitForm['category'] ?? ''), ENT_QUOTES) ?>" /></div>
                <div><label>Tax</label><select name="tax_profile_id"><option value="">-- none --</option><?php foreach ($activeTaxProfiles as $profile): ?><option value="<?= htmlspecialchars((string) ($profile['id'] ?? ''), ENT_QUOTES) ?>" <?= ((string) ($kitForm['tax_profile_id'] ?? '') === (string) ($profile['id'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($profile['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?><?php $kitSelectedTaxProfileId = (string) ($kitForm['tax_profile_id'] ?? ''); $kitSelectedTaxProfile = $kitSelectedTaxProfileId !== '' ? documents_inventory_get_tax_profile($kitSelectedTaxProfileId) : null; if (is_array($kitSelectedTaxProfile) && !empty($kitSelectedTaxProfile['archived_flag'])): ?><option value="<?= htmlspecialchars($kitSelectedTaxProfileId, ENT_QUOTES) ?>" selected><?= htmlspecialchars((string) ($kitSelectedTaxProfile['name'] ?? $kitSelectedTaxProfileId), ENT_QUOTES) ?> (archived)</option><?php endif; ?></select></div>
                <div style="grid-column:1/-1"><label>Description</label><textarea name="description"><?= htmlspecialchars((string) ($kitForm['description'] ?? ''), ENT_QUOTES) ?></textarea></div>
              </div>
              <h4>Select Components for this Kit</h4>
              <div><label>Search Components</label><input type="text" id="kitComponentSearch" placeholder="Search component name/category" /></div>
              <table id="kitComponentSelector"><thead><tr><th>Select</th><th>Name</th><th>Default Unit</th><th>Cuttable?</th><th>Has Variants?</th><th>Tax Profile</th></tr></thead><tbody>
              <?php foreach ($inventoryComponents as $component): if (!is_array($component) || !empty($component['archived_flag'])) { continue; } $cid = (string) ($component['id'] ?? ''); $cmpTax = documents_inventory_get_tax_profile((string) ($component['tax_profile_id'] ?? '')); ?>
                <tr data-kit-component-row="1">
                  <td><input type="checkbox" class="kit-component-checkbox" data-component-id="<?= htmlspecialchars($cid, ENT_QUOTES) ?>" <?= isset($kitItemsByComponent[$cid]) ? 'checked' : '' ?> /></td>
                  <td><?= htmlspecialchars((string) ($component['name'] ?? ''), ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars((string) ($component['default_unit'] ?? ''), ENT_QUOTES) ?></td>
                  <td><?= !empty($component['is_cuttable']) ? 'Yes' : 'No' ?></td>
                  <td><?= !empty($component['has_variants']) ? 'Yes' : 'No' ?></td>
                  <td><?= htmlspecialchars((string) ($cmpTax['name'] ?? ''), ENT_QUOTES) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody></table>
              <h4>Selected Components</h4>
              <p class="muted">Formula supports: digits, kwp, + - * / and parentheses. Example: <code>kwp * 25</code></p>
              <table id="kitSelectedBomTable"><thead><tr><th>Component</th><th>Mode</th><th>Config</th><th>Unit</th><th>Remarks</th><th>Remove</th></tr></thead><tbody>
              <?php foreach ($inventoryComponents as $component): if (!is_array($component) || !empty($component['archived_flag'])) { continue; } $cid=(string)($component['id']??''); $selected = isset($kitItemsByComponent[$cid]); $item=$selected ? (array)$kitItemsByComponent[$cid] : []; $unitDefault = !empty($component['is_cuttable']) ? 'ft' : (string)($component['default_unit']??'pcs'); $lineMode = (string)($item['mode'] ?? (($item['qty'] ?? 0) > 0 ? 'fixed_qty' : 'fixed_qty')); $lineFixedQty = (float)($item['fixed_qty'] ?? ($item['qty'] ?? 0)); $capacityRule = is_array($item['capacity_rule'] ?? null) ? $item['capacity_rule'] : []; $capType = (string)($capacityRule['type'] ?? 'formula'); $capExpr = (string)($capacityRule['expr'] ?? 'kwp * 1'); $slabs = is_array($capacityRule['slabs'] ?? null) ? $capacityRule['slabs'] : []; if ($slabs === []) { $slabs = [['kwp_min' => 0, 'kwp_max' => 0, 'qty' => 0]]; } $ruleCfg = is_array($item['rule'] ?? null) ? $item['rule'] : []; $ruleType = (string)($ruleCfg['rule_type'] ?? 'min_total_wp'); $ruleTarget = (string)($ruleCfg['target_expr'] ?? 'kwp * 1000'); $ruleOverbuild = (float)($ruleCfg['allow_overbuild_pct'] ?? 0); ?>
                <tr class="kit-bom-row" data-component-id="<?= htmlspecialchars($cid, ENT_QUOTES) ?>" style="<?= $selected ? '' : 'display:none;' ?>">
                  <td><?= htmlspecialchars((string) ($component['name'] ?? ''), ENT_QUOTES) ?><input type="hidden" class="kit-selected-component-id" name="selected_component_ids[]" value="<?= $selected ? htmlspecialchars($cid, ENT_QUOTES) : '' ?>" /><input type="hidden" name="bom_line_id[<?= htmlspecialchars($cid, ENT_QUOTES) ?>]" value="<?= htmlspecialchars((string)($item['line_id'] ?? ''), ENT_QUOTES) ?>" /></td>
                  <td><select class="kit-bom-mode" name="bom_mode[<?= htmlspecialchars($cid, ENT_QUOTES) ?>]"><option value="fixed_qty" <?= $lineMode==='fixed_qty'?'selected':'' ?>>Fixed</option><option value="capacity_qty" <?= $lineMode==='capacity_qty'?'selected':'' ?>>Capacity-based</option><option value="rule_fulfillment" <?= $lineMode==='rule_fulfillment'?'selected':'' ?>>Rule-fulfillment</option><option value="unfixed_manual" <?= $lineMode==='unfixed_manual'?'selected':'' ?>>Manual</option></select></td>
                  <td>
                    <div class="bom-mode-panel" data-mode="fixed_qty" style="<?= $lineMode==='fixed_qty'?'':'display:none;' ?>"><label>Fixed Qty</label><input type="number" step="0.01" min="0" name="bom_qty[<?= htmlspecialchars($cid, ENT_QUOTES) ?>]" value="<?= $selected ? htmlspecialchars((string)$lineFixedQty, ENT_QUOTES) : '' ?>" /></div>
                    <div class="bom-mode-panel" data-mode="capacity_qty" style="<?= $lineMode==='capacity_qty'?'':'display:none;' ?>"><label>Capacity Rule Type</label><select name="bom_capacity_type[<?= htmlspecialchars($cid, ENT_QUOTES) ?>]" class="kit-capacity-type"><option value="formula" <?= $capType==='formula'?'selected':'' ?>>Formula</option><option value="slab" <?= $capType==='slab'?'selected':'' ?>>Slab</option></select><div class="kit-capacity-formula" style="<?= $capType==='formula'?'':'display:none;' ?>"><label>Formula</label><input name="bom_capacity_expr[<?= htmlspecialchars($cid, ENT_QUOTES) ?>]" value="<?= htmlspecialchars($capExpr, ENT_QUOTES) ?>" placeholder="kwp * 25" /></div><div class="kit-capacity-slabs" style="<?= $capType==='slab'?'':'display:none;' ?>"><?php foreach ($slabs as $slab): ?><div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px;margin-bottom:4px;"><input type="number" step="0.01" name="bom_capacity_slab_min[<?= htmlspecialchars($cid, ENT_QUOTES) ?>][]" placeholder="kWp min" value="<?= htmlspecialchars((string)($slab['kwp_min'] ?? 0), ENT_QUOTES) ?>" /><input type="number" step="0.01" name="bom_capacity_slab_max[<?= htmlspecialchars($cid, ENT_QUOTES) ?>][]" placeholder="kWp max" value="<?= htmlspecialchars((string)($slab['kwp_max'] ?? 0), ENT_QUOTES) ?>" /><input type="number" step="0.01" min="0" name="bom_capacity_slab_qty[<?= htmlspecialchars($cid, ENT_QUOTES) ?>][]" placeholder="Qty" value="<?= htmlspecialchars((string)($slab['qty'] ?? 0), ENT_QUOTES) ?>" /></div><?php endforeach; ?></div></div>
                    <div class="bom-mode-panel" data-mode="rule_fulfillment" style="<?= $lineMode==='rule_fulfillment'?'':'display:none;' ?>"><label>Rule</label><select name="bom_rule_type[<?= htmlspecialchars($cid, ENT_QUOTES) ?>]"><option value="min_total_wp" <?= $ruleType==='min_total_wp'?'selected':'' ?>>Panels by Wp</option></select><label>Target Expr</label><input name="bom_rule_target_expr[<?= htmlspecialchars($cid, ENT_QUOTES) ?>]" value="<?= htmlspecialchars($ruleTarget, ENT_QUOTES) ?>" placeholder="kwp * 1000" /><label>Allow overbuild %</label><input type="number" step="0.01" min="0" name="bom_rule_overbuild[<?= htmlspecialchars($cid, ENT_QUOTES) ?>]" value="<?= htmlspecialchars((string)$ruleOverbuild, ENT_QUOTES) ?>" /></div>
                    <div class="bom-mode-panel" data-mode="unfixed_manual" style="<?= $lineMode==='unfixed_manual'?'':'display:none;' ?>"><label>Manual note</label><textarea name="bom_manual_note[<?= htmlspecialchars($cid, ENT_QUOTES) ?>]"><?= htmlspecialchars((string)($item['manual_note'] ?? ''), ENT_QUOTES) ?></textarea></div>
                  </td>
                  <td><input name="bom_unit[<?= htmlspecialchars($cid, ENT_QUOTES) ?>]" value="<?= $selected ? htmlspecialchars((string) ($item['unit'] ?? $unitDefault), ENT_QUOTES) : htmlspecialchars($unitDefault, ENT_QUOTES) ?>" <?= !empty($component['is_cuttable']) ? 'readonly' : '' ?> /></td>
                  <td><input name="bom_remarks[<?= htmlspecialchars($cid, ENT_QUOTES) ?>]" value="<?= $selected ? htmlspecialchars((string) ($item['remarks'] ?? ''), ENT_QUOTES) : '' ?>" /></td>
                  <td><button type="button" class="btn secondary kit-remove-component" data-component-id="<?= htmlspecialchars($cid, ENT_QUOTES) ?>">Remove</button></td>
                </tr>
              <?php endforeach; ?>
              </tbody></table>
              <button class="btn" type="submit"><?= is_array($editingKit) ? 'Update Kit BOM' : 'Create Kit BOM' ?></button>
            </form>
          <?php else: ?><p class="muted">Read-only for employees.</p><?php endif; ?>
          <table><thead><tr><th>ID</th><th>Name</th><th>Category</th><th>Tax</th><th>Items</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach ($activeInventoryKits as $kit): ?><tr><td><?= htmlspecialchars((string) ($kit['id'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($kit['name'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($kit['category'] ?? ''), ENT_QUOTES) ?></td><td><?php $kitTax = documents_inventory_get_tax_profile((string) ($kit['tax_profile_id'] ?? '')); ?><?= htmlspecialchars((string) ($kitTax['name'] ?? ''), ENT_QUOTES) ?></td><td><?= count(documents_inventory_kit_active_items((array) $kit)) ?></td><td><?= !empty($kit['archived_flag']) ? '<span class="pill archived">Archived</span>' : 'Active' ?></td><td class="row-actions"><?php if ($isAdmin): ?><a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'kits', 'sub' => 'kits', 'edit' => (string) ($kit['id'] ?? '')]), ENT_QUOTES) ?>">Edit</a><a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'kits', 'sub' => 'kits', 'clone' => (string) ($kit['id'] ?? '')]), ENT_QUOTES) ?>">Clone</a><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="action" value="toggle_kit_archive" /><input type="hidden" name="kit_id" value="<?= htmlspecialchars((string) ($kit['id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="archive_state" value="<?= !empty($kit['archived_flag']) ? 'unarchive' : 'archive' ?>" /><button class="btn secondary" type="submit"><?= !empty($kit['archived_flag']) ? 'Unarchive' : 'Archive' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
        <?php elseif ($itemsSubtab === 'tax_profiles'): ?>
          <h3>Tax Profiles</h3>
          <?php if ($isAdmin): ?>
            <?php $taxForm = is_array($editingTaxProfile) ? $editingTaxProfile : documents_tax_profile_defaults(); $taxSlabs = is_array($taxForm['slabs'] ?? null) ? $taxForm['slabs'] : []; if ($taxSlabs === []) { $taxSlabs = [['share_pct' => 100, 'rate_pct' => 0]]; } ?>
            <form method="post" class="grid" style="margin-bottom:1rem;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
              <input type="hidden" name="action" value="<?= is_array($editingTaxProfile) ? 'save_tax_profile_edit' : 'save_tax_profile' ?>" />
              <?php if (is_array($editingTaxProfile)): ?><input type="hidden" name="tax_profile_id" value="<?= htmlspecialchars((string) ($taxForm['id'] ?? ''), ENT_QUOTES) ?>" /><?php endif; ?>
              <div><label>Profile ID</label><input value="<?= htmlspecialchars((string) ($taxForm['id'] ?? 'Auto'), ENT_QUOTES) ?>" readonly /></div>
              <div><label>Name</label><input name="name" required value="<?= htmlspecialchars((string) ($taxForm['name'] ?? ''), ENT_QUOTES) ?>" /></div>
              <div><label>Mode</label><select name="mode"><option value="single" <?= ((string) ($taxForm['mode'] ?? '') === 'single') ? 'selected' : '' ?>>Single</option><option value="split" <?= ((string) ($taxForm['mode'] ?? '') === 'split') ? 'selected' : '' ?>>Split</option></select></div>
              <div style="grid-column:1/-1"><label>Notes</label><textarea name="notes"><?= htmlspecialchars((string) ($taxForm['notes'] ?? ''), ENT_QUOTES) ?></textarea></div>
              <div style="grid-column:1/-1">
                <table id="taxSlabsTable"><thead><tr><th>Share %</th><th>Rate %</th></tr></thead><tbody>
                <?php foreach ($taxSlabs as $slab): ?>
                  <tr><td><input type="number" step="0.01" min="0" max="100" name="slab_share_pct[]" value="<?= htmlspecialchars((string) ($slab['share_pct'] ?? 0), ENT_QUOTES) ?>" /></td><td><input type="number" step="0.01" min="0" name="slab_rate_pct[]" value="<?= htmlspecialchars((string) ($slab['rate_pct'] ?? 0), ENT_QUOTES) ?>" /></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <button type="button" class="btn secondary" id="addTaxSlabBtn">Add slab</button>
              </div>
              <div><label>&nbsp;</label><button class="btn" type="submit"><?= is_array($editingTaxProfile) ? 'Update Tax Profile' : 'Save Tax Profile' ?></button></div>
            </form>
          <?php endif; ?>
          <table><thead><tr><th>ID</th><th>Name</th><th>Mode</th><th>Slabs</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach ($activeTaxProfiles as $profile): ?><tr><td><?= htmlspecialchars((string) ($profile['id'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($profile['name'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($profile['mode'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) json_encode((array) ($profile['slabs'] ?? [])), ENT_QUOTES) ?></td><td><?= !empty($profile['archived_flag']) ? '<span class="pill archived">Archived</span>' : 'Active' ?></td><td class="row-actions"><?php if ($isAdmin): ?><a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'tax_profiles', 'sub' => 'tax_profiles', 'edit' => (string) ($profile['id'] ?? '')]), ENT_QUOTES) ?>">Edit</a><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="action" value="toggle_tax_profile_archive" /><input type="hidden" name="tax_profile_id" value="<?= htmlspecialchars((string) ($profile['id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="archive_state" value="<?= !empty($profile['archived_flag']) ? 'unarchive' : 'archive' ?>" /><button class="btn secondary" type="submit"><?= !empty($profile['archived_flag']) ? 'Unarchive' : 'Archive' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
        <?php elseif ($itemsSubtab === 'variants'): ?>
          <h3>Component Variants</h3>
          <?php if ($isAdmin): ?>
            <?php $variantForm = is_array($editingVariant) ? $editingVariant : documents_component_variant_defaults(); ?>
            <form method="post" class="grid" style="margin-bottom:1rem;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
              <input type="hidden" name="action" value="<?= is_array($editingVariant) ? 'save_variant_edit' : 'save_variant' ?>" />
              <?php if (is_array($editingVariant)): ?><input type="hidden" name="variant_id" value="<?= htmlspecialchars((string) ($variantForm['id'] ?? ''), ENT_QUOTES) ?>" /><?php endif; ?>
              <div><label>Variant ID</label><input value="<?= htmlspecialchars((string) ($variantForm['id'] ?? 'Auto'), ENT_QUOTES) ?>" readonly /></div>
              <div><label>Component</label><select name="component_id" required><option value="">-- select --</option><?php foreach ($activeInventoryComponents as $component): ?><option value="<?= htmlspecialchars((string) ($component['id'] ?? ''), ENT_QUOTES) ?>" <?= ((string) ($variantForm['component_id'] ?? '') === (string) ($component['id'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($component['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?><?php $variantSelectedComponentId = (string) ($variantForm['component_id'] ?? ''); $variantSelectedComponent = $variantSelectedComponentId !== '' ? documents_inventory_get_component($variantSelectedComponentId) : null; if (is_array($variantSelectedComponent) && !empty($variantSelectedComponent['archived_flag'])): ?><option value="<?= htmlspecialchars($variantSelectedComponentId, ENT_QUOTES) ?>" selected><?= htmlspecialchars((string) ($variantSelectedComponent['name'] ?? $variantSelectedComponentId), ENT_QUOTES) ?> (archived)</option><?php endif; ?></select></div>
              <div><label>Display Name</label><input name="display_name" value="<?= htmlspecialchars((string) ($variantForm['display_name'] ?? ''), ENT_QUOTES) ?>" /></div>
              <div><label>Brand</label><input name="brand" value="<?= htmlspecialchars((string) ($variantForm['brand'] ?? ''), ENT_QUOTES) ?>" /></div>
              <div><label>Technology</label><input name="technology" value="<?= htmlspecialchars((string) ($variantForm['technology'] ?? ''), ENT_QUOTES) ?>" /></div>
              <div><label>Wattage</label><input type="number" step="0.01" min="0" name="wattage_wp" value="<?= htmlspecialchars((string) ($variantForm['wattage_wp'] ?? ''), ENT_QUOTES) ?>" /></div>
              <div><label>Model No</label><input name="model_no" value="<?= htmlspecialchars((string) ($variantForm['model_no'] ?? ''), ENT_QUOTES) ?>" /></div>
              <div><label>HSN Override</label><input name="hsn_override" value="<?= htmlspecialchars((string) ($variantForm['hsn_override'] ?? ''), ENT_QUOTES) ?>" /></div>
              <div><label>Tax Override</label><select name="tax_profile_id_override"><option value="">-- none --</option><?php foreach ($activeTaxProfiles as $profile): ?><option value="<?= htmlspecialchars((string) ($profile['id'] ?? ''), ENT_QUOTES) ?>" <?= ((string) ($variantForm['tax_profile_id_override'] ?? '') === (string) ($profile['id'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($profile['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
              <div><label>Unit Override</label><input name="default_unit_override" value="<?= htmlspecialchars((string) ($variantForm['default_unit_override'] ?? ''), ENT_QUOTES) ?>" /></div>
              <div style="grid-column:1/-1"><label>Notes</label><textarea name="notes"><?= htmlspecialchars((string) ($variantForm['notes'] ?? ''), ENT_QUOTES) ?></textarea></div>
              <div><label>&nbsp;</label><button class="btn" type="submit"><?= is_array($editingVariant) ? 'Update Variant' : 'Save Variant' ?></button></div>
            </form>
          <?php endif; ?>
          <table><thead><tr><th>ID</th><th>Component</th><th>Display</th><th>Specs</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach ($activeInventoryVariants as $variant): ?><tr><td><?= htmlspecialchars((string) ($variant['id'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) (($componentMap[(string) ($variant['component_id'] ?? '')]['name'] ?? $variant['component_id'] ?? '')), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($variant['display_name'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) trim(((string) ($variant['brand'] ?? '')) . ' ' . ((string) ($variant['technology'] ?? '')) . ' ' . ((string) ($variant['model_no'] ?? ''))), ENT_QUOTES) ?></td><td><?= !empty($variant['archived_flag']) ? '<span class="pill archived">Archived</span>' : 'Active' ?></td><td class="row-actions"><?php if ($isAdmin): ?><a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'variants', 'sub' => 'variants', 'edit' => (string) ($variant['id'] ?? '')]), ENT_QUOTES) ?>">Edit</a><a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'variants', 'sub' => 'variants', 'clone' => (string) ($variant['id'] ?? '')]), ENT_QUOTES) ?>">Clone</a><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="action" value="toggle_variant_archive" /><input type="hidden" name="variant_id" value="<?= htmlspecialchars((string) ($variant['id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="archive_state" value="<?= !empty($variant['archived_flag']) ? 'unarchive' : 'archive' ?>" /><button class="btn secondary" type="submit"><?= !empty($variant['archived_flag']) ? 'Unarchive' : 'Archive' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
        <?php elseif ($itemsSubtab === 'locations'): ?>
          <h3>Inventory Locations</h3>
          <?php if ($isAdmin): ?>
            <?php $locationForm = is_array($editingLocation) ? $editingLocation : documents_inventory_location_defaults(); ?>
            <form method="post" class="grid" style="margin-bottom:1rem;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
              <input type="hidden" name="action" value="<?= is_array($editingLocation) ? 'save_location_edit' : 'save_location' ?>" />
              <?php if (is_array($editingLocation)): ?><input type="hidden" name="location_id" value="<?= htmlspecialchars((string) ($locationForm['id'] ?? ''), ENT_QUOTES) ?>" /><?php endif; ?>
              <div><label>Location ID</label><input value="<?= htmlspecialchars((string) ($locationForm['id'] ?? 'Auto'), ENT_QUOTES) ?>" readonly /></div>
              <div><label>Name</label><input name="name" required value="<?= htmlspecialchars((string) ($locationForm['name'] ?? ''), ENT_QUOTES) ?>" /></div>
              <div><label>Type</label><input name="type" placeholder="warehouse / rack / site" value="<?= htmlspecialchars((string) ($locationForm['type'] ?? ''), ENT_QUOTES) ?>" /></div>
              <div style="grid-column:1/-1"><label>Notes</label><textarea name="notes"><?= htmlspecialchars((string) ($locationForm['notes'] ?? ''), ENT_QUOTES) ?></textarea></div>
              <div><label>&nbsp;</label><button class="btn" type="submit"><?= is_array($editingLocation) ? 'Update Location' : 'Save Location' ?></button></div>
            </form>
          <?php endif; ?>
          <table><thead><tr><th>ID</th><th>Name</th><th>Type</th><th>Notes</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach ($activeInventoryLocations as $location): ?><tr><td><?= htmlspecialchars((string) ($location['id'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($location['name'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($location['type'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($location['notes'] ?? ''), ENT_QUOTES) ?></td><td><?= !empty($location['archived_flag']) ? '<span class="pill archived">Archived</span>' : 'Active' ?></td><td class="row-actions"><?php if ($isAdmin): ?><a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'locations', 'edit' => (string) ($location['id'] ?? '')]), ENT_QUOTES) ?>">Edit</a><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="action" value="toggle_location_archive" /><input type="hidden" name="location_id" value="<?= htmlspecialchars((string) ($location['id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="archive_state" value="<?= !empty($location['archived_flag']) ? 'unarchive' : 'archive' ?>" /><button class="btn secondary" type="submit"><?= !empty($location['archived_flag']) ? 'Unarchive' : 'Archive' ?></button></form><?php else: ?><span class="muted">View only</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>

        <?php elseif ($itemsSubtab === 'archived'): ?>
          <h2>Archived Masters</h2>
          <form method="get" class="inline-form" style="margin-bottom:10px;display:flex;gap:8px;flex-wrap:wrap">
            <input type="hidden" name="tab" value="items" />
            <input type="hidden" name="items_subtab" value="archived" />
            <select name="archived_type"><option value="all" <?= $archivedItemsType === 'all' ? 'selected' : '' ?>>All</option><option value="component" <?= $archivedItemsType === 'component' ? 'selected' : '' ?>>Components</option><option value="kit" <?= $archivedItemsType === 'kit' ? 'selected' : '' ?>>Kits</option><option value="variant" <?= $archivedItemsType === 'variant' ? 'selected' : '' ?>>Variants</option><option value="tax_profile" <?= $archivedItemsType === 'tax_profile' ? 'selected' : '' ?>>Tax Profiles</option><option value="location" <?= $archivedItemsType === 'location' ? 'selected' : '' ?>>Locations</option></select>
            <input name="archived_q" value="<?= htmlspecialchars($archivedItemsQuery, ENT_QUOTES) ?>" placeholder="Search" />
            <button class="btn secondary" type="submit">Filter</button>
          </form>
          <table><thead><tr><th>Type</th><th>ID</th><th>Name</th><th>Archived At</th></tr></thead><tbody>
            <?php foreach ($archivedItemsRows as $row): ?>
              <tr><td><?= htmlspecialchars((string) ($row['label'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($row['archived_at'] ?? ''), ENT_QUOTES) ?></td></tr>
            <?php endforeach; ?>
            <?php if ($archivedItemsRows === []): ?><tr><td colspan="4" class="muted">No archived masters found.</td></tr><?php endif; ?>
          </tbody></table>
        
<?php elseif ($itemsSubtab === 'csv_import'): ?>
          <h3>CSV Imports</h3>
          <p class="muted">Import masters using CSV (admin-only) and Stock IN transactions (admin + employee).</p>
          <?php if (is_array($csvImportReport)): ?>
            <div class="pill <?= ((int) ($csvImportReport['rows_failed'] ?? 0)) > 0 ? 'warn' : '' ?>" style="display:block;padding:0.5rem 0.75rem;margin-bottom:0.75rem;">
              Rows read: <?= (int) ($csvImportReport['rows_read'] ?? 0) ?>,
              succeeded: <?= (int) ($csvImportReport['rows_succeeded'] ?? 0) ?>,
              failed: <?= (int) ($csvImportReport['rows_failed'] ?? 0) ?>
            </div>
            <?php if (!empty($csvImportReport['errors']) && is_array($csvImportReport['errors'])): ?>
              <table style="margin-bottom:1rem;"><thead><tr><th>Row</th><th>Error</th></tr></thead><tbody>
                <?php foreach ($csvImportReport['errors'] as $err): if (!is_array($err)) { continue; } ?>
                  <tr><td><?= (int) ($err['row'] ?? 0) ?></td><td><?= htmlspecialchars((string) ($err['message'] ?? ''), ENT_QUOTES) ?></td></tr>
                <?php endforeach; ?>
              </tbody></table>
            <?php endif; ?>
          <?php endif; ?>

          <div class="grid" style="margin-bottom:1rem;">
            <?php if ($isAdmin): ?>
              <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                <input type="hidden" name="action" value="import_components_csv" />
                <h4>Import Components</h4>
                <input type="file" name="csv_file" accept=".csv,text/csv" required />
                <div style="margin-top:0.5rem;"><button class="btn" type="submit">Import</button> <a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'csv_import', 'download_sample' => 'components']), ENT_QUOTES) ?>">Download Sample CSV</a></div>
              </form>

              <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                <input type="hidden" name="action" value="import_variants_csv" />
                <h4>Import Variants</h4>
                <input type="file" name="csv_file" accept=".csv,text/csv" required />
                <div style="margin-top:0.5rem;"><button class="btn" type="submit">Import</button> <a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'csv_import', 'download_sample' => 'variants']), ENT_QUOTES) ?>">Download Sample CSV</a></div>
              </form>

              <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                <input type="hidden" name="action" value="import_locations_csv" />
                <h4>Import Locations</h4>
                <input type="file" name="csv_file" accept=".csv,text/csv" required />
                <div style="margin-top:0.5rem;"><button class="btn" type="submit">Import</button> <a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'csv_import', 'download_sample' => 'locations']), ENT_QUOTES) ?>">Download Sample CSV</a></div>
              </form>

              <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                <input type="hidden" name="action" value="import_kits_csv" />
                <h4>Import Kits (2 files)</h4>
                <label>Kits CSV</label><input type="file" name="kits_csv" accept=".csv,text/csv" required />
                <label>Kit Items CSV</label><input type="file" name="kit_items_csv" accept=".csv,text/csv" required />
                <div style="margin-top:0.5rem;"><button class="btn" type="submit">Import</button> <a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'csv_import', 'download_sample' => 'kits']), ENT_QUOTES) ?>">Sample kits.csv</a> <a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'csv_import', 'download_sample' => 'kit_items']), ENT_QUOTES) ?>">Sample kit_items.csv</a></div>
              </form>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
              <input type="hidden" name="action" value="import_inventory_stock_in_csv" />
              <h4>Import Stock IN Transactions</h4>
              <input type="file" name="csv_file" accept=".csv,text/csv" required />
              <div style="margin-top:0.5rem;"><button class="btn" type="submit">Import</button> <a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'csv_import', 'download_sample' => 'stock_in']), ENT_QUOTES) ?>">Download Sample CSV</a></div>
            </form>
          </div>

        <?php elseif ($itemsSubtab === 'inventory'): ?>
          <h3>Inventory Transactions</h3>
          <div class="grid" style="margin-bottom:1rem;">
            <form method="post" data-inventory-form="1" data-tx-type="IN">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
              <input type="hidden" name="action" value="create_inventory_tx" />
              <input type="hidden" name="tx_type" value="IN" />
              <h4>Add Stock (IN)</h4>
              <div><label>Component</label><select name="component_id" required><option value="">-- select --</option><?php foreach ($inventoryComponents as $component): if (!empty($component['archived_flag'])) { continue; } ?><option value="<?= htmlspecialchars((string) ($component['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($component['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
              <div data-variant-wrap="1"><label>Variant</label><select name="variant_id"><option value="">-- select variant --</option></select><small class="muted" data-variant-empty="1" style="display:none;">No variants found for selected component.</small></div>
              <div data-non-cuttable-wrap="1"><label>Qty (non-cuttable)</label><input type="number" step="0.01" min="0" name="qty" /></div>
              <div data-cuttable-wrap="1"><label>Piece Count (cuttable)</label><input type="number" step="1" min="1" name="cut_piece_count" /></div>
              <div data-cuttable-wrap="1"><label>Piece Length (ft each, cuttable)</label><input type="number" step="0.01" min="0.01" name="cut_piece_length_ft" /></div>
              <div><label>Location</label><select name="location_id"><option value="">Unassigned</option><?php foreach ($activeInventoryLocations as $location): ?><option value="<?= htmlspecialchars((string) ($location['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($location['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
              <div><label>Notes</label><input name="notes" /></div>
              <button class="btn" type="submit">Create IN</button>
            </form>
            <form method="post" data-inventory-form="1" data-tx-type="OUT">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
              <input type="hidden" name="action" value="create_inventory_tx" />
              <input type="hidden" name="tx_type" value="OUT" />
              <h4>Issue Stock (OUT)</h4>
              <div><label>Component</label><select name="component_id" required><option value="">-- select --</option><?php foreach ($inventoryComponents as $component): if (!empty($component['archived_flag'])) { continue; } ?><option value="<?= htmlspecialchars((string) ($component['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($component['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
              <div data-variant-wrap="1"><label>Variant</label><select name="variant_id"><option value="">-- select variant --</option></select><small class="muted" data-variant-empty="1" style="display:none;">No variants found for selected component.</small></div>
              <div data-non-cuttable-wrap="1"><label>Qty</label><input type="number" step="0.01" min="0" name="qty" /></div>
              <div data-cuttable-wrap="1"><label>Length (ft for cuttable)</label><input type="number" step="0.01" min="0" name="length_ft" /></div>
              <div><label>Consume from location (optional)</label><select name="consume_location_id"><option value="">Auto</option><?php foreach ($activeInventoryLocations as $location): ?><option value="<?= htmlspecialchars((string) ($location['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($location['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
              <div><label>Notes</label><input name="notes" /></div>
              <button class="btn secondary" type="submit">Create OUT</button>
            </form>

            <form method="post" data-inventory-form="1" data-tx-type="MOVE">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
              <input type="hidden" name="action" value="create_inventory_tx" />
              <input type="hidden" name="tx_type" value="MOVE" />
              <h4>Move Stock (MOVE)</h4>
              <div><label>Component</label><select name="component_id" required><option value="">-- select --</option><?php foreach ($inventoryComponents as $component): if (!empty($component['archived_flag'])) { continue; } ?><option value="<?= htmlspecialchars((string) ($component['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($component['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
              <div data-variant-wrap="1"><label>Variant</label><select name="variant_id"><option value="">-- select variant --</option></select><small class="muted" data-variant-empty="1" style="display:none;">No variants found for selected component.</small></div>
              <div data-non-cuttable-wrap="1"><label>Batch ID (for qty stock)</label><input name="move_batch_id" placeholder="Optional" /></div>
              <div data-cuttable-wrap="1"><label>Lot ID (for cuttable)</label><input name="move_lot_id" placeholder="Optional" /></div>
              <div data-non-cuttable-wrap="1"><label>Qty to move (non-cuttable)</label><input type="number" step="0.01" min="0" name="qty" /></div>
              <div><label>From location (optional)</label><select name="from_location_id"><option value="">Any</option><?php foreach ($activeInventoryLocations as $location): ?><option value="<?= htmlspecialchars((string) ($location['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($location['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
              <div><label>To location</label><select name="to_location_id" required><option value="">-- select --</option><?php foreach ($activeInventoryLocations as $location): ?><option value="<?= htmlspecialchars((string) ($location['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($location['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
              <div><label>Notes</label><input name="notes" /></div>
              <button class="btn" type="submit">Create MOVE</button>
            </form>

            <?php if ($isAdmin): ?>
            <form method="post" data-inventory-form="1" data-tx-type="ADJUST">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
              <input type="hidden" name="action" value="create_inventory_tx" />
              <input type="hidden" name="tx_type" value="ADJUST" />
              <h4>Adjust Stock (ADJUST)</h4>
              <div><label>Component</label><select name="component_id" required><option value="">-- select --</option><?php foreach ($inventoryComponents as $component): if (!empty($component['archived_flag'])) { continue; } ?><option value="<?= htmlspecialchars((string) ($component['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($component['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
              <div data-variant-wrap="1"><label>Variant</label><select name="variant_id"><option value="">-- select variant --</option></select><small class="muted" data-variant-empty="1" style="display:none;">No variants found for selected component.</small></div>
              <div data-non-cuttable-wrap="1"><label>Qty</label><input type="number" step="0.01" min="0" name="qty" /></div>
              <div data-cuttable-wrap="1"><label>Length (ft)</label><input type="number" step="0.01" min="0" name="length_ft" /></div>
              <div><label>Notes</label><input name="notes" /></div>
              <button class="btn warn" type="submit">Create ADJUST</button>
            </form>
            <?php endif; ?>
          </div>

          <h3>Inventory Summary</h3>
          <div style="display:flex;gap:8px;align-items:center;margin:8px 0 12px">
            <a class="btn <?= $inventoryStockView === 'active' ? '' : 'secondary' ?>" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'inventory', 'inventory_view' => 'active', 'edit_mode' => $inventoryEditMode ? '1' : '0']), ENT_QUOTES) ?>">Active Stock</a>
            <a class="btn <?= $inventoryStockView === 'finished' ? '' : 'secondary' ?>" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'inventory', 'inventory_view' => 'finished', 'edit_mode' => $inventoryEditMode ? '1' : '0']), ENT_QUOTES) ?>">Finished Stock</a>
          </div>
          <?php if ($isAdmin || $isEmployee): ?>
            <div style="display:flex;gap:0.5rem;align-items:center;margin:0.75rem 0;">
              <?php if ($inventoryEditMode): ?>
                <a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'inventory']), ENT_QUOTES) ?>">Cancel</a>
              <?php else: ?>
                <a class="btn" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'inventory', 'edit_mode' => '1']), ENT_QUOTES) ?>"><?= $isEmployee ? 'Edit My Inventory' : 'Edit Inventory' ?></a>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($inventoryEditMode): ?>
            <div class="pill warn" style="display:block;padding:0.5rem 0.75rem;margin-bottom:0.75rem;">Editing allowed only for stock that has not been used/moved out/cut.</div>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
              <input type="hidden" name="action" value="save_inventory_edits" />
          <?php endif; ?>

          <div class="inventory-summary-filter" style="margin:0.75rem 0;">
            <label for="inventorySummarySearch">Search Summary</label>
            <input type="text" id="inventorySummarySearch" placeholder="Component or variant name" />
          </div>
          <table id="inventorySummaryTable"><thead><tr><th>Component</th><th>Type</th><th>Summary</th></tr></thead><tbody>
            <?php foreach ($inventoryComponents as $component): if (!is_array($component) || !empty($component['archived_flag'])) { continue; } $componentId=(string) ($component['id'] ?? ''); $isCuttable=!empty($component['is_cuttable']); $hasVariants=!empty($component['has_variants']); $componentEntry = documents_inventory_component_stock($inventoryStock, $componentId, ''); $lots = is_array($componentEntry['lots'] ?? null) ? $componentEntry['lots'] : []; usort($lots, static function ($a, $b): int { return strcmp((string) (($a['received_at'] ?? '')), (string) (($b['received_at'] ?? ''))); }); $totalFt = documents_inventory_compute_on_hand($inventoryStock, $componentId, '', true); $variantRows = (array) ($variantsByComponent[$componentId] ?? []); $variantTotalQty = documents_inventory_compute_on_hand($inventoryStock, $componentId, '', false); $variantCuttableStats = []; $variantCuttableTotalFt = 0.0; $variantCuttablePieces = 0; if ($isCuttable && $hasVariants) { foreach ($variantRows as $variantRow) { if (!is_array($variantRow) || !empty($variantRow['archived_flag'])) { continue; } $variantId=(string) ($variantRow['id'] ?? ''); if ($variantId === '') { continue; } $variantEntry = documents_inventory_component_stock($inventoryStock, $componentId, $variantId); $variantLots = is_array($variantEntry['lots'] ?? null) ? $variantEntry['lots'] : []; usort($variantLots, static function ($a, $b): int { return strcmp((string) (($a['received_at'] ?? '')), (string) (($b['received_at'] ?? ''))); }); $variantFt = documents_inventory_compute_on_hand($inventoryStock, $componentId, $variantId, true); $variantPieces = count($variantLots); $variantCuttableStats[] = ['variant' => $variantRow, 'variant_id' => $variantId, 'entry' => $variantEntry, 'lots' => $variantLots, 'ft' => $variantFt, 'pieces' => $variantPieces]; $variantCuttableTotalFt += $variantFt; $variantCuttablePieces += $variantPieces; } } $componentCuttableLots = ($isCuttable && $hasVariants) ? [] : $lots; $componentCuttablePieces = ($isCuttable && $hasVariants) ? $variantCuttablePieces : count($componentCuttableLots); $componentCuttableTotalFt = ($isCuttable && $hasVariants) ? $variantCuttableTotalFt : $totalFt; $summaryText = $isCuttable ? (documents_inventory_format_number($componentCuttableTotalFt, 4) . ' ft') : ($hasVariants ? (documents_inventory_format_number($variantTotalQty, 4) . ' qty total') : (documents_inventory_format_number(documents_inventory_compute_on_hand($inventoryStock, $componentId, '', false), 4) . ' ' . (string) ($component['default_unit'] ?? 'qty'))); $isActiveStock = false; if ($isCuttable) { $isActiveStock = $componentCuttableTotalFt > 0.00001 && $componentCuttablePieces > 0; } elseif ($hasVariants) { foreach ($variantRows as $variantRowForState) { if (!is_array($variantRowForState) || !empty($variantRowForState['archived_flag'])) { continue; } $variantIdForState=(string) ($variantRowForState['id'] ?? ''); if ($variantIdForState !== '' && abs((float) documents_inventory_compute_on_hand($inventoryStock, $componentId, $variantIdForState, false)) > 0.00001) { $isActiveStock = true; break; } } } else { $isActiveStock = abs((float) documents_inventory_compute_on_hand($inventoryStock, $componentId, '', false)) > 0.00001; } if (($inventoryStockView === 'active' && !$isActiveStock) || ($inventoryStockView === 'finished' && $isActiveStock)) { continue; } $searchHaystack = strtolower(trim((string) ($component['name'] ?? '') . ' ' . implode(' ', array_map(static function ($vr): string { return (string) ($vr['display_name'] ?? ''); }, $variantRows)))); $locationBreakdownText = array_map(static function (array $row) use ($component): string { return documents_inventory_resolve_location_name((string) ($row['location_id'] ?? '')) . ': ' . documents_inventory_format_number((float) ($row['qty'] ?? 0), 4) . ' ' . (string) ($component['default_unit'] ?? 'qty'); }, (array) ($componentEntry['location_breakdown'] ?? [])); $componentEditable = !isset($inventoryComponentBlocked[$componentId]); ?>
              <tr class="inventory-summary-group" data-inventory-group="1" data-search="<?= htmlspecialchars($searchHaystack, ENT_QUOTES) ?>"><td><strong><?= htmlspecialchars((string) ($component['name'] ?? ''), ENT_QUOTES) ?></strong></td><td><?php if ($isCuttable): ?><span class="pill">Cuttable (ft)</span><?php endif; ?><?php if ($hasVariants): ?><span class="pill">Variants</span><?php endif; ?><?php if (!$isCuttable && !$hasVariants): ?><span class="muted">Plain</span><?php endif; ?></td><td><?= htmlspecialchars($summaryText, ENT_QUOTES) ?><?php if (!$isCuttable && !$hasVariants): ?><br><span class="muted"><?= htmlspecialchars(implode(' | ', $locationBreakdownText !== [] ? $locationBreakdownText : ['Unassigned: 0']), ENT_QUOTES) ?></span><?php endif; ?><?php if ($inventoryEditMode && !$isCuttable && !$hasVariants && !$componentEditable): ?><br><span class="muted">Used stock cannot be edited.</span><?php endif; ?></td></tr>
              <?php if ($isCuttable): ?>
                <tr class="inventory-summary-detail" data-inventory-group="1" data-search="<?= htmlspecialchars($searchHaystack, ENT_QUOTES) ?>">
                  <td colspan="3">
                    <details open>
                      <summary><strong>Lots</strong> — Total <?= htmlspecialchars((string) $summaryText, ENT_QUOTES) ?>, Pieces <?= (int) $componentCuttablePieces ?></summary>
                      <?php if ($hasVariants && $variantCuttableStats !== []): ?>
                        <div style="margin:8px 0;">
                          <strong>Variants</strong>
                          <table>
                            <thead><tr><th>Variant</th><th>Remaining (ft)</th><th>Pieces</th><th>Lots</th></tr></thead>
                            <tbody>
                              <?php foreach ($variantCuttableStats as $variantCutRow): $v=(array) ($variantCutRow['variant'] ?? []); $variantLots=(array) ($variantCutRow['lots'] ?? []); ?>
                                <tr>
                                  <td><?= htmlspecialchars((string) ($v['display_name'] ?? ''), ENT_QUOTES) ?></td>
                                  <td><?= htmlspecialchars(documents_inventory_format_number((float) ($variantCutRow['ft'] ?? 0), 4), ENT_QUOTES) ?></td>
                                  <td><?= (int) ($variantCutRow['pieces'] ?? 0) ?></td>
                                  <td>
                                    <?php if ($variantLots === []): ?>
                                      <span class="muted">No lots</span>
                                    <?php else: ?>
                                      <details>
                                        <summary>View lots (<?= count($variantLots) ?>)</summary>
                                        <div style="max-height:220px;overflow:auto;">
                                          <table>
                                            <thead><tr><th>Lot</th><th>Rem (ft)</th><th>Orig (ft)</th><th>Location</th></tr></thead>
                                            <tbody>
                                              <?php foreach ($variantLots as $vLot): ?>
                                                <tr>
                                                  <td><?= htmlspecialchars((string) ($vLot['lot_id'] ?? ''), ENT_QUOTES) ?></td>
                                                  <td><?= htmlspecialchars(documents_inventory_format_number((float) ($vLot['remaining_length_ft'] ?? 0), 4), ENT_QUOTES) ?></td>
                                                  <td><?= htmlspecialchars(documents_inventory_format_number((float) ($vLot['original_length_ft'] ?? 0), 4), ENT_QUOTES) ?></td>
                                                  <td><?= htmlspecialchars(documents_inventory_resolve_location_name((string) ($vLot['location_id'] ?? '')), ENT_QUOTES) ?></td>
                                                </tr>
                                              <?php endforeach; ?>
                                            </tbody>
                                          </table>
                                        </div>
                                      </details>
                                    <?php endif; ?>
                                  </td>
                                </tr>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                      <?php endif; ?>
                      <?php if ($inventoryEditMode): ?>
                        <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;margin:8px 0;">
                          <strong>Bulk edit</strong>
                          <select name="bulk_lot_action">
                            <option value="">-- Action --</option>
                            <option value="change_location">Change location</option>
                            <?php if ($hasVariants): ?><option value="change_variant">Change variant</option><?php endif; ?>
                          </select>
                          <select name="bulk_lot_location_id"><option value="">Unassigned location</option><?php foreach ($activeInventoryLocations as $location): ?><option value="<?= htmlspecialchars((string) ($location['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($location['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select>
                          <?php if ($hasVariants): ?><select name="bulk_lot_variant_id"><option value="">-- Variant --</option><?php foreach ($variantRows as $variantRow): ?><option value="<?= htmlspecialchars((string) ($variantRow['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($variantRow['display_name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select><?php endif; ?>
                          <input type="text" name="bulk_lot_notes" placeholder="Optional notes" />
                        </div>
                      <?php endif; ?>
                      <table>
                        <thead><tr><th></th><th>Lot / Piece No</th><th>Remaining (ft)</th><th>Original (ft)</th><th>Location</th><th>Variant</th><th>Notes</th><th>Received</th></tr></thead>
                        <tbody>
                          <?php foreach ($componentCuttableLots as $ix => $lot):
                              $lotId=(string) ($lot['lot_id'] ?? '');
                              $lotEligibility = is_lot_editable((array) $lot, $inventoryUsageIndex);
                              $lotEditable = $lotId !== '' && !empty($lotEligibility['editable']);
                          ?>
                            <tr>
                              <td><?php if ($inventoryEditMode): ?><input type="checkbox" name="bulk_lot_ids[]" value="<?= htmlspecialchars($lotId, ENT_QUOTES) ?>" <?= $lotEditable ? '' : 'disabled' ?> /><?php endif; ?></td>
                              <td><?= htmlspecialchars((string) (($lot['lot_id'] ?? '') !== '' ? (string) ($lot['lot_id'] ?? '') : ('Piece #' . ($ix + 1))), ENT_QUOTES) ?><?php if ($inventoryEditMode && !$lotEditable): ?><br><span class="muted"><?= htmlspecialchars((string) ($lotEligibility['reason'] ?? 'Used stock cannot be edited.'), ENT_QUOTES) ?></span><?php endif; ?></td>
                              <td><?= htmlspecialchars((string) (float) ($lot['remaining_length_ft'] ?? 0), ENT_QUOTES) ?></td>
                              <td><?= htmlspecialchars((string) (float) ($lot['original_length_ft'] ?? 0), ENT_QUOTES) ?></td>
                              <td><?php if ($inventoryEditMode && $lotEditable): ?><input type="hidden" name="lot_edits[<?= htmlspecialchars($componentId . '|' . $lotId, ENT_QUOTES) ?>][component_id]" value="<?= htmlspecialchars($componentId, ENT_QUOTES) ?>" /><input type="hidden" name="lot_edits[<?= htmlspecialchars($componentId . '|' . $lotId, ENT_QUOTES) ?>][variant_id]" value="" /><input type="hidden" name="lot_edits[<?= htmlspecialchars($componentId . '|' . $lotId, ENT_QUOTES) ?>][lot_id]" value="<?= htmlspecialchars($lotId, ENT_QUOTES) ?>" /><select name="lot_edits[<?= htmlspecialchars($componentId . '|' . $lotId, ENT_QUOTES) ?>][location_id]"><option value="">Unassigned</option><?php foreach ($activeInventoryLocations as $location): ?><option value="<?= htmlspecialchars((string) ($location['id'] ?? ''), ENT_QUOTES) ?>" <?= ((string) ($lot['location_id'] ?? '') === (string) ($location['id'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($location['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select><?php else: ?><?= htmlspecialchars(documents_inventory_resolve_location_name((string) ($lot['location_id'] ?? '')), ENT_QUOTES) ?><?php endif; ?></td>
                              <td><?php if ($inventoryEditMode && $lotEditable && $hasVariants): ?><select name="lot_edits[<?= htmlspecialchars($componentId . '|' . $lotId, ENT_QUOTES) ?>][variant_id]"><option value="">Keep current</option><?php foreach ($variantRows as $variantRow): ?><option value="<?= htmlspecialchars((string) ($variantRow['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($variantRow['display_name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select><?php else: ?>-<?php endif; ?></td>
                              <td><?php if ($inventoryEditMode && $lotEditable): ?><input type="text" name="lot_edits[<?= htmlspecialchars($componentId . '|' . $lotId, ENT_QUOTES) ?>][notes]" value="<?= htmlspecialchars((string) ($lot['notes'] ?? ''), ENT_QUOTES) ?>" /><?php else: ?><?= htmlspecialchars((string) ($lot['notes'] ?? ''), ENT_QUOTES) ?><?php endif; ?></td>
                              <td><?= htmlspecialchars((string) ($lot['received_at'] ?? ''), ENT_QUOTES) ?></td>
                              <input type="hidden" name="lot_edits[<?= htmlspecialchars($componentId . '|' . $lotId, ENT_QUOTES) ?>][remaining_length_ft]" value="<?= htmlspecialchars((string) ((float) ($lot['remaining_length_ft'] ?? 0)), ENT_QUOTES) ?>" />
                              <input type="hidden" name="lot_edits[<?= htmlspecialchars($componentId . '|' . $lotId, ENT_QUOTES) ?>][original_length_ft]" value="<?= htmlspecialchars((string) ((float) ($lot['original_length_ft'] ?? 0)), ENT_QUOTES) ?>" />
                            </tr>
                          <?php endforeach; ?>
                          <?php if ($componentCuttableLots === []): ?><tr><td colspan="8" class="muted">No root lots available.</td></tr><?php endif; ?>
                        </tbody>
                      </table>
                    </details>
                  </td>
                </tr>
              <?php elseif ($hasVariants): ?>
                <tr class="inventory-summary-detail" data-inventory-group="1" data-search="<?= htmlspecialchars($searchHaystack, ENT_QUOTES) ?>"><td colspan="3"><details open><summary><strong>Variants</strong> — Total <?= htmlspecialchars(rtrim(rtrim((string) $variantTotalQty, '0'), '.'), ENT_QUOTES) ?> qty</summary><table><thead><tr><th>Variant Display Name</th><th>Brand</th><th>Wattage (Wp)</th><th>On-hand Qty</th><th>By Location</th></tr></thead><tbody><?php foreach ($variantRows as $variantRow): $variantId=(string) ($variantRow['id'] ?? ''); $variantEntry = documents_inventory_component_stock($inventoryStock, $componentId, $variantId); $variantLocationRows=(array) ($variantEntry['location_breakdown'] ?? []); $variantLocationText = array_map(static function (array $row): string { return documents_inventory_resolve_location_name((string) ($row['location_id'] ?? '')) . ': ' . rtrim(rtrim((string) ((float) ($row['qty'] ?? 0)), '0'), '.'); }, $variantLocationRows); $variantEditable = !isset($inventoryVariantBlocked[$variantId]); ?><tr><td><?= htmlspecialchars((string) ($variantRow['display_name'] ?? ''), ENT_QUOTES) ?><?php if ($inventoryEditMode && !$variantEditable): ?><br><span class="muted">Used stock cannot be edited.</span><?php endif; ?></td><td><?= htmlspecialchars((string) ($variantRow['brand'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ((float) ($variantRow['wattage_wp'] ?? 0)), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) documents_inventory_compute_on_hand($inventoryStock, $componentId, $variantId, false), ENT_QUOTES) ?></td><td><?php if ($inventoryEditMode && $variantEditable): ?><input type="hidden" name="variant_edits[<?= htmlspecialchars($variantId, ENT_QUOTES) ?>][component_id]" value="<?= htmlspecialchars($componentId, ENT_QUOTES) ?>" /><table><thead><tr><th>Location</th><th>Qty</th></tr></thead><tbody><?php $rowsForEdit = $variantLocationRows === [] ? [['location_id' => '', 'qty' => (float) ($variantEntry['on_hand_qty'] ?? 0)]] : $variantLocationRows; foreach ($rowsForEdit as $idx => $row): ?><tr><td><select name="variant_edits[<?= htmlspecialchars($variantId, ENT_QUOTES) ?>][rows][<?= (int) $idx ?>][location_id]"><option value="">Unassigned</option><?php foreach ($activeInventoryLocations as $location): ?><option value="<?= htmlspecialchars((string) ($location['id'] ?? ''), ENT_QUOTES) ?>" <?= ((string) ($row['location_id'] ?? '') === (string) ($location['id'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($location['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0" name="variant_edits[<?= htmlspecialchars($variantId, ENT_QUOTES) ?>][rows][<?= (int) $idx ?>][qty]" value="<?= htmlspecialchars((string) ((float) ($row['qty'] ?? 0)), ENT_QUOTES) ?>" /></td></tr><?php endforeach; ?></tbody></table><?php else: ?><?= htmlspecialchars(implode(' | ', $variantLocationText !== [] ? $variantLocationText : ['Unassigned: 0']), ENT_QUOTES) ?><?php endif; ?></td></tr><?php endforeach; ?><?php if ($variantRows === []): ?><tr><td colspan="5" class="muted">No active variants found.</td></tr><?php endif; ?></tbody></table></details></td></tr>
              <?php else: ?>
                <?php if ($inventoryEditMode && $componentEditable): ?>
                  <tr class="inventory-summary-detail" data-inventory-group="1" data-search="<?= htmlspecialchars($searchHaystack, ENT_QUOTES) ?>"><td colspan="3"><table><thead><tr><th>Location</th><th>Qty</th></tr></thead><tbody><?php $rowsForEdit = (array) ($componentEntry['location_breakdown'] ?? []); if ($rowsForEdit === []) { $rowsForEdit = [['location_id' => '', 'qty' => (float) ($componentEntry['on_hand_qty'] ?? 0)]]; } foreach ($rowsForEdit as $idx => $row): ?><tr><td><select name="component_edits[<?= htmlspecialchars($componentId, ENT_QUOTES) ?>][<?= (int) $idx ?>][location_id]"><option value="">Unassigned</option><?php foreach ($activeInventoryLocations as $location): ?><option value="<?= htmlspecialchars((string) ($location['id'] ?? ''), ENT_QUOTES) ?>" <?= ((string) ($row['location_id'] ?? '') === (string) ($location['id'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($location['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0" name="component_edits[<?= htmlspecialchars($componentId, ENT_QUOTES) ?>][<?= (int) $idx ?>][qty]" value="<?= htmlspecialchars((string) ((float) ($row['qty'] ?? 0)), ENT_QUOTES) ?>" /></td></tr><?php endforeach; ?></tbody></table></td></tr>
                <?php endif; ?>
              <?php endif; ?>
            <?php endforeach; ?>
          </tbody></table>

          <?php if ($inventoryEditMode): ?>
              <div style="margin-top:0.75rem;display:flex;gap:0.5rem;align-items:center;">
                <input type="text" name="edit_note" placeholder="Optional note" style="max-width:280px;" />
                <button class="btn" type="submit">Save Changes</button>
                <a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'inventory']), ENT_QUOTES) ?>">Cancel</a>
              </div>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <h3>Transactions</h3>
          <table><thead><tr><th>ID</th><th>Type</th><th>Component</th><th>Variant</th><th>Qty/FT</th><th>Ref</th><th>Verification</th><th>Audit</th><th>Edit</th></tr></thead><tbody>
            <?php foreach ($inventoryTransactions as $tx): $componentName = (string) (($componentMap[(string) ($tx['component_id'] ?? '')]['name'] ?? ($tx['component_id'] ?? ''))); $creator = is_array($tx['created_by'] ?? null) ? $tx['created_by'] : ['name' => (string) ($tx['created_by'] ?? ''), 'role' => '', 'id' => '']; $variantLabel = '-'; $txVariantId = (string) ($tx['variant_id'] ?? ''); if ($txVariantId !== '') { $variantLabel = (string) (($variantMap[$txVariantId]['display_name'] ?? '') ?: ($tx['variant_name_snapshot'] ?? '(Unknown variant)')); } ?>
              <tr><td><?= htmlspecialchars((string) ($tx['id'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($tx['type'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars($componentName, ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) $variantLabel, ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) (($tx['qty'] ?? 0) > 0 ? ($tx['qty'] . ' ' . ($tx['unit'] ?? 'qty')) : (($tx['length_ft'] ?? 0) . ' ft')), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) (($tx['ref_type'] ?? 'manual') . ':' . ($tx['ref_id'] ?? '')), ENT_QUOTES) ?></td><td><?php if (documents_inventory_is_employee_actor($creator)): $txVerification = array_merge(documents_inventory_verification_defaults(), (array) ($inventoryVerificationByTxnId[(string) ($tx['id'] ?? '')] ?? ['status' => 'not_verified'])); $verificationStatus = (string) ($txVerification['status'] ?? 'not_verified'); ?><span class="pill <?= $verificationStatus === 'verified' ? '' : 'secondary' ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $verificationStatus)), ENT_QUOTES) ?></span><?php if ($isAdmin): ?><br><a class="muted" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'inventory_verification', 'transaction_id' => (string) ($tx['id'] ?? '')]), ENT_QUOTES) ?>">View</a><?php endif; ?><?php else: ?><span class="muted">—</span><?php endif; ?></td><td><?= htmlspecialchars((string) (($creator['name'] ?? '') . ' [' . ($creator['role'] ?? '') . '] @ ' . ($tx['created_at'] ?? '')), ENT_QUOTES) ?><?php if (!empty($tx['updated_at'])): ?><br><span class="muted">Updated: <?= htmlspecialchars((string) ($tx['updated_at'] ?? ''), ENT_QUOTES) ?></span><?php endif; ?></td><td><?php $canEdit = $isAdmin || (((string) (($creator['id'] ?? '')) === (string) ($user['id'] ?? '')) && ((string) ($tx['ref_type'] ?? 'manual') === 'manual') && ((string) ($tx['ref_id'] ?? '') === '') && ((time() - (strtotime((string) ($tx['created_at'] ?? '')) ?: 0)) <= 600)); ?><?php if ($canEdit): ?><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="action" value="edit_inventory_tx" /><input type="hidden" name="transaction_id" value="<?= htmlspecialchars((string) ($tx['id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="tx_type" value="<?= htmlspecialchars((string) ($tx['type'] ?? 'IN'), ENT_QUOTES) ?>" /><input type="hidden" name="component_id" value="<?= htmlspecialchars((string) ($tx['component_id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="variant_id" value="<?= htmlspecialchars((string) ($tx['variant_id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="qty" value="<?= htmlspecialchars((string) ($tx['qty'] ?? 0), ENT_QUOTES) ?>" /><input type="hidden" name="length_ft" value="<?= htmlspecialchars((string) ($tx['length_ft'] ?? 0), ENT_QUOTES) ?>" /><input type="hidden" name="ref_type" value="<?= htmlspecialchars((string) ($tx['ref_type'] ?? 'manual'), ENT_QUOTES) ?>" /><input type="hidden" name="ref_id" value="<?= htmlspecialchars((string) ($tx['ref_id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="location_id" value="<?= htmlspecialchars((string) ($tx['location_id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="consume_location_id" value="<?= htmlspecialchars((string) ($tx['consume_location_id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="notes" value="<?= htmlspecialchars((string) ($tx['notes'] ?? ''), ENT_QUOTES) ?>" /><button class="btn secondary" type="submit">Reapply Edit</button></form><?php else: ?><span class="muted">Locked</span><?php endif; ?></td></tr>
            <?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
      </section>
    <?php endif; ?>


    <?php if ($activeTab === 'items' && $itemsSubtab === 'inventory_verification'): ?>
      <section class="panel">
        <h3>Inventory Verification Queue</h3>
        <?php if (!$isAdmin): ?>
          <p class="muted">Access denied.</p>
        <?php else: ?>
          <div class="grid grid-3" style="margin-bottom:1rem;">
            <div class="kpi-card"><h4>Not Verified</h4><p><?= (int) ($inventoryVerificationCounts['not_verified'] ?? 0) ?></p></div>
            <div class="kpi-card"><h4>Needs Clarification</h4><p><?= (int) ($inventoryVerificationCounts['needs_clarification'] ?? 0) ?></p></div>
            <div class="kpi-card"><h4>Verified</h4><p><?= (int) ($inventoryVerificationCounts['verified'] ?? 0) ?></p></div>
          </div>
          <?php $selectedVerificationTxnId = safe_text((string) ($_GET['transaction_id'] ?? '')); ?>
          <form method="post" class="panel" style="margin-bottom:1rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
            <input type="hidden" name="action" value="inventory_verification_bulk_update" />
            <div style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap;">
              <div><label>Bulk Action</label><select name="verification_status" required><option value="verified">Mark Verified</option><option value="needs_clarification">Mark Needs Clarification</option><option value="not_verified">Reset to Not Verified</option></select></div>
              <div style="min-width:260px;"><label>Admin note (required for Needs Clarification)</label><input type="text" name="admin_note" /></div>
              <div><button class="btn" type="submit">Apply to selected</button></div>
            </div>
            <table style="margin-top:1rem;"><thead><tr><th><input type="checkbox" data-check-all="verification" /></th><th>Date/Time</th><th>Employee</th><th>Type</th><th>Component</th><th>Variant</th><th>Qty / Feet</th><th>From → To</th><th>Reference</th><th>Status</th><th>View</th></tr></thead><tbody>
              <?php foreach ($inventoryVerificationPendingRows as $queueRow): $tx = (array) ($queueRow['tx'] ?? []); $verification = array_merge(documents_inventory_verification_defaults(), (array) ($queueRow['verification'] ?? [])); $componentName = (string) (($componentMap[(string) ($tx['component_id'] ?? '')]['name'] ?? ($tx['component_id'] ?? ''))); $txVariantId = (string) ($tx['variant_id'] ?? ''); $variantLabel = $txVariantId !== '' ? (string) (($variantMap[$txVariantId]['display_name'] ?? '') ?: ($tx['variant_name_snapshot'] ?? '(Unknown variant)')) : '-'; $qtyText = (string) (($tx['qty'] ?? 0) > 0 ? ($tx['qty'] . ' ' . ($tx['unit'] ?? 'qty')) : (($tx['length_ft'] ?? 0) . ' ft')); $fromTo = trim((string) documents_inventory_resolve_location_name((string) ($tx['from_location_id'] ?? '')) . ' → ' . (string) documents_inventory_resolve_location_name((string) ($tx['to_location_id'] ?? ''))); ?>
                <tr>
                  <td><input type="checkbox" name="txn_ids[]" value="<?= htmlspecialchars((string) ($tx['id'] ?? ''), ENT_QUOTES) ?>" /></td>
                  <td><?= htmlspecialchars((string) ($tx['created_at'] ?? ''), ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars((string) (($verification['created_by']['name'] ?? '') ?: ($tx['created_by']['name'] ?? '')), ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars((string) ($tx['type'] ?? ''), ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($componentName, ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars((string) $variantLabel, ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($qtyText, ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($fromTo === '→' ? '-' : $fromTo, ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars((string) (($tx['ref_type'] ?? 'manual') . ':' . ($tx['ref_id'] ?? '')), ENT_QUOTES) ?></td>
                  <td><span class="pill secondary"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($verification['status'] ?? 'not_verified'))), ENT_QUOTES) ?></span></td>
                  <td><a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'items', 'items_subtab' => 'inventory_verification', 'transaction_id' => (string) ($tx['id'] ?? '')]), ENT_QUOTES) ?>">View</a></td>
                </tr>
              <?php endforeach; ?>
              <?php if ($inventoryVerificationPendingRows === []): ?><tr><td colspan="11" class="muted">No pending employee transactions.</td></tr><?php endif; ?>
            </tbody></table>
          </form>

          <?php if ($selectedVerificationTxnId !== '' && isset($inventoryVerificationByTxnId[$selectedVerificationTxnId])): $selectedTx = null; foreach ($inventoryTransactions as $txRow) { if ((string) ($txRow['id'] ?? '') === $selectedVerificationTxnId) { $selectedTx = $txRow; break; } } $selectedVerification = array_merge(documents_inventory_verification_defaults(), (array) $inventoryVerificationByTxnId[$selectedVerificationTxnId]); ?>
            <?php if (is_array($selectedTx)): $selectedComponentName = (string) (($componentMap[(string) ($selectedTx['component_id'] ?? '')]['name'] ?? ($selectedTx['component_id'] ?? ''))); ?>
              <div class="panel">
                <h4>Transaction Detail: <?= htmlspecialchars((string) ($selectedTx['id'] ?? ''), ENT_QUOTES) ?></h4>
                <p><strong>Employee:</strong> <?= htmlspecialchars((string) ($selectedVerification['created_by']['name'] ?? ''), ENT_QUOTES) ?> | <strong>Type:</strong> <?= htmlspecialchars((string) ($selectedTx['type'] ?? ''), ENT_QUOTES) ?> | <strong>When:</strong> <?= htmlspecialchars((string) ($selectedTx['created_at'] ?? ''), ENT_QUOTES) ?></p>
                <p><strong>Component:</strong> <?= htmlspecialchars($selectedComponentName, ENT_QUOTES) ?> | <strong>Variant:</strong> <?= htmlspecialchars((string) (($variantMap[(string) ($selectedTx['variant_id'] ?? '')]['display_name'] ?? '') ?: ($selectedTx['variant_name_snapshot'] ?? '-')), ENT_QUOTES) ?></p>
                <p><strong>Qty/FT:</strong> <?= htmlspecialchars((string) (($selectedTx['qty'] ?? 0) > 0 ? ($selectedTx['qty'] . ' ' . ($selectedTx['unit'] ?? 'qty')) : (($selectedTx['length_ft'] ?? 0) . ' ft')), ENT_QUOTES) ?></p>
                <p><strong>Notes:</strong> <?= htmlspecialchars((string) ($selectedTx['notes'] ?? ''), ENT_QUOTES) ?></p>
                <p><strong>Lot consumption:</strong> <?= htmlspecialchars(json_encode((array) ($selectedTx['lot_consumption'] ?? []), JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?></p>
                <p><strong>Batch consumption:</strong> <?= htmlspecialchars(json_encode((array) ($selectedTx['batch_consumption'] ?? []), JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?></p>
                <form method="post" style="display:grid;gap:0.75rem;max-width:760px;">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                  <input type="hidden" name="action" value="inventory_verification_update" />
                  <input type="hidden" name="txn_id" value="<?= htmlspecialchars((string) ($selectedTx['id'] ?? ''), ENT_QUOTES) ?>" />
                  <div><label>Status</label><select name="verification_status" required><option value="verified" <?= (string) ($selectedVerification['status'] ?? '') === 'verified' ? 'selected' : '' ?>>Verified</option><option value="needs_clarification" <?= (string) ($selectedVerification['status'] ?? '') === 'needs_clarification' ? 'selected' : '' ?>>Needs Clarification</option><option value="not_verified" <?= (string) ($selectedVerification['status'] ?? '') === 'not_verified' ? 'selected' : '' ?>>Not Verified</option></select></div>
                  <div><label>Admin note</label><textarea name="admin_note"><?= htmlspecialchars((string) ($selectedVerification['admin_note'] ?? ''), ENT_QUOTES) ?></textarea></div>
                  <div><button class="btn" type="submit">Save Verification</button></div>
                </form>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if ($activeTab === 'archived'): ?>
      <section class="panel">
        <h2 style="margin-top:0;">Archived Documents</h2>
        <form method="get" class="grid" style="margin-bottom:1rem;">
          <input type="hidden" name="tab" value="archived" />
          <div>
            <label>Type</label>
            <select name="archive_type">
              <option value="all" <?= $archiveTypeFilter === 'all' ? 'selected' : '' ?>>All</option>
              <option value="quotation" <?= $archiveTypeFilter === 'quotation' ? 'selected' : '' ?>>Quotations</option>
              <option value="agreement" <?= $archiveTypeFilter === 'agreement' ? 'selected' : '' ?>>Agreements</option>
              <option value="receipt" <?= $archiveTypeFilter === 'receipt' ? 'selected' : '' ?>>Receipts</option>
              <option value="delivery_challan" <?= $archiveTypeFilter === 'delivery_challan' ? 'selected' : '' ?>>DC</option>
              <option value="proforma" <?= $archiveTypeFilter === 'proforma' ? 'selected' : '' ?>>PI</option>
              <option value="invoice" <?= $archiveTypeFilter === 'invoice' ? 'selected' : '' ?>>Invoice</option>
            </select>
          </div>
          <div><label>Search</label><input type="text" name="archive_q" value="<?= htmlspecialchars((string) ($_GET['archive_q'] ?? ''), ENT_QUOTES) ?>" placeholder="Customer / mobile / doc id / quote id" /></div>
          <div><label>&nbsp;</label><button class="btn" type="submit">Apply</button></div>
        </form>
        <table>
          <thead><tr><th>Type</th><th>Doc ID</th><th>Customer</th><th>Linked quotation id</th><th>Amount</th><th>Archived at</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($archivedRows as $row): ?>
              <tr>
                <td>
                  <?php if ((string) ($row['type'] ?? '') === 'quotation' && (string) ($row['quotation_status'] ?? '') === 'accepted'): ?>
                    Accepted Customer (Quotation) <span class="pill archived">Accepted</span>
                  <?php else: ?>
                    <?= htmlspecialchars($documentTypeLabel[(string) ($row['type'] ?? '')] ?? (string) ($row['type'] ?? ''), ENT_QUOTES) ?>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars((string) ($row['doc_id'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($row['customer'] ?? ''), ENT_QUOTES) ?><br><span class="muted"><?= htmlspecialchars((string) ($row['mobile'] ?? ''), ENT_QUOTES) ?></span></td>
                <td><?= htmlspecialchars((string) ($row['quotation_id'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($inr((float) ($row['amount'] ?? 0)), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($row['archived_at'] ?? ''), ENT_QUOTES) ?></td>
                <td class="row-actions">
                  <?php if ((string) ($row['type'] ?? '') === 'agreement'): ?><a class="btn secondary" href="agreement-view.php?id=<?= urlencode((string) ($row['doc_id'] ?? '')) ?>" target="_blank" rel="noopener">View as HTML</a><a class="btn secondary" href="agreement-view.php?id=<?= urlencode((string) ($row['doc_id'] ?? '')) ?>&mode=edit" target="_blank" rel="noopener">View / Edit</a><?php elseif ((string) ($row['type'] ?? '') === 'receipt'): ?><a class="btn secondary" href="receipt-view.php?rid=<?= urlencode((string) ($row['doc_id'] ?? '')) ?>" target="_blank" rel="noopener">View as HTML</a><?php elseif ((string) ($row['type'] ?? '') === 'quotation' && (string) ($row['quotation_status'] ?? '') === 'accepted'): ?><a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'accepted_customers', 'view' => (string) ($row['quotation_id'] ?? ''), 'include_archived_pack' => '1']), ENT_QUOTES) ?>">View pack</a><?php elseif ((string) ($row['quotation_id'] ?? '') !== ''): ?><a class="btn secondary" href="quotation-view.php?id=<?= urlencode((string) ($row['quotation_id'] ?? '')) ?>" target="_blank" rel="noopener">View</a><?php endif; ?>
                  <?php if ($isAdmin): ?>
                    <?php if ((string) ($row['type'] ?? '') === 'quotation' && (string) ($row['quotation_status'] ?? '') === 'accepted'): ?>
                      <form class="inline-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                        <input type="hidden" name="action" value="unarchive_accepted_customer" />
                        <input type="hidden" name="quotation_id" value="<?= htmlspecialchars((string) ($row['doc_id'] ?? ''), ENT_QUOTES) ?>" />
                        <input type="hidden" name="return_tab" value="archived" />
                        <button class="btn secondary" type="submit">Unarchive</button>
                      </form>
                    <?php else: ?>
                      <form class="inline-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                        <input type="hidden" name="action" value="set_archive_state" />
                        <input type="hidden" name="doc_type" value="<?= htmlspecialchars((string) ($row['type'] ?? ''), ENT_QUOTES) ?>" />
                        <input type="hidden" name="doc_id" value="<?= htmlspecialchars((string) ($row['doc_id'] ?? ''), ENT_QUOTES) ?>" />
                        <input type="hidden" name="archive_state" value="unarchive" />
                        <input type="hidden" name="return_tab" value="archived" />
                        <button class="btn secondary" type="submit">Unarchive</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($archivedRows === []): ?><tr><td colspan="7" class="muted">No archived documents found.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </section>
    <?php endif; ?>

    <?php if ($activeTab === 'company'): ?>
      <section class="panel">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
          <input type="hidden" name="action" value="save_company_profile" />
          <div class="grid">
            <?php foreach ($company as $key => $value): ?>
              <?php if ($key === 'logo_path' || $key === 'updated_at') { continue; } ?>
              <div>
                <label for="<?= htmlspecialchars($key, ENT_QUOTES) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $key)), ENT_QUOTES) ?></label>
                <input id="<?= htmlspecialchars($key, ENT_QUOTES) ?>" type="text" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>" value="<?= htmlspecialchars((string) $value, ENT_QUOTES) ?>" />
              </div>
            <?php endforeach; ?>
            <div>
              <label for="company_logo_upload">Company Logo Upload</label>
              <input id="company_logo_upload" type="file" name="company_logo_upload" accept="image/*" />
              <?php if ((string) $company['logo_path'] !== ''): ?>
                <img class="logo-preview" src="<?= htmlspecialchars((string) $company['logo_path'], ENT_QUOTES) ?>" alt="Current logo" />
              <?php endif; ?>
            </div>
          </div>
          <p class="muted">WhatsApp accepts 10-12 digits. PAN is optional but should look like ABCDE1234F.</p>
          <p class="muted">Last updated: <?= htmlspecialchars((string) ($company['updated_at'] ?: 'Never'), ENT_QUOTES) ?></p>
          <button class="btn" type="submit">Save Company Profile</button>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($activeTab === 'numbering'): ?>
      <section class="panel">
        <p class="muted">Financial year mode: <?= htmlspecialchars((string) $numbering['financial_year_mode'], ENT_QUOTES) ?> · FY Start Month: <?= (int) $numbering['fy_start_month'] ?> · Current FY: <?= htmlspecialchars(current_fy_string((int) $numbering['fy_start_month']), ENT_QUOTES) ?></p>
        <table>
          <thead>
            <tr><th>Type</th><th>Segment</th><th>Prefix</th><th>Format</th><th>Digits</th><th>Start</th><th>Current</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php if ($numbering['rules'] === []): ?>
              <tr><td colspan="9">No numbering rules added yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($numbering['rules'] as $rule): ?>
              <?php if (!is_array($rule) || !empty($rule['archived_flag'])) { continue; } ?>
              <tr>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                  <input type="hidden" name="rule_id" value="<?= htmlspecialchars((string) ($rule['id'] ?? ''), ENT_QUOTES) ?>" />
                  <td><select name="doc_type"><?php foreach ($docTypes as $docType): ?><option value="<?= htmlspecialchars($docType, ENT_QUOTES) ?>" <?= ($docType === (string) ($rule['doc_type'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars($docType, ENT_QUOTES) ?></option><?php endforeach; ?></select></td>
                  <td><select name="segment"><?php foreach ($segments as $segment): ?><option value="<?= htmlspecialchars($segment, ENT_QUOTES) ?>" <?= ($segment === (string) ($rule['segment'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars($segment, ENT_QUOTES) ?></option><?php endforeach; ?></select></td>
                  <td><input type="text" name="prefix" value="<?= htmlspecialchars((string) ($rule['prefix'] ?? ''), ENT_QUOTES) ?>" /></td>
                  <td><input type="text" name="format" value="<?= htmlspecialchars((string) ($rule['format'] ?? ''), ENT_QUOTES) ?>" /></td>
                  <td><input type="number" name="seq_digits" min="2" max="6" value="<?= (int) ($rule['seq_digits'] ?? 4) ?>" /></td>
                  <td><input type="number" name="seq_start" min="1" value="<?= (int) ($rule['seq_start'] ?? 1) ?>" /></td>
                  <td><input type="number" name="seq_current" min="1" value="<?= (int) ($rule['seq_current'] ?? 1) ?>" /></td>
                  <td><label><input type="checkbox" name="active" <?= !empty($rule['active']) ? 'checked' : '' ?> /> Active</label></td>
                  <td>
                    <button class="btn" type="submit" name="action" value="update_numbering_rule">Save</button>
                    <button class="btn secondary" type="submit" name="action" value="reset_counter">Reset Counter</button>
                    <button class="btn warn" type="submit" name="action" value="archive_numbering_rule">Archive</button>
                  </td>
                </form>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <h3>Add new rule</h3>
        <form method="post" class="grid">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
          <input type="hidden" name="action" value="save_numbering_rule" />
          <div><label>Document Type</label><select name="doc_type"><?php foreach ($docTypes as $docType): ?><option value="<?= htmlspecialchars($docType, ENT_QUOTES) ?>"><?= htmlspecialchars($docType, ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
          <div><label>Segment</label><select name="segment"><?php foreach ($segments as $segment): ?><option value="<?= htmlspecialchars($segment, ENT_QUOTES) ?>"><?= htmlspecialchars($segment, ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
          <div><label>Prefix</label><input type="text" name="prefix" value="DE/QTN" /></div>
          <div><label>Format</label><input type="text" name="format" value="{{prefix}}/{{segment}}/{{fy}}/{{seq}}" /></div>
          <div><label>Sequence Digits</label><input type="number" name="seq_digits" min="2" max="6" value="4" /></div>
          <div><label>Starting Number</label><input type="number" name="seq_start" min="1" value="1" /></div>
          <div><label>Current Number</label><input type="number" name="seq_current" min="1" value="1" /></div>
          <div><label>Status</label><label><input type="checkbox" name="active" checked /> Active</label></div>
          <div><button class="btn" type="submit">Save Numbering Rule</button></div>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($activeTab === 'templates'): ?>
      <section class="panel">
        <table>
          <thead><tr><th>Name</th><th>Segment</th><th>Archived</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($templates as $template): ?>
              <?php if (!is_array($template)) { continue; } ?>
              <?php $theme = is_array($template['default_doc_theme'] ?? null) ? $template['default_doc_theme'] : ['page_background_image' => '', 'page_background_opacity' => 1]; ?>
              <tr>
                <td><?= htmlspecialchars((string) ($template['name'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($template['segment'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= !empty($template['archived_flag']) ? 'Yes' : 'No' ?></td>
                <td>
                  <details>
                    <summary>Edit</summary>
                    <form method="post" enctype="multipart/form-data" class="grid">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                      <input type="hidden" name="action" value="save_template_set" />
                      <input type="hidden" name="template_id" value="<?= htmlspecialchars((string) ($template['id'] ?? ''), ENT_QUOTES) ?>" />
                      <div><label>Name</label><input type="text" name="name" value="<?= htmlspecialchars((string) ($template['name'] ?? ''), ENT_QUOTES) ?>" /></div>
                      <div><label>Segment</label><select name="segment"><?php foreach ($segments as $segment): ?><option value="<?= htmlspecialchars($segment, ENT_QUOTES) ?>" <?= ((string) ($template['segment'] ?? '') === $segment) ? 'selected' : '' ?>><?= htmlspecialchars($segment, ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
                      <div><label>Notes</label><textarea name="notes"><?= htmlspecialchars((string) ($template['notes'] ?? ''), ENT_QUOTES) ?></textarea></div>
                      <div><button class="btn" type="submit">Save</button></div>
                    </form>
                    <form method="post" style="margin-top:0.5rem;">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                      <input type="hidden" name="template_id" value="<?= htmlspecialchars((string) ($template['id'] ?? ''), ENT_QUOTES) ?>" />
                      <button class="btn <?= !empty($template['archived_flag']) ? 'secondary' : 'warn' ?>" type="submit" name="action" value="<?= !empty($template['archived_flag']) ? 'unarchive_template_set' : 'archive_template_set' ?>"><?= !empty($template['archived_flag']) ? 'Unarchive' : 'Archive' ?></button>
                    </form>
                  </details>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <h3>Add new template set</h3>
        <form method="post" enctype="multipart/form-data" class="grid">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
          <input type="hidden" name="action" value="save_template_set" />
          <div><label>Name</label><input type="text" name="name" required /></div>
          <div><label>Segment</label><select name="segment"><?php foreach ($segments as $segment): ?><option value="<?= htmlspecialchars($segment, ENT_QUOTES) ?>"><?= htmlspecialchars($segment, ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
          <div><label>Notes</label><textarea name="notes"></textarea></div>
          <div><button class="btn" type="submit">Save Template Set</button></div>
        </form>
      </section>
    <?php endif; ?>
  </main>
<script>
document.addEventListener('click', function (e) {
  if (e.target && e.target.id === 'addTaxSlabBtn') {
    const body = document.querySelector('#taxSlabsTable tbody');
    if (!body) return;
    const tr = document.createElement('tr');
    tr.innerHTML = '<td><input type="number" step="0.01" min="0" max="100" name="slab_share_pct[]" /></td><td><input type="number" step="0.01" min="0" name="slab_rate_pct[]" /></td>';
    body.appendChild(tr);
  }

  if (e.target && e.target.classList && e.target.classList.contains('kit-remove-component')) {
    const componentId = e.target.getAttribute('data-component-id') || '';
    if (componentId === '') return;
    const checkbox = document.querySelector('.kit-component-checkbox[data-component-id="' + componentId.replace(/"/g, '\"') + '"]');
    if (checkbox) {
      checkbox.checked = false;
      checkbox.dispatchEvent(new Event('change'));
    }
  }
});

function applyKitModePanels(row) {
  if (!row) return;
  const modeSel = row.querySelector('.kit-bom-mode');
  const mode = modeSel ? (modeSel.value || 'fixed_qty') : 'fixed_qty';
  row.querySelectorAll('.bom-mode-panel').forEach(function (panel) {
    panel.style.display = panel.getAttribute('data-mode') === mode ? '' : 'none';
  });
}

function applyCapacityTypePanels(row) {
  if (!row) return;
  const typeSel = row.querySelector('.kit-capacity-type');
  if (!typeSel) return;
  const wrap = typeSel.closest('.bom-mode-panel');
  if (!wrap) return;
  const formula = wrap.querySelector('.kit-capacity-formula');
  const slabs = wrap.querySelector('.kit-capacity-slabs');
  if (formula) formula.style.display = typeSel.value === 'formula' ? '' : 'none';
  if (slabs) slabs.style.display = typeSel.value === 'slab' ? '' : 'none';
}

document.addEventListener('change', function (e) {
  if (e.target && e.target.classList && e.target.classList.contains('kit-component-checkbox')) {
    const componentId = e.target.getAttribute('data-component-id') || '';
    if (componentId === '') return;
    const row = document.querySelector('.kit-bom-row[data-component-id="' + componentId.replace(/"/g, '\"') + '"]');
    if (!row) return;
    const hidden = row.querySelector('.kit-selected-component-id');
    const qtyInput = row.querySelector('input[name="bom_qty[' + componentId.replace(/"/g, '\"') + ']"]');
    if (e.target.checked) {
      row.style.display = '';
      if (hidden) hidden.value = componentId;
      if (qtyInput && qtyInput.value === '0') qtyInput.value = '';
      applyKitModePanels(row);
      applyCapacityTypePanels(row);
    } else {
      row.style.display = 'none';
      if (hidden) hidden.value = '';
      if (qtyInput) qtyInput.value = '';
    }
    return;
  }

  if (e.target && e.target.classList && e.target.classList.contains('kit-bom-mode')) {
    applyKitModePanels(e.target.closest('.kit-bom-row'));
    return;
  }

  if (e.target && e.target.classList && e.target.classList.contains('kit-capacity-type')) {
    applyCapacityTypePanels(e.target.closest('.kit-bom-row'));
  }
});

document.querySelectorAll('.kit-bom-row').forEach(function (row) { applyKitModePanels(row); applyCapacityTypePanels(row); });

document.addEventListener('input', function (e) {
  if (!(e.target && e.target.id === 'kitComponentSearch')) {
    return;
  }
  const q = (e.target.value || '').toLowerCase().trim();
  document.querySelectorAll('#kitComponentSelector tbody tr[data-kit-component-row="1"]').forEach(function (row) {
    const hay = (row.textContent || '').toLowerCase();
    row.style.display = q === '' || hay.indexOf(q) !== -1 ? '' : 'none';
  });
});

document.addEventListener('input', function (e) {
  if (!(e.target && e.target.id === 'inventorySummarySearch')) {
    return;
  }
  const q = (e.target.value || '').toLowerCase().trim();
  document.querySelectorAll('#inventorySummaryTable tbody tr[data-inventory-group="1"]').forEach(function (row) {
    const hay = (row.getAttribute('data-search') || '').toLowerCase();
    row.style.display = q === '' || hay.indexOf(q) !== -1 ? '' : 'none';
  });
});

const INVENTORY_COMPONENTS = <?= json_encode($inventoryComponentJsMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const verificationCheckAll = document.querySelector('[data-check-all="verification"]');
if (verificationCheckAll) { verificationCheckAll.addEventListener('change', function () { document.querySelectorAll('input[name="txn_ids[]"]').forEach(function (box) { box.checked = verificationCheckAll.checked; }); }); }
window.VARIANTS_BY_COMPONENT = <?= json_encode($variantsByComponent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function formatVariantOptionLabel(variant) {
  if (!variant || typeof variant !== 'object') return '';
  const name = (variant.display_name || '').trim();
  const watt = Number(variant.wattage_wp || 0);
  if (watt > 0) {
    return name + ' (' + String(watt).replace(/\.0+$/, '') + 'Wp)';
  }
  return name;
}

function syncInventoryVariantField(form) {
  if (!form) return;
  const componentSelect = form.querySelector('select[name="component_id"]');
  const variantWrap = form.querySelector('[data-variant-wrap="1"]');
  const variantSelect = form.querySelector('select[name="variant_id"]');
  const emptyMsg = form.querySelector('[data-variant-empty="1"]');
  const submitBtn = form.querySelector('button[type="submit"]');
  if (!componentSelect || !variantWrap || !variantSelect) return;

  const componentId = componentSelect.value || '';
  const component = INVENTORY_COMPONENTS[componentId] || null;
  const needsVariants = !!(component && component.has_variants);

  if (!needsVariants) {
    variantWrap.style.display = 'none';
    variantSelect.required = false;
    variantSelect.value = '';
    variantSelect.innerHTML = '<option value="">-- select variant --</option>';
    if (emptyMsg) emptyMsg.style.display = 'none';
    if (submitBtn) submitBtn.disabled = false;
    return;
  }

  variantWrap.style.display = '';
  const variants = window.VARIANTS_BY_COMPONENT[componentId] || [];
  const selectedBefore = variantSelect.value || '';
  variantSelect.innerHTML = '<option value="">-- select variant --</option>';
  variants.forEach(function (variant) {
    const option = document.createElement('option');
    option.value = variant.id || '';
    option.textContent = formatVariantOptionLabel(variant);
    variantSelect.appendChild(option);
  });

  if (selectedBefore !== '' && variants.some(function (variant) { return (variant.id || '') === selectedBefore; })) {
    variantSelect.value = selectedBefore;
  } else {
    variantSelect.value = '';
  }

  const hasVariants = variants.length > 0;
  variantSelect.required = true;
  if (emptyMsg) emptyMsg.style.display = hasVariants ? 'none' : '';
  if (submitBtn) submitBtn.disabled = !hasVariants;
}

function syncInventoryCuttableField(form) {
  if (!form) return;
  const componentSelect = form.querySelector('select[name="component_id"]');
  if (!componentSelect) return;
  const component = INVENTORY_COMPONENTS[componentSelect.value || ''] || null;
  const isCuttable = !!(component && component.is_cuttable);

  form.querySelectorAll('[data-cuttable-wrap="1"]').forEach(function (el) {
    el.style.display = isCuttable ? '' : 'none';
    if (!isCuttable) {
      el.querySelectorAll('input').forEach(function (input) { input.value = ''; });
    }
  });
  form.querySelectorAll('[data-non-cuttable-wrap="1"]').forEach(function (el) {
    el.style.display = isCuttable ? 'none' : '';
    if (isCuttable) {
      el.querySelectorAll('input').forEach(function (input) { input.value = ''; });
    }
  });
}

document.querySelectorAll('form[data-inventory-form="1"]').forEach(function (form) {
  syncInventoryVariantField(form);
  syncInventoryCuttableField(form);
  const componentSelect = form.querySelector('select[name="component_id"]');
  if (componentSelect) {
    componentSelect.addEventListener('change', function () {
      syncInventoryVariantField(form);
      syncInventoryCuttableField(form);
    });
  }
});

</script>
</body>
</html>
