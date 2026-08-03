<?php
declare(strict_types=1);
$tmp=sys_get_temp_dir().'/dentweb_issue950_'.bin2hex(random_bytes(4)); putenv('DOCUMENTS_BASE_DIR='.$tmp);
require_once __DIR__.'/../admin/includes/documents_helpers.php';
function ok950($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function money950($a,$b,$m){ok950(documents_invoice_money_to_paise((float)$a)===documents_invoice_money_to_paise((float)$b),$m);}

documents_ensure_structure(); $qid='project_fixture';
$amounts=[71100,800,50000,50000,20000]; $rows=[];
foreach($amounts as $i=>$amount)$rows[]=['id'=>'receipt_'.($i+1),'receipt_number'=>'R'.($i+1),'quote_id'=>$qid,'status'=>$i===0?'posted':'finalized','amount_received'=>$amount,'customer_mobile'=>'old_snapshot','date_received'=>'2026-01-0'.($i+1)];
$rows[]=$rows[2]; $rows[]=['id'=>'draft','quotation_id'=>$qid,'status'=>'draft','amount_rs'=>999]; $rows[]=['id'=>'void','quotation_id'=>$qid,'status'=>'voided','amount_rs'=>999];
json_save(documents_sales_receipts_store_path(),$rows);
$ledger=documents_receipt_ledger($qid); ok950(count($ledger)===5,'five qualified, deduplicated receipts'); money950(array_sum(array_column($ledger,'amount_rs')),191900,'exact fixture total');
$invoice=['id'=>'invoice_fixture','invoice_no'=>'DRAFT-1','status'=>'draft','linked_quote_id'=>$qid,'customer_mobile'=>'corrected_current','pricing'=>['final_invoice_total_incl_gst'=>191900,'quotation_total_incl_gst'=>191900],'invoice_date'=>'2026-01-01'];
ok950(documents_save_invoice($invoice)['ok'],'invoice saved'); ok950(documents_invoice_is_draft(documents_get_invoice('invoice_fixture')),'allocation never finalizes draft');
$ledger=documents_receipt_ledger($qid); foreach($ledger as $r)ok950(count($r['allocations']??[])===1,'receipt persistently auto allocated');
$summary=documents_invoice_payment_summary($invoice); money950($summary['total_received'],191900,'invoice received'); money950($summary['outstanding'],0,'invoice outstanding'); ok950($summary['receipt_count']===5&&$summary['payment_status']==='paid','paid with five receipts');
$again=documents_reconcile_receipts_for_quote($qid); ok950($again['changed']===[],'second repair/reconcile is idempotent');

$invoice2=array_merge($invoice,['id'=>'invoice_two','invoice_no'=>'DRAFT-2','pricing'=>['final_invoice_total_incl_gst'=>1000,'quotation_total_incl_gst'=>1000]]); documents_save_invoice($invoice2);
$new=['id'=>'ambiguous','quotation_id'=>$qid,'status'=>'paid','amount_rs'=>100]; documents_save_sales_document('receipt',$new); $stored=documents_get_sales_document('receipt','ambiguous'); ok950(($stored['allocations']??[])===[],'multiple invoices are never guessed');
$split=documents_allocate_receipt('ambiguous',['invoice_two'=>60],['id'=>'admin']); ok950($split['ok'],'explicit split saved'); money950($split['unallocated'],40,'unallocated project amount exposed');
$cross=documents_receipt_allocations_normalize(['quotation_id'=>'other','status'=>'final','amount_rs'=>1,'allocations'=>[['invoice_id'=>'invoice_two','amount_rs'=>1]]]); ok950(!$cross['ok']&&in_array('cross_project_allocation',$cross['errors'],true),'cross project denied');
$legacy=documents_receipt_ledger($qid,[['receipt_id'=>'legacy','linked_quotation_id'=>$qid,'payment_status'=>'received','total_received'=>'0.01']]); ok950(count($legacy)===1&&$legacy[0]['amount_paise']===1,'legacy link/status aliases and paise safe amount');

$repairInvoice=array_merge($invoice,['id'=>'repair_invoice','linked_quote_id'=>'repair_project','pricing'=>['final_invoice_total_incl_gst'=>25,'quotation_total_incl_gst'=>25]]); documents_save_invoice($repairInvoice);
$repairRows=documents_read_sales_store(documents_sales_receipts_store_path()); $repairRows[]=['id'=>'repair_receipt','quotation_id'=>'repair_project','status'=>'final','amount_rs'=>25]; json_save(documents_sales_receipts_store_path(),$repairRows);
$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/repair-receipt-ledger.php');
$dry=shell_exec($command.' 2>&1'); ok950(str_contains((string)$dry,'DRY RUN: 1 unambiguous'),'repair defaults to dry run'); ok950((documents_get_sales_document('receipt','repair_receipt')['allocations']??[])===[],'dry run does not write');
$applied=shell_exec($command.' --apply 2>&1'); ok950(str_contains((string)$applied,'APPLY: 1 unambiguous'),'repair apply writes one safe record'); ok950(count(glob(documents_sales_receipts_store_path().'.backup.*')?:[])===1,'repair creates backup');
$second=shell_exec($command.' --apply 2>&1'); ok950(str_contains((string)$second,'APPLY: 0 unambiguous'),'repair second apply is idempotent'); ok950(count(glob(documents_sales_receipts_store_path().'.backup.*')?:[])===1,'idempotent run creates no needless backup');

echo "issue_950_receipt_ledger_test passed\n";
