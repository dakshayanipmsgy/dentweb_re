<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$base = [
    'customer_name' => 'Valid Customer',
    'customer_mobile' => '9876543210',
    'site_address' => 'Valid site address',
    'capacity_kwp' => '3',
    'input_total_gst_inclusive' => 250000,
];

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$label}\n");
};

$valid = documents_quote_has_valid_acceptance_data($base);
$assert(($valid['ok'] ?? false) === true, 'populated quote fields pass acceptance validation');
$assert(($valid['missing_fields'] ?? null) === [], 'valid quote reports no missing fields');

$staleSnapshot = $base;
$staleSnapshot['customer_snapshot'] = [
    'name' => 'Old Snapshot Name',
    'mobile' => 'not-a-mobile',
    'address' => '',
];
$validWithStaleSnapshot = documents_quote_has_valid_acceptance_data($staleSnapshot);
$assert(($validWithStaleSnapshot['ok'] ?? false) === true, 'populated quote fields override stale snapshot values');

$missingCustomerData = $base;
$missingCustomerData['customer_name'] = '';
$missingCustomerData['customer_mobile'] = '';
$missingCustomerData['site_address'] = '';
$missing = documents_quote_has_valid_acceptance_data($missingCustomerData);
$assert(($missing['ok'] ?? true) === false, 'missing customer acceptance data remains invalid');
$assert(($missing['missing_fields'] ?? []) === ['customer_name', 'customer_mobile', 'site_address'], 'missing customer fields are identified');
$assert(($missing['error'] ?? '') === 'Complete customer name, mobile, and site address before acceptance.', 'missing customer data gets actionable helper text');

$missingAmount = $base;
$missingAmount['input_total_gst_inclusive'] = 0;
$amountResult = documents_quote_has_valid_acceptance_data($missingAmount);
$assert(($amountResult['ok'] ?? true) === false, 'missing total amount remains invalid');
$assert(($amountResult['missing_fields'] ?? []) === ['total_amount'], 'missing total amount is identified');
