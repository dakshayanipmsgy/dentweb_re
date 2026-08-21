<?php
declare(strict_types=1);

/** Reusable invoice equipment names. Serial numbers intentionally never enter this store. */
function invoice_component_catalogue_path(): string
{
    return documents_settings_dir() . '/invoice_component_catalogue.json';
}

function invoice_component_catalogue_normalize($value): string
{
    $value = safe_text((string) $value);
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function invoice_component_details_sanitize($submitted): array
{
    $result = [];
    foreach (is_array($submitted) ? $submitted : [] as $row) {
        if (!is_array($row)) { continue; }
        $clean = [];
        foreach (['component_name', 'brand', 'model', 'serial_no'] as $field) {
            $clean[$field] = preg_replace('/\s+/u', ' ', safe_text((string) ($row[$field] ?? ''))) ?? '';
        }
        if (implode('', $clean) !== '') { $result[] = $clean; }
    }
    return $result;
}

function invoice_component_catalogue_read(): array
{
    $catalogue = json_load(invoice_component_catalogue_path(), []);
    return is_array($catalogue['components'] ?? null) ? ['version' => 1, 'components' => array_values($catalogue['components'])] : ['version' => 1, 'components' => []];
}

function invoice_component_catalogue_learn(array $rows): array
{
    documents_ensure_dir(documents_settings_dir());
    $lock = @fopen(documents_settings_dir() . '/invoice_component_catalogue.lock', 'c+');
    if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) { fclose($lock); }
        return ['ok' => false, 'error' => 'Component catalogue is busy.'];
    }
    try {
        $catalogue = invoice_component_catalogue_read();
        foreach (invoice_component_details_sanitize($rows) as $row) {
            $componentKey = invoice_component_catalogue_normalize($row['component_name']);
            if ($componentKey === '') { continue; }
            $componentIndex = null;
            foreach ($catalogue['components'] as $index => $component) {
                if (invoice_component_catalogue_normalize($component['name'] ?? '') === $componentKey) { $componentIndex = $index; break; }
            }
            if ($componentIndex === null) {
                $catalogue['components'][] = ['name' => $row['component_name'], 'brands' => []];
                $componentIndex = array_key_last($catalogue['components']);
            }
            $brandKey = invoice_component_catalogue_normalize($row['brand']);
            if ($brandKey === '') { continue; }
            $brands =& $catalogue['components'][$componentIndex]['brands'];
            if (!is_array($brands)) { $brands = []; }
            $brandIndex = null;
            foreach ($brands as $index => $brand) {
                if (invoice_component_catalogue_normalize($brand['name'] ?? '') === $brandKey) { $brandIndex = $index; break; }
            }
            if ($brandIndex === null) {
                $brands[] = ['name' => $row['brand'], 'models' => []];
                $brandIndex = array_key_last($brands);
            }
            $modelKey = invoice_component_catalogue_normalize($row['model']);
            if ($modelKey === '') { continue; }
            $models =& $brands[$brandIndex]['models'];
            if (!is_array($models)) { $models = []; }
            $exists = false;
            foreach ($models as $model) {
                if (invoice_component_catalogue_normalize($model) === $modelKey) { $exists = true; break; }
            }
            if (!$exists) { $models[] = $row['model']; }
            unset($models, $brands);
        }
        return json_save(invoice_component_catalogue_path(), $catalogue);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
