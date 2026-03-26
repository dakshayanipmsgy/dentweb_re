<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/solar_finance_reports.php';

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Invalid payload']);
    exit;
}

$customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
$snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : [];

$name = trim((string) ($customer['name'] ?? ''));
$location = trim((string) ($customer['location'] ?? ''));
$mobile = trim((string) ($customer['mobile'] ?? ''));
$normalizedMobile = solar_finance_normalize_mobile($mobile);

if ($name === '' || $location === '' || $normalizedMobile === null) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Please enter customer name, location and mobile number to generate the report.',
    ]);
    exit;
}

$customerData = [
    'name' => $name,
    'location' => $location,
    'mobile' => $normalizedMobile,
];

try {
    $leadResult = solar_finance_upsert_lead($customerData, $snapshot);
    $report = solar_finance_create_report($customerData, $snapshot);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to generate report right now.']);
    exit;
}

$reportUrl = '/solar-and-finance-report.php?token=' . urlencode((string) $report['public_token']);

echo json_encode([
    'ok' => true,
    'report_id' => $report['report_id'],
    'token' => $report['public_token'],
    'report_url' => $reportUrl,
    'lead' => $leadResult,
]);
