<?php
declare(strict_types=1);

require_once __DIR__ . '/customer_public.php';
require_once __DIR__ . '/customer_admin.php';
require_once __DIR__ . '/audit_log.php';

function complaint_problem_categories(): array
{
    return [
        'Grid / DISCOM Problem',
        'Net Metering Problem',
        'Solar Generation / Technical Problem',
        'Inverter / Monitoring App Problem',
        'Billing / JBVNL Account Problem',
        'PM Surya Ghar / Subsidy Problem',
        'Structure / Roof / Water Leakage Problem',
        'House Wiring / Internal Electrical Problem',
        'Documentation / Portal Problem',
        'Other Problem',
    ];
}

function complaint_assignee_options(): array
{
    return [
        'Technical Team / Technician',
        'Electrician',
        'Fabrication Team',
        'Documentation Team',
        'DISCOM (Grid / Net Metering)',
        'Billing / DISCOM (Accounts)',
        'PM Surya Ghar Support / Nodal Office',
        'Admin / Office Team',
        'Other / External Agency',
    ];
}

function complaint_default_category(): string
{
    return 'Other Problem';
}

function complaint_normalize_category(string $category): string
{
    $normalized = trim($category);
    if ($normalized === '') {
        return complaint_default_category();
    }

    return in_array($normalized, complaint_problem_categories(), true) ? $normalized : complaint_default_category();
}

function complaint_normalize_assignee(string $assignee): string
{
    $normalized = trim($assignee);
    if ($normalized === '') {
        return '';
    }

    return in_array($normalized, complaint_assignee_options(), true) ? $normalized : '';
}

function complaint_display_assignee(?string $assignee): string
{
    $normalized = complaint_normalize_assignee((string) $assignee);

    return $normalized === '' ? 'Unassigned' : $normalized;
}

function complaint_forwarded_label(?string $forwardedVia): string
{
    $value = complaint_normalize_forwarded_via($forwardedVia);
    return match ($value) {
        'whatsapp' => 'Forwarded via WhatsApp',
        'email' => 'Forwarded via Email',
        'both' => 'Forwarded via WhatsApp and Email',
        default => 'Not Forwarded',
    };
}

function complaint_normalize_forwarded_via(?string $value): string
{
    $normalized = strtolower(trim((string) $value));
    if ($normalized === '' || $normalized === 'none') {
        return 'none';
    }

    if (in_array($normalized, ['whatsapp', 'email', 'both'], true)) {
        return $normalized;
    }

    return 'none';
}

function complaint_summary_counts(?array $complaints = null): array
{
    if ($complaints === null) {
        $complaints = load_all_complaints();
    }

    $total = count($complaints);
    $open = 0;
    $unassigned = 0;

    foreach ($complaints as $complaint) {
        $status = strtolower((string) ($complaint['status'] ?? 'open'));
        if ($status !== 'closed') {
            $open++;
        }

        $assignee = complaint_display_assignee($complaint['assignee'] ?? '');
        if (strcasecmp($assignee, 'Unassigned') === 0) {
            $unassigned++;
        }
    }

    return [
        'total' => $total,
        'open' => $open,
        'unassigned' => $unassigned,
    ];
}

function complaint_storage_base_dir(): string
{
    $base = __DIR__ . '/../storage/customer-complaints';
    if (!is_dir($base)) {
        mkdir($base, 0775, true);
    }

    return $base;
}

function complaint_store_path(): string
{
    return complaint_storage_base_dir() . '/complaints.json';
}

function complaint_lock_path(): string
{
    return complaint_storage_base_dir() . '/complaints.lock';
}

function complaint_now(): string
{
    $tz = new DateTimeZone('Asia/Kolkata');
    return (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
}

function complaint_random_id(): string
{
    try {
        return bin2hex(random_bytes(8));
    } catch (Throwable $exception) {
        return (string) mt_rand(100000, 999999);
    }
}

function complaint_normalize_mobile(string $mobile): string
{
    $digits = preg_replace('/\D+/', '', $mobile);
    if (!is_string($digits)) {
        return '';
    }
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }
    if (strlen($digits) !== 10) {
        return '';
    }

    return $digits;
}

/**
 * @return array{next_id:int, records: array<int, array<string, mixed>>}
 */
function complaint_load_store(): array
{
    $path = complaint_store_path();
    if (!is_file($path)) {
        file_put_contents($path, json_encode(['next_id' => 1, 'records' => []], JSON_PRETTY_PRINT));
    }

    $decoded = json_decode(file_get_contents($path) ?: '', true);
    if (!is_array($decoded)) {
        $decoded = ['next_id' => 1, 'records' => []];
    }

    return [
        'next_id' => isset($decoded['next_id']) ? max(1, (int) $decoded['next_id']) : 1,
        'records' => isset($decoded['records']) && is_array($decoded['records']) ? $decoded['records'] : [],
    ];
}

