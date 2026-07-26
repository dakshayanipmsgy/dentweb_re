<?php
declare(strict_types=1);

require_once __DIR__ . '/customer_admin.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/../admin/includes/documents_helpers.php';

/** Ordinary contact/profile fields allowed to cross the customer/quotation boundary. */
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
        'sanction_load_kwp'=>'sanction_load_kwp', 'installed_pv_module_capacity_kwp'=>'installed_pv_module_capacity_kwp',
        'circle_name'=>'circle_name', 'division_name'=>'division_name', 'sub_division_name'=>'sub_division_name',
        'solar_plant_installation_date'=>'solar_plant_installation_date', 'loan_taken'=>'loan_taken',
        'loan_application_date'=>'loan_application_date', 'subsidy_amount_rs'=>'subsidy_amount_rs',
        'subsidy_disbursed_date'=>'subsidy_disbursed_date', 'customer_type'=>'customer_type',
    ];
}

function customer_bulk_record_fingerprint(array $record): string
{
    return hash('sha256', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
}

function customer_bulk_quote_is_eligible(array $quote): bool
{
    if (documents_is_archived($quote)) return false;
    return in_array(documents_quote_normalize_status((string)($quote['status'] ?? 'draft')), ['draft', 'approved', 'accepted'], true);
}

function customer_bulk_normalise_header(string $header): string
{
    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
    $header = strtolower(trim($header));
    $header = preg_replace('/[.\s-]+/u', '_', $header) ?? $header;
    $header = trim(preg_replace('/_+/', '_', $header) ?? $header, '_');
    if (in_array($header, ['mobile', 'mobile_number', 'mobile_no', 'phone', 'phone_number', 'customer_mobile', 'contact_number'], true)) {
        return 'mobile';
    }
    return $header;
}

function customer_bulk_delimiter_label(string $delimiter): string
{
    return $delimiter === "\t" ? 'tab' : ($delimiter === ';' ? 'semicolon (;)' : 'comma (,)');
}

/** Parse spreadsheet CSV without relying on the locale-dependent default delimiter. */
function customer_bulk_parse_csv(string $contents): array
{
    $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
    $contents = str_replace(["\r\n", "\r"], "\n", $contents);
    $lines = explode("\n", $contents);
    while ($lines !== [] && trim((string) $lines[0]) === '') array_shift($lines);
    $forced = null;
    if (isset($lines[0]) && preg_match('/^sep=(,|;|\t)\s*$/i', trim($lines[0]), $match)) {
        $forced = $match[1];
        array_shift($lines);
        while ($lines !== [] && trim((string) $lines[0]) === '') array_shift($lines);
    }
    $contents = implode("\n", $lines);
    if (trim($contents) === '') return ['error' => 'CSV file is empty.', 'rows' => [], 'headers' => [], 'delimiter' => ','];

    $candidates = $forced !== null ? [$forced] : [',', ';', "\t"];
    $best = null;
    foreach ($candidates as $delimiter) {
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, $contents); rewind($handle); $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) $rows[] = $row;
        fclose($handle);
        while ($rows !== [] && customer_bulk_is_row_empty($rows[0])) array_shift($rows);
        $columns = count($rows[0] ?? []); $consistent = 0;
        foreach (array_slice($rows, 0, 20) as $row) if (customer_bulk_is_row_empty($row) || count($row) === $columns) $consistent++;
        $score = $columns * 100 + $consistent;
        if ($best === null || $score > $best['score']) $best = compact('delimiter', 'rows', 'score');
    }
    $rows = $best['rows'] ?? []; $delimiter = $best['delimiter'] ?? ',';
    if ($rows === []) return ['error' => 'CSV file is empty.', 'rows' => [], 'headers' => [], 'delimiter' => $delimiter];
    $rawHeaders = array_map(static fn($v): string => trim((string) $v), array_shift($rows));
    if (isset($rawHeaders[0])) $rawHeaders[0] = preg_replace('/^\xEF\xBB\xBF/', '', $rawHeaders[0]) ?? $rawHeaders[0];
    $headers = array_map('customer_bulk_normalise_header', $rawHeaders);
    return ['error' => '', 'rows' => $rows, 'headers' => $headers, 'detected_headers' => $rawHeaders, 'delimiter' => $delimiter];
}

