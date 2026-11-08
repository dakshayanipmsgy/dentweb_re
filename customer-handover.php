<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer_portal.php';
require_once __DIR__ . '/includes/handover.php';
require_once __DIR__ . '/includes/customer_workspace_view.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
customer_portal_require_login();
$customer = customer_portal_fetch_customer(new CustomerFsStore());
if ($customer === null) { customer_portal_logout(); header('Location: customer-login.php'); exit; }
// Resolve only the document assigned to the authenticated customer. No path or mobile from GET.
$path = trim((string) ($customer['handover_html_path'] ?? $customer['handover_document_path'] ?? ''));
$root = realpath(handover_storage_directory());
$file = $path !== '' ? realpath(__DIR__ . '/' . ltrim($path, '/')) : false;
if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file) || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'html') {
    http_response_code(404);
    exit('Your handover pack is not available yet. Please contact our team.');
}
$html = file_get_contents($file);
if ($html === false) { http_response_code(404); exit('Handover pack unavailable.'); }
// Resolve only this customer's current accepted project documents. Never persist on view.
$mobile = normalize_customer_mobile((string) ($customer['mobile'] ?? ''));
$projects = [];
foreach (documents_list_quotes() as $quote) {
    if ($mobile === '' || normalize_customer_mobile((string) ($quote['customer_mobile'] ?? '')) !== $mobile
        || documents_quote_normalize_status((string) ($quote['status'] ?? 'draft')) !== 'accepted'
        || documents_is_archived($quote) || empty($quote['is_current_version'])) continue;
    $projects[] = ['quote' => $quote, 'invoices' => documents_customer_visible_invoices_for_quote((string) ($quote['id'] ?? ''), $quote)];
}
$references = customer_workspace_references_html(customer_workspace_project_references($projects, documents_standalone_invoices_for_customer($customer)));
$notice = '<section class="saved-handover-notice"><h2>Saved handover pack</h2><p>The pack below preserves your saved terms and customer-specific content. Missing fields are marked explicitly. Current invoice references above are supplied separately; please request a correction for missing installation details or warranty periods.</p></section>';
$body = customer_workspace_handover_missing_details(handover_extract_body_content($html));
echo handover_wrap_document($customer, '<div class="handover-shell handover-reference-shell">' . $references . $notice . '</div>' . $body);
