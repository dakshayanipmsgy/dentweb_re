<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/business_pulse_helpers.php';

$assertions=0; function ok789($cond,$msg){global $assertions; $assertions++; if(!$cond){fwrite(STDERR,"FAIL: $msg\n"); exit(1);} }
function eq789($a,$b,$msg){ ok789($a===$b,$msg.' got '.var_export($a,true).' expected '.var_export($b,true)); }
function money789($a,$b,$msg){ ok789(abs((float)$a-(float)$b)<0.01,$msg." got $a expected $b"); }

$stamp='789_'.bin2hex(random_bytes(3)); $qid='q_'.$stamp; $actor=['id'=>'u789','name'=>'Admin'];
$quote=['id'=>$qid,'quote_no'=>'Q-789','status'=>'accepted','is_current_version'=>true,'segment'=>'RES','customer_name'=>'Issue 789','customer_mobile'=>'9999999999','customer_snapshot'=>['name'=>'Issue 789','mobile'=>'9999999999'],'accepted_at'=>date('c'),'created_at'=>date('c'),'items'=>[['name'=>'Solar kit','qty'=>1,'price'=>100000]],'input_total_gst_inclusive'=>100000,'calc'=>['gross_payable'=>100000,'grand_total'=>100000,'final_price_incl_gst'=>100000],'workflow'=>['invoice_id'=>'missing_old','invoice_ids'=>['missing_old','missing_old']],'links'=>['invoice_id'=>'missing_old']];
documents_ensure_structure(); documents_save_quote($quote);
$createdFiles=[]; $salesBackup=is_file(documents_sales_receipts_store_path())?file_get_contents(documents_sales_receipts_store_path()):null;
try {
    $quote=documents_get_quote($qid); ok789(is_array($quote),'fixture quote saved');
    $can=documents_quote_can_create_invoice($quote); ok789($can['ok'],'1 first invoice can be created from accepted quotation despite missing stale workflow reference');
    $c1=documents_create_invoice_from_quote($quote,['idempotency_key'=>'tok1']); ok789($c1['ok'],'first create ok'); $inv1=documents_get_invoice($c1['invoice_id']); ok789(is_array($inv1),'3 invoice stored and viewable'); $createdFiles[]=$c1['invoice_id'];
    ok789(documents_invoice_is_draft($inv1),'new invoice is Draft'); ok789((string)$inv1['invoice_date']!=='','new invoice has explicit invoice date');
    documents_quote_link_workflow_doc($quote,'invoice',(string)$inv1['id']); $quote['workflow']['invoice_creation_tokens']['tok1']=(string)$inv1['id']; $quote=documents_quote_repair_invoice_workflow($quote); documents_save_quote($quote);
    $dup=documents_create_invoice_from_quote($quote,['idempotency_key'=>'tok1']); eq789($dup['invoice_id'],$inv1['id'],'17 duplicate submission returns existing invoice id'); ok789(!empty($dup['deduplicated']),'17 duplicate submission marked deduplicated');

    $fin=documents_invoice_finalize($inv1,$actor); ok789($fin['ok'],'2 invoice finalizes'); $inv1=$fin['invoice']; $can=documents_invoice_cancel($inv1,$actor,'Customer requested cancellation'); ok789($can['ok'] && documents_invoice_is_cancelled($can['invoice']),'2 finalized invoice can be cancelled with reason'); documents_save_invoice($can['invoice']); $inv1=$can['invoice'];
    ok789(documents_get_invoice((string)$inv1['id'])!==null,'3 cancelled invoice remains stored and viewable'); ok789(documents_active_invoices_for_quote($qid)===[],'4 cancelled invoice excluded from active invoice helper');
    $repair=documents_quote_repair_invoice_workflow($quote); $repair2=documents_quote_repair_invoice_workflow($repair); eq789($repair,$repair2,'15 workflow repair is idempotent'); ok789(in_array((string)$inv1['id'],$repair['workflow']['invoice_ids'],true),'11 workflow retains cancelled invoice id history'); ok789(!in_array((string)$inv1['id'],$repair['workflow']['active_invoice_ids'],true),'12 cancelled invoice not active/latest blocker');

    $c2=documents_create_invoice_from_quote($repair,['idempotency_key'=>'tok2','replacement_for_invoice_id'=>(string)$inv1['id'],'actor'=>$actor]); ok789($c2['ok'],'5 new draft invoice can be created after cancellation'); $inv2=documents_get_invoice($c2['invoice_id']); $createdFiles[]=$c2['invoice_id'];
    ok789((string)$inv2['id'] !== (string)$inv1['id'],'6 replacement has different ID'); ok789((string)$inv2['invoice_no'] !== (string)$inv1['invoice_no'],'7 replacement has different invoice number'); eq789((string)$inv2['replacement_for_invoice_id'],(string)$inv1['id'],'8 replacement references cancelled invoice'); eq789((string)documents_get_invoice((string)$inv1['id'])['replaced_by_invoice_id'],(string)$inv2['id'],'9 cancelled invoice references replacement after save'); ok789(documents_invoice_is_cancelled(documents_get_invoice((string)$inv1['id'])),'10 cancelled invoice not overwritten or reactivated');
    documents_quote_link_workflow_doc($repair,'invoice',(string)$inv2['id']); $repair=documents_quote_repair_invoice_workflow($repair); ok789(in_array((string)$inv1['id'],$repair['workflow']['invoice_ids'],true)&&in_array((string)$inv2['id'],$repair['workflow']['invoice_ids'],true),'11 workflow keeps all invoice ids'); eq789((string)$repair['workflow']['latest_invoice_id'],(string)$inv2['id'],'12 latest active invoice is replacement');
    $all=documents_invoices_for_quote($qid,true); ok789(count($all)>=2,'16 multiple invoices listed independently');

    $c3=documents_create_invoice_from_quote($repair,['idempotency_key'=>'tok3','exceed_reason'=>'Milestone billing approved']); ok789($c3['ok'],'18 legitimate create another invoice creates separate invoice'); $inv3=documents_get_invoice($c3['invoice_id']); $createdFiles[]=$c3['invoice_id']; ok789((string)$inv3['id'] !== (string)$inv2['id'],'18 another invoice has separate id');
    $summary=documents_quote_invoice_totals_summary($repair); money789($summary['active_invoice_total'],200000,'19 existing active totals shown before additional creation'); ok789($summary['would_exceed'],'20 aggregate warning detects over quotation total'); $blocked=documents_create_invoice_from_quote($repair,['idempotency_key'=>'tok4']); ok789(!$blocked['ok'] && str_contains($blocked['error'],'Reason is required'),'20 over total requires reason');

    $rpath=documents_sales_receipts_store_path(); json_save($rpath,[['id'=>'r_'.$stamp,'status'=>'final','quotation_id'=>$qid,'customer_mobile'=>'9999999999','amount_rs'=>50000,'allocations'=>[['invoice_id'=>(string)$inv1['id'],'amount_rs'=>50000]],'date_received'=>date('Y-m-d')]]);
    money789(documents_invoice_payment_summary($inv2)['total_received'],0,'23 replacement does not copy receipt allocations'); money789(documents_invoice_payment_summary($inv1)['total_received'],50000,'24 cancelled invoice allocation remains only on cancelled invoice, not double-counted');
    $fin2=documents_invoice_finalize($inv2,$actor)['invoice']; documents_save_invoice($fin2); $row=['quote'=>$quote,'qid'=>$qid,'amount'=>documents_invoice_final_total($fin2),'received'=>documents_invoice_payment_summary($fin2)['total_received'],'due'=>documents_invoice_final_total($fin2),'accepted_date'=>date('Y-m-d'),'last_payment_date'=>'']; money789($row['amount'],100000,'22 replacement finalized invoice counts once');
    ok789(documents_invoice_final_total($inv1)===100000.0,'28 invoice-owned totals preserved'); ok789(documents_invoice_payment_status($fin2)==='unpaid','29 lifecycle/payment summary preserved'); eq789(documents_invoice_authoritative_date($fin2),(string)$inv2['invoice_date'],'30 invoice-date behavior preserved');
    ok789((float)$quote['calc']['gross_payable']===100000.0,'27 linked quotation remains unchanged');
} finally {
    foreach(array_unique($createdFiles) as $id){ @unlink(documents_invoices_dir().'/'.safe_filename((string)$id).'.json'); }
    @unlink(documents_quotations_dir().'/'.safe_filename($qid).'.json');
    if($salesBackup===null) @unlink(documents_sales_receipts_store_path()); else file_put_contents(documents_sales_receipts_store_path(),$salesBackup);
}
echo "invoice_recreation_after_cancellation_test passed ($assertions assertions)\n";
