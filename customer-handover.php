<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer_portal.php';
require_once __DIR__ . '/includes/handover.php';
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
// Keep the saved, approved content, including customer-specific edits. Never regenerate on view.
echo handover_wrap_document($customer, handover_extract_body_content($html));
