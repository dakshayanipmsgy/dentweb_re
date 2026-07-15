<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/business_pulse_helpers.php';

$assertions=0; function ok791($cond,$msg){global $assertions; $assertions++; if(!$cond){fwrite(STDERR,"FAIL: $msg\n"); exit(1);} }
function money791($a,$b,$msg){ ok791(abs((float)$a-(float)$b)<0.01,$msg." got $a expected $b"); }
function eq791($a,$b,$msg){ ok791($a===$b,$msg.' got '.var_export($a,true).' expected '.var_export($b,true)); }

$stamp='791_'.bin2hex(random_bytes(3)); $qid='q_'.$stamp; $actor=['id'=>'u791','name'=>'Admin'];
$quote=['id'=>$qid,'quote_no'=>'Q-791','status'=>'accepted','is_current_version'=>true,'segment'=>'RES','customer_name'=>'Issue 791','customer_mobile'=>'9999999999','accepted_at'=>date('c'),'created_at'=>date('c'),'input_total_gst_inclusive'=>300000,'calc'=>['gross_payable'=>300000,'grand_total'=>300000,'final_price_incl_gst'=>300000],'items'=>[['name'=>'Solar','qty'=>1,'price'=>300000]]];
documents_ensure_structure(); documents_save_quote($quote); $created=[]; $rpath=documents_sales_receipts_store_path(); $rbackup=is_file($rpath)?file_get_contents($rpath):null;
try {
    $quote=documents_get_quote($qid); $s=documents_project_financial_summary($quote); money791($s['quotation_amount'],300000,'1 no invoices keeps quotation amount'); eq791($s['calculation_basis'],'quotation','1 defaults to quotation basis'); money791($s['remaining_amount'],300000,'1 remaining uses quotation');

    $c1=documents_create_invoice_from_quote($quote,['idempotency_key'=>'a']); ok791($c1['ok'],'draft invoice created'); $inv1=documents_get_invoice($c1['invoice_id']); $created[]=$inv1['id'];
    $s=documents_project_financial_summary($quote); money791($s['active_finalized_invoice_total'],0,'2 draft invoice excluded'); eq791($s['calculation_basis'],'quotation','2 draft does not switch');
    $inv1=documents_invoice_recalculate_pricing($inv1,190000,'Milestone 1')['invoice']; $inv1=documents_invoice_finalize($inv1,$actor)['invoice']; documents_save_invoice($inv1);
    $quote=documents_get_quote($qid); $s=documents_project_financial_summary($quote); money791($s['active_finalized_invoice_total'],190000,'3 finalized comparison total shown'); money791($s['remaining_by_quotation'],300000,'3 quotation remaining shown'); money791($s['remaining_by_finalized_invoices'],190000,'3 invoice remaining shown'); eq791($s['calculation_basis'],'quotation','3 finalization does not silently switch');

    json_save($rpath,[['id'=>'r1_'.$stamp,'status'=>'final','quotation_id'=>$qid,'amount_rs'=>250000,'allocations'=>[['invoice_id'=>(string)$inv1['id'],'amount_rs'=>190000]],'date_received'=>'2026-07-15'],['id'=>'r1_'.$stamp,'status'=>'final','quotation_id'=>$qid,'amount_rs'=>999999]]);
    $s=documents_project_financial_summary($quote); money791($s['total_payment_received'],250000,'4 duplicate receipt IDs count once'); money791($s['allocated_payment_received'],190000,'4 allocated shown separately'); money791($s['unallocated_payment_received'],60000,'4 unallocated shown separately');

    $confirm=documents_project_confirm_calculation_basis($quote,'finalized_invoices',$actor,'Admin confirmed final invoices',(string)$s['active_finalized_invoice_set_hash']); ok791($confirm['ok'],'5 admin confirms finalized invoices'); $quote=$confirm['quote']; documents_save_quote($quote);
    $s=documents_project_financial_summary($quote); eq791($s['calculation_basis'],'finalized_invoices','5 basis stored'); eq791($s['basis_status'],'confirmed','5 status confirmed'); money791($s['calculation_reference_amount'],190000,'5 confirmed reference snapshot'); money791($s['overpayment'],60000,'5 credit against confirmed amount'); money791(documents_payment_summary_for_quote($quote)['outstanding'],0,'5 consumers use confirmed basis remaining'); ok791(!empty($quote['commercial_settlement']['included_invoice_ids']) && !empty($quote['commercial_settlement']['invoice_set_hash']),'5 snapshot includes invoice IDs and hash');
    money791(documents_invoice_payment_summary($inv1)['total_received'],190000,'6 invoice-specific summary still uses allocations');

    $c2=documents_create_invoice_from_quote($quote,['idempotency_key'=>'b','exceed_reason'=>'Stage 2']); ok791($c2['ok'],'7 multiple invoice draft can be created'); $inv2=documents_get_invoice($c2['invoice_id']); $created[]=$inv2['id']; $inv2=documents_invoice_recalculate_pricing($inv2,100000,'Stage 2')['invoice']; $inv2=documents_invoice_finalize($inv2,$actor)['invoice']; documents_save_invoice($inv2); $quote=documents_get_quote($qid); $s=documents_project_financial_summary($quote); money791($s['active_finalized_invoice_total'],290000,'7 multiple finalized invoices sum once'); eq791($s['basis_status'],'needs_reconfirmation','8 changed invoice set needs reconfirmation'); money791($s['calculation_reference_amount'],190000,'8 stale confirmed amount preserved');
    $stale=documents_project_confirm_calculation_basis($quote,'finalized_invoices',$actor,'stale',(string)$quote['commercial_settlement']['invoice_set_hash']); ok791(!$stale['ok'] && str_contains($stale['error'],'changed'),'9 stale confirmation rejected');
    $reconfirm=documents_project_confirm_calculation_basis($quote,'finalized_invoices',$actor,'Reconfirmed after stage 2',(string)$s['active_finalized_invoice_set_hash']); ok791($reconfirm['ok'],'10 reconfirm succeeds'); $quote=$reconfirm['quote']; money791(documents_project_financial_summary($quote)['remaining_amount'],40000,'10 remaining by reconfirmed invoices');

    $back=documents_project_confirm_calculation_basis($quote,'quotation',$actor,'',''); ok791(!$back['ok'],'11 switching back requires reason'); $back=documents_project_confirm_calculation_basis($quote,'quotation',$actor,'Customer settlement follows original quotation',''); ok791($back['ok'],'11 switching back with reason succeeds'); money791(documents_project_financial_summary($back['quote'])['remaining_amount'],50000,'11 quotation basis remaining restored');

    $cancel=documents_invoice_cancel($inv2,$actor,'Cancelled stage'); documents_save_invoice($cancel['invoice']); $s=documents_project_financial_summary($reconfirm['quote']); money791($s['active_finalized_invoice_total'],190000,'12 cancelled invoice excluded'); ok791($s['basis_status']==='needs_reconfirmation','12 cancellation invalidates invoice basis');
    ok791((float)documents_get_quote($qid)['calc']['gross_payable']===300000.0,'13 quotation pricing unchanged'); ok791(documents_invoice_authoritative_date($inv1)!=='','14 issue 786 invoice date preserved'); ok791(documents_invoice_final_total($inv1)===190000.0,'14 issue 782 invoice total independent'); ok791(documents_invoice_is_finalized($inv1),'14 issue 784 finalization preserved'); ok791((string)$inv2['id'] !== (string)$inv1['id'],'14 issue 789 multiple/replacement IDs independent');
} finally {
    foreach(array_unique(array_map('strval',$created)) as $id){ @unlink(documents_invoices_dir().'/'.safe_filename($id).'.json'); }
    @unlink(documents_quotations_dir().'/'.safe_filename($qid).'.json');
    if($rbackup===null) @unlink($rpath); else file_put_contents($rpath,$rbackup);
}
echo "project_invoice_calculation_basis_test passed ($assertions assertions)\n";
