<?php
declare(strict_types=1);

require_once __DIR__.'/push_notifications.php';

function push_legacy_envelope_values(array $row): ?array
{
    try {
        foreach (['endpoint','p256dh','auth'] as $field) {
            if (!is_string($row[$field]??null) || !preg_match('/^v1\.[A-Za-z0-9_-]+$/D',$row[$field])) return null;
        }
        $endpoint=push_decrypt($row['endpoint']);$p256dh=push_decrypt($row['p256dh']);$auth=push_decrypt($row['auth']);
        if (!push_endpoint_valid($endpoint) || !push_base64url_key_valid($p256dh,40,200) || !push_base64url_key_valid($auth,16,100)) return null;
        if (!hash_equals((string)($row['endpoint_hash']??''),hash('sha256',$endpoint))) return null;
        return [$endpoint,$p256dh,$auth];
    } catch (Throwable) { return null; }
}

function push_migrate_legacy(PDO $db,string $dir,bool $apply=false): array
{
    push_initialize_schema($db);
    $report=['mode'=>$apply?'apply':'dry-run','files'=>0,'subscriptions_inserted'=>0,'subscriptions_existing'=>0,'deliveries_imported'=>0,'duplicate_deliveries'=>0,'invalid_rows'=>0,'orphaned_users'=>0,'orphaned_notifications'=>0,'ownership_conflicts'=>0,'malformed_envelopes'=>0,'malformed_json'=>0,'already_complete'=>false,'backups'=>[]];
    $done=$db->query("SELECT meta_value FROM push_migration_meta WHERE meta_key='legacy_json_completed'")->fetchColumn();
    if($done!==false){$report['already_complete']=true;return $report;}
    $read=static function(string $name)use($dir,&$report):array{$path=$dir.'/'.$name;if(!is_file($path))return [];$report['files']++;$raw=file_get_contents($path);if($raw===false){$report['malformed_json']++;return [];}try{$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){$report['malformed_json']++;return [$path,$raw,[]];}if(!is_array($data)||!array_is_list($data)){$report['malformed_json']++;return [$path,$raw,[]];}return [$path,$raw,$data];};
    $subsFile=$read('subscriptions.json');$deliveriesFile=$read('deliveries.json');$idMap=[];$blocked=[];$prospective=-1;
    if($apply)$db->beginTransaction();
    try{
        foreach(($subsFile[2]??[]) as $row){
            $legacyId=is_array($row)?(string)($row['id']??''):'';
            if(!is_array($row)||$legacyId===''||!filter_var($row['user_id']??null,FILTER_VALIDATE_INT)||!preg_match('/^[a-f0-9]{64}$/D',(string)($row['endpoint_hash']??''))||!in_array($row['status']??'',['active','revoked','expired','invalid'],true)){$report['invalid_rows']++;$blocked[$legacyId]=true;continue;}
            $uid=(int)$row['user_id'];if(!push_employee_active($db,$uid)){$report['orphaned_users']++;$blocked[$legacyId]=true;continue;}
            if(push_legacy_envelope_values($row)===null){$report['malformed_envelopes']++;$blocked[$legacyId]=true;continue;}
            $q=$db->prepare('SELECT id,user_id,status FROM push_subscriptions WHERE endpoint_hash=?');$q->execute([$row['endpoint_hash']]);$existing=$q->fetch(PDO::FETCH_ASSOC);
            if($existing){if((int)$existing['user_id']!==$uid){$report['ownership_conflicts']++;$blocked[$legacyId]=true;continue;}$idMap[$legacyId]=(int)$existing['id'];$report['subscriptions_existing']++;continue;}
            $mapped=$prospective--;
            if($apply){$s=$db->prepare("INSERT INTO push_subscriptions(user_id,endpoint_hash,endpoint_encrypted,p256dh_encrypted,auth_encrypted,envelope_version,device_label,user_agent,status,created_at,updated_at,last_used_at,revoked_at,failure_count,last_failure_category) VALUES(?,?,?,?,?,1,?,?,?,?,?,?,?,?,?)");$now=gmdate('Y-m-d H:i:s');$s->execute([$uid,$row['endpoint_hash'],$row['endpoint'],$row['p256dh'],$row['auth'],push_safe_text((string)($row['label']??''),60)?:'Imported browser',push_safe_text((string)($row['user_agent']??''),160),$row['status'],(string)($row['created_at']??$now),(string)($row['updated_at']??$row['created_at']??$now),$row['last_used_at']??null,$row['revoked_at']??null,max(0,(int)($row['failure_count']??0)),push_safe_text((string)($row['last_failure_code']??''),40)?:null]);$mapped=(int)$db->lastInsertId();}
            $idMap[$legacyId]=$mapped;$report['subscriptions_inserted']++;
        }
        $seen=[];foreach(($deliveriesFile[2]??[]) as $row){
            if(!is_array($row)||($nid=filter_var($row['notification_id']??null,FILTER_VALIDATE_INT))===false||$nid<1||($legacyId=(string)($row['subscription_id']??''))===''){$report['invalid_rows']++;continue;}
            if(isset($blocked[$legacyId])||!isset($idMap[$legacyId]))continue;
            $q=$db->prepare('SELECT 1 FROM portal_notifications WHERE id=?');$q->execute([$nid]);if(!$q->fetchColumn()){$report['orphaned_notifications']++;continue;}
            $sid=$idMap[$legacyId];$key=$nid.':'.$sid;if(isset($seen[$key])){$report['duplicate_deliveries']++;continue;}$seen[$key]=true;
            if($sid>0){$q=$db->prepare('SELECT 1 FROM push_deliveries WHERE notification_id=? AND subscription_id=?');$q->execute([$nid,$sid]);if($q->fetchColumn()){$report['duplicate_deliveries']++;continue;}}
            if($apply){$status=match($row['status']??'pending'){'sent'=>'sent','invalid'=>'invalid','skipped'=>'skipped',default=>'pending'};$now=gmdate('Y-m-d H:i:s');$s=$db->prepare('INSERT INTO push_deliveries(notification_id,subscription_id,status,attempt_count,next_attempt_at,created_at,updated_at,sent_at) VALUES(?,?,?,?,?,?,?,?)');$s->execute([$nid,$sid,$status,max(0,(int)($row['attempt_count']??0)),$row['next_attempt_at']??null,$row['created_at']??$now,$row['updated_at']??$row['created_at']??$now,$row['sent_at']??null]);}
            $report['deliveries_imported']++;
        }
        if($apply){$stamp=gmdate('YmdHis');foreach([$subsFile,$deliveriesFile] as $file)if($file){$backup=$file[0].'.backup-'.$stamp;if(file_put_contents($backup,$file[1],LOCK_EX)!==strlen($file[1]))throw new RuntimeException('Legacy backup failed.');chmod($backup,0400);chmod($file[0],0400);$checksum=hash('sha256',$file[1]);$report['backups'][]=['file'=>basename($backup),'sha256'=>$checksum];$m=$db->prepare("INSERT OR REPLACE INTO push_migration_meta(meta_key,meta_value,updated_at) VALUES(?,?,datetime('now'))");$m->execute(['legacy_checksum_'.basename($file[0]),$checksum]);}$m=$db->prepare("INSERT INTO push_migration_meta(meta_key,meta_value,updated_at) VALUES('legacy_json_completed',?,datetime('now'))");$m->execute([gmdate(DATE_ATOM)]);$db->commit();}
        return $report;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
