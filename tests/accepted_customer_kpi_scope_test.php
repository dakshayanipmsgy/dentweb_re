<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/project_workspace.php';

$assertions=0;
function ackok(bool $condition,string $message):void{global $assertions;$assertions++;if(!$condition)throw new RuntimeException($message);}
function ackmoney(float $actual,float $expected,string $message):void{ackok(abs($actual-$expected)<.001,$message.": $actual != $expected");}
function ackrow(string $id,bool $archived,float $business,float $received,float $outstanding,float $credit=0):array{
    return array_merge(['id'=>$id,'customer'=>'Customer '.$id,'mobile'=>'9000000000','quotation'=>'Q/'.$id,'documents_ready'=>true,'link_ready'=>true,'due_days'=>0,'active_requests'=>0,'archived'=>$archived],project_workspace_accepted_financial_fields(['project_amount'=>$business,'received_amount'=>$received,'outstanding_amount'=>$outstanding,'customer_credit'=>$credit]));
}
$active=[ackrow('A1',false,250000,100000,150000),ackrow('A2',false,200000,125000,75000),ackrow('A3',false,300000,150000,150000),ackrow('A4',false,267690,137500,130190),ackrow('A5',false,200000,0,200000)];
$archived=[];for($i=1;$i<=12;$i++)$archived[]=ackrow('R'.$i,true,$i===12?420190:250000,10000,100000);
$all=array_merge($active,$archived);$before=serialize($all);
$kpis=project_workspace_accepted_kpis($active);
ackok($kpis['count']===5,'five active projects only');ackmoney($kpis['business'],1217690,'active business excludes archived amount');ackmoney($kpis['received'],512500,'active receipts exclude archived receipts');ackmoney($kpis['dues'],705190,'active dues exclude archived balances');ackok($kpis['with_dues']===5,'with-dues counts active only');ackmoney($kpis['collection_pct'],512500/1217690*100,'collection uses active totals');ackmoney(array_sum(array_column($archived,'quotation_amount')),3170190,'archived fixture amount');
$default=project_workspace_params([],'accepted');ackok(count(project_workspace_filter($all,$default,'accepted'))===$kpis['count'],'default rows reconcile with KPIs');
$include=project_workspace_params(['accepted_archive'=>'with_archived'],'accepted');ackok(count(project_workspace_filter($all,$include,'accepted'))===17,'include archived changes results');ackok(project_workspace_accepted_kpis($active)===$kpis,'include archived leaves KPIs');
$archiveOnly=project_workspace_params(['accepted_archive'=>'archived'],'accepted');ackok(count(project_workspace_filter($all,$archiveOnly,'accepted'))===12,'archive-only result scope');ackmoney(project_workspace_accepted_kpis($active)['business'],1217690,'archive-only does not become active money');
foreach([['accepted_q'=>'A1'],['accepted_documents'=>'ready'],['accepted_link'=>'linked'],['accepted_request'=>'no']] as $filter){project_workspace_filter($all,project_workspace_params($filter,'accepted'),'accepted');ackok(project_workspace_accepted_kpis($active)===$kpis,'result filter leaves global KPI');}
$source=file_get_contents(__DIR__.'/../admin-documents.php');ackok(str_contains($source,"empty(\$quote['is_current_version'])")&&str_contains($source,"documents_project_completion_state(\$quote)==='completed'"),'non-current and completed excluded');
$canonical=project_workspace_accepted_financial_fields(['project_amount'=>100,'received_amount'=>120,'outstanding_amount'=>40,'customer_credit'=>20]);ackmoney($canonical['due'],40,'credit not subtracted twice');ackmoney($canonical['credit'],20,'credit separate');
$overpaid=ackrow('C',false,100,120,0,20);ackmoney($overpaid['due'],0,'overpaid due zero');ackok($overpaid['has_credit'],'overpaid credit status');
$states=[ackrow('D',false,100,0,10),ackrow('P',false,100,100,0),$overpaid];foreach(['due'=>'D','paid'=>'P','credit'=>'C'] as $state=>$id){$s=project_workspace_params(['accepted_financial'=>$state],'accepted');$filtered=project_workspace_filter($states,$s,'accepted');ackok(count($filtered)===1&&$filtered[0]['id']===$id,"financial state $state");}
$tolerance=[ackrow('T1',false,10,10,.01,.01),ackrow('T2',false,10,0,.016,.016)];$tk=project_workspace_accepted_kpis($tolerance);ackmoney($tk['dues'],.02,'paise-safe dues');ackmoney($tk['credits'],.02,'paise-safe credits');ackok($tk['with_dues']===1,'tolerance controls count');
project_workspace_filter($all,$include,'accepted');project_workspace_accepted_kpis($active);ackok(serialize($all)===$before,'helpers do not mutate data');
echo "accepted_customer_kpi_scope_test passed ($assertions assertions)\n";
