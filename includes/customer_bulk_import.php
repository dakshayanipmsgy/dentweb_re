<?php
declare(strict_types=1);

require_once __DIR__ . '/customer_admin.php';

function customer_bulk_headers(): array
{
    return [
        'mobile',
        'name',
        'customer_type',
        'address',
        'city',
        'district',
        'pin_code',
        'state',
        'meter_number',
        'meter_serial_number',
        'jbvnl_account_number',
        'application_id',
        'complaints_raised',
        'status',
        'application_submitted_date',
        'sanction_load_kwp',
        'installed_pv_module_capacity_kwp',
        'circle_name',
        'division_name',
        'sub_division_name',
        'loan_taken',
        'loan_application_date',
        'solar_plant_installation_date',
        'subsidy_amount_rs',
        'subsidy_disbursed_date',
        'password',
    ];
}

function customer_bulk_optional_headers(): array
{
    return [
        'serial_number',
        'welcome_sent_via',
    ];
}

function customer_bulk_send_sample_csv(): void
{
    $headers = array_merge(customer_bulk_headers(), customer_bulk_optional_headers());

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="customer-sample.csv"');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        return;
    }

    fputcsv($output, $headers);
    fputcsv($output, [
        '9876543210',
        'Sample PM Customer',
        'PM Surya Ghar',
        '123 Solar Street',
        'Ranchi',
        'Ranchi',
        '834001',
        'Jharkhand',
        'MTR-001',
        'SN-12345',
        'ACCT-123',
        'PM-APP-01',
        'Yes',
        'Survey Done',
        '2024-01-15',
        '3',
        '3.2',
        'Ranchi Circle',
        'Ranchi Division',
        'Ranchi Subdivision',
        'Yes',
        '2024-01-10',
        '2024-02-05',
        '35000',
        '2024-03-01',
        'Temp@123',
        '1',
        'whatsapp',
    ]);
    fputcsv($output, [
        '9998887776',
        'Sample Non PM Customer',
        'Non PM Surya Ghar',
        '45 Green Road',
        'Ranchi',
        'Ranchi',
        '834002',
        'Jharkhand',
        'MTR-002',
        'SN-67890',
        'ACCT-456',
        '',
        'No',
        'New',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        'email',
    ]);
    fclose($output);
}

