<?php declare(strict_types=1);
require_once __DIR__.'/../includes/customer_document_acceptance.php';
function ok($v,$m){if(!$v)throw new RuntimeException($m);}
$d=['id'=>'q1','quote_no'=>'Q-1','status'=>'approved','version_no'=>2,'customer_name'=>'Asha Devi','customer_mobile'=>'9876548178','items'=>[['name'=>'Panel','qty'=>2]]];
$t=customer_acceptance_issue_token($d,'quotation');ok(strlen($t)===64,'token entropy');ok(customer_acceptance_validate_token($d,'quotation',$t),'valid token');ok(!customer_acceptance_validate_token($d,'quotation','bad'),'invalid token');
customer_acceptance_record($d,'quotation',['mobile_first6'=>'987654','confirmed'=>'1'],['ip'=>'127.0.0.1','user_agent'=>'test','salt'=>'x']);$first=$d['customer_acceptance'];ok($first['identity_method']==='secure_link','identity method');ok(str_contains(customer_acceptance_whatsapp_url($first),'917070278178'),'WhatsApp target');customer_acceptance_record($d,'quotation',['mobile_first6'=>'000000','confirmed'=>'1']);ok($d['customer_acceptance']===$first,'idempotent evidence');ok(customer_acceptance_validate_token($d,'quotation',$t),'token remains idempotent');
$c=['id'=>'c1','challan_no'=>'DC-1','customer_name_snapshot'=>'Asha Devi','customer_mobile'=>'9876548178'];customer_acceptance_record($c,'challan',['mobile_first6'=>'987654','confirmed'=>'1','remarks'=>'One box damaged']);ok($c['customer_acceptance']['review_required']===true,'remarks review flag');
ok(customer_acceptance_normalize_mobile('+91 98765-48178')==='9876548178','91-prefixed formatted mobile normalized');ok(customer_acceptance_mask_mobile('919876548178')==='******8178','mobile mask');
echo "customer acceptance tests passed\n";
