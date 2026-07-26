<?php
declare(strict_types=1);

require_once __DIR__ . '/quotation_contact_csv.php';

/** Build connected review groups from active quotations only. */
function documents_similar_names_scan(array $quotes): array
{
    $active = [];
    foreach ($quotes as $quote) {
        if (!is_array($quote) || documents_is_archived($quote)) { continue; }
        $id = safe_text((string)($quote['id'] ?? ''));
        if ($id !== '' && documents_contact_normalize_name((string)($quote['customer_name'] ?? '')) !== '') { $active[$id] = $quote; }
    }
    $ids = array_keys($active); sort($ids, SORT_STRING); $edges = []; $scores = [];
    for ($i=0,$n=count($ids); $i<$n; $i++) for ($j=$i+1; $j<$n; $j++) {
        $score = documents_contact_similarity((string)$active[$ids[$i]]['customer_name'], (string)$active[$ids[$j]]['customer_name']);
        if ($score < 60) { continue; }
        $edges[$ids[$i]][]=$ids[$j]; $edges[$ids[$j]][]=$ids[$i]; $scores[$ids[$i]][$ids[$j]]=$scores[$ids[$j]][$ids[$i]]=$score;
    }
    $groups=[]; $seen=[];
    foreach ($ids as $id) {
        if (isset($seen[$id]) || empty($edges[$id])) continue;
        $queue=[$id]; $members=[]; $seen[$id]=true;
        while ($queue) { $cur=array_shift($queue); $members[]=$cur; foreach($edges[$cur]??[] as $next) if(!isset($seen[$next])){$seen[$next]=true;$queue[]=$next;} }
        sort($members,SORT_STRING); $rows=[];
        foreach($members as $member) {
            $q=$active[$member]; $best=0; foreach($members as $other) if($other!==$member)$best=max($best,(int)($scores[$member][$other]??0));
            $rows[]=documents_similar_names_review_row($q,$best);
        }
        $groups[]=['group_id'=>substr(hash('sha256',implode('|',$members)),0,16),'quotations'=>$rows];
    }
    return $groups;
}

function documents_similar_names_review_row(array $q, int $score): array
{
    $links=is_array($q['links']??null)?$q['links']:[]; $snapshot=is_array($q['customer_snapshot']??null)?$q['customer_snapshot']:[];
    $documentIds=[]; foreach(['agreement_id','proforma_id','challan_id','dispatch_advice_id'] as $key) if(trim((string)($links[$key]??''))!=='')$documentIds[]=(string)$links[$key];
    $invoiceIds=[]; foreach((array)($links['invoice_ids']??[]) as $v) if((string)$v!=='')$invoiceIds[]=(string)$v;
    if(is_string($links['invoice_id']??null)&&$links['invoice_id']!=='')$invoiceIds[]=$links['invoice_id'];
    $receipts=array_values(array_filter(array_map('strval',(array)($links['receipt_ids']??[]))));
    $warnings=[];
    if($receipts!==[] || !empty($q['payment_status']) || (float)($q['amount_paid']??0)>0)$warnings[]='Payment activity';
    if(strtolower((string)($q['project_status']??$q['site_status']??''))==='completed' || !empty($q['site_completed']))$warnings[]='Site Completed';
    $customerLink=documents_project_customer_user_link($q);
    return ['id'=>(string)$q['id'],'reference'=>(string)($q['quote_no']??$q['id']),'status'=>documents_status_label($q,'admin'),
      'customer_name'=>(string)($q['customer_name']??''),'mobile'=>(string)($q['customer_mobile']??''),'city_address'=>(string)($q['customer_city']??$snapshot['city']??$q['customer_address']??$snapshot['address']??''),
      'created_at'=>(string)($q['created_at']??''),'creator'=>(string)($q['created_by_name']??($q['created_by']['name']??'')),
      'amount'=>(float)($q['calc']['grand_total']??$q['calc']['gross_payable']??$q['input_total_gst_inclusive']??0),
      'public_share'=>!empty($q['public_share_enabled']),'customer_user'=>(string)($customerLink['label']??'Not linked'),
      'invoices'=>array_values(array_unique($invoiceIds)),'receipts'=>$receipts,'documents'=>array_values(array_unique($documentIds)),
      'warnings'=>$warnings,'score'=>$score,'band'=>documents_contact_score_band($score),'version'=>(string)($q['updated_at']??$q['created_at']??'')];
}

