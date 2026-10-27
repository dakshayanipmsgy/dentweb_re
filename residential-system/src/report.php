<?php
declare(strict_types=1);
function r_report(array $state): array {
 $out=['live_records'=>0,'historical_unknown'=>0,'saved_website_enquiries'=>0,'handoff_clicks'=>0,'confirmed_conversations'=>'unknown — manual evidence not yet reconciled','staff_qualified'=>0,'survey_completed'=>0,'proposals_sent'=>0,'deposits'=>0,'installations'=>0,'collected_payment_records'=>0,'collection_amount'=>0.0,'cost_amount'=>0.0,'profitability'=>'unknown — project costs and tax completeness unverified','first_response_minutes'=>[],'known_followups_completed'=>0,'known_followups_completed_by_due'=>0,'lost_reasons'=>[],'sources'=>[]];
 foreach($state['leads'] as $l){if($l['mode']!=='live'||!empty($l['is_test']))continue;$out['live_records']++;if($l['stage']==='historical_status_unknown')$out['historical_unknown']++;
  $source=$l['sources'][0]['source']??$l['sources'][0]['kind']??'';$source=$source?:'unknown';$out['sources'][$source]=($out['sources'][$source]??0)+1;
  if(($l['sources'][0]['kind']??'')==='website')$out['saved_website_enquiries']++;
  if(isset($l['handoff_clicked_at']))$out['handoff_clicks']++;if(isset($l['qualification']))$out['staff_qualified']++;
  if(isset($l['first_response_at'])&&$l['stage']!=='historical_status_unknown')$out['first_response_minutes'][]=round((strtotime($l['first_response_at'])-strtotime($l['created_at']))/60,1);
  $types=array_column($l['milestones'],'kind');foreach(['deposit'=>'deposits','installation'=>'installations','proposal'=>'proposals_sent','collected_payment'=>'collected_payment_records'] as $kind=>$metric)if(in_array($kind,$types,true))$out[$metric]++;
  foreach($l['milestones'] as $m){if($m['kind']==='collected_payment')$out['collection_amount']+=$m['amount'];if($m['kind']==='cost')$out['cost_amount']+=$m['amount'];}
  $survey=false;foreach($l['history'] as $h){if(($h['to']??'')==='survey_completed')$survey=true;if(($h['kind']??'')==='staff_outcome'&&!empty($h['previous_due_at'])){$out['known_followups_completed']++;if(strtotime($h['at'])<=strtotime($h['previous_due_at']))$out['known_followups_completed_by_due']++;}if(($h['to']??'')==='lost'&&($h['reason']??'')!=='')$out['lost_reasons'][$h['reason']]=($out['lost_reasons'][$h['reason']]??0)+1;}
  if($survey)$out['survey_completed']++;
 }return $out;
}
