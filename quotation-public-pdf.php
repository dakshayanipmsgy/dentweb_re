<?php
declare(strict_types=1);

require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/quotation_view_renderer.php';
require_once __DIR__ . '/includes/handover.php';

ini_set('display_errors', '0');
documents_ensure_structure();

$token = safe_text((string) ($_GET['t'] ?? ''));
$quote = $token !== '' ? documents_get_quote_by_public_share_token($token) : null;

$isValid = $quote !== null
    && !empty($quote['public_share_enabled'])
    && (string) ($quote['public_share_token'] ?? '') !== '';

$expiresAt = safe_text((string) ($quote['public_share_expires_at'] ?? ''));
if ($isValid && $expiresAt !== '') {
    $expiresAtTs = strtotime($expiresAt);
    if ($expiresAtTs !== false && $expiresAtTs < time()) {
        $isValid = false;
    }
}

if (!$isValid || !is_array($quote)) {
    http_response_code(404);
    echo 'Quotation link not available.';
    exit;
}

$quoteDefaults = load_quote_defaults();
$company = documents_get_company_profile_for_quotes();
$shareUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://')
    . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . '/quotation-public.php?t=' . urlencode((string) ($quote['public_share_token'] ?? ''));

ob_start();
quotation_render($quote, $quoteDefaults, $company, false, $shareUrl, 'public', '');
$html = (string) ob_get_clean();

$tmpDir = __DIR__ . '/storage/cache/quote-pdf';
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0775, true);
}
$tmpPath = $tmpDir . '/quote-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) ($quote['id'] ?? 'quote')) . '-' . bin2hex(random_bytes(4)) . '.pdf';
$ok = handover_generate_pdf($html, $tmpPath);
if (!$ok || !is_file($tmpPath)) {
    http_response_code(500);
    echo 'Unable to generate quotation PDF.';
    exit;
}

$customerSlug = trim((string) ($quote['customer_name'] ?? ''));
$customerSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($customerSlug)) ?? 'customer';
$customerSlug = trim($customerSlug, '-') ?: 'customer';
$quoteId = preg_replace('/[^a-zA-Z0-9_-]+/', '', (string) ($quote['id'] ?? ''));
$filename = 'dakshayani-quotation-' . $customerSlug . '-' . ($quoteId !== '' ? $quoteId : date('YmdHis')) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) filesize($tmpPath));
readfile($tmpPath);
@unlink($tmpPath);
exit;
