<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer_bulk_import.php';
function field_choice_assert(bool $ok,string $message):void { if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);} }
$dir=sys_get_temp_dir().'/dentweb-field-choice-'.bin2hex(random_bytes(4));
$store=new CustomerFsStore($dir);
$hash=password_hash('preserve-me',PASSWORD_DEFAULT);
$made=$store->addCustomer(['mobile'=>'+91 98765-43210','name'=>'Saved Name','address'=>'Saved address','city'=>'Ranchi','serial_number'=>'77','password_hash'=>$hash]);
field_choice_assert(!empty($made['success']),'fixture created');
$quote=['id'=>'Q843','customer_mobile'=>'9876543210','customer_name'=>'Saved Name','site_address'=>'Saved address','city'=>'Ranchi','status'=>'Accepted','archived_flag'=>false,'items'=>[['price'=>500]],'public_token'=>'preserve'];
$archived=array_merge($quote,['id'=>'Q-ARCHIVED','archived_flag'=>true]);
$preview=customer_bulk_mobile_sync_preview($store,"mobile,name,address,city,password,serial_number\n98765 43210,CSV Name,CSV address,Ranchi,hack,999\n",[$quote,$archived]);
$row=$preview['rows'][0];
field_choice_assert($row['result']==='Ready','mobile-only match is ready');
field_choice_assert($row['fields']['city']['state']==='Unchanged','identical field marked unchanged');
field_choice_assert($row['fields']['name']['choice']==='keep','difference defaults to keep');
field_choice_assert(!isset($row['fields']['password'],$row['fields']['serial_number']),'protected fields are not choices');
field_choice_assert(count($row['quotes'])===1 && $row['quotes'][0]['id']==='Q843','archived quotation excluded');
$confirmed=customer_bulk_mobile_sync_confirm($preview,[0=>['row'=>'sync','name'=>['choice'=>'csv'],'address'=>['choice'=>'manual','manual'=>'Corrected address']]]);
$changes=$confirmed['rows'][0]['customer_changes'];
field_choice_assert($changes['name']['to']==='CSV Name','CSV selection planned');
field_choice_assert($changes['address']['to']==='Corrected address','manual selection planned');
field_choice_assert(!isset($changes['city']),'unchanged field not rewritten');
$ignored=customer_bulk_mobile_sync_confirm($preview,[0=>['row'=>'ignore']]);
field_choice_assert($ignored['rows'][0]['result']==='Ignored' && $ignored['rows'][0]['customer_changes']===[],'entire row can be ignored');
$duplicate=customer_bulk_mobile_sync_preview($store,"mobile,name\n9876543210,A\n+91-98765-43210,B\n",[]);
field_choice_assert($duplicate['rows'][0]['result']==='Conflict' && $duplicate['rows'][1]['result']==='Conflict','normalized duplicate identities blocked');
@unlink($dir.'/customers.json'); @unlink($dir.'/customers.lock'); @rmdir($dir);
echo "customer_csv_field_choice_sync_test: ok\n";
