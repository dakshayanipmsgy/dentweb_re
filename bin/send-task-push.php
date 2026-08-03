#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';require_once __DIR__.'/../includes/push_worker.php';
$o=getopt('',['limit:','dry-run','notification-id:']);$limit=max(1,min(100,(int)($o['limit']??25)));$dry=isset($o['dry-run']);$nid=isset($o['notification-id'])?max(1,(int)$o['notification-id']):null;$cfg=push_config();$base=['mode'=>$dry?'dry-run':'send','synchronized'=>0,'claimed'=>0,'sent'=>0,'temporary_failures'=>0,'invalidated'=>0,'skipped'=>0];
if(!$cfg['enabled']){echo json_encode($base+['status'=>'disabled']).PHP_EOL;exit(0);}if(!$cfg['encryption_ready']||!$cfg['dependency_ready']||$cfg['vapid_public']===''||$cfg['vapid_private']===''||$cfg['vapid_subject']===''){fwrite(STDERR,json_encode($base+['status'=>'configuration-unavailable']).PHP_EOL);exit(2);}
$db=push_db();if($dry){$base['synchronized']=push_synchronize($db,500,$nid,true);$q="SELECT COUNT(*) FROM push_deliveries WHERE status IN ('pending','retry') AND COALESCE(next_attempt_at,datetime('now'))<=datetime('now')";$base['claimed']=min($limit,(int)$db->query($q)->fetchColumn());echo json_encode($base+['status'=>'ok']).PHP_EOL;exit(0);}
require __DIR__.'/../vendor/autoload.php';try{$report=push_worker_run($db,new MinishlinkPushTransport($cfg),$limit,$nid);echo json_encode($base+$report).PHP_EOL;exit(($report['temporary_failures']??0)>0?1:0);}catch(Throwable){fwrite(STDERR,json_encode($base+['status'=>'worker-failed']).PHP_EOL);exit(2);}
