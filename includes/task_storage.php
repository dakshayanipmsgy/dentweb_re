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

function tasks_ensure_directory(): void
{
    $dir = tasks_storage_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function tasks_load_all(): array
{
    tasks_ensure_directory();
    $path = tasks_file_path();
    if (!file_exists($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return [];
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<int, array<string, mixed>> $tasks
 */
function tasks_save_all(array $tasks): void
{
    tasks_ensure_directory();
    $path = tasks_file_path();
    file_put_contents($path, json_encode(array_values($tasks), JSON_PRETTY_PRINT));
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

