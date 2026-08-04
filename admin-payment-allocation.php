<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_login_any_role(['admin', 'employee']);
$user=current_user();
if((string)($user['role_name']??'')!=='admin'){http_response_code(403);echo 'Access denied.';exit;}

$quoteId=safe_text($_SERVER['REQUEST_METHOD']==='POST'?($_POST['quotation_id']??''):($_GET['quotation_id']??''));
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf_token(is_string($_POST['csrf_token']??null)?$_POST['csrf_token']:null)){http_response_code(403);$error='Security validation failed. No changes were made.';}
    elseif(safe_text($_POST['action']??'')!=='repair_payment_allocation'){http_response_code(400);$error='Invalid confirmation action.';}
    else{$result=documents_apply_payment_allocation_repair($quoteId,(string)($_POST['state_hash']??''),(array)$user);if(!empty($result['ok'])){header('Location: admin-payment-allocation.php?'.http_build_query(['quotation_id'=>$quoteId,'status'=>'success','message'=>'Payment allocation repaired. Financial summaries have been refreshed.']));exit;}$error=(string)($result['error']??'Repair was refused.');}
}
$plan=documents_payment_allocation_repair_plan($quoteId);$target=is_array($plan['target_invoice']??null)?$plan['target_invoice']:[];
$money=static fn(int $paise):string=>'₹'.number_format(documents_invoice_paise_to_money($paise),2);
$esc=static fn($value):string=>htmlspecialchars((string)$value,ENT_QUOTES);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Review payment allocation</title><link rel="stylesheet" href="assets/css/commercial-documents.css"><style>body{max-width:980px;margin:32px auto;padding:0 18px;font-family:system-ui}.card{border:1px solid #dbe3ee;border-radius:14px;padding:20px;margin:16px 0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}.fact{background:#f8fafc;padding:12px;border-radius:9px}.error{background:#fff1f2;color:#9f1239;padding:12px}.success{background:#ecfdf5;color:#166534;padding:12px}.btn{display:inline-block;padding:10px 14px;border:0;border-radius:8px;background:#1d4ed8;color:#fff;text-decoration:none;cursor:pointer}.secondary{background:#475569}</style></head><body>
<p><a href="admin-documents.php?tab=accepted_customers&amp;view=<?=urlencode($quoteId)?>">← Return to document pack</a></p><h1>Review payment allocation</h1>
<?php if(isset($error)):?><div class="error" role="alert"><?=$esc($error)?></div><?php endif;?>
<?php if(($_GET['status']??'')==='success'):?><div class="success" role="status"><?=$esc($_GET['message']??'Payment allocation repaired.')?></div><?php endif;?>
<section class="card"><h2>Safe preview</h2><div class="grid">
<div class="fact"><strong>Affected receipts</strong><br><?= (int)$plan['affected_count']?></div><div class="fact"><strong>Total requiring repair</strong><br><?=$money((int)$plan['repair_paise'])?></div>
<div class="fact"><strong>Current invoice target</strong><br><?=$esc($target['invoice_no']??$target['id']??'None')?></div><div class="fact"><strong>Document status</strong><br><?=$esc(ucfirst((string)($target['status']??'Not available')))?> (unchanged)</div>
<div class="fact"><strong>Presently recognized</strong><br><?=$money((int)$plan['recognized_before_paise'])?></div><div class="fact"><strong>Recognized after repair</strong><br><?=$money((int)$plan['recognized_after_paise'])?></div>
<div class="fact"><strong>Resulting balance</strong><br><?=$money((int)$plan['balance_after_paise'])?></div><div class="fact"><strong>Resulting payment status</strong><br><?=$esc(documents_invoice_payment_status_label((string)$plan['payment_status_after']))?></div></div>
<h3>Current allocation health</h3><?php if($plan['categories']===[]):?><p>No unresolved allocation health categories.</p><?php else:?><ul><?php foreach($plan['categories'] as $category=>$count):?><li><?=$esc(ucwords(str_replace('_',' ',$category)))?>: <?= (int)$count?> receipt(s)</li><?php endforeach;?></ul><?php endif;?>
<p><strong>No receipt will be created, deleted, or financially modified.</strong> The repair changes only safe invoice-allocation metadata. Receipt amount, date, mode, reference, status and project ownership remain unchanged. The invoice document status will remain <strong><?=$esc((string)($target['status']??'unchanged'))?></strong>; a Draft invoice will not be issued or finalized.</p>
<?php if(!empty($plan['can_repair'])):?><form method="post"><input type="hidden" name="csrf_token" value="<?=$esc(csrf_token())?>"><input type="hidden" name="action" value="repair_payment_allocation"><input type="hidden" name="quotation_id" value="<?=$esc($quoteId)?>"><input type="hidden" name="state_hash" value="<?=$esc($plan['state_hash'])?>"><button class="btn" type="submit">Repair payment allocation</button></form>
<?php else:?><div class="error"><strong>Automatic repair unavailable.</strong><ul><?php foreach($plan['blocked'] as $reason):?><li><?=$esc($reason)?></li><?php endforeach;?></ul></div><?php if(in_array($plan['active_invoice_state'],['multiple'],true)):?><p><a class="btn secondary" href="admin-documents.php?tab=accepted_customers&amp;view=<?=urlencode($quoteId)?>">Open manual allocation workflow</a></p><?php endif;?><?php endif;?></section></body></html>
