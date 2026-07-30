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
    $result = documents_payment_request_authorize_upi($token);
    http_response_code(!empty($result['ok']) ? 200 : 409);
    echo json_encode($result, JSON_UNESCAPED_SLASHES); exit;
}

$request = documents_payment_request_find_by_token($token);
$available = $request !== null && documents_payment_request_link_available($request);
$link = is_array($request['upi_link'] ?? null) ? $request['upi_link'] : [];
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$amount = $request ? '₹' . number_format((float)$request['amount_requested'], 2) : '—';
$remaining = $available ? max(0, 2-(int)($link['launch_count']??0)) : 0;
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>Payment Request</title><style>
*{box-sizing:border-box}body{margin:0;background:#eef6f4;color:#153b36;font:16px/1.5 system-ui,sans-serif}.card{max-width:620px;margin:6vh auto;background:#fff;border:1px solid #d8e8e4;border-radius:20px;padding:30px;box-shadow:0 18px 55px #174b4020}h1{margin-top:0}.amount{font-size:2.3rem;font-weight:800;color:#0f766e}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:22px 0}.item{padding:12px;background:#f5faf9;border-radius:10px}.label{display:block;font-size:.75rem;text-transform:uppercase;color:#64748b;font-weight:700}.btn{display:block;width:100%;border:0;border-radius:12px;padding:14px;background:#0f766e;color:white;font-size:1rem;font-weight:800;cursor:pointer}.btn:disabled{background:#94a3b8}.notice{padding:14px;border-radius:10px;background:#fff7ed;color:#9a3412}.muted{color:#64748b;font-size:.9rem}@media(max-width:640px){.card{margin:0;min-height:100vh;border-radius:0}.grid{grid-template-columns:1fr}}
</style></head><body><main class="card"><h1>Dakshayani Enterprises</h1><?php if(!$available): ?><div class="notice"><strong>Payment link unavailable</strong><br>This link is invalid, expired, exhausted, refreshed, cancelled, archived, or already paid. Please ask Dakshayani Enterprises for a refreshed link.</div><?php else: ?><span class="label">Amount requested</span><div class="amount"><?=$h($amount)?></div><div class="grid"><div class="item"><span class="label">Request reference</span><?=$h((string)$request['id'])?></div><div class="item"><span class="label">Quotation reference</span><?=$h((string)$request['quotation_id'])?></div><div class="item"><span class="label">Reason</span><?=$h(documents_payment_request_reason_label($request))?></div><div class="item"><span class="label">Due date</span><?=$h((string)($request['due_date']?:'As discussed'))?></div><div class="item"><span class="label">Link expiry</span><?=$h((string)$link['expires_at'])?></div><div class="item"><span class="label">Remaining UPI launches</span><span id="remaining"><?=$remaining?></span> / 2</div></div><button id="pay" class="btn" type="button">Pay via UPI</button><p id="status" class="muted">Opening this page does not use a UPI launch. A launch is used only after you press the button.</p><?php endif; ?></main><?php if($available): ?><script>
document.getElementById('pay').addEventListener('click',async function(){this.disabled=true;const status=document.getElementById('status');status.textContent='Authorizing secure UPI launch…';try{const body=new URLSearchParams({token:<?=json_encode($token)?>});const response=await fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body,cache:'no-store',referrerPolicy:'no-referrer'});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Payment link unavailable.');document.getElementById('remaining').textContent=data.remaining;status.textContent='UPI app authorized. This is not proof of payment.';location.href=data.upi_uri;if(data.remaining>0)this.disabled=false;}catch(error){status.textContent=error.message;}}
);</script><?php endif; ?></body></html>
