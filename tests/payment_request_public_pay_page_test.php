<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$store = $root . '/data/documents/payment_requests.json';
$profile = $root . '/data/documents/settings/company_profile.json';
$storeBefore = is_file($store) ? file_get_contents($store) : null;
$profileBefore = file_get_contents($profile);
$oldSecret = getenv('PAYMENT_REQUEST_TOKEN_SECRET');
putenv('PAYMENT_REQUEST_TOKEN_SECRET=test-only-secret-with-at-least-thirty-two-bytes');
$_SERVER['HTTP_HOST'] = 'payments.example.test';
require_once $root . '/admin/includes/documents_helpers.php';
require_once $root . '/includes/payment_request_renderer.php';

function public_pay_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

try {
    $request = array_merge(documents_payment_request_defaults(), [
        'id' => 'PAYREQ-871', 'quotation_id' => 'QUOTE-871',
        'customer_mobile' => '9999999999', 'customer_name' => 'Private Customer',
        'amount_requested' => 12345.67, 'reason' => 'Installation milestone',
        'due_date' => '2026-08-15', 'status' => 'sent',
        'visibility_to_customer' => true, 'created_at' => '2026-07-30T10:00:00+00:00',
        'internal_notes' => 'Never expose this note',
    ]);
    file_put_contents($store, json_encode([$request], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    $token = documents_payment_request_public_token($request);
    public_pay_assert((bool) preg_match('/^[A-Za-z0-9_-]{43}$/D', $token), 'token must be opaque URL-safe HMAC output');
    $url = documents_payment_request_public_url($request);
    public_pay_assert(str_starts_with($url, 'https://dakshayani.co.in/payment-request.php?t='), 'payment URL must use the canonical HTTPS origin');
    foreach (['12345', '9999999999', 'Private', 'QUOTE-871', '@'] as $privateValue) {
        public_pay_assert(!str_contains($url, $privateValue), 'URL must not contain request or payment values');
    }
    public_pay_assert((documents_payment_request_from_public_token($token)['id'] ?? '') === 'PAYREQ-871', 'valid token must resolve the stored request');
    public_pay_assert(documents_payment_request_from_public_token(substr($token, 0, -1) . ($token[-1] === 'A' ? 'B' : 'A')) === null, 'tampered token must be rejected');
    foreach ([
        ['visibility_to_customer' => false], ['status' => 'cancelled'], ['status' => 'paid'],
        ['status' => 'archived'], ['archived_flag' => true],
    ] as $blocked) {
        public_pay_assert(!documents_payment_request_is_publicly_payable(array_merge($request, $blocked)), 'blocked request state must not be payable');
    }
    public_pay_assert(documents_payment_request_public_url(array_merge($request, ['visibility_to_customer' => false])) === '', 'internal-only requests must have no public URL');

    $message = documents_build_payment_request_message($request);
    public_pay_assert(str_contains($message, $url), 'message must make the HTTPS page the primary action');
    public_pay_assert(str_contains($message, "View payment request and pay ₹12,345.67:\n" . $url), 'message must use the required primary-action wording');
    public_pay_assert(!str_contains($message, 'admin-documents.php') && !str_contains($message, 'print_payment_request'), 'message must never contain an admin print URL');
    public_pay_assert(!str_contains($message, 'upi://'), 'message must not contain a raw UPI URI');
    public_pay_assert(!str_contains($message, 'Never expose this note'), 'internal notes must never enter customer messages');

    $pageSource = file_get_contents($root . '/payment-request.php');
    $rendererSource = file_get_contents($root . '/includes/payment_request_renderer.php');
    public_pay_assert(str_contains($pageSource, 'no-store') && str_contains($rendererSource, 'noindex,nofollow'), 'public page must prevent indexing and caching');
    public_pay_assert(!str_contains($pageSource . $rendererSource, 'documents_save_payment_request'), 'public rendering must not save or mutate a payment request');
    public_pay_assert(!str_contains($rendererSource, "['internal_notes']") && !str_contains($rendererSource, "['customer_response']"), 'public renderer must not access internal-only fields');
    $storeAfterLookup = file_get_contents($store);
    $company = array_merge(documents_get_company_profile_for_quotes(), [
        'upi_id' => 'payments@example-bank', 'bank_account_name' => 'Dakshayani Enterprises',
        'bank_name' => 'Example Bank', 'bank_account_no' => '00123456789', 'bank_ifsc' => 'EXAM0001234',
    ]);
    $html = documents_render_public_payment_request($request, $company);
    foreach (['PAYREQ-871', '2026-07-30', 'Private Customer', 'QUOTE-871', 'Total Project Amount', 'Paid So Far', 'Outstanding', '₹12,345.67', 'Installation milestone', '2026-08-15', 'Print Payment Request', 'Not a receipt', 'UPI ID:', 'Bank transfer'] as $expected) {
        public_pay_assert(str_contains($html, $expected), 'public renderer missing: ' . $expected);
    }
    public_pay_assert(str_contains($html, 'upi-launch') && str_contains($html, 'data-copy'), 'public page must retain the UPI action and copy controls');
    public_pay_assert(!str_contains($html, 'Never expose this note'), 'public rendering must exclude internal notes');
    foreach (['paid', 'cancelled', 'archived'] as $status) {
        $inactive = documents_render_public_payment_request(array_merge($request, ['status' => $status]), $company);
        public_pay_assert(!str_contains($inactive, 'class="btn upi-launch"') && str_contains($inactive, 'Online payment is not available'), 'inactive request must expose no active Pay button');
    }
    $unavailable = documents_render_public_payment_request(null, documents_get_company_profile_for_quotes());
    public_pay_assert(str_contains($unavailable, 'Payment request unavailable') && !str_contains($unavailable, 'PAYREQ-871'), 'invalid token page must fail safely');
    public_pay_assert(!str_contains($pageSource, 'auth.php') && !str_contains($pageSource, 'require_admin'), 'public page must not require a login');
    public_pay_assert(file_get_contents($store) === $storeAfterLookup, 'lookup and rendering must not mutate saved requests');
    $adminBefore = shell_exec('git show HEAD:admin-documents.php');
    public_pay_assert(is_string($adminBefore) && hash_equals(hash('sha256', $adminBefore), hash_file('sha256', $root . '/admin-documents.php')), 'admin printing must remain unchanged');
    public_pay_assert(file_get_contents($profile) === $profileBefore, 'Company Profile must remain unchanged');
} finally {
    if ($storeBefore === null) { @unlink($store); } else { file_put_contents($store, $storeBefore, LOCK_EX); }
    if ($oldSecret === false) { putenv('PAYMENT_REQUEST_TOKEN_SECRET'); } else { putenv('PAYMENT_REQUEST_TOKEN_SECRET=' . $oldSecret); }
}

echo "Payment request public pay page tests passed.\n";
