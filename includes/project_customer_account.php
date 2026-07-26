<?php
declare(strict_types=1);

require_once __DIR__ . '/customer_admin.php';

/** Normalize an Indian mobile number to the key used by CustomerFsStore. */
function project_customer_mobile(string $mobile): string
{
    $digits = preg_replace('/\D+/', '', $mobile) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }
    return preg_match('/^[6-9][0-9]{9}$/', $digits) === 1 ? $digits : '';
}

function project_customer_account_link(array $quote): ?array
{
    $link = $quote['customer_account_link'] ?? null;
    return is_array($link) && project_customer_mobile((string) ($link['mobile'] ?? '')) !== '' ? $link : null;
}

/** Build an account exclusively from the immutable customer snapshot/fallback quote fields. */
function project_customer_payload(array $quote, bool $completed): array
{
    $snapshot = is_array($quote['customer_snapshot'] ?? null) ? $quote['customer_snapshot'] : [];
    $pick = static function (string $key, string $fallback = '') use ($snapshot, $quote): string {
        $value = trim((string) ($snapshot[$key] ?? ''));
        return $value !== '' ? $value : trim((string) ($quote[$fallback !== '' ? $fallback : $key] ?? ''));
    };
    $segment = strtoupper(trim((string) ($quote['segment'] ?? 'RES')));
    $types = ['RES' => 'Residential', 'COM' => 'Commercial', 'IND' => 'Industrial', 'INST' => 'Institutional', 'PROD' => 'Product'];

    return [
        'mobile' => project_customer_mobile($pick('mobile', 'customer_mobile')),
        'name' => $pick('name', 'customer_name'),
        'address' => $pick('address', 'site_address'),
        'city' => $pick('city'), 'district' => $pick('district'), 'state' => $pick('state'),
        'pin_code' => $pick('pin_code', 'pin'),
        'customer_type' => $pick('customer_type') ?: ($types[$segment] ?? $segment),
        'meter_number' => $pick('meter_number'),
        'meter_serial_number' => $pick('meter_serial_number'),
        'jbvnl_account_number' => $pick('consumer_account_no', 'consumer_account_no'),
        'application_id' => $pick('application_id'),
        'application_submitted_date' => $pick('application_submitted_date'),
        'sanction_load_kwp' => $pick('sanction_load_kwp'),
        'installed_pv_module_capacity_kwp' => $pick('installed_pv_module_capacity_kwp'),
        'circle_name' => $pick('circle_name'), 'division_name' => $pick('division_name'),
        'sub_division_name' => $pick('sub_division_name'),
        'status' => $completed ? 'Completed' : 'Installation Pending',
        'welcome_sent_via' => 'none',
        'created_from_quote_id' => trim((string) ($quote['id'] ?? '')),
        'created_from_quote_no' => trim((string) ($quote['quote_no'] ?? '')),
    ];
}

/**
 * Idempotently create/find a customer, then persist the project link.
 * Callbacks make both persistence boundaries and audit behaviour testable.
 */
function project_create_customer_account(
    array $quote,
    bool $completed,
    array $actor,
    CustomerFsStore $store,
    callable $saveQuote,
    callable $audit,
    ?string $lockDir = null
): array {
    $id = trim((string) ($quote['id'] ?? ''));
    if ($id === '') return ['ok' => false, 'error' => 'Quotation ID is required.'];
    $lockDir ??= __DIR__ . '/../storage/project-customer-locks';
    if (!is_dir($lockDir) && !@mkdir($lockDir, 0775, true) && !is_dir($lockDir)) return ['ok' => false, 'error' => 'Unable to initialize account lock.'];
    $handle = @fopen($lockDir . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $id) . '.lock', 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) { if (is_resource($handle)) fclose($handle); return ['ok' => false, 'error' => 'Unable to lock this project.']; }
    try {
        $payload = project_customer_payload($quote, $completed);
        $mobile = (string) $payload['mobile'];
        if ($mobile === '') return ['ok' => false, 'error' => 'Quotation has an invalid customer mobile number.'];
        $existing = $store->findByMobile($mobile); // includes archived customers
        $created = false;
        if ($existing === null) {
            // Same secure hashing primitive as manual account setup; the random secret is never displayed or sent.
            $secret = bin2hex(random_bytes(16));
            $hash = password_hash($secret, PASSWORD_DEFAULT);
            if ($hash === false) return ['ok' => false, 'error' => 'Unable to initialize secure account access.'];
            $payload['password_hash'] = $hash;
            $added = $store->addCustomer($payload);
            if (empty($added['success'])) {
                // A competing customer-store writer may have won before its lock was acquired.
                $existing = $store->findByMobile($mobile);
                if ($existing === null) return ['ok' => false, 'error' => implode(' ', (array) ($added['errors'] ?? ['Unable to create customer.']))];
            } else { $existing = (array) $added['customer']; $created = true; }
        }
        $now = date('c');
        $link = ['mobile' => $mobile, 'customer_mobile_key' => (string) ($existing['mobile_key'] ?? $mobile), 'archived' => !empty($existing['archived']), 'linked_at' => $now, 'linked_by' => ['id' => (string) ($actor['id'] ?? ''), 'name' => (string) ($actor['name'] ?? ''), 'role' => (string) ($actor['role'] ?? 'admin')]];
        $current = project_customer_account_link($quote);
        if ($current !== null && project_customer_mobile((string) $current['mobile']) === $mobile) {
            $link = $current; // preserve the original actor and timestamp on repeat clicks
        } else {
            $quote['customer_account_link'] = $link;
            $saved = $saveQuote($quote);
            if (empty($saved['ok'])) return ['ok' => false, 'error' => 'Customer was saved, but the quotation link could not be persisted. Retry to link the existing customer.', 'customer' => $existing];
        }
        $audit($created ? 'project_customer_account_created' : 'project_customer_account_linked', $id, ['mobile' => $mobile, 'archived' => !empty($existing['archived']), 'linked_at' => (string) ($link['linked_at'] ?? $now)]);
        return ['ok' => true, 'created' => $created, 'customer' => $existing, 'link' => $link, 'url' => 'admin-users.php?tab=customers&view=' . rawurlencode($mobile)];
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}
