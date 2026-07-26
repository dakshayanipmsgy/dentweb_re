<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

function project_customer_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dir = sys_get_temp_dir() . '/dentweb-project-customer-' . bin2hex(random_bytes(5));
$store = new CustomerFsStore($dir);
$quote = documents_quote_defaults();
$quote['id'] = 'Q-LINK-1';
$quote['quote_no'] = 'QT-809';
$quote['customer_mobile'] = '+91 98765 43210';
$quote['customer_name'] = 'Anita Kumari';
$quote['site_address'] = 'Safe snapshot address';
$quote['customer_snapshot'] = array_merge(documents_customer_snapshot_defaults(), [
    'mobile' => '+91 98765 43210',
    'name' => 'Anita Kumari',
    'city' => 'Ranchi',
]);

$missing = documents_project_customer_user_link($quote, $store);
project_customer_assert($missing['state'] === 'missing' && $missing['label'] === 'Not in Customer Users', 'missing customer is visible');

$created = documents_project_create_or_link_customer($quote, $store);
project_customer_assert($created['ok'] && $created['created'], 'customer is created from the snapshot');
project_customer_assert((string) ($created['customer']['serial_number'] ?? '') !== '', 'store generated a serial number');
project_customer_assert((string) ($created['customer']['address'] ?? '') === 'Safe snapshot address', 'safe quotation fields were copied');

$again = documents_project_create_or_link_customer($quote, $store);
project_customer_assert($again['ok'] && !$again['created'], 'double click links instead of duplicating');
project_customer_assert(count($store->listCustomers()) === 1, 'only one customer exists');

$quote['customer_user_link'] = ['mobile' => '9876543210', 'link_type' => 'created'];
$link = documents_project_customer_user_link($quote, $store);
project_customer_assert($link['label'] === 'Customer account created', 'successful creation metadata controls the created badge');

$conflict = $quote;
$conflict['customer_name'] = 'Different Person';
$conflict['customer_snapshot']['name'] = 'Different Person';
$blocked = documents_project_create_or_link_customer($conflict, $store);
project_customer_assert(!$blocked['ok'] && str_contains($blocked['error'], 'different customer identity'), 'identity conflict blocks creation');
project_customer_assert((string) ($store->findByMobile('9876543210')['name'] ?? '') === 'Anita Kumari', 'existing customer was not overwritten');

$invalid = $quote;
$invalid['customer_mobile'] = '123';
$invalid['customer_snapshot']['mobile'] = '123';
$bad = documents_project_create_or_link_customer($invalid, $store);
project_customer_assert(!$bad['ok'] && str_contains($bad['error'], 'valid 10-digit'), 'invalid mobile gives a clear error');

foreach (glob($dir . '/*') ?: [] as $file) { @unlink($file); }
@rmdir($dir);
echo "project customer user link tests passed\n";
