<?php
declare(strict_types=1);

$tmp=sys_get_temp_dir().'/dentweb_956_'.bin2hex(random_bytes(5));putenv('DOCUMENTS_BASE_DIR='.$tmp);
require_once __DIR__.'/../admin/includes/documents_helpers.php';
function ok956($condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}

documents_ensure_structure();
$project='manual-project';
documents_save_invoice(['id'=>'first','status'=>'draft','linked_quote_id'=>$project,'pricing'=>['final_invoice_total_incl_gst'=>100]]);
documents_save_invoice(['id'=>'second','status'=>'draft','linked_quote_id'=>$project,'pricing'=>['final_invoice_total_incl_gst'=>100]]);
documents_save_invoice(['id'=>'foreign','status'=>'draft','linked_quote_id'=>'another-project','pricing'=>['final_invoice_total_incl_gst'=>100]]);
$receipt=['id'=>'manual-receipt','status'=>'finalized','quotation_id'=>$project,'amount_rs'=>100,'payment_mode'=>'bank','reference_no'=>'immutable'];
json_save(documents_sales_receipts_store_path(),[$receipt]);

$badPrecision=documents_allocate_receipt('manual-receipt',['first'=>'10.001'],['id'=>'admin']);
ok956(!$badPrecision['ok'],'sub-paise input is rejected instead of rounded');
$cross=documents_allocate_receipt('manual-receipt',['foreign'=>'10.00'],['id'=>'admin']);
ok956(!$cross['ok'],'cross-project target is rejected');

$result=documents_allocate_receipt('manual-receipt',['first'=>'60.00','second'=>'30.00'],['id'=>'admin']);
ok956($result['ok']&&$result['unallocated']===10.0,'valid split is saved and project credit remains visible');
ok956(is_file($result['backup'])&&is_file($result['audit']),'validated recovery copy and immutable audit record are written');
$saved=documents_get_sales_document('receipt','manual-receipt');
ok956(($saved['amount_rs']??null)===100&&($saved['payment_mode']??'')==='bank'&&($saved['reference_no']??'')==='immutable','receipt financial facts are unchanged');
ok956(count($saved['allocations']??[])===2,'both explicit invoice allocations persist');

$overCapacity=documents_allocate_receipt('manual-receipt',['first'=>'101.00'],['id'=>'admin']);
ok956(!$overCapacity['ok'],'invoice capacity is enforced from the locked snapshot');
echo "issue_956_manual_allocation_safety_test passed\n";
