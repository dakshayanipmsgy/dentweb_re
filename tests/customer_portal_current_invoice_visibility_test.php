<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$assertions = 0;
function customerInvoiceOk(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
function customerInvoiceIds(array $invoices): array
{
    return array_map(static fn(array $invoice): string => (string)($invoice['id'] ?? ''), $invoices);
}

$suffix = bin2hex(random_bytes(5));
$quoteId = 'customer_invoice_visibility_' . $suffix;
$mobile = '9876543210';
$base = [
    'linked_quote_id' => $quoteId,
    'quotation_id' => $quoteId,
    'customer_mobile' => $mobile,
    'customer_snapshot' => ['mobile' => $mobile],
    'status' => 'finalized',
];
$fixtures = [
    'archived' => array_merge($base, ['id' => 'archived_' . $suffix, 'created_at' => '2025-01-01T00:00:00+00:00', 'archived_flag' => true]),
    'cancelled' => array_merge($base, ['id' => 'cancelled_' . $suffix, 'created_at' => '2025-02-01T00:00:00+00:00', 'status' => 'cancelled']),
    'superseded' => array_merge($base, ['id' => 'superseded_' . $suffix, 'created_at' => '2025-03-01T00:00:00+00:00', 'superseded_by_invoice_id' => 'replacement_' . $suffix]),
    'replacement' => array_merge($base, ['id' => 'replacement_' . $suffix, 'created_at' => '2025-04-01T00:00:00+00:00']),
    'newest' => array_merge($base, ['id' => 'newest_' . $suffix, 'created_at' => '2025-05-01T00:00:00+00:00']),
];
$quote = [
    'id' => $quoteId,
    'quote_no' => 'VIS-' . $suffix,
    'customer_mobile' => $mobile,
    'workflow' => ['latest_invoice_id' => $fixtures['replacement']['id'], 'invoice_id' => $fixtures['newest']['id']],
];

documents_ensure_structure();
try {
    documents_save_quote($quote);
    foreach ($fixtures as $fixture) { documents_save_invoice(array_merge(documents_invoice_defaults(), $fixture)); }

    $visible = documents_customer_visible_invoices_for_quote($quoteId, $quote);
    $ids = customerInvoiceIds($visible);
    customerInvoiceOk($ids === [$fixtures['replacement']['id'], $fixtures['newest']['id']], 'archived, cancelled and superseded history is excluded while multiple active invoices remain');
    customerInvoiceOk($ids[0] === $fixtures['replacement']['id'], 'active workflow.latest_invoice_id is canonical even when another invoice is newer');

    $staleQuote = $quote;
    $staleQuote['workflow'] = ['latest_invoice_id' => $fixtures['cancelled']['id'], 'invoice_id' => 'missing_' . $suffix];
    customerInvoiceOk(customerInvoiceIds(documents_customer_visible_invoices_for_quote($quoteId, $staleQuote)) === [$fixtures['newest']['id'], $fixtures['replacement']['id']], 'stale workflow references fall back to deterministic newest-first order');

    $customerDocumentView = file_get_contents(__DIR__ . '/../customer-document-view.php');
    $invoiceView = file_get_contents(__DIR__ . '/../invoice-view.php');
    $dashboard = file_get_contents(__DIR__ . '/../customer-dashboard.php');
    customerInvoiceOk(str_contains($customerDocumentView, 'documents_customer_visible_invoices_for_quote') && str_contains($customerDocumentView, "exit('Invoice unavailable.')"), 'customer document URL blocks historical invoices');
    customerInvoiceOk(str_contains($invoiceView, 'documents_customer_visible_invoices_for_quote') && str_contains($invoiceView, 'http_response_code(404)'), 'direct customer invoice view blocks historical invoices');
    customerInvoiceOk(str_contains($invoiceView, 'if ($customerView)') && str_contains($invoiceView, 'require_admin();'), 'non-customer invoice view retains unchanged admin authentication path');
    customerInvoiceOk(str_contains($dashboard, 'documents_customer_visible_invoices_for_quote') && str_contains($dashboard, 'View All Active Invoices'), 'dashboard uses canonical helper and offers a multiple-active-invoice list');

    $beforeHandover = customerInvoiceIds(documents_customer_visible_invoices_for_quote($quoteId, $quote));
    $quote['workflow']['handover_id'] = 'handover_' . $suffix;
    $quote['handover_html_path'] = 'data/handovers/' . $suffix . '.html';
    customerInvoiceOk(customerInvoiceIds(documents_customer_visible_invoices_for_quote($quoteId, $quote)) === $beforeHandover, 'handover workflow data does not affect invoice visibility');
} finally {
    foreach ($fixtures as $fixture) { @unlink(documents_invoices_dir() . '/' . safe_filename((string)$fixture['id']) . '.json'); }
    @unlink(documents_quotations_dir() . '/' . safe_filename($quoteId) . '.json');
}

echo "customer_portal_current_invoice_visibility_test passed ({$assertions} assertions)\n";
