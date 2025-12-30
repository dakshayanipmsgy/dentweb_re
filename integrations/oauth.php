<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ai_gemini.php';
require_once __DIR__ . '/../includes/smart_marketing.php';

require_admin();
$admin = current_user();
$platform = (string) ($_GET['platform'] ?? '');
$state = (string) ($_GET['state'] ?? '');
$errorReason = (string) ($_GET['error'] ?? '');
$status = 'error';
$message = '';
$data = [];

try {
    if ($platform === '') {
        throw new RuntimeException('Missing integration platform.');
    }
    if ($state === '') {
        throw new RuntimeException('Missing OAuth state parameter.');
    }
    $stateMeta = smart_marketing_oauth_consume_state($platform, $state);
    if ($stateMeta === null) {
        throw new RuntimeException('OAuth session expired. Start the connection again.');
    }
    if ($errorReason !== '') {
        throw new RuntimeException(sprintf('%s authorization failed: %s', smart_marketing_integration_label($platform), $errorReason));
    }
    $code = (string) ($_GET['code'] ?? '');
    if ($code === '') {
        throw new RuntimeException('Authorization code not returned by provider.');
    }

    $entry = smart_marketing_connector_complete_authorization($platform, $code, $admin);
    $status = 'success';
    $message = sprintf('%s connected. Return to Smart Marketing to pick accounts.', smart_marketing_integration_label($platform));
    $data = [
        'status' => $entry['status'] ?? 'connected',
        'message' => $entry['message'] ?? $message,
    ];
} catch (Throwable $exception) {
    $message = $exception->getMessage();
}
smart_marketing_flash($status, $message ?: 'OAuth flow completed.', [
    'platform' => $platform,
    'data' => $data,
]);

header('Location: ' . smart_marketing_absolute_url('admin-smart-marketing.php'));
exit;
