<?php declare(strict_types=1); require_once __DIR__.'/../admin/includes/documents_helpers.php';
function wok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
$c=documents_dispatch_catalog_seed();wok(count($c)===8,'eight seeded generic items');wok($c[0]['name']==='Solar Panels'&&$c[7]['name']==='Lightning Arrestor','catalog order');wok(documents_dispatch_catalog_match('panel',$c)['name']==='Solar Panels','aliases map to generic item');
$q=['status'=>'accepted','customer_name'=>'Test','customer_mobile'=>'9876543210'];wok(documents_dispatch_quote_eligible($q),'accepted quote eligible');$q['status']='approved';wok(!documents_dispatch_quote_eligible($q),'approved quote ineligible');
$d=documents_dispatch_advice_defaults();$d['items']=[['name'=>'Panel','qty'=>2,'unit'=>'Nos','cost'=>99]];$i=documents_normalize_dispatch_advice_items($d['items']);wok(!isset($i[0]['cost']),'internal fields removed');echo "Dispatch workflow tests passed\n";
