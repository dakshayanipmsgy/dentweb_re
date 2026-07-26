<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/customer_operations.php';

function conflict_links_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$dir = sys_get_temp_dir() . '/dentweb-conflict-links-' . bin2hex(random_bytes(5));
$store = new CustomerFsStore($dir);
$created = $store->addCustomer(['name'=>'Customer User Name','mobile'=>'9876543210','address'=>'Main Road','city'=>'Ranchi','pin_code'=>'834001']);
conflict_links_assert(!empty($created['success']), 'customer fixture created');
$quote = documents_quote_defaults();
$quote['id']='Q-CONFLICT-831'; $quote['quote_no']='QT-831'; $quote['status']='accepted';
$quote['customer_name']='Quotation Name'; $quote['customer_mobile']='9876543210';
$quote['customer_snapshot']=array_merge(documents_customer_snapshot_defaults(), ['name'=>'Quotation Name','mobile'=>'9876543210']);
$_SESSION['csrf_token']='test';
$html = customer_operations_render($quote, 'accepted_customers', true, $store);
foreach (['Resolve conflict','Open Customer User','Review quotation','Change quotation mobile','Review differences','Complete customer details','Quotation Name','Customer User Name','quotation-view.php?id=Q-CONFLICT-831','admin-customers.php?view=9876543210','mobile-correction-Q-CONFLICT-831','return_to=admin-documents.php'] as $needle) {
    conflict_links_assert(str_contains(html_entity_decode($html), $needle), 'rendered actionable conflict contains '.$needle);
}
conflict_links_assert(customer_operations_valid_return_to('admin-documents.php?tab=accepted_customers&view=Q-CONFLICT-831') !== '', 'same-project return URL accepted');
foreach (['https://evil.example/','//evil.example/','admin-customers.php?view=9876543210','admin-documents.php?tab=company'] as $unsafe) {
    conflict_links_assert(customer_operations_valid_return_to($unsafe) === '', 'unsafe or unrelated return URL rejected');
}
$page = file_get_contents(__DIR__ . '/../admin-documents.php');
conflict_links_assert(str_contains($page, 'Create in Customer Users'), 'existing creation workflow preserved');
conflict_links_assert(str_contains($page, "!empty(\$customer['archived'])"), 'archived conflict is identified and linked rather than duplicated');
foreach (glob($dir.'/*') ?: [] as $file) @unlink($file); @rmdir($dir);
echo "project customer conflict resolution links test passed\n";
