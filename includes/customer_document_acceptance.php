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
function customer_acceptance_confirmation_text(string $type): string { return ['quotation'=>'I have reviewed this quotation and accept the offered scope, price and terms shown in this version.','dispatch_advice'=>'I have reviewed the planned dispatch items listed in this Material Dispatch Advice. I understand this does not mean delivery occurred.','challan'=>'I confirm that I have received the items listed in this Delivery Challan, subject to any remarks entered below.'][$type]??''; }
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
function customer_acceptance_build_whatsapp_message(array $e,string $publicLink=''): string { $verb=$e['document_type']==='challan'?'confirm receipt of the items listed in':'confirm acceptance/review of'; return "I, {$e['customer_name_snapshot']}, {$verb} {$e['document_no']}.\nReference: {$e['acceptance_ref']}\nConfirmed on: {$e['confirmed_at']}\nDocument link: {$publicLink}\nRemarks: ".($e['customer_remarks']?:'None')."\nRegistered mobile ending: {$e['confirmed_mobile_last4']}"; }
function customer_acceptance_whatsapp_url(array $e,string $publicLink=''): string { return 'https://wa.me/'.CUSTOMER_ACCEPTANCE_WHATSAPP_TARGET.'?text='.rawurlencode(customer_acceptance_build_whatsapp_message($e,$publicLink)); }
function customer_acceptance_mark_whatsapp_verified(array &$document,array $admin): void { if(empty($document['customer_acceptance']['confirmed_at'])) throw new RuntimeException('No customer confirmation exists.'); $document['customer_acceptance']['status']='whatsapp_verified';$document['customer_acceptance']['whatsapp_verified_at']=date('c');$document['customer_acceptance']['whatsapp_verified_by']=$admin;$document['customer_acceptance']['events'][]=['event'=>'whatsapp_manually_verified','at'=>date('c'),'admin'=>$admin]; }
