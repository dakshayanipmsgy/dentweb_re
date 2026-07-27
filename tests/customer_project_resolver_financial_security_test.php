<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';

function c856(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$customer=['mobile'=>'9876543210','name'=>'Asha Devi','serial_number'=>'CU-856'];
$base=documents_quote_defaults();
$base=array_merge($base,['id'=>'Q-856','status'=>'accepted','is_current_version'=>true,'customer_mobile'=>'9876543210','customer_name'=>'Asha Devi','customer_snapshot'=>array_merge(documents_customer_snapshot_defaults(),['mobile'=>'9876543210','name'=>'Asha Devi']),'customer_user_link'=>['mobile'=>'9876543210','serial_number'=>'CU-856']]);
$sameMobile=$base; $sameMobile['id']='UNLINKED'; $sameMobile['customer_user_link']=[];
$archived=$base; $archived['id']='ARCHIVED'; $archived['archived_flag']=true;
$stale=$base; $stale['id']='STALE'; $stale['customer_user_link']['serial_number']='OLD';
$conflict=$base; $conflict['id']='CONFLICT'; $conflict['customer_name']='Another Person'; $conflict['customer_snapshot']['name']='Another Person';
$hidden=$base; $hidden['id']='HIDDEN'; $hidden['customer_visible']=false;
$projects=documents_customer_projects($customer,[$sameMobile,$archived,$stale,$conflict,$hidden,$base]);
c856(array_column($projects,'id')===['Q-856'],'only the canonical linked, current, visible project resolves');
c856(documents_customer_document_authorized($customer,['linked_quote_id'=>'Q-856'],[$base]),'linked document is authorized');
c856(!documents_customer_document_authorized($customer,['linked_quote_id'=>'UNLINKED'],[$sameMobile,$base]),'same-mobile unrelated document is denied');

$base['calc']=['grand_total'=>118000,'tax_breakdown'=>['basic_total'=>100000,'gst_total'=>18000]];
$base['commercial_settlement']=['basis'=>'quotation','status'=>'confirmed'];
$q=documents_project_financial_presentation($base,[]);
c856($q['project_amount']===118000.0 && $q['taxable_amount']===100000.0 && $q['gst_amount']===18000.0 && $q['outstanding_amount']===118000.0,'quotation basis is internally consistent');

echo "customer project resolver, financial and document security tests passed\n";
