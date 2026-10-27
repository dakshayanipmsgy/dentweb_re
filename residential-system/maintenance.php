<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/src/core.php';
$command=$argv[1]??'';$id=$argv[2]??'';
if($command!=='mark-synthetic'||!preg_match('/^[a-f0-9]{24}$/D',$id))exit("Use: php maintenance.php mark-synthetic reference\n");
r_state(function(&$s)use($id){if(!isset($s['leads'][$id])||!str_starts_with($s['leads'][$id]['name'],'SYNTHETIC '))throw new RuntimeException('Only a labelled synthetic fixture can be excluded by this command.');$l=&$s['leads'][$id];if(!empty($l['is_test']))return;$l['is_test']=true;$l['stage']='unsuitable';$l['contact_permission']='do_not_contact';$l['next_action']='No outreach: synthetic launch check only.';$l['history'][]=['at'=>gmdate('c'),'actor'=>'owner-authorised-launch-check','kind'=>'synthetic_exclusion','note'=>'Live form acceptance and owner-only alert test, not a customer, opportunity or sale.'];$l['version']++;});
echo 'Synthetic fixture excluded from business totals: '.$id.PHP_EOL;
