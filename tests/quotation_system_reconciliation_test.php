<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/solar_finance_reports.php';

$normalizeCases = [
    'Ongrid' => 'ongrid',
    'on_grid' => 'ongrid',
    'on-grid' => 'ongrid',
    'Hybrid' => 'hybrid',
];
foreach ($normalizeCases as $input => $expected) {
    $actual = documents_quote_normalize_system_type($input);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: normalize {$input} => {$actual}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: normalize {$input}\n");
}

$items = documents_normalize_quote_structured_items([[
    'type' => 'component',
    'component_id' => 'cmp_1',
    'qty' => 1,
    'custom_description' => "Manual component note",
    'auto_description' => 'Auto note',
    'description_mode' => 'manual',
    'meta' => ['managed_system_kit' => true, 'system_type' => 'on-grid', 'model_number' => 'OG-3K'],
]]);
if (($items[0]['custom_description'] ?? '') !== 'Manual component note' || ($items[0]['auto_description'] ?? '') !== 'Auto note' || ($items[0]['description_mode'] ?? '') !== 'manual') {
    fwrite(STDERR, "FAIL: description fields did not persist\n");
    exit(1);
}
if (($items[0]['meta']['system_type'] ?? '') !== 'ongrid') {
    fwrite(STDERR, "FAIL: managed metadata system type was not normalized\n");
    exit(1);
}
fwrite(STDOUT, "PASS: structured item description and metadata persistence\n");

$quote = [
    'system_type' => 'Ongrid',
    'rate_chart_snapshot' => [
        'hybrid_inverter_kva' => 5,
        'hybrid_phase' => '1 Phase',
        'hybrid_battery_count' => 4,
        'battery_code' => 'BAT',
        'inverter_code' => 'INV-TB',
    ],
    'quote_items' => $items,
];
$quote = documents_quote_reconcile_system_configuration($quote);
if (($quote['rate_chart_snapshot']['hybrid_battery_count'] ?? null) !== 0 || ($quote['rate_chart_snapshot']['battery_code'] ?? null) !== '') {
    fwrite(STDERR, "FAIL: Ongrid reconciliation did not clear hybrid fields\n");
    exit(1);
}
fwrite(STDOUT, "PASS: Ongrid reconciliation clears stale hybrid fields\n");
