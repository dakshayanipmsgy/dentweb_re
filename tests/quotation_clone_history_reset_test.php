<?php declare(strict_types=1);
require_once __DIR__.'/../admin/includes/documents_helpers.php';
require_once __DIR__.'/../includes/quotation_view_renderer.php';
function clone_history_assert($v,$m){if(!$v)throw new RuntimeException($m);}
$source=[
    'id'=>'old','quote_no'=>'DQ-OLD','status'=>'accepted','customer_name'=>'Jane Customer','system_type'=>'Ongrid','system_capacity_kwp'=>5,
    'base_price'=>'250000','finance_enabled'=>true,'quote_items'=>[['name'=>'Solar panel','qty'=>2,'unit_price'=>1000]],
    'panel_orientation'=>['mode'=>'custom','panels'=>[['x'=>1,'y'=>2,'rotation'=>90]]],
    'notes'=>'Keep this business note','important_points_snapshot'=>['enabled'=>true,'items'=>[['text'=>'Keep point','enabled'=>true]]],
    'customer_visible_change_history'=>[['event'=>'approved','message'=>'Revised quotation approved and ready for review.','recorded_at'=>'2024-01-02T03:04:05+00:00','visible_to_customer'=>true]],
    'revision_history'=>[['old'=>'history']],'change_history'=>[['old'=>'change']],'history_events'=>[['old'=>'event']],'revision_events'=>[['old'=>'revision']],
    'change_request_events'=>[['old'=>'request']],'customer_change_request'=>['request_ref'=>'REQ-OLD'],'requested_changes'=>'Old request','request_ref'=>'REQ-OLD',
    'generated_draft_revision_id'=>'draft-old','generated_draft_revision_no'=>'DQ-REV','generated_from_change_request_ref'=>'REQ-OLD',
    'revised_from_quote_id'=>'parent','revised_from_quote_no'=>'DQ-PARENT','revision_parent_id'=>'parent','revision_source_id'=>'source',
    'source_change_request_ref'=>'REQ-OLD','superseded_by_quote_id'=>'next','supersedes_quote_id'=>'prev','is_current_version'=>false,
    'accepted_at'=>'2024-01-03T00:00:00+00:00','accepted_by'=>['name'=>'Old Customer'],'customer_acceptance'=>['confirmed_at'=>'2024-01-03'],
    'customer_acceptance_request'=>['token_hash'=>'secret'],'acceptance_evidence'=>['ip'=>'127.0.0.1'],'share_audit'=>[['sent'=>true]],
    'locked_flag'=>true,'locked_at'=>'2024-01-03','workflow'=>['invoice_id'=>'inv-old'],'links'=>['invoice_id'=>'inv-old'],
];
$clone=documents_quote_reset_clone_state($source,'new-id');
$clone['id']='new-id';
$clone['quote_no']='DQ-NEW';
$clone['cloned_from_quote_id']='old';
$clone['cloned_from_quote_no']='DQ-OLD';
clone_history_assert($clone['quote_no']==='DQ-NEW','clone has new quote number');
clone_history_assert($clone['status']==='draft','clone is draft');
foreach(['customer_visible_change_history','revision_history','change_history','history_events','revision_events','change_request_events','revision_child_ids'] as $field){
    clone_history_assert(($clone[$field]??[])===[], "$field reset");
}
foreach(['customer_change_request','requested_changes','request_ref','generated_draft_revision_id','generated_draft_revision_no','revision_parent_id','revision_source_id','source_change_request_ref','customer_acceptance','customer_acceptance_request','acceptance_evidence','share_audit'] as $field){
    clone_history_assert(!isset($clone[$field]), "$field removed");
}
clone_history_assert(($clone['revised_from_quote_id']??null)===null && ($clone['revised_from_quote_no']??'')==='', 'revision lineage reset');
clone_history_assert(($clone['generated_from_change_request_ref']??'')==='' && ($clone['superseded_by_quote_id']??'')==='' && ($clone['supersedes_quote_id']??'')==='', 'revision pointers reset');
clone_history_assert(($clone['accepted_at']??'')==='' && empty($clone['locked_flag']), 'acceptance and lock reset');
clone_history_assert(($clone['workflow']['invoice_id']??'')==='' && ($clone['links']['invoice_id']??'')==='', 'workflow links reset');
clone_history_assert(($clone['customer_name']??'')==='Jane Customer' && ($clone['base_price']??'')==='250000' && count($clone['quote_items'])===1, 'business content preserved');
clone_history_assert(($clone['panel_orientation']['panels'][0]['rotation']??null)===90 && ($clone['notes']??'')==='Keep this business note', 'layout and notes preserved');
ob_start();
quotation_render($clone, ['important_points'=>['enabled'=>true,'items'=>[]]], ['name'=>'Dakshayani Enterprises'], false, '', 'public');
$html=ob_get_clean();
clone_history_assert(strpos($html,'Revised quotation approved and ready for review.')===false, 'old history message not rendered');
clone_history_assert(strpos($html,'2024-01-02T03:04:05+00:00')===false, 'old history timestamp not rendered');
clone_history_assert(strpos($html,'Quotation Revision / Change History')===false, 'empty clone history section hidden');
$dirty=$clone;
$dirty['customer_visible_change_history']=$source['customer_visible_change_history'];
$dirty['customer_change_request']=$source['customer_change_request'];
$dirty['revised_from_quote_id']='old';
$repaired=documents_quote_repair_clone_history($dirty);
clone_history_assert(($repaired['customer_visible_change_history']??[])===[] && !isset($repaired['customer_change_request']) && ($repaired['revised_from_quote_id']??null)===null, 'manual clone repair removes copied history');
$revision=documents_quote_append_customer_visible_history(['id'=>'rev','revised_from_quote_id'=>'old','status'=>'draft'], 'revision_created', 'A revised quotation draft was created for your requested changes.', ['request_ref'=>'REQ-NEW']);
clone_history_assert(count(documents_quote_customer_visible_history($revision))===1, 'true revision history remains when not marked clone');
echo "quotation clone history reset tests passed\n";
