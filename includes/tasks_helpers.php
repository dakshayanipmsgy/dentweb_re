<?php
declare(strict_types=1);

const TASKS_TIMEZONE = 'Asia/Kolkata';
const TASK_WORKFLOW_STATUSES = [
    'assigned',
    'acknowledged',
    'in_progress',
    'blocked',
    'submitted',
    'completed',
    'cancelled',
];

function tasks_data_path(): string
{
    return __DIR__ . '/../data/tasks/tasks.json';
}

function tasks_data_dir(): string
{
    return dirname(tasks_data_path());
}

function tasks_lock_path(): string
{
    return tasks_data_dir() . '/tasks.lock';
}

function ensure_tasks_storage(): bool
{
    $GLOBALS['tasks_last_error'] = null;
    $dir = tasks_data_dir();

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        $GLOBALS['tasks_last_error'] = 'Could not create tasks directory: ' . $dir;
        return false;
    }

    $path = tasks_data_path();
    if (!file_exists($path)) {
        $initial = json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($initial === false || file_put_contents($path, $initial, LOCK_EX) === false) {
            $GLOBALS['tasks_last_error'] = 'Could not create tasks file: ' . $path;
            return false;
        }
    }

    return true;
}

function tasks_last_error(): string
{
    return is_string($GLOBALS['tasks_last_error'] ?? null) ? (string) $GLOBALS['tasks_last_error'] : '';
}

/** @return array<int, array<string, mixed>> */
function tasks_read_unlocked(): array
{
    $contents = @file_get_contents(tasks_data_path());
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        $GLOBALS['tasks_last_error'] = 'Tasks data is not valid JSON.';
        return [];
    }

    return array_values(array_map('task_normalize', array_filter($decoded, 'is_array')));
}

/** @return array<int, array<string, mixed>> */
function load_tasks(): array
{
    if (!ensure_tasks_storage()) {
        return [];
    }

    return tasks_read_unlocked();
}

/** @param array<int, array<string, mixed>> $tasks */
function tasks_write_unlocked(array $tasks): bool
{
    $GLOBALS['tasks_last_error'] = null;
    $normalized = array_values(array_map('task_normalize', $tasks));
    $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        $GLOBALS['tasks_last_error'] = 'Failed to encode tasks payload.';
        return false;
    }

    $path = tasks_data_path();
    $tempPath = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tempPath, $encoded, LOCK_EX) === false) {
        $GLOBALS['tasks_last_error'] = 'Could not write temporary tasks file.';
        return false;
    }

    if (!@rename($tempPath, $path)) {
        @unlink($tempPath);
        $GLOBALS['tasks_last_error'] = 'Could not replace tasks file.';
        return false;
    }

    return true;
}

/** @param array<int, array<string, mixed>> $tasks */
function save_tasks(array $tasks): bool
{
    if (!ensure_tasks_storage()) {
        return false;
    }

    $lock = @fopen(tasks_lock_path(), 'c+');
    if ($lock === false) {
        $GLOBALS['tasks_last_error'] = 'Could not open task lock file.';
        return false;
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            $GLOBALS['tasks_last_error'] = 'Could not lock tasks storage.';
            return false;
        }
        return tasks_write_unlocked($tasks);
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

/**
 * Safely read-modify-write the task collection under one exclusive lock.
 * The callback receives the task list by reference and may modify it.
 */
function tasks_mutate(callable $callback): bool
{
    if (!ensure_tasks_storage()) {
        return false;
    }

    $lock = @fopen(tasks_lock_path(), 'c+');
    if ($lock === false) {
        $GLOBALS['tasks_last_error'] = 'Could not open task lock file.';
        return false;
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            $GLOBALS['tasks_last_error'] = 'Could not lock tasks storage.';
            return false;
        }

        $tasks = tasks_read_unlocked();
        $callback($tasks);
        return tasks_write_unlocked($tasks);
    } catch (Throwable $exception) {
        $GLOBALS['tasks_last_error'] = $exception->getMessage();
        return false;
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function generate_task_id(): string
{
    $stamp = (new DateTimeImmutable('now', new DateTimeZone(TASKS_TIMEZONE)))->format('YmdHis');
    return 'tsk_' . $stamp . '_' . bin2hex(random_bytes(4));
}

function generate_task_event_id(): string
{
    return 'evt_' . bin2hex(random_bytes(6));
}

function tasks_now_timestamp(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(TASKS_TIMEZONE)))->format('Y-m-d H:i:s');
}

function tasks_today_date(): string
{
    return (new DateTimeImmutable('today', new DateTimeZone(TASKS_TIMEZONE)))->format('Y-m-d');
}

function tasks_valid_date(string $value, string $fallback = ''): string
{
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(TASKS_TIMEZONE));
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : $fallback;
}