function customer_bulk_import(CustomerFsStore $store, ?array $upload): array
{
    if ($upload === null || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload a CSV file to import customers.', 'summary' => null];
    }

    $extension = strtolower((string) pathinfo($upload['name'] ?? '', PATHINFO_EXTENSION));
    $mime = strtolower((string) ($upload['type'] ?? ''));
    if ($extension !== 'csv' && $mime !== 'text/csv') {
        return ['success' => false, 'message' => 'Please upload a .csv file.', 'summary' => null];
    }

    $handle = fopen($upload['tmp_name'], 'r');
    if ($handle === false) {
        return ['success' => false, 'message' => 'Could not open uploaded file.', 'summary' => null];
    }

    $expectedHeaders = customer_bulk_headers();
    $optionalHeaders = customer_bulk_optional_headers();
    $headerRow = fgetcsv($handle);
    if ($headerRow === false) {
        fclose($handle);
        return ['success' => false, 'message' => 'CSV file is empty.', 'summary' => null];
    }

    $normalisedHeaders = array_map(static fn ($value) => strtolower(trim((string) $value)), $headerRow);
    $expectedWithOptional = array_merge($expectedHeaders, $optionalHeaders);
    if ($normalisedHeaders !== $expectedHeaders && $normalisedHeaders !== $expectedWithOptional) {
        fclose($handle);
        return ['success' => false, 'message' => 'CSV header row does not match the required template.', 'summary' => null];
    }

    $activeHeaders = $normalisedHeaders;

    $summary = [
        'processed' => 0,
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    $seenMobiles = [];

    while (($row = fgetcsv($handle)) !== false) {
        $summary['processed']++;
        $lineNumber = $summary['processed'] + 1; // include header row
        if (customer_bulk_is_row_empty($row)) {
            $summary['skipped']++;
            continue;
        }

        $payload = customer_bulk_build_payload($row, $activeHeaders);
        foreach ($optionalHeaders as $optionalHeader) {
            if (!array_key_exists($optionalHeader, $payload)) {
                $payload[$optionalHeader] = '';
            }
        }
        $mobileKey = customer_bulk_normalise_mobile($payload['mobile']);
        if ($mobileKey === '') {
            $summary['skipped']++;
            $summary['errors'][] = ['line' => $lineNumber, 'message' => 'Missing or invalid mobile'];
            continue;
        }

        if (isset($seenMobiles[$mobileKey])) {
            $summary['skipped']++;
            $summary['errors'][] = ['line' => $lineNumber, 'message' => 'Duplicate mobile in file'];
            continue;
        }
        $seenMobiles[$mobileKey] = true;

        if ($payload['name'] === '') {
            $summary['skipped']++;
            $summary['errors'][] = ['line' => $lineNumber, 'message' => 'Missing customer name'];
            continue;
        }

        $customerType = customer_bulk_normalise_customer_type($payload['customer_type']);
        if ($customerType === null) {
            $summary['skipped']++;
            $summary['errors'][] = ['line' => $lineNumber, 'message' => 'Invalid customer_type value'];
            continue;
        }
        $payload['customer_type'] = $customerType;
        $payload['complaints_raised'] = customer_bulk_normalise_optional_boolean($payload['complaints_raised']);
        $payload['loan_taken'] = customer_bulk_normalise_optional_boolean($payload['loan_taken']);
        $statusInput = $payload['status'] ?? '';

        $passwordInput = $payload['password'] ?? '';
        unset($payload['password']);

        $existing = $store->findByMobile($payload['mobile']);
        if ($existing === null) {
            $payload['status'] = $statusInput === '' ? $store->ensureStatusValue('') : $store->ensureStatusValue($statusInput);
            if ($passwordInput === '') {
                $summary['skipped']++;
                $summary['errors'][] = ['line' => $lineNumber, 'message' => 'Password is required for new customers'];
                continue;
            }

            $hash = password_hash($passwordInput, PASSWORD_DEFAULT);
            if ($hash === false) {
                $summary['skipped']++;
                $summary['errors'][] = ['line' => $lineNumber, 'message' => 'Unable to process password for new customer'];
                continue;
            }

            $payload['password_hash'] = $hash;
            $result = $store->addCustomer($payload);
            if ($result['success']) {
                $summary['created']++;
            } else {
                $summary['skipped']++;
                $summary['errors'][] = ['line' => $lineNumber, 'message' => implode('; ', $result['errors'])];
            }
        } else {
            $payload['status'] = $statusInput === ''
                ? (string) ($existing['status'] ?? $store->ensureStatusValue(''))
                : $store->ensureStatusValue($statusInput);
            $updatePayload = customer_bulk_merge_existing($payload, $existing);
            if ($passwordInput !== '') {
                $hash = password_hash($passwordInput, PASSWORD_DEFAULT);
                if ($hash === false) {
                    $summary['skipped']++;
                    $summary['errors'][] = ['line' => $lineNumber, 'message' => 'Unable to process password for customer'];
                    continue;
                }
                $updatePayload['password_hash'] = $hash;
            }

            $result = $store->updateCustomer($payload['mobile'], $updatePayload);
            if ($result['success']) {
                $summary['updated']++;
            } else {
                $summary['skipped']++;
                $summary['errors'][] = ['line' => $lineNumber, 'message' => implode('; ', $result['errors'])];
            }
        }
    }

    fclose($handle);
    return ['success' => true, 'message' => 'Import completed.', 'summary' => $summary];
}

function customer_bulk_build_payload(array $row, array $headers): array
{
    $payload = [];
    foreach ($headers as $index => $header) {
        $payload[$header] = trim((string) ($row[$index] ?? ''));
    }

    return $payload;
}

function customer_bulk_is_row_empty(array $row): bool
{
    foreach ($row as $cell) {
        if (trim((string) $cell) !== '') {
            return false;
        }
    }

    return true;
}

function customer_bulk_normalise_mobile(string $mobile): string
{
    $digits = preg_replace('/\D+/', '', $mobile);
    if (!is_string($digits) || $digits === '') {
        return '';
    }
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }

    return $digits;
}

function customer_bulk_normalise_customer_type(string $value): ?string
{
    $normalised = strtolower(trim($value));
    if ($normalised === '') {
        return '';
    }
    if ($normalised === 'pm surya ghar') {
        return 'PM Surya Ghar';
    }
    if ($normalised === 'non pm surya ghar') {
        return 'Non PM Surya Ghar';
    }

    return null;
}

function customer_bulk_normalise_boolean(string $value): string
{
    $value = strtolower(trim($value));
    $truthy = ['yes', '1', 'true', 'y'];
    return in_array($value, $truthy, true) ? 'Yes' : 'No';
}

function customer_bulk_normalise_optional_boolean(string $value): string
{
    return trim($value) === '' ? '' : customer_bulk_normalise_boolean($value);
}

function customer_bulk_merge_existing(array $payload, array $existing): array
{
    $merged = $existing;
    foreach ($payload as $field => $value) {
        if ($field === 'password') {
            continue;
        }

        if ($value !== '') {
            $merged[$field] = $value;
        } elseif (!array_key_exists($field, $merged)) {
            $merged[$field] = '';
        }
    }

    return $merged;
}
