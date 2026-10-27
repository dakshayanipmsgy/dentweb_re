<?php
declare(strict_types=1);
// Private application. All readers and writers use one lock and atomic snapshots.
function r_config(): array { return ['data'=>getenv('RES_DATA')?:dirname(__DIR__).'/runtime','mode'=>getenv('RES_MODE')?:'preview','mail'=>getenv('RES_MAIL')==='1']; }
function r_text(array $a,string $k,int $max=500): string { $v=$a[$k]??''; if(!is_string($v)||strlen($v)>$max)throw new RuntimeException('Check '.$k.'.'); return trim(strip_tags($v)); }
function r_phone(string $v): string { $v=preg_replace('/[\s()+-]/','',$v); if(strlen($v)===12&&str_starts_with($v,'91'))$v=substr($v,2); if(!preg_match('/^[6-9][0-9]{9}$/D',$v))throw new RuntimeException('Enter a valid Indian mobile number.'); return $v; }
function r_atomic(string $path,array $data): void {
 $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp'; $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 $f=fopen($tmp,'xb'); if(!$f)throw new RuntimeException('Storage unavailable.');
 try { if(fwrite($f,$json)!==strlen($json)||!fflush($f))throw new RuntimeException('Storage write failed.'); if(function_exists('fsync')&&!fsync($f))throw new RuntimeException('Storage sync failed.'); } finally { fclose($f); }
 chmod($tmp,0600); if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Storage commit failed.');}
}
function r_state(callable $fn,bool $write=true): mixed {
 $c=r_config();$d=$c['data']; if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))throw new RuntimeException('Storage unavailable.');
 $lock=fopen($d.'/sales.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Sales desk busy. Retry.');
 try { $p=$d.'/sales.json';$s=is_file($p)?json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR):['schema'=>1,'leads'=>[],'requests'=>[],'rates'=>[],'events'=>[]];
  if(!is_array($s)||($s['schema']??0)!==1||!isset($s['leads'],$s['requests'],$s['rates'],$s['events']))throw new RuntimeException('Storage needs recovery. No records overwritten.');
  $before=json_encode($s,JSON_THROW_ON_ERROR);$result=$fn($s);
  if($write&&$before!==json_encode($s,JSON_THROW_ON_ERROR)){if(is_file($p)){if(!is_dir($d.'/backups')&&!mkdir($d.'/backups',0700))throw new RuntimeException('Backup unavailable.');$backup=$d.'/backups/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';if(!copy($p,$backup))throw new RuntimeException('Backup failed.');chmod($backup,0600);} r_atomic($p,$s);}
  return $result;
 } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function r_stages(): array { return ['historical_status_unknown','new','contact_attempted','connected','qualified','survey_considered','survey_booked','survey_completed','proposal_sent','decision_finance_pending','deposit_received','installation_scheduled','installed_commissioning','payment_completed','unsuitable','lost','deferred','do_not_contact']; }
