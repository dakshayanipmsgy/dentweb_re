<?php
require_once __DIR__.'/../admin/includes/documents_helpers.php';
require_once __DIR__.'/../includes/customer_document_acceptance.php';
function dbht($cond,$msg){if(!$cond){fwrite(STDERR,"FAIL: $msg\n");exit(1);}}
$_SERVER['HTTP_HOST']='example.test';
$_SERVER['HTTPS']='on';
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['REQUEST_URI']='/customer-document-acceptance.php?type=dispatch_advice&token=secure-token-href-588';
$doc=array_merge(documents_dispatch_advice_defaults(),[
    'id'=>'da_href_588','dispatch_advice_no'=>'DE/DA/RES/26-27/0588','revision_no'=>2,'quotation_no'=>'DE/QTN/0588','agreement_no'=>'DE/AGR/0588','status'=>'acknowledged','public_share_enabled'=>true,'public_token'=>'secure-token-href-588','customer_name'=>'Href Customer','customer_mobile'=>'9876543210','delivery_address'=>'Ranchi, Jharkhand','planned_dispatch_date'=>'2026-06-20','items'=>[['name'=>'Solar Panels','qty'=>2,'unit'=>'Nos']],
    'customer_acceptance'=>['confirmed_at'=>'2026-06-15T10:00:00+00:00','acceptance_ref'=>'ACC-DA-20260615-HREF','document_no'=>'DE/DA/RES/26-27/0588','document_type'=>'dispatch_advice','customer_name_snapshot'=>'Href Customer','customer_mobile_snapshot'=>'******3210','confirmed_mobile_last4'=>'3210','customer_remarks'=>'']
]);
documents_save_dispatch_advice($doc);
$_GET=['type'=>'dispatch_advice','token'=>'secure-token-href-588'];
ob_start();
include __DIR__.'/../customer-document-acceptance.php';
$html=ob_get_clean();
@unlink(documents_dispatch_advices_dir().'/da_href_588.json');
dbht(preg_match('/href="([^"]+)"/', $html, $m)===1,'button href rendered');
$href=html_entity_decode($m[1],ENT_QUOTES);
dbht(str_starts_with($href,'https://wa.me/917070278178?text='),'href begins with direct Dakshayani wa.me target');
dbht(!str_contains($href,'open_whatsapp=1'),'href does not contain open_whatsapp=1');
dbht(!str_contains($href,'customer-document-acceptance.php'),'href does not point back to acceptance page');
dbht(str_contains(rawurldecode(substr($href, strlen('https://wa.me/917070278178?text='))),'dispatch-advice-public.php?token=secure-token-href-588'),'message includes actual dispatch advice public link');
echo "Dispatch confirmation button href test passed\n";
