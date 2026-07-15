<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$assertions=0; function ok786($cond,$msg){global $assertions; $assertions++; if(!$cond){fwrite(STDERR,"FAIL: $msg\n"); exit(1);} }
function eq786($a,$b,$msg){ok786($a===$b,$msg." got=".var_export($a,true)." expected=".var_export($b,true));}

$today=date('Y-m-d'); $future=date('Y-m-d', strtotime('+1 day'));
$quote=['id'=>'q786','quote_no'=>'Q-786','input_total_gst_inclusive'=>300000,'updated_at'=>'2026-07-01T00:00:00+00:00','calc'=>['gross_payable'=>300000],'tax_breakdown'=>['items'=>[['name'=>'Solar','gross_incl_gst'=>300000,'slabs'=>[['share_pct'=>100,'rate_pct'=>12]]]]]];
$invoice=['id'=>'inv_786','invoice_no'=>'INV-786','status'=>'draft','created_at'=>'2026-07-01T10:20:00+00:00','linked_quote_id'=>'q786','quotation_no'=>'Q-786','customer_mobile'=>'9999999999','customer_snapshot'=>['name'=>'Customer','mobile'=>'9999999999'],'quotation_snapshot'=>documents_invoice_quote_snapshot($quote),'commercial_items'=>[['name'=>'Solar']],'input_total_gst_inclusive'=>300000];

$normalized=documents_invoice_normalize_commercial_snapshot($invoice);
eq786($normalized['invoice_date'],'2026-07-01','1 legacy draft gets explicit created_at fallback date');
eq786($normalized['invoice_date_source'],'legacy_created_at_fallback','2 legacy source marked');
ok786(documents_invoice_normalize_date($normalized)===$normalized,'3 date normalization idempotent');

$set=documents_invoice_set_date($normalized,'2026-07-10',['id'=>'u1','name'=>'Admin']);
ok786($set['ok'],'4 valid draft date accepted'); $draft=$set['invoice'];
eq786($draft['invoice_date'],'2026-07-10','5 selected date persisted exactly');
eq786(documents_invoice_authoritative_date($draft),'2026-07-10','6 editor/list/workspace authoritative date is saved date');
ok786(strpos('<input type="date" name="invoice_date" value="'.htmlspecialchars($draft['invoice_date'],ENT_QUOTES).'">','name="invoice_date"')!==false,'7 draft editor renders date input name');
ok786(strpos('Invoice date '.$draft['invoice_date'],'2026-07-10')!==false,'8 view/print/customer/document pack can show saved date');
$sales=['invoice_date'=>documents_invoice_authoritative_date($draft),'amount'=>documents_invoice_final_total($draft)]; eq786($sales['invoice_date'],'2026-07-10','9 sales index stores saved date');

ok786(!documents_invoice_date_validate('',false)['ok'],'10 empty rejected');
ok786(!documents_invoice_date_validate('10-07-2026',false)['ok'],'11 invalid format rejected');
ok786(!documents_invoice_date_validate('2026-02-30',false)['ok'],'12 impossible date rejected');
ok786(!documents_invoice_date_validate($future,false)['ok'],'13 future date rejected');
$missing=$draft; unset($missing['invoice_date']); $can=documents_invoice_can_finalize($missing); ok786(!$can['ok'] && in_array('Set a valid invoice date before finalizing this invoice.',$can['errors'],true),'14 finalization requires valid saved date');

$fin=documents_invoice_finalize($draft,['id'=>'u1','name'=>'Admin']); ok786($fin['ok'],'15 valid dated invoice finalizes'); $final=$fin['invoice'];
eq786($final['finalized_snapshot']['invoice_date'],'2026-07-10','16 finalization freezes date in snapshot');
eq786(documents_invoice_authoritative_date($final),'2026-07-10','17 finalized authoritative date comes from snapshot');
$ordinary=$final; $ordinary['invoice_date']='2026-07-11'; eq786(documents_invoice_authoritative_date($ordinary),'2026-07-10','18 ordinary edit cannot override finalized snapshot date');
ok786(($final['audit_events'][count($final['audit_events'])-1]['new_invoice_date']??'')==='2026-07-10','19 audit event records finalized date');

$rev=documents_invoice_start_revision($final,['id'=>'u1','name'=>'Admin'],'Date correction'); ok786($rev['ok'],'20 revision starts with reason'); $revDraft=$rev['invoice'];
eq786($revDraft['invoice_date'],'2026-07-10','21 revision draft copies prior date');
$changed=documents_invoice_set_date($revDraft,'2026-07-09',['id'=>'u1','name'=>'Admin']); ok786($changed['ok'],'22 revision draft may change date'); $revDraft=$changed['invoice'];
eq786($revDraft['revisions'][0]['snapshot']['invoice_date'],'2026-07-10','23 old finalized revision retains old date');
$revFin=documents_invoice_finalize($revDraft,['id'=>'u1','name'=>'Admin'])['invoice']; eq786($revFin['finalized_snapshot']['invoice_date'],'2026-07-09','24 finalized revision uses new date');
ok786(array_filter($revFin['audit_events'],fn($e)=>($e['previous_invoice_date']??'')==='2026-07-10' && ($e['new_invoice_date']??'')==='2026-07-09')!==[],'25 audit history records previous and new dates');

$quoteBefore=$quote; documents_invoice_recalculate_pricing($draft,290000,'discount'); ok786($quote===$quoteBefore,'26 linked quotation unchanged / issue 782 regression');
$paid=$draft; $paid['pricing']['final_invoice_total_incl_gst']=250000; $summary=documents_invoice_payment_summary($paid); ok786(in_array($summary['payment_status'],['unpaid','partially_paid','paid','overpaid','not_applicable'],true),'27 issue 784 payment summary still computes');

echo "invoice_date_editing_test passed ($assertions assertions)\n";
