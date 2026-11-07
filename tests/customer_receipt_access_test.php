<?php
declare(strict_types=1);
putenv('DOCUMENTS_BASE_DIR=' . sys_get_temp_dir() . '/portal_access_' . bin2hex(random_bytes(5)));
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/customer_receipt_access.php';
function receiptAccessCheck(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
$customer = ['mobile' => '9876543210'];
$quote = ['id' => 'q1', 'customer_mobile' => '9876543210'];
$receipt = ['id' => 'r1', 'linked_quotation_id' => 'q1', 'status' => 'finalized', 'amount_rs' => 100, 'customer_mobile' => '9999999999'];
$ledger = documents_receipt_ledger('q1', [$receipt]);
receiptAccessCheck(customer_receipt_can_view($receipt, $quote, $customer, $ledger), 'Current project owner can see canonical finalized receipt despite stale receipt mobile');
receiptAccessCheck(!customer_receipt_can_view($receipt, $quote, ['mobile' => '9999999999'], $ledger), 'Snapshot phone cannot grant another customer access');
receiptAccessCheck(!customer_receipt_can_view($receipt, null, $customer, $ledger), 'Missing project denied');
receiptAccessCheck(!customer_receipt_can_view($receipt, array_merge($quote, ['id' => 'q2']), $customer, $ledger), 'Other project denied');
receiptAccessCheck(!customer_receipt_can_view($receipt, $quote, ['mobile' => ''], $ledger), 'Blank identity denied');
foreach (['draft', 'cancelled', 'voided'] as $status) {
    $row = array_merge($receipt, ['status' => $status]);
    receiptAccessCheck(!customer_receipt_can_view($row, $quote, $customer, documents_receipt_ledger('q1', [$row])), $status . ' receipt denied');
}
foreach (['archived_flag', 'voided', 'reversed'] as $flag) {
    $row = array_merge($receipt, [$flag => true]);
    receiptAccessCheck(!customer_receipt_can_view($row, $quote, $customer, documents_receipt_ledger('q1', [$row])), $flag . ' receipt denied');
}
receiptAccessCheck(!customer_receipt_can_view(array_merge($receipt, ['id' => 'r2']), $quote, $customer, $ledger), 'Unqualified receipt id denied');
echo "customer_receipt_access_test passed\n";
