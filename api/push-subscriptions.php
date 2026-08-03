<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/push_notifications.php';
send_private_workspace_headers(); header('Content-Type: application/json; charset=utf-8'); header('X-Content-Type-Options: nosniff');
try {
    start_session(); $user=current_user();
    if(!$user){http_response_code(401);throw new RuntimeException('Authentication required.');}
    if(($user['role_name']??'')!=='employee'){http_response_code(403);throw new RuntimeException('Employee access required.');}
    if(!hash_equals(csrf_token(),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))){http_response_code(419);throw new RuntimeException('Invalid CSRF token.');}
    $uid=(int)$user['id'];$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));$action=(string)($_GET['action']??'status');$cfg=push_config();
    if($method==='GET'){
        if($action==='status')$data=['enabled'=>$cfg['enabled'],'available'=>$cfg['enabled']&&$cfg['encryption_ready']&&$cfg['dependency_ready']&&$cfg['vapid_public']!=='','vapid_public_key'=>$cfg['enabled']?$cfg['vapid_public']:'','devices'=>push_devices($uid)];
        elseif($action==='devices')$data=['devices'=>push_devices($uid)];else{http_response_code(404);throw new RuntimeException('Unknown endpoint.');}
    }elseif($method==='POST'){
        if(stripos((string)($_SERVER['CONTENT_TYPE']??''),'application/json')!==0){http_response_code(415);throw new RuntimeException('JSON content type required.');}
        $raw=(string)file_get_contents('php://input');$body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($body)||!str_starts_with(ltrim($raw),'{'))throw new InvalidArgumentException('JSON object required.');
        if($action==='subscribe'){
            if(!$cfg['enabled']||!$cfg['encryption_ready']){http_response_code(503);throw new RuntimeException('Push is not configured.');}
            $last=(int)($_SESSION['push_registration_at']??0);if(time()-$last<2){http_response_code(429);throw new RuntimeException('Please wait before registering again.');}$_SESSION['push_registration_at']=time();
            $data=['device'=>push_register($uid,$body['subscription']??[] ,(string)($body['label']??''),(string)($_SERVER['HTTP_USER_AGENT']??''))];
        }elseif($action==='revoke'){$id=(string)($body['id']??'');if(!preg_match('/^[a-f0-9]{24}$/D',$id))throw new InvalidArgumentException('Invalid device ID.');$data=['revoked'=>push_revoke($uid,$id)];}
        elseif($action==='revoke-all')$data=['revoked'=>push_revoke($uid,null)];else{http_response_code(404);throw new RuntimeException('Unknown endpoint.');}
    }else{http_response_code(405);header('Allow: GET, POST');throw new RuntimeException('Method not allowed.');}
    echo json_encode(['ok'=>true,'data'=>$data],JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){if(http_response_code()<400)http_response_code($e instanceof InvalidArgumentException||$e instanceof JsonException?422:500);echo json_encode(['ok'=>false,'error'=>['code'=>'push_request_failed','message'=>http_response_code()>=500?'Push request failed.':$e->getMessage()]]);}
