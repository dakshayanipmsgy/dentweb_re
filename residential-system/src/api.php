<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';
header('Cache-Control: no-store');header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
session_name('DE_RESIDENTIAL');session_start(['cookie_httponly'=>true,'cookie_secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','cookie_samesite'=>'Strict']);
if(empty($_SESSION['res_csrf']))$_SESSION['res_csrf']=bin2hex(random_bytes(32));
try{
 if($_SERVER['REQUEST_METHOD']==='GET'){echo json_encode(['csrf'=>$_SESSION['res_csrf'],'mode'=>r_config()['mode']]);exit;}
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
 if((int)($_SERVER['CONTENT_LENGTH']??0)>8192){http_response_code(413);exit;}
 $a=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
 if(!is_array($a)||!is_string($a['csrf']??null)||!hash_equals($_SESSION['res_csrf'],$a['csrf'])){http_response_code(403);echo json_encode(['error'=>'Refresh the form before submitting.']);exit;}
 if(($a['action']??'')==='handoff'){
  $id=r_text($a,'reference',24);if(empty($_SESSION['res_receipts'][$id])){http_response_code(403);exit;}
  r_state(function(&$s)use($id){if(!isset($s['leads'][$id]))throw new RuntimeException('Record not found.');$l=&$s['leads'][$id];if(empty($l['handoff_clicked_at'])){$l['handoff_clicked_at']=gmdate('c');$l['version']++;$s['events'][]=['id'=>'handoff-'.$id,'lead'=>$id,'type'=>'whatsapp_handoff_click','at'=>gmdate('c'),'mode'=>$l['mode']];}});echo json_encode(['recorded'=>'click_only']);exit;
 }
 $result=r_accept($a,$_SERVER['REMOTE_ADDR']??'unknown');$_SESSION['res_receipts'][$result['reference']]=true;http_response_code(201);echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code($e instanceof RuntimeException?422:503);echo json_encode(['error'=>$e instanceof RuntimeException?$e->getMessage():'Could not confirm saving. Retry with the same details or call 7070278178.']);}
