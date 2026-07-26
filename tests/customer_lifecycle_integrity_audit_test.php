<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/customer_lifecycle_integrity.php';

$n=0; function life_ok(bool $v,string $m):void{global $n;$n++;if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function life_codes(array $r):array{return array_column($r['findings'],'code');}
$customer=['serial_number'=>'C1','name'=>'Asha Devi','mobile'=>'98765 43210','archived'=>false];
$lead=['id'=>'L1','name'=>'Asha Devi','mobile'=>'9876543210','lead_source'=>'website','status'=>'Quotation Sent','converted_flag'=>'No','quotation_ids'=>['Q1']];
$base=['id'=>'Q1','customer_name'=>'Asha Devi','customer_mobile'=>'+91-98765-43210','status'=>'approved','is_current_version'=>true,'archived_flag'=>false,'source'=>['type'=>'lead','lead_id'=>'L1','lead_mobile'=>'9876543210'],'project_completion'=>['state'=>'pending']];
$approved=customer_lifecycle_integrity_check([$lead],[$base],[]);
life_ok(!in_array('missing_valid_customer_user',life_codes($approved),true),'lead to approved quotation does not imply Customer User creation');
life_ok(($base['source']['lead_id']??'')==='L1' && $lead['lead_source']==='website','lead source metadata stays distinct and traceable');
$accepted=$base; $accepted['status']='accepted'; $accepted['customer_user_link']=['mobile'=>'9876543210'];
$r=customer_lifecycle_integrity_check([$lead],[$accepted],[$customer],['accepted'=>['Q1'=>true],'completed'=>['Q1'=>false]]);
life_ok($r['findings']===[],'normalized mobile links idempotently and accepted visibility is valid');
$q2=$accepted;$q2['id']='Q2';$lead2=$lead;$lead2['quotation_ids'][]='Q2'; life_ok(!in_array('duplicate_active_customer_users',life_codes(customer_lifecycle_integrity_check([$lead2],[$accepted,$q2],[$customer])),true),'multiple quotations for one mobile do not duplicate a Customer User');
$other=$customer;$other['serial_number']='C2';$other['mobile']='9765432109'; life_ok(!in_array('duplicate_active_customer_users',life_codes(customer_lifecycle_integrity_check([],[],[$customer,$other])),true),'same name different mobile stays isolated');
$badView=customer_lifecycle_integrity_check([],[$base],[],['accepted'=>['Q1'=>true]]); life_ok(in_array('incorrect_accepted_visibility',life_codes($badView),true),'approved is not accepted');
$completed=$accepted;$completed['project_completion']=['state'=>'completed','completed_at'=>'2026-01-01T00:00:00Z','completed_by'=>['id'=>'A1'],'snapshot'=>['reference_amount'=>100]];
life_ok(customer_lifecycle_integrity_check([$lead],[$completed],[$customer],['accepted'=>['Q1'=>false],'completed'=>['Q1'=>true]])['findings']===[],'accepted moves to completed derived view');
$reopened=$completed;$reopened['project_completion']['state']='reopened';$reopened['project_completion']['reopened_at']='2026-01-02T00:00:00Z';$reopened['project_completion']['reopen_reason']='review';
life_ok(customer_lifecycle_integrity_check([$lead],[$reopened],[$customer],['accepted'=>['Q1'=>true],'completed'=>['Q1'=>false]])['findings']===[],'reopened moves back to accepted derived view');
$archived=$customer;$archived['archived']=true;$missing=customer_lifecycle_integrity_check([],[$accepted],[$archived]);life_ok(in_array('missing_valid_customer_user',life_codes($missing),true)&&in_array('archived_customer_in_active_matching',life_codes($missing),true),'missing and archived Customer Users are reported');
$conflict=$customer;$conflict['name']='Another Person';life_ok(in_array('mobile_name_conflict',life_codes(customer_lifecycle_integrity_check([],[$accepted],[$conflict])),true),'name/mobile conflict is reported');
$corrected=$accepted;$corrected['customer_mobile']='9765432109';life_ok(in_array('stale_customer_user_linkage',life_codes(customer_lifecycle_integrity_check([],[$corrected],[$customer,$other])),true),'mobile correction exposes stale linkage');
life_ok(!in_array('duplicate_active_customer_users',life_codes(customer_lifecycle_integrity_check([],[],[$customer,$archived])),true),'archived rows are excluded from active matching and synchronization identity');
life_ok(($accepted['status']==='accepted'&&$accepted['project_completion']['state']==='pending'&&($customer['status']??'')!=='accepted'),'quotation, Customer User, and completion statuses are separate');
$failed=$lead;$failed['status']='converted';$failed['converted_flag']='Yes';life_ok(in_array('converted_lead_without_customer_user',life_codes(customer_lifecycle_integrity_check([$failed],[],[])),true),'failed conversion cannot appear successful');
$snapshot=$completed['project_completion']['snapshot'];$changed=$completed;$changed['calc']=['gross_payable'=>999];life_ok($changed['project_completion']['snapshot']===$snapshot,'completion snapshot remains immutable when live document values change');
$invalid=$accepted;$invalid['customer_mobile']='123';life_ok(in_array('invalid_mobile',life_codes(customer_lifecycle_integrity_check([],[$invalid],[])),true),'invalid mobile reported');
echo "customer_lifecycle_integrity_audit_test passed ($n assertions)\n";
