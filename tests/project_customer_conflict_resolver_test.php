<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/customer_operations.php';

function resolver_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$dir=sys_get_temp_dir().'/dentweb-resolver-'.bin2hex(random_bytes(5));
$store=new CustomerFsStore($dir);
$created=$store->addCustomer([
    'name'=>'Customer User Name','mobile'=>'9876543210','address'=>'Customer Address',
    'city'=>'Ranchi','pin_code'=>'834001','password_hash'=>'security-must-remain-untouched',
]);
resolver_assert(!empty($created['success']),'Customer User fixture created');

$quote=documents_quote_defaults();
$quote['id']='Q-RESOLVER-841'; $quote['quote_no']='QT-841'; $quote['status']='accepted';
$quote['customer_name']='Quotation Name'; $quote['customer_mobile']='9876543210';
$quote['site_address']='Project Address'; $quote['city']='Ranchi'; $quote['pin']='';
$quote['updated_at']='2026-07-26T00:00:00+00:00';
$quote['customer_snapshot']=array_merge(documents_customer_snapshot_defaults(),['name'=>'Quotation Name','mobile'=>'9876543210']);
$archived=$quote; $archived['id']='ARCHIVED-841'; $archived['archived_flag']=true;

$detected=customer_conflict_detect($quote,$store,[$quote,$archived]);
resolver_assert($detected['state']==='conflict' && $detected['type']==='details','detail conflict classified');
resolver_assert(isset($detected['differences']['name'],$detected['differences']['address'],$detected['differences']['pin_code']),'all differing fields exposed');
resolver_assert(count($detected['affected'])===1 && $detected['affected'][0]['id']==='Q-RESOLVER-841','archived quotations completely excluded');

$_SESSION['csrf_token']='resolver-csrf';
$html=html_entity_decode(customer_conflict_render_resolver($quote,'accepted_customers',$store));
foreach([
    'Resolve conflict','Quotation / project','Customer User','Affected active quotations/projects',
    'Use Customer User details everywhere','Use quotation/project details everywhere','Choose field by field',
    'Fill only missing handover-required fields','Ignore for now','Before/after preview','Required reason',
    'expected_version','request_id',
] as $needle) resolver_assert(str_contains($html,$needle),'resolver contains '.$needle);
resolver_assert(str_contains($html,'IDs, references, public links, statuses, timestamps, commercial/payment data'),'preservation guarantee is shown before apply');

$identityQuote=$quote;
$identityQuote['customer_mobile']='9123456789';
$identityQuote['customer_user_link']=['mobile'=>'9876543210'];
$identity=customer_conflict_detect($identityQuote,$store,[$identityQuote]);
resolver_assert($identity['type']==='identity' && !empty($identity['differences']['mobile']['identity']),'mobile mismatch classified as identity conflict');
$identityHtml=html_entity_decode(customer_conflict_render_resolver($identityQuote,'completed_customers',$store));
foreach(['Use Customer User identity','Use quotation/project identity','explicitly confirm','Change quotation mobile through issue #811'] as $needle) {
    resolver_assert(str_contains($identityHtml,$needle),'identity resolver contains '.$needle);
}

$archivedCustomer=$created['customer'];
resolver_assert($store->archiveCustomer('9876543210','test'),'Customer User archived');
$archivedState=customer_conflict_detect($quote,$store,[$quote]);
resolver_assert($archivedState['state']==='archived','archived Customer User detected for restoration');
resolver_assert(str_contains(customer_conflict_render_resolver($quote,'accepted_customers',$store),'Restore archived Customer User'),'restore offered instead of duplicate creation');

$missingQuote=$quote; $missingQuote['customer_mobile']='9234567890'; $missingQuote['customer_user_link']=[];
$missing=customer_conflict_detect($missingQuote,$store,[$missingQuote]);
resolver_assert($missing['state']==='missing','missing Customer User detected');
resolver_assert(str_contains(customer_conflict_render_resolver($missingQuote,'accepted_customers',$store),'Create missing Customer User'),'issue #809 creation option offered');

$blocked=customer_conflict_apply('does-not-exist',['reason'=>'','resolution'=>'ignore'],['actor_type'=>'admin','actor_id'=>'test'],$store);
resolver_assert($blocked['state']==='blocked','reason is mandatory before apply');

foreach(glob($dir.'/*')?:[] as $file) @unlink($file);
@rmdir($dir);
echo "project customer conflict resolver tests passed\n";
