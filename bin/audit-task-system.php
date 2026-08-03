#!/usr/bin/env php
<?php
declare(strict_types=1);require_once __DIR__.'/../includes/task_system_operations.php';$o=getopt('',['strict','json','db::']);try{$path=(string)($o['db']??TaskSystemOperations::databasePath());$report=TaskSystemOperations::audit(TaskSystemOperations::open($path));echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;$bad=$report['summary']['failures']+(isset($o['strict'])?$report['summary']['warnings']:0);exit($bad?2:0);}catch(Throwable $e){fwrite(STDERR,'Audit failed without exposing the database path: '.$e->getMessage().PHP_EOL);exit(2);}
