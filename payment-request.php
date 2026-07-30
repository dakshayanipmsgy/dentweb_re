<?php
declare(strict_types=1);

require_once __DIR__ . '/admin/includes/documents_helpers.php';

header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0', true);
header('Pragma: no-cache', true);
header('Expires: 0', true);
header('Referrer-Policy: no-referrer', true);
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'", true);
header('X-Content-Type-Options: nosniff', true);

$token = trim((string) ($_GET['t'] ?? ''));
$request = documents_payment_request_from_public_token($token);
if (is_array($request)) {
    // Receipt data remains authoritative, but this presentation-only refresh is
    // deliberately not saved.
    $request = documents_payment_request_refresh_from_receipts($request);
}
$available = is_array($request) && documents_payment_request_is_publicly_payable($request);
if (!$available) { http_response_code(404); }

$company = documents_get_company_profile_for_quotes();
$instructions = $available ? documents_payment_instructions($request, $company) : ['upi' => null, 'bank' => []];
$amount = $available ? (float) ($request['amount_requested'] ?? 0) : 0.0;
$safe = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn(float $value): string => '₹' . number_format($value, 2);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive"><title><?= $available ? 'Secure Payment Request' : 'Payment request unavailable' ?></title>
<style>
:root{--ink:#16322d;--muted:#60736f;--teal:#0f766e;--line:#d8e7e3;--soft:#f1faf7}*{box-sizing:border-box}body{margin:0;background:#edf6f3;color:var(--ink);font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{width:min(760px,calc(100% - 28px));margin:28px auto}.brand,.card{background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 12px 34px #174b4020}.brand{padding:24px;background:linear-gradient(135deg,#0b514b,var(--teal));color:#fff}.brand h1{margin:0;font-size:clamp(1.4rem,5vw,2rem)}.brand p{margin:.35rem 0 0}.card{padding:clamp(18px,4vw,30px);margin-top:18px}.amount{font-size:clamp(2rem,9vw,3.4rem);margin:.2rem 0;color:var(--teal)}.meta{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:20px 0}.item,.paybox{padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--soft)}.item span,.label{display:block;color:var(--muted);font-size:.78rem;font-weight:750;text-transform:uppercase;letter-spacing:.05em}.button{display:block;width:100%;padding:14px 18px;border-radius:12px;background:var(--teal);color:#fff;text-align:center;text-decoration:none;font-weight:800;font-size:1.05rem}.paybox{margin-top:14px;background:#fff}.row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid var(--line);overflow-wrap:anywhere}.row:last-child{border:0}.copy{flex:0 0 auto;border:1px solid var(--teal);background:#fff;color:var(--teal);border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer}.notice{color:var(--muted);font-size:.9rem}.error{text-align:center;padding:48px 24px}.toast{position:fixed;bottom:18px;left:50%;translate:-50% 0;background:#102c27;color:#fff;border-radius:999px;padding:9px 16px;opacity:0;transition:.2s;pointer-events:none}.toast.show{opacity:1}@media(max-width:560px){.wrap{margin:14px auto}.meta{grid-template-columns:1fr}.row{align-items:flex-start}.brand,.card{border-radius:14px}}
</style></head><body><main class="wrap">
<header class="brand"><h1><?= $safe(($company['company_name'] ?? '') ?: 'Dakshayani Enterprises') ?></h1><p>Secure payment request</p></header>
<?php if (!$available): ?>
<section class="card error"><h2>Payment request unavailable</h2><p>This link is invalid, expired, or the request can no longer accept payment.</p><p class="notice">Please contact the company if you need an updated payment request.</p></section>
<?php else: ?>
<section class="card"><span class="label">Requested amount</span><h2 class="amount"><?= $safe($money($amount)) ?></h2>
<div class="meta"><div class="item"><span>Reason</span><strong><?= $safe(documents_payment_request_reason_label($request) ?: 'Project payment') ?></strong></div><div class="item"><span>Due date</span><strong><?= $safe(($request['due_date'] ?? '') ?: 'As discussed') ?></strong></div><div class="item"><span>Project reference</span><strong><?= $safe($request['quotation_id'] ?? '') ?></strong></div><div class="item"><span>Request reference</span><strong><?= $safe($request['id'] ?? '') ?></strong></div></div>
<?php if (is_array($instructions['upi'] ?? null)): $upi = $instructions['upi']; ?>
<a class="button" href="<?= $safe($upi['uri']) ?>"><?= $safe($upi['label']) ?></a>
<div class="paybox"><span class="label">UPI payment</span><div class="row"><span>UPI ID: <strong><?= $safe($upi['id']) ?></strong></span><button class="copy" type="button" data-copy="<?= $safe($upi['id']) ?>">Copy</button></div></div>
<?php endif; ?>
<?php if (($instructions['bank'] ?? []) !== []): ?><div class="paybox"><span class="label">Bank transfer details</span><?php foreach ($instructions['bank'] as $field): ?><div class="row"><span><?= $safe($field['label']) ?>: <strong><?= $safe($field['value']) ?></strong></span><button class="copy" type="button" data-copy="<?= $safe($field['value']) ?>">Copy</button></div><?php endforeach; ?></div><?php endif; ?>
<?php if (($instructions['upi'] ?? null) === null && ($instructions['bank'] ?? []) === []): ?><p>Contact the company for payment instructions.</p><?php endif; ?>
<p class="notice">Verify the payee details in your payment app before confirming. This page does not record a payment; an issued receipt is your payment confirmation.</p></section>
<?php endif; ?></main><div id="toast" class="toast" role="status" aria-live="polite">Copied</div>
<script>document.querySelectorAll('[data-copy]').forEach(function(button){button.addEventListener('click',function(){var value=button.getAttribute('data-copy')||'';var done=function(){var toast=document.getElementById('toast');toast.classList.add('show');setTimeout(function(){toast.classList.remove('show')},1400)};if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(value).then(done)}else{var input=document.createElement('textarea');input.value=value;input.setAttribute('readonly','');input.style.position='fixed';input.style.opacity='0';document.body.appendChild(input);input.select();document.execCommand('copy');input.remove();done()}})});</script></body></html>
