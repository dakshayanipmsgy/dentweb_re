<?php declare(strict_types=1); require_once __DIR__.'/../admin/includes/documents_helpers.php';
function wok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
$c=documents_dispatch_catalogue_defaults();wok(count($c)===7,'seven generic items');wok(array_column($c,'name')===['Solar Panels','Inverter','Batteries','Mounting Structure','Wire / Cables','Earthing','Lightning Arrestor'],'catalogue names');
$d=documents_dispatch_advice_defaults();$d['id']='test_da';$d['items']=[['name'=>'Panel','description'=>'D','brand_model'=>'B','qty'=>2,'unit'=>'Nos','remarks'=>'R']];wok(documents_normalize_dispatch_advice_items($d['items'])[0]['brand_model']==='B','preserves safe details');
$inv=documents_invoice_defaults();wok(!isset($inv['delivery_details']),'historical defaults remain compatible');echo "Dispatch workflow tests passed\n";
