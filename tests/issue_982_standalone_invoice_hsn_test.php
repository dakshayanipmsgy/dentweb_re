<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/dentweb_982_' . bin2hex(random_bytes(4));
putenv('DOCUMENTS_BASE_DIR=' . $root . '/documents');
putenv('LEGACY_BILLING_BASE_DIR=' . $root . '/legacy');
require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$count = 0;
$ok = static function (bool $condition, string $message) use (&$count): void {
    $count++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    documents_ensure_structure();
    json_save(documents_inventory_components_path(), [[
        'id' => 'panel', 'name' => 'Panel', 'hsn' => '8541', 'default_unit' => 'Nos',
    ], [
        'id' => 'service', 'name' => 'Service', 'hsn' => '', 'default_unit' => 'Job',
    ]]);
    json_save(documents_inventory_component_variants_path(), [[
        'id' => 'panel-special', 'component_id' => 'panel', 'display_name' => 'Special', 'hsn_override' => '85414300',
    ]]);

    $invoice = documents_invoice_defaults();
    $invoice['id'] = 'inv-982';
    $invoice['commercial_ref'] = ['type' => 'standalone_invoice', 'id' => 'inv-982'];

    $variant = documents_standalone_apply_master_items($invoice, [[
        'type' => 'component', 'component_id' => 'panel', 'variant_id' => 'panel-special',
        'quantity' => 1, 'unit_price_incl_gst' => 118,
    ]]);
    $ok($variant['ok'] && ($variant['invoice']['commercial_items'][0]['hsn'] ?? '') === '85414300', 'variant HSN auto-fill wins over component HSN');

    $manual = documents_standalone_apply_master_items($invoice, [[
        'type' => 'component', 'component_id' => 'panel', 'variant_id' => 'panel-special', 'hsn' => 'MANUAL-982',
        'quantity' => 1, 'unit_price_incl_gst' => 118,
    ]]);
    $savedInvoice = $manual['invoice'];
    $ok(($savedInvoice['commercial_items'][0]['hsn'] ?? '') === 'MANUAL-982', 'manual HSN has priority in commercial snapshot');
    $ok(($savedInvoice['quote_items'][0]['hsn_snapshot'] ?? '') === 'MANUAL-982' && ($savedInvoice['quote_items'][0]['hsn_source'] ?? '') === 'invoice_manual', 'manual HSN is saved in compatible structured snapshot fields');
    $ok(($savedInvoice['tax_breakdown']['items'][0]['hsn'] ?? '') === 'MANUAL-982', 'tax breakup snapshots manual HSN');
    $ok(documents_save_invoice($savedInvoice)['ok'] && (documents_get_invoice('inv-982')['commercial_items'][0]['hsn'] ?? '') === 'MANUAL-982', 'manual HSN survives draft save and reload');
    $ok((documents_inventory_components(false)[0]['hsn'] ?? '') === '8541', 'invoice override does not modify Items Master');

    $fallback = documents_standalone_apply_master_items($invoice, [[
        'type' => 'component', 'component_id' => 'service', 'quantity' => 1, 'unit_price_incl_gst' => 100,
    ]]);
    $ok(($fallback['invoice']['commercial_items'][0]['hsn'] ?? '') === '8541', 'quotation HSN default is used when master HSN is empty');

    $admin = file_get_contents(__DIR__ . '/../admin-invoices.php');
    $view = file_get_contents(__DIR__ . '/../invoice-view.php');
    $ok(str_contains($admin, 'items[<?=$i?>][hsn]') && str_contains($admin, 'invoiceAutofillHsn'), 'direct invoice rows expose editable auto-filled HSN');
    $ok(str_contains($view, '<th>HSN</th>') && str_contains($view, '$itemHsn($item,$index)') && str_contains($view, "$taxItem" . "['hsn']"), 'invoice and customer renderer use saved HSN in scope and tax breakup');
    echo "issue_982_standalone_invoice_hsn_test passed ($count assertions)\n";
} finally {
    $it = is_dir($root) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) : null;
    if ($it) {
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
    }
    if (is_dir($root)) {
        rmdir($root);
    }
}
