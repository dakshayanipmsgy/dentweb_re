<?php
declare(strict_types=1);

require_once __DIR__ . '/leads.php';

function solar_finance_reports_storage_path(): string
{
    $dir = __DIR__ . '/../data/solar_finance';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir . '/reports.json';
}

function solar_finance_load_reports(): array
{
    $path = solar_finance_reports_storage_path();
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function solar_finance_save_reports(array $reports): void
{
    $path = solar_finance_reports_storage_path();
    $encoded = json_encode(array_values($reports), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Unable to encode reports.');
    }

    file_put_contents($path, $encoded, LOCK_EX);
}

function solar_finance_normalize_mobile(string $mobile): string
{
    $digits = preg_replace('/\D+/', '', $mobile) ?? '';
    if ($digits === '') {
        return '';
    }

    $core = '';
    if (preg_match('/^0\d{10}$/', $digits) === 1) {
        $core = substr($digits, 1);
    } elseif (preg_match('/^91\d{10}$/', $digits) === 1) {
        $core = substr($digits, 2);
    } elseif (preg_match('/^\d{10}$/', $digits) === 1) {
        $core = $digits;
    }

    if (preg_match('/^\d{10}$/', $core) !== 1) {
        return '';
    }

    return '91' . $core;
}

function solar_finance_mobile_key(string $mobile): string
{
    $normalized = solar_finance_normalize_mobile($mobile);
    if ($normalized !== '') {
        return substr($normalized, -10);
    }

    $digits = preg_replace('/\D+/', '', $mobile) ?? '';
    if (preg_match('/^0\d{10}$/', $digits) === 1) {
        return substr($digits, -10);
    }
    if (preg_match('/^91\d{10}$/', $digits) === 1) {
        return substr($digits, -10);
    }
    if (preg_match('/^\d{10}$/', $digits) === 1) {
        return $digits;
    }

    return $digits;
}

function solar_finance_generate_report_id(): string
{
    return 'sfr_' . bin2hex(random_bytes(6));
}

function solar_finance_generate_report_token(): string
{
    return bin2hex(random_bytes(24));
}

function solar_finance_create_report(array $payload): array
{
    $reports = solar_finance_load_reports();
    $now = date('Y-m-d H:i:s');

    $record = [
        'report_id' => solar_finance_generate_report_id(),
        'public_token' => solar_finance_generate_report_token(),
        'customer_name' => trim((string) ($payload['customer']['name'] ?? '')),
        'location' => trim((string) ($payload['customer']['location'] ?? '')),
        'mobile' => solar_finance_normalize_mobile((string) ($payload['customer']['mobile_normalized'] ?? ($payload['customer']['mobile_raw'] ?? ''))),
        'input_snapshot' => is_array($payload['inputs'] ?? null) ? $payload['inputs'] : [],
        'result_snapshot' => is_array($payload['results'] ?? null) ? $payload['results'] : [],
        'charts_images' => is_array($payload['charts_images'] ?? null) ? $payload['charts_images'] : [],
        'created_at' => $now,
        'generated_at' => $now,
        'source' => 'Solar and Finance',
        'created_by' => 'public_calculator',
    ];

    $reports[] = $record;
    solar_finance_save_reports($reports);

    return $record;
}

function solar_finance_find_report(?string $token, ?string $reportId): ?array
{
    $token = trim((string) $token);
    $reportId = trim((string) $reportId);

    foreach (solar_finance_load_reports() as $report) {
        if ($token !== '' && hash_equals((string) ($report['public_token'] ?? ''), $token)) {
            return $report;
        }
        if ($token === '' && $reportId !== '' && (string) ($report['report_id'] ?? '') === $reportId) {
            return $report;
        }
    }

    return null;
}

function solar_finance_append_note(string $existingNotes, string $line): string
{
    $existingNotes = trim($existingNotes);
    return $existingNotes === '' ? $line : ($existingNotes . "\n" . $line);
}

function solar_finance_create_or_update_lead(array $report): array
{
    $mobile = (string) ($report['mobile'] ?? '');
    $mobileKey = solar_finance_mobile_key($mobile);

    if ($mobileKey === '') {
        return ['action' => 'skipped', 'lead_id' => ''];
    }

    $leads = load_all_leads();
    $existing = null;
    foreach ($leads as $lead) {
        $existingKey = solar_finance_mobile_key((string) ($lead['mobile'] ?? ''));
        if ($existingKey !== '' && $existingKey === $mobileKey) {
            $existing = $lead;
            break;
        }
    }

    $input = is_array($report['input_snapshot'] ?? null) ? $report['input_snapshot'] : [];
    $noteLine = sprintf(
        '[%s] Lead created from Solar and Finance report generation (%s).',
        date('d M Y h:i A'),
        (string) ($report['report_id'] ?? '')
    );

    $payload = [
        'name' => (string) ($report['customer_name'] ?? ''),
        'mobile' => $mobile,
        'city' => (string) ($report['location'] ?? ''),
        'lead_source' => 'Solar and Finance',
        'status' => 'Interested',
        'monthly_bill' => (string) ($input['monthly_bill'] ?? ''),
        'interest_type' => (string) ($input['system_type'] ?? ''),
        'finance_subsidy' => (string) ($input['subsidy'] ?? ''),
    ];

    if (is_array($existing)) {
        $payload['notes'] = solar_finance_append_note((string) ($existing['notes'] ?? ''), $noteLine);
        $updated = update_lead((string) ($existing['id'] ?? ''), $payload);

        return ['action' => $updated === null ? 'skipped' : 'updated', 'lead_id' => (string) ($existing['id'] ?? '')];
    }

    $payload['notes'] = $noteLine;
    $created = add_lead($payload);

    return ['action' => 'created', 'lead_id' => (string) ($created['id'] ?? '')];
}