function compute_next_due_date(string $frequencyType, string $startOrCurrentDate, int $customDays): string
{
    $tz = new DateTimeZone(TASKS_TIMEZONE);
    $baseDate = DateTimeImmutable::createFromFormat('!Y-m-d', $startOrCurrentDate, $tz);
    if ($baseDate === false) {
        $baseDate = new DateTimeImmutable('today', $tz);
    }

    switch (strtolower(trim($frequencyType))) {
        case 'daily':
            $next = $baseDate->modify('+1 day');
            break;
        case 'weekly':
            $next = $baseDate->modify('+7 days');
            break;
        case 'monthly':
            $next = $baseDate->add(new DateInterval('P1M'));
            break;
        case 'custom':
            $next = $baseDate->modify('+' . max(1, $customDays) . ' days');
            break;
        default:
            $next = $baseDate;
    }

    return $next->format('Y-m-d');
}

function compute_next_due_after_completion(string $frequencyType, string $currentDueDate, int $customDays): string
{
    $today = tasks_today_date();
    $base = tasks_valid_date($currentDueDate, $today);
    if (strcmp($base, $today) < 0) {
        $base = $today;
    }
    return compute_next_due_date($frequencyType, $base, $customDays);
}

/** @param array<string, mixed> $task */
function task_normalize(array $task): array
{
    $now = tasks_now_timestamp();
    $legacyStatus = strtolower(trim((string) ($task['status'] ?? 'open'));
    $workflow = strtolower(trim((string) ($task['workflow_status'] ?? '')));

    if (!in_array($workflow, TASK_WORKFLOW_STATUSES, true)) {
        $workflow = $legacyStatus === 'completed' ? 'completed' : 'assigned';
    }

    $task['id'] = trim((string) ($task['id'] ?? '')) ?: generate_task_id();
    $task['title'] = trim((string) ($task['title'] ?? 'Untitled task'));
    $task['description'] = trim((string) ($task['description'] ?? ''));
    $task['expected_outcome'] = trim((string) ($task['expected_outcome'] ?? ''));
    $task['category'] = trim((string) ($task['category'] ?? 'General')) ?: 'General';
    $task['project_reference'] = trim((string) ($task['project_reference'] ?? ''));
    $task['priority'] = ucfirst(strtolower(trim((string) ($task['priority'] ?? 'Medium'))));
    if (!in_array(strtolower($task['priority']), ['low', 'medium', 'high', 'urgent'], true)) {
        $task['priority'] = 'Medium';
    }

    $task['created_by_type'] = (string) ($task['created_by_type'] ?? 'admin');
    $task['created_by_id'] = (string) ($task['created_by_id'] ?? '');
    $task['created_by_name'] = trim((string) ($task['created_by_name'] ?? ''));
    $task['assigned_to_id'] = (string) ($task['assigned_to_id'] ?? '');
    $task['assigned_to_name'] = trim((string) ($task['assigned_to_name'] ?? ''));
    $task['assigned_by_name'] = trim((string) ($task['assigned_by_name'] ?? $task['created_by_name']));

    $frequency = strtolower((string) ($task['frequency_type'] ?? 'once'));
    if (!in_array($frequency, ['once', 'daily', 'weekly', 'monthly', 'custom'], true)) {
        $frequency = 'once';
    }
    $task['frequency_type'] = $frequency;
    $task['custom_every_n_days'] = max(0, (int) ($task['custom_every_n_days'] ?? 0));
    $task['start_date'] = tasks_valid_date((string) ($task['start_date'] ?? ''), tasks_today_date());
    $task['due_date'] = $frequency === 'once' ? tasks_valid_date((string) ($task['due_date'] ?? ''), $task['start_date']) : '';
    $task['next_due_date'] = $frequency !== 'once' ? tasks_valid_date((string) ($task['next_due_date'] ?? ''), $task['start_date']) : '';

    $task['workflow_status'] = $workflow;
    $task['status'] = in_array($workflow, ['completed', 'cancelled'], true) ? 'Completed' : 'Open';
    $task['attention_owner'] = strtolower((string) ($task['attention_owner'] ?? ''));
    if (!in_array($task['attention_owner'], ['admin', 'employee', 'none'], true)) {
        $task['attention_owner'] = match ($workflow) {
            'assigned' => 'employee',
            'blocked', 'submitted' => 'admin',
            'completed', 'cancelled' => 'none',
            default => 'none',
        };
    }

    $task['archived_flag'] = !empty($task['archived_flag']);
    $task['acknowledged_at'] = (string) ($task['acknowledged_at'] ?? '');
    $task['started_at'] = (string) ($task['started_at'] ?? '');
    $task['submitted_at'] = (string) ($task['submitted_at'] ?? '');
    $task['approved_at'] = (string) ($task['approved_at'] ?? '');
    $task['last_completed_at'] = (string) ($task['last_completed_at'] ?? '');
    $task['completion_log'] = is_array($task['completion_log'] ?? null) ? array_values($task['completion_log']) : [];
    $task['thread'] = is_array($task['thread'] ?? null) ? array_values($task['thread']) : [];
    $task['activity_log'] = is_array($task['activity_log'] ?? null) ? array_values($task['activity_log']) : [];
    $task['occurrence_number'] = max(1, (int) ($task['occurrence_number'] ?? 1));
    $task['created_at'] = (string) ($task['created_at'] ?? $now);
    $task['updated_at'] = (string) ($task['updated_at'] ?? $task['created_at']);
    $task['last_activity_at'] = (string) ($task['last_activity_at'] ?? $task['updated_at']);

    return $task;
}

/** @param array<string, mixed> $task */
function get_effective_due_date(array $task): string
{
    return strtolower((string) ($task['frequency_type'] ?? 'once')) === 'once'
        ? (string) ($task['due_date'] ?? '')
        : (string) ($task['next_due_date'] ?? '');
}

/** @param array<string, mixed> $task */
function task_workflow_status(array $task): string
{
    $normalized = task_normalize($task);
    return (string) $normalized['workflow_status'];
}

function task_workflow_label(string $status): string
{
    return match ($status) {
        'assigned' => 'Assigned',
        'acknowledged' => 'Acknowledged',
        'in_progress' => 'In progress',
        'blocked' => 'Blocked',
        'submitted' => 'Awaiting review',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

/** @param array<string, mixed> $task */
function task_is_active(array $task): bool
{
    return !in_array(task_workflow_status($task), ['completed', 'cancelled'], true) && empty($task['archived_flag']);
}

/** @param array<string, mixed> $task */
function is_overdue(array $task, string $today): bool
{
    if (!task_is_active($task) || task_workflow_status($task) === 'submitted') {
        return false;
    }
    $due = get_effective_due_date($task);
    return $due !== '' && strcmp($due, $today) < 0;
}

/** @param array<string, mixed> $task */
function is_due_today(array $task, string $today): bool
{
    if (!task_is_active($task)) {
        return false;
    }
    $due = get_effective_due_date($task);
    return $due !== '' && strcmp($due, $today) === 0;
}

/** @param array<string, mixed> $task */
function task_frequency_label(array $task): string
{
    return match (strtolower((string) ($task['frequency_type'] ?? 'once'))) {
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'custom' => 'Every ' . max(1, (int) ($task['custom_every_n_days'] ?? 1)) . ' days',
        default => 'One time',
    };
}

/** @param array<string, mixed> $task */
function task_add_message(array &$task, string $actorType, string $actorId, string $actorName, string $message, string $kind = 'comment'): void
{
    $message = trim($message);
    if ($message === '') {
        return;
    }

    $now = tasks_now_timestamp();
    $task['thread'][] = [
        'id' => generate_task_event_id(),
        'actor_type' => $actorType,
        'actor_id' => $actorId,
        'actor_name' => $actorName,
        'message' => $message,
        'kind' => $kind,
        'created_at' => $now,
    ];
    $task['updated_at'] = $now;
    $task['last_activity_at'] = $now;
}

/** @param array<string, mixed> $task */
function task_add_activity(array &$task, string $actorType, string $actorId, string $actorName, string $action, string $details = ''): void
{
    $now = tasks_now_timestamp();
    $task['activity_log'][] = [
        'id' => generate_task_event_id(),
        'actor_type' => $actorType,
        'actor_id' => $actorId,
        'actor_name' => $actorName,
        'action' => trim($action),
        'details' => trim($details),
        'created_at' => $now,
    ];
    $task['updated_at'] = $now;
    $task['last_activity_at'] = $now;
}

/** @param array<string, mixed> $task */
function task_set_workflow(array &$task, string $status, string $attentionOwner = 'none'): void
{
    if (!in_array($status, TASK_WORKFLOW_STATUSES, true)) {
        throw new InvalidArgumentException('Invalid task workflow status.');
    }
    if (!in_array($attentionOwner, ['admin', 'employee', 'none'], true)) {
        $attentionOwner = 'none';
    }

    $task['workflow_status'] = $status;
    $task['status'] = in_array($status, ['completed', 'cancelled'], true) ? 'Completed' : 'Open';
    $task['attention_owner'] = $attentionOwner;
    $task['updated_at'] = tasks_now_timestamp();
    $task['last_activity_at'] = $task['updated_at'];
}

/** @return array{0:string,1:string} */
function week_range_dates(): array
{
    $tz = new DateTimeZone(TASKS_TIMEZONE);
    $today = new DateTimeImmutable('today', $tz);
    $monday = $today->modify('monday this week');
    return [$monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d')];
}
