<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/leads.php';
require_once __DIR__ . '/../../../includes/customer_public.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new RuntimeException('Invalid request.');
    }

    $name = trim((string) ($input['name'] ?? ''));
    $mobile = public_normalize_mobile((string) ($input['phone'] ?? ''));
    $city = trim((string) ($input['city'] ?? ''));
    $projectType = trim((string) ($input['projectType'] ?? ''));
    if ($name === '' || $mobile === '' || $city === '' || $projectType === '') {
        throw new RuntimeException('Please complete every field with a valid phone number.');
    }

    $lead = add_lead([
        'name' => $name,
        'mobile' => $mobile,
        'city' => $city,
        'interest_type' => $projectType,
        'lead_source' => trim((string) ($input['leadSource'] ?? 'Website Homepage')),
        'status' => 'New',
        'rating' => 'Warm',
    ]);

    echo json_encode(['success' => true, 'lead_id' => $lead['id'] ?? '']);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
}
