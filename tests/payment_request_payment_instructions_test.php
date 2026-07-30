<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$profilePath = $root . '/data/documents/settings/company_profile.json';
$profileBefore = file_get_contents($profilePath);
require_once $root . '/admin/includes/documents_helpers.php';

function payment_instructions_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$company = [
    'company_name' => 'Fallback & Company',
    'brand_name' => 'Fallback Brand',
    'bank_account_name' => 'Dakshayani & Sons',
    'bank_name' => 'Example Bank',
    'bank_account_no' => '00123456789',
    'bank_ifsc' => 'EXAM0001234',
    'bank_branch' => 'Main & Market',
    'upi_id' => 'accounts.solar@example-bank',
];
$request = [
    'id' => 'PAY REQ/869 & 1',
    'quotation_id' => 'QUOTE/869',
    'customer_name' => 'Test Customer',
    'customer_mobile' => '9999999999',
    'amount_requested' => 27290,
    'status' => 'sent',
    'amount_paid_against_request' => 0,
    'outstanding_against_request' => 27290,
];
$requestBefore = $request;

$instructions = documents_payment_instructions($request, $company);
payment_instructions_assert(is_array($instructions['upi']), '₹27,290 should include UPI');
payment_instructions_assert(str_contains($instructions['upi']['uri'], 'am=27290.00'), 'UPI URI should contain the exact two-decimal amount');
payment_instructions_assert(str_contains($instructions['upi']['uri'], 'cu=INR'), 'UPI URI should specify INR');
payment_instructions_assert(str_contains($instructions['upi']['uri'], 'pa=accounts.solar%40example-bank'), 'UPI ID should be safely encoded');
payment_instructions_assert(str_contains($instructions['upi']['uri'], 'pn=Dakshayani%20%26%20Sons'), 'payee should be safely encoded');
payment_instructions_assert(str_contains($instructions['upi']['uri'], 'tn=PAY%20REQ%2F869%20%26%201'), 'reference should be safely encoded');
payment_instructions_assert($instructions['upi']['label'] === 'Pay ₹27,290.00 via UPI', 'UPI action label should include the amount');

$atLimit = documents_payment_instructions(array_merge($request, ['amount_requested' => 75000]), $company);
$aboveLimit = documents_payment_instructions(array_merge($request, ['amount_requested' => 75000.01]), $company);
payment_instructions_assert(is_array($atLimit['upi']), '₹75,000 should include UPI');
payment_instructions_assert($aboveLimit['upi'] === null, '₹75,000.01 should exclude UPI');
payment_instructions_assert(count($aboveLimit['bank']) === 5, 'requests above the UPI limit should retain bank details');

$missingUpi = documents_payment_instructions($request, array_merge($company, ['upi_id' => ' ']));
payment_instructions_assert($missingUpi['upi'] === null && $missingUpi['bank'] !== [], 'missing UPI should fall back to bank details');
$partialBank = documents_payment_instructions($request, array_merge($company, ['upi_id' => '', 'bank_name' => '', 'bank_account_no' => '', 'bank_branch' => '']));
payment_instructions_assert(array_column($partialBank['bank'], 'label') === ['Account Name', 'IFSC'], 'blank bank fields should be omitted individually');

$messageLines = documents_payment_instruction_message_lines($instructions);
payment_instructions_assert(in_array('UPI ID: accounts.solar@example-bank', $messageLines, true) && in_array('Bank transfer:', $messageLines, true), 'message fallback should include UPI ID and bank details');
payment_instructions_assert(!str_contains(implode("\n", $messageLines), 'upi://'), 'message fallback must not expose a raw UPI URI');

$helperSource = file_get_contents($root . '/admin/includes/documents_helpers.php');
$adminSource = file_get_contents($root . '/admin-documents.php');
$portalSource = file_get_contents($root . '/customer-dashboard.php');
payment_instructions_assert(substr_count($helperSource, 'documents_payment_instructions(') >= 2, 'shared helper should feed payment-request messages');
payment_instructions_assert(str_contains($adminSource, 'documents_payment_request_whatsapp_url($payReq,$payMsg)') && str_contains($adminSource, 'documents_payment_request_mailto($payReq,$payMsg)'), 'WhatsApp and email should share the enriched message');
payment_instructions_assert(str_contains($adminSource, 'documents_payment_instructions($request, $company)'), 'print view should use the shared instructions');
payment_instructions_assert(str_contains($portalSource, 'documents_payment_instructions($request, $paymentCompany)'), 'customer portal should use the shared instructions');
payment_instructions_assert(str_contains($portalSource, "!empty(\$request['visibility_to_customer'])"), 'internal-only requests should remain hidden');
payment_instructions_assert($request === $requestBefore, 'building/clicking instructions must not mutate payment request data');
payment_instructions_assert(!str_contains($helperSource, 'documents_save_payment_request($request)'), 'instruction helper must not save a payment request');
payment_instructions_assert(file_get_contents($profilePath) === $profileBefore, 'Company Profile JSON must remain unchanged');

echo "Payment request payment instructions tests passed.\n";
