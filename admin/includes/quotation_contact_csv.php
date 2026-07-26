<?php
declare(strict_types=1);

/** Pure parsing/matching and locked batch application for issue #823. */
function documents_contact_normalize_name(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/^[\s]*(mr|mrs|ms|miss|dr|shri|smt|prof)\.?[\s]+/i', '', $name) ?? $name;
    $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;
    return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
}

function documents_contact_similarity(string $left, string $right): int
{
    $a = documents_contact_normalize_name($left); $b = documents_contact_normalize_name($right);
    if ($a === '' || $b === '') { return 0; }
    if ($a === $b) { return 100; }
    $max = max(strlen($a), strlen($b));
    $lev = $max ? (1 - levenshtein($a, $b) / $max) * 100 : 0;
    $at = array_values(array_unique(explode(' ', $a))); $bt = array_values(array_unique(explode(' ', $b)));
    $union = array_unique(array_merge($at, $bt));
    $token = $union ? count(array_intersect($at, $bt)) / count($union) * 100 : 0;
    return (int) round(max(0, min(100, $lev * .6 + $token * .4)));
}

function documents_contact_score_band(int $score): string
{
    return $score >= 90 ? 'strong' : ($score >= 75 ? 'likely' : ($score >= 60 ? 'weak' : 'below threshold'));
}

/** @return array{ok:bool,rows:array,errors:array} */
function documents_contact_parse_csv(string $contents, int $maxRows = 1000, int $maxBytes = 1048576): array
{
    $errors=[]; $rows=[];
    if (strlen($contents) > $maxBytes) { return ['ok'=>false,'rows'=>[],'errors'=>['CSV exceeds the 1 MB file limit.']]; }
    $stream=fopen('php://temp','w+b'); fwrite($stream, $contents); rewind($stream);
    $header=fgetcsv($stream);
    if (isset($header[0])) { $header[0]=preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]); }
    $header=is_array($header)?array_map(static fn($v)=>strtolower(trim((string)$v)),$header):[];
    if ($header !== ['name','mobile']) { fclose($stream); return ['ok'=>false,'rows'=>[],'errors'=>['CSV headers must be exactly: name,mobile']]; }
    $seen=[]; $line=1;
    while (($cells=fgetcsv($stream)) !== false) {
        $line++; if (count(array_filter($cells,static fn($v)=>trim((string)$v)!==''))===0) continue;
        if (count($rows) >= $maxRows) { $errors[]='CSV exceeds the 1,000 row limit.'; break; }
        $name=trim((string)($cells[0]??'')); $mobile=documents_normalize_mobile((string)($cells[1]??''));
        $rowErrors=[];
        if ($name==='' || strlen($name)>160) $rowErrors[]='Name is required (maximum 160 characters).';
        if (!preg_match('/^[6-9][0-9]{9}$/',$mobile)) $rowErrors[]='A valid 10-digit Indian mobile is required.';
        $key=documents_contact_normalize_name($name).'|'.$mobile;
        if (isset($seen[$key])) $rowErrors[]='Duplicate of row '.$seen[$key].'.'; else $seen[$key]=$line;
        $rows[]=['row_no'=>$line,'name'=>$name,'mobile'=>$mobile,'errors'=>$rowErrors];
        foreach($rowErrors as $e) $errors[]='Row '.$line.': '.$e;
    }
    fclose($stream); return ['ok'=>$errors===[],'rows'=>$rows,'errors'=>$errors];
}

function documents_contact_build_preview(array $csvRows, array $quotes): array
{
    $candidates=[]; $quoteUse=[]; $csvUse=[];
    foreach($csvRows as $ci=>$csv) foreach($quotes as $quote) {
        $score=documents_contact_similarity((string)$csv['name'],(string)($quote['customer_name']??''));
        if($score<60) continue;
        $qid=(string)($quote['id']??''); $quoteUse[$qid][]= $ci; $csvUse[$ci][]=$qid;
        $oldMobile=documents_normalize_mobile((string)($quote['customer_mobile']??''));
        $oldLink=documents_project_customer_user_link($quote); $newOwner=documents_find_customer_by_mobile((string)$csv['mobile']);
        $candidates[]=['candidate_id'=>hash('sha256',$ci.'|'.$qid),'csv_index'=>$ci,'csv'=>$csv,'quotation_id'=>$qid,
          'quotation_reference'=>(string)($quote['quote_no']??$qid),'status'=>(string)($quote['status']??''),'quotation_name'=>(string)($quote['customer_name']??''),
          'current_mobile'=>$oldMobile,'mobile_matches'=>$oldMobile===(string)$csv['mobile'],'score'=>$score,'band'=>documents_contact_score_band($score),
          'preselected'=>$score>=75,'version'=>(string)($quote['updated_at']??$quote['created_at']??''),'old_link'=>$oldLink['label']??'',
          'new_link'=>$newOwner===null?'Not in Customer Users':'Existing mobile owner: '.(string)($newOwner['name']??''),'conflicts'=>[]];
    }
    foreach($candidates as &$c) {
        $conf=[]; if(count($quoteUse[$c['quotation_id']]??[])>1)$conf[]='Many CSV rows match this quotation';
        if(count($csvUse[$c['csv_index']]??[])>1)$conf[]='CSV row matches multiple quotations';
        $c['conflicts']=$conf; if($conf!==[] || $c['band']==='weak')$c['preselected']=false;
    } unset($c);
    return $candidates;
}

