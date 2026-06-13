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

    if (trim((string) ($input['website'] ?? '')) !== '') {
        throw new RuntimeException('Unable to submit request.');
    }
    if (isset($input['consent']) && !in_array((string) $input['consent'], ['on', '1', 'true'], true)) {
        throw new RuntimeException('Consent is required.');
    }
    $rateDir = __DIR__ . '/../../../storage/public-rate-limit';
    if (!is_dir($rateDir)) @mkdir($rateDir, 0775, true);
    $rateKey = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $ratePath = $rateDir . '/' . $rateKey . '.txt';
    $lastRequest = is_file($ratePath) ? (int) file_get_contents($ratePath) : 0;
    if ($lastRequest > time() - 8) throw new RuntimeException('Please wait a moment before submitting again.');

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
        'lead_source' => substr(trim((string) ($input['leadSource'] ?? 'Website Homepage')), 0, 120),
        'email' => substr(trim((string) ($input['email'] ?? '')), 0, 160),
        'area_or_locality' => substr(trim((string) ($input['locality'] ?? '')), 0, 160),
        'monthly_bill' => substr(trim((string) ($input['monthlyBill'] ?? '')), 0, 80),
        'notes' => substr(trim((string) ($input['message'] ?? '')), 0, 1000),
        'status' => 'New',
        'rating' => 'Warm',
    ]);

    @file_put_contents($ratePath, (string) time(), LOCK_EX);
    echo json_encode(['success' => true, 'lead_id' => $lead['id'] ?? '']);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
}