function customer_bulk_compare_value(string $field, string $value): string
{
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if (in_array($field, ['loan_taken'], true)) return strtolower($value);
    if ($field === 'customer_type') return strtolower(preg_replace('/\s+/u', ' ', $value) ?? $value);
    return $value;
}

/** Build a read-only server-side plan. The CSV mobile is identity only and is never a writable field. */
function customer_bulk_mobile_sync_preview(CustomerFsStore $store, string $contents, ?array $quotes = null): array
{
    $parsed=customer_bulk_parse_csv($contents);
    if (($parsed['error']??'')!=='') return ['rows'=>[],'error'=>$parsed['error']];
    $header=$parsed['headers'];
    $metadata=['headers'=>$header,'detected_headers'=>$parsed['detected_headers'],'delimiter'=>$parsed['delimiter'],'delimiter_label'=>customer_bulk_delimiter_label($parsed['delimiter'])];
    if (count($header)!==count(array_unique($header))) return array_merge(['rows'=>[],'error'=>'CSV contains duplicate canonical column names after header normalization.'],$metadata);
    if (!in_array('mobile',$header,true)) return array_merge(['rows'=>[],'error'=>'Mobile column not found. Detected headers: '.implode(', ', $parsed['detected_headers']).'. Detected delimiter: '.$metadata['delimiter_label'].'.'],$metadata);
    $allowed=array_merge(customer_bulk_headers(),customer_bulk_optional_headers());
    $unknown=array_values(array_diff($header,$allowed));
    if ($unknown!==[]) return array_merge(['rows'=>[],'error'=>'Unsupported CSV field(s): '.implode(', ',$unknown)],$metadata);
    $presentFields=array_values(array_intersect(customer_bulk_sync_fields(),$header));
    $quotes=$quotes ?? documents_list_quotes(); $raw=[]; $line=1;
    foreach ($parsed['rows'] as $cells) { $line++; if (!customer_bulk_is_row_empty($cells)) $raw[]=['line'=>$line,'csv'=>customer_bulk_build_payload($cells,$header)]; }
    $counts=[];
    foreach ($raw as $r) { $m=customer_bulk_normalise_mobile((string)($r['csv']['mobile']??'')); if ($m!=='') $counts[$m]=($counts[$m]??0)+1; }
    $customers=$store->listCustomers(); $rows=[];
    foreach ($raw as $r) {
        $csv=$r['csv']; $mobile=customer_bulk_normalise_mobile((string)($csv['mobile']??''));
        $row=['line'=>$r['line'],'mobile'=>$mobile,'csv'=>$csv,'result'=>'Ready','message'=>'','ignored'=>false,'customer'=>null,'fields'=>[],'customer_changes'=>[],'quotes'=>[]];
        if (strlen($mobile)!==10) { $row['result']='Conflict'; $row['message']='Missing or invalid normalized mobile'; $rows[]=$row; continue; }
        if (($counts[$mobile]??0)>1) { $row['result']='Conflict'; $row['message']='Duplicate/conflicting CSV mobile rows'; $rows[]=$row; continue; }
        $matches=array_values(array_filter($customers,static fn(array $c):bool=>customer_bulk_normalise_mobile((string)($c['mobile']??''))===$mobile));
        $active=array_values(array_filter($matches,static fn(array $c):bool=>empty($c['archived'])));
        if (count($matches)!==1 || count($active)!==1) { $row['result']='Conflict'; $row['message']=$matches===[]?'No Customer User has this normalized mobile':'Identity conflict: normalized mobile is not one unique active Customer User'; $rows[]=$row; continue; }
        $customer=$active[0];
        $row['customer']=['mobile'=>(string)$customer['mobile'],'fingerprint'=>customer_bulk_record_fingerprint($customer)];
        foreach ($presentFields as $field) {
            $saved=(string)($customer[$field]??''); $csvValue=(string)($csv[$field]??'');
            $savedBlank=trim($saved)===''; $csvBlank=trim($csvValue)==='';
            $same=customer_bulk_compare_value($field,$saved)===customer_bulk_compare_value($field,$csvValue);
            if (($savedBlank && $csvBlank) || $same) { $state='Unchanged'; $choice='keep'; }
            elseif ($savedBlank) { $state='Fill blank field'; $choice='csv'; $row['customer_changes'][$field]=['from'=>$saved,'to'=>$csvValue]; }
            elseif ($csvBlank) { $state='CSV blank — keep saved value'; $choice='keep'; }
            else { $state='Conflict'; $choice=''; }
            $row['fields'][$field]=['saved'=>$saved,'csv'=>$csvValue,'state'=>$state,'choice'=>$choice,'manual_supported'=>true,'requires_choice'=>$state==='Conflict'];
        }
        foreach ($quotes as $quote) {
            if (!is_array($quote) || !customer_bulk_quote_is_eligible($quote) || customer_bulk_normalise_mobile((string)($quote['customer_mobile']??''))!==$mobile) continue;
            $changes=[]; $values=[];
            foreach ($presentFields as $field) { $qfield=customer_bulk_quote_field_map()[$field]??null; if ($qfield!==null) $values[$qfield]=(string)($quote[$qfield]??''); }
            foreach ($row['customer_changes'] as $field=>$change) { $qfield=customer_bulk_quote_field_map()[$field]??null; if ($qfield!==null && trim((string)($quote[$qfield]??''))!==trim((string)$change['to'])) $changes[$qfield]=['from'=>(string)($quote[$qfield]??''),'to'=>$change['to']]; }
            $row['quotes'][]=['id'=>(string)($quote['id']??''),'status'=>(string)($quote['status']??'Draft'),'fingerprint'=>customer_bulk_record_fingerprint($quote),'values'=>$values,'changes'=>$changes];
        }
        $hasConflict=(bool)array_filter($row['fields'],static fn(array $field):bool=>!empty($field['requires_choice']));
        if ($row['customer_changes']===[] && !$hasConflict) { $row['result']='Unchanged'; $row['message']='All supported CSV fields are unchanged or blank in the CSV'; }
        $rows[]=$row;
    }
    return array_merge(['id'=>bin2hex(random_bytes(16)),'rows'=>$rows,'error'=>'','fields'=>$presentFields,'created_at'=>time(),'expires_at'=>time()+1800],$metadata);
}

