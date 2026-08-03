<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$assertions=0; function ok784($cond,$msg){global $assertions; $assertions++; if(!$cond){fwrite(STDERR,"FAIL: $msg\n"); exit(1);} }
function eq784($a,$b,$msg){ ok784(abs((float)$a-(float)$b)<0.005,$msg." got $a expected $b"); }

$base=documents_base_dir(); $old=getenv('DOCUMENTS_BASE_DIR');
$invoice=['id'=>'inv_784_a','invoice_no'=>'INV-784-A','invoice_date'=>'2026-07-01','status'=>'draft','linked_quote_id'=>'q784','quotation_no'=>'Q-784','customer_mobile'=>'9999999999','customer_snapshot'=>['name'=>'A','mobile'=>'9999999999'],'pricing'=>['final_invoice_total_incl_gst'=>290000,'quotation_total_incl_gst'=>300000],'calc'=>['gross_payable'=>290000,'grand_total'=>290000],'tax_breakdown'=>['gross_incl_gst'=>290000,'basic_total'=>245762.71,'gst_total'=>44237.29,'items'=>[['gross_incl_gst'=>290000]]],'commercial_items'=>[['name'=>'Solar']]];
ok784(documents_invoice_is_draft($invoice),'1 new invoice starts draft');
ok784(documents_invoice_status_label($invoice['status'])==='Draft','2 draft label available for Finalize / Issue action callers');
$s=documents_invoice_payment_summary($invoice); eq784($s['invoice_total'],290000,'3 summary uses invoice final total'); eq784($s['outstanding'],290000,'4 no payments outstanding'); ok784($s['payment_status']==='unpaid','5 no-payment finalized would be unpaid');

$receipts=[
 ['id'=>'r_draft','status'=>'draft','quotation_id'=>'q784','allocations'=>[['invoice_id'=>'inv_784_a','amount_rs'=>999999]]],
 ['id'=>'r_part','status'=>'final','amount_rs'=>250000,'quotation_id'=>'q784','customer_mobile'=>'9999999999','allocations'=>[['invoice_id'=>'inv_784_a','amount_rs'=>250000]],'date_received'=>'2026-07-01'],
 ['id'=>'r_cancel','status'=>'cancelled','quotation_id'=>'q784','allocations'=>[['invoice_id'=>'inv_784_a','amount_rs'=>40000]]],
 ['id'=>'r_reverse','status'=>'reversed','quotation_id'=>'q784','allocations'=>[['invoice_id'=>'inv_784_a','amount_rs'=>40000]]],
 ['id'=>'r_void','status'=>'voided','quotation_id'=>'q784','allocations'=>[['invoice_id'=>'inv_784_a','amount_rs'=>40000]]],
 ['id'=>'r_arch','status'=>'final','archived_flag'=>true,'quotation_id'=>'q784','allocations'=>[['invoice_id'=>'inv_784_a','amount_rs'=>40000]]],
];
$sum=0; foreach($receipts as $r){$sum += documents_receipt_allocation_for_invoice($r,'inv_784_a',[$invoice]);}
eq784($sum,250000,'6 only finalized active receipt allocation counts');

$path=documents_sales_receipts_store_path(); $dir=dirname($path); if(!is_dir($dir)) mkdir($dir,0775,true); $backup=is_file($path)?file_get_contents($path):null; file_put_contents($path,json_encode($receipts));
$s=documents_invoice_payment_summary($invoice); eq784($s['total_received'],250000,'7 partial received exact'); eq784($s['outstanding'],40000,'8 partial remaining exact'); ok784($s['payment_status']==='partially_paid','9 partial status'); ok784($s['receipt_count']===1,'10 receipt counted once');
$invoicePaid=$invoice; $invoicePaid['pricing']['final_invoice_total_incl_gst']=250000; $invoicePaid['calc']['gross_payable']=250000; $s=documents_invoice_payment_summary($invoicePaid); ok784($s['payment_status']==='paid','11 exact payment paid');
$invoiceTol=$invoice; $invoiceTol['pricing']['final_invoice_total_incl_gst']=250000.01; $invoiceTol['calc']['gross_payable']=250000.01; ok784(documents_invoice_payment_summary($invoiceTol)['payment_status']==='paid','12 one paise tolerance paid');
$invoiceOver=$invoice; $invoiceOver['pricing']['final_invoice_total_incl_gst']=249000; $invoiceOver['calc']['gross_payable']=249000; $s=documents_invoice_payment_summary($invoiceOver); ok784($s['payment_status']==='overpaid','13 excess overpaid'); eq784($s['overpayment'],1000,'14 overpayment exact');

