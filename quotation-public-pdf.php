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

if (!$isValid) {
    http_response_code(404);
    exit('Quotation link not available.');
}

$quoteDefaults = load_quote_defaults();
$company = documents_get_company_profile_for_quotes();
$shareUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://')
    . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . '/quotation-public.php?t=' . urlencode((string) ($quote['public_share_token'] ?? ''));

ob_start();
quotation_render($quote, $quoteDefaults, $company, false, $shareUrl, 'public', '');
$html = (string) ob_get_clean();

$pdfDir = documents_quote_pdf_dir();
if (!is_dir($pdfDir)) {
    @mkdir($pdfDir, 0775, true);
}
$tmpFile = $pdfDir . '/public-' . safe_text((string) ($quote['id'] ?? 'quote')) . '-' . bin2hex(random_bytes(4)) . '.pdf';
if (!handover_generate_pdf($html, $tmpFile) || !is_file($tmpFile)) {
    http_response_code(500);
    exit('Unable to generate quotation PDF.');
}

$rawName = trim((string) ($quote['customer_name'] ?? 'quotation'));
$slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $rawName));
$slug = trim($slug, '-');
if ($slug === '') {
    $slug = 'customer';
}
$quoteId = safe_text((string) ($quote['id'] ?? 'quotation'));
$downloadName = 'dakshayani-quotation-' . $slug . '-' . $quoteId . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
header('Content-Length: ' . (string) filesize($tmpFile));
header('Cache-Control: private, max-age=0, no-store, no-cache, must-revalidate');
readfile($tmpFile);
@unlink($tmpFile);
exit;
