<?php
declare(strict_types=1);

/**
 * Shared filesystem-backed task storage utilities used by admin and employee dashboards.
 */

function tasks_storage_dir(): string
{
    return __DIR__ . '/../data/tasks';
}

function tasks_file_path(): string
{
    return tasks_storage_dir() . '/tasks.json';
}

function tasks_ensure_directory(?array &$errors = null): bool
{
    $dir = tasks_storage_dir();
    if (is_dir($dir)) {
        if (!is_writable($dir)) {
            $errors[] = sprintf('Tasks directory exists but is not writable: %s', $dir);
            return false;
        }
        return true;
    }

    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        $errors[] = sprintf('Unable to create tasks directory at %s', $dir);
        return false;
    }

    return true;
}

/**
 * @return array<int, array<string, mixed>>
 */
function tasks_load_all(?array &$errors = null): array
{
    $errors = $errors ?? [];
    if (!tasks_ensure_directory($errors)) {
        return [];
    }

    $path = tasks_file_path();
    if (!file_exists($path)) {
        return [];
    }

    if (!is_readable($path)) {
        $errors[] = sprintf('Tasks file is not readable: %s', $path);
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        $errors[] = sprintf('Unable to read tasks file: %s', $path);
        return [];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        $errors[] = sprintf('Tasks file contained invalid JSON; path: %s', $path);
        return [];
    }

    return array_values($decoded);
}

/**
 * @param array<int, array<string, mixed>> $tasks
 */
function tasks_save_all(array $tasks, ?array &$errors = null): bool
{
    $errors = $errors ?? [];
    if (!tasks_ensure_directory($errors)) {
        return false;
    }

    $path = tasks_file_path();
    $dir = dirname($path);

    if (!is_dir($dir) || !is_writable($dir)) {
        $errors[] = sprintf(
            'Tasks directory is not writable: %s (exists: %s, writable: %s)',
            $dir,
            is_dir($dir) ? 'yes' : 'no',
            is_writable($dir) ? 'yes' : 'no'
        );
        return false;
    }

    $payload = json_encode(array_values($tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        $errors[] = 'Failed to encode tasks to JSON.';
        return false;
    }

    $bytesWritten = file_put_contents($path, $payload, LOCK_EX);
    if ($bytesWritten === false) {
        $errors[] = sprintf(
            'Failed to write tasks to %s (file writable: %s)',
            $path,
            file_exists($path) ? (is_writable($path) ? 'yes' : 'no') : 'no'
        );
        return false;
    }

    return true;
}

function tasks_generate_id(): string
{
    return 'tsk_' . bin2hex(random_bytes(4));
}

function tasks_parse_date(string $value, ?DateTimeImmutable $fallback = null): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value) ?: null;
    if ($date instanceof DateTimeImmutable) {
        return $date;
    }

    return $fallback ?? new DateTimeImmutable('today');
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

function tasks_effective_due_date(array $task, DateTimeImmutable $referenceDate): DateTimeImmutable
{
    $frequency = strtolower((string) ($task['frequency_type'] ?? 'once'));
    $dueDateRaw = (string) ($task['due_date'] ?? '');
    $nextDueRaw = (string) ($task['next_due_date'] ?? '');

    $dateString = $frequency === 'once'
        ? ($dueDateRaw !== '' ? $dueDateRaw : $nextDueRaw)
        : ($nextDueRaw !== '' ? $nextDueRaw : ($dueDateRaw !== '' ? $dueDateRaw : ''));

    return $dateString !== '' ? tasks_parse_date($dateString, $referenceDate) : $referenceDate;
}

function tasks_recent_completion_count(array $task, DateTimeImmutable $cutoffDate, DateTimeImmutable $endDate): int
{
    $logEntries = is_array($task['completion_log'] ?? null) ? $task['completion_log'] : [];
    $count = 0;

    foreach ($logEntries as $entry) {
        $completedAtRaw = (string) ($entry['completed_at'] ?? '');
        if ($completedAtRaw === '') {
            continue;
        }
        try {
            $completedAt = new DateTimeImmutable($completedAtRaw);
        } catch (Throwable $exception) {
            continue;
        }

        if ($completedAt >= $cutoffDate && $completedAt <= $endDate) {
            $count++;
        }
    }

    return $count;
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
