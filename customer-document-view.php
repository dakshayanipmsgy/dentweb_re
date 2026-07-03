<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/customer_portal.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/quotation_view_renderer.php';

$store = new CustomerFsStore();
customer_portal_require_login();
$customer = customer_portal_fetch_customer($store);
if ($customer === null) { customer_portal_logout(); header('Location: customer-login.php'); exit; }

$type = safe_text((string) ($_GET['type'] ?? ''));
$id = safe_text((string) ($_GET['id'] ?? ''));
$customerMobile = normalize_customer_mobile((string) ($customer['mobile'] ?? ''));

$document = null;
$title = 'Document';
$number = '';
$rows = [];
$amount = null;
$date = '';

if ($type === 'quotation') {
    $document = documents_get_quote($id);
    $title = 'Accepted Quotation';
    $number = (string) ($document['quote_no'] ?? $document['id'] ?? '');
    $date = substr((string) ($document['accepted_at'] ?? $document['quotation_date'] ?? $document['created_at'] ?? ''), 0, 10);
    $amount = (float) ($document['calc']['gross_payable'] ?? $document['calc']['final_price_incl_gst'] ?? $document['calc']['grand_total'] ?? 0);
    $rows = is_array($document['items'] ?? null) ? $document['items'] : [];
} elseif ($type === 'agreement') {
    $document = documents_get_agreement($id);
    $title = 'Agreement';
    $number = (string) ($document['agreement_no'] ?? $document['id'] ?? '');
    $date = (string) ($document['execution_date'] ?? $document['created_at'] ?? '');
    $amount = is_numeric($document['total_cost'] ?? null) ? (float) $document['total_cost'] : null;
} elseif ($type === 'dispatch_advice') {
    $document = documents_get_dispatch_advice($id);
    $title = 'Dispatch Advice';
    $number = (string) ($document['dispatch_advice_no'] ?? $document['id'] ?? '');
    $date = (string) ($document['planned_dispatch_date'] ?? $document['created_at'] ?? '');
    $rows = is_array($document['items'] ?? null) ? $document['items'] : [];
} elseif ($type === 'challan') {
    $document = documents_get_challan($id);
    $title = 'Delivery Challan';
    $number = (string) ($document['challan_no'] ?? $document['dc_number'] ?? $document['id'] ?? '');
    $date = (string) ($document['delivery_date'] ?? $document['created_at'] ?? '');
    $rows = documents_challan_customer_items($document ?? []);
} elseif ($type === 'invoice') {
    $document = documents_get_invoice($id);
    $title = 'Invoice';
    $number = (string) ($document['invoice_no'] ?? $document['id'] ?? '');
    $date = (string) ($document['invoice_date'] ?? $document['created_at'] ?? '');
    $amount = (float) ($document['input_total_gst_inclusive'] ?? $document['calc']['grand_total'] ?? $document['calc']['gross_payable'] ?? 0);
    $rows = is_array($document['commercial_items'] ?? null) ? $document['commercial_items'] : [];
} elseif ($type === 'receipt') {
    $document = documents_get_sales_document('receipt', $id);
    $title = 'Payment Receipt';
    $number = (string) ($document['receipt_number'] ?? $document['id'] ?? '');
    $date = (string) ($document['date_received'] ?? $document['receipt_date'] ?? $document['created_at'] ?? '');
    $amount = (float) ($document['amount_rs'] ?? $document['amount_received'] ?? $document['amount'] ?? 0);
}

if (!is_array($document)) { http_response_code(404); exit('Document not found.'); }
$docMobile = normalize_customer_mobile((string) ($document['customer_mobile'] ?? $document['customer_snapshot']['mobile'] ?? $document['party_snapshot']['customer_mobile'] ?? ''));
if ($docMobile === '' && isset($document['quotation_id'])) {
    $quote = documents_get_quote((string) ($document['quotation_id'] ?? $document['linked_quote_id'] ?? ''));
    $docMobile = normalize_customer_mobile((string) ($quote['customer_mobile'] ?? $quote['customer_snapshot']['mobile'] ?? ''));
}
if ($customerMobile === '' || $docMobile !== $customerMobile) { http_response_code(403); exit('Access denied.'); }
$esc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$fmt = static fn($v): string => '₹' . number_format((float) $v, 2);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= $esc($title . ' ' . $number) ?></title><style>body{margin:0;background:#f8fafc;color:#0f172a;font:15px/1.55 Arial,sans-serif}.sheet{max-width:920px;margin:24px auto;background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:28px;box-shadow:0 20px 60px rgba(15,23,42,.08)}.top{display:flex;justify-content:space-between;gap:18px;border-bottom:1px solid #e2e8f0;padding-bottom:18px}h1{margin:0;font-size:32px}.muted{color:#64748b}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin:18px 0}.card{border:1px solid #e2e8f0;border-radius:14px;padding:14px;background:#fbfdff}.label{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;font-weight:800}.value{font-weight:800;margin-top:4px}table{width:100%;border-collapse:collapse;margin-top:16px}th,td{border-bottom:1px solid #e5e7eb;padding:10px;text-align:left}th{font-size:12px;text-transform:uppercase;color:#64748b}.actions{margin-bottom:14px}.btn{display:inline-block;background:#2563eb;color:#fff;padding:10px 14px;border-radius:10px;text-decoration:none;font-weight:800}@media print{.actions{display:none}.sheet{box-shadow:none;border:0;margin:0;border-radius:0}}@media(max-width:640px){.top,.grid{display:block}.card{margin-top:10px}}</style></head><body><main class="sheet"><div class="actions"><a class="btn" href="customer-dashboard.php">Back to dashboard</a> <a class="btn" href="#" onclick="window.print();return false;">Print</a></div><header class="top"><div><h1><?= $esc($title) ?></h1><p class="muted"><?= $esc($number) ?></p></div><div><strong><?= $esc($date ?: '—') ?></strong></div></header><section class="grid"><div class="card"><div class="label">Customer</div><div class="value"><?= $esc($customer['name'] ?? ($document['customer_name'] ?? 'Customer')) ?></div></div><div class="card"><div class="label">Registered mobile</div><div class="value"><?= $esc($customer['mobile'] ?? '') ?></div></div><?php if ($amount !== null): ?><div class="card"><div class="label">Amount</div><div class="value"><?= $esc($fmt($amount)) ?></div></div><?php endif; ?><div class="card"><div class="label">Status</div><div class="value"><?= $esc(ucwords(str_replace('_', ' ', (string) ($document['status'] ?? 'Available')))) ?></div></div></section><?php if ($type === 'agreement'): ?><section class="card"><?= quotation_sanitize_html(documents_render_agreement_body_html($document, load_company_profile())) ?></section><?php elseif ($rows !== []): ?><table><thead><tr><th>#</th><th>Item</th><th>Qty</th><th>Unit</th></tr></thead><tbody><?php foreach ($rows as $i => $row): ?><tr><td><?= (int) $i + 1 ?></td><td><?= $esc($row['name'] ?? $row['description'] ?? $row['item'] ?? 'Item') ?></td><td><?= $esc($row['qty'] ?? $row['quantity'] ?? '') ?></td><td><?= $esc($row['unit'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><p class="muted">Detailed line items are not available for this document.</p><?php endif; ?></main></body></html>
