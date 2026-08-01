<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/task_workflow.php';
send_private_workspace_headers();header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');
try {
    ensure_api_access('');$user=current_user();$uid=(int)($user['id']??0);$role=(string)($user['role_name']??'');
    if($uid<1||!in_array($role,['admin','employee'],true)){http_response_code(403);throw new RuntimeException('Notification access is unavailable.');}
    $db=get_db();task_notification_fallback($db);$action=(string)($_GET['action']??'list');
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if($action==='count')$data=['unread_count'=>task_notification_unread_count($db,$uid)];
        elseif($action==='list'){$view=in_array($_GET['view']??'all',['all','unread'],true)?(string)$_GET['view']:'all';$page=max(1,(int)($_GET['page']??1));$items=task_notification_list($db,$uid,$view,$page);$data=['notifications'=>$items,'page'=>$page,'view'=>$view,'has_more'=>count($items)===20,'unread_count'=>task_notification_unread_count($db,$uid)];}
        else{http_response_code(404);throw new RuntimeException('Unknown endpoint.');}
    } elseif($_SERVER['REQUEST_METHOD']==='POST') {
        $payload=json_decode((string)file_get_contents('php://input'),true)?:[];$id=isset($payload['id'])?(int)$payload['id']:null;
        task_notification_mutate($db,$uid,$action,$id);$data=['unread_count'=>task_notification_unread_count($db,$uid)];
    } else {http_response_code(405);header('Allow: GET, POST');throw new RuntimeException('Method not allowed.');}
    echo json_encode(['ok'=>true,'data'=>$data],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
} catch(OutOfBoundsException $e){http_response_code(404);echo json_encode(['ok'=>false,'error'=>['code'=>'not_found','message'=>$e->getMessage()]]);} catch(Throwable $e){if(http_response_code()<400)http_response_code($e instanceof InvalidArgumentException?422:500);echo json_encode(['ok'=>false,'error'=>['code'=>'request_failed','message'=>http_response_code()>=500?'Notification request failed.':$e->getMessage()]]);}
