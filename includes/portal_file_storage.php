<?php
declare(strict_types=1);

/**
 * File-backed portal storage for referrers, leads, and installations.
 */

function portal_file_base_dir(): string
{
    $base = __DIR__ . '/../storage/portal';
    if (!is_dir($base)) {
        mkdir($base, 0775, true);
    }

    return $base;
}

function portal_file_path(string $name): string
{
    return portal_file_base_dir() . '/' . $name . '.json';
}

function portal_file_lock_path(string $name): string
{
    return portal_file_base_dir() . '/' . $name . '.lock';
}

function portal_file_default_store(): array
{
    return [
        'next_id' => 1,
        'records' => [],
    ];
}

function portal_file_load(string $name): array
{
    $path = portal_file_path($name);
    if (!is_file($path)) {
        return portal_file_default_store();
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return portal_file_default_store();
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log(sprintf('portal_file_load: failed to decode %s: %s', $name, $exception->getMessage()));
        return portal_file_default_store();
    }

    if (!is_array($decoded)) {
        return portal_file_default_store();
    }

    $decoded['next_id'] = isset($decoded['next_id']) ? (int) $decoded['next_id'] : 1;
    if ($decoded['next_id'] < 1) {
        $decoded['next_id'] = 1;
    }
    $decoded['records'] = isset($decoded['records']) && is_array($decoded['records']) ? $decoded['records'] : [];

    return $decoded;
}

function portal_file_save(string $name, array $data): void
{
    $path = portal_file_path($name);
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('portal_file_save: failed to encode data for ' . $name);
    }

    if (file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('portal_file_save: failed to write store ' . $name);
    }
}

function portal_file_update(string $name, callable $callback)
{
    $handle = fopen(portal_file_lock_path($name), 'c+');
    if ($handle === false) {
        throw new RuntimeException('portal_file_update: unable to open lock for ' . $name);
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('portal_file_update: unable to acquire lock for ' . $name);
        }

        $data = portal_file_load($name);
        $result = $callback($data);
        portal_file_save($name, $data);

        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    return $result;
}

function portal_file_now(): string
{
    $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    return $now->format('Y-m-d H:i:s');
}

function portal_file_uuid(): string
{
    try {
        return bin2hex(random_bytes(8));
    } catch (Throwable $exception) {
        return sprintf('%d%d', mt_rand(100000, 999999), mt_rand(100000, 999999));
    }
}

function file_portal_find_user(int $userId): ?array
{
    if (!function_exists('user_store')) {
        return null;
    }

    try {
        return user_store()->get($userId);
    } catch (Throwable $exception) {
        error_log(sprintf('file_portal_find_user: failed to read user %d: %s', $userId, $exception->getMessage()));
        return null;
    }
}

function file_portal_role_label(string $roleName): string
{
    return function_exists('portal_role_label')
        ? portal_role_label($roleName)
        : ucfirst(strtolower(trim($roleName)) ?: 'User');
}

function file_portal_actor_details(int $actorId): array
{
    if ($actorId <= 0) {
        return [
            'id' => 0,
            'name' => 'System',
            'role' => 'System',
        ];
    }

    $record = file_portal_find_user($actorId);
    if (!$record) {
        return [
            'id' => $actorId,
            'name' => 'User #' . $actorId,
            'role' => 'User',
        ];
    }

    $roleName = (string) ($record['role'] ?? ($record['role_name'] ?? 'User'));
    $fullName = trim((string) ($record['full_name'] ?? ''));

    return [
        'id' => (int) ($record['id'] ?? $actorId),
        'name' => $fullName !== '' ? $fullName : 'User #' . $actorId,
        'role' => file_portal_role_label($roleName),
    ];
}

// -----------------------------------------------------------------------------
// Referrers & leads
// -----------------------------------------------------------------------------

function file_referrer_store_all(): array
{
    $data = portal_file_load('referrers');
    $records = [];
    foreach ($data['records'] as $key => $record) {
        if (!is_array($record)) {
            continue;
        }
        $record['id'] = isset($record['id']) ? (int) $record['id'] : (int) $key;
        if (!isset($record['created_at'])) {
            $record['created_at'] = null;
        }
        if (!isset($record['updated_at'])) {
            $record['updated_at'] = null;
        }
        $records[(string) $record['id']] = $record;
    }

    return $records;
}

function file_referrer_find(int $id): ?array
{
    $records = file_referrer_store_all();
    return $records[(string) $id] ?? null;
}

function file_referrer_save(array $record): array
{
    $id = isset($record['id']) ? (int) $record['id'] : 0;
    if ($id <= 0) {
        throw new RuntimeException('file_referrer_save: record id missing.');
    }

    return portal_file_update('referrers', function (array &$data) use ($record, $id) {
        $data['records'][(string) $id] = $record;
        if (!isset($data['next_id']) || (int) $data['next_id'] <= $id) {
            $data['next_id'] = $id + 1;
        }

        return $record;
    });
}

function file_referrer_create(array $payload): array
{
    return portal_file_update('referrers', function (array &$data) use ($payload) {
        $nextId = isset($data['next_id']) ? (int) $data['next_id'] : 1;
        if ($nextId < 1) {
            $nextId = 1;
        }

        $record = $payload;
        $record['id'] = $nextId;
        $data['records'][(string) $nextId] = $record;
        $data['next_id'] = $nextId + 1;

        return $record;
    });
}

function file_referrer_status_options(): array
{
    return function_exists('admin_referrer_status_options')
        ? admin_referrer_status_options()
        : [
            'active' => 'Active',
            'prospect' => 'Prospect',
            'inactive' => 'Inactive',
        ];
}

