#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$options=getopt('', ['dry-run','db::','employees::','tasks::','report-dir::']);
$dry=array_key_exists('dry-run',$options);
$dbPath=(string)($options['db']??(__DIR__.'/../storage/app.sqlite'));
$employeePath=(string)($options['employees']??(__DIR__.'/../storage/employee-users/employees.json'));
$taskPath=(string)($options['tasks']??(__DIR__.'/../data/tasks/tasks.json'));
$reportDir=(string)($options['report-dir']??(__DIR__.'/../storage/migration-reports'));
$stamp=gmdate('Ymd_His');
$counts=['imported'=>0,'updated'=>0,'skipped'=>0,'conflicted'=>0,'failed'=>0]; $conflicts=[]; $backups=[];

function safe_json_file(string $path): array { if(!is_file($path))return []; $v=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR); return is_array($v)?$v:[]; }
function fingerprint(array $record): string { unset($record['password_hash'],$record['notes'],$record['completion_log'],$record['attachments']); ksort($record); return hash('sha256',json_encode($record,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)); }

try {
  $workingDbPath=$dbPath;
  if($dry){$workingDbPath=tempnam(sys_get_temp_dir(),'dentweb-911-dry-');if($workingDbPath===false)throw new RuntimeException('Cannot create dry-run database.');if(is_file($dbPath)&&!copy($dbPath,$workingDbPath))throw new RuntimeException('Cannot copy database for dry run.');}
  if(!$dry){
    $backupDir=dirname($dbPath).'/backups/'.$stamp; if(!is_dir($backupDir)&&!mkdir($backupDir,0770,true)&&!is_dir($backupDir))throw new RuntimeException('Cannot create backup directory.');
    foreach([$dbPath,$employeePath,$taskPath] as $path)if(is_file($path)){ $dest=$backupDir.'/'.basename($path); if(!copy($path,$dest))throw new RuntimeException('Backup failed.'); $backups[]=$dest; }
  }
  $db=new PDO('sqlite:'.$workingDbPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); $db->exec('PRAGMA foreign_keys=ON'); initialize_schema($db);
  $db->beginTransaction();
  $db->exec("INSERT OR IGNORE INTO roles(name,description) VALUES('employee','Internal staff')"); $role=(int)$db->query("SELECT id FROM roles WHERE name='employee'")->fetchColumn();
  $payload=safe_json_file($employeePath); foreach(($payload['employees']??[]) as $employee){
    $legacy=trim((string)($employee['id']??''));$login=trim((string)($employee['login_id']??''));
    if($legacy===''||$login===''){ $counts['failed']++; continue; }
    $mapped=$db->prepare("SELECT user_id FROM employee_legacy_ids WHERE source='employee_json' AND legacy_id=:id");$mapped->execute([':id'=>$legacy]);$mappedId=$mapped->fetchColumn();
    if($mappedId!==false){$counts['skipped']++;continue;}
    $find=$db->prepare('SELECT id FROM users WHERE lower(username)=lower(:v) OR lower(email)=lower(:v)');$find->execute([':v'=>$login]);$ids=array_values(array_unique(array_map('intval',$find->fetchAll(PDO::FETCH_COLUMN))));
    if(count($ids)>1){$counts['conflicted']++;$conflicts[]=['type'=>'employee','legacy_id'=>$legacy,'reason'=>'ambiguous exact login identifier'];continue;}
    if(count($ids)===1){$uid=$ids[0];$counts['updated']++;}
    else {
      $email=filter_var($login,FILTER_VALIDATE_EMAIL)?$login:'employee+'.substr(hash('sha256',strtolower($login)),0,16).'@local.invalid';
      $insert=$db->prepare('INSERT INTO users(full_name,email,username,password_hash,role_id,status,permissions_note,created_at,updated_at) VALUES(:n,:e,:u,:p,:r,:s,:d,:c,:m)');
      $insert->execute([':n'=>(string)($employee['name']??$login),':e'=>$email,':u'=>$login,':p'=>(string)($employee['password_hash']??''),':r'=>$role,':s'=>(string)($employee['status']??'active'),':d'=>(string)($employee['designation']??''),':c'=>(string)($employee['created_at']??gmdate('c')),':m'=>(string)($employee['updated_at']??gmdate('c'))]);$uid=(int)$db->lastInsertId();$counts['imported']++;
    }
    $db->prepare("INSERT INTO employee_legacy_ids(source,legacy_id,user_id) VALUES('employee_json',:l,:u)")->execute([':l'=>$legacy,':u'=>$uid]);
  }
  foreach($db->query('SELECT id FROM portal_tasks')->fetchAll(PDO::FETCH_COLUMN) as $id){$stmt=$db->prepare("INSERT OR IGNORE INTO task_legacy_ids(source,legacy_id,task_id,fingerprint) VALUES('portal_tasks',:l,:t,:f)");$stmt->execute([':l'=>(string)$id,':t'=>(int)$id,':f'=>hash('sha256','portal_tasks:'.$id)]);$counts[$stmt->rowCount()===1?'updated':'skipped']++;}
  $repo=new CanonicalTaskRepository($db); foreach(safe_json_file($taskPath) as $task){
    $legacy=trim((string)($task['id']??''));if($legacy===''){$counts['failed']++;continue;}
    $check=$db->prepare("SELECT task_id,fingerprint FROM task_legacy_ids WHERE source='tasks_json' AND legacy_id=:l");$check->execute([':l'=>$legacy]);$old=$check->fetch();$fp=fingerprint($task);
    if($old){if(hash_equals((string)$old['fingerprint'],$fp))$counts['skipped']++;else{$counts['conflicted']++;$conflicts[]=['type'=>'task','legacy_id'=>$legacy,'reason'=>'source changed after import'];}continue;}
    $assigneeLegacy=trim((string)($task['assigned_to_id']??'')); if($assigneeLegacy!==''){$map=$db->prepare("SELECT user_id FROM employee_legacy_ids WHERE source='employee_json' AND legacy_id=:l");$map->execute([':l'=>$assigneeLegacy]);$uid=$map->fetchColumn();if($uid===false){$counts['conflicted']++;$conflicts[]=['type'=>'task','legacy_id'=>$legacy,'reason'=>'unmapped assignee legacy ID'];continue;}$task['assigned_to_id']=(string)$uid;}
    $task['id']='';$saved=$repo->saveLegacyShape($task);$db->prepare("INSERT INTO task_legacy_ids(source,legacy_id,task_id,fingerprint) VALUES('tasks_json',:l,:t,:f)")->execute([':l'=>$legacy,':t'=>(int)$saved['id'],':f'=>$fp]);$counts['imported']++;
  }
  if($dry)$db->rollBack();else$db->commit();
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();$counts['failed']++;$conflicts[]=['type'=>'migration','reason'=>$e->getMessage()];}
$db=null;if($dry&&isset($workingDbPath)&&is_file($workingDbPath))unlink($workingDbPath);
$report=['mode'=>$dry?'dry-run':'apply','generated_at'=>gmdate('c'),'database'=>basename($dbPath),'counts'=>$counts,'conflicts'=>$conflicts,'backups'=>array_map('basename',$backups)];
if(!$dry){if(!is_dir($reportDir))mkdir($reportDir,0770,true);file_put_contents($reportDir.'/canonical-work-'.$stamp.'.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL; exit($counts['failed']>0?1:0);
