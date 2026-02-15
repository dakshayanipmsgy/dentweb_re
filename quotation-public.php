<?php
declare(strict_types=1);
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/quotation_view_renderer.php';

ini_set('display_errors', '0');
documents_ensure_structure();
$token = safe_text($_GET['token'] ?? '');
$quote = null;
foreach (documents_list_quotes() as $q) {
    if ((string)($q['share']['public_token'] ?? '') === $token && !empty($q['share']['public_enabled'])) {
        $quote = $q;
        break;
    }
}
if ($token === '' || $quote === null) { http_response_code(404); echo '<h1>Link invalid or expired.</h1>'; exit; }
$quoteDefaults = load_quote_defaults();
$company = documents_get_company_profile_for_quotes();
$shareUrl=((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')?'https://':'http://').($_SERVER['HTTP_HOST'] ?? 'localhost').'/quotation-public.php?token='.urlencode((string)($quote['share']['public_token'] ?? ''));
quotation_render($quote, $quoteDefaults, $company, false, $shareUrl);
