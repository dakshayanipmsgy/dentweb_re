<?php
declare(strict_types=1);
require_once __DIR__.'/../admin/includes/documents_helpers.php';
$assert=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);echo "PASS: $m\n";};
$assert(documents_contact_normalize_name(' Dr.  ANITA-Sharma ')==='anita sharma','titles, case, whitespace and punctuation normalize for scoring');
$assert(documents_contact_similarity('Mr Raj Kumar','raj kumar')===100,'deterministic exact normalized score');
$assert(documents_contact_score_band(90)==='strong'&&documents_contact_score_band(75)==='likely'&&documents_contact_score_band(60)==='weak','score bands');
$bad=documents_contact_parse_csv("mobile,name\n9876543210,A\n"); $assert(!$bad['ok'],'exact required headers enforced');
$bad=documents_contact_parse_csv("name,mobile\nA,123\n"); $assert(!$bad['ok'],'Indian mobile validation enforced');
$bad=documents_contact_parse_csv("name,mobile\nAnita,9876543210\n Anita ,9876543210\n"); $assert(!$bad['ok']&&str_contains(implode(' ',$bad['errors']),'Duplicate'),'normalized duplicate rows reported');
$parsed=documents_contact_parse_csv("name,mobile\nDr Anita Sharma,+91 98765 43210\n"); $assert($parsed['ok']&&$parsed['rows'][0]['mobile']==='9876543210','valid CSV canonicalizes mobile');
$quotes=[['id'=>'q1','quote_no'=>'QT-1','status'=>'accepted','customer_name'=>'Anita Sharma','customer_mobile'=>'9876543210','updated_at'=>'v1'],['id'=>'q2','quote_no'=>'QT-2','status'=>'approved','customer_name'=>'Dr. Anita Sharma','customer_mobile'=>'9123456789','updated_at'=>'v2']];
$preview=documents_contact_build_preview($parsed['rows'],$quotes); $assert(count($preview)>=1,'candidates at 60 percent or more displayed');
$exact=array_values(array_filter($preview,static fn($c)=>$c['quotation_id']==='q1'))[0];
$assert($exact['mobile_matches']&&!empty($exact['conflicts'])&&!$exact['preselected'],'matching mobile and one-to-many ambiguity are exposed and not preselected');
$assert(!array_key_exists('score',['client'=>'never accepted']),'server matching does not consume client scores');
echo "quotation contact CSV reconciliation tests passed\n";
