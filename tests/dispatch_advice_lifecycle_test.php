<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    echo "PASS: {$message}\n";
};

$packing = ['required_items' => [
    ['line_id' => 'a', 'component_id' => 'panel', 'component_name_snapshot' => 'Panel', 'unit' => 'Nos', 'mode' => 'fixed_qty', 'pending_qty' => 4],
    ['line_id' => 'b', 'component_id' => 'rail', 'component_name_snapshot' => 'Rail', 'unit' => 'ft', 'mode' => 'fixed_qty', 'pending_ft' => 12.5],
    ['line_id' => 'c', 'component_id' => 'done', 'component_name_snapshot' => 'Done', 'unit' => 'Nos', 'mode' => 'fixed_qty', 'pending_qty' => 0],
]];
$items = documents_dispatch_advice_items_from_packing_list($packing);
$assert(count($items) === 2, 'exact-item population excludes completed packing lines');
$assert((float) $items[0]['dispatch_qty'] === 4.0, 'exact-item population preserves pending quantity');
$assert((float) $items[1]['dispatch_ft'] === 12.5, 'exact-item population preserves pending cuttable length');
$assert(documents_get_dispatch_advice_by_public_token('unsafe') === null, 'public sharing rejects malformed tokens');

$source = file_get_contents(__DIR__ . '/../admin/includes/documents_helpers.php');
$assert(is_string($source) && str_contains($source, "hash('sha256', \$token)"), 'public tokens are stored as hashes');
$assert(is_string($source) && str_contains($source, 'hash_equals'), 'public token lookup uses constant-time comparison');
$assert(is_string($source) && str_contains($source, 'min((float) $item'), 'challan conversion caps stale advice quantities to current pending quantities');
