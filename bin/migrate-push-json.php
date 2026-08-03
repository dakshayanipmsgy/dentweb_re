#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/push_legacy_migration.php';
try{$report=push_migrate_legacy(push_db(),push_config()['legacy_store'],in_array('--apply',$argv,true));echo json_encode($report,JSON_UNESCAPED_SLASHES).PHP_EOL;}
catch(Throwable){fwrite(STDERR,json_encode(['status'=>'invalid-legacy-state']).PHP_EOL);exit(2);}
