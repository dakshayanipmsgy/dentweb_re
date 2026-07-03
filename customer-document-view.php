<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/customer_portal.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/quotation_view_renderer.php';

$store = new CustomerFsStore();
customer_portal_require_login();
$customer = customer_portal_fetch_customer($store);
if ($customer === null) { customer_portal_logout(); header('Location: customer-login.php'); exit; }

function customer_document_quote_mobile(array $document): string
{
    return normalize_customer_mobile((string) ($document['customer_mobile'] ?? $document['customer_snapshot']['mobile'] ?? $document['party_snapshot']['customer_mobile'] ?? ''));
}

function customer_document_quote_tax_summary(array $quote): array
{
    $calc = is_array($quote['calc'] ?? null) ? $quote['calc'] : [];
    $taxBreakdown = is_array($calc['tax_breakdown'] ?? null) ? $calc['tax_breakdown'] : (is_array($quote['tax_breakdown'] ?? null) ? $quote['tax_breakdown'] : []);
    $gross = (float) ($taxBreakdown['gross_incl_gst'] ?? $calc['grand_total'] ?? $calc['final_price_incl_gst'] ?? $quote['input_total_gst_inclusive'] ?? 0);
    $taxable = (float) ($taxBreakdown['basic_total'] ?? $calc['basic_total'] ?? $calc['taxable_total'] ?? $calc['basic_value'] ?? 0);
    $gst = (float) ($taxBreakdown['gst_total'] ?? $calc['gst_total'] ?? $calc['total_gst'] ?? 0);
    if ($gst <= 0 && $gross > 0 && $taxable > 0) {
        $gst = max(0, $gross - $taxable);
    }
    $split = is_array($calc['gst_split'] ?? null) ? $calc['gst_split'] : [];
    $components = [];
    foreach ([
        'CGST' => ['cgst_5', 'cgst_18'],
        'SGST' => ['sgst_5', 'sgst_18'],
        'IGST' => ['igst_5', 'igst_18'],
    ] as $label => $keys) {
        $amount = 0.0;
        foreach ($keys as $key) {
            $amount += (float) ($split[$key] ?? 0);
        }
        if ($amount > 0) {
            $components[$label] = $amount;
        }
    }
    return ['taxable' => $taxable, 'gst' => $gst, 'gross' => $gross, 'components' => $components];
}

function customer_document_assert_owner(array $document, string $customerMobile): void
{
    $docMobile = customer_document_quote_mobile($document);
    if ($docMobile === '' && ((string) ($document['quotation_id'] ?? $document['linked_quote_id'] ?? '')) !== '') {
        $quote = documents_get_quote((string) ($document['quotation_id'] ?? $document['linked_quote_id'] ?? ''));
        if (is_array($quote)) {
            $docMobile = customer_document_quote_mobile($quote);
        }
    }
    if ($customerMobile === '' || $docMobile !== $customerMobile) {
        http_response_code(403);
        exit('Access denied.');
    }
}

$type = safe_text((string) ($_GET['type'] ?? ''));
$id = safe_text((string) ($_GET['id'] ?? ''));
$customerMobile = normalize_customer_mobile((string) ($customer['mobile'] ?? ''));

if ($type === 'quotation' || $type === 'accepted_quotation') {
    $quote = documents_get_quote($id);
    if (!is_array($quote)) {
        http_response_code(404);
        exit('Document not found.');
    }
    customer_document_assert_owner($quote, $customerMobile);

    if ($type === 'quotation') {
        $quoteDefaults = load_quote_defaults();
        $company = documents_get_company_profile_for_quotes();
        $shareUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://')
            . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . '/quotation-public.php?t=' . urlencode((string) ($quote['public_share_token'] ?? ''));
        quotation_render($quote, $quoteDefaults, $company, false, $shareUrl, 'customer', (string) ($customer['id'] ?? $customerMobile));
        exit;
    }
}

$document = null;
$title = 'Document';
$number = '';
$rows = [];
$amount = null;
$date = '';

