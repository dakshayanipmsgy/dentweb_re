<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/customer_operations.php';

function stale_link_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$dir=sys_get_temp_dir().'/dentweb-stale-link-'.bin2hex(random_bytes(5));
$store=new CustomerFsStore($dir);
$created=$store->addCustomer(['name'=>" José   D’Souza ",'mobile'=>'9876543210','address'=>'Main Road','city'=>'Ranchi','pin_code'=>'834001','state'=>'Jharkhand']);
stale_link_assert(!empty($created['success']),'active Customer User fixture created');
$quote=documents_quote_defaults();
$quote['id']='Q-STALE-850'; $quote['quote_no']='QT-850'; $quote['status']='accepted';
$quote['customer_name']="JOSE\u{0301} D’SOUZA"; $quote['customer_mobile']='+91 98765 43210';
$quote['site_address']=' Main   Road '; $quote['city']='ranchi'; $quote['pin']='834001';
$quote['updated_at']='2026-07-27T00:00:00+00:00';
$quote['customer_user_link']=['mobile'=>'9123456789','serial_number'=>'obsolete','link_type'=>'created'];
$quote['customer_snapshot']=array_merge(documents_customer_snapshot_defaults(),['name'=>$quote['customer_name'],'mobile'=>$quote['customer_mobile']]);
$state=customer_conflict_detect($quote,$store,[$quote]);
stale_link_assert($state['state']==='stale_link','Unicode/case/whitespace-normalized matching isolates stale metadata');
stale_link_assert($state['differences']===[],'stale metadata is not reported as a genuine identity/detail conflict');
$_SESSION['csrf_token']='stale-link-csrf';
$html=html_entity_decode(customer_conflict_render_resolver($quote,'completed_customers',$store));
foreach(['Resolve conflict','Confirm and repair current link','Quotation / project','Customer User','José   D’Souza','9876543210','Required reason','expected_version','csrf_token'] as $needle) {
    stale_link_assert(str_contains($html,$needle),'stale-link resolver contains '.$needle);
}
stale_link_assert(!str_contains($html,'Use Customer User details everywhere'),'genuine conflict choices are not offered for stale-link-only state');
$source=(string)file_get_contents(__DIR__.'/../includes/customer_operations.php');
stale_link_assert(str_contains($source,"'customer_user_link_metadata'"),'repair records a linkage-metadata-only change');
stale_link_assert(str_contains($source,'customer_conflict_detect($reloaded,$store)'),'repair is recomputed before reporting verified success');
$page=(string)file_get_contents(__DIR__.'/../admin-documents.php');
stale_link_assert(str_contains($page,"typeof resolver.showModal === 'function'"),'dialog has showModal fallback');
stale_link_assert(str_contains($page,'resolver-open-error'),'dialog opening exposes an inline error target');
foreach(glob($dir.'/*')?:[] as $file) @unlink($file);
@rmdir($dir);
echo "project customer stale link conflict tests passed\n";
