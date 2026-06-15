<?php declare(strict_types=1); require_once __DIR__.'/../admin/includes/documents_helpers.php';
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
$i=documents_normalize_dispatch_advice_items([['name'=>'Panel','qty'=>2,'unit'=>'Nos','component_id'=>'secret','cost'=>99,'shortage'=>4]]);ok(count($i)===1,'safe item retained');ok(!isset($i[0]['component_id'])&&!isset($i[0]['cost'])&&!isset($i[0]['shortage']),'internal fields removed');
$a=documents_dispatch_advice_defaults();$a['items']=$i;$c=['items'=>[['name'=>'Panel','qty'=>2,'unit'=>'Nos']]];ok(!documents_dispatch_advice_items_differ($a,$c),'same items compare equal');$c['items'][0]['qty']=3;ok(documents_dispatch_advice_items_differ($a,$c),'difference detected');echo "Dispatch advice tests passed\n";
