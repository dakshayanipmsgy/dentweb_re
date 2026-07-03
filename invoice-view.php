<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();

$id = safe_text((string) ($_GET['id'] ?? ''));
$invoice = $id !== '' ? documents_get_invoice($id) : null;
$company = documents_get_company_profile_for_quotes();

$esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn($value): string => '₹' . number_format((float) $value, 2);
$first = static function (array $values): string {
    foreach ($values as $value) {
        $text = trim((string) $value);
        if ($text !== '') {
            return $text;
        }
    }
    return '';
};

$quote = $invoice !== null ? documents_get_quote((string) ($invoice['linked_quote_id'] ?? $invoice['quotation_id'] ?? '')) : null;
$quoteSnapshot = $invoice !== null && is_array($invoice['quotation_snapshot'] ?? null) ? $invoice['quotation_snapshot'] : [];
if ($quoteSnapshot === [] && is_array($quote)) {
    $quoteSnapshot = documents_invoice_quote_snapshot($quote);
}
$snapshot = $invoice !== null ? array_merge(documents_customer_snapshot_defaults(), is_array($invoice['customer_snapshot'] ?? null) ? $invoice['customer_snapshot'] : []) : documents_customer_snapshot_defaults();
$items = is_array($quoteSnapshot['item_summary'] ?? null) ? $quoteSnapshot['item_summary'] : [];
if ($items === [] && $invoice !== null && is_array($invoice['commercial_items'] ?? null)) {
    $items = $invoice['commercial_items'];
}
$calc = is_array($quoteSnapshot['calc'] ?? null) && $quoteSnapshot['calc'] !== [] ? $quoteSnapshot['calc'] : ($invoice !== null && is_array($invoice['calc'] ?? null) ? $invoice['calc'] : []);
$taxBreakdown = is_array($quoteSnapshot['tax_breakdown'] ?? null) && $quoteSnapshot['tax_breakdown'] !== [] ? $quoteSnapshot['tax_breakdown'] : (is_array($invoice['tax_breakdown'] ?? null) ? $invoice['tax_breakdown'] : (is_array($calc['tax_breakdown'] ?? null) ? $calc['tax_breakdown'] : []));
$grandTotal = $invoice !== null ? (float) ($quoteSnapshot['input_total_gst_inclusive'] ?? $invoice['input_total_gst_inclusive'] ?? $calc['gross_payable'] ?? $calc['grand_total'] ?? $calc['final_price_incl_gst'] ?? 0) : 0.0;
$invoiceDate = $invoice !== null ? $first([$invoice['invoice_date'] ?? '', substr((string) ($invoice['created_at'] ?? ''), 0, 10)]) : '';
$customerFields = is_array($quoteSnapshot['customer_site_fields'] ?? null) ? $quoteSnapshot['customer_site_fields'] : [];
if ($customerFields === [] && is_array($quote)) {
    $customerFields = documents_quote_invoice_customer_fields($quote);
}
$specialRequests = trim((string) ($quoteSnapshot['special_requests_text'] ?? ($quote['special_requests_text'] ?? $quote['special_requests_inclusive'] ?? '')));
$pricingRows = [];
$addPricingRow = static function (string $label, $value, bool $negative = false, string $note = '') use (&$pricingRows): void {
    if ($value === null || (is_string($value) && trim($value) === '')) { return; }
    $amount = (float) $value;
    if (abs($amount) < 0.005) { return; }
    $pricingRows[] = ['label' => $label, 'amount' => $negative ? -abs($amount) : $amount, 'note' => $note];
};
$systemPrice = (float) ($calc['grand_total'] ?? $calc['final_price_incl_gst'] ?? $taxBreakdown['gross_incl_gst'] ?? $grandTotal);
$addPricingRow('Total system price incl GST', $systemPrice);
$addPricingRow('Transportation', $calc['transportation_rs'] ?? null);
$addPricingRow('Discount', $calc['discount_rs'] ?? null, true, (string) ($calc['discount_note'] ?? ''));
$addPricingRow('Gross payable', $calc['gross_payable'] ?? null);
$addPricingRow('Subsidy expected', $calc['subsidy_expected_rs'] ?? null);
$addPricingRow('Net Investment/Cost After Subsidy Credit', $calc['net_after_subsidy'] ?? null);
if ($pricingRows === []) {
    $addPricingRow('Grand total', $grandTotal);
}
$hsnFallbacks = array_values(array_filter(array_map(static fn($row): string => trim((string) ($row['hsn'] ?? $row['hsn_snapshot'] ?? '')), $items)));
$taxItems = is_array($taxBreakdown['items'] ?? null) ? array_values(array_filter((array) $taxBreakdown['items'], static fn($row): bool => is_array($row))) : [];
foreach ($taxItems as $idx => &$taxItem) {
    if (trim((string) ($taxItem['hsn'] ?? '')) === '' && isset($hsnFallbacks[$idx])) {
        $taxItem['hsn'] = $hsnFallbacks[$idx];
    }
}
unset($taxItem);
$taxType = strtoupper((string) ($invoice['tax_type'] ?? $quote['tax_type'] ?? 'CGST_SGST'));
$taxParts = static function (array $taxItem) use ($taxType): array {
    $gst = (float) ($taxItem['gst_amount'] ?? 0);
    if ($taxType === 'IGST') { return ['cgst' => 0.0, 'sgst' => 0.0, 'igst' => $gst]; }
    return ['cgst' => $gst / 2, 'sgst' => $gst / 2, 'igst' => 0.0];
};
$rateText = static function (array $taxItem): string {
    $slabs = is_array($taxItem['slabs'] ?? null) ? $taxItem['slabs'] : [];
    $rates = [];
    foreach ($slabs as $slab) { if (is_array($slab)) { $rates[] = rtrim(rtrim(number_format((float) ($slab['rate_pct'] ?? 0), 2, '.', ''), '0'), '.') . '%'; } }
    return $rates === [] ? '—' : implode(', ', array_unique($rates));
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $invoice === null ? 'Invoice not found' : 'Invoice ' . $esc($invoice['invoice_no'] ?: $invoice['id']) ?></title>
<link rel="stylesheet" href="assets/css/admin-unified.css">
<style>
body{background:#f1f5f9;color:#0f172a}.invoice-page{max-width:980px;margin:24px auto;padding:0 16px}.invoice-sheet{background:#fff;border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 18px 45px rgba(15,23,42,.08);padding:34px}.invoice-top{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;border-bottom:2px solid #e2e8f0;padding-bottom:20px}.brand-block{display:flex;gap:14px;align-items:flex-start}.brand-logo{max-width:92px;max-height:92px;object-fit:contain}.brand-name{font-size:24px;font-weight:800;margin:0}.invoice-title{text-align:right}.invoice-title h1{font-size:34px;margin:0 0 8px}.meta-grid,.party-grid,.summary-grid,.detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:22px}.info-card{border:1px solid #e2e8f0;border-radius:14px;padding:16px;background:#f8fafc}.info-card h2{font-size:15px;margin:0 0 10px;text-transform:uppercase;color:#475569;letter-spacing:.08em}.detail-row{display:flex;justify-content:space-between;gap:12px;margin:6px 0}.detail-row span:first-child{color:#64748b}.invoice-table{width:100%;border-collapse:collapse;margin-top:22px}.invoice-table th,.invoice-table td{border-bottom:1px solid #e2e8f0;padding:9px;text-align:left;vertical-align:top}.invoice-table th{background:#f8fafc;color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.06em}.num{text-align:right}.notes{white-space:pre-wrap}.page-actions{display:flex;justify-content:space-between;gap:12px;margin-bottom:16px}.not-found{background:#fff;border:1px solid #fecaca;border-radius:16px;padding:28px;text-align:center}.print-signature{margin-top:42px;display:flex;justify-content:flex-end}.signature-box{text-align:center;min-width:240px;border-top:1px solid #0f172a;padding-top:10px}@media print{body{background:#fff}.page-actions{display:none}.invoice-page{margin:0;max-width:none;padding:0}.invoice-sheet{box-shadow:none;border:0;border-radius:0;padding:0}.invoice-table th{background:#f8fafc!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}}@media(max-width:720px){.invoice-top,.meta-grid,.party-grid,.summary-grid,.detail-grid{grid-template-columns:1fr;display:grid}.invoice-title{text-align:left}.invoice-table{font-size:13px}}
</style>
</head>
<body>
<main class="invoice-page">
  <div class="page-actions">
    <a class="btn secondary" href="admin-invoices.php<?= $id !== '' ? '?id=' . urlencode($id) : '' ?>">Back to editor</a>
    <?php if ($invoice !== null): ?><button class="btn" type="button" onclick="window.print()">Print invoice</button><?php endif; ?>
  </div>
  <?php if ($invoice === null): ?>
    <section class="not-found"><h1>Invoice not found</h1><p>The requested invoice could not be loaded. Draft invoices do not require a challan; please check the invoice ID and try again.</p></section>
  <?php else: ?>
  <section class="invoice-sheet" aria-label="Invoice document">
    <header class="invoice-top">
      <div class="brand-block">
        <?php if ((string) ($company['logo_path'] ?? '') !== ''): ?><img class="brand-logo" src="<?= $esc($company['logo_path']) ?>" alt="Company logo"><?php endif; ?>
        <div><p class="brand-name"><?= $esc($company['brand_name'] ?: $company['company_name'] ?: 'Dakshayani Enterprises') ?></p><p><?= nl2br($esc($company['address_line'] ?? '')) ?></p><p><?= $esc($company['phone_primary'] ?? '') ?> <?= $esc($company['email_primary'] ?? '') ?></p><p><?= $esc($company['gstin'] ?? '') ?></p></div>
      </div>
      <div class="invoice-title"><h1>Tax Invoice</h1><p><strong><?= $esc($invoice['invoice_no'] ?: $invoice['id']) ?></strong></p><p>Status: <?= $esc($invoice['status'] ?? 'Draft') ?></p></div>
    </header>

    <section class="meta-grid">
      <div class="info-card"><h2>Invoice details</h2><div class="detail-row"><span>Invoice date</span><strong><?= $esc($invoiceDate ?: '—') ?></strong></div><div class="detail-row"><span>Invoice ID</span><strong><?= $esc($invoice['id']) ?></strong></div><div class="detail-row"><span>Capacity</span><strong><?= $esc($invoice['capacity_kwp'] ?? '—') ?> kWp</strong></div></div>
      <div class="info-card"><h2>Linked quotation</h2><div class="detail-row"><span>Quotation no.</span><strong><?= $esc($quote['quote_no'] ?? $invoice['quotation_no'] ?? '—') ?></strong></div><div class="detail-row"><span>Quotation ID</span><strong><?= $esc($invoice['linked_quote_id'] ?? $invoice['quotation_id'] ?? '—') ?></strong></div><div class="detail-row"><span>Pricing mode</span><strong><?= $esc($invoice['pricing_mode'] ?? '—') ?></strong></div></div>
    </section>

    <?php if ($customerFields !== []): ?>
    <section class="info-card" style="margin-top:22px"><h2>Customer &amp; Site Details</h2><div class="detail-grid">
      <?php foreach ($customerFields as $field): ?><div class="detail-row"><span><?= $esc($field['label'] ?? '') ?></span><strong><?= nl2br($esc($field['value'] ?? '')) ?></strong></div><?php endforeach; ?>
    </div></section>
    <?php endif; ?>

    <table class="invoice-table"><thead><tr><th>Sr No</th><th>Item and Description</th><th>HSN</th><th class="num">Quantity</th><th>Unit</th></tr></thead><tbody>
      <?php foreach ($items as $index => $item): if (!is_array($item)) { continue; } $desc=$first([$item['description']??'', $item['description_snapshot']??'', $item['master_description_snapshot']??'']); $name=$first([$item['name']??'', $item['name_snapshot']??'', $item['item_name']??'', $item['title']??'Solar supply / service']); $qty=(float)($item['qty']??$item['quantity']??1); $unit=$first([$item['unit']??'']); $hsn=$first([$item['hsn']??'', $item['hsn_snapshot']??'']); ?>
        <tr><td><?= $index + 1 ?></td><td><strong><?= $esc($name) ?></strong><?php if ($desc !== ''): ?><div class="notes"><?= $esc($desc) ?></div><?php endif; ?><?php if (trim((string)($item['custom_description']??'')) !== ''): ?><div class="notes"><em><?= $esc($item['custom_description']) ?></em></div><?php endif; ?></td><td><?= $esc($hsn) ?></td><td class="num"><?= $esc($qty) ?></td><td><?= $esc($unit) ?></td></tr>
      <?php endforeach; if ($items === []): ?><tr><td colspan="5">No line items were stored on this invoice. The amount summary below is still available from the accepted quotation.</td></tr><?php endif; ?>
    </tbody></table>

    <?php if ($specialRequests !== ''): ?><section class="info-card" style="margin-top:22px"><h2>Special Requests From Consumer</h2><p class="notes"><?= $esc($specialRequests) ?></p></section><?php endif; ?>

    <section class="summary-grid"><div class="info-card"><h2>Pricing Summary</h2><?php foreach ($pricingRows as $row): ?><div class="detail-row"><span><?= $esc($row['label']) ?><?php if (($row['note'] ?? '') !== ''): ?><br><small><?= $esc($row['note']) ?></small><?php endif; ?></span><strong><?= $money($row['amount']) ?></strong></div><?php endforeach; ?></div><div class="info-card"><h2>Tax Summary</h2><div class="detail-row"><span>Basic Value</span><strong><?= $money($taxBreakdown['basic_total'] ?? 0) ?></strong></div><div class="detail-row"><span>Total GST</span><strong><?= $money($taxBreakdown['gst_total'] ?? 0) ?></strong></div><div class="detail-row"><span>Total incl GST</span><strong><?= $money($taxBreakdown['gross_incl_gst'] ?? $grandTotal) ?></strong></div></div></section>

    <table class="invoice-table"><thead><tr><th>Sr No</th><th>Item</th><th>HSN</th><th class="num">Taxable value</th><th>GST Rate</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">IGST</th><th class="num">GST Amount</th><th class="num">Gross incl GST</th></tr></thead><tbody>
      <?php foreach ($taxItems as $index => $taxItem): $parts=$taxParts($taxItem); ?>
        <tr><td><?= $index + 1 ?></td><td><?= $esc($taxItem['name'] ?? 'Item') ?></td><td><?= $esc($taxItem['hsn'] ?? '') ?></td><td class="num"><?= $money($taxItem['taxable_value'] ?? 0) ?></td><td><?= $esc($rateText($taxItem)) ?></td><td class="num"><?= (float)$parts['cgst'] > 0 ? $money($parts['cgst']) : '—' ?></td><td class="num"><?= (float)$parts['sgst'] > 0 ? $money($parts['sgst']) : '—' ?></td><td class="num"><?= (float)$parts['igst'] > 0 ? $money($parts['igst']) : '—' ?></td><td class="num"><?= $money($taxItem['gst_amount'] ?? 0) ?></td><td class="num"><?= $money($taxItem['gross_incl_gst'] ?? 0) ?></td></tr>
      <?php endforeach; if ($taxItems === []): ?><tr><td colspan="10">Detailed tax breakup is not available for this invoice.</td></tr><?php endif; ?>
    </tbody></table>

    <section class="summary-grid"><div class="info-card"><h2>Notes</h2><p class="notes"><?= $esc($invoice['notes'] ?? $invoice['internal_notes'] ?? 'Thank you for your business.') ?></p></div></section>
    <div class="print-signature"><div class="signature-box">Authorized Signatory</div></div>
  </section>
  <?php endif; ?>
</main>
</body>
</html>
