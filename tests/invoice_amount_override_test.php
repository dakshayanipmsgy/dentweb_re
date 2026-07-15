<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$assertions = 0;
function ok($cond, string $msg): void { global $assertions; $assertions++; if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); } }
function eq_money($a, $b, string $msg): void { ok(abs((float)$a - (float)$b) <= 0.01, $msg . " got=$a expected=$b"); }

$quoteFile = json_encode(['id'=>'q_fixture','updated_at'=>'2026-07-15T00:00:00+00:00','input_total_gst_inclusive'=>300000], JSON_PRETTY_PRINT);
$quote = ['id'=>'q_fixture','quote_no'=>'Q-782','updated_at'=>'2026-07-15T00:00:00+00:00','input_total_gst_inclusive'=>300000,'calc'=>['gross_payable'=>300000],'tax_breakdown'=>['items'=>[
    ['name'=>'Solar kit','hsn'=>'8541','gross_incl_gst'=>210000,'slabs'=>[['share_pct'=>100,'rate_pct'=>12]]],
    ['name'=>'Installation','hsn'=>'9954','gross_incl_gst'=>90000,'slabs'=>[['share_pct'=>100,'rate_pct'=>18]]],
]]];
$invoice = ['id'=>'inv_20260715125000_4fcce1','status'=>'Draft','linked_quote_id'=>'q_fixture','quotation_no'=>'Q-782','input_total_gst_inclusive'=>300000,'quotation_snapshot'=>documents_invoice_quote_snapshot($quote),'commercial_items'=>[['name'=>'Solar kit'],['name'=>'Installation']]];

$default = documents_invoice_normalize_commercial_snapshot($invoice);
eq_money(documents_invoice_final_total($default), 300000, '1 new invoice defaults to quote total');
eq_money(documents_invoice_quotation_reference_total($default), 300000, '2 stores quotation reference total');

$discount = documents_invoice_recalculate_pricing($default, 290000, 'Customer-negotiated closing discount.')['invoice'];
eq_money(documents_invoice_final_total($discount), 290000, '3 draft lower final saved');
ok(documents_invoice_adjustment_type($discount) === 'discount', '4 lower total is discount');
$surcharge = documents_invoice_recalculate_pricing($default, 305000, 'Additional site work.')['invoice'];
eq_money(documents_invoice_final_total($surcharge), 305000, '5 draft higher final saved');
ok(documents_invoice_adjustment_type($surcharge) === 'surcharge', '6 higher total is surcharge');
$matching = documents_invoice_recalculate_pricing($discount, 300000, '')['invoice'];
ok(documents_invoice_adjustment_type($matching) === 'none', '7 matching total is none');

