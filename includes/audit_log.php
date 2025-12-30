<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function audit_log_path(): string
{
    $base = __DIR__ . '/../storage';
    if (!is_dir($base)) {
        mkdir($base, 0775, true);
    }

    return $base . '/audit-log.jsonl';
}

function audit_current_actor(): array
{
    start_session();

    if (!empty($_SESSION['user']) && ($_SESSION['user']['role_name'] ?? '') === 'admin') {
        $user = (array) $_SESSION['user'];
        $identifier = (string) ($user['username'] ?? ($user['email'] ?? ($user['id'] ?? 'admin')));

        return ['actor_type' => 'admin', 'actor_id' => $identifier];
    }

    if (!empty($_SESSION['employee_logged_in']) && isset($_SESSION['employee_id'])) {
        $loginId = isset($_SESSION['employee_login_id']) ? (string) $_SESSION['employee_login_id'] : '';
        $identifier = $loginId !== '' ? $loginId : (string) $_SESSION['employee_id'];

        return ['actor_type' => 'employee', 'actor_id' => $identifier];
    }

    if (!empty($_SESSION['customer_logged_in']) && isset($_SESSION['customer_mobile'])) {
        return ['actor_type' => 'customer', 'actor_id' => (string) $_SESSION['customer_mobile']];
    }

    return ['actor_type' => 'system', 'actor_id' => 'system'];
}

function log_audit_event(
    string $actor_type,
    string $actor_id,
    string $entity_type,
    string $entity_key,
    string $action,
    $details = []
): void {
    $entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'actor_type' => $actor_type,
        'actor_id' => $actor_id,
        'entity_type' => $entity_type,
        'entity_key' => $entity_key,
        'action' => $action,
        'details' => $details,
    ];

    $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return;
    }

    $line = $encoded . PHP_EOL;
    $path = audit_log_path();
    file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

function audit_changed_fields(array $before, array $after, array $excludeKeys = []): array
{
    $exclude = array_fill_keys($excludeKeys, true);
    $changes = [];
    $allKeys = array_unique(array_merge(array_keys($before), array_keys($after)));
    foreach ($allKeys as $key) {
        if (isset($exclude[$key])) {
            continue;
        }
        $old = $before[$key] ?? null;
        $new = $after[$key] ?? null;
        if ($old === $new) {
            continue;
        }

        $changes[$key] = ['old' => $old, 'new' => $new];
    }

    return $changes;
}

function audit_read_recent(int $limit = 200): array
{
    $path = audit_log_path();
    if (!is_file($path)) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $lines = array_slice($lines, -1 * max(1, $limit));
    $entries = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }
        $entries[] = $decoded;
    }

    return array_reverse($entries);
}
