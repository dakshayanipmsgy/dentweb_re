<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/business_pulse_helpers.php';

$assertions=0; function ok793($cond,$msg){global $assertions; $assertions++; if(!$cond){fwrite(STDERR,"FAIL: $msg\n"); exit(1);} }
function money793($a,$b,$msg){ ok793(abs((float)$a-(float)$b)<0.01,$msg." got $a expected $b"); }
function eq793($a,$b,$msg){ ok793($a===$b,$msg.' got '.var_export($a,true).' expected '.var_export($b,true)); }

$stamp='793_'.bin2hex(random_bytes(3)); $qid='q_'.$stamp; $actor=['id'=>'u793','name'=>'Admin'];
$quote=['id'=>$qid,'quote_no'=>'Q-793','status'=>'accepted','is_current_version'=>true,'segment'=>'RES','customer_name'=>'Issue 793','customer_mobile'=>'9888888888','accepted_at'=>'2026-07-15T10:00:00+00:00','created_at'=>'2026-07-15T09:00:00+00:00','input_total_gst_inclusive'=>272900,'calc'=>['gross_payable'=>272900,'grand_total'=>272900,'final_price_incl_gst'=>272900],'items'=>[['name'=>'Solar','qty'=>1,'price'=>272900]]];
documents_ensure_structure(); documents_save_quote($quote); $created=[]; $rpath=documents_sales_receipts_store_path(); $rbackup=is_file($rpath)?file_get_contents($rpath):null;
try {
    $quote=documents_get_quote($qid);
    $c1=documents_create_invoice_from_quote($quote,['idempotency_key'=>'793a']); ok793($c1['ok'],'invoice created');
    $inv1=documents_get_invoice($c1['invoice_id']); $created[]=$inv1['id'];
    $inv1=documents_invoice_recalculate_pricing($inv1,269000,'Final invoice discount')['invoice'];
    $inv1=documents_invoice_finalize($inv1,$actor)['invoice']; documents_save_invoice($inv1);
    json_save($rpath,[['id'=>'r_'.$stamp,'status'=>'final','quotation_id'=>$qid,'amount_rs'=>269000,'allocations'=>[['invoice_id'=>(string)$inv1['id'],'amount_rs'=>269000]],'date_received'=>'2026-07-15']]);
    $quote=documents_get_quote($qid); $s=documents_project_financial_summary($quote);
    $confirm=documents_project_confirm_calculation_basis($quote,'finalized_invoices',$actor,'Confirmed final invoice total',(string)$s['active_finalized_invoice_set_hash']); ok793($confirm['ok'],'basis confirmed'); $quote=$confirm['quote']; documents_save_quote($quote);

    $present=documents_project_financial_presentation($quote);
    money793($present['project_amount'],269000,'presentation project amount'); money793($present['received_amount'],269000,'presentation received'); money793($present['outstanding_amount'],0,'presentation outstanding'); money793($present['customer_credit'],0,'presentation credit'); eq793($present['calculation_basis'],'finalized_invoices','invoice basis'); eq793($present['basis_status'],'confirmed','basis confirmed status'); money793($present['collection_pct'],100.0,'collection 100'); ok793(!$present['has_receivable'],'no receivable'); eq793($present['due_since'],'','due-since blank'); money793($present['quotation_to_invoice_difference'],3900,'adjustment amount'); ok793(str_contains($present['quotation_to_invoice_difference_label'],'reduction'),'adjustment is reduction label');

    $pay=documents_payment_summary_for_quote($quote); money793($pay['quotation_amount'],269000,'payment request total project'); money793($pay['total_received'],269000,'payment request received'); money793($pay['outstanding'],0,'payment request outstanding/default max');
    $receiptRemaining=(float)$present['outstanding_amount']; money793($receiptRemaining,0,'payment receipt remaining receivable');

    $bpRows=business_pulse_accepted_rows(); $row=null; foreach($bpRows as $candidate){ if((string)$candidate['qid']===$qid){ $row=$candidate; break; } } ok793(is_array($row),'accepted customer row present'); money793($row['amount'],269000,'list row total'); money793($row['received'],269000,'list row received'); money793($row['due'],0,'list row due'); money793($row['pct'],100.0,'list row collection');

    $summary=business_pulse_summary(); ok793($summary['customers_with_dues']>=0,'summary produced'); $foundDue=false; foreach($summary['rows'] as $r){ if((string)$r['qid']===$qid && (float)$r['due']>0.009){ $foundDue=true; } } ok793(!$foundDue,'customer not counted with dues');

    $quoteBasis=documents_project_confirm_calculation_basis($quote,'quotation',$actor,'Customer settlement follows quotation',''); ok793($quoteBasis['ok'],'quotation basis can be selected'); $qp=documents_project_financial_presentation($quoteBasis['quote']); money793($qp['project_amount'],272900,'quotation basis project amount'); money793($qp['outstanding_amount'],3900,'quotation basis outstanding');

    $c2=documents_create_invoice_from_quote($quote,['idempotency_key'=>'793b','exceed_reason'=>'extra']); ok793($c2['ok'],'second invoice created'); $inv2=documents_get_invoice($c2['invoice_id']); $created[]=$inv2['id']; $inv2=documents_invoice_recalculate_pricing($inv2,1000,'Extra')['invoice']; $inv2=documents_invoice_finalize($inv2,$actor)['invoice']; documents_save_invoice($inv2);
    $stale=documents_project_financial_presentation($quote); eq793($stale['basis_status'],'needs_reconfirmation','needs reconfirmation shown'); money793($stale['project_amount'],269000,'stale keeps confirmed reference'); ok793((string)$stale['needs_reconfirmation_warning']!=='','stale warning displayed');
    money793(documents_invoice_payment_summary($inv1)['total_received'],269000,'invoice allocation remains invoice-specific');
} finally {
    foreach(array_unique(array_map('strval',$created)) as $id){ @unlink(documents_invoices_dir().'/'.safe_filename($id).'.json'); }
    @unlink(documents_quotations_dir().'/'.safe_filename($qid).'.json');
    if($rbackup===null) @unlink($rpath); else file_put_contents($rpath,$rbackup);
}
echo "project_invoice_basis_consumer_consistency_test passed ($assertions assertions)\n";
