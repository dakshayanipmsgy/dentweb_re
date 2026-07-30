<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$profile = $root . '/data/documents/settings/company_profile.json';
$profileBefore = file_get_contents($profile);
$customerDir = sys_get_temp_dir() . '/dentweb-payment-portal-' . bin2hex(random_bytes(5));
$stateDir = $customerDir . '/upi-state';
$oldSecret = getenv('PAYMENT_REQUEST_TOKEN_SECRET');
$oldStateDir = getenv('PAYMENT_REQUEST_UPI_STATE_DIR');
putenv('PAYMENT_REQUEST_TOKEN_SECRET=test-payment-request-secret-at-least-32-bytes');
putenv('PAYMENT_REQUEST_UPI_STATE_DIR=' . $stateDir);
require_once $root . '/admin/includes/documents_helpers.php';
require_once $root . '/includes/payment_request_renderer.php';

function issue875_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function issue875_remove(string $path): void { if (!is_dir($path)) { return; } foreach (array_diff(scandir($path) ?: [], ['.','..']) as $entry) { $item=$path.'/'.$entry; is_dir($item) ? issue875_remove($item) : unlink($item); } rmdir($path); }

try {
    $request = array_merge(documents_payment_request_defaults(), [
        'id'=>'PAYREQ-875', 'quotation_id'=>'QUOTE-875', 'customer_name'=>'Portal Customer',
        'customer_mobile'=>'+91 98765 43210', 'amount_requested'=>8750, 'quotation_amount'=>20000,
        'status'=>'sent', 'visibility_to_customer'=>true, 'created_at'=>'2026-07-30T12:00:00+00:00',
        'internal_notes'=>'PRIVATE NOTE',
    ]);
    $url = documents_payment_request_public_url($request);
    issue875_assert(str_starts_with($url, 'https://dakshayani.co.in/payment-request.php?t='), 'messages must use the canonical secure public URL');
    issue875_assert(!str_contains($url, 'admin-documents.php') && documents_payment_request_public_url(array_merge($request,['visibility_to_customer'=>false])) === '', 'admin/internal links must never be shared');

    $token = documents_payment_request_public_token($request);
    issue875_assert(documents_payment_request_upi_nonce($token, 0) !== documents_payment_request_upi_nonce($token, 1), 'nonce must be token and attempt bound');
    issue875_assert(documents_payment_request_upi_attempts($token) === 0, 'GET-style nonce lookup must not count an attempt');

    $store = new CustomerFsStore($customerDir . '/customers');
    $added = $store->addCustomer(['mobile'=>'9876543210','name'=>'Portal Customer','password_hash'=>password_hash('abcd1234', PASSWORD_DEFAULT)]);
    issue875_assert($added['success'], 'temporary customer fixture must be created');
    $temporary = documents_payment_request_portal_guidance($request, $store);
    issue875_assert(in_array('Temporary password: abcd1234', $temporary, true), 'verified unchanged temporary password should be included');
    $store->updateCustomer('9876543210', ['mobile'=>'9876543210','name'=>'Portal Customer','password_hash'=>password_hash('Changed-password-1!', PASSWORD_DEFAULT)]);
    $existing = documents_payment_request_portal_guidance($request, $store);
    issue875_assert(!str_contains(implode("\n", $existing), 'abcd1234') && str_contains(implode("\n", $existing), 'existing password'), 'changed password must never be disclosed as temporary');
    $store->archiveCustomer('9876543210');
    issue875_assert(documents_payment_request_portal_guidance($request, $store) === [], 'archived customer must receive no portal guidance');

    $company = ['company_name'=>'Dakshayani Enterprises','upi_id'=>'pay@example','bank_account_name'=>'Dakshayani Enterprises','bank_name'=>'Test Bank','bank_account_no'=>'1234','bank_ifsc'=>'TEST0001'];
    $html = documents_render_public_payment_request($request, $company, $token, 0);
    foreach (['Payment Request','Pay ₹8,750.00 via UPI','Bank transfer','data-copy','Print Payment Request','fetch(location.pathname'] as $text) { issue875_assert(str_contains($html,$text), 'public page missing '.$text); }
    issue875_assert(!str_contains($html,'PRIVATE NOTE'), 'internal note must remain private');
    $limited = documents_render_public_payment_request($request, $company, $token, 2);
    issue875_assert(!str_contains($limited,'class="btn upi-launch"') && str_contains($limited,'UPI launch limit reached') && str_contains($limited,'Bank transfer'), 'third launch must be disabled while bank details remain');
    foreach (['paid','cancelled','archived'] as $status) { $inactive=documents_render_public_payment_request(array_merge($request,['status'=>$status]),$company,$token,0); issue875_assert(!str_contains($inactive,'class="btn upi-launch"'),'inactive requests expose no UPI launch'); }

    $source = file_get_contents($root . '/payment-request.php');
    issue875_assert(str_contains($source,"REQUEST_METHOD") && str_contains($source,"=== 'POST'") && !str_contains($source,'auth.php'), 'only explicit public POST activation may count');
    issue875_assert(file_get_contents($profile) === $profileBefore, 'Company Profile must not be modified');
} finally {
    issue875_remove($customerDir);
    $oldSecret === false ? putenv('PAYMENT_REQUEST_TOKEN_SECRET') : putenv('PAYMENT_REQUEST_TOKEN_SECRET='.$oldSecret);
    $oldStateDir === false ? putenv('PAYMENT_REQUEST_UPI_STATE_DIR') : putenv('PAYMENT_REQUEST_UPI_STATE_DIR='.$oldStateDir);
}
echo "Payment request public link, retry, and portal guidance tests passed.\n";
