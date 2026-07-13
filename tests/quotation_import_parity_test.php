<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/solar_finance_reports.php';
require_once __DIR__ . '/../includes/quotation_import_service.php';

$input = [
    'import_key' => 'PARITY-001',
    'customer_name' => 'Parity Customer',
    'customer_mobile' => '9876543210',
    'party_type' => 'lead',
    'template_set' => 'pm surya ghar - residential (subsidy) (res)',
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

fwrite(STDOUT, "PASS: manual and CSV canonical quotation builds are equivalent with GST data\n");
