<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/includes/documents_helpers.php';

$apply = in_array('--apply', $argv, true);
$path = documents_sales_receipts_store_path();
$lockPath = $path . '.repair.lock';
$lock = fopen($lockPath, 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Receipt repair is already running.\n"); exit(2);
}

$rows = documents_read_sales_store($path);
$invoices = documents_all_invoices();
$byQuote = [];
foreach ($invoices as $invoice) {
    $qid=(string)($invoice['linked_quote_id']??$invoice['quotation_id']??'');
    if($qid!=='' && documents_invoice_is_active_for_quote($invoice))$byQuote[$qid][]=$invoice;
}
$changed=[]; $ambiguous=[];
$allocated=[];
foreach($rows as $existing){if(!is_array($existing)||!documents_receipt_is_finalized_active($existing))continue;foreach((array)($existing['allocations']??[]) as $a)if(is_array($a)){$iid=(string)($a['invoice_id']??'');$allocated[$iid]=($allocated[$iid]??0)+max(0,documents_invoice_money_to_paise((float)($a['amount_rs']??$a['amount']??0)));}}
foreach($rows as $index=>$receipt){
    if(!is_array($receipt)||!documents_receipt_is_finalized_active($receipt))continue;
    $qid=documents_receipt_quote_id($receipt); $raw=is_array($receipt['allocations']??null)?$receipt['allocations']:[];
    if($qid===''||$raw!==[])continue;
    $eligible=$byQuote[$qid]??[];
    if(count($eligible)!==1){$ambiguous[]=(string)($receipt['id']??'unknown');continue;}
    $iid=(string)($eligible[0]['id']??''); $amount=documents_invoice_money_to_paise(documents_receipt_amount_total($receipt));
    $capacity=documents_invoice_money_to_paise(documents_invoice_final_total($eligible[0]))-(int)($allocated[$iid]??0);
    if($amount<=0||$amount>$capacity){$ambiguous[]=(string)($receipt['id']??'unknown');continue;}
    $norm=documents_receipt_allocations_normalize($receipt,$invoices);
    if(empty($norm['ok'])||count($norm['allocations'])!==1){$ambiguous[]=(string)($receipt['id']??'unknown');continue;}
    $rows[$index]['quotation_id']=$qid; $rows[$index]['allocations']=$norm['allocations'];
    $rows[$index]['allocation_basis']='repair_single_active_invoice'; $rows[$index]['allocation_repaired_at']=date('c');
    $allocated[$iid]=($allocated[$iid]??0)+$amount;
    $changed[]=(string)($receipt['id']??'unknown');
}

echo ($apply?'APPLY':'DRY RUN').': '.count($changed).' unambiguous receipt(s); '.count($ambiguous)." require administrator allocation.\n";
if($apply && $changed!==[]){
    $backup=$path.'.backup.'.gmdate('YmdHis');
    if(!is_file($path)||!copy($path,$backup)){fwrite(STDERR,"Backup failed; no changes written.\n");exit(3);}
    $saved=json_save($path,$rows); if(empty($saved['ok'])){fwrite(STDERR,"Atomic write failed. Restore from $backup\n");exit(4);}
    echo "Backup: $backup\n";
}
foreach($ambiguous as $id)echo "UNALLOCATED: $id\n";
flock($lock,LOCK_UN); fclose($lock);
