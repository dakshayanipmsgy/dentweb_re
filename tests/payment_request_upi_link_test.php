<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$assertions=0;
function upi_ok(bool $condition,string $message): void { global $assertions; $assertions++; if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);} }
$path=documents_payment_requests_path(); $backup=is_file($path)?file_get_contents($path):null;
$profilePath=documents_settings_dir().'/company_profile.json'; $profileHash=is_file($profilePath)?hash_file('sha256',$profilePath):'';
$receiptPath=documents_sales_receipts_store_path(); $receiptHash=is_file($receiptPath)?hash_file('sha256',$receiptPath):'';
$auditPath=dirname(__DIR__).'/storage/audit-log.jsonl'; $auditBackup=is_file($auditPath)?file_get_contents($auditPath):null;
$parentPid=getmypid();
register_shutdown_function(static function() use($path,$backup,$auditPath,$auditBackup,$parentPid): void { if(getmypid()!==$parentPid)return; if($backup===null) @unlink($path); else file_put_contents($path,$backup); if($auditBackup===null) @unlink($auditPath); else file_put_contents($auditPath,$auditBackup); @unlink($path.'.lock'); });

$request=array_merge(documents_payment_request_defaults(),['id'=>'PAYREQ-UPI-888','quotation_id'=>'QUOTE-888','customer_name'=>'Safe Customer','amount_requested'=>1234.50,'reason'=>'Advance Payment','due_date'=>'2026-08-01','status'=>'draft','internal_notes'=>'SECRET NOTE','customer_response'=>'SECRET RESPONSE']);
file_put_contents($path,json_encode([$request],JSON_PRETTY_PRINT));
$created=documents_payment_request_generate_upi_link($request['id'],1000000);
upi_ok(!empty($created['ok']) && strlen($created['token'])===64,'generation returns 256 random bits');
$stored=(string)file_get_contents($path);
upi_ok(!str_contains($stored,$created['token']) && str_contains($stored,hash('sha256',$created['token'])),'only token hash is stored');
$loaded=documents_payment_request_find_by_token($created['token']);
upi_ok($loaded!==null && (string)$loaded['id']===$request['id'],'token is bound to one request');
upi_ok(strtotime((string)$loaded['upi_link']['expires_at'])===1086400,'link expires after exactly 24 hours');

$first=documents_payment_request_authorize_upi($created['token'],1000001);
upi_ok(!empty($first['ok']) && $first['remaining']===1,'first launch succeeds');
upi_ok($first['upi_uri']==='upi://pay?pa=d.entranchi%40ybl&pn=Dakshayani%20Enterprises&am=1234.50&cu=INR&tn=PAYREQ-UPI-888','URI uses exact server amount and configured payee');
$second=documents_payment_request_authorize_upi($created['token'],1000002); $third=documents_payment_request_authorize_upi($created['token'],1000003);
upi_ok(!empty($second['ok']) && empty($third['ok']),'exactly two launches are enforced');

// Start three authorizations together; the store lock and in-lock count update must permit only two.
$concurrent=documents_payment_request_generate_upi_link($request['id'],1000005); $resultFiles=[]; $children=[]; $gate=tempnam(sys_get_temp_dir(),'upi_gate_'); @unlink($gate);
if(function_exists('pcntl_fork')) {
    for($i=0;$i<3;$i++){ $resultFiles[$i]=tempnam(sys_get_temp_dir(),'upi_result_'); $pid=pcntl_fork(); if($pid===0){while(!is_file($gate))usleep(1000);$r=documents_payment_request_authorize_upi($concurrent['token'],1000006);file_put_contents($resultFiles[$i],!empty($r['ok'])?'1':'0');exit(0);} $children[]=$pid; }
    touch($gate); foreach($children as $pid)pcntl_waitpid($pid,$status); $successes=0; foreach($resultFiles as $file){$successes+=(int)file_get_contents($file);@unlink($file);} @unlink($gate);
    upi_ok($successes===2,'three concurrent clicks cannot exceed two authorizations');
} else { upi_ok(str_contains((string)file_get_contents(__DIR__.'/../admin/includes/documents_helpers.php'),'flock($handle, LOCK_EX)'),'exclusive locking is present when pcntl concurrency is unavailable'); }

$refreshed=documents_payment_request_generate_upi_link($request['id'],1000010);
upi_ok(!empty($refreshed['ok']) && empty(documents_payment_request_authorize_upi($created['token'],1000011)['ok']),'refresh immediately revokes old token');
upi_ok(!empty(documents_payment_request_authorize_upi($refreshed['token'],1000011)['ok']),'refresh starts at zero uses');
$expired=documents_payment_request_generate_upi_link($request['id'],100);
upi_ok(empty(documents_payment_request_authorize_upi($expired['token'],86501)['ok']),'expired token is rejected');

foreach(['paid','cancelled'] as $status){$request['status']=$status;$request['upi_link']=documents_get_payment_request($request['id'])['upi_link'];file_put_contents($path,json_encode([$request]));upi_ok(empty(documents_payment_request_authorize_upi($expired['token'],101)['ok']),"$status request invalidates link");}
$request['status']='draft';$request['archived_flag']=true;file_put_contents($path,json_encode([$request]));
upi_ok(empty(documents_payment_request_authorize_upi($expired['token'],101)['ok']),'archived request invalidates link');

$url='https://example.test/payment-request.php?token=opaque'; $message=documents_build_payment_request_message($request,[],$url);
upi_ok(str_contains($message,$url) && !str_contains($message,'upi://pay'),'shared message uses public page rather than raw UPI URI');
upi_ok(str_contains(documents_payment_request_whatsapp_url($request,$message),rawurlencode($url)),'WhatsApp contains encoded public URL');
upi_ok(str_contains(documents_payment_request_mailto($request,$message),rawurlencode($url)),'email contains encoded public URL');
$publicSource=(string)file_get_contents(__DIR__.'/../payment-request.php');
upi_ok(!str_contains($publicSource,'internal_notes') && !str_contains($publicSource,'customer_response') && str_contains($publicSource,"documents_payment_request_authorize_upi"),'public page reveals URI only after authorization and omits private fields');
upi_ok(($profileHash==='' || hash_file('sha256',$profilePath)===$profileHash),'company profile is unchanged');
upi_ok(($receiptHash==='' || hash_file('sha256',$receiptPath)===$receiptHash),'UPI authorization creates no receipt');
upi_ok(!str_contains((string)@file_get_contents(documents_logs_dir().'/documents.log'),$created['token']),'audit/log output contains no raw token');
echo "payment_request_upi_link_test passed ($assertions assertions)\n";
