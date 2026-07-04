<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/customer_portal.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/customer_document_acceptance.php';
require_once __DIR__ . '/includes/dispatch_advice_view_renderer.php';

$customerView = (string) ($_GET['customer_view'] ?? '') === '1';
$customer = null;
if ($customerView) {
    $store = new CustomerFsStore();
    customer_portal_require_login();
    $customer = customer_portal_fetch_customer($store);
    if ($customer === null) {
        customer_portal_logout();
        header('Location: customer-login.php');
        exit;
    }
} else {
    require_admin();
}

$d = documents_get_dispatch_advice((string) ($_GET['id'] ?? ''));
if (!$d) {
    http_response_code(404);
    exit('Not found');
}

if ($customerView) {
    $customerMobile = normalize_customer_mobile((string) ($customer['mobile'] ?? ''));
    $documentMobile = normalize_customer_mobile((string) ($d['customer_mobile'] ?? $d['customer_snapshot']['mobile'] ?? ''));
    if ($documentMobile === '' && (string) ($d['quotation_id'] ?? $d['linked_quote_id'] ?? '') !== '') {
        $quote = documents_get_quote((string) ($d['quotation_id'] ?? $d['linked_quote_id'] ?? ''));
        if (is_array($quote)) {
            $documentMobile = normalize_customer_mobile((string) ($quote['customer_mobile'] ?? $quote['customer_snapshot']['mobile'] ?? ''));
        }
    }
    if ($customerMobile === '' || $documentMobile !== $customerMobile) {
        http_response_code(403);
        exit('Access denied.');
    }
}

$company = load_company_profile();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Material Dispatch Advice</title>
<style>body{font:14px Arial;max-width:210mm;margin:20px auto;color:#172033}.document{border:1px solid #ccd5e1;padding:14mm}.document-header{display:flex;justify-content:space-between;border-bottom:3px solid #087b61}.document-title{text-align:right}.company-logo{max-width:140px;max-height:70px}.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:15px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccd5e1;padding:8px;text-align:left}.disclaimer{background:#fff7ed;padding:12px}footer{text-align:right;margin-top:45px;font-weight:bold}@media print{@page{size:A4;margin:10mm}.actions{display:none}body{margin:0}.document{border:0;padding:0}}</style>
</head>
<body>
<p class="actions"><a href="<?= $customerView ? 'customer-dashboard.php' : 'admin-dispatch-advices.php' ?>"><?= $customerView ? 'Back to dashboard' : 'Back' ?></a> · <button onclick="print()">Print HTML</button></p>
<?php render_dispatch_advice($d, $company); ?>
</body>
</html>
