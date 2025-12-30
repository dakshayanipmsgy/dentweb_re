<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    ensure_api_access('employee');
} catch (Throwable $exception) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['error' => $exception->getMessage()]);
    exit;
}

$db = get_db();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$actor = current_user();
$actorId = (int) ($actor['id'] ?? 0);

if ($actor['status'] !== 'active') {
    throw new RuntimeException('Your account is not active. Please contact an administrator.');
}

try {
    switch ($action) {
        case 'bootstrap':
            require_method('GET');
            respond_success(employee_bootstrap_payload($db, $actorId));
            break;
        case 'update-task-status':
            require_method('POST');
            $payload = read_json();
            $taskId = (int) ($payload['id'] ?? 0);
            $status = (string) ($payload['status'] ?? '');
            $task = portal_update_task_status($db, $taskId, $status, $actorId);
            respond_success(['task' => $task]);
            break;
        case 'mark-notification-read':
            require_method('POST');
            $payload = read_json();
            $notificationId = (int) ($payload['id'] ?? 0);
            portal_mark_notification($db, $notificationId, $actorId, 'read');
            respond_success([]);
            break;
        case 'update-complaint-status':
            require_method('POST');
            $payload = read_json();
            $reference = (string) ($payload['reference'] ?? '');
            $status = (string) ($payload['status'] ?? '');
            enforce_complaint_access($db, $reference, $actorId);
            $complaint = portal_update_complaint_status($db, $reference, $status, $actorId);
            respond_success(['complaint' => $complaint]);
            break;
        case 'add-complaint-note':
            require_method('POST');
            $payload = read_json();
            $reference = (string) ($payload['reference'] ?? '');
            $note = (string) ($payload['note'] ?? '');
            enforce_complaint_access($db, $reference, $actorId);
            $complaint = portal_add_complaint_note($db, $reference, $note, $actorId, 'internal');
            respond_success(['complaint' => $complaint]);
            break;
        case 'upload-complaint-media':
            require_method('POST');
            $payload = read_json();
            $complaint = employee_upload_complaint_media($db, $actorId, $payload);
            respond_success(['complaint' => $complaint]);
            break;
        case 'create-lead':
            require_method('POST');
            $payload = read_json();
            $lead = employee_create_lead($db, $payload, $actorId);
            respond_success(['lead' => $lead]);
            break;
        case 'add-lead-visit':
            require_method('POST');
            $payload = read_json();
            $lead = employee_add_lead_visit($db, $payload, $actorId);
            respond_success(['lead' => $lead]);
            break;
        case 'progress-lead':
            require_method('POST');
            $payload = read_json();
            $leadId = (int) ($payload['lead_id'] ?? 0);
            $stage = (string) ($payload['stage'] ?? '');
            $lead = employee_progress_lead($db, $leadId, $stage, $actorId);
            respond_success(['lead' => $lead]);
            break;
        case 'update-lead':
            require_method('POST');
            $payload = read_json();
            $leadId = (int) ($payload['lead_id'] ?? 0);
            $lead = employee_update_lead($db, $leadId, $payload, $actorId);
            respond_success(['lead' => $lead]);
            break;
        case 'update-installation-stage':
            require_method('POST');
            $payload = read_json();
            $installationId = (int) ($payload['installation_id'] ?? 0);
            $stage = (string) ($payload['stage'] ?? '');
            enforce_installation_access($db, $installationId, $actorId);
            $installation = installation_update_stage($db, $installationId, $stage, $actorId, 'employee');
            respond_success(['installation' => $installation]);
            break;
        case 'upload-installation-photo':
            require_method('POST');
            $payload = read_json();
            $installation = employee_upload_installation_photo($db, $actorId, $payload);
            respond_success(['installation' => $installation]);
            break;
        case 'add-installation-note':
            require_method('POST');
            $payload = read_json();
            $installationId = (int) ($payload['installation_id'] ?? 0);
            $note = (string) ($payload['note'] ?? '');
            $installation = employee_add_installation_note($db, $installationId, $note, $actorId);
            respond_success(['installation' => $installation]);
            break;
        case 'generate-installation-completion-note':
            require_method('POST');
            $payload = read_json();
            $installationId = (int) ($payload['installation_id'] ?? 0);
            $installation = employee_generate_installation_completion_note($db, $installationId, $actorId);
            respond_success(['installation' => $installation]);
            break;
        case 'update-profile':
            require_method('POST');
            $payload = read_json();
            $user = employee_update_profile($db, $actorId, $payload);
            respond_success(['user' => $user]);
            break;
        case 'propose-reminder':
            require_method('POST');
            $payload = read_json();
            $reminder = employee_propose_reminder($db, $payload, $actorId);
            respond_success(['reminder' => $reminder]);
            break;
        case 'cancel-reminder':
            require_method('POST');
            $payload = read_json();
            $reminderId = (int) ($payload['reminder_id'] ?? 0);
            $reminder = employee_cancel_reminder($db, $reminderId, $actorId);
            respond_success(['reminder' => $reminder]);
            break;
        case 'complete-reminder':
            require_method('POST');
            $payload = read_json();
            $reminderId = (int) ($payload['reminder_id'] ?? 0);
            $reminder = employee_complete_reminder($db, $reminderId, $actorId);
            respond_success(['reminder' => $reminder]);
            break;
        case 'edit-reminder':
            require_method('POST');
            $payload = read_json();
            $reminderId = (int) ($payload['reminder_id'] ?? 0);
            $reminder = employee_edit_reminder($db, $reminderId, $payload, $actorId);
            respond_success(['reminder' => $reminder]);
            break;
        case 'submit-request':
            require_method('POST');
            $payload = read_json();
            $type = (string) ($payload['type'] ?? '');
            $request = employee_submit_request($db, $actorId, $type, $payload);
            respond_success(['request' => $request]);
            break;
        default:
            throw new RuntimeException('Unknown action: ' . $action);
    }
} catch (Throwable $exception) {
    http_response_code(400);
    respond_error($exception->getMessage());
}

function require_method(string $expected): void
{
    if (strcasecmp($_SERVER['REQUEST_METHOD'], $expected) !== 0) {
        throw new RuntimeException('Invalid request method.');
    }
}

function read_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON payload.');
    }

    return $data;
}

function respond_success(array $data): void
{
    echo json_encode(['success' => true, 'data' => $data]);
}

function respond_error(string $message): void
{
    echo json_encode(['success' => false, 'error' => $message]);
}
