<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

function cw_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$root=sys_get_temp_dir().'/dentweb-911-'.bin2hex(random_bytes(4));mkdir($root,0770,true);mkdir($root.'/reports');
$dbPath=$root.'/app.sqlite';$employees=$root.'/employees.json';$tasks=$root.'/tasks.json';
$hash=password_hash('continuity-password',PASSWORD_DEFAULT);
file_put_contents($employees,json_encode(['employees'=>[
 ['id'=>'legacy-a','name'=>'Employee A','login_id'=>'employee.a@example.test','status'=>'active','password_hash'=>$hash],
 ['id'=>'legacy-b','name'=>'Same Name','login_id'=>'unique-b','status'=>'inactive','password_hash'=>password_hash('b',PASSWORD_DEFAULT)]
]],JSON_PRETTY_PRINT));
file_put_contents($tasks,json_encode([['id'=>'json-task-1','title'=>'Migrated task','assigned_to_id'=>'legacy-a','priority'=>'High','status'=>'Open','frequency_type'=>'once','due_date'=>'2026-08-01']],JSON_PRETTY_PRINT));
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/migrate-canonical-work.php').' --db='.escapeshellarg($dbPath).' --employees='.escapeshellarg($employees).' --tasks='.escapeshellarg($tasks).' --report-dir='.escapeshellarg($root.'/reports');
exec($cmd,$out,$code);cw_assert($code===0,'first migration failed');
$db=new PDO('sqlite:'.$dbPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec('PRAGMA foreign_keys=ON');
$user=$db->query("SELECT * FROM users WHERE username='employee.a@example.test'")->fetch();cw_assert(is_array($user)&&password_verify('continuity-password',$user['password_hash']),'password hash/login continuity lost');
cw_assert((int)$db->query("SELECT user_id FROM employee_legacy_ids WHERE legacy_id='legacy-a'")->fetchColumn()===(int)$user['id'],'legacy mapping missing');
cw_assert((int)$db->query("SELECT COUNT(*) FROM portal_tasks WHERE title='Migrated task'")->fetchColumn()===1,'JSON task not imported');
exec($cmd,$again,$code);cw_assert($code===0,'repeat migration failed');cw_assert((int)$db->query("SELECT COUNT(*) FROM portal_tasks WHERE title='Migrated task'")->fetchColumn()===1,'repeat duplicated task');

$repo=new CanonicalTaskRepository($db);$rows=$repo->all((int)$user['id']);cw_assert(count($rows)===1,'repository/dashboard assignee read failed');
$task=$rows[0];$task['title']='fresh';$saved=$repo->saveLegacyShape($task);$task['title']='stale';
try{$repo->saveLegacyShape($task);throw new RuntimeException('stale update accepted');}catch(RuntimeException $e){cw_assert(str_contains($e->getMessage(),'another request'),'wrong stale error');}
$before=(int)$db->query('SELECT COUNT(*) FROM portal_tasks')->fetchColumn();
try{$repo->replaceLegacyShape([['title'=>'temporary'],['title'=>'','completion_log'=>[INF]]]);}catch(Throwable $e){}
cw_assert((int)$db->query('SELECT COUNT(*) FROM portal_tasks')->fetchColumn()===$before,'transaction did not roll back');

// Exact identifiers can still be ambiguous across the two unique columns; names are never consulted.
$role=(int)$db->query("SELECT id FROM roles WHERE name='employee'")->fetchColumn();
$stmt=$db->prepare('INSERT INTO users(full_name,email,username,password_hash,role_id,status) VALUES(?,?,?,?,?,?)');
$stmt->execute(['Duplicate Name','first@example.test','cross-match','x',$role,'active']);$stmt->execute(['Duplicate Name','cross-match','second','x',$role,'active']);
file_put_contents($employees,json_encode(['employees'=>[['id'=>'ambiguous','name'=>'Duplicate Name','login_id'=>'cross-match','status'=>'active','password_hash'=>'x']]]));file_put_contents($tasks,'[]');
exec($cmd.' --dry-run',$dryOut,$code);$dry=json_decode(implode("\n",$dryOut),true);cw_assert(($dry['counts']['conflicted']??0)===1,'ambiguous identifier not reported');

echo "canonical work migration tests passed\n";
