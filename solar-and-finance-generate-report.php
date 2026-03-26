<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/solar_finance_reports.php';

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode((string) $rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
    exit;
}

$name = trim((string) ($payload['customer']['name'] ?? ''));
$location = trim((string) ($payload['customer']['location'] ?? ''));
$mobile = solar_finance_normalize_mobile((string) ($payload['customer']['mobile_normalized'] ?? ($payload['customer']['mobile_raw'] ?? '')));

if ($name === '' || $location === '' || $mobile === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please enter customer name, location and mobile number to generate the report.']);
    exit;
}

try {
    $payload['customer'] = [
        'name' => $name,
        'location' => $location,
        'mobile_normalized' => $mobile,
    ];

    $report = solar_finance_create_report($payload);
    $leadResult = solar_finance_create_or_update_lead($report);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $reportUrl = sprintf('%s://%s/solar-and-finance-report.php?token=%s', $scheme, $host, urlencode((string) ($report['public_token'] ?? '')));

    echo json_encode([
        'success' => true,
        'report_id' => $report['report_id'] ?? '',
        'report_url' => $reportUrl,
        'lead_action' => $leadResult['action'] ?? 'skipped',
        'lead_id' => $leadResult['lead_id'] ?? '',
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to generate report right now.']);
}
