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

/**
 * @return array<int, array<string, mixed>>
 */
function solar_finance_load_reports(): array
{
    $path = solar_finance_reports_storage_path();
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function solar_finance_save_reports(array $reports): void
{
    $path = solar_finance_reports_storage_path();
    $encoded = json_encode(array_values($reports), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Unable to encode reports data.');
    }

    file_put_contents($path, $encoded, LOCK_EX);
}

function solar_finance_generate_report_id(): string
{
    return 'sfr_' . bin2hex(random_bytes(6));
}

function solar_finance_generate_token(): string
{
    return bin2hex(random_bytes(24));
}

function solar_finance_normalize_mobile(string $mobile): ?string
{
    $digits = preg_replace('/\D+/', '', $mobile) ?? '';
    if ($digits === '') {
        return null;
    }

    if (strlen($digits) === 10) {
        return $digits;
    }

    if (strlen($digits) === 11 && $digits[0] === '0') {
        return substr($digits, 1);
    }

    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        return substr($digits, 2);
    }

    return null;
}

function solar_finance_find_report(string $tokenOrId): ?array
{
    foreach (solar_finance_load_reports() as $report) {
        if (($report['public_token'] ?? '') === $tokenOrId || ($report['report_id'] ?? '') === $tokenOrId) {
            return $report;
        }
    }

    return null;
}

function solar_finance_upsert_lead(array $customer, array $snapshot): array
{
    $normalizedMobile = (string) ($customer['mobile'] ?? '');
    $leads = load_all_leads();
    $matchIndex = null;

    foreach ($leads as $index => $lead) {
        $leadMobile = solar_finance_normalize_mobile((string) ($lead['mobile'] ?? ''));
        if ($leadMobile !== null && $leadMobile === $normalizedMobile) {
            $matchIndex = $index;
            break;
        }
    }

    $now = date('Y-m-d H:i:s');
    $notes = 'Lead created from Solar and Finance report generation';
    $input = is_array($snapshot['inputs'] ?? null) ? $snapshot['inputs'] : [];
    $results = is_array($snapshot['results'] ?? null) ? $snapshot['results'] : [];

    if ($matchIndex === null) {
        $record = lead_normalize_record([
            'id' => leads_generate_id(),
            'created_at' => $now,
            'updated_at' => $now,
            'name' => (string) ($customer['name'] ?? ''),
            'mobile' => $normalizedMobile,
            'city' => (string) ($customer['location'] ?? ''),
            'status' => 'Interested',
            'lead_source' => 'Solar and Finance',
            'monthly_bill' => (string) ($input['monthlyBill'] ?? ''),
            'finance_subsidy' => (string) ($input['subsidy'] ?? ''),
            'interest_type' => (string) ($input['loanPreference'] ?? ''),
            'notes' => $notes,
            'activity_log' => [
                [
                    'time' => $now,
                    'message' => 'Lead auto-created from Solar and Finance report generation.',
                ],
            ],
        ]);
        $leads[] = $record;
        save_all_leads($leads);

        return ['action' => 'created', 'lead_id' => $record['id']];
    }

    $existing = lead_normalize_record($leads[$matchIndex]);
    $existingNotes = trim((string) ($existing['notes'] ?? ''));
    $latestContext = sprintf(
        'Latest snapshot — Bill: %s, Units: %s, Type: %s, Size: %s, Cost: %s, Subsidy: %s, Loan pref: %s.',
        (string) ($input['monthlyBill'] ?? '-'),
        (string) ($input['monthlyUnits'] ?? '-'),
        (string) ($input['systemType'] ?? '-'),
        (string) ($input['solarSize'] ?? '-'),
        (string) ($results['selfFunded']['systemCost'] ?? ($input['systemCostSelf'] ?? '-')),
        (string) ($input['subsidy'] ?? '-'),
        (string) ($input['loanPreference'] ?? '-')
    );

    $mergedNotes = trim($existingNotes . "\n" . '[' . $now . '] ' . $notes . ' ' . $latestContext);

    $existing['name'] = (string) ($customer['name'] ?? $existing['name']);
    $existing['city'] = (string) ($customer['location'] ?? $existing['city']);
    $existing['mobile'] = $normalizedMobile;
    $existing['lead_source'] = 'Solar and Finance';
    $existing['status'] = (string) ($existing['status'] ?: 'Interested');
    $existing['monthly_bill'] = (string) ($input['monthlyBill'] ?? $existing['monthly_bill']);
    $existing['finance_subsidy'] = (string) ($input['subsidy'] ?? $existing['finance_subsidy']);
    $existing['interest_type'] = (string) ($input['loanPreference'] ?? $existing['interest_type']);
    $existing['notes'] = $mergedNotes;

    $log = is_array($existing['activity_log']) ? $existing['activity_log'] : [];
    $log[] = [
        'time' => $now,
        'message' => 'Lead refreshed from Solar and Finance report generation.',
    ];
    $existing['activity_log'] = $log;
    $existing['updated_at'] = $now;

    $leads[$matchIndex] = $existing;
    save_all_leads($leads);

    return ['action' => 'updated', 'lead_id' => $existing['id'] ?? ''];
}

function solar_finance_create_report(array $customer, array $snapshot): array
{
    $reports = solar_finance_load_reports();
    $record = [
        'report_id' => solar_finance_generate_report_id(),
        'public_token' => solar_finance_generate_token(),
        'customer' => [
            'name' => (string) ($customer['name'] ?? ''),
            'location' => (string) ($customer['location'] ?? ''),
            'mobile' => (string) ($customer['mobile'] ?? ''),
        ],
        'inputs' => is_array($snapshot['inputs'] ?? null) ? $snapshot['inputs'] : [],
        'results' => is_array($snapshot['results'] ?? null) ? $snapshot['results'] : [],
        'charts' => is_array($snapshot['charts'] ?? null) ? $snapshot['charts'] : [],
        'created_at' => date('Y-m-d H:i:s'),
        'generated_at' => date('Y-m-d H:i:s'),
        'source' => 'Solar and Finance',
        'created_by' => 'public_calculator',
    ];

    $reports[] = $record;
    solar_finance_save_reports($reports);

    return $record;
}
