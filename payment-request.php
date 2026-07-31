<?php
declare(strict_types=1);
require_once __DIR__ . '/admin/includes/documents_helpers.php';

header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $method = (string)($_POST['method'] ?? 'direct');
    $result = documents_payment_request_authorize_upi($token, null, $method);
    http_response_code(!empty($result['ok']) ? 200 : 409);
    echo json_encode($result, JSON_UNESCAPED_SLASHES); exit;
}

$request = documents_payment_request_find_by_token($token);
$available = $request !== null && documents_payment_request_link_available($request);
// Remember an already-issued bearer in this browser so an authenticated
// customer can reopen the same request from the dashboard. This GET neither
// creates a link nor consumes one of its two authorized UPI launches.
if ($available) {
    if (function_exists('start_session')) {
        start_session();
    } elseif (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['payment_request_upi_tokens'][(string) $request['id']] = $token;
}
$link = is_array($request['upi_link'] ?? null) ? $request['upi_link'] : [];
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$amount = $request ? '₹' . number_format((float)$request['amount_requested'], 2) : '—';
$remaining = $available ? max(0, 2-(int)($link['launch_count']??0)) : 0;
$bank = $available ? documents_company_bank_transfer_details() : ['fields'=>[], 'has_details'=>false];
$portal = $available ? documents_payment_request_portal_guidance($request) : ['available'=>false, 'login_url'=>'', 'password_instruction'=>'', 'show_default_password'=>false, 'message'=>''];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>Payment Request</title><style>
*{box-sizing:border-box}body{margin:0;background:#eef6f4;color:#153b36;font:16px/1.5 system-ui,sans-serif}.card{max-width:720px;margin:6vh auto;background:#fff;border:1px solid #d8e8e4;border-radius:20px;padding:30px;box-shadow:0 18px 55px #174b4020}h1{margin-top:0}h2{font-size:1.2rem;margin:0 0 10px}.amount{font-size:2.3rem;font-weight:800;color:#0f766e}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:22px 0}.item{padding:12px;background:#f5faf9;border-radius:10px}.label{display:block;font-size:.75rem;text-transform:uppercase;color:#64748b;font-weight:700}.actions{display:grid;grid-template-columns:1fr 1fr;gap:12px}.btn{display:block;width:100%;border:0;border-radius:12px;padding:14px;background:#0f766e;color:white;font-size:1rem;font-weight:800;cursor:pointer;text-align:center;text-decoration:none}.btn:disabled{background:#94a3b8}.secondary{background:#334155}.panel{margin-top:22px;padding:18px;border:1px solid #d8e8e4;border-radius:12px;background:#f8fbfa}.bank-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #e2e8f0}.bank-row:last-child{border:0}.copy{border:1px solid #0f766e;border-radius:7px;background:white;color:#0f766e;padding:6px 10px;font-weight:700;cursor:pointer}.notice{padding:14px;border-radius:10px;background:#fff7ed;color:#9a3412}.muted{color:#64748b;font-size:.9rem}.capabilities{columns:2;margin-bottom:0}#qr{display:none;width:256px;min-height:256px;margin:18px auto;padding:8px;background:#fff}#qr img,#qr canvas{display:block;margin:auto}@media(max-width:640px){.card{margin:0;min-height:100vh;border-radius:0}.grid,.actions{grid-template-columns:1fr}.capabilities{columns:1}}
</style><script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js" defer></script></head><body><main class="card"><h1>Dakshayani Enterprises</h1><?php if(!$available): ?><div class="notice"><strong>Payment link unavailable</strong><br>This link is invalid, expired, exhausted, refreshed, cancelled, archived, or already paid. Please ask Dakshayani Enterprises for a refreshed link.</div><?php else: ?><span class="label">Amount requested</span><div class="amount"><?=$h($amount)?></div><div class="grid"><div class="item"><span class="label">Request reference</span><?=$h((string)$request['id'])?></div><div class="item"><span class="label">Quotation reference</span><?=$h((string)$request['quotation_id'])?></div><div class="item"><span class="label">Reason</span><?=$h(documents_payment_request_reason_label($request))?></div><div class="item"><span class="label">Due date</span><?=$h((string)($request['due_date']?:'As discussed'))?></div><div class="item"><span class="label">Link expiry</span><?=$h((string)$link['expires_at'])?></div><div class="item"><span class="label">Remaining UPI uses</span><span id="remaining"><?=$remaining?></span> / 2</div></div><div class="actions"><button id="pay" class="btn" type="button">Open UPI app</button><button id="generate-qr" class="btn secondary" type="button">Generate QR code</button></div><div id="qr" aria-label="Generated UPI payment QR code"></div><p id="status" class="muted" role="status" aria-live="polite">Opening this page does not use a UPI launch. Direct UPI launches and QR generations share the same two-attempt limit.</p>

<section class="panel" aria-labelledby="bank-heading"><h2 id="bank-heading">Bank transfer details</h2>
<?php if(!empty($bank['has_details'])): ?>
<?php foreach($bank['fields'] as $field): ?><div class="bank-row"><div><span class="label"><?=$h((string)$field['label'])?></span><span><?=$h((string)$field['value'])?></span></div><?php if(!empty($field['copyable'])): ?><button class="copy" type="button" data-copy-value="<?=$h((string)$field['value'])?>" aria-label="Copy <?=$h((string)$field['label'])?>">Copy</button><?php endif; ?></div><?php endforeach; ?>
<?php else: ?><p class="muted">Bank transfer details are not currently configured. Please contact Dakshayani Enterprises.</p><?php endif; ?>
<p id="copy-status" class="muted" role="status" aria-live="polite"></p></section>

<section class="panel"><h2>After making payment</h2><p>Opening UPI or completing a bank transfer does not automatically confirm payment. Dakshayani Enterprises will verify the payment and, after verification, finalize the money receipt. This may take some time after you make payment.</p><p>The finalized receipt can then be downloaded from the Customer Dashboard and remains the official payment record.</p></section>

<section class="panel"><h2>Customer Portal</h2><?php if(!empty($portal['available'])): ?><p><?=$h((string)$portal['password_instruction'])?></p><a class="btn secondary" href="<?=$h((string)$portal['login_url'])?>">Open Customer Login</a><?php else: ?><p class="muted"><?=$h((string)$portal['message'])?></p><?php endif; ?>
<p>The Customer Dashboard can provide the following, where available for your project:</p><ul class="capabilities"><li>Quotations</li><li>Accepted quotation details</li><li>Vendor-consumer agreement</li><li>Invoices</li><li>Payment history</li><li>Finalized money receipts</li><li>Delivery challans</li><li>Dispatch advice</li><li>Handover documents</li><li>Active payment requests</li><li>Account and project details</li><li>Complaint submission and complaint history</li></ul></section>
<?php endif; ?></main><?php if($available): ?><script>
document.querySelectorAll('[data-copy-value]').forEach(function(button){button.addEventListener('click',async function(){const status=document.getElementById('copy-status');try{await navigator.clipboard.writeText(this.dataset.copyValue);status.textContent=this.getAttribute('aria-label').replace('Copy ','')+' copied.';}catch(error){status.textContent='Copy was unavailable. Select and copy the value manually.';}});});
async function authorizeUpi(method,button){const buttons=[document.getElementById('pay'),document.getElementById('generate-qr')],status=document.getElementById('status');if(method==='qr'&&typeof QRCode!=='function'){status.textContent='QR generator is still loading. Please try again.';return;}buttons.forEach(function(item){item.disabled=true;});status.textContent=method==='qr'?'Generating secure UPI QR code…':'Authorizing secure UPI launch…';try{const body=new URLSearchParams({token:<?=json_encode($token)?>,method});const response=await fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body,cache:'no-store',referrerPolicy:'no-referrer'});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Payment link unavailable.');document.getElementById('remaining').textContent=data.remaining;if(method==='qr'){const qr=document.getElementById('qr');qr.replaceChildren();qr.style.display='block';new QRCode(qr,{text:data.upi_uri,width:240,height:240,correctLevel:QRCode.CorrectLevel.M});status.textContent='QR code generated. Scan it with a UPI app. This is not proof of payment.';}else{status.textContent='UPI app authorized. This is not proof of payment.';location.href=data.upi_uri;}if(data.remaining>0)buttons.forEach(function(item){item.disabled=false;});}catch(error){status.textContent=error.message;if(document.getElementById('remaining').textContent!=='0')buttons.forEach(function(item){item.disabled=false;});}}
document.getElementById('pay').addEventListener('click',function(){authorizeUpi('direct',this);});document.getElementById('generate-qr').addEventListener('click',function(){authorizeUpi('qr',this);});</script><?php endif; ?></body></html>
