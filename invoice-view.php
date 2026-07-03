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
$snapshot = $invoice !== null ? array_merge(documents_customer_snapshot_defaults(), is_array($invoice['customer_snapshot'] ?? null) ? $invoice['customer_snapshot'] : []) : documents_customer_snapshot_defaults();
$items = $invoice !== null && is_array($invoice['commercial_items'] ?? null) ? $invoice['commercial_items'] : [];
$calc = $invoice !== null && is_array($invoice['calc'] ?? null) ? $invoice['calc'] : [];
$taxBreakdown = is_array($invoice['tax_breakdown'] ?? null) ? $invoice['tax_breakdown'] : (is_array($calc['tax_breakdown'] ?? null) ? $calc['tax_breakdown'] : []);
$grandTotal = $invoice !== null ? (float) ($invoice['input_total_gst_inclusive'] ?? $calc['gross_payable'] ?? $calc['grand_total'] ?? $calc['final_price_incl_gst'] ?? 0) : 0.0;
$invoiceDate = $invoice !== null ? $first([$invoice['invoice_date'] ?? '', substr((string) ($invoice['created_at'] ?? ''), 0, 10)]) : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $invoice === null ? 'Invoice not found' : 'Invoice ' . $esc($invoice['invoice_no'] ?: $invoice['id']) ?></title>
<link rel="stylesheet" href="assets/css/admin-unified.css">
<style>
body{background:#f1f5f9;color:#0f172a}.invoice-page{max-width:980px;margin:24px auto;padding:0 16px}.invoice-sheet{background:#fff;border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 18px 45px rgba(15,23,42,.08);padding:34px}.invoice-top{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;border-bottom:2px solid #e2e8f0;padding-bottom:20px}.brand-block{display:flex;gap:14px;align-items:flex-start}.brand-logo{max-width:92px;max-height:92px;object-fit:contain}.brand-name{font-size:24px;font-weight:800;margin:0}.invoice-title{text-align:right}.invoice-title h1{font-size:34px;margin:0 0 8px}.meta-grid,.party-grid,.summary-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:22px}.info-card{border:1px solid #e2e8f0;border-radius:14px;padding:16px;background:#f8fafc}.info-card h2{font-size:15px;margin:0 0 10px;text-transform:uppercase;color:#475569;letter-spacing:.08em}.detail-row{display:flex;justify-content:space-between;gap:12px;margin:6px 0}.detail-row span:first-child{color:#64748b}.invoice-table{width:100%;border-collapse:collapse;margin-top:22px}.invoice-table th,.invoice-table td{border-bottom:1px solid #e2e8f0;padding:11px;text-align:left;vertical-align:top}.invoice-table th{background:#f8fafc;color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.06em}.num{text-align:right}.notes{white-space:pre-wrap}.page-actions{display:flex;justify-content:space-between;gap:12px;margin-bottom:16px}.not-found{background:#fff;border:1px solid #fecaca;border-radius:16px;padding:28px;text-align:center}.print-signature{margin-top:42px;display:flex;justify-content:flex-end}.signature-box{text-align:center;min-width:240px;border-top:1px solid #0f172a;padding-top:10px}@media print{body{background:#fff}.page-actions{display:none}.invoice-page{margin:0;max-width:none;padding:0}.invoice-sheet{box-shadow:none;border:0;border-radius:0;padding:0}.invoice-table th{background:#f8fafc!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}}@media(max-width:720px){.invoice-top,.meta-grid,.party-grid,.summary-grid{grid-template-columns:1fr;display:grid}.invoice-title{text-align:left}.invoice-table{font-size:13px}}
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

    <section class="party-grid"><div class="info-card"><h2>Bill to</h2><p><strong><?= $esc($snapshot['name'] ?? 'Customer') ?></strong></p><p><?= nl2br($esc($snapshot['address'] ?? '')) ?></p><p>Mobile: <?= $esc($invoice['customer_mobile'] ?? $snapshot['mobile'] ?? '') ?></p></div><div class="info-card"><h2>Project / supply</h2><p><?= $esc($quote['project_summary_line'] ?? $quote['system_type'] ?? 'Solar project') ?></p><p>Place of supply: <?= $esc($quote['place_of_supply_state'] ?? $snapshot['state'] ?? 'Jharkhand') ?></p></div></section>

    <table class="invoice-table"><thead><tr><th>#</th><th>Description</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Tax</th><th class="num">Amount</th></tr></thead><tbody>
      <?php foreach ($items as $index => $item): if (!is_array($item)) { continue; } $desc=$first([$item['description']??'', $item['name']??'', $item['item_name']??'', $item['title']??'Solar supply / service']); $qty=(float)($item['qty']??$item['quantity']??1); $rate=(float)($item['rate']??$item['unit_price']??$item['price']??0); $amount=(float)($item['amount']??$item['total']??($qty*$rate)); ?>
        <tr><td><?= $index + 1 ?></td><td><?= $esc($desc) ?></td><td class="num"><?= $esc($qty) ?></td><td class="num"><?= $rate > 0 ? $money($rate) : '—' ?></td><td class="num"><?= $esc($item['gst_rate'] ?? $item['tax_rate'] ?? '—') ?></td><td class="num"><?= $amount > 0 ? $money($amount) : '—' ?></td></tr>
      <?php endforeach; if ($items === []): ?><tr><td colspan="6">No line items were stored on this invoice. The amount summary below is still available from the accepted quotation.</td></tr><?php endif; ?>
    </tbody></table>

    <section class="summary-grid"><div class="info-card"><h2>Notes</h2><p class="notes"><?= $esc($invoice['notes'] ?? $invoice['internal_notes'] ?? 'Thank you for your business.') ?></p></div><div class="info-card"><h2>Amount summary</h2><div class="detail-row"><span>Basic total</span><strong><?= $money($calc['basic_total'] ?? $taxBreakdown['basic_total'] ?? 0) ?></strong></div><div class="detail-row"><span>GST total</span><strong><?= $money($calc['gst_total'] ?? $taxBreakdown['gst_total'] ?? (($taxBreakdown['gross_incl_gst'] ?? 0) - ($taxBreakdown['basic_total'] ?? 0))) ?></strong></div><div class="detail-row"><span>Discount</span><strong><?= $money($calc['discount_rs'] ?? 0) ?></strong></div><div class="detail-row"><span>Grand total</span><strong><?= $money($grandTotal) ?></strong></div></div></section>
    <div class="print-signature"><div class="signature-box">Authorized Signatory</div></div>
  </section>
  <?php endif; ?>
</main>
</body>
</html>