function documents_contact_stage_dir(): string { return documents_settings_dir().'/quotation-contact-imports'; }
function documents_contact_stage_save(array $preview, array $csvRows, string $adminId): array
{
    documents_ensure_dir(documents_contact_stage_dir()); $id=bin2hex(random_bytes(16));
    $stage=['id'=>$id,'admin_id'=>$adminId,'created_at'=>date('c'),'expires_at'=>time()+3600,'state'=>'review','rows'=>$csvRows,'candidates'=>$preview];
    $saved=json_save(documents_contact_stage_dir().'/'.$id.'.json',$stage);
    return !empty($saved['ok'])?['ok'=>true,'stage'=>$stage]:$saved;
}
function documents_contact_stage_load(string $id, string $adminId): ?array
{
    $id=preg_replace('/[^a-f0-9]/','',$id)??''; $s=json_load(documents_contact_stage_dir().'/'.$id.'.json',[]);
    return is_array($s)&&($s['admin_id']??'')===$adminId&&(int)($s['expires_at']??0)>=time()?$s:null;
}

function documents_contact_apply_batch(array $stage, array $decisions, string $reason, array $actor, string $batchId): array
{
    $reason=trim($reason); if($reason==='') return ['ok'=>false,'error'=>'Batch correction reason is required.','results'=>[]];
    if(!preg_match('/^[a-zA-Z0-9_-]{8,80}$/',$batchId)) return ['ok'=>false,'error'=>'Invalid batch ID.','results'=>[]];
    $results=[]; $seenQuotes=[];
    foreach($stage['candidates'] as $candidate) {
        $cid=(string)$candidate['candidate_id']; $d=$decisions[$cid]??null;
        if(!is_array($d)||($d['action']??'ignore')!=='apply') continue;
        $qid=(string)$candidate['quotation_id'];
        if(isset($seenQuotes[$qid])) { $results[]=['quotation_id'=>$qid,'ok'=>false,'message'=>'Conflict: quotation selected more than once.']; continue; }
        $seenQuotes[$qid]=true; $lock=@fopen(documents_quotations_dir().'/'.safe_filename($qid).'.contact-batch.lock','c+');
        if(!is_resource($lock)||!flock($lock,LOCK_EX)){ $results[]=['quotation_id'=>$qid,'ok'=>false,'message'=>'Could not acquire quotation lock.']; continue; }
        try {
            $q=documents_get_quote($qid); if($q===null){$results[]=['quotation_id'=>$qid,'ok'=>false,'message'=>'Quotation not found.'];continue;}
            foreach((array)($q['contact_correction_audit']??[]) as $event) if(($event['batch_id']??'')===$batchId){$results[]=['quotation_id'=>$qid,'ok'=>true,'duplicate'=>true,'message'=>'Already applied.'];continue 2;}
            $version=(string)($q['updated_at']??$q['created_at']??''); if(!hash_equals((string)$candidate['version'],$version)){$results[]=['quotation_id'=>$qid,'ok'=>false,'message'=>'Stale quotation; reload and review.'];continue;}
            $oldName=(string)($q['customer_name']??''); $oldMobile=documents_normalize_mobile((string)($q['customer_mobile']??''));
            $nameChoice=(string)($d['name_choice']??'keep'); $mobileChoice=(string)($d['mobile_choice']??'keep');
            $newName=$nameChoice==='csv'?(string)$candidate['csv']['name']:($nameChoice==='manual'?trim((string)($d['manual_name']??'')):$oldName);
            $newMobile=$mobileChoice==='csv'?(string)$candidate['csv']['mobile']:($mobileChoice==='manual'?documents_normalize_mobile((string)($d['manual_mobile']??'')):$oldMobile);
            if($newName===''||strlen($newName)>160||!preg_match('/^[6-9][0-9]{9}$/',$newMobile)){$results[]=['quotation_id'=>$qid,'ok'=>false,'message'=>'Invalid final name or mobile.'];continue;}
            if(!empty($d['confirm_account_change'])){$results[]=['quotation_id'=>$qid,'ok'=>false,'message'=>'Account migration/relinking is not performed by this batch; use the separate Customer Users workflow.'];continue;}
            $now=date('c'); $q['customer_name']=$newName; $q['customer_mobile']=$newMobile;
            $q['customer_snapshot']=array_merge((array)($q['customer_snapshot']??[]),['name'=>$newName,'mobile'=>$newMobile]);
            if(is_array($q['links']??null)){$q['links']['customer_mobile']=$newMobile;}
            $q['updated_at']=$now; $q['contact_correction_audit'][]=['batch_id'=>$batchId,'reason'=>$reason,'old_name'=>$oldName,'new_name'=>$newName,'old_mobile'=>$oldMobile,'new_mobile'=>$newMobile,'customer_user_action'=>'quotation_only','at'=>$now,'actor'=>$actor];
            $save=documents_save_quote($q); $results[]=['quotation_id'=>$qid,'ok'=>!empty($save['ok']),'message'=>!empty($save['ok'])?'Corrected (quotation only).':($save['error']??'Save failed.')];
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    return ['ok'=>count(array_filter($results,static fn($r)=>empty($r['ok'])))===0,'results'=>$results,'error'=>''];
}
