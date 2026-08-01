<?php
declare(strict_types=1);

function route_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$root=sys_get_temp_dir().'/notification-api-'.bin2hex(random_bytes(4));mkdir($root);mkdir($root.'/api');mkdir($root.'/storage');mkdir($root.'/includes');
$includes=realpath(__DIR__.'/../includes');foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($includes,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $item){$target=$root.'/includes/'.substr($item->getPathname(),strlen($includes)+1);if($item->isDir())mkdir($target);else copy($item->getPathname(),$target);}copy(__DIR__.'/../api/notifications.php',$root.'/api/notifications.php');
file_put_contents($root.'/session.php', <<<'PHP'
<?php
require __DIR__.'/includes/auth.php';start_session();$db=get_db();
$role=$_GET['role']??'admin';$s=$db->prepare('SELECT u.*,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name=? LIMIT 1');$s->execute([$role]);$user=$s->fetch();
if(!$user&&in_array($role,['admin','employee'],true)){$rid=$db->query("SELECT id FROM roles WHERE name='".$role."'")->fetchColumn();$q=$db->prepare('INSERT INTO users(full_name,email,username,password_hash,role_id,status) VALUES(?,?,?,?,?,?)');$q->execute(['Route '.$role,$role.'@route.test','route-'.$role,'x',$rid,'active']);$s->execute([$role]);$user=$s->fetch();}
if(!$user){$user=['id'=>999,'role_name'=>$role,'full_name'=>'Test'];}
$_SESSION['user']=$user;$_SESSION['csrf_token']='route-token';
if(($_GET['notification']??'')==='1'){$db->exec("INSERT INTO portal_notifications(audience,tone,title,message,created_at) VALUES('admin','info','Safe title','Safe message',datetime('now'))");$id=(int)$db->lastInsertId();$q=$db->prepare("INSERT INTO portal_notification_status(notification_id,user_id,status,updated_at,unread_at) VALUES(?,?,'unread',datetime('now'),datetime('now'))");$q->execute([$id,(int)$user['id']]);echo $id;}
PHP);
$port=random_int(20000,40000);$log=$root.'/server.log';$proc=proc_open(['php','-S','127.0.0.1:'.$port,'-t',$root],[['pipe','r'],['file',$log,'a'],['file',$log,'a']],$pipes);route_assert(is_resource($proc),'server start');usleep(500000);$jar=$root.'/cookies';
$request=function(string $method,string $path,string $body='',string|false $csrf='route-token')use($port,$jar):array{$headers=$csrf!==false?["X-CSRF-Token: ".$csrf]:[];if($body!=='')$headers[]='Content-Type: application/json';$cmd=['curl','-sS','-D','-','-o','-','-X',$method,'-b',$jar,'-c',$jar];foreach($headers as $h){$cmd[]='-H';$cmd[]=$h;}if($body!==''){$cmd[]='--data-binary';$cmd[]=$body;}$cmd[]='http://127.0.0.1:'.$port.$path;$p=proc_open($cmd,[['pipe','r'],['pipe','w'],['pipe','w']],$io);$out=stream_get_contents($io[1]);$err=stream_get_contents($io[2]);$exit=proc_close($p);route_assert($exit===0,'curl '.$err);[$rawHeaders,$rawBody]=explode("\r\n\r\n",$out,2);preg_match('#HTTP/\S+ (\d+)#',$rawHeaders,$m);return [(int)$m[1],strtolower($rawHeaders),$rawBody,json_decode($rawBody,true)];};
try{
    [$status,$headers,$body,$json]=$request('GET','/api/notifications.php?action=count');route_assert($status===401,'unauthenticated 401');
    $request('GET','/session.php?role=customer');[$status]=$request('GET','/api/notifications.php?action=count');route_assert($status===403,'unsupported role 403');
    $request('GET','/session.php?role=admin&notification=1');[$status]=$request('GET','/api/notifications.php?action=count','',false);route_assert($status===419,'missing CSRF 419');
    $badToken=$request('GET','/api/notifications.php?action=count','','wrong-token');route_assert($badToken[0]===419,'invalid CSRF 419');
    [$status]=$request('GET','/api/notifications.php?action=count','');route_assert($status===200,'valid count');
    [$status,$headers,$body,$json]=$request('GET','/api/notifications.php?action=list');route_assert($status===200&&isset($json['ok'],$json['data']['notifications']),'valid list envelope');$id=(int)$json['data']['notifications'][0]['id'];
    foreach(['read','unread','dismiss'] as $action){[$status,,,$json]=$request('POST','/api/notifications.php?action='.$action,json_encode(['id'=>$id]));route_assert($status===200&&$json['ok']===true,$action);}
    $request('GET','/session.php?role=employee');[$status]=$request('POST','/api/notifications.php?action=read',json_encode(['id'=>$id]));route_assert($status===404,"another user's notification");$request('GET','/session.php?role=admin');
    [$status]=$request('POST','/api/notifications.php?action=read-all','{}');route_assert($status===200,'read-all');
    foreach([['{',422],['[]',422],['1',422],['{}',422]] as [$payload,$expected]){[$status]=$request('POST','/api/notifications.php?action=read',$payload);route_assert($status===$expected,'invalid JSON/object/id');}
    [$status]=$request('POST','/api/notifications.php?action=nope','{}');route_assert($status===422,'invalid action');
    [$status,$headers]=$request('PUT','/api/notifications.php?action=count');route_assert($status===405&&str_contains($headers,'allow: get, post'),'method and Allow');
    route_assert(str_contains($headers,'content-type: application/json')&&str_contains($headers,'x-content-type-options: nosniff')&&str_contains($headers,'cache-control:')&&str_contains($headers,'no-store')&&str_contains($headers,'private')&&str_contains($headers,'pragma: no-cache'),'security headers');
    route_assert(!preg_match('/(?:sqlstate|\/workspace\/|stack trace|event_data)/i',$body),'no sensitive diagnostics');
    echo "notification API route tests passed\n";
}finally{proc_terminate($proc);proc_close($proc);}
