<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/customer_document_acceptance.php';
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
function ok($cond,$msg){if(!$cond){fwrite(STDERR,"FAIL: $msg\n");exit(1);}}
$d=documents_dispatch_advice_defaults();
$d['id']='da_test_604';$d['dispatch_advice_no']='DE/DA/RES/26-27/0604';$d['quotation_id']='q1';$d['quotation_no']='Q-604';$d['revision_no']=2;$d['status']='acknowledged';$d['customer_name']='Customer';$d['customer_mobile']='9876543210';$d['delivery_address']='Site';$d['planned_dispatch_date']='2026-06-16';$d['items']=[['line_id'=>'l1','name'=>'Panel','description'=>'Solar panel','brand_model'=>'ABC','qty'=>2,'unit'=>'Nos','remarks'=>'Accepted'],['line_id'=>'l2','name'=>'Inverter','qty'=>1,'unit'=>'Set']];
customer_acceptance_record($d,'dispatch_advice',['mobile_first6'=>'987654','confirmed'=>'1'],['salt'=>'test']);
$c=documents_challan_defaults();$c['dispatch_advice_id']=$d['id'];$c['dispatch_advice_no']=$d['dispatch_advice_no'];$c['challan_no']='DC-604';$c['delivery_date']='2026-06-16';$c['customer_snapshot']=['name'=>'Customer','mobile'=>'9876543210'];$c['items']=array_map(static fn($i)=>['name'=>$i['name']??'','description'=>$i['description']??'','brand_model'=>$i['brand_model']??'','qty'=>$i['qty']??0,'unit'=>$i['unit']??'Nos','remarks'=>$i['remarks']??'','source_dispatch_advice_id'=>'da_test_604','source_dispatch_advice_line_id'=>$i['line_id']??''],documents_normalize_dispatch_advice_items($d['items']));
ok(documents_challan_workflow_status($d,null)==='Pending','pending without challan');
ok(documents_challan_workflow_status($d,$c)==='Created','created with challan');
ok(documents_challan_materials_match_dispatch_advice($d,$c),'material snapshot matches');
ok(documents_challan_has_valid_items($c),'DA item-only challan has valid items');
$c['dispatch_status']='dispatched';$c['dispatched_at']='2026-06-16T10:00:00+00:00';ok(documents_challan_workflow_status($d,$c)==='Dispatched','dispatched status');
$c['customer_acceptance']=['confirmed_at'=>'2026-06-16T11:00:00+00:00'];ok(documents_challan_workflow_status($d,$c)==='Delivered','delivered status');
echo "challan dispatch advice workflow tests passed\n";
