<?php
declare(strict_types=1);

/**
 * Shared filesystem-backed task storage utilities used by admin and employee dashboards.
 */

function tasks_storage_dir(): string
{
    return dirname(__DIR__) . '/data/tasks';
}

function tasks_file_path(): string
{
    return tasks_storage_dir() . '/tasks.json';
}

function tasks_timezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Kolkata');
}

function tasks_ensure_directory(?string &$error = null): bool
{
    $error = null;
    $dir = tasks_storage_dir();

    if (is_dir($dir)) {
        return true;
    }

    if (@mkdir($dir, 0775, true) || is_dir($dir)) {
        return true;
    }

    $error = 'Could not create task storage directory at ' . $dir;
    return false;
}

/**
 * @return array<int, array<string, mixed>>
 */
function tasks_load_all(?string &$error = null): array
{
    $error = null;

    if (!tasks_ensure_directory($error)) {
        return [];
    }

    $path = tasks_file_path();
    if (!file_exists($path)) {
        return [];
    }

    $contents = @file_get_contents($path);
    if ($contents === false) {
        $error = 'Unable to read tasks from storage.';
        return [];
    }

    $contents = trim($contents);
    if ($contents === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        $error = 'Task storage is corrupted or invalid JSON.';
        return [];
    }

    return array_values(array_filter($decoded, static fn($item): bool => is_array($item)));
}

/**
 * @param array<int, array<string, mixed>> $tasks
 */
function tasks_save_all(array $tasks, ?string &$error = null): bool
{
    $error = null;

    if (!tasks_ensure_directory($error)) {
        return false;
    }

    $encoded = json_encode(array_values($tasks), JSON_PRETTY_PRINT);
    if ($encoded === false) {
        $error = 'Unable to encode tasks to JSON.';
        return false;
    }

    $path = tasks_file_path();
    $bytes = @file_put_contents($path, $encoded, LOCK_EX);
    if ($bytes === false) {
        $error = 'Could not write to task storage at ' . $path;
        return false;
    }

    return true;
}

function tasks_generate_id(): string
{
    return 'tsk_' . bin2hex(random_bytes(4));
}

function tasks_parse_date(string $value, ?DateTimeImmutable $fallback = null, ?DateTimeZone $timezone = null): DateTimeImmutable
{
    $tz = $timezone ?? ($fallback ? $fallback->getTimezone() : tasks_timezone());
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value, $tz) ?: null;
    if ($date instanceof DateTimeImmutable) {
        return $date->setTime(0, 0);
    }

    return ($fallback ?? new DateTimeImmutable('today', $tz))->setTime(0, 0);
}

function tasks_next_due_date(string $frequency, DateTimeImmutable $currentDueDate, int $customDays): DateTimeImmutable
{
    switch ($frequency) {
        case 'daily':
            return $currentDueDate->modify('+1 day');
        case 'weekly':
            return $currentDueDate->modify('+7 days');
        case 'monthly':
            return $currentDueDate->modify('+1 month');
        case 'custom':
            $days = max($customDays, 1);
            return $currentDueDate->modify('+' . $days . ' days');
        case 'once':
        default:
            return $currentDueDate;
    }
}

function tasks_effective_due_date(array $task, DateTimeImmutable $today): ?DateTimeImmutable
{
    $timezone = $today->getTimezone();
    $nextDue = (string) ($task['next_due_date'] ?? '');
    $due = (string) ($task['due_date'] ?? '');

    if ($nextDue !== '') {
        return tasks_parse_date($nextDue, $today, $timezone);
    }
    if ($due !== '') {
        return tasks_parse_date($due, $today, $timezone);
    }

    return null;
}

function tasks_completed_in_week(array $task, DateTimeImmutable $today): bool
{
    $tz = $today->getTimezone();
    $weekStart = $today->setTime(0, 0)->modify('monday this week');
    $weekEnd = $weekStart->modify('+7 days');

    $candidates = is_array($task['completion_log'] ?? null) ? $task['completion_log'] : [];
    if ($candidates === [] && !empty($task['last_completed_at'])) {
        $candidates[] = ['completed_at' => $task['last_completed_at']];
    }

    foreach ($candidates as $entry) {
        $completedAtRaw = (string) ($entry['completed_at'] ?? '');
        if ($completedAtRaw === '') {
            continue;
        }

        try {
            $completedAt = new DateTimeImmutable($completedAtRaw, $tz);
        } catch (Throwable $exception) {
            continue;
        }

        $completedAt = $completedAt->setTimezone($tz);
        if ($completedAt >= $weekStart && $completedAt < $weekEnd) {
            return true;
        }
    }

    return false;
}

function tasks_frequency_label(array $task): string
{
    $frequency = strtolower((string) ($task['frequency_type'] ?? 'once'));
    $customDays = (int) ($task['frequency_custom_days'] ?? 0);
    switch ($frequency) {
        case 'daily':
            return 'Daily';
        case 'weekly':
            return 'Weekly';
        case 'monthly':
            return 'Monthly';
        case 'custom':
            return 'Every ' . max($customDays, 1) . ' days';
        case 'once':
        default:
            return 'Once';
    }
}
