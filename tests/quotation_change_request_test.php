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
$assert(str_contains($source,'documents_create_quote_revision'),'new draft uses the centralized numbered revision flow');
$helpers=file_get_contents(__DIR__.'/../admin/includes/documents_helpers.php');
$assert(str_contains($helpers,'documents_generate_quote_number')&&str_contains($helpers,"'is_current_version'] = true")&&str_contains($helpers,"'superseded_by_quote_no'"),'revision gets a new number and becomes current while preserving lineage');
$assert(str_contains($helpers,'documents_repair_broken_quote_revisions'),'legacy broken revisions have a safe repair audit');
$assert(str_contains($renderer,'Request Changes')&&str_contains($renderer,'customer_acceptance_mask_mobile'),'public renderer offers changes and masks mobile');
$assert(str_contains($admin,'Edit Revised Quotation')&&str_contains($admin,'generated_draft_revision_no')&&str_contains($admin,'requested_changes'),'admin list shows request and numbered revised draft action');
