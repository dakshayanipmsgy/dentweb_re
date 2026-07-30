<?php
declare(strict_types=1);
require_once __DIR__.'/../admin/includes/documents_helpers.php';

function upl(bool $condition,string $message):void { if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);} }
$profile=documents_company_profile_path(); $profileBefore=is_file($profile)?file_get_contents($profile):false;
$links=documents_payment_request_links_path(); $requests=documents_payment_requests_path();
$linksBefore=is_file($links)?file_get_contents($links):null; $requestsBefore=is_file($requests)?file_get_contents($requests):null;
$restore=static function()use($links,$requests,$linksBefore,$requestsBefore,$profile,$profileBefore):void{foreach([[$links,$linksBefore],[$requests,$requestsBefore]] as [$p,$v]){if($v===null)@unlink($p);else file_put_contents($p,$v,LOCK_EX);}if($profileBefore!==false)upl(file_get_contents($profile)===$profileBefore,'company profile JSON remains byte-for-byte unchanged');}; register_shutdown_function($restore);
file_put_contents($links,"[]\n",LOCK_EX);
$request=array_merge(documents_payment_request_defaults(),['id'=>'PAYREQ-UPI-TEST','quotation_id'=>'QUOTE-42','customer_name'=>'Test Customer','amount_requested'=>1234.56,'outstanding_against_request'=>1234.56,'reason'=>'Advance Payment','due_date'=>'2030-01-02','status'=>'draft']);
upl(documents_save_payment_request($request)['ok'],'fixture request saved');
$made=documents_create_payment_request_link($request,['role'=>'admin','id'=>'test']);
upl(!empty($made['ok'])&&strlen($made['token'])>=43,'256-bit opaque token generated');
$stored=(string)file_get_contents($links); upl(!str_contains($stored,$made['token']),'raw token is never stored'); upl(str_contains($stored,hash('sha256',$made['token'])),'token hash stored');
$ua=['method'=>'GET','user_agent'=>'Mozilla/5.0'];
upl(documents_resolve_payment_request_link($made['token'],'access','browser-a',$ua)['ok'],'first access session');
upl(documents_resolve_payment_request_link($made['token'],'access','browser-a',$ua)['ok'],'refresh in same session');
upl(documents_resolve_payment_request_link($made['token'],'access','scanner',['method'=>'HEAD','user_agent'=>'link-preview-bot'])['ok'],'HEAD crawler preview ignored');
upl(documents_resolve_payment_request_link($made['token'],'access','browser-b',$ua)['ok'],'second access session');
upl(empty(documents_resolve_payment_request_link($made['token'],'access','browser-c',$ua)['ok']),'third access fails');
upl(documents_resolve_payment_request_link($made['token'],'launch','browser-a',$ua)['ok'],'first UPI launch');
upl(documents_resolve_payment_request_link($made['token'],'launch','browser-a',$ua)['ok'],'second UPI launch');
upl(empty(documents_resolve_payment_request_link($made['token'],'launch','browser-a',$ua)['ok']),'third UPI launch fails');
$uri=documents_payment_request_upi_uri($request); upl(str_contains($uri,'pa=d.entranchi%40ybl')&&str_contains($uri,'am=1234.56')&&str_contains($uri,'tn=PAYREQ-UPI-TEST'),'exact amount, destination and reference in server UPI URI');
$fresh=documents_create_payment_request_link($request); upl($fresh['ok'],'refresh creates generation'); upl(empty(documents_resolve_payment_request_link($made['token'],'inspect')['ok']),'refresh revokes old token');
$msg=documents_build_payment_request_message($request,[],documents_payment_request_public_url($fresh['token'])); upl(str_contains($msg,'Secure UPI Payment Link:'),'WhatsApp/email helper includes usable current URL');
$rows=json_decode((string)file_get_contents($links),true); foreach($rows as &$row)if(($row['token_hash']??'')===hash('sha256',$fresh['token']))$row['expires_at']=date('c',time()-1); unset($row); file_put_contents($links,json_encode($rows),LOCK_EX);
upl(empty(documents_resolve_payment_request_link($fresh['token'],'inspect')['ok']),'expired token fails');
upl(!str_contains(documents_build_payment_request_message($request,[],documents_payment_request_public_url($fresh['token'])),'Secure UPI Payment Link:'),'expired URL omitted from WhatsApp/email');
$forLifecycle=static function(string $status,bool $archived=false)use($request):void{$r=$request;$r['status']=$status;$r['archived_flag']=$archived;$m=documents_create_payment_request_link($request);upl($m['ok'],'lifecycle token created');documents_save_payment_request($r);upl(empty(documents_resolve_payment_request_link($m['token'],'inspect')['ok']),"$status/archive invalidates immediately");documents_save_payment_request($request);};
$forLifecycle('paid'); $forLifecycle('cancelled'); $forLifecycle('draft',true);
$beforeReceipts=json_encode(documents_final_receipts_for_quote('QUOTE-42')); $beforeRequest=documents_get_payment_request($request['id']); $mutationToken=documents_create_payment_request_link($request); documents_resolve_payment_request_link($mutationToken['token'],'launch','browser-z',$ua); upl(json_encode(documents_final_receipts_for_quote('QUOTE-42'))===$beforeReceipts&&documents_get_payment_request($request['id'])==$beforeRequest,'UPI launch creates no receipt or payment mutation');
$helperSource=(string)file_get_contents(__DIR__.'/../admin/includes/documents_helpers.php'); upl(str_contains($helperSource,'flock($handle, LOCK_EX)'),'counter updates use exclusive locking for concurrent requests');
upl($profileBefore===false||file_get_contents($profile)===$profileBefore,'company profile JSON unchanged during test');
echo "Payment request UPI link tests passed\n";
