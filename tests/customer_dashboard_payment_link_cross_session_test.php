<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$assertions = 0;
function cross_session_ok(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

$requestPath = documents_payment_requests_path();
$vaultPath = documents_payment_request_token_vault_path();
$logPath = documents_logs_dir() . '/documents.log';
$auditPath = dirname(__DIR__) . '/storage/audit-log.jsonl';
$backups = [];
foreach ([$requestPath, $vaultPath, $logPath, $auditPath] as $path) $backups[$path] = is_file($path) ? file_get_contents($path) : null;
register_shutdown_function(static function () use ($backups, $requestPath): void {
    foreach ($backups as $path => $contents) $contents === null ? @unlink($path) : file_put_contents($path, $contents);
    @unlink($requestPath . '.lock');
});

$mobile = ' +91 98765-43210 ';
$normalizedMobile = normalize_customer_mobile($mobile);
$quote = ['id'=>'QUOTE-CROSS-892', 'customer_mobile'=>$mobile];
$request = array_merge(documents_payment_request_defaults(), [
    'id'=>'PAYREQ-CROSS-892', 'quotation_id'=>$quote['id'], 'customer_mobile'=>$normalizedMobile,
    'amount_requested'=>892.37, 'visibility_to_customer'=>true, 'status'=>'draft',
]);
file_put_contents($requestPath, json_encode([$request], JSON_PRETTY_PRINT));
$created = documents_payment_request_generate_upi_link($request['id']);
cross_session_ok(!empty($created['ok']), 'admin can generate the canonical link');
$request = documents_get_payment_request($request['id']);

// Independent PHP/browser sessions have no bearer in $_SESSION; both resolve the vault-backed canonical link.
unset($_SESSION['payment_request_upi_tokens']);
$desktop = documents_customer_payment_request_link_state($request, $quote, $normalizedMobile);
$_SESSION = [];
$mobileSession = documents_customer_payment_request_link_state($request, $quote, $normalizedMobile);
cross_session_ok($desktop['available'] && $mobileSession['available'] && $desktop['url'] === $mobileSession['url'], 'same customer sees one link across independent sessions');
cross_session_ok(str_contains($desktop['url'], rawurlencode($created['token'])), 'canonical persisted bearer is used without deriving it from its hash');

$before = file_get_contents($requestPath);
for ($i = 0; $i < 5; $i++) documents_customer_payment_request_link_state($request, $quote, $normalizedMobile);
cross_session_ok(file_get_contents($requestPath) === $before && (int)documents_get_payment_request($request['id'])['upi_link']['launch_count'] === 0, 'dashboard views and redirects do not consume launches');
$first = documents_payment_request_authorize_upi($created['token']);
$second = documents_payment_request_authorize_upi($created['token']);
$third = documents_payment_request_authorize_upi($created['token']);
cross_session_ok(!empty($first['ok']) && !empty($second['ok']) && empty($third['ok']), 'two launches succeed globally and the third is blocked');
cross_session_ok(str_contains($first['upi_uri'], 'pa=d.entranchi%40ybl') && str_contains($first['upi_uri'], 'am=892.37'), 'payee and exact requested amount are preserved');

$refreshed = documents_payment_request_generate_upi_link($request['id']);
$request = documents_get_payment_request($request['id']);
$refreshedState = documents_customer_payment_request_link_state($request, $quote, $normalizedMobile);
cross_session_ok(empty(documents_payment_request_authorize_upi($created['token'])['ok']) && $refreshedState['available'] && str_contains($refreshedState['url'], $refreshed['token']), 'refresh revokes the old link across sessions');

foreach (['paid', 'cancelled'] as $status) {
    $copy = $request; $copy['status'] = $status; file_put_contents($requestPath, json_encode([$copy]));
    cross_session_ok(!documents_customer_payment_request_link_state($copy, $quote, $normalizedMobile)['available'], "$status request is unavailable");
}
$states = [
    'archived' => ['archived_flag'=>true],
    'exhausted' => ['upi_link'=>array_merge($request['upi_link'], ['launch_count'=>2])],
    'expired' => ['upi_link'=>array_merge($request['upi_link'], ['expires_at'=>date('c', time()-1)])],
];
foreach ($states as $name => $changes) {
    $copy = array_replace($request, $changes); file_put_contents($requestPath, json_encode([$copy]));
    cross_session_ok(!documents_customer_payment_request_link_state($copy, $quote, $normalizedMobile)['available'], "$name request is unavailable");
}
file_put_contents($requestPath, json_encode([$request]));
cross_session_ok(!documents_customer_payment_request_link_state($request, ['id'=>'OTHER','customer_mobile'=>$mobile], $normalizedMobile)['available'], 'quotation ownership is isolated');
cross_session_ok(!documents_customer_payment_request_link_state($request, $quote, '9000000000')['available'], 'customer mobile ownership is isolated');
$hidden = $request; $hidden['visibility_to_customer'] = false; file_put_contents($requestPath, json_encode([$hidden]));
cross_session_ok(!documents_customer_payment_request_link_state($hidden, $quote, $normalizedMobile)['available'], 'customer visibility is enforced');

$logs = (string)@file_get_contents($logPath) . (string)@file_get_contents($auditPath);
cross_session_ok(!str_contains($logs, $created['token']) && !str_contains($logs, $refreshed['token']), 'raw bearers are never logged');
$dashboardSource = file_get_contents(__DIR__ . '/../customer-dashboard.php');
cross_session_ok(!str_contains($dashboardSource, 'payment_request_upi_tokens') && str_contains($dashboardSource, 'documents_customer_payment_request_link_state'), 'dashboard has no PHP-session token dependency');

echo "customer_dashboard_payment_link_cross_session_test passed ($assertions assertions)\n";
