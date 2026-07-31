<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/project_workspace.php';
$n=0; function wsok($v,$m){global $n;$n++;if(!$v)throw new RuntimeException($m);}
$accepted=[];$completed=[];
for($i=1;$i<=120;$i++){
 $base=['id'=>sprintf('q%03d',$i),'customer'=>'Customer '.sprintf('%03d',$i),'mobile'=>'98765'.sprintf('%05d',$i),'quotation'=>'DE/Q/'.sprintf('%03d',$i),'documents_ready'=>$i%2===0,'link_ready'=>$i%3===0];
 $accepted[]=array_merge($base,['due'=>$i%4===0?-10:($i%4===1?0:$i*100),'received'=>$i*50,'due_days'=>$i===1?0:$i,'active_requests'=>$i%5===0?1:0,'archived'=>$i%7===0]);
 $completed[]=array_merge($base,['completed_date'=>'2026-'.sprintf('%02d',($i%12)+1).'-'.sprintf('%02d',($i%27)+1),'review_changed'=>$i%4===0,'amount'=>1000+$i,'paid'=>$i%5===0?1000+$i:500]);
}
$a=project_workspace_params([], 'accepted'); wsok($a['per_page']===25&&$a['page']===1,'default pagination');
foreach([25,50,100] as $size)wsok(project_workspace_params(['accepted_per_page'=>$size],'accepted')['per_page']===$size,'allowed page size');
foreach([0,26,1000,'bad'] as $size)wsok(project_workspace_params(['accepted_per_page'=>$size],'accepted')['per_page']===25,'invalid page size');
$page=project_workspace_paginate(project_workspace_filter($accepted,$a,'accepted'),$a);wsok(count($page['rows'])===25&&$page['total']===103,'120 fixture pagination and default archive exclusion');
foreach(['financial'=>['due','paid','credit'],'age'=>['current','1_30','31_60','61_plus'],'request'=>['yes','no'],'documents'=>['ready','missing'],'link'=>['linked','attention'],'archive'=>['active','with_archived','archived']] as $key=>$values)foreach($values as $value){$s=$a;$s[$key]=$value;wsok(project_workspace_filter($accepted,$s,'accepted')!==[],"accepted $key=$value filter");}
foreach(['Customer 042','9876500042','98765 00042','DE/Q/042'] as $q){$s=$a;$s['archive']='with_archived';$s['q']=$q;wsok(count(project_workspace_filter($accepted,$s,'accepted'))===1,"search $q");}
$s=$a;$s['archive']='with_archived';$s['sort']='due_desc';$r=project_workspace_filter($accepted,$s,'accepted');wsok($r[0]['due']>=$r[1]['due'],'due sort');
$dupe=$accepted[0];$dupe['id']='q000';$dupe['due']=$accepted[0]['due'];$r=project_workspace_filter(array_merge($accepted,[$dupe]),$s,'accepted');$ids=array_column($r,'id');wsok(array_search('q000',$ids,true)<array_search('q001',$ids,true),'stable deterministic tie breaker');
$c=project_workspace_params([], 'completed');
foreach(['review'=>['clear','changed'],'payment'=>['paid','due'],'documents'=>['ready','missing'],'link'=>['linked','attention']] as $key=>$values)foreach($values as $value){$s=$c;$s[$key]=$value;wsok(project_workspace_filter($completed,$s,'completed')!==[],"completed $key=$value filter");}
$s=$c;$s['from']='2026-06-01';$s['to']='2026-06-30';wsok(project_workspace_filter($completed,$s,'completed')!==[],'completion date range');
foreach(['completed_desc','completed_asc','customer_asc','quotation_asc','amount_desc','paid_desc'] as $sort){$s=$c;$s['sort']=$sort;$r=project_workspace_filter($completed,$s,'completed');wsok(count($r)===120,"completed $sort");}
$q=project_workspace_query(array_merge($c,['q'=>'Customer 9','page'=>3,'per_page'=>50]),'completed',['view'=>'q009']);$rest=project_workspace_return_query(http_build_query($q),'completed');wsok($rest['completed_q']==='Customer 9'&&$rest['completed_page']==='3'&&$rest['completed_per_page']==='50','query-state restoration');
$source=file_get_contents(__DIR__.'/../admin-documents.php');
wsok(substr_count($source,'customer_operations_render(')===1,'full Customer Operations only in workbench');
wsok(!preg_match('/foreach\(\$completedRows.*?<label>Reason<input/s',$source),'no permanent reopen forms');
foreach(['data-dialog-open','<dialog class="reopen-dialog"','name="reopen_reason" required','name="csrf_token"','if($isAdmin)'] as $needle)wsok(str_contains($source,$needle),"reopen dialog $needle");
wsok(str_contains($source,'data-label="Customer"')&&str_contains($source,'@media (max-width:768px)'),'accessible mobile cards');
wsok(!str_contains($source,'data-mobile-copy'),'no duplicate desktop/mobile interaction markup');
echo "project_workspace_test passed ($n assertions; 120 Accepted + 120 Completed fixtures)\n";
