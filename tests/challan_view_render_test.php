<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/customer_document_acceptance.php';
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
function ok($cond, string $msg): void { if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); } }

$quoteId = 'quote_issue_610_render';
$adviceId = 'da_issue_610_render';
$challanId = 'dc_issue_610_render';
$csrf = 'csrf_issue_610';
$numberingRulesPath = documents_settings_dir() . '/numbering_rules.json';
$numberingRulesBefore = is_file($numberingRulesPath) ? file_get_contents($numberingRulesPath) : null;
$numberingRulesPerms = is_file($numberingRulesPath) ? (fileperms($numberingRulesPath) & 0777) : null;
$generatedPaths = [
    documents_sales_documents_dir(),
    documents_base_dir() . '/packing_lists.json',
    documents_base_dir() . '/payment_receipts.json',
    documents_inventory_components_path(),
    documents_inventory_kits_path(),
    documents_inventory_component_variants_path(),
    documents_inventory_stock_path(),
    documents_inventory_transactions_path(),
    documents_inventory_tax_profiles_path(),
    documents_inventory_dir() . '/inventory_edits_log.json',
];

$removePath = static function (string $path) use (&$removePath): void {
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $removePath($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
};

$cleanup = static function () use ($quoteId, $adviceId, $challanId, $numberingRulesPath, $numberingRulesBefore, $numberingRulesPerms, $generatedPaths, $removePath): void {
    @unlink(documents_quotations_dir() . '/' . safe_filename($quoteId) . '.json');
    @unlink(documents_dispatch_advices_dir() . '/' . safe_filename($adviceId) . '.json');
    @unlink(documents_challans_dir() . '/' . safe_filename($challanId) . '.json');
    foreach ($generatedPaths as $path) {
        if (file_exists($path)) {
            $removePath($path);
        }
    }
    if ($numberingRulesBefore !== null) {
        file_put_contents($numberingRulesPath, $numberingRulesBefore);
        if ($numberingRulesPerms !== null) { @chmod($numberingRulesPath, $numberingRulesPerms); }
    } else {
        @unlink($numberingRulesPath);
    }
};
$cleanup();
register_shutdown_function($cleanup);

documents_ensure_structure();
$quote = documents_quote_defaults();
$quote['id'] = $quoteId;
$quote['quote_no'] = 'Q-610';
$quote['status'] = 'accepted';
$quote['is_current_version'] = true;
$quote['customer_name'] = 'Render Customer';
$quote['customer_mobile'] = '9876543210';
$quote['created_at'] = $quote['updated_at'] = date('c');
ok(!empty(documents_save_quote($quote)['ok']), 'saved eligible quote');

$advice = documents_dispatch_advice_defaults();
$advice['id'] = $adviceId;
$advice['dispatch_advice_no'] = 'DA-610';
$advice['quotation_id'] = $quoteId;
$advice['quotation_no'] = 'Q-610';
$advice['status'] = 'acknowledged';
$advice['customer_name'] = 'Render Customer';
$advice['customer_mobile'] = '9876543210';
$advice['delivery_address'] = 'Render Site Address';
$advice['planned_dispatch_date'] = '2026-06-17';
$advice['items'] = [['line_id' => 'line610', 'name' => 'Solar Panel', 'description' => 'Accepted panel', 'brand_model' => 'Model X', 'qty' => 2, 'unit' => 'Nos', 'remarks' => 'Accepted']];
$advice['customer_acceptance'] = ['confirmed_at' => date('c'), 'document_id' => $adviceId, 'document_no' => 'DA-610', 'document_version' => 1, 'acceptance_ref' => 'ACC-DA-610'];
ok(!empty(documents_save_dispatch_advice($advice)['ok']), 'saved accepted dispatch advice');

$challan = documents_challan_defaults();
$challan['id'] = $challanId;
$challan['challan_no'] = $challan['dc_number'] = 'CHL-610';
$challan['status'] = 'draft';
$challan['workflow_status'] = 'created';
$challan['dispatch_status'] = 'not_dispatched';
$challan['dispatch_advice_id'] = $adviceId;
$challan['dispatch_advice_no'] = 'DA-610';
$challan['linked_quote_id'] = $challan['quote_id'] = $quoteId;
$challan['linked_quote_no'] = 'Q-610';
$challan['customer_snapshot'] = ['name' => 'Render Customer', 'mobile' => '9876543210', 'address' => 'Render Site Address'];
$challan['customer_mobile'] = '9876543210';
$challan['delivery_address'] = 'Render Site Address';
$challan['items'] = documents_challan_items_from_dispatch_advice($advice);
$challan['created_at'] = $challan['updated_at'] = date('c');
ok(!empty(documents_save_challan($challan)['ok']), 'saved created challan');

$cmd = escapeshellcmd(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg(__DIR__ . '/challan_view_render_runner.php') . ' ' . escapeshellarg($challanId) . ' get ' . escapeshellarg($csrf);
$output = shell_exec($cmd);
ok(is_string($output), 'render produced output');
foreach (['Registered mobile','Accepted material snapshot','Dispatch details','Dispatch date','Dispatch time','Vehicle number','Driver / transporter','Driver mobile','E-way bill / reference','Delivery notes','Mark Dispatched','Save Dispatch Details'] as $needle) {
    ok(str_contains($output, $needle), 'render contains ' . $needle);
}
ok(str_contains($output, '</html>'), 'render includes closing html');

shell_exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/challan_view_render_runner.php') . ' ' . escapeshellarg($challanId) . ' save ' . escapeshellarg($csrf));
$saved = documents_get_challan($challanId);
ok(($saved['delivery_date'] ?? '') === '2026-06-17', 'saved dispatch date persisted');
ok(($saved['dispatch_time'] ?? '') === '10:30', 'saved dispatch time persisted');
ok(($saved['vehicle_no'] ?? '') === 'JH01AB1234', 'saved vehicle persisted');

shell_exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/challan_view_render_runner.php') . ' ' . escapeshellarg($challanId) . ' dispatch ' . escapeshellarg($csrf));
$dispatched = documents_get_challan($challanId);
ok(documents_challan_workflow_status($advice, $dispatched) === 'Dispatched', 'mark dispatched transition persisted');
ok(!empty($dispatched['public_share_enabled']), 'share enabled after dispatch');

$cleanup();
echo "challan view render tests passed\n";
