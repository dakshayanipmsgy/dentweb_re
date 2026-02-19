<?php
declare(strict_types=1);

require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/quotation_view_renderer.php';

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
    ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Quotation link not available</title>
<style>body{font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;max-width:460px;text-align:center;box-shadow:0 8px 24px rgba(2,6,23,.08)}h1{margin:0 0 10px;font-size:1.2rem}p{margin:0;color:#475569}</style></head>
<body><div class="card"><h1>Quotation link not available</h1><p>This quotation cannot be viewed right now. Please request a fresh link.</p></div></body></html>
<?php
    exit;
}

$quoteDefaults = load_quote_defaults();
$company = documents_get_company_profile_for_quotes();
$shareUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://')
    . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . '/quotation-public.php?t=' . urlencode((string) ($quote['public_share_token'] ?? ''));

quotation_render($quote, $quoteDefaults, $company, false, $shareUrl, 'public', '');
