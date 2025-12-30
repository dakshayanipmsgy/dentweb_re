<?php
declare(strict_types=1);

require_once __DIR__ . '/customer_public.php';

function customer_import_parse_csv(string $contents): array
{
    $rows = [];
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $contents);
    rewind($handle);

    $headers = [];
    while (($data = fgetcsv($handle)) !== false) {
        if ($headers === []) {
            $headers = array_map(static fn ($h) => strtolower(trim((string) $h)), $data);
            continue;
        }
        $row = [];
        foreach ($headers as $index => $header) {
            $row[$header] = $data[$index] ?? '';
        }
        $rows[] = $row;
    }

    fclose($handle);
    return $rows;
}

function customer_import_process(string $contents, string $mode = 'skip'): array
{
    $rows = customer_import_parse_csv($contents);
    $store = customer_record_store();

    $summary = [
        'processed' => 0,
        'created' => 0,
        'updated' => 0,
        'errors' => [],
    ];

    $seenMobiles = [];

    foreach ($rows as $index => $row) {
        $summary['processed']++;
        $line = $index + 2; // account for header
        $mobile = public_normalize_mobile((string) ($row['mobile'] ?? ($row['phone'] ?? '')));
        if ($mobile === '') {
            $summary['errors'][] = ['line' => $line, 'message' => 'Missing or invalid mobile'];
            continue;
        }
        if (in_array($mobile, $seenMobiles, true)) {
            $summary['errors'][] = ['line' => $line, 'message' => 'Duplicate mobile in file'];
            continue;
        }
        $seenMobiles[] = $mobile;

        $hasLoan = strtolower(trim((string) ($row['has loan?'] ?? $row['has_loan'] ?? 'no'))) === 'yes';
        $loanBank = trim((string) ($row['loan bank'] ?? $row['loan_bank'] ?? ''));
        $loanAmount = trim((string) ($row['loan amount'] ?? $row['loan_amount'] ?? ''));
        if ($hasLoan && ($loanBank === '' || $loanAmount === '')) {
            $summary['errors'][] = ['line' => $line, 'message' => 'Loan bank and amount required when loan is yes'];
            continue;
        }

        $projectType = strtolower(trim((string) ($row['project type'] ?? $row['project_type'] ?? '')));
        $pmApp = trim((string) ($row['pm surya ghar application number'] ?? $row['pm_application'] ?? ''));
        if ($projectType === 'pm surya ghar' && $pmApp === '') {
            $summary['errors'][] = ['line' => $line, 'message' => 'PM Surya Ghar application number required'];
            continue;
        }

        $progress = (int) ($row['initial progress percentage'] ?? $row['progress'] ?? 0);
        if ($progress < 0 || $progress > 100) {
            $summary['errors'][] = ['line' => $line, 'message' => 'Progress must be 0-100'];
            continue;
        }

        $customerType = strtolower(trim((string) ($row['customer type'] ?? $row['customer_type'] ?? 'lead_only')));
        $portalEnabled = strtolower(trim((string) ($row['portal enabled'] ?? $row['portal_enabled'] ?? 'no'))) === 'yes';
        if ($customerType !== 'full_customer') {
            $customerType = 'lead_only';
            $portalEnabled = false;
        }

        $existing = $store->findByMobile($mobile);
        if ($existing === null) {
            $payload = [
                'full_name' => trim((string) ($row['full name'] ?? $row['name'] ?? 'Customer')),
                'phone' => $mobile,
                'email' => trim((string) ($row['email'] ?? '')),
                'district' => trim((string) ($row['city'] ?? '')),
                'state' => trim((string) ($row['state'] ?? '')),
                'discom' => trim((string) ($row['discom'] ?? '')),
                'lead_source' => $projectType !== '' ? $projectType : 'bulk import',
                'notes' => trim((string) ($row['notes'] ?? '')),
            ];
            $record = $store->createLead($payload);
            $record = $store->updateCustomer((int) $record['id'], [
                'customer_type' => $customerType === 'full_customer' ? CustomerRecordStore::TYPE_FULL_CUSTOMER : CustomerRecordStore::TYPE_LEAD_ONLY,
                'portal_enabled' => $portalEnabled,
                'pm_surya_ghar' => $projectType === 'pm surya ghar' ? 'Yes' : 'No',
                'pm_sgy_application_id' => $pmApp,
                'loan_taken' => $hasLoan ? 'Yes' : 'No',
                'loan_bank_name' => $loanBank,
                'loan_amount' => $loanAmount,
                'progress_percent' => $progress,
                'crm_stage' => trim((string) ($row['initial crm stage'] ?? $row['crm_stage'] ?? 'Lead Received')),
                'meter_brand' => trim((string) ($row['meter brand'] ?? '')),
                'meter_serial' => trim((string) ($row['meter serial number'] ?? '')),
            ]);
            $summary['created']++;
        } else {
            if ($mode === 'skip') {
                continue;
            }
            $update = [];
            foreach ([
                'full_name' => 'full name',
                'email' => 'email',
                'district' => 'city',
                'state' => 'state',
                'discom' => 'discom',
                'meter_brand' => 'meter brand',
                'meter_serial' => 'meter serial number',
                'lead_source' => 'project type',
                'notes' => 'notes',
            ] as $field => $header) {
                $value = trim((string) ($row[$header] ?? ($row[str_replace(' ', '_', $header)] ?? '')));
                if ($value !== '') {
                    $update[$field] = $value;
                }
            }

            $update['customer_type'] = $customerType === 'full_customer' ? CustomerRecordStore::TYPE_FULL_CUSTOMER : CustomerRecordStore::TYPE_LEAD_ONLY;
            $update['portal_enabled'] = $portalEnabled;
            $update['pm_surya_ghar'] = $projectType === 'pm surya ghar' ? 'Yes' : 'No';
            $update['pm_sgy_application_id'] = $pmApp;
            $update['loan_taken'] = $hasLoan ? 'Yes' : 'No';
            $update['loan_bank_name'] = $loanBank;
            $update['loan_amount'] = $loanAmount;
            $update['progress_percent'] = $progress;
            if (isset($row['initial crm stage']) || isset($row['crm_stage'])) {
                $update['crm_stage'] = trim((string) ($row['initial crm stage'] ?? $row['crm_stage'] ?? 'Lead Received'));
            }

            $store->updateCustomer((int) $existing['id'], $update);
            $summary['updated']++;
        }
    }

    return $summary;
}
