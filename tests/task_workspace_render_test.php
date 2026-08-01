<?php
declare(strict_types=1);
function rassert(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$workspace=file_get_contents(__DIR__.'/../includes/task_workspace.php');$admin=file_get_contents(__DIR__.'/../admin-dashboard.php');$employee=file_get_contents(__DIR__.'/../employee-dashboard.php');
rassert(str_contains($workspace,'notification_bell.php')&&str_contains($admin,'notification_bell.php')&&str_contains($employee,'notification_bell.php'),'shared notification bell missing');
$bell=file_get_contents(__DIR__.'/../includes/notification_bell.php');rassert(str_contains($bell,"n>99?'99+'"),'99+ badge missing');
rassert(str_contains($workspace,'@media(max-width:540px)'),'narrow-mobile layout missing');rassert(str_contains($workspace,'Chronological timeline'),'timeline missing');rassert(str_contains($workspace,'My Reminders'),'reminder separation missing');foreach(['status','next_action','priority','employee','search','view'] as $filter)rassert(str_contains($workspace,"'$filter'"),'filter missing '.$filter);rassert(str_contains($admin,'admin-tasks.php?next_action=admin'),'admin deep link missing');rassert(str_contains($employee,'employee-tasks.php?next_action=employee'),'employee deep link missing');echo "task workspace rendering checks passed\n";
foreach(['Needs admin action','Awaiting review','Blocked','Not acknowledged','Overdue','Due today','Completed this week','New assignments','Needs my action','In progress','Waiting for admin','Correction required','Approved complete'] as $card)rassert(str_contains($workspace,$card),'summary card missing '.$card);
foreach(['due','category','upcoming','no due date','completed','cancelled','archived'] as $filter)rassert(str_contains($workspace,$filter),'complete filter missing '.$filter);
rassert(str_contains($workspace,"\$_GET['employee']"),'selected employee filter missing');
rassert(str_contains($workspace,"!in_array(\$t['workflow_status'],['completed','cancelled'],true)&&!(int)\$t['archived_flag']"),'closed-control guard missing');
rassert(str_contains($workspace,'aria-controls="custom-recurrence"')&&str_contains($workspace,"s.value==='custom'")&&str_contains($workspace,'c.hidden=!show'),'custom recurrence progressive disclosure missing');
rassert(str_contains($workspace,"Default visible")||str_contains($workspace,'id="custom-recurrence"'),'no-JavaScript recurrence fallback missing');