function documents_similar_names_stage_dir(): string { return documents_settings_dir().'/quotation-similar-name-cleanup'; }
function documents_similar_names_stage_save(array $groups, string $adminId): array
{
    documents_ensure_dir(documents_similar_names_stage_dir()); $id=bin2hex(random_bytes(16));
    $stage=['id'=>$id,'admin_id'=>$adminId,'state'=>'review','created_at'=>date('c'),'expires_at'=>time()+3600,'groups'=>$groups,'decisions'=>[]];
    $r=json_save(documents_similar_names_stage_dir().'/'.$id.'.json',$stage); return !empty($r['ok'])?$stage:[];
}
function documents_similar_names_stage_load(string $id,string $adminId): ?array
{
    $id=preg_replace('/[^a-f0-9]/','',$id)??''; $s=json_load(documents_similar_names_stage_dir().'/'.$id.'.json',[]);
    return is_array($s)&&($s['admin_id']??'')===$adminId&&(int)($s['expires_at']??0)>=time()?$s:null;
}
function documents_similar_names_stage_confirm(array $stage,array $decisions): array
{
    $valid=[]; foreach($stage['groups'] as $g)foreach($g['quotations'] as $q){$choice=(string)($decisions[$q['id']]??'ignore');$valid[$q['id']]=in_array($choice,['keep','archive','ignore'],true)?$choice:'ignore';}
    $stage['decisions']=$valid;$stage['state']='confirm'; json_save(documents_similar_names_stage_dir().'/'.$stage['id'].'.json',$stage); return $stage;
}

function documents_similar_names_apply(array $stage,string $reason,array $actor,string $batchId): array
{
    $out=['archived'=>[],'kept'=>[],'skipped'=>[],'conflicted'=>[],'failed'=>[]];
    if(trim($reason)===''){ $out['failed'][]='Archive reason is required.'; return $out; }
    if(!preg_match('/^[A-Za-z0-9_-]{8,80}$/',$batchId)){ $out['failed'][]='Invalid batch ID.'; return $out; }
    $rows=[];foreach($stage['groups'] as $g)foreach($g['quotations'] as $q)$rows[$q['id']]=$q;
    foreach((array)($stage['decisions']??[]) as $id=>$choice){
        if($choice==='keep'){$out['kept'][]=$id;continue;} if($choice!=='archive'){$out['skipped'][]=$id;continue;}
        $lock=@fopen(documents_quotations_dir().'/'.safe_filename((string)$id).'.similar-names.lock','c+');
        if(!is_resource($lock)||!flock($lock,LOCK_EX|LOCK_NB)){$out['conflicted'][]=$id; if(is_resource($lock))fclose($lock);continue;}
        try{$q=documents_get_quote((string)$id);if($q===null){$out['failed'][]=$id;continue;}if(documents_is_archived($q)){$out['skipped'][]=$id;continue;}
            foreach((array)($q['similar_name_cleanup_audit']??[]) as $event)if(($event['batch_id']??'')===$batchId){$out['archived'][]=$id;continue 2;}
            $version=(string)($q['updated_at']??$q['created_at']??'');if(!isset($rows[$id])||!hash_equals((string)$rows[$id]['version'],$version)){$out['conflicted'][]=$id;continue;}
            $q['similar_name_cleanup_audit'][]=['batch_id'=>$batchId,'reason'=>trim($reason),'at'=>date('c'),'actor'=>$actor];
            $r=documents_quote_apply_admin_status_transition($q,'archived',$actor);if(!empty($r['ok']))$out['archived'][]=$id;else $out['failed'][]=$id;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    } return $out;
}
