<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer_bulk_import.php';
function compact_assert(bool $ok,string $message):void { if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);} }
$dir=sys_get_temp_dir().'/dentweb-compact-csv-'.bin2hex(random_bytes(4));
$store=new CustomerFsStore($dir);
$store->addCustomer(['mobile'=>'9876543210','name'=>" José\tKumar ",'address'=>'','city'=>'Ranchi','password_hash'=>password_hash('keep',PASSWORD_DEFAULT),'serial_number'=>'42']);
$cosmetic=customer_bulk_mobile_sync_preview($store,"mobile,name,city\r\n9876543210,josé  kumar,RANCHI\r\n",[]);
compact_assert($cosmetic['rows'][0]['result']==='Unchanged','case, whitespace, Unicode and line endings compare cosmetically equal');
$preview=customer_bulk_mobile_sync_preview($store,"mobile,name,address,city,password,serial_number\n9876543210,New Name,CSV Address,,replace,99\n",[]);
$row=$preview['rows'][0];
compact_assert($row['result']==='Ready' && $row['fields']['name']['choice']==='csv','different populated value defaults to CSV');
compact_assert($row['fields']['address']['choice']==='csv' && $row['fields']['city']['choice']==='keep','blank-value defaults are safe');
compact_assert(isset($row['row_token']) && !isset($row['fields']['password'],$row['fields']['serial_number']),'staged token exists and protected fields are excluded');
$result=customer_bulk_mobile_sync_apply_staged_row($store,$preview,$row['row_token'],[]);
compact_assert($result['ok'] && $result['result']==='Applied','individual staged row applies');
$saved=$store->findByMobile('9876543210');
compact_assert($saved['name']==='New Name' && $saved['address']==='CSV Address' && $saved['city']==='Ranchi','selected values apply without blank overwrite');
compact_assert(password_verify('keep',$saved['password_hash']) && $saved['serial_number']==='42','password and serial are preserved');
$again=customer_bulk_mobile_sync_apply_staged_row($store,$preview,$row['row_token'],[]);
compact_assert(!$again['ok'] && $again['result']==='Conflict','duplicate application is stale and rejected');
$stale=customer_bulk_mobile_sync_preview($store,"mobile,name\n9876543210,Another Name\n",[]);
$current=$store->findByMobile('9876543210'); $current['address']='changed after preview'; $store->updateCustomer('9876543210',$current);
$failed=customer_bulk_mobile_sync_apply_staged_row($store,$stale,$stale['rows'][0]['row_token'],[]);
compact_assert(!$failed['ok'] && $failed['result']==='Conflict','stale row remains a conflict');
$page=file_get_contents(__DIR__.'/../admin-customers.php');
foreach(['Save customer','Ignore','Save All','Change choice','Selected final value:','save_customer_sync_ajax','fetch(\'admin-customers.php\''] as $needle) compact_assert(str_contains($page,$needle),'compact AJAX review contains '.$needle);
@unlink($dir.'/customers.json'); @unlink($dir.'/customers.lock'); @rmdir($dir);
echo "customer_csv_compact_review_ajax_test: ok\n";
