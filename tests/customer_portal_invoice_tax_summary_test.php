<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$assertions = 0;
function portalTaxOk(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
function portalTaxMoney($actual, $expected, string $message): void
{
    portalTaxOk(documents_invoice_money_to_paise((float) $actual) === documents_invoice_money_to_paise((float) $expected), $message . " (got {$actual}, expected {$expected})");
}

$invoice = static fn(string $id, string $status, float $gross, float $taxable, float $gst, array $extra = []): array => array_replace_recursive([
    'id' => $id,
    'status' => $status,
    'linked_quote_id' => 'portal-tax-861',
    'pricing' => ['final_invoice_total_incl_gst' => $gross],
    'calc' => ['gross_payable' => $gross, 'tax_breakdown' => ['basic_total' => 1, 'gst_total' => $gross - 1]],
    'tax_breakdown' => ['gross_incl_gst' => $gross, 'basic_total' => $taxable, 'gst_total' => $gst],
], $extra);

$discount = $invoice('discount', 'finalized', 258890, 237731.86, 21158.14);
$discountTax = documents_invoice_tax_summary($discount);
portalTaxMoney($discountTax['taxable'], 237731.86, 'discount uses issued invoice taxable value');
portalTaxMoney($discountTax['gst'], 21158.14, 'discount uses issued invoice GST');
portalTaxMoney($discountTax['taxable'] + $discountTax['gst'], $discountTax['gross'], 'discount tax totals reconcile in paise');
portalTaxMoney($discountTax['gross'], 258890, 'reported invoice gross is preserved');

$surcharge = $invoice('surcharge', 'finalized', 305000, 272321.43, 32678.57);
$surchargeTax = documents_invoice_tax_summary($surcharge);
portalTaxMoney($surchargeTax['taxable'] + $surchargeTax['gst'], 305000, 'surcharge tax totals reconcile');
portalTaxMoney($surchargeTax['taxable'], 272321.43, 'surcharge taxable value comes from its breakdown');

$legacy = $invoice('legacy', 'finalized', 118, 0, 0);
unset($legacy['tax_breakdown']);
$legacy['calc']['tax_breakdown'] = ['basic_total' => 100, 'gst_total' => 18, 'gross_incl_gst' => 118];
portalTaxMoney(documents_invoice_tax_summary($legacy)['gst'], 18, 'invoice-view calc breakdown fallback is retained');

$rounding = $invoice('rounding', 'finalized', 100, 84.75, 15.24);
$roundingTax = documents_invoice_tax_summary($rounding);
portalTaxMoney($roundingTax['taxable'], 84.75, 'authoritative taxable amount survives a legacy rounding difference');
portalTaxMoney($roundingTax['gst'], 15.25, 'rounding remainder is reconciled to GST in paise');

$active = [$discount, $surcharge];
$summaries = array_map('documents_invoice_tax_summary', $active);
portalTaxMoney(array_sum(array_column($summaries, 'gross')), 563890, 'multiple legitimate finalized invoices sum exactly once');
portalTaxMoney(array_sum(array_column($summaries, 'taxable')) + array_sum(array_column($summaries, 'gst')), 563890, 'aggregate taxable and GST reconcile to aggregate invoice value');

foreach ([
    $invoice('draft', 'draft', 100, 90, 10),
    $invoice('cancelled', 'cancelled', 100, 90, 10),
    $invoice('archived', 'finalized', 100, 90, 10, ['archived_flag' => true]),
    $invoice('superseded', 'finalized', 100, 90, 10, ['superseded_by_invoice_id' => 'replacement']),
    $invoice('replaced', 'finalized', 100, 90, 10, ['replaced_by_invoice_id' => 'replacement']),
] as $excluded) {
    portalTaxOk(!(documents_invoice_is_active_for_quote($excluded) && documents_invoice_is_finalized($excluded)), $excluded['id'] . ' is excluded from issued portal tax totals');
}

$quote = ['input_total_gst_inclusive' => 288890, 'calc' => ['gross_payable' => 288890, 'tax_breakdown' => ['basic_total' => 265036.70, 'gst_total' => 23853.30]]];
portalTaxMoney(documents_project_quotation_amount($quote), 288890, 'project value remains the exact quotation value');
portalTaxOk(!documents_invoice_is_finalized($invoice('only-draft', 'draft', 258890, 237731.86, 21158.14)), 'draft invoice is not treated as issued tax information');

$dashboard = file_get_contents(__DIR__ . '/../customer-dashboard.php');
$invoiceView = file_get_contents(__DIR__ . '/../invoice-view.php');
portalTaxOk(str_contains((string) $dashboard, 'Quotation taxable value') && str_contains((string) $dashboard, 'Quotation GST / tax total'), 'quotation fallback is clearly labelled');
portalTaxOk(str_contains((string) $dashboard, 'documents_invoice_tax_summary($invoice)') && str_contains((string) $invoiceView, 'documents_invoice_tax_summary($invoice)'), 'portal and invoice view use the shared summary helper');
portalTaxOk(str_contains((string) $dashboard, "'quotation_value' => documents_project_quotation_amount(\$quote)") && str_contains((string) $dashboard, "'payment_summary' => \$paymentSummary"), 'project value and payment/outstanding sources are preserved');
portalTaxOk(!str_contains((string) $dashboard, 'documents_save_invoice(') && !str_contains((string) $dashboard, 'documents_invoice_recalculate_pricing('), 'portal tax rendering is read-only');

echo "customer_portal_invoice_tax_summary_test passed ({$assertions} assertions)\n";
