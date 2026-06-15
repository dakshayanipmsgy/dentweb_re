<?php
declare(strict_types=1);

const CUSTOMER_ACCEPTANCE_WHATSAPP_TARGET = '917070278178';
const CUSTOMER_ACCEPTANCE_TERMS_VERSION = '2026-06-15';

function customer_acceptance_document_hash(string $type, array $document): string
{
    $omit = ['customer_acceptance', 'customer_acceptance_request', 'public_token', 'public_share_token', 'share_audit', 'updated_at'];
    foreach ($omit as $key) unset($document[$key]);
    return hash('sha256', json_encode(['type'=>$type, 'document'=>$document], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}
function customer_acceptance_issue_token(array &$document, string $type, int $ttlDays = 30): string
{
    $token = bin2hex(random_bytes(32));
    $document['customer_acceptance_request'] = ['token_hash'=>hash('sha256',$token),'document_hash'=>customer_acceptance_document_hash($type,$document),'document_version'=>(int)($document['version_no']??$document['revision_no']??1),'expires_at'=>date('c',time()+86400*$ttlDays),'issued_at'=>date('c'),'incorrect_mobile_attempts'=>0,'locked_at'=>''];
    return $token;
}
function customer_acceptance_is_locked(array $document): bool { return !empty($document['customer_acceptance_request']['locked_at']) || (int)($document['customer_acceptance_request']['incorrect_mobile_attempts']??0)>=3; }
function customer_acceptance_check_submission(array &$document,string $type,array $input,string $token,array $context=[]): array
{
    if(customer_acceptance_is_locked($document))return ['ok'=>false,'code'=>'locked','message'=>'Acceptance is locked. Ask an administrator to reissue a fresh confirmation link.'];
    if(!customer_acceptance_validate_token($document,$type,$token))return ['ok'=>false,'code'=>'invalid_token','message'=>'This confirmation link is no longer valid.'];
    if(empty($input['confirmed']))return ['ok'=>false,'code'=>'missing_confirmation','message'=>'The confirmation statement must be accepted.'];
    $mobile=customer_acceptance_normalize_mobile((string)($document['customer_mobile']??$document['customer_snapshot']['mobile']??''));$entered=preg_replace('/\D+/','',(string)($input['mobile_first6']??''))??'';
    if(strlen($entered)!==6||!hash_equals(substr($mobile,0,6),$entered)){$attempts=(int)($document['customer_acceptance_request']['incorrect_mobile_attempts']??0)+1;$document['customer_acceptance_request']['incorrect_mobile_attempts']=$attempts;if($attempts>=3)$document['customer_acceptance_request']['locked_at']=date('c');return ['ok'=>false,'code'=>$attempts>=3?'locked':'incorrect_mobile','message'=>$attempts>=3?'Acceptance is locked. Ask an administrator to reissue a fresh confirmation link.':'The confirmation details do not match our record.','save'=>true];}
    try{customer_acceptance_record($document,$type,$input,$context);return ['ok'=>true,'code'=>'accepted'];}catch(Throwable $e){return ['ok'=>false,'code'=>'server_error','message'=>'Unable to record confirmation. Please try again.'];}
}
function customer_acceptance_validate_token(array $document, string $type, string $token): bool
{
    $r=(array)($document['customer_acceptance_request']??[]); $hash=(string)($r['token_hash']??'');
    return $token!=='' && $hash!=='' && hash_equals($hash,hash('sha256',$token)) && (empty($r['expires_at'])||strtotime((string)$r['expires_at'])>=time()) && hash_equals((string)($r['document_hash']??''),customer_acceptance_document_hash($type,$document));
}
function customer_acceptance_normalize_mobile(string $mobile): string { $n=preg_replace('/\D+/','',$mobile)??''; return substr($n,-10); }
function customer_acceptance_mask_mobile(string $mobile): string { $n=customer_acceptance_normalize_mobile($mobile); return $n===''?'':('******'.substr($n,-4)); }
function customer_change_request_reference(): string { return 'CHG-QTN-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3))); }
function customer_change_request_whatsapp_url(array $request, string $publicLink=''): string { return 'https://wa.me/'.CUSTOMER_ACCEPTANCE_WHATSAPP_TARGET.'?text='.rawurlencode("Quotation change request recorded.\nReference: ".($request['request_ref']??'')."\nQuotation: ".($request['quotation_no']??'')."\nRequested changes: ".($request['requested_changes']??'')."\nDocument link: ".$publicLink); }
function customer_acceptance_reference(string $type): string { $p=['quotation'=>'QTN','dispatch_advice'=>'DA','challan'=>'CHL'][$type]??'DOC'; return 'ACC-'.$p.'-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3))); }
function customer_acceptance_confirmation_text(string $type): string { return ['quotation'=>'I have reviewed this quotation and accept the offered scope, price and terms shown in this version.','dispatch_advice'=>'I have reviewed the items listed in this Material Dispatch Advice and confirm that these are the items planned for dispatch. I understand that this is not proof of delivery.','challan'=>'I confirm that I have received the items listed in this Delivery Challan, subject to any remarks entered below.'][$type]??''; }
function customer_acceptance_record(array &$document,string $type,array $input,array $context=[]): array
{
    if (!empty($document['customer_acceptance']['confirmed_at'])) return $document['customer_acceptance'];
    $mobile=customer_acceptance_normalize_mobile((string)($document['customer_mobile']??$document['customer_snapshot']['mobile']??'')); $name=trim((string)($document['customer_name']??$document['customer_name_snapshot']??$document['customer_snapshot']['name']??''));
    $portal=!empty($context['portal_customer_id']) && (string)$context['portal_customer_id']===(string)($document['customer_id']??$document['customer_snapshot']['id']??'');
    if(!$portal && !hash_equals(substr($mobile,0,6),preg_replace('/\D+/','',(string)($input['mobile_first6']??''))??'')) throw new RuntimeException('The confirmation details do not match our record.');
    if(empty($input['confirmed'])) throw new RuntimeException('The confirmation statement must be accepted.');
    $document['customer_mobile']=$mobile; $now=date('c'); $ref=customer_acceptance_reference($type); $remarks=trim((string)($input['remarks']??''));
    $document['customer_acceptance']=['status'=>'whatsapp_pending','acceptance_ref'=>$ref,'document_type'=>$type,'document_id'=>(string)($document['id']??''),'document_no'=>(string)($document['quote_no']??$document['dispatch_advice_no']??$document['challan_no']??$document['dc_number']??''),'document_version'=>(int)($document['version_no']??$document['revision_no']??1),'document_hash'=>customer_acceptance_document_hash($type,$document),'customer_id'=>(string)($document['customer_id']??$document['customer_snapshot']['id']??''),'customer_name_snapshot'=>$name,'customer_mobile_snapshot'=>customer_acceptance_mask_mobile($mobile),'identity_method'=>$portal?'customer_portal':'secure_link','portal_user_id'=>(string)($context['portal_customer_id']??''),'confirmed_name'=>$name,'confirmed_mobile_last4'=>substr($mobile,-4),'confirmed_at'=>$now,'ip_hash'=>hash_hmac('sha256',(string)($context['ip']??''),(string)($context['salt']??'acceptance')),'user_agent_hash'=>hash('sha256',(string)($context['user_agent']??'')),'token_id_hash'=>(string)($document['customer_acceptance_request']['token_hash']??''),'public_token_hash'=>hash('sha256',(string)($document['public_share_token']??$document['public_token']??'')),'confirmation_text_snapshot'=>customer_acceptance_confirmation_text($type),'terms_version'=>CUSTOMER_ACCEPTANCE_TERMS_VERSION,'customer_remarks'=>$remarks,'review_required'=>$type==='challan'&&$remarks!=='','whatsapp_target'=>CUSTOMER_ACCEPTANCE_WHATSAPP_TARGET,'whatsapp_message_snapshot'=>'','whatsapp_opened_at'=>'','whatsapp_verified_at'=>'','whatsapp_verified_by'=>[],'events'=>[['event'=>'customer_confirmed','at'=>$now,'method'=>$portal?'customer_portal':'secure_link']]];
    return $document['customer_acceptance'];
}
function customer_acceptance_current_origin(): string { return ((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https://':'http://').($_SERVER['HTTP_HOST']??'localhost'); }
function customer_acceptance_dispatch_public_link(string $token): string { return customer_acceptance_current_origin().'/dispatch-advice-public.php?token='.rawurlencode($token); }
function customer_acceptance_build_whatsapp_message(array $e,string $publicLink=''): string { $verb=$e['document_type']==='challan'?'confirm receipt of the items listed in':'confirm acceptance/review of'; return "I, {$e['customer_name_snapshot']}, {$verb} {$e['document_no']}.\nReference: {$e['acceptance_ref']}\nConfirmed on: {$e['confirmed_at']}\nDocument link: {$publicLink}\nRemarks: ".($e['customer_remarks']?:'None')."\nRegistered mobile ending: {$e['confirmed_mobile_last4']}"; }
function customer_acceptance_whatsapp_url(array $e,string $publicLink=''): string { return 'https://wa.me/'.CUSTOMER_ACCEPTANCE_WHATSAPP_TARGET.'?text='.rawurlencode(customer_acceptance_build_whatsapp_message($e,$publicLink)); }
function customer_acceptance_dispatch_item_summary(array $document): string { $parts=[];foreach((array)($document['items']??[]) as $item){$name=trim((string)($item['name']??''));if($name!=='')$parts[]=$name.' — '.(string)($item['qty']??'').' '.trim((string)($item['unit']??''));}return implode("\n",$parts); }
function customer_acceptance_dispatch_template(array $document,array $e,array $company,string $publicLink,string $template): array
{
    $companyName=trim((string)($company['brand_name']??$company['company_name']??''))?:'Dakshayani Enterprises';
    $values=['customer_name'=>(string)($document['customer_name']??''),'customer_mobile_mask'=>customer_acceptance_mask_mobile((string)($document['customer_mobile']??'')),'dispatch_advice_no'=>(string)($document['dispatch_advice_no']??''),'dispatch_advice_version'=>(string)($document['revision_no']??1),'quotation_no'=>(string)($document['quotation_no']??''),'agreement_no'=>(string)($document['agreement_no']??''),'dispatch_date'=>(string)($document['planned_dispatch_date']??''),'delivery_address'=>(string)($document['delivery_address']??''),'item_count'=>(string)count((array)($document['items']??[])),'item_summary'=>customer_acceptance_dispatch_item_summary($document),'acceptance_ref'=>(string)($e['acceptance_ref']??''),'confirmed_at'=>(string)($e['confirmed_at']??''),'public_link'=>$publicLink,'company_name'=>$companyName,'company_phone'=>(string)($company['phone_primary']??''),'company_whatsapp'=>'7070278178'];
    $message=preg_replace_callback('/\{([a-z_]+)\}/',static fn($m)=>array_key_exists($m[1],$values)?$values[$m[1]]:$m[0],$template)??$template;
    preg_match_all('/\{[a-z_]+\}/',$message,$matches);
    return ['message'=>$message,'unresolved'=>array_values(array_unique($matches[0]))];
}

function customer_acceptance_dispatch_default_whatsapp_template(): string
{
    return "Dispatch Advice customer confirmation for {company_name}
Customer: {customer_name}
Dispatch Advice: {dispatch_advice_no}
Version: {dispatch_advice_version}
Quotation: {quotation_no}
Agreement: {agreement_no}
Planned dispatch date: {dispatch_date}
Delivery address: {delivery_address}
Items:
{item_summary}
Acceptance reference: {acceptance_ref}
Confirmation timestamp: {confirmed_at}
Masked customer mobile: {customer_mobile_mask}
Public Dispatch Advice link: {public_link}";
}
function customer_acceptance_dispatch_whatsapp_payload(array $document, array $company=[]): array
{
    $publicLink=customer_acceptance_dispatch_public_link((string)($document['public_token']??''));
    return customer_acceptance_dispatch_template($document,(array)($document['customer_acceptance']??[]),$company,$publicLink,customer_acceptance_dispatch_default_whatsapp_template());
}
function customer_acceptance_dispatch_whatsapp_url(array $document, array $company=[]): string
{
    $built=customer_acceptance_dispatch_whatsapp_payload($document,$company);
    return 'https://wa.me/'.CUSTOMER_ACCEPTANCE_WHATSAPP_TARGET.'?text='.rawurlencode($built['message']);
}
function customer_acceptance_mark_whatsapp_opened(array &$document,string $message): void { $now=date('c');$document['customer_acceptance']['whatsapp_opened_at']=$now;$document['customer_acceptance']['whatsapp_message_snapshot']=$message;$document['customer_acceptance']['events'][]=['event'=>'whatsapp_opened','at'=>$now]; }
function customer_acceptance_mark_whatsapp_verified(array &$document,array $admin): void { if(empty($document['customer_acceptance']['confirmed_at'])) throw new RuntimeException('No customer confirmation exists.'); $document['customer_acceptance']['status']='whatsapp_verified';$document['customer_acceptance']['whatsapp_verified_at']=date('c');$document['customer_acceptance']['whatsapp_verified_by']=$admin;$document['customer_acceptance']['events'][]=['event'=>'whatsapp_manually_verified','at'=>date('c'),'admin'=>$admin]; }
