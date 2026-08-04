<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/admin/includes/documents_helpers.php';

$apply=in_array('--apply',$argv,true); $quoteFilter='';
foreach($argv as $arg)if(str_starts_with($arg,'--quotation-id='))$quoteFilter=safe_text(substr($arg,15));
$path=documents_sales_receipts_store_path(); $lock=@fopen($path.'.lock','c+');
if(!is_resource($lock)||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Receipt store is busy; no changes made.\n");exit(2);}
try {
    // Re-read only after acquiring the same lock used by every receipt writer.
    $rows=documents_read_sales_store($path); $invoices=documents_all_invoices(); $changed=[];$categories=[];
    $allocated=[]; foreach(documents_receipt_ledger(null,$rows) as $r)foreach(documents_receipt_allocation_health($r,$invoices)['allocations'] as $a)if($a['health']==='valid_current')$allocated[$a['invoice_id']]=($allocated[$a['invoice_id']]??0)+(int)$a['amount_paise'];
    foreach($rows as $index=>$receipt){if(!is_array($receipt)||!documents_receipt_is_finalized_active($receipt))continue;$qid=documents_receipt_quote_id($receipt);if($qid===''||($quoteFilter!==''&&$qid!==$quoteFilter))continue;
        $health=documents_receipt_allocation_health($receipt,$invoices);$categories[$health['health']]=($categories[$health['health']]??0)+1;$resolved=documents_resolve_active_invoices($qid,$invoices);
        if($resolved['state']!=='single'||$health['cross_project_paise']>0||$health['overallocated_paise']>0||$health['recoverable_paise']<=0)continue;
        $invoice=$resolved['invoice'];$iid=(string)$invoice['id'];$capacity=max(0,documents_invoice_money_to_paise(documents_invoice_final_total($invoice))-($allocated[$iid]??0));$move=min($capacity,(int)$health['recoverable_paise']);if($move<=0)continue;
        $keep=0;foreach($health['allocations'] as $a)if($a['health']==='valid_current'&&$a['invoice_id']===$iid)$keep+=(int)$a['amount_paise'];$before=is_array($receipt['allocations']??null)?$receipt['allocations']:[];
        $rows[$index]['allocations']=[['invoice_id'=>$iid,'amount_rs'=>documents_invoice_paise_to_money($keep+$move)]];$rows[$index]['allocation_migration_audit'][]=['at'=>date('c'),'reason'=>'repair_single_active_invoice','previous_allocations'=>$before,'target_invoice_id'=>$iid,'migrated_amount_paise'=>$move];$rows[$index]['allocation_repaired_at']=date('c');$allocated[$iid]=($allocated[$iid]??0)+$move;$changed[]=$index;
    }
    echo ($apply?'APPLY':'DRY RUN').': '.count($changed)." unambiguous receipt record(s) would change.\n";ksort($categories);foreach($categories as $category=>$count)echo strtoupper($category).": $count\n";
    if($apply&&$changed!==[]){$stamp=gmdate('Ymd\THis\Z');$backup=$path.'.backup.'.$stamp;if(!is_file($path)||!copy($path,$backup)){fwrite(STDERR,"Backup failed; no changes written.\n");exit(3);}$saved=json_save($path,$rows);if(empty($saved['ok'])){fwrite(STDERR,"Atomic write failed. Roll back with: cp '$backup' '$path'\n");exit(4);}echo "Backup: $backup\nRollback: stop receipt writes, then cp '$backup' '$path'\n";}
} finally {flock($lock,LOCK_UN);fclose($lock);}
