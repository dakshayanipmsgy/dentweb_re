<?php
declare(strict_types=1);

$root = dirname(__DIR__);
putenv('PAYMENT_REQUEST_TOKEN_SECRET=issue-878-test-secret');
$_SERVER['HTTP_HOST'] = 'admin.example.test';
require_once $root . '/admin/includes/documents_helpers.php';
require_once $root . '/includes/payment_request_renderer.php';

function public_message_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$request = array_merge(documents_payment_request_defaults(), [
    'id' => 'PAYREQ-878', 'created_at' => '2026-07-30T10:00:00+00:00',
    'quotation_id' => 'QUO-878', 'customer_name' => 'Test Customer',
    'customer_mobile' => '9876543210', 'amount_requested' => 50000,
    'quotation_amount' => 100000, 'visibility_to_customer' => true, 'status' => 'sent',
]);
$url = documents_payment_request_public_url($request);
$message = documents_build_payment_request_message($request, ['total_received'=>25000, 'outstanding'=>75000]);
public_message_assert(str_starts_with($url, 'https://') && str_contains($url, '/payment-request.php?t='), 'customer link must be signed HTTPS URL');
public_message_assert(str_starts_with($url, 'https://dakshayani.co.in/payment-request.php?t='), 'customer link must use the canonical public origin');
public_message_assert(str_contains($message, $url), 'message must automatically contain the public Payment Request URL');
public_message_assert(!str_contains($message, 'admin-documents.php') && !str_contains($message, 'upi://'), 'message must not send an administrator URL or raw UPI URI');
public_message_assert(documents_payment_request_public_url(array_merge($request, ['visibility_to_customer'=>false])) === '', 'internal-only requests must have no public URL');
foreach (['amount_requested', 'customer_mobile', 'status'] as $field) {
    public_message_assert(!str_contains($url, rawurlencode((string)$request[$field])), "$field must not be embedded in the URL");
}

$html = documents_render_public_payment_request(array_merge($request, [
    'amount_paid_against_request'=>25000, 'outstanding_against_request'=>25000,
    'internal_notes'=>'PRIVATE NOTE', 'customer_response'=>'PRIVATE RESPONSE',
]), ['company_name'=>'Dakshayani Enterprises', 'upi_id'=>'pay@example', 'bank_account_name'=>'Dakshayani Enterprises', 'bank_name'=>'Example Bank', 'bank_account_no'=>'1234', 'bank_ifsc'=>'EXAM0001']);
foreach (['Test Customer', '₹50,000.00', 'QUO-878', 'Total Project Amount', 'Paid So Far', 'Outstanding', 'Print Payment Request', 'Not a receipt', 'Bank transfer', 'Copy'] as $text) {
    public_message_assert(str_contains($html, $text), "public document should show $text");
}
public_message_assert(!str_contains($html, 'PRIVATE NOTE') && !str_contains($html, 'PRIVATE RESPONSE'), 'internal fields must not render');
foreach (['cancelled', 'paid', 'archived'] as $status) {
    $inactive = array_merge($request, ['status'=>$status, 'archived_flag'=>$status === 'archived']);
    $inactiveHtml = documents_render_public_payment_request($inactive, ['company_name'=>'Dakshayani Enterprises', 'upi_id'=>'pay@example']);
    public_message_assert(str_contains($inactiveHtml, 'Online payment is not available') && !str_contains($inactiveHtml, 'upi://'), "$status request must expose no payment action");
}

$helperSource = file_get_contents($root . '/admin/includes/documents_helpers.php');
$pageSource = file_get_contents($root . '/payment-request.php');
$adminSource = file_get_contents($root . '/admin-documents.php');
public_message_assert(str_contains($adminSource, '>Open public link<') && str_contains($adminSource, '>Copy link<'), 'accepted-customer actions must offer the clarified public-link controls');
public_message_assert(str_contains($adminSource, 'data-copy-payment-request-url') && !str_contains($adminSource, 'onclick="navigator.clipboard.writeText(<?= htmlspecialchars(json_encode($publicPayUrl)'), 'copy control must use delegated, no-refresh browser behavior');
public_message_assert(str_contains($adminSource, "status.textContent = 'Copied'") && str_contains($adminSource, "status.setAttribute('role', 'alert')"), 'copy must provide accessible success and failure feedback');
public_message_assert(!str_contains($adminSource, "fetch(value") && str_contains($adminSource, 'event.preventDefault()'), 'clipboard behavior must not use AJAX or navigate/refresh');
public_message_assert(substr_count($message, $url) === 1, 'shared message must contain the exact stable public URL once for WhatsApp and email');
public_message_assert(str_contains(rawurldecode(documents_payment_request_whatsapp_url($request, $message)), $url), 'WhatsApp must carry the shared public URL');
public_message_assert(str_contains(rawurldecode(documents_payment_request_mailto($request, $message)), $url), 'email must carry the shared public URL');
public_message_assert(!str_contains(substr($helperSource, strpos($helperSource, 'function documents_payment_request_portal_guidance'), strpos($helperSource, 'function documents_build_payment_request_message') - strpos($helperSource, 'function documents_payment_request_portal_guidance')), 'password_hash'), 'portal guidance must never inspect password hashes');
public_message_assert(str_contains($pageSource, 'documents_payment_request_refresh_from_receipts') && !str_contains($pageSource, 'documents_save_payment_request'), 'public view may refresh in memory but must never save');

echo "payment request public message link tests passed\n";
