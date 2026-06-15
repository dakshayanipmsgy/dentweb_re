<?php
declare(strict_types=1);
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/admin/includes/documents_helpers.php';
require_once __DIR__.'/includes/customer_document_acceptance.php';
require_admin();
if($_SERVER['REQUEST_METHOD']!=='POST'||!verify_csrf_token($_POST['csrf_token']??null)){http_response_code(403);exit('CSRF validation failed');}
$type=safe_text($_POST['type']??'');$id=safe_text($_POST['id']??'');$doc=null;$save=null;
if($type==='quotation'){$doc=documents_get_quote($id);$save='documents_save_quote';}elseif($type==='dispatch_advice'){$doc=documents_get_dispatch_advice($id);$save='documents_save_dispatch_advice';}elseif($type==='challan'){$doc=documents_get_challan($id);$save='documents_save_challan';}
if(!$doc||!$save){http_response_code(404);exit('Document not found');}
$token=customer_acceptance_issue_token($doc,$type);$doc['public_share_enabled']=true;if(empty($doc['public_share_token'])&&empty($doc['public_token'])){$doc[$type==='quotation'?'public_share_token':'public_token']=bin2hex(random_bytes(32));}$saved=$save($doc);if(!($saved['ok']??false)){http_response_code(500);exit('Unable to reissue link');}
header('Content-Type: application/json');echo json_encode(['ok'=>true,'message'=>'Customer confirmation link reissued.','acceptance_token'=>$token]);
