<?php
declare(strict_types=1);

/**
 * Shared filesystem-backed task storage utilities used by admin and employee dashboards.
 */

function tasks_storage_dir(): string
{
    return __DIR__ . '/../data/tasks';
}

function tasks_logs_dir(): string
{
    return __DIR__ . '/../data/logs';
}

function tasks_file_path(): string
{
    return tasks_storage_dir() . '/tasks.json';
}

function tasks_log_file_path(): string
{
    return tasks_logs_dir() . '/tasks.log';
}

function tasks_ensure_directory(): bool
{
    $dir = tasks_storage_dir();
    if (is_dir($dir)) {
        return true;
    }

    if (@mkdir($dir, 0775, true)) {
        return true;
    }

    tasks_log_event('storage', 'error', 'Unable to create tasks storage directory: ' . $dir);
    return false;
}

function tasks_ensure_logs_directory(): bool
{
    $dir = tasks_logs_dir();
    if (is_dir($dir)) {
        return true;
    }

    if (@mkdir($dir, 0775, true)) {
        return true;
    }

    return false;
}

function tasks_log_event(string $action, string $result, string $message = ''): void
{
    if (!tasks_ensure_logs_directory()) {
        return;
    }

    $line = sprintf(
        '[%s] action=%s result=%s message=%s',
        date('Y-m-d H:i:s'),
        $action,
        $result,
        trim(preg_replace('/\s+/', ' ', $message))
    );

    @file_put_contents(tasks_log_file_path(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * @return array<int, array<string, mixed>>
 */
function tasks_load_all(?array &$warnings = null): array
{
    if ($warnings === null) {
        $warnings = [];
    }

    tasks_ensure_directory();
    tasks_ensure_logs_directory();

    $path = tasks_file_path();
    if (!file_exists($path)) {
        return [];
    }

    if (!is_readable($path)) {
        $warnings[] = 'Tasks file is not readable: ' . $path;
        tasks_log_event('load', 'error', 'Tasks file is not readable: ' . $path);
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        $warnings[] = 'Failed to read tasks file.';
        tasks_log_event('load', 'error', 'Failed to read tasks file at ' . $path);
        return [];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        $backupPath = $path . '.bak_' . date('Ymd_His');
        $renamed = @rename($path, $backupPath);
        if (!$renamed) {
            @copy($path, $backupPath);
        }

        $warnings[] = 'Tasks file was corrupted; backed up and recreated.';
        tasks_log_event('load', 'corrupt', sprintf('Tasks file invalid JSON; backup created at %s', $backupPath));

        $saveErrors = [];
        tasks_save_all([], $saveErrors);
        if ($saveErrors !== []) {
            $warnings = array_merge($warnings, $saveErrors);
        }

        return [];
    }

    return array_values($decoded);
}

/**
 * @param array<int, array<string, mixed>> $tasks
 */
function tasks_save_all(array $tasks, ?array &$errors = null): bool
{
    if ($errors === null) {
        $errors = [];
    }

    $storageReady = tasks_ensure_directory();
    tasks_ensure_logs_directory();

    $storageDir = tasks_storage_dir();
    if (!$storageReady || !is_writable($storageDir)) {
        $errors[] = 'Folder permissions issue: ' . $storageDir . ' is not writable.';
        tasks_log_event('save', 'error', end($errors));
        return false;
    }

    $payload = json_encode(array_values($tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        $errors[] = 'Failed to encode tasks as JSON.';
        tasks_log_event('save', 'error', 'Failed to encode tasks as JSON');
        return false;
    }

    $path = tasks_file_path();
    $result = @file_put_contents($path, $payload, LOCK_EX);
    if ($result === false) {
        $errors[] = 'Failed to save tasks file (permission issue).';
        $lastError = error_get_last();
        $lastMessage = is_array($lastError) ? ($lastError['message'] ?? '') : '';
        tasks_log_event('save', 'error', 'Failed to write tasks file: ' . $lastMessage);
        return false;
    }

    tasks_log_event('save', 'success', sprintf('Saved %d tasks to %s', count($tasks), $path));
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
