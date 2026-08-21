<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/dentweb_991_' . bin2hex(random_bytes(4));
putenv('DOCUMENTS_BASE_DIR=' . $root . '/documents');
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/invoice_component_catalogue.php';

$count = 0;
$ok = static function (bool $condition, string $message) use (&$count): void {
    $count++;
    if (!$condition) { throw new RuntimeException($message); }
};

try {
    documents_ensure_structure();
    $rows = invoice_component_details_sanitize([
        ['component_name'=>' Inverter ', 'brand'=>' UTL ', 'model'=>' Sigma   324 ', 'serial_no'=>' INV-001 '],
        ['component_name'=>'Inverter', 'brand'=>'UTL', 'model'=>'Sigma 324', 'serial_no'=>'INV-002'],
        ['component_name'=>'', 'brand'=>'', 'model'=>'', 'serial_no'=>''],
    ]);
    $ok(count($rows) === 2 && $rows[0]['model'] === 'Sigma 324', 'rows are trimmed, safely space-normalized, and empty rows ignored');
    $invoice = array_merge(documents_invoice_defaults(), ['id'=>'inv-991', 'component_details'=>$rows]);
    $ok(documents_save_invoice($invoice)['ok'], 'component rows save on an invoice');
    $loaded = documents_get_invoice('inv-991');
    $ok(array_column($loaded['component_details'], 'serial_no') === ['INV-001','INV-002'], 'multiple duplicate equipment rows and their order survive reload');
    $invoice['component_details'] = [$rows[1]];
    documents_save_invoice($invoice);
    $ok(array_column(documents_get_invoice('inv-991')['component_details'], 'serial_no') === ['INV-002'], 'removed row stays deleted after save');

    $ok(invoice_component_catalogue_learn($rows)['ok'], 'new catalogue values are learned after committed invoice save');
    $ok(invoice_component_catalogue_learn([['component_name'=>' inverter ', 'brand'=>'utl', 'model'=>' sigma  324', 'serial_no'=>'SECRET-SERIAL']])['ok'], 'case and spacing variant merges');
    invoice_component_catalogue_learn([['component_name'=>'Battery','brand'=>'UTL','model'=>'UST 1560','serial_no'=>'BAT-1'], ['component_name'=>'Inverter','brand'=>'Deye','model'=>'SUN-5K-SG04LP1','serial_no'=>'INV-3']]);
    $catalogue = invoice_component_catalogue_read();
    $ok(count($catalogue['components']) === 2 && $catalogue['components'][0]['name'] === 'Inverter', 'component source deduplicates while retaining readable first casing');
    $inverter = $catalogue['components'][0];
    $battery = $catalogue['components'][1];
    $ok(array_column($inverter['brands'], 'name') === ['UTL','Deye'] && array_column($battery['brands'], 'name') === ['UTL'], 'brands remain contextual beneath their components');
    $ok($inverter['brands'][0]['models'] === ['Sigma 324'] && $battery['brands'][0]['models'] === ['UST 1560'], 'models remain contextual beneath component and brand');
    $encoded = json_encode($catalogue);
    $ok(!str_contains($encoded, 'SECRET-SERIAL') && !str_contains($encoded, 'BAT-1'), 'serial numbers never enter reusable catalogue');

    file_put_contents(invoice_component_catalogue_path(), '{malformed');
    $ok(invoice_component_catalogue_read()['components'] === [], 'malformed catalogue is handled as empty');
    $old = array_merge(documents_invoice_defaults(), ['id'=>'old-991']);
    unset($old['component_details']);
    documents_save_invoice($old);
    $ok(documents_get_invoice('old-991')['component_details'] === [], 'historical invoices without component_details remain compatible');

    $admin = file_get_contents(__DIR__ . '/../admin-invoices.php');
    $view = file_get_contents(__DIR__ . '/../invoice-view.php');
    $ok(str_contains($admin, 'if (!documents_invoice_is_draft($doc))') && str_contains($admin, '$isDraft?\'\':\'readonly\''), 'existing draft-only save guard and read-only rendering apply to equipment');
    $ok(str_contains($view, "if (\$componentDetails !== [])") && str_contains($view, '<h2>Component Details</h2>'), 'existing invoice renderer conditionally renders component details only when rows exist');
    $ok(substr_count($view, "\$esc(\$componentRow[") === 4, 'renderer HTML-escapes every component detail value');
    echo "issue_991_invoice_component_catalogue_test passed ($count assertions)\n";
} finally {
    if (is_dir($root)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($root);
    }
}
