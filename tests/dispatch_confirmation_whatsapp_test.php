<?php
require_once __DIR__.'/../admin/includes/documents_helpers.php';
require_once __DIR__.'/../includes/customer_document_acceptance.php';
function dawt($cond,$msg){if(!$cond){fwrite(STDERR,"FAIL: $msg\n");exit(1);}}
$_SERVER['HTTP_HOST']='example.test';
$_SERVER['HTTPS']='on';
$d=array_merge(documents_dispatch_advice_defaults(),[
    'id'=>'da_issue_586','dispatch_advice_no'=>'DE/DA/RES/26-27/0586','revision_no'=>3,'status'=>'acknowledged','public_share_enabled'=>true,'public_token'=>'secure-token-586','customer_name'=>'Customer','customer_mobile'=>'9876543210','items'=>[['name'=>'Solar Panels','qty'=>2,'unit'=>'Nos']]
]);
$token=customer_acceptance_issue_token($d,'dispatch_advice');
$r=customer_acceptance_check_submission($d,'dispatch_advice',['mobile_first6'=>'987654','confirmed'=>'1'],$token);
dawt(!empty($r['ok']),'customer confirms dispatch advice with mobile verification');
dawt(!empty($d['customer_acceptance']['confirmed_at']),'confirmed_at saved');
$link=customer_acceptance_dispatch_public_link((string)$d['public_token']);
dawt($link==='https://example.test/dispatch-advice-public.php?token=secure-token-586','post-confirmation message uses dispatch advice public token link');
$built=customer_acceptance_dispatch_template($d,$d['customer_acceptance'],['brand_name'=>'Dakshayani Enterprises'],$link,'Customer {customer_name} confirmed {dispatch_advice_no} v{dispatch_advice_version}. Acceptance {acceptance_ref}. Link {public_link}');
dawt(str_contains($built['message'],'DE/DA/RES/26-27/0586'),'message includes exact Dispatch Advice');
dawt(str_contains($built['message'],'secure-token-586'),'message includes public token');
dawt(str_contains($built['message'],$d['customer_acceptance']['acceptance_ref']),'message includes acceptance reference');
dawt(str_contains($built['message'],' v3.'),'message includes document version');
customer_acceptance_mark_whatsapp_opened($d,$built['message']);
dawt(!empty($d['customer_acceptance']['whatsapp_opened_at']),'WhatsApp recorded as opened');
dawt(($d['customer_acceptance']['status']??'')==='whatsapp_pending','WhatsApp is not marked sent or delivered');
dawt(CUSTOMER_ACCEPTANCE_WHATSAPP_TARGET==='917070278178','recipient is Dakshayani Enterprises WhatsApp number');
dawt(customer_acceptance_validate_token($d,'dispatch_advice',$token),'acknowledged dispatch advice remains token-valid after confirmation/opening evidence');
echo "Dispatch confirmation WhatsApp tests passed\n";
