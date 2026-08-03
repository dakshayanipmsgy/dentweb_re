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
    ensure_api_access('admin');
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

try {
    switch ($action) {
        case 'bootstrap':
            require_method('GET');
            respond_success(bootstrap_payload($db));
            break;
        case 'dashboard-data':
            require_method('GET');
            respond_success([
                'counts' => admin_overview_counts($db),
                'highlights' => admin_today_highlights($db, 20),
            ]);
            break;
        case 'list-employees':
            require_method('GET');
            respond_success(admin_list_accounts($db, ['role' => 'employee', 'status' => 'active']));
            break;
        case 'create-user':
            require_method('POST');
            respond_success(create_user($db, read_json()));
            break;
        case 'update-user-status':
            require_method('POST');
            respond_success(update_user_status($db, read_json()));
            break;
        case 'invite-user':
            require_method('POST');
            respond_success(create_invitation($db, read_json()));
            break;
        case 'approve-invite':
            require_method('POST');
            respond_success(approve_invitation($db, read_json()));
            break;
        case 'reject-invite':
            require_method('POST');
            respond_success(reject_invitation($db, read_json()));
            break;
        case 'change-password':
            require_method('POST');
            respond_success(change_password($db, read_json()));
            break;
        case 'update-login-policy':
            require_method('POST');
            respond_success(update_login_policy($db, read_json()));
            break;
        case 'save-task':
            require_method('POST');
            respond_success(['task' => portal_save_task($db, read_json(), $actorId), 'tasks' => portal_list_tasks($db)]);
            break;
        case 'update-task-status':
            require_method('POST');
            $payload = read_json();
            $taskId = (int) ($payload['id'] ?? 0);
            if ($taskId <= 0) {
                throw new RuntimeException('Task ID is required.');
            }
            $status = (string) ($payload['status'] ?? '');
            respond_success(['task' => portal_update_task_status($db, $taskId, $status, $actorId), 'tasks' => portal_list_tasks($db)]);
            break;
        case 'save-document':
            require_method('POST');
            respond_success(['document' => portal_save_document($db, read_json(), $actorId), 'documents' => portal_list_documents($db, 'admin')]);
            break;
        case 'save-complaint':
            require_method('POST');
            $complaint = portal_save_complaint($db, read_json(), $actorId);
            respond_success([
                'complaint' => $complaint,
                'complaints' => portal_all_complaints($db),
                'metrics' => current_metrics($db),
            ]);
            break;
        case 'assign-complaint':
            require_method('POST');
            $payload = read_json();
            $reference = (string) ($payload['reference'] ?? '');
            $assigneeId = isset($payload['assigneeId']) && $payload['assigneeId'] !== '' ? (int) $payload['assigneeId'] : null;
            $slaDue = (string) ($payload['slaDue'] ?? '');
            $complaint = portal_assign_complaint($db, $reference, $assigneeId, $slaDue !== '' ? $slaDue : null, $actorId);
            respond_success([
                'complaint' => $complaint,
                'complaints' => portal_all_complaints($db),
                'metrics' => current_metrics($db),
            ]);
            break;
        case 'add-complaint-note':
            require_method('POST');
            $payload = read_json();
            $reference = (string) ($payload['reference'] ?? '');
            $note = (string) ($payload['note'] ?? '');
            $visibility = (string) ($payload['visibility'] ?? 'internal');
            $complaint = portal_add_complaint_note($db, $reference, $note, $actorId, $visibility);
            respond_success([
                'complaint' => $complaint,
                'complaints' => portal_all_complaints($db),
                'metrics' => current_metrics($db),
            ]);
            break;
        case 'add-complaint-attachment':
            require_method('POST');
            $payload = read_json();
            $reference = (string) ($payload['reference'] ?? '');
            $attachment = $payload['attachment'] ?? [];
            if (!is_array($attachment)) {
                throw new RuntimeException('Invalid attachment payload.');
            }
            $complaint = portal_add_complaint_attachment($db, $reference, $attachment, $actorId);
            respond_success([
                'complaint' => $complaint,
                'complaints' => portal_all_complaints($db),
                'metrics' => current_metrics($db),
            ]);
            break;
        case 'update-complaint-status':
            require_method('POST');
            $payload = read_json();
            $reference = (string) ($payload['reference'] ?? '');
            $status = (string) ($payload['status'] ?? '');
            $complaint = portal_admin_update_complaint_status($db, $reference, $status, $actorId);
            respond_success([
                'complaint' => $complaint,
                'complaints' => portal_all_complaints($db),
                'metrics' => current_metrics($db),
            ]);
            break;
        case 'complaint-timeline':
            require_method('GET');
            respond_success([
                'timeline' => fetch_complaint_timeline($db, $_GET),
                'metrics' => current_metrics($db),
            ]);
            break;
        case 'fetch-audit':
            require_method('GET');
            respond_success([
                'audit' => recent_audit_logs($db),
                'metrics' => current_metrics($db),
            ]);
            break;
        case 'fetch-complaints':
            require_method('GET');
            respond_success([
                'complaints' => portal_all_complaints($db),
                'metrics' => current_metrics($db),
            ]);
            break;
        case 'fetch-metrics':
            require_method('GET');
            respond_success(current_metrics($db));
            break;
        case 'list-blog-posts':
            require_method('GET');
            respond_success(['posts' => blog_admin_list($db)]);
            break;
        case 'get-blog-post':
            require_method('GET');
            respond_success(['post' => get_blog_post_for_admin($db, $_GET)]);
            break;
        case 'save-blog-post':
            require_method('POST');
            respond_success(['post' => blog_save_post($db, read_json(), $actorId)]);
            break;
        case 'publish-blog-post':
            require_method('POST');
            $payload = read_json();
            $postId = (int) ($payload['id'] ?? 0);
            if ($postId <= 0) {
                throw new RuntimeException('Post ID is required.');
            }
            $publish = !empty($payload['publish']);
            respond_success(['post' => blog_publish_post($db, $postId, $publish, $actorId)]);
            break;
        case 'archive-blog-post':
            require_method('POST');
            $payload = read_json();
            $postId = (int) ($payload['id'] ?? 0);
            if ($postId <= 0) {
                throw new RuntimeException('Post ID is required.');
            }
            respond_success(['post' => blog_archive_post($db, $postId, $actorId)]);
            break;
        case 'delete-customer':
            require_method('POST');
            $payload = read_json();
            $customerId = (int) ($payload['id'] ?? 0);
            if ($customerId <= 0) {
                throw new RuntimeException('Customer ID is required.');
            }
            $store = customer_record_store();
            $result = $store->delete($customerId);
            audit('delete_customer', 'customer', $customerId, 'Customer record permanently deleted.');
            respond_success($result);
            break;
        case 'deactivate-customer':
            require_method('POST');
            $payload = read_json();
            $customerId = (int) ($payload['id'] ?? 0);
            if ($customerId <= 0) {
                throw new RuntimeException('Customer ID is required.');
            }
            $store = customer_record_store();
            $customer = $store->deactivate($customerId);
            audit('deactivate_customer', 'customer', $customerId, 'Customer record deactivated.');
            respond_success(['customer' => $customer]);
            break;
        case 'reactivate-customer':
            require_method('POST');
            $payload = read_json();
            $customerId = (int) ($payload['id'] ?? 0);
            if ($customerId <= 0) {
                throw new RuntimeException('Customer ID is required.');
            }
            $store = customer_record_store();
            $customer = $store->reactivate($customerId);
            audit('reactivate_customer', 'customer', $customerId, 'Customer record reactivated.');
            respond_success(['customer' => $customer]);
            break;
        case 'change-customer-state':
            require_method('POST');
            $payload = read_json();
            $customerId = (int) ($payload['id'] ?? 0);
            if ($customerId <= 0) {
                throw new RuntimeException('Customer ID is required.');
            }
            $targetState = (string) ($payload['state'] ?? '');

            $store = customer_record_store();
            $existing = $store->find($customerId);
            $previousState = is_array($existing) ? (string) ($existing['state'] ?? '') : '';

            // The CustomerRecordStore::changeState method handles the full payload,
            // including state-specific fields like assigned_employee_id, system_type,
            // system_kwp, and handover_date. It also contains validation logic.
            $customer = $store->changeState($customerId, $targetState, $payload);

            $currentState = (string) ($customer['state'] ?? $targetState);
            $stateLabel = ucfirst($currentState);
            $customerName = trim((string) ($customer['full_name'] ?? 'Customer #' . $customerId));
            if ($customerName === '') {
                $customerName = 'Customer #' . $customerId;
            }

            $message = sprintf('%s state updated to %s.', $customerName, strtolower($stateLabel));
            if ($currentState === CustomerRecordStore::STATE_ONGOING) {
                $message = sprintf('%s moved to ongoing. Assigned details saved.', $customerName);
            } elseif ($currentState === CustomerRecordStore::STATE_INSTALLED) {
                $message = sprintf('%s marked as installed. Complaints enabled.', $customerName);
            }

            $summary = $store->stateSummary();

            $assignmentNote = '';
            if ($currentState === CustomerRecordStore::STATE_ONGOING) {
                $employeeId = isset($customer['assigned_employee_id']) ? (int) $customer['assigned_employee_id'] : 0;
                if ($employeeId > 0) {
                    $assignmentNote = sprintf('Employee #%d assigned', $employeeId);
                }
            }

            $handoverNote = '';
            if ($currentState === CustomerRecordStore::STATE_INSTALLED) {
                $handover = (string) ($customer['handover_date'] ?? '');
                if ($handover !== '') {
                    $handoverNote = 'Handover on ' . $handover;
                }
            }

            $auditParts = [];
            $auditParts[] = sprintf('State changed from %s to %s',
                $previousState !== '' ? ucfirst($previousState) : 'Unknown',
                $stateLabel
            );
            if ($assignmentNote !== '') {
                $auditParts[] = $assignmentNote;
            }
            if ($handoverNote !== '') {
                $auditParts[] = $handoverNote;
            }

            $auditDescription = implode('; ', array_filter($auditParts));
            audit('change_customer_state', 'customer', $customerId, $auditDescription);

            $activityTimeRaw = (string) ($customer['last_state_change_at'] ?? '');
            try {
                $activityTime = new DateTimeImmutable($activityTimeRaw !== '' ? $activityTimeRaw : 'now', new DateTimeZone('Asia/Kolkata'));
            } catch (Throwable $exception) {
                $activityTime = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
            }

            $activity = [
                'moduleKey' => $currentState === CustomerRecordStore::STATE_ONGOING ? 'installations' : 'installations',
                'moduleLabel' => 'Installations',
                'icon' => $currentState === CustomerRecordStore::STATE_INSTALLED ? 'fa-circle-check' : 'fa-solar-panel',
                'summary' => $currentState === CustomerRecordStore::STATE_INSTALLED
                    ? sprintf('%s marked as installed', $customerName)
                    : sprintf('%s moved to ongoing', $customerName),
                'isoTime' => $activityTime->format(DateTimeInterface::ATOM),
                'timeDisplay' => $activityTime->format('d M · h:i A'),
            ];

            respond_success([
                'customer' => $customer,
                'message' => $message,
                'summary' => [
                    'states' => $summary,
                    'previous_state' => $previousState,
                    'current_state' => $currentState,
                ],
                'activity' => $activity,
            ]);
            break;
        case 'bulk-update-customers':
            require_method('POST');
            $payload = read_json();
            $customerIds = $payload['customer_ids'] ?? [];
            if (empty($customerIds)) {
                throw new RuntimeException('Select at least one customer to update.');
            }
            $action = $payload['bulk_action'] ?? '';
            $store = customer_record_store();
            $results = [];
            foreach ($customerIds as $customerId) {
                try {
                    switch ($action) {
                        case 'delete':
                            $results[] = $store->delete($customerId);
                            audit('bulk_delete_customer', 'customer', $customerId, 'Customer record permanently deleted in bulk action.');
                            break;
                        case 'deactivate':
                            $results[] = $store->deactivate($customerId);
                            audit('bulk_deactivate_customer', 'customer', $customerId, 'Customer record deactivated in bulk action.');
                            break;
                        case 'reactivate':
                            $results[] = $store->reactivate($customerId);
                            audit('bulk_reactivate_customer', 'customer', $customerId, 'Customer record reactivated in bulk action.');
                            break;
                        case 'change_state':
                            $targetState = (string) ($payload['state'] ?? '');
                            $results[] = $store->changeState($customerId, $targetState, $payload);
                            audit('bulk_change_customer_state', 'customer', $customerId, 'Customer state changed to ' . $targetState . ' in bulk action.');
                            break;
                    }
                } catch (Throwable $exception) {
                    $results[] = ['error' => $exception->getMessage()];
                }
            }
            respond_success(['results' => $results]);
            break;
        case 'import-customers':
            require_method('POST');
            if (empty($_FILES['csv_file'])) {
                throw new RuntimeException('Please select a CSV file to upload.');
            }
            $csvContent = file_get_contents($_FILES['csv_file']['tmp_name']);
            $store = customer_record_store();
            $result = $store->importLeadCsv($csvContent);
            audit('import_customers', 'system', 0, 'Customer CSV imported.');
            respond_success($result);
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


function bootstrap_payload(PDO $db): array
{
    $blogPosts = blog_admin_list($db);

    return [
        'employees' => admin_list_accounts($db, ['role' => 'employee', 'status' => 'active']),
        'users' => list_users($db),
        'invitations' => list_invitations($db),
        'complaints' => portal_all_complaints($db),
        'audit' => recent_audit_logs($db),
        'metrics' => current_metrics($db),
        'loginPolicy' => fetch_login_policy($db),
        'tasks' => [
            'items' => portal_list_tasks($db),
            'team' => portal_list_team($db),
        ],
        'documents' => portal_list_documents($db, 'admin'),
        'blog' => [
            'posts' => $blogPosts,
        ],
    ];
}

function list_users(PDO $db): array
{
    $accounts = admin_list_accounts($db, ['status' => 'all']);

    return array_map(static function (array $account): array {
        $account['role_name'] = $account['role'];
        return $account;
    }, $accounts);
}

function list_invitations(PDO $db): array
{
    $stmt = $db->query('SELECT invitations.id, invitations.invitee_name, invitations.invitee_email, invitations.status, invitations.created_at, invitations.approved_at, invitations.message, roles.name AS role_name FROM invitations INNER JOIN roles ON invitations.role_id = roles.id ORDER BY invitations.created_at DESC');
    return $stmt->fetchAll();
}

function fetch_complaint_timeline(PDO $db, array $query): array
{
    return portal_all_complaints($db);
}

function recent_audit_logs(PDO $db): array
{
    return portal_recent_audit_logs($db);
}

function current_metrics(PDO $db): array
{
    $counts = admin_overview_counts($db);
    $pendingInvites = (int) $db->query("SELECT COUNT(*) FROM invitations WHERE status = 'pending'")->fetchColumn();
    $metrics = $db->query('SELECT name, value FROM system_metrics')->fetchAll(PDO::FETCH_KEY_PAIR);

    return [
        'counts' => [
            'employees' => $counts['employees'],
            'leads' => $counts['leads'],
            'pendingInvitations' => $pendingInvites,
            'reminders' => $counts['reminders'],
            'referrers' => $counts['referrers'],
        ],
        'system' => [
            'last_backup' => $metrics['last_backup'] ?? 'Not recorded',
            'errors_24h' => $metrics['errors_24h'] ?? '0',
            'disk_usage' => $metrics['disk_usage'] ?? 'Normal',
            'uptime' => $metrics['uptime'] ?? 'Unknown',
        ],
    ];
}

function fetch_login_policy(PDO $db): array
{
    $stmt = $db->query('SELECT retry_limit, lockout_minutes, twofactor_mode, session_timeout, updated_at FROM login_policies WHERE id = 1');
    $row = $stmt->fetch();
    if (!$row) {
        ensure_login_policy_row($db);
        $row = $db->query('SELECT retry_limit, lockout_minutes, twofactor_mode, session_timeout, updated_at FROM login_policies WHERE id = 1')->fetch();
    }
    return $row ?: [];
}

function get_blog_post_for_admin(PDO $db, array $query): array
{
    $postId = isset($query['id']) ? (int) $query['id'] : 0;
    $slug = isset($query['slug']) ? trim((string) $query['slug']) : '';

    if ($postId > 0) {
        return blog_get_post_by_id($db, $postId);
    }

    if ($slug !== '') {
        $post = blog_get_post_by_slug($db, $slug, true);
        if ($post) {
            $tags = array_map(static fn ($tag) => $tag['name'] ?? $tag, $post['tags'] ?? []);
            return [
                'id' => (int) $post['id'],
                'title' => $post['title'],
                'slug' => $post['slug'],
                'excerpt' => $post['excerpt'] ?? '',
                'body' => $post['body_html'] ?? '',
                'coverImage' => $post['cover_image'] ?? '',
                'coverImageAlt' => $post['cover_image_alt'] ?? '',
                'authorName' => $post['author_name'] ?? '',
                'status' => $post['status'],
                'publishedAt' => $post['published_at'],
                'updatedAt' => $post['updated_at'],
                'tags' => $tags,
            ];
        }
    }

    throw new RuntimeException('Post not found.');
}

function create_user(PDO $db, array $input): array
{
    $fullName = trim((string)($input['fullName'] ?? ''));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $username = strtolower(trim((string)($input['username'] ?? '')));
    $password = (string)($input['password'] ?? '');
    $requestedRole = strtolower(trim((string)($input['role'] ?? '')));
    $permissions = trim((string)($input['permissions'] ?? ''));

    if ($fullName === '' || $email === '' || $username === '' || $password === '' || $requestedRole === '') {
        throw new RuntimeException('All required fields must be supplied.');
    }

    $roleName = normalize_role_name($requestedRole);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
    }

    if (!preg_match('/^[a-z0-9._-]{3,}$/i', $username)) {
        throw new RuntimeException('Username must be at least 3 characters and contain only letters, numbers, dots, dashes, or underscores.');
    }

    if (strlen($password) < 8) {
        throw new RuntimeException('Password must contain at least 8 characters.');
    }

    resolve_role_id($db, $roleName);

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $now = now_ist();

    $store = user_store();

    try {
        $record = $store->save([
            'full_name' => $fullName,
            'email' => $email,
            'username' => $username,
            'role' => $roleName,
            'status' => 'active',
            'permissions_note' => $permissions,
            'password_hash' => $hash,
            'password_last_set_at' => $now,
            'created_at' => $now,
        ]);
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
        if (stripos($message, 'already in use') !== false) {
            throw new RuntimeException('A user with that email, username, or phone already exists.');
        }
        throw $exception;
    }

    $userId = (int) ($record['id'] ?? 0);
    $auditMessage = sprintf('User %s (%s) created with role %s', $fullName, $email, $roleName);
    if ($roleName !== $requestedRole) {
        $auditMessage .= sprintf(' (requested %s)', $requestedRole);
    }
    audit('create_user', 'user', $userId, $auditMessage);

    return ['user' => fetch_user($db, $userId), 'metrics' => current_metrics($db)];
}

function normalize_role_name(string $roleName): string
{
    $key = strtolower(trim($roleName));
    $map = [
        'admin' => 'admin',
        'administrator' => 'admin',
        'employee' => 'employee',
        'staff' => 'employee',
        'team' => 'employee',
        'installer' => 'installer',
        'referrer' => 'referrer',
        'customer' => 'customer',
    ];

    if (!array_key_exists($key, $map)) {
        throw new RuntimeException('Unknown role specified.');
    }

    return $map[$key];
}

function resolve_role_id(PDO $db, string $roleName): int
{
    $normalized = normalize_role_name($roleName);
    $stmt = $db->prepare('SELECT id FROM roles WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $stmt->execute([':name' => $normalized]);
    $roleId = $stmt->fetchColumn();
    if ($roleId === false) {
        throw new RuntimeException('Unknown role specified.');
    }
    return (int)$roleId;
}

function fetch_user(PDO $db, int $userId): array
{
    return admin_fetch_user($db, $userId);
}

function update_user_status(PDO $db, array $input): array
{
    $userId = (int)($input['userId'] ?? 0);
    $status = strtolower(trim((string)($input['status'] ?? '')));
    $validStatuses = ['active', 'inactive', 'pending'];
    if (!in_array($status, $validStatuses, true)) {
        throw new RuntimeException('Invalid status provided.');
    }
    $store = user_store();
    $record = $store->get($userId);
    if (!$record) {
        throw new RuntimeException('User not found.');
    }

    $record['status'] = $status;
    $store->save($record);

    audit('update_user_status', 'user', $userId, sprintf('User status changed to %s', $status));
    return ['user' => fetch_user($db, $userId), 'metrics' => current_metrics($db)];
}

function create_invitation(PDO $db, array $input): array
{
    $name = trim((string)($input['name'] ?? ''));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $requestedRole = strtolower(trim((string)($input['role'] ?? '')));
    $submittedBy = trim((string)($input['submittedBy'] ?? ''));

    if ($name === '' || $email === '' || $requestedRole === '' || $submittedBy === '') {
        throw new RuntimeException('Complete all invitation fields.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
    }

    $roleName = normalize_role_name($requestedRole);
    $roleId = resolve_role_id($db, $roleName);
    $token = bin2hex(random_bytes(16));
    $currentUser = current_user();
    $stmt = $db->prepare('INSERT INTO invitations(inviter_id, invitee_name, invitee_email, role_id, status, token, message) VALUES(:inviter_id, :invitee_name, :invitee_email, :role_id, "pending", :token, :message)');
    $stmt->execute([
        ':inviter_id' => $currentUser['id'] ?? null,
        ':invitee_name' => $name,
        ':invitee_email' => $email,
        ':role_id' => $roleId,
        ':token' => $token,
        ':message' => $submittedBy,
    ]);

    $inviteId = (int)$db->lastInsertId();
    $inviteAudit = sprintf('Invitation recorded for %s (%s) with role %s', $name, $email, $roleName);
    if ($roleName !== $requestedRole) {
        $inviteAudit .= sprintf(' (requested %s)', $requestedRole);
    }
    audit('create_invitation', 'invitation', $inviteId, $inviteAudit);
    return ['invitation' => fetch_invitation($db, $inviteId), 'metrics' => current_metrics($db)];
}

function fetch_invitation(PDO $db, int $inviteId): array
{
    $stmt = $db->prepare('SELECT invitations.id, invitations.invitee_name, invitations.invitee_email, invitations.status, invitations.created_at, invitations.approved_at, invitations.message, roles.name AS role_name FROM invitations INNER JOIN roles ON invitations.role_id = roles.id WHERE invitations.id = :id LIMIT 1');
    $stmt->execute([':id' => $inviteId]);
    $invite = $stmt->fetch();
    if (!$invite) {
        throw new RuntimeException('Invitation not found.');
    }
    return $invite;
}

function approve_invitation(PDO $db, array $input): array
{
    $inviteId = (int)($input['inviteId'] ?? 0);
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($inviteId <= 0) {
        throw new RuntimeException('Invalid invitation identifier.');
    }
    if ($username === '' || $password === '') {
        throw new RuntimeException('Set a username and password for the new user.');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('Passwords must be at least 8 characters.');
    }

    $inviteStmt = $db->prepare('SELECT * FROM invitations WHERE id = :id AND status = "pending" LIMIT 1');
    $inviteStmt->execute([':id' => $inviteId]);
    $invite = $inviteStmt->fetch();
    if (!$invite) {
        throw new RuntimeException('Only pending invitations can be approved.');
    }

    $roleId = (int)$invite['role_id'];
    $fullName = $invite['invitee_name'];
    $email = strtolower($invite['invitee_email']);

    $roleStmt = $db->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
    $roleStmt->execute([':id' => $roleId]);
    $roleValue = $roleStmt->fetchColumn();
    if ($roleValue === false) {
        throw new RuntimeException('Invitation role is not available.');
    }
    $roleName = normalize_role_name((string) $roleValue);

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $now = now_ist();

    $store = user_store();

    $db->beginTransaction();
    try {
        $record = $store->save([
            'full_name' => $fullName,
            'email' => $email,
            'username' => strtolower($username),
            'role' => $roleName,
            'status' => 'active',
            'password_hash' => $hash,
            'password_last_set_at' => $now,
            'created_at' => $now,
        ]);

        $db->prepare("UPDATE invitations SET status = 'approved', approved_at = datetime('now') WHERE id = :id")->execute([':id' => $inviteId]);
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        if ($exception instanceof RuntimeException && stripos($exception->getMessage(), 'already in use') !== false) {
            throw new RuntimeException('A user with that email, username, or phone already exists.');
        }
        throw $exception;
    }

    $userId = (int) ($record['id'] ?? 0);
    audit('approve_invitation', 'invitation', $inviteId, sprintf('Invitation %d approved and converted to user %s', $inviteId, $fullName));

    return [
        'invitation' => fetch_invitation($db, $inviteId),
        'user' => fetch_user($db, $userId),
        'metrics' => current_metrics($db),
    ];
}

function reject_invitation(PDO $db, array $input): array
{
    $inviteId = (int)($input['inviteId'] ?? 0);
    if ($inviteId <= 0) {
        throw new RuntimeException('Invalid invitation identifier.');
    }
    $stmt = $db->prepare('UPDATE invitations SET status = "rejected", approved_at = NULL WHERE id = :id');
    $stmt->execute([':id' => $inviteId]);
    audit('reject_invitation', 'invitation', $inviteId, sprintf('Invitation %d rejected', $inviteId));
    return ['invitation' => fetch_invitation($db, $inviteId), 'metrics' => current_metrics($db)];
}

function change_password(PDO $db, array $input): array
{
    $user = current_user();
    if (!$user) {
        throw new RuntimeException('Authentication required.');
    }

    $current = (string)($input['currentPassword'] ?? '');
    $new = (string)($input['newPassword'] ?? '');
    $confirm = (string)($input['confirmPassword'] ?? '');

    if ($current === '' || $new === '' || $confirm === '') {
        throw new RuntimeException('Provide current, new, and confirmation passwords.');
    }

    if ($new !== $confirm) {
        throw new RuntimeException('New password and confirmation do not match.');
    }

    if (strlen($new) < 8) {
        throw new RuntimeException('New password must be at least 8 characters long.');
    }

    $store = user_store();
    $record = $store->get((int) $user['id']);
    if (!$record) {
        throw new RuntimeException('Unable to load the current user.');
    }

    if (!password_verify($current, (string) ($record['password_hash'] ?? ''))) {
        throw new RuntimeException('Current password is incorrect.');
    }

    if (password_verify($new, (string) ($record['password_hash'] ?? ''))) {
        throw new RuntimeException('New password must differ from the current password.');
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $updatedAt = now_ist();

    $record['password_hash'] = $hash;
    $record['password_last_set_at'] = $updatedAt;
    $store->save($record);

    start_session();
    $_SESSION['user']['password_last_set_at'] = $updatedAt;

    audit('change_password', 'user', (int)$user['id'], sprintf('Password updated for %s', $user['email'] ?? 'administrator'));

    return ['changedAt' => $updatedAt];
}

function update_login_policy(PDO $db, array $input): array
{
    $retry = max(1, (int)($input['retry'] ?? 5));
    $lockout = max(1, (int)($input['lockout'] ?? 30));
    $session = max(5, (int)($input['session'] ?? 45));
    $twofactor = strtolower(trim((string)($input['twofactor'] ?? 'admin')));
    $validTwofactor = ['all', 'admin', 'none'];
    if (!in_array($twofactor, $validTwofactor, true)) {
        throw new RuntimeException('Invalid two-factor mode.');
    }

    $stmt = $db->prepare("UPDATE login_policies SET retry_limit = :retry, lockout_minutes = :lockout, session_timeout = :session, twofactor_mode = :twofactor, updated_at = datetime('now') WHERE id = 1");
    $stmt->execute([
        ':retry' => $retry,
        ':lockout' => $lockout,
        ':session' => $session,
        ':twofactor' => $twofactor,
    ]);

    audit('update_login_policy', 'policy', 1, sprintf('Authentication policy updated (retry: %d, lockout: %d, twofactor: %s)', $retry, $lockout, $twofactor));

    return ['loginPolicy' => fetch_login_policy($db)];
}

function audit(string $action, string $entityType, int $entityId, string $description): void
{
    $db = get_db();
    $user = current_user();
    $stmt = $db->prepare('INSERT INTO audit_logs(actor_id, action, entity_type, entity_id, description) VALUES(:actor_id, :action, :entity_type, :entity_id, :description)');
    $stmt->execute([
        ':actor_id' => $user['id'] ?? null,
        ':action' => $action,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':description' => $description,
    ]);
}