function file_referrer_normalize_payload(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Referrer name is required.');
    }

    $company = trim((string) ($input['company'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address for the referrer.');
    }

    $phone = trim((string) ($input['phone'] ?? ''));
    $status = strtolower(trim((string) ($input['status'] ?? 'active')));
    if (!isset(file_referrer_status_options()[$status])) {
        $status = 'active';
    }
    $notes = trim((string) ($input['notes'] ?? ''));

    return [
        'name' => $name,
        'company' => $company !== '' ? $company : null,
        'email' => $email !== '' ? $email : null,
        'phone' => $phone !== '' ? $phone : null,
        'status' => $status,
        'notes' => $notes !== '' ? $notes : null,
    ];
}

function file_lead_store_all(): array
{
    $data = portal_file_load('leads');
    $records = [];
    foreach ($data['records'] as $key => $record) {
        if (!is_array($record)) {
            continue;
        }
        $record['id'] = isset($record['id']) ? (int) $record['id'] : (int) $key;
        if (!isset($record['stage_history']) || !is_array($record['stage_history'])) {
            $record['stage_history'] = [];
        }
        if (!isset($record['status'])) {
            $record['status'] = 'new';
        }
        if (!isset($record['created_at'])) {
            $record['created_at'] = portal_file_now();
        }
        $records[(string) $record['id']] = $record;
    }

    return $records;
}

function file_lead_save(array $record): array
{
    $id = isset($record['id']) ? (int) $record['id'] : 0;
    if ($id <= 0) {
        throw new RuntimeException('file_lead_save: record id missing.');
    }

    return portal_file_update('leads', function (array &$data) use ($record, $id) {
        $data['records'][(string) $id] = $record;
        if (!isset($data['next_id']) || (int) $data['next_id'] <= $id) {
            $data['next_id'] = $id + 1;
        }

        return $record;
    });
}

function file_lead_create(array $payload): array
{
    return portal_file_update('leads', function (array &$data) use ($payload) {
        $nextId = isset($data['next_id']) ? (int) $data['next_id'] : 1;
        if ($nextId < 1) {
            $nextId = 1;
        }

        $record = $payload;
        $record['id'] = $nextId;
        $data['records'][(string) $nextId] = $record;
        $data['next_id'] = $nextId + 1;

        return $record;
    });
}

function file_lead_find(int $id): ?array
{
    $records = file_lead_store_all();
    return $records[(string) $id] ?? null;
}

function file_lead_delete(int $id): void
{
    portal_file_update('leads', static function (array &$data) use ($id): void {
        $key = (string) $id;
        if (isset($data['records'][$key])) {
            unset($data['records'][$key]);
        }
    });
}

function file_lead_stage_order(): array
{
    if (function_exists('lead_stage_order')) {
        return lead_stage_order();
    }

    return [
        'new' => 0,
        'visited' => 1,
        'quotation' => 2,
        'converted' => 3,
        'lost' => 4,
    ];
}

function file_lead_stage_index(string $status): int
{
    if (function_exists('lead_stage_index')) {
        return lead_stage_index($status);
    }

    $order = file_lead_stage_order();
    $normalized = strtolower(trim($status));

    return $order[$normalized] ?? 99;
}

function file_lead_status_label(string $status): string
{
    if (function_exists('lead_status_label')) {
        return lead_status_label($status);
    }

    $labels = [
        'new' => 'New',
        'visited' => 'Visited',
        'quotation' => 'Quotation',
        'converted' => 'Converted',
        'lost' => 'Lost',
    ];

    $normalized = strtolower(trim($status));

    return $labels[$normalized] ?? ucfirst($normalized ?: 'New');
}

function file_admin_active_employees(): array
{
    if (!function_exists('user_store')) {
        return [];
    }

    try {
        $users = user_store()->listAll();
    } catch (Throwable $exception) {
        error_log('file_admin_active_employees: unable to list users: ' . $exception->getMessage());
        return [];
    }

    $results = [];
    foreach ($users as $user) {
        $id = (int) ($user['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $role = strtolower((string) ($user['role'] ?? ''));
        if (function_exists('canonical_role_name')) {
            $role = canonical_role_name($role);
        }
        if ($role !== 'employee') {
            continue;
        }

        $status = strtolower((string) ($user['status'] ?? 'active'));
        if ($status !== 'active') {
            continue;
        }

        $name = trim((string) ($user['full_name'] ?? ''));
        if ($name === '') {
            $name = 'User #' . $id;
        }

        $results[] = [
            'id' => $id,
            'name' => $name,
        ];
    }

    usort($results, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

    return $results;
}

function file_admin_validate_employee(?int $employeeId): ?array
{
    if ($employeeId === null || $employeeId <= 0) {
        return null;
    }

    $record = file_portal_find_user($employeeId);
    if (!$record) {
        throw new RuntimeException('Selected employee is not available.');
    }

    $role = strtolower((string) ($record['role'] ?? ($record['role_name'] ?? '')));
    if (function_exists('canonical_role_name')) {
        $role = canonical_role_name($role);
    }
    if ($role !== 'employee') {
        throw new RuntimeException('Selected employee is not available.');
    }

    $status = strtolower((string) ($record['status'] ?? 'active'));
    if ($status !== 'active') {
        throw new RuntimeException('Selected employee is not available.');
    }

    return $record;
}

function file_admin_format_lead(array $record): array
{
    $id = (int) ($record['id'] ?? 0);
    $status = strtolower((string) ($record['status'] ?? 'new'));
    $createdAt = (string) ($record['created_at'] ?? '');
    $updatedAt = (string) ($record['updated_at'] ?? ($createdAt !== '' ? $createdAt : portal_file_now()));
    $assignedId = isset($record['assigned_to']) && $record['assigned_to'] !== null
        ? (int) $record['assigned_to']
        : null;
    $assignedName = '';
    if ($assignedId) {
        $employee = file_portal_find_user($assignedId);
        if ($employee) {
            $assignedName = trim((string) ($employee['full_name'] ?? ''));
            if ($assignedName === '') {
                $assignedName = 'User #' . $assignedId;
            }
        }
    }

    $referrerId = isset($record['referrer_id']) && $record['referrer_id'] !== null
        ? (int) $record['referrer_id']
        : null;
    $referrerName = '';
    if ($referrerId) {
        $referrer = file_referrer_find($referrerId);
        if ($referrer) {
            $referrerName = (string) ($referrer['name'] ?? '');
        }
    }

    $history = [];
    if (isset($record['stage_history']) && is_array($record['stage_history'])) {
        foreach ($record['stage_history'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $history[] = [
                'from' => strtolower((string) ($entry['from'] ?? '')),
                'to' => strtolower((string) ($entry['to'] ?? '')),
                'note' => $entry['note'] ?? null,
                'changedAt' => (string) ($entry['changed_at'] ?? ''),
                'actorId' => isset($entry['actor_id']) ? (int) $entry['actor_id'] : null,
            ];
        }
    }

    return [
        'id' => $id,
        'name' => (string) ($record['name'] ?? ''),
        'phone' => trim((string) ($record['phone'] ?? '')),
        'email' => trim((string) ($record['email'] ?? '')),
        'source' => trim((string) ($record['source'] ?? '')),
        'status' => $status,
        'statusLabel' => file_lead_status_label($status),
        'stageIndex' => file_lead_stage_index($status),
        'assignedId' => $assignedId,
        'assignedName' => $assignedName,
        'createdById' => isset($record['created_by']) && $record['created_by'] !== null
            ? (int) $record['created_by']
            : null,
        'referrerId' => $referrerId,
        'referrerName' => $referrerName,
        'siteLocation' => trim((string) ($record['site_location'] ?? '')),
        'siteDetails' => trim((string) ($record['site_details'] ?? '')),
        'notes' => (string) ($record['notes'] ?? ''),
        'createdAt' => $createdAt,
        'updatedAt' => $updatedAt,
        'visits' => [],
        'proposals' => [],
        'hasPendingProposal' => false,
        'pendingProposal' => null,
        'hasApprovedProposal' => false,
        'latestVisit' => null,
        'history' => $history,
    ];
}

function file_admin_lead_overview(): array
{
    $leads = [];
    foreach (file_lead_store_all() as $record) {
        $leads[] = file_admin_format_lead($record);
    }

    usort($leads, static function (array $left, array $right): int {
        $leftStage = $left['stageIndex'] ?? 99;
        $rightStage = $right['stageIndex'] ?? 99;
        if ($leftStage !== $rightStage) {
            return $leftStage <=> $rightStage;
        }

        $leftTime = $left['updatedAt'] !== '' ? $left['updatedAt'] : $left['createdAt'];
        $rightTime = $right['updatedAt'] !== '' ? $right['updatedAt'] : $right['createdAt'];

        return strcmp($rightTime, $leftTime);
    });

    return $leads;
}

function file_admin_create_lead(array $input, int $actorId): array
{
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Lead name is required.');
    }

    $phone = trim((string) ($input['phone'] ?? ''));
    if ($phone !== '') {
        $digits = preg_replace('/\D+/', '', $phone);
        if (!is_string($digits)) {
            $digits = '';
        }
        if ($digits !== '') {
            $phone = $digits;
        }
    } else {
        $phone = null;
    }

    $email = trim((string) ($input['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
    }

    $source = trim((string) ($input['source'] ?? 'Admin Portal'));
    $siteLocation = trim((string) ($input['site_location'] ?? ''));
    $siteDetails = trim((string) ($input['site_details'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));

    $assignedTo = null;
    if (isset($input['assigned_to']) && $input['assigned_to'] !== '') {
        $assignedTo = (int) $input['assigned_to'];
    }
    $employee = null;
    if ($assignedTo !== null) {
        $employee = file_admin_validate_employee($assignedTo);
    }

    $referrerId = isset($input['referrer_id']) && $input['referrer_id'] !== ''
        ? (int) $input['referrer_id']
        : null;
    if ($referrerId !== null && $referrerId > 0) {
        file_referrer_with_metrics($referrerId);
    } else {
        $referrerId = null;
    }

    $timestamp = portal_file_now();
    $record = file_lead_create([
        'name' => $name,
        'phone' => $phone,
        'email' => $email !== '' ? $email : null,
        'source' => $source !== '' ? $source : 'Admin Portal',
        'status' => 'new',
        'assigned_to' => $assignedTo,
        'referrer_id' => $referrerId,
        'site_location' => $siteLocation !== '' ? $siteLocation : null,
        'site_details' => $siteDetails !== '' ? $siteDetails : null,
        'notes' => $notes !== '' ? $notes : null,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
        'created_by' => $actorId > 0 ? $actorId : null,
        'updated_by' => $actorId > 0 ? $actorId : null,
        'stage_history' => [],
    ]);

    if ($referrerId) {
        file_referrer_touch_lead($referrerId);
    }

    if ($employee) {
        $record['assigned_to'] = (int) ($employee['id'] ?? $assignedTo);
    }

    return file_admin_format_lead($record);
}

function file_admin_assign_lead(int $leadId, ?int $employeeId, int $actorId): array
{
    $employee = file_admin_validate_employee($employeeId);

    $record = portal_file_update('leads', static function (array &$data) use ($leadId, $employeeId, $actorId): array {
        $key = (string) $leadId;
        if (!isset($data['records'][$key]) || !is_array($data['records'][$key])) {
            throw new RuntimeException('Lead not found.');
        }

        $record = $data['records'][$key];
        $record['assigned_to'] = $employeeId ?: null;
        $record['updated_at'] = portal_file_now();
        $record['updated_by'] = $actorId > 0 ? $actorId : null;
        $data['records'][$key] = $record;

        return $record;
    });

    return file_admin_format_lead($record);
}

function file_admin_update_lead_stage(int $leadId, string $targetStage, int $actorId, string $note = ''): array
{
    $allowed = array_keys(file_lead_stage_order());
    $target = strtolower(trim($targetStage));
    if (!in_array($target, $allowed, true)) {
        throw new RuntimeException('Unsupported lead stage.');
    }

    $record = portal_file_update('leads', static function (array &$data) use ($leadId, $target, $actorId, $note): array {
        $key = (string) $leadId;
        if (!isset($data['records'][$key]) || !is_array($data['records'][$key])) {
            throw new RuntimeException('Lead not found.');
        }

        $record = $data['records'][$key];
        $current = strtolower((string) ($record['status'] ?? 'new'));
        if ($current === $target) {
            return $record;
        }

        if (in_array($current, ['converted', 'lost'], true)) {
            throw new RuntimeException('Finalized leads cannot change stages.');
        }

        $history = isset($record['stage_history']) && is_array($record['stage_history'])
            ? $record['stage_history']
            : [];
        $history[] = [
            'from' => $current,
            'to' => $target,
            'note' => $note !== '' ? $note : null,
            'actor_id' => $actorId > 0 ? $actorId : null,
            'changed_at' => portal_file_now(),
        ];
        $record['stage_history'] = array_slice($history, -20);
        $record['status'] = $target;
        if ($target === 'converted') {
            $record['lead_status'] = 'converted';
        }
        $record['updated_at'] = portal_file_now();
        $record['updated_by'] = $actorId > 0 ? $actorId : null;
        $data['records'][$key] = $record;

        return $record;
    });

    return file_admin_format_lead($record);
}

function file_admin_mark_lead_lost(int $leadId, int $actorId, string $note = ''): array
{
    return file_admin_update_lead_stage($leadId, 'lost', $actorId, $note);
}

function file_admin_delete_lead(int $leadId, int $actorId): void
{
    $lead = file_lead_find($leadId);
    if (!$lead) {
        throw new RuntimeException('Lead not found.');
    }

    file_lead_delete($leadId);

    if (!empty($lead['referrer_id'])) {
        try {
            file_referrer_with_metrics((int) $lead['referrer_id']);
        } catch (Throwable $exception) {
            error_log('file_admin_delete_lead: unable to refresh referrer metrics: ' . $exception->getMessage());
        }
    }
}

function file_lead_metrics_for_referrer(int $referrerId): array
{
    $total = 0;
    $converted = 0;
    $lost = 0;
    $latestUpdate = '';

    foreach (file_lead_store_all() as $lead) {
        if ((int) ($lead['referrer_id'] ?? 0) !== $referrerId) {
            continue;
        }

        $total++;
        $status = strtolower((string) ($lead['status'] ?? 'new'));
        if ($status === 'converted') {
            $converted++;
        } elseif ($status === 'lost') {
            $lost++;
        }

        $updated = (string) ($lead['updated_at'] ?? ($lead['created_at'] ?? ''));
        if ($updated !== '' && strcmp($updated, $latestUpdate) > 0) {
            $latestUpdate = $updated;
        }
    }

    $pipeline = max(0, $total - $converted - $lost);
    $conversionRate = $total > 0 ? round(($converted / $total) * 100, 2) : 0.0;

    return [
        'total' => $total,
        'converted' => $converted,
        'lost' => $lost,
        'pipeline' => $pipeline,
        'conversionRate' => $conversionRate,
        'latestLeadUpdate' => $latestUpdate,
    ];
}

function file_referrer_touch_lead(int $referrerId): void
{
    $record = file_referrer_find($referrerId);
    if (!$record) {
        return;
    }

    $record['last_lead_at'] = portal_file_now();
    $record['updated_at'] = $record['last_lead_at'];
    file_referrer_save($record);
}

function file_referrer_with_metrics(int $id): array
{
    $record = file_referrer_find($id);
    if (!$record) {
        throw new RuntimeException('Referrer not found.');
    }

    $record['metrics'] = file_lead_metrics_for_referrer($id);
    $record['statusLabel'] = function_exists('referrer_status_label')
        ? referrer_status_label((string) ($record['status'] ?? 'active'))
        : ucfirst((string) ($record['status'] ?? 'active'));

    return $record;
}

function file_referrer_lookup(callable $predicate): ?array
{
    foreach (file_referrer_store_all() as $record) {
        if ($predicate($record)) {
            return $record;
        }
    }

    return null;
}

function file_referrer_ensure_profile(array $user): array
{
    $email = strtolower(trim((string) ($user['email'] ?? '')));
    $name = trim((string) ($user['full_name'] ?? ''));
    $note = trim((string) ($user['permissions_note'] ?? ''));

    if ($email !== '') {
        $match = file_referrer_lookup(static fn (array $record) => strtolower((string) ($record['email'] ?? '')) === $email);
        if ($match) {
            return file_referrer_with_metrics((int) $match['id']);
        }
    }

    if ($name !== '') {
        $match = file_referrer_lookup(static fn (array $record) => strcasecmp((string) ($record['name'] ?? ''), $name) === 0);
        if ($match) {
            return file_referrer_with_metrics((int) $match['id']);
        }
    }

    if ($email === '' && $name === '') {
        throw new RuntimeException('Referrer profile could not be resolved. Please contact the administrator.');
    }

    $now = portal_file_now();
    $fallbackName = $name !== '' ? $name : 'Referrer #' . ((int) ($user['id'] ?? 0) ?: random_int(1000, 9999));
    $company = $note !== '' ? $note : null;

    $created = file_referrer_create([
        'name' => $fallbackName,
        'company' => $company,
        'email' => $email !== '' ? $email : null,
        'phone' => null,
        'status' => 'active',
        'notes' => 'Auto-created from portal access on ' . $now,
        'last_lead_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return file_referrer_with_metrics((int) $created['id']);
}

function file_referrer_submit_lead(array $input, int $referrerId, int $actorId): array
{
    if ($referrerId <= 0) {
        throw new RuntimeException('Referrer profile missing.');
    }

    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Customer name is required.');
    }

    $phoneRaw = trim((string) ($input['phone'] ?? ''));
    $digits = preg_replace('/\D+/', '', $phoneRaw);
    if (!is_string($digits)) {
        $digits = '';
    }
    if ($digits !== '' && strlen($digits) < 6) {
        throw new RuntimeException('Enter a valid contact number (at least 6 digits).');
    }
    $phone = $digits !== '' ? $digits : ($phoneRaw !== '' ? $phoneRaw : null);

    $email = trim((string) ($input['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
    }

    $location = trim((string) ($input['site_location'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));

    $timestamp = portal_file_now();
    $lead = file_lead_create([
        'name' => $name,
        'phone' => $phone,
        'email' => $email !== '' ? $email : null,
        'status' => 'new',
        'source' => 'Referrer Portal',
        'referrer_id' => $referrerId,
        'assigned_to' => null,
        'site_location' => $location !== '' ? $location : null,
        'notes' => $notes !== '' ? $notes : null,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
        'created_by' => $actorId > 0 ? $actorId : null,
    ]);

    file_referrer_touch_lead($referrerId);

    return $lead;
}

function file_referrer_portal_leads(int $referrerId): array
{
    $rows = [];
    foreach (file_lead_store_all() as $lead) {
        if ((int) ($lead['referrer_id'] ?? 0) !== $referrerId) {
            continue;
        }

        $status = strtolower((string) ($lead['status'] ?? 'new'));
        $category = match ($status) {
            'converted' => 'converted',
            'lost' => 'rejected',
            default => 'approved',
        };

        $rows[] = [
            'id' => (int) ($lead['id'] ?? 0),
            'name' => (string) ($lead['name'] ?? ''),
            'phone' => trim((string) ($lead['phone'] ?? '')),
            'email' => trim((string) ($lead['email'] ?? '')),
            'status' => $status,
            'statusLabel' => function_exists('lead_status_label') ? lead_status_label($status) : ucfirst($status),
            'category' => $category,
            'categoryLabel' => match ($category) {
                'converted' => 'Converted',
                'rejected' => 'Rejected',
                default => 'Approved',
            },
            'createdAt' => (string) ($lead['created_at'] ?? ''),
            'updatedAt' => (string) ($lead['updated_at'] ?? ''),
        ];
    }

    usort($rows, static function (array $left, array $right): int {
        $lhs = (string) ($left['updatedAt'] !== '' ? $left['updatedAt'] : $left['createdAt']);
        $rhs = (string) ($right['updatedAt'] !== '' ? $right['updatedAt'] : $right['createdAt']);
        return strcmp($rhs, $lhs);
    });

    return $rows;
}

function file_admin_list_referrers(string $statusFilter = 'all'): array
{
    $statusFilter = strtolower(trim($statusFilter));
    $validStatuses = array_merge(['all'], array_keys(file_referrer_status_options()));
    if (!in_array($statusFilter, $validStatuses, true)) {
        $statusFilter = 'all';
    }

    $list = [];
    foreach (file_referrer_store_all() as $record) {
        $status = strtolower((string) ($record['status'] ?? 'active'));
        if ($statusFilter !== 'all' && $status !== $statusFilter) {
            continue;
        }

        $metrics = file_lead_metrics_for_referrer((int) $record['id']);
        $list[] = [
            'id' => (int) $record['id'],
            'name' => (string) ($record['name'] ?? ''),
            'company' => (string) ($record['company'] ?? ''),
            'email' => (string) ($record['email'] ?? ''),
            'phone' => (string) ($record['phone'] ?? ''),
            'status' => $status,
            'statusLabel' => function_exists('referrer_status_label') ? referrer_status_label($status) : ucfirst($status),
            'notes' => (string) ($record['notes'] ?? ''),
            'lastLeadAt' => (string) ($record['last_lead_at'] ?? ''),
            'createdAt' => (string) ($record['created_at'] ?? ''),
            'updatedAt' => (string) ($record['updated_at'] ?? ''),
            'metrics' => $metrics,
        ];
    }

    usort($list, static function (array $left, array $right): int {
        $lhs = (string) ($left['updatedAt'] !== '' ? $left['updatedAt'] : $left['createdAt']);
        $rhs = (string) ($right['updatedAt'] !== '' ? $right['updatedAt'] : $right['createdAt']);
        return strcmp($rhs, $lhs);
    });

    return $list;
}

function file_admin_create_referrer(array $input): array
{
    $payload = file_referrer_normalize_payload($input);
    $now = portal_file_now();
    $created = file_referrer_create($payload + [
        'last_lead_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return file_referrer_with_metrics((int) $created['id']);
}

function file_admin_update_referrer(int $id, array $input): array
{
    $existing = file_referrer_find($id);
    if (!$existing) {
        throw new RuntimeException('Referrer not found.');
    }

    $payload = file_referrer_normalize_payload($input);
    $existing = array_merge($existing, $payload);
    $existing['updated_at'] = portal_file_now();

    file_referrer_save($existing);

    return file_referrer_with_metrics($id);
}

function file_admin_referrer_leads(int $referrerId): array
{
    $rows = [];
    foreach (file_lead_store_all() as $lead) {
        if ((int) ($lead['referrer_id'] ?? 0) !== $referrerId) {
            continue;
        }

        $status = strtolower((string) ($lead['status'] ?? 'new'));
        $assignedId = isset($lead['assigned_to']) && $lead['assigned_to'] !== null ? (int) $lead['assigned_to'] : null;
        $assignedName = '';
        if ($assignedId) {
            $assignee = file_portal_find_user($assignedId);
            if ($assignee) {
                $assignedName = (string) ($assignee['full_name'] ?? 'User #' . $assignedId);
            }
        }

        $rows[] = [
            'id' => (int) ($lead['id'] ?? 0),
            'name' => (string) ($lead['name'] ?? ''),
            'phone' => trim((string) ($lead['phone'] ?? '')),
            'status' => $status,
            'statusLabel' => function_exists('lead_status_label') ? lead_status_label($status) : ucfirst($status),
            'source' => (string) ($lead['source'] ?? ''),
            'assignedId' => $assignedId,
            'assignedName' => $assignedName,
            'createdAt' => (string) ($lead['created_at'] ?? ''),
            'updatedAt' => (string) ($lead['updated_at'] ?? ''),
        ];
    }

    usort($rows, static function (array $left, array $right): int {
        $lhs = (string) ($left['updatedAt'] !== '' ? $left['updatedAt'] : $left['createdAt']);
        $rhs = (string) ($right['updatedAt'] !== '' ? $right['updatedAt'] : $right['createdAt']);
        return strcmp($rhs, $lhs);
    });

    return $rows;
}

function file_admin_unassigned_leads(): array
{
    $results = [];
    foreach (file_lead_store_all() as $lead) {
        if (!empty($lead['referrer_id'])) {
            continue;
        }

        $results[] = [
            'id' => (int) ($lead['id'] ?? 0),
            'name' => (string) ($lead['name'] ?? ''),
        ];
    }

    usort($results, static fn (array $left, array $right): int => $right['id'] <=> $left['id']);

    return $results;
}

function file_admin_assign_referrer(int $leadId, ?int $referrerId, int $actorId): array
{
    $lead = file_lead_find($leadId);
    if (!$lead) {
        throw new RuntimeException('Lead not found.');
    }

    if ($referrerId !== null && $referrerId > 0) {
        file_referrer_with_metrics($referrerId);
    }

    $lead['referrer_id'] = $referrerId ?: null;
    $lead['updated_at'] = portal_file_now();
    $lead['updated_by'] = $actorId > 0 ? $actorId : null;

    file_lead_save($lead);

    if ($referrerId) {
        file_referrer_touch_lead($referrerId);
    }

    return $lead;
}

function file_admin_active_referrers(): array
{
    $results = [];
    foreach (file_referrer_store_all() as $record) {
        $status = strtolower((string) ($record['status'] ?? 'active'));
        if ($status !== 'active') {
            continue;
        }

        $results[] = [
            'id' => (int) $record['id'],
            'name' => (string) ($record['name'] ?? ''),
        ];
    }

    usort($results, static fn (array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name']));

    return $results;
}

// -----------------------------------------------------------------------------
// Installations
// -----------------------------------------------------------------------------

function file_installation_store_all(): array
{
    $data = portal_file_load('installations');
    $records = [];
    foreach ($data['records'] as $key => $record) {
        if (!is_array($record)) {
            continue;
        }

        $record['id'] = isset($record['id']) ? (int) $record['id'] : (int) $key;
        if (!isset($record['stage_entries']) || !is_array($record['stage_entries'])) {
            $record['stage_entries'] = [];
        }
        if (!isset($record['requested_stage'])) {
            $record['requested_stage'] = '';
        }
        if (!isset($record['status'])) {
            $record['status'] = 'in_progress';
        }

        $records[(string) $record['id']] = $record;
    }

    return $records;
}

function file_installation_find(int $id): array
{
    $records = file_installation_store_all();
    $key = (string) $id;
    if (!isset($records[$key])) {
        throw new RuntimeException('Installation not found.');
    }

    return $records[$key];
}

function file_installation_save(array $record): array
{
    $id = isset($record['id']) ? (int) $record['id'] : 0;
    if ($id <= 0) {
        throw new RuntimeException('file_installation_save: record id missing.');
    }

    return portal_file_update('installations', function (array &$data) use ($record, $id) {
        $data['records'][(string) $id] = $record;
        if (!isset($data['next_id']) || (int) $data['next_id'] <= $id) {
            $data['next_id'] = $id + 1;
        }

        return $record;
    });
}

function file_installation_actor_name(?int $userId): string
{
    if (!$userId) {
        return '';
    }

    $record = file_portal_find_user($userId);
    if (!$record) {
        return 'User #' . $userId;
    }

    return (string) ($record['full_name'] ?? 'User #' . $userId);
}

function file_installation_normalize(array $record, string $role = 'admin'): array
{
    $stage = strtolower(trim((string) ($record['stage'] ?? 'structure')));
    $requestedStage = strtolower(trim((string) ($record['requested_stage'] ?? '')));
    $progress = function_exists('installation_stage_progress') ? installation_stage_progress($stage) : [];

    $entries = [];
    foreach ($record['stage_entries'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $stageKey = strtolower((string) ($entry['stage'] ?? $stage));
        $entries[] = [
            'id' => (string) ($entry['id'] ?? portal_file_uuid()),
            'stage' => $stageKey,
            'stageLabel' => function_exists('installation_stage_label') ? installation_stage_label($stageKey) : ucfirst($stageKey),
            'type' => (string) ($entry['type'] ?? 'note'),
            'remarks' => (string) ($entry['remarks'] ?? ''),
            'photo' => (string) ($entry['photo'] ?? ''),
            'actorId' => isset($entry['actorId']) ? (int) $entry['actorId'] : null,
            'actorName' => (string) ($entry['actorName'] ?? file_installation_actor_name(isset($entry['actorId']) ? (int) $entry['actorId'] : null)),
            'actorRole' => (string) ($entry['actorRole'] ?? ''),
            'timestamp' => (string) ($entry['timestamp'] ?? ''),
        ];
    }
    usort($entries, static fn (array $left, array $right): int => strcmp((string) $left['timestamp'], (string) $right['timestamp']));

    $roleKey = strtolower(trim($role));
    $allowedUpdates = function_exists('installation_role_allowed_stage_updates')
        ? installation_role_allowed_stage_updates($roleKey)
        : ['structure', 'wiring', 'testing', 'meter', 'commissioned'];

    $maxStage = function_exists('installation_stage_max_for_role') ? installation_stage_max_for_role($roleKey) : 'meter';
    $maxIndex = function_exists('installation_stage_index') ? installation_stage_index($maxStage) : 4;
    $currentIndex = function_exists('installation_stage_index') ? installation_stage_index($stage) : 0;
    $commissionIndex = function_exists('installation_stage_index') ? installation_stage_index('commissioned') : 4;

    $stageOptions = [];
    $keys = function_exists('installation_stage_keys') ? installation_stage_keys() : ['structure', 'wiring', 'testing', 'meter', 'commissioned'];
    $hasCurrent = false;
    $hasCommissionOption = false;

    foreach ($keys as $key) {
        $index = function_exists('installation_stage_index') ? installation_stage_index($key) : 0;
        if ($index < $currentIndex && $roleKey !== 'admin') {
            continue;
        }
        if ($index > $maxIndex && $key !== $stage) {
            continue;
        }
        if ($roleKey !== 'admin' && !in_array($key, $allowedUpdates, true) && $key !== $stage) {
            continue;
        }

        $isCurrent = $key === $stage;
        $isLocked = $requestedStage !== '' && $requestedStage !== $stage && $roleKey !== 'admin';

        $stageOptions[] = [
            'value' => $key,
            'label' => function_exists('installation_stage_label') ? installation_stage_label($key) : ucfirst($key),
            'disabled' => $isLocked && !$isCurrent,
        ];

        if ($isCurrent) {
            $hasCurrent = true;
        }
        if ($key === 'commissioned') {
            $hasCommissionOption = true;
        }
    }

    $canRequestCommissioning = $roleKey !== 'admin'
        && $currentIndex < $commissionIndex
        && in_array('commissioned', $allowedUpdates, true)
        && ($requestedStage === '' || $requestedStage === $stage);

    if ($canRequestCommissioning && !$hasCommissionOption) {
        $stageOptions[] = [
            'value' => 'commissioned',
            'label' => 'Request commissioning approval',
            'disabled' => false,
        ];
    }

    if (!$hasCurrent) {
        $stageOptions[] = [
            'value' => $stage,
            'label' => function_exists('installation_stage_label') ? installation_stage_label($stage) : ucfirst($stage),
            'disabled' => false,
        ];
    }

    usort($stageOptions, static function (array $left, array $right): int {
        $order = ['structure' => 0, 'wiring' => 1, 'testing' => 2, 'meter' => 3, 'commissioned' => 4];
        return ($order[strtolower($left['value'])] ?? 99) <=> ($order[strtolower($right['value'])] ?? 99);
    });

    $stageTone = function_exists('installation_stage_tone') ? installation_stage_tone($stage) : 'progress';

    return [
        'id' => (int) ($record['id'] ?? 0),
        'customer' => (string) ($record['customer'] ?? ($record['customer_name'] ?? '')),
        'project' => (string) ($record['project'] ?? ($record['project_reference'] ?? '')),
        'capacity' => isset($record['capacity']) ? (float) $record['capacity'] : (isset($record['capacity_kw']) ? (float) $record['capacity_kw'] : null),
        'stage' => $stage,
        'stageLabel' => function_exists('installation_stage_label') ? installation_stage_label($stage) : ucfirst($stage),
        'stageTone' => $stageTone,
        'progress' => $progress,
        'entries' => $entries,
        'amcCommitted' => !empty($record['amc_committed']),
        'scheduled' => (string) ($record['scheduled'] ?? ($record['scheduled_date'] ?? '')),
        'handover' => (string) ($record['handover'] ?? ($record['handover_date'] ?? '')),
        'employeeName' => file_installation_actor_name(isset($record['assigned_to']) ? (int) $record['assigned_to'] : null),
        'installerName' => file_installation_actor_name(isset($record['installer_id']) ? (int) $record['installer_id'] : null),
        'requestedStage' => $requestedStage,
        'requestedByName' => file_installation_actor_name(isset($record['requested_by']) ? (int) $record['requested_by'] : null),
        'stageOptions' => $stageOptions,
        'stageLocked' => $requestedStage !== '' && $requestedStage !== $stage,
        'updated' => (string) ($record['updated_at'] ?? ''),
        'created' => (string) ($record['created_at'] ?? ''),
        'status' => strtolower((string) ($record['status'] ?? 'in_progress')),
    ];
}

function file_installation_list_for_role(string $role, ?int $userId = null): array
{
    $roleKey = strtolower(trim($role));
    $userId = $userId > 0 ? $userId : null;
    $rows = [];

    foreach (file_installation_store_all() as $record) {
        if ($roleKey === 'employee' && $userId !== null && (int) ($record['assigned_to'] ?? 0) !== $userId) {
            continue;
        }
        if ($roleKey === 'installer' && $userId !== null && (int) ($record['installer_id'] ?? 0) !== $userId) {
            continue;
        }

        $rows[] = file_installation_normalize($record, $roleKey);
    }

    usort($rows, static function (array $left, array $right): int {
        $lhs = (string) ($left['updated'] !== '' ? $left['updated'] : $left['created']);
        $rhs = (string) ($right['updated'] !== '' ? $right['updated'] : $right['created']);
        $stageWeight = static fn (array $row): int => strtolower((string) ($row['stage'] ?? '')) === 'commissioned' ? 1 : 0;
        $compareStage = $stageWeight($left) <=> $stageWeight($right);
        if ($compareStage !== 0) {
            return $compareStage;
        }
        return strcmp($rhs, $lhs);
    });

    return $rows;
}

function file_installation_append_entry(array $record, array $entry): array
{
    if (!isset($record['stage_entries']) || !is_array($record['stage_entries'])) {
        $record['stage_entries'] = [];
    }

    $record['stage_entries'][] = $entry;

    return $record;
}

function file_installation_stage_transition_allowed(string $role, string $currentStage, string $targetStage): bool
{
    $currentIndex = function_exists('installation_stage_index') ? installation_stage_index($currentStage) : 0;
    $targetIndex = function_exists('installation_stage_index') ? installation_stage_index($targetStage) : 0;
    $maxIndex = function_exists('installation_stage_index') ? installation_stage_index(function_exists('installation_stage_max_for_role') ? installation_stage_max_for_role($role) : 'meter') : 3;

    if ($targetIndex > $maxIndex) {
        return false;
    }
    if ($targetIndex < $currentIndex && $role !== 'admin') {
        return false;
    }
    if ($targetIndex === $currentIndex) {
        return true;
    }

    $allowed = function_exists('installation_role_allowed_stage_updates')
        ? installation_role_allowed_stage_updates($role)
        : ['structure', 'wiring', 'testing', 'meter', 'commissioned'];

    return in_array($targetStage, $allowed, true);
}

function file_installation_update_stage(int $installationId, string $targetStage, int $actorId, string $role, string $remarks = '', string $photoLabel = ''): array
{
    $target = strtolower(trim($targetStage));
    $roleKey = strtolower(trim($role));
    $validStages = function_exists('installation_stage_keys') ? installation_stage_keys() : ['structure', 'wiring', 'testing', 'meter', 'commissioned'];
    if (!in_array($target, $validStages, true)) {
        throw new RuntimeException('Unsupported installation stage.');
    }

    $record = file_installation_find($installationId);
    $currentStage = strtolower((string) ($record['stage'] ?? 'structure'));
    $timestamp = portal_file_now();
    $actor = file_portal_actor_details($actorId);

    if ($target === 'commissioned' && $roleKey !== 'admin') {
        if (!file_installation_stage_transition_allowed($roleKey, $currentStage, $target)) {
            throw new RuntimeException('You are not permitted to request commissioning for this project yet.');
        }

        $record['requested_stage'] = 'commissioned';
        $record['requested_by'] = $actor['id'];
        $record = file_installation_append_entry($record, [
            'id' => portal_file_uuid(),
            'stage' => $target,
            'type' => 'request',
            'remarks' => $remarks,
            'photo' => $photoLabel,
            'actorId' => $actor['id'],
            'actorName' => $actor['name'],
            'actorRole' => $actor['role'],
            'timestamp' => $timestamp,
        ]);
        $record['updated_at'] = $timestamp;

        file_installation_save($record);

        return file_installation_normalize($record, $roleKey);
    }

    if (!file_installation_stage_transition_allowed($roleKey, $currentStage, $target)) {
        throw new RuntimeException('Stage update not permitted for this role.');
    }

    $record['stage'] = $target;
    if ($target === 'commissioned') {
        $record['status'] = 'completed';
        $record['requested_stage'] = '';
        $record['requested_by'] = null;
    } elseif ($record['status'] === 'completed') {
        $record['status'] = 'in_progress';
    }

    if ($roleKey === 'admin') {
        $record['requested_stage'] = '';
        $record['requested_by'] = null;
    }

    $record = file_installation_append_entry($record, [
        'id' => portal_file_uuid(),
        'stage' => $target,
        'type' => $target === 'commissioned' ? 'approval' : 'update',
        'remarks' => $remarks,
        'photo' => $photoLabel,
        'actorId' => $actor['id'],
        'actorName' => $actor['name'],
        'actorRole' => $actor['role'],
        'timestamp' => $timestamp,
    ]);

    $record['updated_at'] = $timestamp;

    file_installation_save($record);

    return file_installation_normalize($record, $roleKey);
}

function file_installation_approve_commissioning(int $installationId, int $actorId, string $remarks = ''): array
{
    $record = file_installation_find($installationId);
    if (strtolower((string) ($record['requested_stage'] ?? '')) !== 'commissioned') {
        throw new RuntimeException('No commissioning request pending.');
    }

    return file_installation_update_stage($installationId, 'commissioned', $actorId, 'admin', $remarks);
}

function file_installation_toggle_amc(int $installationId, bool $committed, int $actorId): array
{
    $record = file_installation_find($installationId);
    $record['amc_committed'] = $committed;
    $record['updated_at'] = portal_file_now();
    $actor = file_portal_actor_details($actorId);

    $record = file_installation_append_entry($record, [
        'id' => portal_file_uuid(),
        'stage' => strtolower((string) ($record['stage'] ?? 'structure')),
        'type' => 'note',
        'remarks' => $committed ? 'AMC commitment captured.' : 'AMC commitment removed.',
        'photo' => '',
        'actorId' => $actor['id'],
        'actorName' => $actor['name'],
        'actorRole' => $actor['role'],
        'timestamp' => $record['updated_at'],
    ]);

    file_installation_save($record);

    return file_installation_normalize($record, 'admin');
}

function file_admin_list_installations(string $filter = 'ongoing'): array
{
    $filter = strtolower(trim($filter));
    $records = [];

    foreach (file_installation_store_all() as $record) {
        $stage = strtolower((string) ($record['stage'] ?? 'structure'));
        $status = strtolower((string) ($record['status'] ?? 'in_progress'));
        $requested = strtolower((string) ($record['requested_stage'] ?? ''));

        $include = match ($filter) {
            'structure', 'wiring', 'testing', 'meter', 'commissioned' => $stage === $filter,
            'on_hold', 'cancelled' => $status === $filter,
            'pending_commissioned' => $requested === 'commissioned' && $stage !== 'commissioned',
            'ongoing' => $stage !== 'commissioned' && $status !== 'cancelled',
            'all' => true,
            default => true,
        };

        if (!$include) {
            continue;
        }

        $records[] = file_installation_normalize($record, 'admin');
    }

    usort($records, static function (array $left, array $right): int {
        $lhs = (string) ($left['updated'] !== '' ? $left['updated'] : $left['created']);
        $rhs = (string) ($right['updated'] !== '' ? $right['updated'] : $right['created']);
        $stageWeight = static fn (array $row): int => strtolower((string) ($row['stage'] ?? '')) === 'commissioned' ? 1 : 0;
        $compareStage = $stageWeight($left) <=> $stageWeight($right);
        if ($compareStage !== 0) {
            return $compareStage;
        }
        return strcmp($rhs, $lhs);
    });

    return $records;
}