$bad=documents_receipt_allocations_normalize(['status'=>'final','amount_rs'=>100,'allocations'=>[['invoice_id'=>'inv_784_a','amount_rs'=>-1]]],[$invoice]); ok784(!$bad['ok'] && in_array('negative_allocation',$bad['errors'],true),'15 negative rejected');
$bad=documents_receipt_allocations_normalize(['status'=>'final','amount_rs'=>100,'allocations'=>[['invoice_id'=>'missing','amount_rs'=>1]]],[$invoice]); ok784(!$bad['ok'] && in_array('invalid_invoice_id',$bad['errors'],true),'16 invalid invoice rejected');
$bad=documents_receipt_allocations_normalize(['status'=>'final','amount_rs'=>100,'customer_mobile'=>'1111111111','allocations'=>[['invoice_id'=>'inv_784_a','amount_rs'=>1]]],[$invoice]); ok784(!$bad['ok'] && in_array('cross_customer_allocation',$bad['errors'],true),'17 cross customer rejected');
$bad=documents_receipt_allocations_normalize(['status'=>'final','amount_rs'=>100,'allocations'=>[['invoice_id'=>'inv_784_a','amount_rs'=>60],['invoice_id'=>'inv_784_a','amount_rs'=>50,'authorized_override'=>true]]],[$invoice]); ok784(!$bad['ok'] && in_array('allocation_exceeds_receipt',$bad['errors'],true),'18 allocation cannot exceed receipt');

$split=documents_receipt_allocations_normalize(['status'=>'final','amount_rs'=>350000,'allocations'=>[['invoice_id'=>'inv_784_a','amount_rs'=>300000],['invoice_id'=>'inv_784_b','amount_rs'=>50000]]],[$invoice, array_merge($invoice,['id'=>'inv_784_b','customer_mobile'=>'9999999999'])]); ok784($split['ok'] && count($split['allocations'])===2,'19 split receipt valid');
$amb=documents_receipt_allocations_normalize(['status'=>'final','amount_rs'=>10,'quotation_id'=>'q784'],[$invoice,array_merge($invoice,['id'=>'inv_784_b'])]); ok784($amb['allocations']===[],'20 ambiguous quote receipt unallocated');
$one=documents_receipt_allocations_normalize(['status'=>'final','amount_rs'=>10,'quotation_id'=>'q784'],[$invoice]); $two=documents_receipt_allocations_normalize(['status'=>'final','amount_rs'=>10,'quotation_id'=>'q784'],[$invoice]); ok784($one==$two && count($one['allocations'])===1,'21 unambiguous migration idempotent');

$f=documents_invoice_finalize($invoice,['id'=>'u1','name'=>'Admin']); ok784($f['ok'],'22 draft finalizes without full payment'); $fin=$f['invoice']; ok784(documents_invoice_is_finalized($fin),'23 finalized document status separate'); ok784(documents_invoice_payment_status($fin)==='partially_paid','24 payment status remains partial'); ok784(!empty($fin['finalized_snapshot']['pricing']) && !empty($fin['finalized_at']),'25 freezes pricing and actor timestamp');
$rev=documents_invoice_start_revision($fin,['id'=>'u1','name'=>'Admin'],'Correction'); ok784($rev['ok'] && $rev['invoice']['revision_no']===2 && $rev['invoice']['status']==='draft','26 revision reason creates draft and increments'); ok784(($rev['invoice']['revisions'][0]['status']??'')==='finalized','27 previous finalized snapshot preserved');
ok784(!documents_invoice_start_revision($fin,['id'=>'u1'],'')['ok'],'28 revision requires reason'); ok784(!documents_invoice_cancel($fin,['id'=>'u1'],'')['ok'],'29 cancel requires reason'); $can=documents_invoice_cancel($fin,['id'=>'u1'],'Customer requested cancellation'); ok784($can['ok'] && documents_invoice_is_cancelled($can['invoice']),'30 cancellation preserves history status');

if($backup===null) unlink($path); else file_put_contents($path,$backup);
echo "invoice_finalization_payment_reconciliation_test passed ($assertions assertions)\n";