function complaint_save_store(array $store): void
{
    $encoded = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Could not encode complaint store.');
    }

    if (file_put_contents(complaint_store_path(), $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Could not write complaint store.');
    }
}

function complaint_with_store(callable $callback)
{
    $handle = fopen(complaint_lock_path(), 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open complaint lock file.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock complaints store.');
        }

        $store = complaint_load_store();
        $result = $callback($store);
        complaint_save_store($store);

        flock($handle, LOCK_UN);

        return $result;
    } finally {
        fclose($handle);
    }
}

function complaint_normalize_record(array $record): array
{
    $record['customer_mobile'] = complaint_normalize_mobile((string) ($record['customer_mobile'] ?? ($record['mobile'] ?? '')));
    $record['title'] = trim((string) ($record['title'] ?? ($record['type'] ?? 'Complaint')));
    $record['description'] = trim((string) ($record['description'] ?? ''));
    $record['status'] = strtolower(trim((string) ($record['status'] ?? 'open')));
    $record['problem_category'] = complaint_normalize_category((string) ($record['problem_category'] ?? ''));
    $record['assignee'] = complaint_normalize_assignee((string) ($record['assignee'] ?? ''));
    $record['forwarded_via'] = complaint_normalize_forwarded_via($record['forwarded_via'] ?? 'none');
    $record['created_at'] = $record['created_at'] ?? complaint_now();
    $record['updated_at'] = $record['updated_at'] ?? $record['created_at'];
    $record['id'] = isset($record['id']) ? (string) $record['id'] : (string) ($record['reference'] ?? complaint_random_id());

    return $record;
}

function load_all_complaints(): array
{
    $store = complaint_load_store();
    $normalized = [];
    foreach ($store['records'] as $record) {
        if (!is_array($record)) {
            continue;
        }
        $normalized[] = complaint_normalize_record($record);
    }

    return $normalized;
}

function find_complaint_by_id(string $id): ?array
{
    foreach (load_all_complaints() as $record) {
        if ((string) ($record['id'] ?? '') === (string) $id) {
            return $record;
        }
    }

    return null;
}

function save_all_complaints(array $complaints): void
{
    $nextId = 1;
    foreach ($complaints as $complaint) {
        $id = isset($complaint['id']) ? (int) $complaint['id'] : 0;
        if ($id >= $nextId) {
            $nextId = $id + 1;
        }
    }

    complaint_save_store([
        'next_id' => $nextId,
        'records' => $complaints,
    ]);
}

function get_complaints_by_customer(string $customerMobile): array
{
    $normalized = complaint_normalize_mobile($customerMobile);
    $matches = [];
    foreach (load_all_complaints() as $record) {
        if (($record['customer_mobile'] ?? '') === $normalized) {
            $matches[] = $record;
        }
    }

    usort($matches, static function (array $left, array $right): int {
        return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
    });

    return $matches;
}

