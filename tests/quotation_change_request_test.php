<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/customer_document_acceptance.php';
$assert=static function(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}fwrite(STDOUT,"PASS: $message\n");};
foreach(['9876541234','919876541234','+91 98765 41234'] as $mobile)$assert(customer_acceptance_normalize_mobile($mobile)==='9876541234','mobile normalizes to national ten digits');
$assert(customer_acceptance_mask_mobile('+91 98765 41234')==='******1234','public mask exposes only last four digits');
$source=file_get_contents(__DIR__.'/../quotation-change-request.php');
$renderer=file_get_contents(__DIR__.'/../includes/quotation_view_renderer.php');
$admin=file_get_contents(__DIR__.'/../admin/partials/quotation-list.php');
$assert(!str_contains($source,'customer_name'), 'change request does not ask for customer name');
$assert(str_contains($source,'flock($lock,LOCK_EX)')&&str_contains($source,"customer_change_request"),'request is persisted under a lock for duplicate prevention');
$assert(str_contains($source,'documents_quote_reset_clone_state')&&str_contains($source,"'revised_from_quote_id'"),'new draft resets state and preserves lineage');
$assert(str_contains($renderer,'Request Changes')&&str_contains($renderer,'customer_acceptance_mask_mobile'),'public renderer offers changes and masks mobile');
$assert(str_contains($admin,'Edit Draft Revision')&&str_contains($admin,'requested_changes'),'admin list shows request and draft action');