/** Convert posted choices into an immutable before/after confirmation plan. */
function customer_bulk_mobile_sync_confirm(array $preview, array $choices): array
{
    $unresolved=[];
    foreach ($preview['rows'] as $i=>&$row) {
        if (($row['result']??'')!=='Ready') continue;
        $posted=is_array($choices[$i]??null)?$choices[$i]:[];
        if (($posted['row']??'sync')==='ignore') { $row['ignored']=true; $row['result']='Ignored'; $row['message']='Customer row will not be changed'; $row['customer_changes']=[]; $row['quotes']=[]; continue; }
        $selected=[];
        foreach ($row['fields'] as $field=>&$comparison) {
            if (($comparison['state']??'')==='Unchanged') continue;
            $choice=(string)($posted[$field]['choice']??($comparison['choice']??''));
            if (($comparison['requires_choice']??false) && !in_array($choice,['keep','csv','manual'],true)) { $unresolved[]='CSV line '.($row['line']??'?').': '.$field; continue; }
            if (!in_array($choice,['keep','csv','manual'],true)) $choice='keep';
            $after=(string)$comparison['saved'];
            if ($choice==='csv') $after=(string)$comparison['csv'];
            elseif ($choice==='manual') $after=trim((string)($posted[$field]['manual']??''));
            $comparison['choice']=$choice; $comparison['after']=$after;
            if ($after!==(string)$comparison['saved']) $selected[$field]=['from'=>(string)$comparison['saved'],'to'=>$after];
        }
        unset($comparison);
        $row['customer_changes']=$selected;
        foreach ($row['quotes'] as &$planned) {
            $previousChanges=$planned['changes'];
            $planned['changes']=[];
            foreach ($selected as $field=>$change) { $qfield=customer_bulk_quote_field_map()[$field]??null; if ($qfield!==null && (string)($planned['values'][$qfield]??'')!==(string)$change['to']) $planned['changes'][$qfield]=['from'=>$previousChanges[$qfield]['from']??($planned['values'][$qfield]??''), 'to'=>$change['to']]; }
        }
        unset($planned);
        if ($selected===[]) { $row['result']='Unchanged'; $row['message']='Keep saved value selected for every difference'; }
    }
    unset($row);
    if ($unresolved!==[]) { $preview['error']='Resolve every populated conflict or ignore its customer row: '.implode('; ',$unresolved).'.'; return $preview; }
    unset($preview['error']);
    $preview['confirmed_at']=time(); $preview['confirmation_id']=bin2hex(random_bytes(16));
    return $preview;
}

