<?php
declare(strict_types=1);

require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/payment_request_renderer.php';

header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0', true);
header('Pragma: no-cache', true);
header('Expires: 0', true);
header('Referrer-Policy: no-referrer', true);
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'", true);
header('X-Content-Type-Options: nosniff', true);

$request = documents_payment_request_from_public_token(trim((string) ($_GET['t'] ?? '')));
if (!is_array($request) || empty($request['visibility_to_customer'])) {
    $request = null;
    http_response_code(404);
} else {
    // Calculate the display from authoritative finalized receipts without saving
    // or otherwise mutating the payment request or its balances.
    $request = documents_payment_request_refresh_from_receipts($request);
}

echo documents_render_public_payment_request($request, documents_get_company_profile_for_quotes());
