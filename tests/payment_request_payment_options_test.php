<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

function payment_options_assert(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$company = documents_company_profile_defaults();
$base = array_merge(documents_payment_request_defaults(), [
    'id' => 'PAYREQ-863', 'quotation_id' => 'DE/Q/863', 'amount_requested' => 74999.99,
]);
$before = $base;
$options = documents_payment_request_payment_options($base, $company);
payment_options_assert($options['active'] && $options['upi_url'] !== '', '₹74,999.99 permits UPI');
payment_options_assert(str_contains($options['upi_url'], 'am=74999.99'), 'UPI uses the exact saved amount');
payment_options_assert(str_contains(rawurldecode($options['upi_url']), 'Payment request PAYREQ-863 / DE/Q/863'), 'UPI includes request and quotation references');
payment_options_assert($base === $before, 'building options does not mutate the request or its status');

foreach ([75000, 75000.01] as $amount) {
    $request = array_merge($base, ['amount_requested' => $amount]);
    payment_options_assert(documents_payment_request_payment_options($request, $company)['upi_url'] === '', 'UPI blocked at and above ₹75,000');
}

foreach (['draft', 'sent', 'phone_requested', 'overdue'] as $status) {
    $request = array_merge($base, ['status'=>$status]);
    $html = documents_payment_request_payment_options_html($request, $company);
    payment_options_assert(str_contains($html, 'Punjab National Bank') && str_contains($html, 'Hinoo'), "bank details shown for {$status}");
    foreach (['4670002100003474', 'PUNB0467000', '₹74999.99', 'PAYREQ-863 / DE/Q/863'] as $copyValue) {
        payment_options_assert(str_contains($html, 'data-copy="'.$copyValue.'"'), "copy control provided for {$copyValue}");
    }
}

foreach (['paid', 'cancelled', 'archived'] as $status) {
    $request = array_merge($base, ['status'=>$status]);
    payment_options_assert(documents_payment_request_payment_options_html($request, $company) === '', "payment actions blocked for {$status}");
    payment_options_assert(!str_contains(documents_build_payment_request_message($request), 'Pay via UPI:'), "message blocks inactive {$status}");
}

$message = documents_build_payment_request_message($base);
payment_options_assert(str_contains($message, 'Account number: 4670002100003474'), 'WhatsApp/email shared content includes bank details');
payment_options_assert(str_contains($message, 'Pay via UPI: upi://pay?'), 'WhatsApp/email shared content includes UPI');

foreach (['admin-documents.php', 'customer-dashboard.php'] as $renderer) {
    $source = file_get_contents(__DIR__ . '/../' . $renderer);
    payment_options_assert(is_string($source) && str_contains($source, 'documents_payment_request_payment_options_html'), "{$renderer} uses shared renderer");
}

payment_options_assert(str_contains((string)file_get_contents(__DIR__.'/../admin/includes/documents_helpers.php'), 'documents_payment_request_refresh_from_receipts'), 'receipt reconciliation remains present');
echo "payment request payment options tests passed\n";
