<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/admin/includes/documents_helpers.php';

$apply=in_array('--apply',$argv,true);$quoteFilter='';foreach($argv as $arg)if(str_starts_with($arg,'--quotation-id='))$quoteFilter=safe_text(substr($arg,15));
$quoteIds=[];foreach(documents_receipt_ledger() as $receipt){$qid=documents_receipt_quote_id($receipt);if($qid!==''&&($quoteFilter===''||$qid===$quoteFilter))$quoteIds[$qid]=true;}ksort($quoteIds);
$plans=[];$categories=[];$count=0;foreach(array_keys($quoteIds) as $qid){$plan=documents_payment_allocation_repair_plan($qid);$plans[$qid]=$plan;if(!empty($plan['can_repair']))$count+=(int)$plan['affected_count'];foreach($plan['categories'] as $category=>$n)$categories[$category]=($categories[$category]??0)+(int)$n;}
echo ($apply?'APPLY':'DRY RUN').': '.$count." unambiguous receipt record(s) would change.\n";ksort($categories);foreach($categories as $category=>$n)echo strtoupper($category).': '.$n."\n";
if(!$apply)exit(0);
foreach($plans as $qid=>$plan){if(empty($plan['can_repair']))continue;$result=documents_apply_payment_allocation_repair($qid,(string)$plan['state_hash'],['id'=>'cli-administrator']);if(empty($result['ok'])){fwrite(STDERR,'Repair refused: '.(string)($result['error']??'unknown error')."\n");exit(3);}echo 'Backup: '.(string)$result['backup']."\n";echo "Rollback: stop receipt and invoice writes, validate the recovery reference, then atomically restore it to the receipt store.\n";}
