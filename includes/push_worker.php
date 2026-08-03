<?php
declare(strict_types=1);
require_once __DIR__.'/push_notifications.php';

interface PushDeliveryTransport { public function send(array $delivery,array $payload): array; }

final class MinishlinkPushTransport implements PushDeliveryTransport
{
    public function __construct(private array $config){}
    public function send(array $delivery,array $payload):array
    {
        $webPush=new Minishlink\WebPush\WebPush(['VAPID'=>['subject'=>$this->config['vapid_subject'],'publicKey'=>$this->config['vapid_public'],'privateKey'=>$this->config['vapid_private']]]);
        $subscription=Minishlink\WebPush\Subscription::create(['endpoint'=>push_decrypt($delivery['endpoint_encrypted']),'publicKey'=>push_decrypt($delivery['p256dh_encrypted']),'authToken'=>push_decrypt($delivery['auth_encrypted']),'contentEncoding'=>'aes128gcm']);
        $webPush->queueNotification($subscription,json_encode($payload,JSON_THROW_ON_ERROR));
        foreach($webPush->flush() as $result)return ['success'=>$result->isSuccess(),'status'=>(int)($result->getResponse()?->getStatusCode()??0)];
        return ['success'=>false,'status'=>0,'category'=>'no_report'];
    }
}

function push_worker_lease_acquire(PDO $db,string $token,int $seconds=300):bool{$db->exec('BEGIN IMMEDIATE');try{$s=$db->prepare("INSERT INTO push_worker_leases(lease_key,lease_token,lease_expires_at,updated_at) VALUES('global',?,datetime('now',?),datetime('now')) ON CONFLICT(lease_key) DO UPDATE SET lease_token=excluded.lease_token,lease_expires_at=excluded.lease_expires_at,updated_at=excluded.updated_at WHERE push_worker_leases.lease_expires_at<=datetime('now')");$s->execute([$token,'+'.$seconds.' seconds']);$q=$db->prepare("SELECT 1 FROM push_worker_leases WHERE lease_key='global' AND lease_token=? AND lease_expires_at>datetime('now')");$q->execute([$token]);$ok=(bool)$q->fetchColumn();$db->commit();return $ok;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}}
function push_worker_lease_renew(PDO $db,string $token,int $seconds=300):bool{$s=$db->prepare("UPDATE push_worker_leases SET lease_expires_at=datetime('now',?),updated_at=datetime('now') WHERE lease_key='global' AND lease_token=? AND lease_expires_at>datetime('now')");$s->execute(['+'.$seconds.' seconds',$token]);return $s->rowCount()===1;}
function push_worker_lease_owned(PDO $db,string $token):bool{$s=$db->prepare("SELECT 1 FROM push_worker_leases WHERE lease_key='global' AND lease_token=? AND lease_expires_at>datetime('now')");$s->execute([$token]);return(bool)$s->fetchColumn();}
function push_worker_lease_release(PDO $db,string $token):void{$s=$db->prepare("DELETE FROM push_worker_leases WHERE lease_key='global' AND lease_token=?");$s->execute([$token]);}

