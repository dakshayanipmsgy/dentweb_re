<?php
declare(strict_types=1);
require_once __DIR__.'/includes/public_document_security.php';
protect_customer_document_response();
header('Referrer-Policy: no-referrer');
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/admin/includes/documents_helpers.php';
$token=safe_text((string)($_GET['t']??''));
$quote=$token!==''?documents_get_quote_by_public_share_token($token):null;
if($token===''){
    require_login_any_role(['admin','employee']);
    $quote=documents_get_quote(safe_text((string)($_GET['id']??'')));
    $user=current_user();
    if($quote&&($user['role_name']??'')!=='admin'&&(string)($quote['created_by_id']??'')!==(string)($user['id']??''))$quote=null;
}
$expires=(string)($quote['public_share_expires_at']??'');
if(!$quote||documents_is_archived($quote)||($token!==''&&
    (empty($quote['public_share_enabled'])||!in_array(documents_quote_normalize_status((string)($quote['status']??'')),['approved','accepted'],true)||
    empty($quote['is_current_version'])||($expires!==''&&strtotime($expires)!==false&&strtotime($expires)<time())))){
    http_response_code(404);exit('Payment link unavailable. Please request the current approved quotation.');
}
$h=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES,'UTF-8');
$amount='';$upi='';$error='';
if(isset($_GET['amount']))try{$amount=quotation_workflow_amount((string)$_GET['amount']);$upi=quotation_workflow_upi($amount,(string)$quote['quote_no']);}catch(InvalidArgumentException $e){$error=$e->getMessage();}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Pay an amount | Dakshayani Enterprises</title>
<style>body{font:17px/1.6 system-ui;background:#eef5f7;color:#123044;margin:0;padding:20px}main{max-width:600px;margin:auto;background:white;padding:28px;border-radius:16px}input,button,a.btn{font:inherit;padding:12px;border-radius:7px}input{display:block;border:1px solid #738a9b;width:90%}.actions{display:flex;flex-wrap:wrap;gap:12px;margin:18px 0}.btn,button{background:#076978;color:white;border:0;text-decoration:none;cursor:pointer}#qr{margin:20px 0}#qr img{max-width:100%;height:auto}a:focus-visible,input:focus-visible,button:focus-visible{outline:3px solid #a85d00;outline-offset:3px}.error{color:#9d2222}</style><script src="assets/vendor/qrcode.min.js" defer></script></head><body><main>
<h1>Pay an amount</h1><p>Dakshayani Enterprises<br>Quotation <strong><?= $h((string)$quote['quote_no']) ?></strong><br>UPI ID: <strong>d.entranchi@ybl</strong></p>
<p>You may pay part of the agreed advance now. A partial payment does not change the quotation price or payment milestones.</p>
<?php if($error!==''): ?><p role="alert" class="error"><?= $h($error) ?></p><?php endif; ?>
<form method="get"><?php if($token!==''): ?><input type="hidden" name="t" value="<?= $h($token) ?>"><?php else: ?><input type="hidden" name="id" value="<?= $h((string)$quote['id']) ?>"><?php endif; ?>
<label for="paymentAmount">Amount to pay ₹</label><input id="paymentAmount" name="amount" type="number" min="0.01" max="10000000" step="0.01" inputmode="decimal" required value="<?= $h($amount) ?>" placeholder="For example, 5000"><div class="actions"><button type="submit">Continue with this amount</button></div></form>
<?php if($upi!==''): ?><h2>Pay ₹<?= $h(number_format((float)$amount,2)) ?></h2><div class="actions"><a class="btn" href="<?= $h($upi) ?>">Pay by UPI</a><button type="button" id="generateQR">Generate QR</button></div><div id="qr" role="img" aria-label="QR for entered payment amount"></div><p id="qrStatus" role="status"></p>
<script>document.addEventListener('DOMContentLoaded',()=>{document.getElementById('generateQR').addEventListener('click',()=>{const status=document.getElementById('qrStatus');if(typeof QRCode!=='function'){status.textContent='QR could not load. Use Pay by UPI or try again.';return;}const target=document.getElementById('qr');target.replaceChildren();new QRCode(target,{text:<?= json_encode($upi,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,width:240,height:240,correctLevel:QRCode.CorrectLevel.M});status.textContent='Scan this QR in a UPI app. This is not proof of payment.';});});</script><?php endif; ?>
<p>Opening a UPI app or generating a QR does not confirm payment. Share the transaction reference with Dakshayani Enterprises; a receipt is issued after verification.</p>
</main></body></html>
