<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/customer_operations.php';

function assert_customer_operations(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$customer = [
    'name' => 'Test Customer', 'mobile' => '9876543210', 'status' => 'Completed',
    'handover_html_path' => 'handovers/test.html', 'handover_generated_at' => '2026-07-26 10:00:00',
    'handover_version' => 2,
];
$customer['handover_source_hash'] = customer_operations_source_hash($customer);
$state = customer_operations_handover_state($customer);
assert_customer_operations($state['ready'] === true, 'Generated handover must be ready.');
assert_customer_operations($state['needs_regeneration'] === false, 'Unchanged handover source must remain current.');
$customer['address'] = 'Changed after generation';
assert_customer_operations(customer_operations_handover_state($customer)['needs_regeneration'] === true, 'Changed source data must require regeneration.');

$warnings = customer_operations_quote_warnings(
    ['customer_name'=>'Quotation Name', 'customer_mobile'=>'9999999999'],
    ['name'=>'Customer Record Name', 'mobile'=>'8888888888']
);
assert_customer_operations(count($warnings) === 2, 'Name and mobile mismatches must both be explicit.');

$page = (string) file_get_contents(__DIR__ . '/../admin-documents.php');
$helper = (string) file_get_contents(__DIR__ . '/../includes/customer_operations.php');
foreach (['accepted_customers', 'completed_customers', 'customer_operations_render'] as $needle) {
    assert_customer_operations(str_contains($page, $needle), "Documents page must integrate {$needle}.");
}
foreach (['Customer Operations','Create in Customer Users','Open Customer','Mark Handover Sent','Needs regeneration','Open complaints','Recent customer-operation activity','Copy mobile'] as $needle) {
    assert_customer_operations(str_contains($helper, $needle), "Operations presentation must expose {$needle}.");
}
assert_customer_operations(str_contains($page, 'handover_message_prepared_opened'), 'Opening WhatsApp must record prepared/opened separately.');
assert_customer_operations(str_contains($page, 'handover_marked_sent'), 'Explicit sent action must have a distinct audit event.');

echo "project_customer_operations_visibility_test: OK\n";
