<?php
declare(strict_types=1);
$tmp=sys_get_temp_dir().'/dentweb_952_'.bin2hex(random_bytes(4));putenv('DOCUMENTS_BASE_DIR='.$tmp);
require_once __DIR__.'/../admin/includes/documents_helpers.php';
function ok952($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function money952($v,$e,$m){ok952(documents_invoice_money_to_paise((float)$v)===$e,$m);}
documents_ensure_structure();$qid='sanitized-project';
$old=['id'=>'old','status'=>'cancelled','linked_quote_id'=>$qid,'pricing'=>['final_invoice_total_incl_gst'=>500]];documents_save_invoice($old);
$current=['id'=>'current','status'=>'draft','linked_quote_id'=>$qid,'pricing'=>['final_invoice_total_incl_gst'=>500]];documents_save_invoice($current);
$rows=[];for($i=1;$i<=5;$i++)$rows[]=['id'=>'r'.$i,'receipt_id'=>'r'.$i,'status'=>'final','quotation_id'=>$qid,'amount_rs'=>100,'allocations'=>[['invoice_id'=>$i<=3?'current':'old','amount_rs'=>100]]];
json_save(documents_sales_receipts_store_path(),$rows);
$before=documents_invoice_payment_summary($current);money952($before['total_received'],30000,'three initially count');ok952(count(documents_receipt_ledger($qid))===5,'id and receipt_id aliases deduplicate');
$result=documents_reconcile_receipts_for_quote($qid);ok952(count($result['changed'])===2,'two stale allocations migrate');
$after=documents_invoice_payment_summary($current);money952($after['total_received'],50000,'all five count exactly once');ok952($after['receipt_count']===5&&$after['payment_status']==='paid','five receipts paid');ok952(documents_get_invoice('current')['status']==='draft','draft remains draft');
foreach(['r4','r5'] as $rid){$r=documents_get_sales_document('receipt',$rid);ok952(count($r['allocation_migration_audit']??[])===1,'immutable migration audit appended');ok952(($r['allocations'][0]['invoice_id']??'')==='current','stale target migrated');}
ok952(documents_reconcile_receipts_for_quote($qid)['changed']===[],'idempotent');

$partial=['id'=>'partial','status'=>'final','quotation_id'=>'partial-project','amount_rs'=>100,'allocations'=>[['invoice_id'=>'missing','amount_rs'=>40]]];
$health=documents_receipt_allocation_health($partial,[]);ok952($health['missing_paise']===4000&&$health['unallocated_paise']===6000,'missing target and remainder classified');
$crossInvoice=['id'=>'foreign','status'=>'draft','linked_quote_id'=>'foreign-project','pricing'=>['final_invoice_total_incl_gst'=>100]];documents_save_invoice($crossInvoice);
$cross=['id'=>'cross','status'=>'final','quotation_id'=>$qid,'amount_rs'=>10,'allocations'=>[['invoice_id'=>'foreign','amount_rs'=>10]]];$h=documents_receipt_allocation_health($cross);ok952($h['health']==='invalid_cross_project','cross-project blocked');
$second=['id'=>'second','status'=>'draft','linked_quote_id'=>$qid,'pricing'=>['final_invoice_total_incl_gst'=>10]];documents_save_invoice($second);$amb=['id'=>'amb','status'=>'final','quotation_id'=>$qid,'amount_rs'=>10];documents_save_sales_document('receipt',$amb);ok952((documents_get_sales_document('receipt','amb')['allocations']??[])===[],'multiple active invoices never guessed');ok952(documents_receipt_allocation_health($amb)['requires_admin_split'],'administrator split required');
echo "issue_952_allocation_health_test passed\n";
