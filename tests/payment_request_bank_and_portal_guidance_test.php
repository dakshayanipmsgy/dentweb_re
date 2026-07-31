<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

function guidance_ok(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$profilePath = documents_company_profile_path();
$profileBefore = is_file($profilePath) ? file_get_contents($profilePath) : null;
$complete = documents_company_bank_transfer_details([
    'bank_account_name' => ' Account Holder ', 'bank_name' => ' Test Bank ',
    'bank_account_no' => ' 001234500 ', 'bank_ifsc' => ' TEST0007 ', 'bank_branch' => ' Main ',
]);
guidance_ok(array_column($complete['fields'], 'value', 'key') === [
    'bank_account_name'=>'Account Holder', 'bank_name'=>'Test Bank', 'bank_account_no'=>'001234500',
    'bank_ifsc'=>'TEST0007', 'bank_branch'=>'Main',
], 'complete bank details preserve exact trimmed display and Copy values');
guidance_ok(array_values(array_filter($complete['fields'], fn(array $f): bool => $f['copyable'])) === [$complete['fields'][2], $complete['fields'][3]], 'only account number and IFSC are copyable');
$partial = documents_company_bank_transfer_details(['bank_name'=>'Only Bank', 'bank_account_no'=>' ', 'bank_ifsc'=>'']);
guidance_ok(count($partial['fields']) === 1 && $partial['fields'][0]['value'] === 'Only Bank', 'partial bank details omit blanks');
guidance_ok(!documents_company_bank_transfer_details([])['has_details'], 'empty bank configuration provides no-details fallback');

$dir = sys_get_temp_dir() . '/dentweb-guidance-' . bin2hex(random_bytes(5));
$store = new CustomerFsStore($dir);
$defaultHash = password_hash('abcd1234', PASSWORD_DEFAULT);
$created = $store->addCustomer(['mobile'=>'+91 98765 43210', 'name'=>'Exact Owner', 'password_hash'=>$defaultHash]);
guidance_ok(!empty($created['success']), 'Customer User fixture created');
$request = ['customer_mobile'=>'9876543210', 'customer_name'=>'Exact Owner', 'quotation_id'=>'Q-901'];
$_SERVER['SCRIPT_NAME'] = '/app/payment-request.php';
$portal = documents_payment_request_portal_guidance($request, $store);
guidance_ok($portal['available'] && $portal['login_url'] === '/app/login.php?login_type=customer', 'route-safe subdirectory Customer Login URL');
guidance_ok($portal['show_default_password'] && str_contains($portal['password_instruction'], 'default password abcd1234'), 'default password is shown only when its hash verifies');
guidance_ok(!str_contains(json_encode($portal), '9876543210') && !isset($portal['password_hash']), 'presentation helper discloses no full mobile, hash, or raw user');

$store->updateCustomer('9876543210', ['password_hash'=>password_hash('changed-secret', PASSWORD_DEFAULT)]);
$changed = documents_payment_request_portal_guidance($request, $store);
guidance_ok($changed['available'] && !$changed['show_default_password'] && $changed['password_instruction'] === 'Login with your registered mobile number and your current password.', 'changed password receives current-password guidance');
guidance_ok(!documents_payment_request_portal_guidance(['customer_mobile'=>'9123456789','customer_name'=>'Missing'], $store)['available'], 'missing Customer User is unavailable');
guidance_ok(!$store->archiveCustomer('9876543210') || !documents_payment_request_portal_guidance($request, $store)['available'], 'archived Customer User is unavailable');
$store->restoreCustomer('9876543210');
guidance_ok(!documents_payment_request_portal_guidance(['customer_mobile'=>'9876543210','customer_name'=>'Different Owner'], $store)['available'], 'ownership-conflicted Customer User is unavailable');

$dataPath = $dir . '/customers.json';
$raw = json_decode((string) file_get_contents($dataPath), true);
$raw['customers'][] = $raw['customers'][0];
file_put_contents($dataPath, json_encode($raw));
guidance_ok(!documents_payment_request_portal_guidance($request, $store)['available'], 'duplicated Customer User is unavailable');

$source = (string) file_get_contents(__DIR__ . '/../payment-request.php');
foreach (['After making payment', 'does not automatically confirm payment', 'will verify the payment', 'finalize the money receipt', 'official payment record', 'where available for your project', 'Complaint submission and complaint history'] as $wording) {
    guidance_ok(str_contains($source, $wording), "public page contains {$wording}");
}
guidance_ok(str_contains($source, '$available ? documents_company_bank_transfer_details()') && str_contains($source, 'if(!$available)'), 'invalid and inactive links render no bank details');
guidance_ok(substr_count($source, 'documents_payment_request_authorize_upi(') === 1 && str_contains($source, "REQUEST_METHOD'] === 'POST'"), 'only existing UPI POST authorizes or mutates a launch');
guidance_ok(!preg_match('/documents_(save|refresh|create).*receipt|amount_paid_against_request\s*=|outstanding_against_request\s*=/i', $source), 'public page does not mutate payments, receipts, or outstanding amounts');
guidance_ok((is_file($profilePath) ? file_get_contents($profilePath) : null) === $profileBefore, 'company_profile.json remains unchanged');

foreach (glob($dir . '/*') ?: [] as $file) @unlink($file);
@rmdir($dir);
echo "payment request bank and portal guidance tests passed\n";
