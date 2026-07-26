<?php
declare(strict_types=1);

require_once __DIR__ . '/customer_admin.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/../admin/includes/documents_helpers.php';

/** Fields which may cross the CSV/customer/project boundary. Identity, workflow and money fields are intentionally absent. */
function customer_bulk_sync_fields(): array
{
    return [
        'name', 'customer_type', 'address', 'city', 'district', 'pin_code', 'state',
        'meter_number', 'meter_serial_number', 'jbvnl_account_number', 'application_id',
        'application_submitted_date', 'sanction_load_kwp', 'installed_pv_module_capacity_kwp',
        'circle_name', 'division_name', 'sub_division_name', 'loan_taken',
        'loan_application_date', 'solar_plant_installation_date', 'subsidy_amount_rs',
        'subsidy_disbursed_date',
    ];
}

function customer_bulk_quote_field_map(): array
{
    return [
        'name'=>'customer_name', 'address'=>'site_address', 'city'=>'city', 'district'=>'district',
        'state'=>'state', 'pin_code'=>'pin', 'meter_number'=>'meter_number',
        'meter_serial_number'=>'meter_serial_number', 'jbvnl_account_number'=>'consumer_account_no',
        'application_id'=>'application_id', 'application_submitted_date'=>'application_submitted_date',
        'sanction_load_kwp'=>'sanction_load_kwp',
        'installed_pv_module_capacity_kwp'=>'installed_pv_module_capacity_kwp',
        'circle_name'=>'circle_name', 'division_name'=>'division_name', 'sub_division_name'=>'sub_division_name',
        'solar_plant_installation_date'=>'solar_plant_installation_date', 'loan_taken'=>'loan_taken',
        'loan_application_date'=>'loan_application_date', 'subsidy_amount_rs'=>'subsidy_amount_rs',
        'subsidy_disbursed_date'=>'subsidy_disbursed_date', 'customer_type'=>'customer_type',
    ];
}

function customer_bulk_record_fingerprint(array $record): string
{
    return hash('sha256', json_encode($record, JSON_UNESCAPED_SLASHES) ?: '');
}

/** Build a read-only, field-by-field plan. A blank CSV cell means "not selected". */
function customer_bulk_mobile_sync_preview(CustomerFsStore $store, string $contents, ?array $quotes = null): array
{
    $handle = fopen('php://temp', 'w+b'); fwrite($handle, $contents); rewind($handle);
    $header = fgetcsv($handle);
    if (!is_array($header)) return ['rows'=>[], 'error'=>'CSV file is empty.'];
    $header = array_map(static fn($v)=>strtolower(trim((string)$v)), $header);
    if (!in_array('mobile', $header, true)) return ['rows'=>[], 'error'=>'CSV must contain a mobile column.'];
    $unknown = array_diff($header, array_merge(customer_bulk_headers(), customer_bulk_optional_headers()));
    if ($unknown !== []) return ['rows'=>[], 'error'=>'Unsupported CSV field(s): '.implode(', ', $unknown)];
    $quotes = $quotes ?? documents_list_quotes(); $raw=[]; $line=1;
    while (($cells=fgetcsv($handle)) !== false) { $line++; if (customer_bulk_is_row_empty($cells)) continue; $raw[]=['line'=>$line,'csv'=>customer_bulk_build_payload($cells,$header)]; }
    fclose($handle);
    $counts=[]; foreach ($raw as $r) { $m=customer_bulk_normalise_mobile((string)($r['csv']['mobile']??'')); if ($m!=='') $counts[$m]=($counts[$m]??0)+1; }
    $rows=[];
    foreach ($raw as $r) {
        $csv=$r['csv']; $mobile=customer_bulk_normalise_mobile((string)($csv['mobile']??''));
        $row=['line'=>$r['line'],'mobile'=>$mobile,'csv'=>$csv,'result'=>'Ready','message'=>'','customer'=>null,'customer_changes'=>[],'quotes'=>[]];
        if ($mobile==='' || strlen($mobile)!==10) { $row['result']='Skipped'; $row['message']='Missing or invalid mobile'; $rows[]=$row; continue; }
        if (($counts[$mobile]??0)>1) { $row['result']='Conflict'; $row['message']='Duplicate/conflicting CSV mobile rows'; $rows[]=$row; continue; }
        $customer=$store->findByMobile($mobile);
        if ($customer===null) { $row['result']='Skipped'; $row['message']='No Customer Users record has this normalized mobile'; $rows[]=$row; continue; }
        if (!empty($customer['archived'])) { $row['result']='Conflict'; $row['message']='Matching customer is archived'; $rows[]=$row; continue; }
        $row['customer']=['mobile'=>(string)$customer['mobile'],'fingerprint'=>customer_bulk_record_fingerprint($customer)];
        foreach (customer_bulk_sync_fields() as $field) { $new=trim((string)($csv[$field]??'')); if ($new!=='' && $new!==trim((string)($customer[$field]??''))) $row['customer_changes'][$field]=['from'=>(string)($customer[$field]??''),'to'=>$new]; }
        foreach ($quotes as $quote) {
            if (!is_array($quote) || !empty($quote['archived_flag']) || customer_bulk_normalise_mobile((string)($quote['customer_mobile']??''))!==$mobile) continue;
            $changes=[]; foreach (customer_bulk_quote_field_map() as $field=>$qfield) { $new=trim((string)($csv[$field]??'')); if ($new!=='' && $new!==trim((string)($quote[$qfield]??''))) $changes[$qfield]=['from'=>(string)($quote[$qfield]??''),'to'=>$new]; }
            $row['quotes'][]=['id'=>(string)($quote['id']??''),'status'=>(string)($quote['status']??'Draft'),'fingerprint'=>customer_bulk_record_fingerprint($quote),'changes'=>$changes];
        }
        if ($row['customer_changes']===[] && array_filter($row['quotes'],static fn($q)=>$q['changes']!==[])===[]) { $row['result']='Unchanged'; $row['message']='Stored values are identical'; }
        $rows[]=$row;
    }
    return ['rows'=>$rows,'error'=>'','created_at'=>date('c')];
}