function r_closed(string $v): bool {return in_array($v,['unsuitable','lost','do_not_contact','payment_completed'],true);}
function r_accept(array $a,string $ip): array {
 $name=r_text($a,'name',320);$length=preg_match_all('/./us',$name);if($length===false||$length<2||$length>80)throw new RuntimeException('Enter your name.');
 $phone=r_phone(r_text($a,'phone',30));$district=r_text($a,'district',100);$bill=r_text($a,'bill',80);
 $districts=['Ranchi','Khunti','Ramgarh','Hazaribagh','Bokaro','Dhanbad','Giridih','Koderma','Chatra','Latehar','Lohardaga','Gumla','Simdega','Palamu','Garhwa','East Singhbhum','West Singhbhum','Seraikela Kharsawan','Deoghar','Dumka','Jamtara','Godda','Pakur','Sahibganj'];
 if(!in_array($district,$districts,true)||!in_array($bill,['0-1000','1001-2500','2501-3500','3501-4500','4500+'],true))throw new RuntimeException('Choose district and bill range.');
 if(($a['consent']??'')!=='1'||r_text($a,'website')!=='')throw new RuntimeException('Please confirm contact permission.');
 $key=r_text($a,'request_id',80);if(!preg_match('/^[a-f0-9-]{32,40}$/D',$key))throw new RuntimeException('Refresh the form.');
 $lang=($a['language']??'hi')==='en'?'en':'hi';$source=[];
 // Only controlled source codes are accepted, never arbitrary query text or click IDs.
 foreach(['source','campaign','creative','medium'] as $k){$v=r_text($a,$k,80);$source[$k]=preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$v)?$v:'';}
 $input=['name'=>$name,'phone'=>$phone,'district'=>$district,'bill'=>$bill,'language'=>$lang,'source'=>$source,'consent'=>true,'measurement_consent'=>($a['measurement_consent']??'')==='1'];
 $hash=hash('sha256',json_encode($input));$config=r_config();
 return r_state(function(&$s)use($input,$key,$hash,$ip,$config){
  if(isset($s['requests'][$key])){ $old=$s['requests'][$key];if($old['hash']!==$hash)throw new RuntimeException('This request was already saved. Start a new enquiry for changed details.'); return ['reference'=>$old['id'],'duplicate'=>true,'mode'=>$config['mode']];}
  $bucket=hash('sha256',$ip);$s['rates'][$bucket]=array_values(array_filter($s['rates'][$bucket]??[],fn($t)=>$t>time()-3600));if(count($s['rates'][$bucket])>=5)throw new RuntimeException('Too many submissions. Please call 7070278178.');
  $s['rates'][$bucket][]=time(); foreach($s['rates'] as $b=>$ts)if(max($ts?:[0])<time()-86400)unset($s['rates'][$b]);
  $id=bin2hex(random_bytes(12));$now=gmdate('c');$related=[];
  foreach($s['leads'] as $other)if(($other['phone']??'')===$input['phone'])$related[]=$other['id'];
  $lead=$input+['id'=>$id,'segment'=>'residential','mode'=>$config['mode'],'created_at'=>$now,'updated_at'=>$now,'stage'=>'new','owner'=>'Vishesh Vardhan','next_action'=>'Confirm preferred call time; review paid bill and roof authority.','due_at'=>$now,'contact_permission'=>'enquiry_only','consent_at'=>$now,'consent_version'=>'residential-v1','sources'=>[$input['source']+['kind'=>'website','at'=>$now]],'related_phone_records'=>$related,'facts'=>[],'milestones'=>[],'history'=>[['at'=>$now,'actor'=>'website','kind'=>'saved_enquiry','note'=>'Customer supplied form details; phone is syntactically valid, not independently verified.']],'notification'=>['status'=>$config['mode']==='live'?'pending':'suppressed_preview','attempts'=>0],'version'=>1];
  $s['leads'][$id]=$lead;$s['requests'][$key]=['id'=>$id,'hash'=>$hash];
  $s['events'][]=['id'=>'res-'.$id,'type'=>'saved_enquiry','at'=>$now,'lead'=>$id,'mode'=>$config['mode'],'measurement_consent'=>$input['measurement_consent'],'external_delivery'=>'disabled_pending_dataset_verification'];
  return ['reference'=>$id,'duplicate'=>false,'mode'=>$config['mode']];
 });
}
function r_advice(array $l): array {
 $f=$l['facts']??[];$missing=[];foreach(['serviceable','roof_authority','paid_bill','roof_space','shading','connection_load','requirement','decision_maker','timing','budget_finance'] as $k)if(!isset($f[$k])||$f[$k]===''||$f[$k]==='unknown')$missing[]=$k;
 $fit=($f['serviceable']??'')==='no'||($f['roof_authority']??'')==='no'?'needs_resolution':(count(array_intersect($missing,['serviceable','roof_authority','paid_bill','roof_space','shading','connection_load','requirement']))?'unverified':'staff_review_ready');
 $why=[];if(($l['contact_permission']??'')!=='enquiry_only')$why[]='Check permission/current status before outreach';if(empty($l['phone']))$why[]='Missing contact details';if(in_array($l['notification']['status']??'',['pending','retry_required','sending_receipt_unconfirmed'],true))$why[]='Owner alert needs review';if($l['stage']==='new')$why[]='Awaiting first response';if($l['stage']==='survey_completed')$why[]='Quotation needs preparation';if($l['stage']==='proposal_sent')$why[]='Proposal awaiting decision';if($l['stage']==='decision_finance_pending')$why[]='Decision or finance blocker needs review';if($l['stage']==='deposit_received')$why[]='Confirm documents and installation readiness';if(!r_closed($l['stage'])&&strtotime($l['due_at'])<=time())$why[]='Action due';if(time()-strtotime($l['updated_at'])>7*86400&&!r_closed($l['stage']))$why[]='No update for 7 days';
 return ['fit'=>$fit,'readiness'=>($f['timing']??'unknown'),'missing'=>$missing,'reasons'=>$why,'suggestion'=>r_closed($l['stage'])?'No further sales outreach.':($missing?'Ask about '.str_replace('_',' ',$missing[0]).'.':$l['next_action'])];
}
function r_update(string $id,array $a,string $actor): void {
 r_state(function(&$s)use($id,$a,$actor){
  if(!isset($s['leads'][$id]))throw new RuntimeException('Record not found.');$l=$s['leads'][$id];
  if((int)($a['version']??0)!==$l['version'])throw new RuntimeException('Someone updated this record. Refresh before saving.');
  $stage=r_text($a,'stage',50);if(!in_array($stage,r_stages(),true))throw new RuntimeException('Choose a valid stage.');
  $note=r_text($a,'note',4000);$owner=r_text($a,'owner',120);$next=r_text($a,'next_action',500);$due=r_text($a,'due_at',40);
  if(!$note||!$owner)throw new RuntimeException('Owner and outcome note are required.');
  if(!r_closed($stage)&&(!$next||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$due)||strtotime($due.'+05:30')===false))throw new RuntimeException('Set the next action and due time (India time).');
  if(in_array($stage,['lost','unsuitable','deferred','do_not_contact'],true)&&r_text($a,'reason',300)==='')throw new RuntimeException('Record the reason.');
  $facts=$l['facts'];foreach(['serviceable','roof_authority','paid_bill','roof_space','shading','connection_load','requirement','decision_maker','timing','budget_finance'] as $k){$v=r_text($a,'fact_'.$k,400);if($v!=='')$facts[$k]=$v;}
  foreach(['serviceable','roof_authority'] as $k)if(isset($facts[$k])&&!in_array($facts[$k],['unknown','yes','no'],true))throw new RuntimeException('Choose yes, no or unknown.');
  if($stage==='qualified'&&r_advice(['facts'=>$facts]+$l)['fit']!=='staff_review_ready')throw new RuntimeException('Complete project-fit evidence before marking qualified.');
  $evidence=r_text($a,'evidence',500);$amount=r_text($a,'amount',30);$milestone=r_text($a,'milestone',40);
  if($milestone!==''){
   if(!in_array($milestone,['deposit','installation','collected_payment','cost','subsidy','proposal'],true)||!$evidence)throw new RuntimeException('Choose milestone and supply a document/reference.');
   if($milestone!=='installation'&&(!is_numeric($amount)||(float)$amount<=0||(float)$amount>100000000))throw new RuntimeException('Enter the actual amount.');
   foreach($l['milestones'] as $m)if($m['kind']===$milestone&&$m['evidence']===$evidence)throw new RuntimeException('That milestone reference is already recorded.');
   $l['milestones'][]=['kind'=>$milestone,'amount'=>$milestone==='installation'?null:round((float)$amount,2),'evidence'=>$evidence,'at'=>gmdate('c'),'actor'=>$actor];
  }
  foreach(['deposit_received'=>'deposit','installed_commissioning'=>'installation','payment_completed'=>'collected_payment','proposal_sent'=>'proposal'] as $target=>$needed)if($stage===$target&&!array_filter($l['milestones'],fn($m)=>$m['kind']===$needed))throw new RuntimeException('Record supporting '.$needed.' evidence first.');
  if($l['stage']==='do_not_contact'&&$stage!=='do_not_contact')throw new RuntimeException('Opt-out remains in force. Record renewed permission through the correction process first.');
  $now=gmdate('c');$l['history'][]=['at'=>$now,'actor'=>$actor,'kind'=>'staff_outcome','from'=>$l['stage'],'to'=>$stage,'note'=>$note,'reason'=>r_text($a,'reason',300),'previous_facts'=>$l['facts'],'recorded_facts'=>$facts,'previous_next_action'=>$l['next_action'],'previous_due_at'=>$l['due_at']];
  if($stage==='qualified')$l['qualification']=['at'=>$now,'actor'=>$actor,'facts'=>$facts];
  if(in_array($stage,['contact_attempted','connected'],true)&&!isset($l['first_response_at']))$l['first_response_at']=$now;
  $l['facts']=$facts;$l['stage']=$stage;$l['owner']=$owner;$l['next_action']=$next;$l['due_at']=r_closed($stage)?$l['due_at']:date('c',strtotime($due.'+05:30'));$l['updated_at']=$now;$l['version']++;
  if(($a['permission_verified']??'')==='1'&&$l['contact_permission']!=='do_not_contact'){$l['contact_permission']='enquiry_only';$l['history'][]=['kind'=>'contact_permission_verified','at'=>$now,'actor'=>$actor,'note'=>$note];}
  if($stage==='do_not_contact')$l['contact_permission']='do_not_contact';
  $s['leads'][$id]=$l;
 });
}
function r_notify(callable $transport): array {
 $c=r_config();$ids=r_state(fn(&$s)=>array_keys($s['leads']),false);$result=[];
 foreach($ids as $id){$claimed=r_state(function(&$s)use($id,$c){$l=&$s['leads'][$id];$n=&$l['notification'];if($c['mode']!=='live'||!$c['mail']||$l['mode']!=='live'||!in_array($n['status'],['pending','retry_required'],true)||($n['last_attempt']??0)>time()-300||$n['attempts']>=5)return false;$n['status']='sending_receipt_unconfirmed';$n['attempts']++;$n['last_attempt']=time();$l['version']++;return true;});if(!$claimed)continue;
  try{$ok=$transport('d.entranchi@gmail.com','Residential solar enquiry needs attention',"Reference: $id\nOwner: Vishesh Vardhan\nOpen https://dakshayani.co.in/residential-enquiries.php\nNo customer details in this email. Mailer acceptance is not proof of inbox delivery.\n");}catch(Throwable $e){$ok=false;}
  r_state(function(&$s)use($id,$ok){$s['leads'][$id]['notification']['status']=$ok?'accepted_by_mailer':'retry_required';$s['leads'][$id]['version']++;});$result[$id]=$ok?'accepted_by_mailer':'retry_required';
 }return $result;
}
function r_draft(array $l,string $kind,string $lang='hi'): string {
 if(($l['contact_permission']??'')!=='enquiry_only'||r_closed($l['stage']))return 'No outreach draft: confirm permission/current status or respect the recorded stop outcome.';
 $en=$lang==='en';$name=$l['name'];$key=r_advice($l)['missing'][0]??'next';
 $questions=['serviceable'=>['your installation locality and PIN code','घर का इलाका और पिनकोड'],'roof_authority'=>['whether you own the roof or have permission to use it','छत आपकी है या उपयोग की अनुमति है'],'paid_bill'=>['the amount actually paid and units on your latest bill','हाल के बिल में वास्तव में भुगतान की राशि और यूनिट'],'roof_space'=>['the usable rooftop area','छत की उपलब्ध जगह'],'shading'=>['whether nearby buildings or trees shade the roof','छत पर आसपास के मकान या पेड़ों की छाया आती है'],'connection_load'=>['your connection phase and sanctioned load from the bill','बिल में कनेक्शन phase और sanctioned load'],'requirement'=>['whether you need bill savings, outage backup or both','बिल की बचत, कटौती में backup या दोनों चाहिए'],'decision_maker'=>['who will approve the project','परियोजना का निर्णय कौन करेगा'],'timing'=>['when you would like to proceed','कब आगे बढ़ना चाहेंगे'],'budget_finance'=>['your budget or interest in bank finance','बजट या बैंक finance की जरूरत'],'next'=>['your preferred next step','अगला कदम']];$q=$questions[$key][$en?0:1];
 $messages=[
 'first'=>$en?"Hello $name, this is Dakshayani Enterprises. We received your home solar enquiry for {$l['district']}. What time suits a short call?":"नमस्ते $name, Dakshayani Enterprises से। {$l['district']} के लिए आपकी सोलर enquiry मिली। छोटी कॉल के लिए कौन सा समय ठीक रहेगा?",
 'missing'=>$en?"For your solar plan, could you confirm $q?":"आपके सोलर प्लान के लिए कृपया यह जानकारी दें: $q।",
 'photos'=>$en?'Please share 2–3 clear rooftop photos if convenient. We will review them and call before deciding whether a visit is needed.':'कृपया सुविधानुसार छत की 2–3 साफ तस्वीरें भेजें। देखकर कॉल करेंगे, फिर visit की जरूरत तय होगी।',
 'callback'=>$en?'Please confirm the callback time discussed. A survey is confirmed separately by our team.':'बात किए गए callback समय की पुष्टि कर दें। Survey की पुष्टि टीम अलग से करेगी।',
 'finance'=>$en?'We can help with a bank application. The bank decides approval, rate and margin. We will show price, loan, EMI and conditional subsidy separately; savings are estimates.':'बैंक आवेदन में सहायता करेंगे। मंजूरी, ब्याज और शुरुआती योगदान बैंक तय करता है। कीमत, लोन, EMI और पात्र subsidy अलग दिखाएंगे; बचत अनुमान है।',
 'compare'=>$en?'On-grid is for electricity-bill reduction and normally stops during outages. Hybrid can supply selected backup loads; battery capacity, chemistry and usable hours need a load assessment.':'On-grid बिल कम करने के लिए है और कटौती में सामान्यतः बंद रहता है। Hybrid चुने हुए उपकरणों को backup दे सकता है; battery और समय load देखकर तय होंगे।',
 'proposal'=>$en?'Have you reviewed our written proposal? Which item needs clarification: scope, price, finance or timing?':'क्या हमारा लिखित प्रस्ताव देखा? Scope, कीमत, finance या समय—किस बात पर जानकारी चाहिए?',
 'trust'=>$en?'Please tell us which service, warranty or subsidy point concerns you. We will share the relevant written scope or official condition before you decide.':'Service, warranty या subsidy में किस बात की चिंता है? निर्णय से पहले संबंधित लिखित scope या आधिकारिक शर्त साझा करेंगे।',
 'later'=>$en?'Understood. When would you like us to check back? We will pause until the agreed date.':'ठीक है। दोबारा कब बात करना चाहेंगे? तय तारीख तक follow-up रोक देंगे।',
 'final'=>$en?'We will pause follow-up now. You can contact us when ready. If you prefer no further contact, please say STOP.':'अभी follow-up रोक रहे हैं। तैयार होने पर संपर्क कर सकते हैं। आगे संपर्क नहीं चाहिए तो STOP लिखें।'
 ];return $messages[$kind]??$messages['first'];
}



