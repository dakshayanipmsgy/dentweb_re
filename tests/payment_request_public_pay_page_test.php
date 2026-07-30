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
    public_pay_assert(str_starts_with($url, 'https://payments.example.test/payment-request.php?t='), 'payment URL must always use HTTPS');
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

    $message = documents_build_payment_request_message($request);
    public_pay_assert(str_contains($message, $url), 'message must make the HTTPS page the primary action');
    public_pay_assert(!str_contains($message, 'upi://'), 'message must not contain a raw UPI URI');
    public_pay_assert(!str_contains($message, 'Never expose this note'), 'internal notes must never enter customer messages');

    $page = file_get_contents($root . '/payment-request.php');
    public_pay_assert(str_contains($page, 'noindex,nofollow') && str_contains($page, 'no-store'), 'public page must prevent indexing and caching');
    public_pay_assert(str_contains($page, "documents_payment_request_refresh_from_receipts") && !str_contains($page, 'documents_save_payment_request'), 'public page must refresh from receipts without mutation');
    public_pay_assert(!str_contains($page, "internal_notes"), 'public page must not render internal notes');
    public_pay_assert(str_contains($page, "['uri']") && str_contains($page, 'data-copy'), 'public page must retain exact UPI deep link and copy controls');
    public_pay_assert(file_get_contents($profile) === $profileBefore, 'Company Profile must remain unchanged');
} finally {
    if ($storeBefore === null) { @unlink($store); } else { file_put_contents($store, $storeBefore, LOCK_EX); }
    if ($oldSecret === false) { putenv('PAYMENT_REQUEST_TOKEN_SECRET'); } else { putenv('PAYMENT_REQUEST_TOKEN_SECRET=' . $oldSecret); }
}

echo "Payment request public pay page tests passed.\n";
