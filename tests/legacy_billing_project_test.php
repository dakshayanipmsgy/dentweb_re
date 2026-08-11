<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/dentweb_956_'.bin2hex(random_bytes(4));
putenv('LEGACY_BILLING_BASE_DIR='.$root.'/legacy'); putenv('DOCUMENTS_BASE_DIR='.$root.'/documents');
require_once __DIR__.'/../admin/includes/documents_helpers.php';
$n=0; function legacy956(bool $ok,string $message):void{global $n;$n++;if(!$ok)throw new RuntimeException($message);}
try {
  $direct=['mobile'=>'9876543210','mobile_key'=>'9876543210','name'=>'Direct Owner','serial_number'=>'C-1','created_from_quote_id'=>'','created_from_quote_no'=>'','solar_plant_installation_date'=>'2025-01-02'];
  $modern=$direct;$modern['created_from_quote_id']='quote-1';
  legacy956(legacy_billing_customer_is_eligible($direct),'direct customer eligible');
  legacy956(!legacy_billing_customer_is_eligible($modern),'quotation-created customer ineligible');
  $first=legacy_billing_enable($direct,['id'=>'1','name'=>'Admin']); legacy956($first['ok']&&!$first['deduplicated'],'project created');
  $second=legacy_billing_enable($direct); legacy956($second['ok']&&$second['deduplicated'],'enable is idempotent');
  legacy956(count(legacy_billing_list_projects())===1,'exactly one project');
  legacy956(glob($root.'/documents/quotations/*.json')===false||glob($root.'/documents/quotations/*.json')===[],'no quotation created');
  $blocked=legacy_billing_enable(array_merge($direct,['mobile'=>'9123456780','mobile_key'=>'9123456780']),[],static fn():bool=>true); legacy956(!$blocked['ok'],'modern duplicate blocked');
  documents_ensure_structure();
  $settings=documents_settings_dir();if(!is_dir($settings))mkdir($settings,0775,true);
  file_put_contents($settings.'/numbering_rules.json',json_encode(['fy_start_month'=>4,'rules'=>[['doc_type'=>'invoice_public','segment'=>'RES','active'=>true,'prefix'=>'INV','format'=>'{{prefix}}/{{segment}}/{{fy}}/{{seq}}','seq_start'=>1,'seq_current'=>1,'seq_digits'=>4]]]));
  $made=documents_create_invoice_from_legacy_project($first['project'],['idempotency_key'=>'click']); legacy956($made['ok'],'legacy invoice created');
  $again=documents_create_invoice_from_legacy_project($first['project'],['idempotency_key'=>'click']);legacy956($again['invoice_id']===$made['invoice_id']&&!empty($again['deduplicated']),'invoice submission idempotent');
  $invoice=documents_get_invoice($made['invoice_id']); legacy956(is_array($invoice)&&($invoice['commercial_ref']['id']??'')===$first['project']['id'],'genuine project linkage');
  legacy956(($invoice['linked_quote_id']??'')===''&&($invoice['quotation_id']??'')==='','no fake quote fields');
  legacy956(!documents_invoice_has_quotation_reference($invoice),'unknown quote basis remains unknown');
  $edited=documents_invoice_recalculate_pricing($invoice,125000,'')['invoice'];legacy956(documents_invoice_final_total($edited)===125000.0&&!documents_invoice_has_price_adjustment($edited),'draft editable without false quotation increase');
  documents_save_invoice($edited);$final=documents_invoice_finalize($edited,['id'=>'1','name'=>'Admin']);legacy956($final['ok'],'legacy invoice finalized');
  documents_save_invoice($final['invoice']);legacy956(count(legacy_billing_invoices($first['project']['id'],true))===1,'final invoice customer-visible only to project');
  $rows=[['id'=>'modern','customer'=>'A','mobile'=>'1','quotation'=>'Q-1','documents_ready'=>true,'link_ready'=>true,'completed_date'=>'2026-01-01','review_changed'=>false,'paid'=>10,'amount'=>10],['id'=>$first['project']['id'],'customer'=>'Direct Owner','mobile'=>'9876543210','quotation'=>$first['project']['id'],'documents_ready'=>true,'link_ready'=>true,'completed_date'=>'2025-01-02','review_changed'=>false,'paid'=>0,'amount'=>125000]];
  require_once __DIR__.'/../includes/project_workspace.php';
  $state=project_workspace_params(['completed_q'=>'LEG-','completed_per_page'=>25],'completed');
  legacy956(count(project_workspace_filter($rows,$state,'completed'))===1,'mixed completed filtering works');
  echo "legacy_billing_project_test passed ($n assertions)\n";
} finally { $it=is_dir($root)?new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST):null;if($it)foreach($it as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}if(is_dir($root))rmdir($root); }
