<?php
declare(strict_types=1);

/** Read-only/rehearsal helpers for the #915 production release gate. */
final class TaskSystemOperations
{
    public const MIGRATION_VERSION = 91500;

    public static function root(): string { return dirname(__DIR__); }
    public static function databasePath(): string
    {
        $configured = getenv('PORTAL_DB_PATH');
        return is_string($configured) && $configured !== '' ? $configured : self::root().'/storage/app.sqlite';
    }
    public static function relative(string $path): string
    {
        $root = realpath(self::root()) ?: self::root();
        $real = realpath($path);
        return $real !== false && str_starts_with($real, $root.'/') ? substr($real, strlen($root)+1) : basename($path);
    }
    public static function open(string $path, bool $readOnly=true): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) throw new RuntimeException('PDO SQLite is unavailable.');
        $dsn = $readOnly ? 'sqlite:file:'.rawurlencode($path).'?mode=ro' : 'sqlite:'.$path;
        $db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA foreign_keys=ON');
        return $db;
    }
    public static function tables(PDO $db): array
    {
        return $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    }
    public static function count(PDO $db, string $table, string $where='1=1'): int
    {
        if (!in_array($table, self::tables($db), true) || !preg_match('/^[A-Za-z0-9_ =().<>!\'"-]+$/', $where)) return 0;
        return (int)$db->query('SELECT COUNT(*) FROM "'.$table.'" WHERE '.$where)->fetchColumn();
    }
    public static function audit(PDO $db): array
    {
        $tables=self::tables($db); $checks=[];
        $add=static function(string $id,int $count,string $severity='failure')use(&$checks):void{$checks[]=['id'=>$id,'count'=>$count,'severity'=>$severity,'status'=>$count===0?'pass':$severity];};
        $foreign=$db->query('PRAGMA foreign_key_check')->fetchAll(); $add('foreign_key_violations',count($foreign));
        $integrity=(string)$db->query('PRAGMA integrity_check')->fetchColumn(); $add('sqlite_integrity',$integrity==='ok'?0:1);
        $relations=[
          ['portal_tasks','assignee_id','users'],['task_occurrences','task_id','portal_tasks'],['task_messages','task_id','portal_tasks'],
          ['task_events','task_id','portal_tasks'],['task_attachments','task_id','portal_tasks'],['portal_notification_status','notification_id','portal_notifications'],
          ['push_subscriptions','user_id','users'],['push_deliveries','subscription_id','push_subscriptions'],['push_deliveries','notification_id','portal_notifications']];
        foreach($relations as [$child,$column,$parent]) if(in_array($child,$tables,true)&&in_array($parent,$tables,true)){
            $n=(int)$db->query("SELECT COUNT(*) FROM $child c LEFT JOIN $parent p ON p.id=c.$column WHERE c.$column IS NOT NULL AND p.id IS NULL")->fetchColumn();
            $add('orphan_'.$child.'_'.$column,$n);
        }
        if(in_array('users',$tables,true)){
            $n=(int)$db->query("SELECT COUNT(*) FROM (SELECT lower(username) v FROM users GROUP BY lower(username) HAVING COUNT(*)>1)")->fetchColumn();$add('duplicate_usernames',$n);
            $n=(int)$db->query("SELECT COUNT(*) FROM (SELECT lower(email) v FROM users GROUP BY lower(email) HAVING COUNT(*)>1)")->fetchColumn();$add('duplicate_emails',$n);
        }
        if(in_array('portal_tasks',$tables,true)){
            $n=(int)$db->query("SELECT COUNT(*) FROM portal_tasks WHERE workflow_status='completed' AND proof_required=1 AND approved_at IS NULL")->fetchColumn();$add('completed_without_approval',$n);
            $n=(int)$db->query("SELECT COUNT(*) FROM portal_tasks t JOIN users u ON u.id=t.assignee_id WHERE t.workflow_status NOT IN ('completed','cancelled') AND u.status<>'active'")->fetchColumn();$add('inactive_active_task_assignees',$n,'warning');
        }
        if(in_array('portal_notifications',$tables,true)){$n=(int)$db->query("SELECT COUNT(*) FROM (SELECT deduplication_key FROM portal_notifications WHERE deduplication_key IS NOT NULL GROUP BY deduplication_key HAVING COUNT(*)>1)")->fetchColumn();$add('duplicate_notification_keys',$n);}
        if(in_array('push_deliveries',$tables,true)){
            $n=(int)$db->query("SELECT COUNT(*) FROM push_deliveries WHERE status='claimed' AND claim_expires_at<datetime('now')")->fetchColumn();$add('stale_push_claims',$n,'warning');
            $n=(int)$db->query("SELECT COUNT(*) FROM (SELECT notification_id,subscription_id FROM push_deliveries GROUP BY notification_id,subscription_id HAVING COUNT(*)>1)")->fetchColumn();$add('duplicate_push_deliveries',$n);
        }
        if(in_array('task_attachments',$tables,true)){
            $missing=0;$outside=0;$base=realpath(self::root().'/storage/task-attachments');
            foreach($db->query('SELECT storage_key FROM task_attachments')->fetchAll(PDO::FETCH_COLUMN) as $key){$key=(string)$key;if(str_contains($key,'..')||str_starts_with($key,'/')||str_contains($key,"\0")){$outside++;continue;}$p=self::root().'/storage/task-attachments/'.$key;if(!is_file($p))$missing++;elseif($base!==false&&!str_starts_with((string)realpath($p),$base.'/'))$outside++;}
            $add('missing_attachment_files',$missing,'warning');$add('attachment_paths_outside_storage',$outside);
        }
        return ['migration_version'=>self::MIGRATION_VERSION,'generated_at'=>gmdate('c'),'checks'=>$checks,'summary'=>['failures'=>count(array_filter($checks,fn($c)=>$c['status']==='failure')),'warnings'=>count(array_filter($checks,fn($c)=>$c['status']==='warning'))]];
    }
    public static function backupFiles(): array
    {
        $candidates=[self::databasePath(),self::root().'/storage/employee-users/employees.json',self::root().'/data/tasks/tasks.json',self::root().'/storage/push/subscriptions.json',self::root().'/storage/push/deliveries.json',self::root().'/composer.json',self::root().'/composer.lock'];
        return array_values(array_filter($candidates,'is_file'));
    }
    public static function verifyBackup(string $dir): array
    {
        $manifest=$dir.'/manifest.json';if(!is_file($manifest))throw new RuntimeException('Backup manifest is missing.');
        $data=json_decode((string)file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);$errors=[];
        foreach(($data['files']??[]) as $item){$rel=(string)($item['path']??'');if($rel===''||str_contains($rel,'..')||str_starts_with($rel,'/')){$errors[]='unsafe_path';continue;}$path=$dir.'/'.$rel;if(!is_file($path)||!hash_equals((string)$item['sha256'],hash_file('sha256',$path))||filesize($path)!==(int)$item['bytes'])$errors[]=$rel;}
        $dbFile=$dir.'/database/app.sqlite';$integrity=null;if(is_file($dbFile))$integrity=(string)self::open($dbFile)->query('PRAGMA integrity_check')->fetchColumn();
        return ['valid'=>$errors===[]&&($integrity===null||$integrity==='ok'),'files'=>count($data['files']??[]),'integrity'=>$integrity,'errors'=>$errors];
    }
}
