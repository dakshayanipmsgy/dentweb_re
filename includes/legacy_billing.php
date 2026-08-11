<?php
declare(strict_types=1);

/** Additive filesystem store for billing projects which have no quotation. */
function legacy_billing_base_dir(): string
{
    $override = trim((string) getenv('LEGACY_BILLING_BASE_DIR'));
    return $override !== '' ? rtrim($override, '/\\') : dirname(__DIR__) . '/storage/commercial-projects/legacy';
}

function legacy_billing_customer_key(array $customer): string
{
    $key = preg_replace('/\D+/', '', (string) ($customer['mobile_key'] ?? $customer['mobile'] ?? '')) ?? '';
    return $key;
}

function legacy_billing_customer_is_eligible(array $customer): bool
{
    return legacy_billing_customer_key($customer) !== '' && trim((string) ($customer['created_from_quote_id'] ?? '')) === '';
}

function legacy_billing_customer_has_modern_project(array $customer, array $quotes): bool
{
    $mobile = legacy_billing_customer_key($customer);
    if ($mobile === '') return false;
    foreach ($quotes as $quote) {
        if (!is_array($quote)) continue;
        $quoteMobile = preg_replace('/\D+/', '', (string) ($quote['customer_mobile'] ?? '')) ?? '';
        if ($quoteMobile !== $mobile || empty($quote['is_current_version'])) continue;
        if (documents_quote_normalize_status((string) ($quote['status'] ?? 'draft')) === 'accepted') return true;
    }
    return false;
}

function legacy_billing_list_projects(): array
{
    $rows = [];
    foreach (glob(legacy_billing_base_dir() . '/LEG-*.json') ?: [] as $file) {
        $row = json_decode((string) @file_get_contents($file), true);
        if (is_array($row) && ($row['source_type'] ?? '') === 'legacy_direct_customer') $rows[] = $row;
    }
    usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
    return $rows;
}

function legacy_billing_get_project(string $id): ?array
{
    if (!preg_match('/^LEG-\d{4}-\d{4,}$/D', $id)) return null;
    $row = json_decode((string) @file_get_contents(legacy_billing_base_dir() . '/' . $id . '.json'), true);
    return is_array($row) && ($row['id'] ?? '') === $id ? $row : null;
}

function legacy_billing_project_for_customer(array $customer): ?array
{
    $key = legacy_billing_customer_key($customer);
    foreach (legacy_billing_list_projects() as $row) {
        if (hash_equals((string) ($row['customer_ref']['key'] ?? ''), $key)) return $row;
    }
    return null;
}

/** The callback must return true when a current accepted quotation owns this installation. */
function legacy_billing_enable(array $customer, array $actor = [], ?callable $modernProjectExists = null): array
{
    if (!legacy_billing_customer_is_eligible($customer)) return ['ok'=>false, 'error'=>'Only directly-created customers without quotation provenance are eligible.'];
    if ($modernProjectExists !== null && $modernProjectExists($customer)) return ['ok'=>false, 'error'=>'A modern quotation-backed project already exists for this customer.'];
    $dir = legacy_billing_base_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return ['ok'=>false, 'error'=>'Unable to initialise the legacy project store.'];
    $lock = @fopen($dir . '/projects.lock', 'c+');
    if (!is_resource($lock) || !flock($lock, LOCK_EX)) { if (is_resource($lock)) fclose($lock); return ['ok'=>false, 'error'=>'Legacy project store is busy.']; }
    try {
        $existing = legacy_billing_project_for_customer($customer);
        if ($existing !== null) return ['ok'=>true, 'project'=>$existing, 'deduplicated'=>true];
        $year = date('Y'); $max = 0;
        foreach (glob($dir . '/LEG-' . $year . '-*.json') ?: [] as $file) {
            if (preg_match('/LEG-' . preg_quote($year, '/') . '-(\d+)\.json$/', basename($file), $m)) $max=max($max,(int)$m[1]);
        }
        $id = sprintf('LEG-%s-%04d', $year, $max + 1); $now = date('c');
        $project = [
            'id'=>$id, 'source_type'=>'legacy_direct_customer', 'project_source'=>'legacy', 'status'=>'completed',
            'customer_ref'=>['type'=>'customer_user','key'=>legacy_billing_customer_key($customer),'mobile'=>(string)($customer['mobile']??'')],
            'customer_serial_number'=>(string)($customer['serial_number']??''),
            'customer_snapshot'=>['name'=>(string)($customer['name']??''),'mobile'=>(string)($customer['mobile']??''),'address'=>(string)($customer['address']??''),'city'=>(string)($customer['city']??''),'state'=>(string)($customer['state']??'')],
            'installation_date'=>(string)($customer['solar_plant_installation_date']??''),
            'completion_date'=>(string)($customer['solar_plant_installation_date']??substr($now,0,10)),
            'capacity_kwp'=>(string)($customer['installed_pv_module_capacity_kwp']??$customer['sanction_load_kwp']??''),
            'historical_project_value'=>null, 'invoice_ids'=>[], 'created_at'=>$now,
            'created_by'=>['type'=>(string)($actor['type']??'admin'),'id'=>(string)($actor['id']??''),'name'=>(string)($actor['name']??'Admin')],
            'provenance'=>['customer_created_from_quote_id'=>'','customer_created_from_quote_no'=>(string)($customer['created_from_quote_no']??''),'enabled_explicitly'=>true],
        ];
        $tmp=$dir.'/.'.$id.'.'.bin2hex(random_bytes(4)).'.tmp';
        $json=json_encode($project, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if ($json===false || file_put_contents($tmp,$json."\n",LOCK_EX)===false || !@rename($tmp,$dir.'/'.$id.'.json')) { @unlink($tmp); return ['ok'=>false,'error'=>'Unable to save legacy project.']; }
        return ['ok'=>true,'project'=>$project,'deduplicated'=>false];
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function legacy_billing_save_project(array $project): bool
{
    $id=(string)($project['id']??''); if (!preg_match('/^LEG-\d{4}-\d{4,}$/D',$id)) return false;
    $dir=legacy_billing_base_dir(); if(!is_dir($dir)) @mkdir($dir,0775,true);
    $lock=@fopen($dir.'/projects.lock','c+'); if(!is_resource($lock)||!flock($lock,LOCK_EX)){if(is_resource($lock))fclose($lock);return false;}
    try { $tmp=$dir.'/.'.$id.'.'.bin2hex(random_bytes(4)).'.tmp'; $json=json_encode($project,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); return $json!==false&&file_put_contents($tmp,$json."\n",LOCK_EX)!==false&&@rename($tmp,$dir.'/'.$id.'.json'); }
    finally { flock($lock,LOCK_UN);fclose($lock); }
}

function legacy_billing_invoices(string $projectId, bool $customerVisibleOnly = false): array
{
    $rows=[];
    foreach (glob(documents_invoices_dir().'/*.json')?:[] as $file) {
        $row=documents_get_invoice(pathinfo($file,PATHINFO_FILENAME));
        if (!is_array($row) || (string)($row['commercial_ref']['type']??'')!=='legacy_project' || (string)($row['commercial_ref']['id']??'')!==$projectId) continue;
        if ($customerVisibleOnly && (!documents_invoice_is_finalized($row) || documents_is_archived($row) || documents_invoice_is_cancelled($row))) continue;
        $rows[]=$row;
    }
    return $rows;
}
