<?php
declare(strict_types=1);
require_once __DIR__.'/../admin/includes/documents_helpers.php';
require_once __DIR__.'/../includes/customer_document_acceptance.php';
require_once __DIR__.'/../includes/dispatch_advice_view_renderer.php';
function b(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
$d=array_merge(documents_dispatch_advice_defaults(),['id'=>'da_test','dispatch_advice_no'=>'DE/DA/RES/26-27/0001','revision_no'=>2,'status'=>'finalized','customer_name'=>'Customer','customer_mobile'=>'9876541234','quotation_no'=>'Q-1','agreement_no'=>'A-1','planned_dispatch_date'=>'2026-06-15','delivery_address'=>'Safe address','items'=>[['name'=>'Panel','description'=>'Safe','brand_model'=>'X','qty'=>2,'unit'=>'Nos','remarks'=>'','cost'=>999,'warehouse'=>'secret']]]);
ob_start();render_dispatch_advice($d,['brand_name'=>'Dakshayani Enterprises','gstin'=>'GST123'],true);$html=ob_get_clean();
b(str_contains($html,'Dakshayani Enterprises')&&str_contains($html,'GST123')&&str_contains($html,'******1234'),'branded public renderer masks mobile');
$e=['acceptance_ref'=>'ACC-1','confirmed_at'=>'2026-06-15T00:00:00Z'];$built=customer_acceptance_dispatch_template($d,$e,['brand_name'=>'Dakshayani Enterprises','phone_primary'=>'7070278178'],'https://example.test/public','{customer_name}|{customer_mobile_mask}|{dispatch_advice_no}|{dispatch_advice_version}|{item_summary}|{acceptance_ref}|{company_whatsapp}|{unknown}');
b(str_contains($built['message'],'Panel — 2 Nos')&&!str_contains($built['message'],'999')&&!str_contains($built['message'],'secret'),'item summary is customer-safe');
b($built['unresolved']===['{unknown}'],'unresolved placeholders reported');
b(CUSTOMER_ACCEPTANCE_WHATSAPP_TARGET==='917070278178','verified WhatsApp target');
echo "Dispatch advice branding tests passed\n";
