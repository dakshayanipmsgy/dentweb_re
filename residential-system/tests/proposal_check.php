<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/proposal.php';
putenv('RES_DATA='.dirname(__DIR__).'/qa-runtime/proposal-'.bin2hex(random_bytes(4)));putenv('RES_MODE=preview');
$r=r_accept(['name'=>'SYNTHETIC proposal','phone'=>'9000000003','district'=>'Ranchi','bill'=>'4500+','consent'=>'1','request_id'=>bin2hex(random_bytes(16))],'proposal');
$a=['version'=>1,'system_type'=>'ongrid','package_code'=>'O5-1P-D','panel_variant'=>'mixed','confirmed_scope'=>'TEST modules and inverter; final mounting review required','site_assumptions'=>'TEST pitched roof, shade review completed','exclusions'=>'TEST extra structure and meter costs separately quoted','scope_confirmed'=>'1'];
$ref=r_proposal($r['reference'],$a,'TEST');$l=r_state(fn(&$s)=>$s['leads'][$r['reference']],false);
if(!str_ends_with($ref,'-P1')||$l['stage']!=='new'||$l['proposals'][0]['price']!==281900||!str_contains($l['proposals'][0]['text'],'No subsidy deducted'))throw new RuntimeException('Proposal failed');
$a['version']=2;r_proposal($r['reference'],$a,'TEST');$l=r_state(fn(&$s)=>$s['leads'][$r['reference']],false);if(count($l['proposals'])!==2)throw new RuntimeException('Version failed');
echo "PASS approved pricebook, mixed price, zero assumed subsidy, version history, draft does not become sent proposal.\n";
