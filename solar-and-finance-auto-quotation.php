<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/solar_finance_reports.php';

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid payload.');
    }

    $result = create_or_update_solar_finance_quote($payload);
    if (!($result['success'] ?? false)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => (string) ($result['message'] ?? 'Unable to auto-create quotation.'),
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'action' => (string) ($result['action'] ?? 'updated'),
        'quote_id' => (string) ($result['quote_id'] ?? ''),
        'quote_no' => (string) ($result['quote_no'] ?? ''),
        'scenario' => (string) ($result['scenario'] ?? ''),
        'quote_view_url' => (string) ($result['quote_view_url'] ?? ''),
        'message' => (string) ($result['message'] ?? ''),
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to auto-create quotation.',
    ]);
}
