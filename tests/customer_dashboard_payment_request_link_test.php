<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$assertions = 0;
function dashboard_link_ok(bool $condition, string $message): void { global $assertions; $assertions++; if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$path = documents_payment_requests_path();
$backup = is_file($path) ? file_get_contents($path) : null;
$receiptPath = documents_sales_receipts_store_path();
$receiptBackup = is_file($receiptPath) ? file_get_contents($receiptPath) : null;
$auditPath = dirname(__DIR__) . '/storage/audit-log.jsonl';
$auditBackup = is_file($auditPath) ? file_get_contents($auditPath) : null;
register_shutdown_function(static function () use ($path, $backup, $receiptPath, $receiptBackup, $auditPath, $auditBackup): void {
    $backup === null ? @unlink($path) : file_put_contents($path, $backup);
    $receiptBackup === null ? @unlink($receiptPath) : file_put_contents($receiptPath, $receiptBackup);
    $auditBackup === null ? @unlink($auditPath) : file_put_contents($auditPath, $auditBackup);
    @unlink($path . '.lock');
});

$mobile = '9876543210';
$quote = ['id'=>'QUOTE-DASH-890', 'customer_mobile'=>$mobile];
$request = array_merge(documents_payment_request_defaults(), [
    'id'=>'PAYREQ-DASH-890', 'quotation_id'=>$quote['id'], 'customer_mobile'=>$mobile,
    'amount_requested'=>890.00, 'visibility_to_customer'=>true, 'status'=>'draft',
    'internal_notes'=>'MUST NOT APPEAR'
]);
file_put_contents($path, json_encode([$request], JSON_PRETTY_PRINT));
$created = documents_payment_request_generate_upi_link($request['id']);
$request = documents_get_payment_request($request['id']);
$url = documents_customer_payment_request_public_url($request, $quote, $mobile, $created['token']);
dashboard_link_ok(str_contains($url, '/payment-request.php?token=') && str_contains($url, rawurlencode($created['token'])), 'valid request uses canonical tokenized public URL');

$serverBackup = $_SERVER;
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'example.test';
foreach ([
    '/admin-documents.php' => '/payment-request.php',
    '/customer-dashboard.php' => '/payment-request.php',
    '/app/admin-documents.php' => '/app/payment-request.php',
    '/app/customer-dashboard.php' => '/app/payment-request.php',
    '/' => '/payment-request.php',
    'customer-dashboard.php' => '/payment-request.php',
] as $scriptName => $expectedPath) {
    $_SERVER['SCRIPT_NAME'] = $scriptName;
    $routeUrl = documents_payment_request_public_url('route token');
    dashboard_link_ok(
        $routeUrl === 'https://example.test' . $expectedPath . '?token=route%20token',
        "$scriptName resolves the dedicated payment-request route"
    );
    dashboard_link_ok(
        !str_contains($routeUrl, 'customer-dashboard.php/payment-request.php')
            && !str_contains($routeUrl, 'admin-documents.php/payment-request.php'),
        "$scriptName never appends the payment route to a PHP filename"
    );
}
$_SERVER = $serverBackup;
dashboard_link_ok(documents_customer_payment_request_public_url($request, $quote, $mobile, '') === '', 'missing bearer is unavailable');

$expired = $request; $expired['upi_link']['expires_at'] = date('c', time()-1);
dashboard_link_ok(documents_customer_payment_request_public_url($expired, $quote, $mobile, $created['token']) === '', 'expired link is unavailable');
$exhausted = $request; $exhausted['upi_link']['launch_count'] = 2;
dashboard_link_ok(documents_customer_payment_request_public_url($exhausted, $quote, $mobile, $created['token']) === '', 'exhausted link is unavailable');
foreach (['paid','cancelled'] as $status) { $copy=$request; $copy['status']=$status; dashboard_link_ok(documents_customer_payment_request_public_url($copy,$quote,$mobile,$created['token'])==='', "$status request is unavailable"); }
$archived=$request; $archived['archived_flag']=true;
dashboard_link_ok(documents_customer_payment_request_public_url($archived,$quote,$mobile,$created['token'])==='', 'archived request is unavailable');
$internal=$request; $internal['visibility_to_customer']=false;
dashboard_link_ok(documents_customer_payment_request_public_url($internal,$quote,$mobile,$created['token'])==='', 'internal-only request is unavailable');
dashboard_link_ok(documents_customer_payment_request_public_url($request,['id'=>'OTHER','customer_mobile'=>$mobile],$mobile,$created['token'])==='', 'exact quotation is enforced');
dashboard_link_ok(documents_customer_payment_request_public_url($request,$quote,'9000000000',$created['token'])==='', 'logged-in customer ownership is enforced');
dashboard_link_ok(documents_customer_payment_request_public_url($request,['id'=>$quote['id'],'customer_mobile'=>'9000000000'],$mobile,$created['token'])==='', 'quotation ownership isolation is enforced');

$paidBefore = (float)$request['amount_paid_against_request']; $outstandingBefore = (float)$request['outstanding_against_request'];
$before = file_get_contents($path); $receiptBefore = is_file($receiptPath) ? file_get_contents($receiptPath) : null;
for ($i=0; $i<3; $i++) documents_customer_payment_request_public_url($request,$quote,$mobile,$created['token']);
$afterRefresh = documents_get_payment_request($request['id']);
dashboard_link_ok((int)$afterRefresh['upi_link']['launch_count']===0 && file_get_contents($path)===$before, 'dashboard GET/refresh does not consume or mutate link');
dashboard_link_ok((is_file($receiptPath) ? file_get_contents($receiptPath) : null)===$receiptBefore, 'dashboard lookup creates no receipt');
dashboard_link_ok((float)$afterRefresh['amount_paid_against_request']===$paidBefore && (float)$afterRefresh['outstanding_against_request']===$outstandingBefore, 'dashboard lookup does not mark paid or change outstanding');

$first=documents_payment_request_authorize_upi($created['token']); $second=documents_payment_request_authorize_upi($created['token']); $third=documents_payment_request_authorize_upi($created['token']);
dashboard_link_ok(!empty($first['ok']) && !empty($second['ok']) && empty($third['ok']), 'existing two-use enforcement is preserved');
$refreshed=documents_payment_request_generate_upi_link($request['id']);
$request=documents_get_payment_request($request['id']);
dashboard_link_ok(documents_customer_payment_request_public_url($request,$quote,$mobile,$created['token'])==='', 'refreshed/revoked token is unavailable');
dashboard_link_ok(documents_customer_payment_request_public_url($request,$quote,$mobile,$refreshed['token'])!=='', 'refreshed current token is available');

$dashboardSource=file_get_contents(__DIR__.'/../customer-dashboard.php');
dashboard_link_ok(str_contains($dashboardSource,'Payment link not available') && str_contains($dashboardSource,'Pay via UPI'), 'dashboard renders actionable and safe fallback states');
dashboard_link_ok(str_contains($dashboardSource, "href=\"<?= customer_portal_safe(\$paymentLink['url']) ?>\"") && str_contains($dashboardSource, 'target="_blank"'), 'dashboard action opens the dedicated public URL in a new tab');
dashboard_link_ok(!str_contains(substr($dashboardSource, strpos($dashboardSource,'Active payment requests'), 2500), 'internal_notes'), 'payment table exposes no internal notes');
echo "customer_dashboard_payment_request_link_test passed ($assertions assertions)\n";
