<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';
/** Import exact source IDs only. Phone matches are review links, not person merges. */
function r_import(array $rows): array {
 return r_state(function(&$s)use($rows){$counts=['added'=>0,'already_present'=>0];foreach($rows as $row){
  $mid=$row['message_id']??'';if(!is_string($mid)||!preg_match('/^[a-f0-9]{16}$/D',$mid))throw new RuntimeException('Invalid portal source ID.');
  $existing=false;foreach($s['leads'] as $l)foreach($l['sources'] as $source)if(($source['message_id']??'')===$mid)$existing=true;
  if($existing){$counts['already_present']++;continue;}
  $text=$row['source_text']??'';$name=$row['name']??'';$phone=$row['phone']??'';
  if(!$name&&preg_match('/selected by the (.+?) on \d{2}-\d{2}-\d{4}/u',$text,$m))$name=$m[1];
  if(!$phone&&preg_match('/consumer are\s*([0-9+ -]{10,16})/',$text,$m))$phone=trim($m[1]);
  try{$phone=r_phone($phone);}catch(Throwable $e){$phone='';}
  $id=bin2hex(random_bytes(12));$now=gmdate('c');$related=[];foreach($s['leads'] as $other)if($phone!==''&&$phone===($other['phone']??''))$related[]=$other['id'];
  $s['leads'][$id]=['id'=>$id,'segment'=>'residential','name'=>$name?:'Name requires review','phone'=>$phone,'district'=>'','bill'=>'unknown','language'=>'hi','mode'=>'live','stage'=>'historical_status_unknown','created_at'=>$row['received_at']??$now,'updated_at'=>$now,'owner'=>'Vishesh Vardhan','next_action'=>'Check current portal/customer stage and permission before any outreach.','due_at'=>$now,'contact_permission'=>'needs_current_stage_and_preference_review','sources'=>[['kind'=>'PM Surya Ghar vendor selection','message_id'=>$mid,'original_record'=>$row]],'related_phone_records'=>$related,'facts'=>[],'milestones'=>[],'history'=>[['at'=>$now,'actor'=>'historical_import','kind'=>'imported_without_stage_inference','note'=>'Original status and converted flag retained as historical source data, not proof of sale.']],'notification'=>['status'=>'historical_import_no_alert','attempts'=>0],'version'=>1];$counts['added']++;
 }return $counts;});
}
