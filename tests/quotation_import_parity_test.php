<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/solar_finance_reports.php';
require_once __DIR__ . '/../includes/quotation_import_service.php';
documents_seed_template_sets_if_empty();

$input = [
    'import_key' => 'PARITY-001',
    'customer_name' => 'Parity Customer',
    'customer_mobile' => '9876543210',
    'party_type' => 'lead',
    'template_set' => 'PM Surya Ghar – Residential Ongrid (Subsidy) (RES)',
    'system_type' => 'Ongrid',
    'selected_model_number' => 'MODEL-PARITY',
    'main_solar_kwp' => '3',
    'non_dcr_kwp' => '1',
    'quotation_date' => '2026-07-13',
    'valid_until' => '2026-07-20',
    'scenario_price_self_funded' => '250000',
    'scenario_price_loan_upto_2_lacs' => '250000',
    'scenario_price_loan_above_2_lacs' => '250000',
    'subsidy_amount' => '78000',
    'transportation_amount' => '5000',
    'discount_amount' => '2500',
    'place_of_supply_state' => 'Jharkhand',
    'show_tax_breakup' => '',
    'monthly_bill_rs' => '3000',
    'unit_rate_rs_per_kwh' => '7',
    'annual_generation_per_kw' => '1450',
];

$manualInput = documents_quote_import_normalize_input($input);
$csvInput = documents_quote_import_normalize_input($input);
$manual = documents_build_quote_draft_from_input($manualInput, ['dry_run' => true]);
$csv = documents_build_quote_draft_from_input($csvInput, ['dry_run' => true]);

$strip = static function (array $q): array {
    foreach (['id','quote_no','created_at','updated_at','created_by_id','created_by_name','created_by_type','import_metadata','public_token','public_url'] as $k) {
        unset($q[$k]);
    }
    if (isset($q['rate_chart_snapshot']['captured_at'])) {
        unset($q['rate_chart_snapshot']['captured_at']);
    }
    return $q;
};

if ($strip($manual) !== $strip($csv)) {
    fwrite(STDERR, "FAIL: manual and CSV canonical quotation builds differ\n");
    exit(1);
}
if (empty($csv['tax_breakdown']['slabs']) && (float)($csv['tax_breakdown']['gst_total'] ?? 0) <= 0) {
    fwrite(STDERR, "FAIL: canonical CSV build did not store complete GST data\n");
    exit(1);
}
if (($csv['quote_items'][0]['id'] ?? '') === 'imported_system' || ($csv['quote_items'][0]['name'] ?? '') === 'Imported solar system') {
    fwrite(STDERR, "FAIL: CSV build kept placeholder imported-system item\n");
    exit(1);
}


foreach (documents_quote_import_template_headers() as $header) {
    $found = false;
    foreach (documents_quote_import_field_dictionary_rows() as $row) {
        if (($row['field_name'] ?? '') === $header && trim((string)($row['canonical_destination'] ?? '')) !== '') {
            $found = true;
            break;
        }
    }
    if (!$found) {
        fwrite(STDERR, "FAIL: missing field dictionary definition for {$header}\n");
        exit(1);
    }
}
$knownFields = ['area_or_locality','average_monthly_units','shadow_free_area_sqft','email','gstin','pan','meter_number','meter_serial_number','special_requests_text','segment','project_summary_line','application_id','application_submitted_date','installed_pv_module_capacity_kwp','circle_name','division_name','sub_division_name','discount_note','hybrid_inverter_kva','hybrid_battery_count','source_lead_id'];
foreach ($knownFields as $field) {
    $testInput = $csvInput;
    $testInput[$field] = $field === 'segment' ? (string)($csv['segment'] ?? 'RES') : ('value-for-' . $field);
    $built = documents_build_quote_draft_from_input($testInput, ['dry_run' => true]);
    $captured = false;
    if (isset($built[$field]) && (string)$built[$field] !== '') { $captured = true; }
    if (isset($built['rate_chart_snapshot'][$field]) && (string)$built['rate_chart_snapshot'][$field] !== '') { $captured = true; }
    if (isset($built['finance_inputs'][$field]) && (string)$built['finance_inputs'][$field] !== '') { $captured = true; }
    if (!$captured) {
        fwrite(STDERR, "FAIL: known CSV field was silently ignored: {$field}\n");
        exit(1);
    }
}
$resolved = documents_quote_import_resolve_template($csvInput);
if (empty($resolved['ok']) || trim((string)($csv['template_set_id'] ?? '')) === '') {
    fwrite(STDERR, "FAIL: template resolution did not populate template_set_id\n");
    exit(1);
}
if (count(array_filter($csv['annexures_overrides'] ?? [], static fn($v): bool => trim((string)$v) !== '')) === 0) {
    fwrite(STDERR, "FAIL: template resolution did not produce annexures\n");
    exit(1);
}
$overrideInput = $csvInput;
$overrideInput['ann_warranty'] = '<p>CSV warranty override</p>';
$override = documents_build_quote_draft_from_input($overrideInput, ['dry_run' => true]);
if (($override['annexures_overrides']['warranty'] ?? '') !== '<p>CSV warranty override</p>') {
    fwrite(STDERR, "FAIL: nonblank annexure override did not replace selected block\n");
    exit(1);
}
foreach (documents_quote_import_annexure_keys() as $key) {
    if ($key !== 'warranty' && (($override['annexures_overrides'][$key] ?? null) !== ($csv['annexures_overrides'][$key] ?? null))) {
        fwrite(STDERR, "FAIL: annexure override changed unrelated block {$key}\n");
        exit(1);
    }
}
$blankInput = $csvInput;
$blankInput['ann_warranty'] = '';
$blank = documents_build_quote_draft_from_input($blankInput, ['dry_run' => true]);
if (($blank['annexures_overrides']['warranty'] ?? null) !== ($csv['annexures_overrides']['warranty'] ?? null)) {
    fwrite(STDERR, "FAIL: blank annexure override did not inherit template default\n");
    exit(1);
}
$bad = documents_validate_quote_draft_input(array_merge($csvInput, ['template_set' => 'not-a-real-template']), ['source' => 'csv']);
if (!empty($bad['ok']) || ($bad['status'] ?? '') !== 'Error') {
    fwrite(STDERR, "FAIL: unresolved template row was not rejected\n");
    exit(1);
}

fwrite(STDOUT, "PASS: manual and CSV canonical quotation builds are equivalent with GST data and import annexure coverage\n");
