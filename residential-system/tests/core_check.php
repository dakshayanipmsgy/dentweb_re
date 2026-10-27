<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/core.php';
function check(bool $v,string $message):void{if(!$v)throw new RuntimeException('FAIL '.$message);echo 'PASS '.$message.PHP_EOL;}
function rejected(callable $fn,string $message):void{try{$fn();}catch(Throwable $e){echo 'PASS '.$message.PHP_EOL;return;}throw new RuntimeException('FAIL '.$message);}
$root=dirname(__DIR__).'/qa-runtime/core-'.bin2hex(random_bytes(4));putenv('RES_DATA='.$root);putenv('RES_MODE=preview');putenv('RES_MAIL=0');
$a=['name'=>'TEST ONLY गृहस्वामी','phone'=>'+91 90000 00000','district'=>'Ranchi','bill'=>'1001-2500','consent'=>'1','request_id'=>bin2hex(random_bytes(16)),'source'=>'synthetic','campaign'=>'qa'];
$first=r_accept($a,'test-a');$id=$first['reference'];check(r_accept($a,'test-a')['reference']===$id,'retry idempotency');
rejected(fn()=>r_accept(array_replace($a,['name'=>'Changed name']),'test-a'),'conflicting retry cannot overwrite');
foreach(['phone'=>'12345','consent'=>'0','district'=>'Other','bill'=>'bad','website'=>'spam'] as $k=>$v)rejected(fn()=>r_accept(array_replace($a,[$k=>$v,'request_id'=>bin2hex(random_bytes(16))]),'invalid'),'invalid '.$k);
$second=r_accept(array_replace($a,['request_id'=>bin2hex(random_bytes(16))]),'test-b');$s=r_state(fn(&$s)=>$s,false);check(count($s['leads'])===2&&$s['leads'][$second['reference']]['related_phone_records']===[$id],'same phone flagged, never silently merged');
check($s['leads'][$id]['notification']['status']==='suppressed_preview'&&count(r_notify(fn()=>true))===0,'preview alerts suppressed');
$update=['version'=>1,'stage'=>'contact_attempted','note'=>'TEST attempted call, no answer','owner'=>'Vishesh Vardhan','next_action'=>'Review callback timing','due_at'=>'2026-10-28T10:00'];r_update($id,$update,'TEST');
check(r_state(fn(&$s)=>$s['leads'][$id]['version'],false)===2,'follow-up persisted with history');rejected(fn()=>r_update($id,$update,'TEST'),'stale update rejected');
rejected(fn()=>r_update($id,array_replace($update,['version'=>2,'stage'=>'deposit_received']),'TEST'),'deposit requires evidence');
rejected(fn()=>r_update($id,array_replace($update,['version'=>2,'stage'=>'qualified']),'TEST'),'qualification cannot be invented');
$paid=array_replace($update,['version'=>2,'stage'=>'deposit_received','milestone'=>'deposit','amount'=>'10000','evidence'=>'SYNTHETIC-RECEIPT-1']);r_update($id,$paid,'TEST');check(r_state(fn(&$s)=>count($s['leads'][$id]['milestones']),false)===1,'deposit evidence retained separately');
rejected(fn()=>r_update($id,array_replace($paid,['version'=>3]),'TEST'),'duplicate milestone reference rejected');
r_update($id,array_replace($update,['version'=>3,'stage'=>'do_not_contact','reason'=>'TEST stop request']),'TEST');$l=r_state(fn(&$s)=>$s['leads'][$id],false);check(str_starts_with(r_draft($l,'first'),'No outreach'),'opt-out suppresses draft');
rejected(fn()=>r_update($id,array_replace($update,['version'=>4]),'TEST'),'opt-out cannot silently reopen');
for($i=0;$i<5;$i++)r_accept(array_replace($a,['request_id'=>bin2hex(random_bytes(16))]),'rate-test');rejected(fn()=>r_accept(array_replace($a,['request_id'=>bin2hex(random_bytes(16))]),'rate-test'),'rate limit');
putenv('RES_MODE=live');putenv('RES_MAIL=1');$live=r_accept(array_replace($a,['request_id'=>bin2hex(random_bytes(16))]),'mail-test')['reference'];$n=r_notify(fn()=>false);check($n[$live]==='retry_required','alert failure visible and enquiry retained');
r_state(function(&$s)use($live){$s['leads'][$live]['notification']['last_attempt']=0;});$n=r_notify(fn()=>true);check($n[$live]==='accepted_by_mailer','alert retry succeeds');check(r_notify(fn()=>true)===[],'accepted alert not resent');
$before=r_state(fn(&$s)=>$s,false);$backup=$root.'/restore-fixture.json';r_atomic($backup,$before);$restore=$root.'/restore-isolated';mkdir($restore,0700);copy($backup,$restore.'/sales.json');putenv('RES_DATA='.$restore);check(r_state(fn(&$s)=>$s,false)===$before,'isolated backup restore exact');
file_put_contents($restore.'/sales.json','broken');rejected(fn()=>r_accept($a,'corrupt'),'corrupt storage fails closed');check(file_get_contents($restore.'/sales.json')==='broken','corruption never replaced by empty state');
echo "All fixtures synthetic; no external messages or conversions.\n";