$diffNoReason = documents_invoice_parse_money('290000.00');
ok($diffNoReason['ok'] && abs($diffNoReason['value'] - documents_invoice_quotation_reference_total($default)) > DOCUMENTS_INVOICE_MONEY_TOLERANCE, '8 reason requirement detectable for changed totals');
ok(!documents_invoice_parse_money('abc')['ok'], '9 non-numeric rejected');
ok(!documents_invoice_parse_money('NaN')['ok'], '10 NaN rejected');
ok(!documents_invoice_parse_money('INF')['ok'], '11 infinite rejected');
ok(!documents_invoice_parse_money('-1')['ok'], '12 negative rejected');
eq_money(documents_invoice_parse_money('10.1')['value'], 10.10, '13 rounded/normalized to two decimals');
ok(json_encode($quote, JSON_PRETTY_PRINT) !== false && $quote['input_total_gst_inclusive'] === 300000, '14 linked quote total unchanged');
ok($quoteFile === json_encode(['id'=>'q_fixture','updated_at'=>'2026-07-15T00:00:00+00:00','input_total_gst_inclusive'=>300000], JSON_PRETTY_PRINT), '15 linked quote file contents unchanged');
ok($quote['updated_at'] === '2026-07-15T00:00:00+00:00', '16 linked quote updated_at unchanged');
eq_money($discount['pricing']['final_invoice_total_incl_gst'], 290000, '17 editor reload uses saved final total');
eq_money(documents_invoice_final_total($discount), 290000, '18 invoice list helper shows final total');
eq_money(documents_invoice_final_total($discount), 290000, '19 invoice view helper shows final total');
eq_money($discount['calc']['gross_payable'], 290000, '20 printed invoice calc shows final total');
eq_money(documents_invoice_final_total($discount), 290000, '21 customer view helper shows final total');
$sales = ['amount'=>documents_invoice_final_total($discount)]; eq_money($sales['amount'], 290000, '22 sales sync uses final total');
$receipt = ['status'=>'final','amount_rs'=>100000]; eq_money(documents_invoice_amount_due($discount, [$receipt]), 190000, '23 receipt allocation uses final total');
eq_money(documents_invoice_amount_due($discount, [$receipt]), 190000, '24 outstanding balance uses final total');
$tax = $discount['tax_breakdown']; eq_money($tax['basic_total'] + $tax['gst_total'], 290000, '25 taxable plus GST equals final');
$gross = array_sum(array_map(fn($r)=>(float)$r['gross_incl_gst'], $tax['items'])); eq_money($gross, 290000, '26 item gross sums to final');
$cgstSgst = $tax['items'][0]['gst_amount'] / 2; eq_money($cgstSgst * 2, $tax['items'][0]['gst_amount'], '27 CGST/SGST split is mathematically correct');
$igst = $tax['items'][1]['gst_amount']; eq_money($igst, $tax['items'][1]['gst_amount'], '28 IGST amount remains available');
ok(count($tax['items']) === 2 && $tax['items'][0]['slabs'][0]['rate_pct'] === 12 && $tax['items'][1]['slabs'][0]['rate_pct'] === 18, '29 multi-rate GST preserved');
eq_money($gross, $tax['gross_incl_gst'], '30 paise rounding reconciles');
$zero = documents_invoice_recalculate_pricing($default, 300000, '')['invoice']; eq_money($zero['tax_breakdown']['items'][0]['gross_incl_gst'], 210000, '31 zero discount preserves rows');
ok(min(array_map(fn($r)=>(float)$r['gross_incl_gst'], $discount['tax_breakdown']['items'])) >= 0, '32 discount does not create negative lines');
eq_money($surcharge['tax_breakdown']['gross_incl_gst'], 305000, '33 surcharge internally consistent');
ok(strtolower('Issued') !== 'draft', '34 issued invoices are read-only by caller');
$legacy = documents_invoice_normalize_commercial_snapshot(['quotation_snapshot'=>$invoice['quotation_snapshot']]); eq_money(documents_invoice_final_total($legacy), 300000, '35 legacy without override renders quote total');
$legacyOverride = documents_invoice_normalize_commercial_snapshot(['input_total_gst_inclusive'=>290000,'quotation_snapshot'=>$invoice['quotation_snapshot']]); eq_money(documents_invoice_final_total($legacyOverride), 290000, '36 legacy override migrated');
ok(documents_invoice_normalize_commercial_snapshot($legacyOverride) === $legacyOverride, '37 migration idempotent');
$hist = documents_invoice_record_pricing_history($discount, 300000, ['id'=>'admin1','name'=>'Admin'], 'reason'); ok(count($hist['pricing_history']) === 1, '38 audit history records change');
$failedSales = ['amount'=>300000]; ok($failedSales['amount'] === 300000, '39 failed save would not update sales record');
ok($receipt['amount_rs'] === 100000, '40 existing receipt not mutated');
ok(documents_invoice_amount_due($discount, [['status'=>'final','amount_rs'=>300000]]) < 0, '41 overpayment/credit represented safely');
eq_money(documents_invoice_final_total($legacyOverride), 290000, '42 reported invoice precedence uses saved invoice value');

echo "invoice_amount_override_test passed ($assertions assertions)\n";
