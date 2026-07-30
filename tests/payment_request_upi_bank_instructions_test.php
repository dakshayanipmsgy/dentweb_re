<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

function payment_instruction_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$company = documents_get_company_profile_for_quotes();
payment_instruction_assert($company['upi_id'] === 'd.entranchi@ybl', 'UPI configuration is centralized in the company profile.');
payment_instruction_assert($company['bank_account_no'] === '4670002100003474', 'Bank configuration is centralized in the company profile.');

$base = array_merge(documents_payment_request_defaults(), [
    'id' => 'PAYREQ/866',
    'quotation_id' => 'QUO/2026/866',
    'customer_name' => 'Customer',
    'customer_mobile' => '9876543210',
    'amount_requested' => 27290,
    'status' => 'draft',
]);
$instructions = documents_payment_request_payment_instructions($base, $company);
payment_instruction_assert($instructions['upi_eligible'] === true, '₹27,290 is UPI eligible.');
payment_instruction_assert(str_contains($instructions['upi_uri'], 'am=27290.00'), 'UPI URI retains the exact two-decimal amount.');
payment_instruction_assert(str_contains($instructions['upi_uri'], 'pa=d.entranchi%40ybl'), 'UPI ID is URL encoded.');
payment_instruction_assert(str_contains($instructions['upi_uri'], 'tn=PAYREQ%2F866'), 'Request reference is URL encoded.');

$atLimit = documents_payment_request_payment_instructions(array_merge($base, ['amount_requested'=>75000]), $company);
$overLimit = documents_payment_request_payment_instructions(array_merge($base, ['amount_requested'=>75000.01]), $company);
payment_instruction_assert($atLimit['upi_eligible'] && $atLimit['bank_details'] !== [], '₹75,000 includes UPI and bank details.');
payment_instruction_assert(!$overLimit['upi_eligible'] && $overLimit['upi_uri'] === '' && $overLimit['bank_details'] !== [], '₹75,000.01 is bank-only.');

$message = documents_build_payment_request_message($base, []);
payment_instruction_assert(str_contains($message, $instructions['upi_uri']) && str_contains($message, 'Punjab National Bank'), 'WhatsApp/email message includes shared instructions.');
payment_instruction_assert(str_contains(documents_payment_request_whatsapp_url($base, $message), rawurlencode($message)), 'WhatsApp URL carries the instructions.');
payment_instruction_assert(str_contains(documents_payment_request_mailto($base, $message), rawurlencode($message)), 'Email URL carries the instructions.');

foreach ([['status'=>'paid'], ['status'=>'cancelled'], ['status'=>'archived'], ['status'=>'draft','archived_flag'=>true], ['status'=>'draft','amount_requested'=>0], ['status'=>'draft','amount_requested'=>-1]] as $change) {
    $inactive = documents_payment_request_payment_instructions(array_merge($base, $change), $company);
    payment_instruction_assert(!$inactive['upi_eligible'] && $inactive['upi_uri'] === '', 'Inactive/non-positive requests exclude UPI actions.');
}

$before = $base;
documents_payment_request_payment_instructions($base, $company);
payment_instruction_assert($base === $before, 'Instruction generation does not mutate accounting or request status.');

$adminSource = file_get_contents(__DIR__ . '/../admin-documents.php');
$portalSource = file_get_contents(__DIR__ . '/../customer-dashboard.php');
payment_instruction_assert(str_contains((string) $adminSource, 'documents_payment_request_payment_instructions($request, $company)'), 'Printable request uses the shared helper.');
payment_instruction_assert(str_contains((string) $portalSource, 'Pay <?= customer_portal_safe($customerInr'), 'Portal renders the exact-amount UPI action.');
payment_instruction_assert(str_contains((string) $portalSource, "['cancelled', 'paid', 'archived']"), 'Portal excludes closed requests.');
payment_instruction_assert(str_contains((string) $portalSource, "!empty(\$request['visibility_to_customer'])"), 'Portal preserves internal-only visibility filtering.');

echo "payment request UPI/bank instruction tests passed\n";
