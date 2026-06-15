<?php declare(strict_types=1);
require_once __DIR__.'/../admin/includes/documents_helpers.php';
function clone_ok($v,$m){if(!$v)throw new RuntimeException($m);}
$q=['id'=>'old','status'=>'accepted','accepted_at'=>'now','customer_acceptance'=>['confirmed_at'=>'now'],'customer_acceptance_request'=>['token_hash'=>'secret'],'acceptance_ref'=>'ref','locked_flag'=>true,'workflow'=>['invoice_id'=>'i1'],'links'=>['invoice_id'=>'i1'],'public_share_token'=>'oldtoken'];
$c=documents_quote_reset_clone_state($q,'new');
clone_ok($c['status']==='draft'&&!$c['locked_flag'],'clone is independent draft');
clone_ok(!isset($c['customer_acceptance'],$c['customer_acceptance_request'],$c['acceptance_ref']),'acceptance evidence removed');
clone_ok($c['public_share_token']!==$q['public_share_token']&&strlen($c['public_share_token'])>=32,'new high entropy public token');
clone_ok($c['workflow']['invoice_id']===''&&$c['links']['invoice_id']==='','workflow links removed');
echo "quotation clone reset tests passed\n";
