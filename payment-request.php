<?php
declare(strict_types=1);

require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/payment_request_renderer.php';

header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0', true);
header('Pragma: no-cache', true);
header('Expires: 0', true);
header('Referrer-Policy: no-referrer', true);
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'", true);
header('X-Content-Type-Options: nosniff', true);

$token = trim((string) ($_GET['t'] ?? $_POST['t'] ?? ''));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $result = documents_payment_request_activate_upi($token, trim((string)($_POST['nonce'] ?? '')), trim((string)($_POST['idempotency_key'] ?? '')));
    http_response_code($result['ok'] ? 200 : ($result['attempts'] >= 2 ? 429 : 400));
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
    exit;
}

$request = documents_payment_request_from_public_token($token);
if (!is_array($request) || empty($request['visibility_to_customer'])) {
    $request = null;
    http_response_code(404);
} else {
    $request = documents_payment_request_refresh_from_receipts($request);
}
$attempts = $request === null ? 0 : documents_payment_request_upi_attempts($token);
echo documents_render_public_payment_request($request, documents_get_company_profile_for_quotes(), $token, $attempts);
