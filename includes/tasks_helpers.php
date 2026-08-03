<?php
declare(strict_types=1);

const TASKS_TIMEZONE = 'Asia/Kolkata';

/**
 * Return the absolute filesystem path for the tasks.json file.
 */
function tasks_data_path(): string
{
    return __DIR__ . '/../data/tasks/tasks.json';
}

/**
 * @return string Absolute path to the tasks data directory.
 */
function tasks_data_dir(): string
{
    return dirname(tasks_data_path());
}

/**
 * Create the tasks storage folder and JSON file if they do not exist.
 */
function ensure_tasks_storage(): bool
{
    $GLOBALS['tasks_last_error'] = null;

    $dir = tasks_data_dir();
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            $GLOBALS['tasks_last_error'] = 'Could not create tasks directory: ' . $dir;
            return false;
        }
    }

    $path = tasks_data_path();
    if (!file_exists($path)) {
        $initial = json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($path, $initial, LOCK_EX) === false) {
            $GLOBALS['tasks_last_error'] = 'Could not create tasks file: ' . $path;
            return false;
        }
    }

    return true;
}

/**
 * Read tasks from disk, tolerating corrupt files by returning an empty list.
 *
 * @return array<int, array<string, mixed>>
 */
function load_tasks(): array
{
    ensure_tasks_storage();

    $path = tasks_data_path();
    $contents = @file_get_contents($path);
    if ($contents === false) {
        return [];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return [];
    }

    return $decoded;
}

/**
 * Persist tasks to disk with an exclusive lock.
 *
 * @param array<int, array<string, mixed>> $tasks
 */
function save_tasks(array $tasks): bool
{
    $GLOBALS['tasks_last_error'] = null;

    if (!ensure_tasks_storage()) {
        if (!is_string($GLOBALS['tasks_last_error'] ?? null) || $GLOBALS['tasks_last_error'] === '') {
            $GLOBALS['tasks_last_error'] = 'Tasks storage is not writable.';
        }
        return false;
    }

    $encoded = json_encode(array_values($tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        $GLOBALS['tasks_last_error'] = 'Failed to encode tasks payload.';
        return false;
    }

    $result = @file_put_contents(tasks_data_path(), $encoded, LOCK_EX);
    if ($result === false) {
        $error = error_get_last();
        $GLOBALS['tasks_last_error'] = is_array($error) ? ($error['message'] ?? 'Unknown error writing tasks.') : 'Unknown error writing tasks.';
        return false;
    }

    return true;
}

function tasks_last_error(): string
{
    return is_string($GLOBALS['tasks_last_error'] ?? null) ? (string) $GLOBALS['tasks_last_error'] : '';
}

function generate_task_id(): string
{
    $stamp = (new DateTimeImmutable('now', new DateTimeZone(TASKS_TIMEZONE)))->format('YmdHis');
    $rand = bin2hex(random_bytes(4));
    return 'tsk_' . $stamp . '_' . $rand;
}

function compute_next_due_date(string $frequencyType, string $startOrCurrentDate, int $customDays): string
{
    $tz = new DateTimeZone(TASKS_TIMEZONE);
    $baseDate = DateTimeImmutable::createFromFormat('Y-m-d', $startOrCurrentDate, $tz);
    if ($baseDate === false) {
        $baseDate = new DateTimeImmutable('today', $tz);
    }

    $frequency = strtolower(trim($frequencyType));
    switch ($frequency) {
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
            $days = max(1, $customDays);
            $next = $baseDate->modify('+' . $days . ' days');
            break;
        case 'once':
        default:
            $next = $baseDate;
            break;
    }

    return $next->format('Y-m-d');
}

/**
 * @param array<string, mixed> $task
 */
function get_effective_due_date(array $task): string
{
    $frequency = strtolower((string) ($task['frequency_type'] ?? 'once'));
    if ($frequency === 'once') {
        return (string) ($task['due_date'] ?? '');
    }

    return (string) ($task['next_due_date'] ?? '');
}

/**
 * @param array<string, mixed> $task
 */
function is_overdue(array $task, string $today): bool
{
    $due = get_effective_due_date($task);
    if ($due === '') {
        return false;
    }

    return strcmp($due, $today) < 0;
}

/**
 * @param array<string, mixed> $task
 */
function is_due_today(array $task, string $today): bool
{
    $due = get_effective_due_date($task);
    if ($due === '') {
        return false;
    }

    return strcmp($due, $today) === 0;
}

/**
 * Return Monday-Sunday dates for the current week in Asia/Kolkata.
 *
 * @return array{0:string,1:string}
 */
function week_range_dates(): array
{
    $tz = new DateTimeZone(TASKS_TIMEZONE);
    $today = new DateTimeImmutable('today', $tz);

    $monday = $today->modify('monday this week');
    $sunday = $monday->modify('sunday this week');

    return [$monday->format('Y-m-d'), $sunday->format('Y-m-d')];
}

function tasks_now_timestamp(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(TASKS_TIMEZONE)))->format('Y-m-d H:i:s');
}

function tasks_today_date(): string
{
    return (new DateTimeImmutable('today', new DateTimeZone(TASKS_TIMEZONE)))->format('Y-m-d');
}