/** Apply an exact preview. Fingerprints provide stale checks; a lock serializes imports. */
function customer_bulk_mobile_sync_apply(CustomerFsStore $store, array $preview): array
{
    $summary=['applied'=>0,'unchanged'=>0,'skipped'=>0,'conflict'=>0,'failed'=>0,'rows'=>[]];
    $lock=fopen(sys_get_temp_dir().'/dentweb-customer-csv-sync.lock','c');
    if ($lock===false || !flock($lock, LOCK_EX)) return array_merge($summary,['failed'=>count($preview['rows']??[])]);
    foreach (($preview['rows']??[]) as $row) {
        $result=(string)($row['result']??'Skipped');
        if ($result!=='Ready') { $key=strtolower($result); if (isset($summary[$key])) $summary[$key]++; $summary['rows'][]=$row; continue; }
        $current=$store->findByMobile((string)$row['mobile']);
        if ($current===null || !empty($current['archived']) || customer_bulk_record_fingerprint($current)!==($row['customer']['fingerprint']??'')) { $row['result']='Conflict'; $row['message']='Customer changed after preview'; $summary['conflict']++; $summary['rows'][]=$row; continue; }
        $payload=$current; foreach ($row['customer_changes'] as $field=>$change) $payload[$field]=$change['to'];
        $saved=$store->updateCustomer((string)$row['mobile'],$payload);
        if (empty($saved['success'])) { $row['result']='Failed'; $row['message']=implode('; ',(array)($saved['errors']??[])); $summary['failed']++; $summary['rows'][]=$row; continue; }
        $failed=false;
        foreach ($row['quotes'] as $planned) {
            $quote=documents_get_quote((string)$planned['id']);
            if ($quote===null || !empty($quote['archived_flag']) || customer_bulk_record_fingerprint($quote)!==$planned['fingerprint']) { $failed=true; $row['message']='Quotation changed after preview: '.$planned['id']; break; }
            if ($planned['changes']===[]) continue;
            foreach ($planned['changes'] as $field=>$change) $quote[$field]=$change['to'];
            // Current display data is synchronized, while finalized snapshots, status, IDs and revision remain untouched.
            $snapshot=is_array($quote['customer_snapshot']??null)?$quote['customer_snapshot']:[];
            foreach (customer_bulk_quote_field_map() as $source=>$qfield) if (isset($planned['changes'][$qfield]) && array_key_exists($source,$snapshot)) $snapshot[$source]=$planned['changes'][$qfield]['to'];
            $quote['customer_snapshot']=$snapshot;
            if (empty(documents_save_quote($quote)['ok'])) { $failed=true; $row['message']='Could not save quotation '.$planned['id']; break; }
        }
        if ($failed) { $row['result']='Failed'; $summary['failed']++; } else { $row['result']='Applied'; $summary['applied']++; }
        $actor=audit_current_actor(); log_audit_event($actor['actor_type'],$actor['actor_id'],'customer',(string)$row['mobile'],'customer_csv_mobile_sync',['result'=>$row['result'],'customer_fields'=>array_keys($row['customer_changes']),'quotation_ids'=>array_column($row['quotes'],'id')]);
        $summary['rows'][]=$row;
    }
    flock($lock,LOCK_UN); fclose($lock); return $summary;
}

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
