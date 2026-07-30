<?php
declare(strict_types=1);

require_once __DIR__ . '/admin/includes/documents_helpers.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');

session_name('dentweb_payment');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);
session_start();
$now=time(); $attempts=array_values(array_filter((array)($_SESSION['payment_link_attempts']??[]),static fn($t):bool=>(int)$t>$now-60));
if (count($attempts)>=30) { http_response_code(429); exit('Too many requests. Please try again shortly.'); }
$attempts[]=$now; $_SESSION['payment_link_attempts']=$attempts;

$token=trim((string)($_GET['token']??$_POST['token']??''));
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$sessionKey=session_id();
$context=['method'=>$method,'user_agent'=>(string)($_SERVER['HTTP_USER_AGENT']??'')];
if ($method==='POST') {
    $nonce=(string)($_POST['launch_nonce']??'');
    if ($nonce==='' || !hash_equals((string)($_SESSION['payment_launch_nonce']??''),$nonce)) { http_response_code(403); exit('Invalid payment action.'); }
    unset($_SESSION['payment_launch_nonce']);
    $resolved=documents_resolve_payment_request_link($token,'launch',$sessionKey,$context);
    if (!empty($resolved['ok'])) { header('Location: '.documents_payment_request_upi_uri((array)$resolved['request']),true,303); exit; }
} else {
    $resolved=documents_resolve_payment_request_link($token,'access',$sessionKey,$context);
}
$ok=!empty($resolved['ok']);
if (!$ok) http_response_code(410);
$request=$ok?(array)$resolved['request']:[];
$nonce=bin2hex(random_bytes(24)); $_SESSION['payment_launch_nonce']=$nonce;
$h=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$amount=number_format((float)($request['outstanding_against_request']??0),2);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive,nosnippet"><title>Secure payment request</title><style>body{margin:0;background:#eef7f5;color:#173b35;font:16px/1.5 Arial,sans-serif}.wrap{max-width:680px;margin:40px auto;padding:16px}.card{background:#fff;border:1px solid #cfe2dd;border-radius:18px;padding:28px;box-shadow:0 16px 45px #173b3514}h1{margin-top:0}.row{border-top:1px solid #e3eeeb;padding:12px 0}.muted{color:#60756f}.amount{font-size:2rem;color:#087b61}.btn{width:100%;border:0;border-radius:12px;background:#087b61;color:#fff;font-size:1.05rem;font-weight:700;padding:15px;cursor:pointer}.error{color:#991b1b}</style></head><body><main class="wrap"><section class="card"><?php if(!$ok):?><h1>Payment link unavailable</h1><p class="error"><?= $h($resolved['error']??'This link cannot be used.') ?></p><p class="muted">Please contact Dakshayani Enterprises for a fresh payment link.</p><?php else:?><p class="muted">Dakshayani Enterprises · Secure UPI payment</p><h1>Payment Request <?= $h($request['id']??'') ?></h1><div class="row"><strong>Quotation reference</strong><br><?= $h($request['quotation_id']??'') ?></div><div class="row"><strong>Reason</strong><br><?= $h(documents_payment_request_reason_label($request)) ?></div><div class="row"><strong>Due date</strong><br><?= $h(($request['due_date']??'')?:'As discussed') ?></div><div class="row"><strong>Exact payable amount</strong><br><span class="amount">₹<?= $h($amount) ?></span></div><form method="post"><input type="hidden" name="token" value="<?= $h($token) ?>"><input type="hidden" name="launch_nonce" value="<?= $h($nonce) ?>"><button class="btn" type="submit">Pay ₹<?= $h($amount) ?> by UPI</button></form><p class="muted">Opening UPI does not record a payment. Your receipt remains the payment record.</p><?php endif;?></section></main></body></html>
