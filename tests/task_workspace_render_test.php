<?php
declare(strict_types=1);
function rassert(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$workspace=file_get_contents(__DIR__.'/../includes/task_workspace.php');$admin=file_get_contents(__DIR__.'/../admin-dashboard.php');$employee=file_get_contents(__DIR__.'/../employee-dashboard.php');
rassert(str_contains($workspace,'@media(max-width:540px)'),'narrow-mobile layout missing');rassert(str_contains($workspace,'Chronological timeline'),'timeline missing');rassert(str_contains($workspace,'My Reminders'),'reminder separation missing');foreach(['status','next_action','priority','employee','search','view'] as $filter)rassert(str_contains($workspace,"'$filter'"),'filter missing '.$filter);rassert(str_contains($admin,'admin-tasks.php?next_action=admin'),'admin deep link missing');rassert(str_contains($employee,'employee-tasks.php?next_action=employee'),'employee deep link missing');echo "task workspace rendering checks passed\n";
