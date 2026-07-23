<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$assertions=0;
function ok796($condition,string $message):void { global $assertions; $assertions++; if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);} }

$stamp='796_'.bin2hex(random_bytes(3)); $qid='q_'.$stamp; $actor=['id'=>'admin796','name'=>'Workflow Admin'];
$quote=['id'=>$qid,'quote_no'=>'Q-796','status'=>'accepted','is_current_version'=>true,'customer_name'=>'Completed Customer','calc'=>['gross_payable'=>100000]];
$receiptPath=documents_sales_receipts_store_path(); $backup=is_file($receiptPath)?file_get_contents($receiptPath):null;
documents_ensure_structure(); documents_save_quote($quote);
try {
    ok796(documents_project_completion_state($quote)==='pending','new accepted project is pending, not auto-completed');
    json_save($receiptPath,[['id'=>'r_'.$stamp,'status'=>'final','quotation_id'=>$qid,'amount_rs'=>100000,'allocations'=>[]]]);
    $quote=documents_get_quote($qid); $review=documents_project_completion_review($quote);
    ok796($review['can_complete']===true,'settled active quotation basis can be explicitly completed');
    ok796(documents_project_completion_state($quote)==='pending','100 percent payment alone does not complete project');
    $result=documents_project_mark_completed($quote,$actor,'Site commissioned'); ok796($result['ok'],'explicit completion succeeds');
    $completed=$result['quote']; $snapshot=$completed['project_completion']['snapshot'];
    ok796(documents_project_completion_state($completed)==='completed','completed state stored');
    ok796(($completed['project_completion']['completed_by']['id']??'')==='admin796' && ($completed['project_completion']['completed_at']??'')!=='','actor and timestamp stored');
    ok796(($completed['project_completion']['note']??'')==='Site commissioned','completion note stored');
    foreach(['active_invoice_snapshot','calculation_basis','reference_amount','paid_amount','outstanding','overpayment'] as $key) ok796(array_key_exists($key,$snapshot),"snapshot stores $key");
    documents_save_quote($completed);
    json_save($receiptPath,[['id'=>'r_'.$stamp,'status'=>'final','quotation_id'=>$qid,'amount_rs'=>90000,'allocations'=>[]]]);
    $changed=documents_project_completion_review($completed); ok796($changed['financial_data_changed']===true,'post-completion finance change requires review');
    ok796((float)$completed['project_completion']['snapshot']['paid_amount']===100000.0,'stored completion snapshot remains unchanged');
    $bad=documents_project_reopen($completed,$actor,''); ok796(!$bad['ok'],'reopening requires a reason');
    $reopened=documents_project_reopen($completed,$actor,'Receipt correction review'); ok796($reopened['ok'] && documents_project_completion_state($reopened['quote'])==='reopened','controlled reopen succeeds');
    $events=$reopened['quote']['project_completion_audit']; ok796(($events[count($events)-1]['event']??'')==='project_reopened','reopen audit event stored');
    $stale=$quote; $stale['commercial_settlement']=['basis'=>'finalized_invoices','status'=>'confirmed','confirmed_reference_amount'=>100000,'invoice_set_hash'=>'changed'];
    ok796(documents_project_completion_review($stale)['can_complete']===false,'needs-reconfirmation basis cannot complete');
} finally {
    @unlink(documents_quotations_dir().'/'.safe_filename($qid).'.json');
    if($backup===null) @unlink($receiptPath); else file_put_contents($receiptPath,$backup);
}
echo "site_completed_customers_workflow_test passed ($assertions assertions)\n";
