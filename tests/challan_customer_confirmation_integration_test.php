<?php declare(strict_types=1);
require_once __DIR__.'/../admin/includes/documents_helpers.php';
require_once __DIR__.'/../includes/customer_document_acceptance.php';
function ct_ok($cond,string $msg): void { if(!$cond){fwrite(STDERR,"FAIL: $msg\n"); exit(1);} }
function ct_challan(string $id): array { return ['id'=>$id,'challan_no'=>'DC-'.$id,'dc_number'=>'DC-'.$id,'version_no'=>1,'status'=>'final','dispatch_status'=>'dispatched','workflow_status'=>'dispatched','public_share_enabled'=>true,'public_token'=>'pub-'.$id,'customer_mobile'=>'9876543210','customer_snapshot'=>['name'=>'Test Customer','mobile'=>'9876543210'],'items'=>[['name'=>'Panel','qty'=>1,'unit'=>'Nos']],'customer_acceptance'=>[],'customer_acceptance_request'=>[]]; }
function ct_submit(array &$c,string $raw,string $digits): array { return customer_acceptance_check_submission($c,'challan',['acceptance_token'=>$raw,'mobile_first6'=>$digits,'confirmed'=>'1','remarks'=>''], $raw, ['ip'=>'127.0.0.1','user_agent'=>'test','salt'=>$c['id']]); }

// A. Fresh confirmation.
$c=ct_challan('fresh'); $t=customer_acceptance_issue_token($c,'challan'); $r=ct_submit($c,$t,'987654'); ct_ok(!empty($r['ok']),'fresh confirmation succeeds');

// B. Reload preserves URL-carried token.
$c=ct_challan('reload'); $t=customer_acceptance_issue_token($c,'challan'); ct_ok(customer_acceptance_validate_token($c,'challan',$t),'token valid on initial load'); ct_ok(customer_acceptance_validate_token($c,'challan',$t),'same token valid after reload'); $r=ct_submit($c,$t,'987654'); ct_ok(!empty($r['ok']),'reload then correct succeeds');

// C. wrong once, then correct.
$c=ct_challan('retry1'); $t=customer_acceptance_issue_token($c,'challan'); $r=ct_submit($c,$t,'000000'); ct_ok(empty($r['ok'])&&($c['customer_acceptance_request']['incorrect_mobile_attempts']??0)===1,'one wrong attempt counted'); $r=ct_submit($c,$t,'987654'); ct_ok(!empty($r['ok']),'correct after one wrong succeeds');

// D. wrong twice, then correct.
$c=ct_challan('retry2'); $t=customer_acceptance_issue_token($c,'challan'); ct_submit($c,$t,'000000'); ct_submit($c,$t,'111111'); ct_ok(($c['customer_acceptance_request']['incorrect_mobile_attempts']??0)===2,'two wrong attempts counted'); $r=ct_submit($c,$t,'987654'); ct_ok(!empty($r['ok']),'correct after two wrong succeeds');

// E. three wrong locks; reissue succeeds.
$c=ct_challan('lock'); $t=customer_acceptance_issue_token($c,'challan'); ct_submit($c,$t,'000000'); ct_submit($c,$t,'111111'); $r=ct_submit($c,$t,'222222'); ct_ok(($r['code']??'')==='locked'&&customer_acceptance_is_locked($c),'third wrong locks'); $r=ct_submit($c,$t,'987654'); ct_ok(empty($r['ok']),'correct after lock blocked'); $oldEvents=count((array)$c['customer_acceptance_request']['events']); $nt=customer_acceptance_issue_token($c,'challan'); ct_ok(!customer_acceptance_is_locked($c),'reissue resets lock'); ct_ok(count((array)$c['customer_acceptance_request']['events'])>$oldEvents,'reissue preserves previous audit events'); $r=ct_submit($c,$nt,'987654'); ct_ok(!empty($r['ok']),'new reissued link succeeds');

// F. valid request always has a non-empty render token.
$c=ct_challan('render'); $t=customer_acceptance_issue_token($c,'challan'); ct_ok($t!==''&&customer_acceptance_validate_token($c,'challan',$t),'render token non-empty while request valid');

// G. first six digits are never stored in plaintext.
$stored=['request'=>$c['customer_acceptance_request']??[],'evidence'=>$c['customer_acceptance']??[]]; $encoded=json_encode($stored, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ct_ok(!str_contains((string)$encoded,'"mobile_first6"')&&!str_contains((string)$encoded,'987654'), 'submitted first six digits not stored in plaintext in acceptance records');

echo "Challan customer confirmation integration tests passed\n";
