<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer_bulk_import.php';

function csv_sync_assert(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$dir=sys_get_temp_dir().'/dentweb-csv-sync-'.bin2hex(random_bytes(4));
$store=new CustomerFsStore($dir);
$created=$store->addCustomer(['mobile'=>'98765 43210','name'=>'Old Name','customer_type'=>'PM Surya Ghar','address'=>'Old address','password_hash'=>password_hash('secret', PASSWORD_DEFAULT)]);
csv_sync_assert(!empty($created['success']), 'fixture customer created');
$quote=['id'=>'Q834','customer_mobile'=>'+91 98765-43210','customer_name'=>'Old Name','site_address'=>'Old address','status'=>'Accepted','revision'=>7,'archived_flag'=>false,'items'=>[['price'=>123]],'public_token'=>'keep'];
$csv="mobile,name,address,status,password,serial_number\n+91 98765-43210,New Name,New address,Completed,hack,999\n";
$preview=customer_bulk_mobile_sync_preview($store,$csv,[$quote]);
csv_sync_assert(($preview['error']??'')==='', 'CSV accepted');
$row=$preview['rows'][0];
csv_sync_assert($row['result']==='Ready', 'valid row is ready');
csv_sync_assert($row['fields']['name']['state']==='Different' && $row['fields']['address']['state']==='Different', 'populated differences previewed and defaulted safely');
csv_sync_assert(!isset($row['customer_changes']['status'],$row['customer_changes']['password'],$row['customer_changes']['serial_number']), 'protected fields excluded');
csv_sync_assert(count($row['quotes'])===1, 'all mobile-matched quotes previewed');
$confirmed=customer_bulk_mobile_sync_confirm($preview,[0=>['name'=>['choice'=>'csv'],'address'=>['choice'=>'csv']]]);
csv_sync_assert(isset($confirmed['rows'][0]['quotes'][0]['changes']['customer_name']), 'selected compatible fields are planned for quotations');
$duplicate=customer_bulk_mobile_sync_preview($store,"mobile,name\n9876543210,A\n+91 9876543210,B\n",[]);
csv_sync_assert($duplicate['rows'][0]['result']==='Conflict' && $duplicate['rows'][1]['result']==='Conflict', 'normalized duplicate rows blocked');
$unchanged=customer_bulk_mobile_sync_preview($store,"mobile,name\n9876543210,Old Name\n",[]);
csv_sync_assert($unchanged['rows'][0]['result']==='Unchanged', 'identical record marked unchanged');
$other=customer_bulk_mobile_sync_preview($store,"mobile,name\n9999999999,Old Name\n",[]);
csv_sync_assert($other['rows'][0]['result']==='Conflict', 'same name with different mobile is not matched');

$ids=[];
foreach (['draft','approved','accepted'] as $status) {
    $id='CSV-SYNC-'.strtoupper($status).'-'.bin2hex(random_bytes(3)); $ids[]=$id;
    $stored=$quote; $stored['id']=$id; $stored['status']=$status; $stored['project_completion']=['state'=>'completed','snapshot'=>['customer_name'=>'Old Name']];
    documents_save_quote($stored);
}
$archivedId='CSV-SYNC-ARCHIVED-'.bin2hex(random_bytes(3)); $ids[]=$archivedId;
$stored=$quote; $stored['id']=$archivedId; $stored['status']='accepted'; $stored['archived_flag']=true; documents_save_quote($stored);
$writePreview=customer_bulk_mobile_sync_preview($store,$csv);
$writeConfirmed=customer_bulk_mobile_sync_confirm($writePreview,[0=>['name'=>['choice'=>'csv'],'address'=>['choice'=>'csv']]]);
$writeResult=customer_bulk_mobile_sync_apply($store,$writeConfirmed);
csv_sync_assert($writeResult['applied']===1 && $store->findByMobile('9876543210')['name']==='New Name','confirmed fields applied to active CustomerFsStore customer');
foreach (array_slice($ids,0,3) as $id) csv_sync_assert(documents_get_quote($id)['customer_name']==='New Name', 'active '.$id.' synchronized');
csv_sync_assert(documents_get_quote($archivedId)['customer_name']==='Old Name','archived quotation excluded from writes');
csv_sync_assert(documents_get_quote($ids[2])['project_completion']['snapshot']['customer_name']==='Old Name','completion snapshot remains immutable');
foreach ($ids as $id) @unlink(documents_quotations_dir().'/'.safe_filename($id).'.json');
@unlink($dir.'/customers.json'); @unlink($dir.'/customers.lock'); @rmdir($dir);
echo "customer_csv_mobile_sync_test: ok\n";