/** Apply exactly one confirmed plan with locking, stale preflight, auditing and post-write verification. */
function customer_bulk_mobile_sync_apply(CustomerFsStore $store, array $preview): array
{
    $summary=['applied'=>0,'unchanged'=>0,'skipped'=>0,'ignored'=>0,'conflict'=>0,'failed'=>0,'rows'=>[]];
    if (empty($preview['confirmation_id']) || (int)($preview['expires_at']??0)<time()) return array_merge($summary,['failed'=>count($preview['rows']??[]),'error'=>'Confirmation is missing or expired.']);
    $lock=fopen(sys_get_temp_dir().'/dentweb-customer-csv-sync.lock','c');
    if ($lock===false || !flock($lock,LOCK_EX)) return array_merge($summary,['failed'=>count($preview['rows']??[]),'error'=>'Synchronization is locked.']);
    $applicable=[];
    foreach ($preview['rows']??[] as $row) {
        if (($row['result']??'')!=='Ready') continue;
        $current=$store->findByMobile((string)$row['mobile']);
        if ($current===null || !empty($current['archived']) || customer_bulk_record_fingerprint($current)!==($row['customer']['fingerprint']??'')) { $summary['conflict']++; $summary['rows'][]=array_merge($row,['result'=>'Conflict','message'=>'Customer changed after preview']); continue; }
        $stale=false;
        foreach ($row['quotes'] as $planned) { $q=documents_get_quote((string)$planned['id']); if ($q===null || !customer_bulk_quote_is_eligible($q) || customer_bulk_record_fingerprint($q)!==$planned['fingerprint']) { $stale=true; break; } }
        if ($stale) { $summary['conflict']++; $summary['rows'][]=array_merge($row,['result'=>'Conflict','message'=>'A quotation changed after preview']); continue; }
        $applicable[]=[$row,$current];
    }
    foreach ($preview['rows']??[] as $row) { if (($row['result']??'')==='Ignored') { $summary['ignored']++; $summary['rows'][]=$row; } elseif (($row['result']??'')==='Unchanged') { $summary['unchanged']++; $summary['rows'][]=$row; } elseif (!in_array(($row['result']??''),['Ready','Ignored','Unchanged'],true)) { $summary['conflict']++; $summary['rows'][]=$row; } }
    foreach ($applicable as [$row,$current]) {
        $payload=$current; foreach ($row['customer_changes'] as $field=>$change) $payload[$field]=$change['to'];
        $saved=$store->updateCustomer((string)$row['mobile'],$payload); $failed=empty($saved['success']);
        foreach ($row['quotes'] as $planned) {
            if ($failed || $planned['changes']===[]) continue; $quote=documents_get_quote((string)$planned['id']);
            foreach ($planned['changes'] as $field=>$change) $quote[$field]=$change['to'];
            // Never alter finalized/customer/site-completion snapshots or workflow/financial metadata.
            if (empty(documents_save_quote($quote)['ok'])) {
                $failed=true;
            } else {
                $verifiedQuote=documents_get_quote((string)$planned['id']);
                foreach ($planned['changes'] as $field=>$change) {
                    if ($verifiedQuote===null || (string)($verifiedQuote[$field]??'')!==(string)$change['to']) $failed=true;
                }
            }
        }
        $verify=$store->findByMobile((string)$row['mobile']);
        foreach ($row['customer_changes'] as $field=>$change) if ((string)($verify[$field]??'')!==(string)$change['to']) $failed=true;
        $row['result']=$failed?'Failed':'Applied'; $summary[$failed?'failed':'applied']++; $summary['rows'][]=$row;
        $actor=audit_current_actor(); log_audit_event($actor['actor_type'],$actor['actor_id'],'customer',(string)$row['mobile'],'customer_csv_field_choice_sync',['confirmation_id'=>$preview['confirmation_id'],'result'=>$row['result'],'changes'=>$row['customer_changes'],'quotation_ids'=>array_column($row['quotes'],'id'),'verified'=>!$failed]);
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