function add_complaint(array $data, bool $assigneeRequired = false): array
{
    $mobile = complaint_normalize_mobile((string) ($data['customer_mobile'] ?? ''));
    if ($mobile === '') {
        throw new RuntimeException('A valid mobile number is required.');
    }

    $title = trim((string) ($data['title'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    if ($title === '' || $description === '') {
        throw new RuntimeException('Title and description are required.');
    }

    $status = strtolower(trim((string) ($data['status'] ?? 'open')));
    $problemCategory = complaint_normalize_category((string) ($data['problem_category'] ?? ''));
    $assignee = complaint_normalize_assignee((string) ($data['assignee'] ?? ''));
    if ($assigneeRequired && $assignee === '') {
        throw new RuntimeException('Select an assignee.');
    }
    $now = complaint_now();

    $record = complaint_with_store(static function (array &$store) use ($mobile, $title, $description, $status, $problemCategory, $assignee, $now): array {
        $id = $store['next_id'] ?? 1;

        $record = [
            'id' => (string) $id,
            'customer_mobile' => $mobile,
            'title' => $title,
            'description' => $description,
            'status' => $status === '' ? 'open' : $status,
            'problem_category' => $problemCategory === '' ? complaint_default_category() : $problemCategory,
            'assignee' => $assignee,
            'forwarded_via' => 'none',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $store['records'][] = $record;
        $store['next_id'] = $id + 1;

        return $record;
    });

    $actor = audit_current_actor();
    log_audit_event(
        $actor['actor_type'],
        $actor['actor_id'],
        'complaint',
        (string) ($record['id'] ?? ''),
        'complaint_create',
        [
            'customer_mobile' => $mobile,
            'title' => $title,
            'problem_category' => $problemCategory,
            'assignee' => $assignee,
        ]
    );

    return $record;
}

function update_complaint_status($id, string $newStatus): ?array
{
    $normalizedStatus = strtolower(trim($newStatus));
    if ($normalizedStatus === '') {
        $normalizedStatus = 'open';
    }

    return update_complaint([
        'id' => $id,
        'status' => $normalizedStatus,
    ]);
}

function update_complaint(array $updates, bool $assigneeRequired = false): ?array
{
    $id = (string) ($updates['id'] ?? '');
    if ($id === '') {
        return null;
    }

    $status = array_key_exists('status', $updates) ? strtolower(trim((string) $updates['status'])) : null;
    if ($status !== null && $status === '') {
        $status = 'open';
    }

    $problemCategory = array_key_exists('problem_category', $updates)
        ? complaint_normalize_category((string) $updates['problem_category'])
        : null;

    $assignee = array_key_exists('assignee', $updates)
        ? complaint_normalize_assignee((string) $updates['assignee'])
        : null;

    $forwardedVia = array_key_exists('forwarded_via', $updates)
        ? complaint_normalize_forwarded_via((string) $updates['forwarded_via'])
        : null;

    if ($assigneeRequired && $assignee === '') {
        throw new RuntimeException('Select an assignee.');
    }

    $previousRecord = null;

    $updatedRecord = complaint_with_store(static function (array &$store) use ($id, $status, $problemCategory, $assignee, $forwardedVia, &$previousRecord): ?array {
        foreach ($store['records'] as $index => $record) {
            if ((string) ($record['id'] ?? '') !== $id) {
                continue;
            }

            $previousRecord = $record;

            if ($status !== null) {
                $record['status'] = $status;
            }
            if ($problemCategory !== null) {
                $record['problem_category'] = $problemCategory;
            }
            if ($assignee !== null) {
                $record['assignee'] = $assignee;
            }

            if ($forwardedVia !== null) {
                $record['forwarded_via'] = $forwardedVia;
            }

            $record['updated_at'] = complaint_now();
            $store['records'][$index] = $record;

            return complaint_normalize_record($record);
        }

        return null;
    });

    if ($updatedRecord !== null && $previousRecord !== null) {
        $actor = audit_current_actor();
        $changes = audit_changed_fields(complaint_normalize_record($previousRecord), $updatedRecord, ['updated_at']);
        if ($changes !== []) {
            log_audit_event(
                $actor['actor_type'],
                $actor['actor_id'],
                'complaint',
                (string) ($updatedRecord['id'] ?? $id),
                'complaint_update',
                ['changed_fields' => $changes]
            );
        }
    }

    return $updatedRecord;
}

function complaints_customer_has_active(string $mobile): bool
{
    foreach (get_complaints_by_customer($mobile) as $complaint) {
        if (strtolower((string) ($complaint['status'] ?? '')) !== 'closed') {
            return true;
        }
    }

    return false;
}

function complaint_sync_customer_flag(CustomerFsStore $store, string $mobile): void
{
    $customer = $store->findByMobile($mobile);
    if ($customer === null) {
        return;
    }

    $hasActive = complaints_customer_has_active($mobile);
    $desired = $hasActive ? 'Yes' : 'No';
    if (($customer['complaints_raised'] ?? '') === $desired) {
        return;
    }

    $store->updateCustomer($mobile, ['complaints_raised' => $desired]);
}

// -----------------------------------------------------------------------------
// Legacy wrappers
// -----------------------------------------------------------------------------

function customer_complaint_all(): array
{
    return load_all_complaints();
}

function customer_complaint_by_mobile(string $mobile): array
{
    return get_complaints_by_customer($mobile);
}

function customer_complaint_submit(array $payload): array
{
    $store = public_customer_store();
    $mobile = public_normalize_mobile((string) ($payload['mobile'] ?? ''));
    if ($mobile === '') {
        throw new RuntimeException('Enter a valid mobile number.');
    }

    $customer = $store->findByMobile($mobile);
    if ($customer === null) {
        throw new RuntimeException('Only registered customers can submit complaints. Please contact our team if you have not yet completed installation.');
    }

    $title = trim((string) ($payload['title'] ?? ($payload['type'] ?? 'Complaint')));
    $description = trim((string) ($payload['description'] ?? ''));
    if ($description === '') {
        throw new RuntimeException('Describe the issue so we can register your complaint.');
    }

    $problemCategory = trim((string) ($payload['problem_category'] ?? ''));
    if ($problemCategory === '') {
        throw new RuntimeException('Select a problem category.');
    }

    $record = add_complaint([
        'customer_mobile' => $mobile,
        'title' => $title,
        'description' => $description,
        'status' => 'open',
        'problem_category' => $problemCategory,
        'assignee' => '',
    ]);

    complaint_sync_customer_flag($store, $mobile);

    return $record;
}