function push_worker_claim(PDO $db,string $token,int $limit,?int $notificationId=null):array
{
    $db->exec('BEGIN IMMEDIATE');try{$db->exec("UPDATE push_deliveries SET status='retry',claim_token=NULL,claim_expires_at=NULL,updated_at=datetime('now'),last_failure_category='stale_claim' WHERE status='claimed' AND claim_expires_at<=datetime('now')");$sql="SELECT id FROM push_deliveries WHERE status IN ('pending','retry') AND COALESCE(next_attempt_at,datetime('now'))<=datetime('now')".($notificationId?' AND notification_id='.(int)$notificationId:'')." ORDER BY id LIMIT ".max(1,min(100,$limit));$ids=$db->query($sql)->fetchAll(PDO::FETCH_COLUMN);$claim=$db->prepare("UPDATE push_deliveries SET status='claimed',claim_token=?,claim_expires_at=datetime('now','+2 minutes'),attempt_count=attempt_count+1,updated_at=datetime('now') WHERE id=? AND status IN ('pending','retry') AND COALESCE(next_attempt_at,datetime('now'))<=datetime('now')");foreach($ids as $id)$claim->execute([$token,$id]);$q=$db->prepare("SELECT id FROM push_deliveries WHERE claim_token=? AND status='claimed' ORDER BY id LIMIT ?");$q->bindValue(1,$token);$q->bindValue(2,max(1,min(100,$limit)),PDO::PARAM_INT);$q->execute();$actual=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));$db->commit();return $actual;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function push_worker_run(PDO $db,PushDeliveryTransport $transport,int $limit=25,?int $notificationId=null):array
{
    $report=['synchronized'=>push_synchronize($db,500,$notificationId,false),'claimed'=>0,'sent'=>0,'temporary_failures'=>0,'invalidated'=>0,'skipped'=>0];$token=bin2hex(random_bytes(16));
    if(!push_worker_lease_acquire($db,$token))return $report+['status'=>'worker-busy'];
    try{$ids=push_worker_claim($db,$token,$limit,$notificationId);$report['claimed']=count($ids);
        foreach($ids as $id){if(!push_worker_lease_renew($db,$token)||!push_worker_lease_owned($db,$token))break;
            $q=$db->prepare("SELECT d.*,p.user_id,p.endpoint_encrypted,p.p256dh_encrypted,p.auth_encrypted,n.notification_type,s.status notification_status,s.dismissed_at FROM push_deliveries d JOIN push_subscriptions p ON p.id=d.subscription_id JOIN portal_notifications n ON n.id=d.notification_id JOIN portal_notification_status s ON s.notification_id=n.id AND s.user_id=p.user_id WHERE d.id=? AND d.claim_token=? AND d.status='claimed' AND p.status='active'");$q->execute([$id,$token]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)continue;
            $copy=push_safe_copy((string)$row['notification_type']);if($row['notification_status']!=='unread'||$row['dismissed_at']!==null||$copy===null){$reason=$copy===null?'unsupported_notification_type':'not_eligible';$u=$db->prepare("UPDATE push_deliveries SET status='skipped',skipped_at=datetime('now'),claim_token=NULL,claim_expires_at=NULL,updated_at=datetime('now'),last_failure_category=? WHERE id=? AND claim_token=?");$u->execute([$reason,$id,$token]);$report['skipped']+=$u->rowCount();continue;}
            $payload=$copy+['notification_id'=>(int)$row['notification_id'],'unread_count'=>min(99,push_unread_count($db,(int)$row['user_id']))];try{$result=$transport->send($row,$payload);}catch(Throwable){$result=['success'=>false,'status'=>0,'category'=>'transport_error'];}$code=max(0,(int)($result['status']??0));$success=($result['success']??false)===true;
            $db->beginTransaction();try{if($success){$u=$db->prepare("UPDATE push_deliveries SET status='sent',sent_at=datetime('now'),updated_at=datetime('now'),claim_token=NULL,claim_expires_at=NULL,last_response_status=? WHERE id=? AND claim_token=?");$u->execute([$code?:201,$id,$token]);$report['sent']+=$u->rowCount();}elseif(in_array($code,[404,410],true)){$u=$db->prepare("UPDATE push_deliveries SET status='invalid',invalidated_at=datetime('now'),updated_at=datetime('now'),claim_token=NULL,claim_expires_at=NULL,last_response_status=?,last_failure_category='permanent' WHERE id=? AND claim_token=?");$u->execute([$code,$id,$token]);if($u->rowCount()){$db->prepare("UPDATE push_subscriptions SET status='expired',invalidated_at=datetime('now'),updated_at=datetime('now'),failure_count=failure_count+1,last_response_status=?,last_failure_category='permanent' WHERE id=?")->execute([$code,$row['subscription_id']]);$db->prepare("UPDATE push_deliveries SET status='skipped',skipped_at=datetime('now'),updated_at=datetime('now'),claim_token=NULL,claim_expires_at=NULL,last_failure_category='subscription_inactive' WHERE subscription_id=? AND id<>? AND status IN ('pending','retry','claimed')")->execute([$row['subscription_id'],$id]);$report['invalidated']++;}}else{$attempt=(int)$row['attempt_count'];$terminal=$attempt>=PUSH_MAX_ATTEMPTS;$category=in_array($result['category']??'',['no_report','transport_error'],true)?$result['category']:'temporary';$u=$db->prepare("UPDATE push_deliveries SET status=?,next_attempt_at=datetime('now',?),updated_at=datetime('now'),claim_token=NULL,claim_expires_at=NULL,last_response_status=?,last_failure_category=? WHERE id=? AND claim_token=?");$u->execute([$terminal?'failed':'retry','+'.push_backoff_seconds($attempt).' seconds',$code?:null,$category,$id,$token]);$report['temporary_failures']+=$u->rowCount();}$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        }return $report+['status'=>'ok'];
    }finally{push_worker_lease_release($db,$token);}
}
