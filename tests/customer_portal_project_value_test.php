<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$assertions = 0;
function projectValueOk(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
function projectValueMoney($actual, $expected, string $message): void
{
    projectValueOk(abs((float) $actual - (float) $expected) < 0.01, $message . " (got {$actual}, expected {$expected})");
}

$suffix = bin2hex(random_bytes(5));
$mobile = '9898989898';
$quoteOne = [
    'id' => 'portal_value_one_' . $suffix,
    'quote_no' => 'PV-ONE-' . $suffix,
    'status' => 'accepted',
    'is_current_version' => true,
    'segment' => 'RES',
    'customer_name' => 'Portal Value Customer',
    'customer_mobile' => $mobile,
    'accepted_at' => '2026-07-27T09:00:00+00:00',
    'created_at' => '2026-07-27T08:00:00+00:00',
    'input_total_gst_inclusive' => 125000,
    'calc' => ['gross_payable' => 125000, 'final_price_incl_gst' => 125000, 'grand_total' => 125000],
    'items' => [['name' => 'Project one', 'qty' => 1, 'price' => 125000]],
];
$quoteTwo = [
    'id' => 'portal_value_two_' . $suffix,
    'quote_no' => 'PV-TWO-' . $suffix,
    'status' => 'accepted',
    'is_current_version' => true,
    'segment' => 'RES',
    'customer_name' => 'Portal Value Customer',
    'customer_mobile' => $mobile,
    'accepted_at' => '2026-07-27T10:00:00+00:00',
    'created_at' => '2026-07-27T09:00:00+00:00',
    'input_total_gst_inclusive' => 275000,
    'calc' => ['gross_payable' => 275000, 'final_price_incl_gst' => 275000, 'grand_total' => 275000],
    'items' => [['name' => 'Project two', 'qty' => 1, 'price' => 275000]],
];

documents_ensure_structure();
$receiptPath = documents_sales_receipts_store_path();
$receiptBackup = is_file($receiptPath) ? file_get_contents($receiptPath) : null;
$invoiceId = '';
try {
    documents_save_quote($quoteOne);
    documents_save_quote($quoteTwo);

    projectValueMoney(documents_project_quotation_amount($quoteOne), 125000, 'one-project value is its canonical quotation total');
    $individualValues = array_map('documents_project_quotation_amount', [$quoteOne, $quoteTwo]);
    projectValueMoney($individualValues[0], 125000, 'first of two projects retains its individual value');
    projectValueMoney($individualValues[1], 275000, 'second of two projects retains its individual value');
    projectValueMoney(array_sum($individualValues), 400000, 'combined KPI total sums the two canonical quotation values');
    projectValueOk($quoteOne['customer_mobile'] === $quoteTwo['customer_mobile'] && $individualValues === [125000.0, 275000.0], 'quotations sharing a mobile remain independent projects');

    $created = documents_create_invoice_from_quote(documents_get_quote($quoteOne['id']), ['idempotency_key' => 'portal-value-' . $suffix]);
    projectValueOk(!empty($created['ok']), 'invoice fixture created: ' . json_encode($created['errors'] ?? $created));
    $invoiceId = (string) $created['invoice_id'];
    $invoice = documents_get_invoice($invoiceId);
    projectValueOk(is_array($invoice), 'invoice fixture loaded');
    $invoice = documents_invoice_recalculate_pricing($invoice, 110000, 'Confirmed invoice basis differs from quotation')['invoice'];
    $invoice = documents_invoice_finalize($invoice, ['id' => 'test', 'name' => 'Test'])['invoice'];
    documents_save_invoice($invoice);
    json_save($receiptPath, [[
        'id' => 'portal_value_receipt_' . $suffix,
        'status' => 'final',
        'quotation_id' => $quoteOne['id'],
        'amount_rs' => 30000,
        'allocations' => [['invoice_id' => $invoiceId, 'amount_rs' => 30000]],
        'date_received' => '2026-07-27',
    ]]);

    $storedQuote = documents_get_quote($quoteOne['id']);
    $financial = documents_project_financial_summary($storedQuote);
    $confirmed = documents_project_confirm_calculation_basis($storedQuote, 'finalized_invoices', ['id' => 'test', 'name' => 'Test'], 'Test differing basis', (string) $financial['active_finalized_invoice_set_hash']);
    projectValueOk(!empty($confirmed['ok']), 'invoice calculation basis confirmed');
    $payment = documents_payment_summary_for_quote($confirmed['quote']);
    projectValueMoney($payment['quotation_amount'], 110000, 'payment summary retains confirmed invoice calculation basis');
    projectValueMoney(documents_project_quotation_amount($confirmed['quote']), 125000, 'project value remains the quotation amount when invoice basis differs');
    projectValueMoney(documents_invoice_final_total($invoice), 110000, 'invoice value is unchanged');
    projectValueMoney($payment['total_received'], 30000, 'paid value is unchanged');
    projectValueMoney($payment['outstanding'], 80000, 'outstanding value is unchanged');

    $dashboard = file_get_contents(__DIR__ . '/../customer-dashboard.php');
    projectValueOk(is_string($dashboard), 'customer dashboard source loaded');
    projectValueOk(str_contains($dashboard, "'quotation_value' => documents_project_quotation_amount(\$quote)"), 'each customer project stores a separate canonical quotation value');
    projectValueOk(str_contains($dashboard, "\$p['quotation_value'] ?? 0") && str_contains($dashboard, "\$project['quotation_value']"), 'portfolio and project cards use quotation_value');
    projectValueOk(str_contains($dashboard, "'Total value of all projects' : 'Project value'"), 'combined total is correctly labelled while one project keeps Project value');
    projectValueOk(str_contains($dashboard, "'Current invoice totals' : 'Current invoice total'") && str_contains($dashboard, "'Total paid across projects' : 'Paid amount'") && str_contains($dashboard, "'Total outstanding across projects' : 'Outstanding'"), 'multi-project invoice, paid, and outstanding labels are portfolio-level');
    projectValueOk(str_contains($dashboard, '!documents_is_archived($quote)') && str_contains($dashboard, "!empty(\$quote['is_current_version'])"), 'archived and non-current quotations are excluded');
} finally {
    if ($invoiceId !== '') {
        @unlink(documents_invoices_dir() . '/' . safe_filename($invoiceId) . '.json');
    }
    @unlink(documents_quotations_dir() . '/' . safe_filename($quoteOne['id']) . '.json');
    @unlink(documents_quotations_dir() . '/' . safe_filename($quoteTwo['id']) . '.json');
    if ($receiptBackup === null) {
        @unlink($receiptPath);
    } else {
        file_put_contents($receiptPath, $receiptBackup);
    }
}

echo "customer_portal_project_value_test passed ({$assertions} assertions)\n";