if ($type === 'accepted_quotation') {
    $document = $quote;
    $title = 'Accepted Quotation Details';
    $number = (string) ($document['quote_no'] ?? $document['id'] ?? '');
    $date = substr((string) ($document['accepted_at'] ?? $document['quotation_date'] ?? $document['created_at'] ?? ''), 0, 10);
    $amount = (float) ($document['calc']['gross_payable'] ?? $document['calc']['final_price_incl_gst'] ?? $document['calc']['grand_total'] ?? 0);
    $rows = is_array($document['quote_items'] ?? null) ? $document['quote_items'] : (is_array($document['items'] ?? null) ? $document['items'] : []);
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
customer_document_assert_owner($document, $customerMobile);
$esc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$fmt = static fn($v): string => quotation_format_inr_indian((float) $v, true);
$calc = is_array($document['calc'] ?? null) ? $document['calc'] : [];
$snapshot = is_array($document['customer_snapshot'] ?? null) ? $document['customer_snapshot'] : [];
$acceptedTaxSummary = $type === 'accepted_quotation' ? customer_document_quote_tax_summary($document) : ['taxable' => 0.0, 'gst' => 0.0, 'gross' => 0.0, 'components' => []];
$acceptedSpecialRequest = $type === 'accepted_quotation' ? trim((string) ($document['special_requests_text'] ?? $document['special_requests_inclusive'] ?? '')) : '';
$acceptedSummary = [
    'Quotation reference' => $number,
    'Customer name' => (string) ($document['customer_name'] ?? $snapshot['name'] ?? $customer['name'] ?? ''),
    'Project / system' => trim((string) ($document['capacity_kwp'] ?? $document['system_capacity_kwp'] ?? '') . ' kWp ' . (string) ($document['system_type'] ?? 'Solar project')),
    'Site address' => (string) ($document['site_address'] ?? $snapshot['address'] ?? $customer['address'] ?? ''),
    'Accepted on' => $date ?: '—',
    'Acceptance reference' => (string) ($document['acceptance_ref'] ?? $document['customer_acceptance']['acceptance_ref'] ?? '—'),
    'Acceptance / project status' => ucwords(str_replace('_', ' ', (string) ($document['status'] ?? 'accepted'))),
    'Accepted amount' => $fmt($amount),
    'Taxable value' => ((float) $acceptedTaxSummary['taxable'] > 0) ? $fmt((float) $acceptedTaxSummary['taxable']) : '—',
    'GST / tax total' => ((float) $acceptedTaxSummary['gst'] > 0) ? $fmt((float) $acceptedTaxSummary['gst']) : '—',
    'Expected subsidy' => isset($calc['subsidy_expected_rs']) ? $fmt((float) $calc['subsidy_expected_rs']) : '—',
    'Net after subsidy' => isset($calc['net_after_subsidy']) ? $fmt((float) $calc['net_after_subsidy']) : '—',
];
if ($type === 'accepted_quotation') {
    foreach ((array) $acceptedTaxSummary['components'] as $label => $value) {
        $acceptedSummary[$label] = $fmt((float) $value);
    }
}
$acceptedSummary = $type === 'accepted_quotation' ? $acceptedSummary : [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= $esc($title . ' ' . $number) ?></title>
<style>body{margin:0;background:#f8fafc;color:#0f172a;font:15px/1.55 Arial,sans-serif}.sheet{max-width:920px;margin:24px auto;background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:28px;box-shadow:0 20px 60px rgba(15,23,42,.08)}.top{display:flex;justify-content:space-between;gap:18px;border-bottom:1px solid #e2e8f0;padding-bottom:18px}h1{margin:0;font-size:32px}.muted{color:#64748b}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin:18px 0}.card{border:1px solid #e2e8f0;border-radius:14px;padding:14px;background:#fbfdff}.label{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;font-weight:800}.value{font-weight:800;margin-top:4px}.section-title{margin:22px 0 8px;font-size:20px}table{width:100%;border-collapse:collapse;margin-top:16px}th,td{border-bottom:1px solid #e5e7eb;padding:10px;text-align:left;vertical-align:top}th{font-size:12px;text-transform:uppercase;color:#64748b}.actions{margin-bottom:14px}.btn{display:inline-block;background:#2563eb;color:#fff;padding:10px 14px;border-radius:10px;text-decoration:none;font-weight:800}@media print{.actions{display:none}.sheet{box-shadow:none;border:0;margin:0;border-radius:0}}@media(max-width:640px){.sheet{margin:0;border-radius:0;padding:18px}.top,.grid{display:block}.card{margin-top:10px}table{display:block;overflow-x:auto;white-space:nowrap}}</style>
</head>
<body><main class="sheet">
<div class="actions"><a class="btn" href="customer-dashboard.php">Back to dashboard</a> <a class="btn" href="#" onclick="window.print();return false;">Print</a></div>
<header class="top"><div><h1><?= $esc($title) ?></h1><p class="muted"><?= $esc($number) ?></p></div><div><strong><?= $esc($date ?: '—') ?></strong></div></header>
<?php if ($type === 'accepted_quotation'): ?>
  <p class="muted">Your accepted project summary and customer-specific details.</p>
  <section class="grid" aria-label="Accepted quotation summary">
    <?php foreach ($acceptedSummary as $label => $value): ?>
      <div class="card"><div class="label"><?= $esc($label) ?></div><div class="value"><?= $esc($value === '' ? '—' : $value) ?></div></div>
    <?php endforeach; ?>
  </section>
  <?php if ($acceptedSpecialRequest !== ''): ?>
    <section class="card" aria-label="Customer special request"><div class="label">Customer Special Request</div><div class="value"><?= quotation_sanitize_html($acceptedSpecialRequest) ?></div></section>
  <?php endif; ?>
  <?php if ($rows !== []): ?>
    <h2 class="section-title">Accepted system / project details</h2>
    <table><thead><tr><th>#</th><th>Item / detail</th><th>Qty</th><th>Unit</th></tr></thead><tbody>
    <?php foreach ($rows as $i => $row): ?>
      <tr><td><?= (int) $i + 1 ?></td><td><?= $esc($row['name_snapshot'] ?? $row['name'] ?? $row['description_snapshot'] ?? $row['description'] ?? $row['item'] ?? 'Project item') ?></td><td><?= $esc($row['qty'] ?? $row['quantity'] ?? '') ?></td><td><?= $esc($row['unit'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
<?php else: ?>
  <section class="grid"><div class="card"><div class="label">Customer</div><div class="value"><?= $esc($customer['name'] ?? ($document['customer_name'] ?? 'Customer')) ?></div></div><div class="card"><div class="label">Registered mobile</div><div class="value"><?= $esc($customer['mobile'] ?? '') ?></div></div><?php if ($amount !== null): ?><div class="card"><div class="label">Amount</div><div class="value"><?= $esc($fmt($amount)) ?></div></div><?php endif; ?><div class="card"><div class="label">Status</div><div class="value"><?= $esc(ucwords(str_replace('_', ' ', (string) ($document['status'] ?? 'Available')))) ?></div></div></section>
  <?php if ($type === 'agreement'): ?><section class="card"><?= quotation_sanitize_html(documents_render_agreement_body_html($document, load_company_profile())) ?></section><?php elseif ($rows !== []): ?><table><thead><tr><th>#</th><th>Item</th><th>Qty</th><th>Unit</th></tr></thead><tbody><?php foreach ($rows as $i => $row): ?><tr><td><?= (int) $i + 1 ?></td><td><?= $esc($row['name'] ?? $row['description'] ?? $row['item'] ?? 'Item') ?></td><td><?= $esc($row['qty'] ?? $row['quantity'] ?? '') ?></td><td><?= $esc($row['unit'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><p class="muted">Detailed line items are not available for this document.</p><?php endif; ?>
<?php endif; ?>
</main></body></html>
