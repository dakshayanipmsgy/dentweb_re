<?php
declare(strict_types=1);

function documents_base_dir(): string
{
    return dirname(__DIR__, 2) . '/data/documents';
}

function documents_settings_dir(): string
{
    return documents_base_dir() . '/settings';
}

function documents_templates_dir(): string
{
    return documents_base_dir() . '/templates';
}

function documents_media_dir(): string
{
    return documents_base_dir() . '/media';
}

function documents_logs_dir(): string
{
    return documents_base_dir() . '/logs';
}

function documents_quotations_dir(): string
{
    return documents_base_dir() . '/quotations';
}

function documents_proformas_dir(): string
{
    return documents_base_dir() . '/proformas';
}

function documents_agreements_dir(): string
{
    return documents_base_dir() . '/agreements';
}

function documents_public_branding_dir(): string
{
    return dirname(__DIR__, 2) . '/images/documents/branding';
}

function documents_public_backgrounds_dir(): string
{
    return dirname(__DIR__, 2) . '/images/documents/backgrounds';
}

function documents_public_media_dir(): string
{
    return dirname(__DIR__, 2) . '/images/documents';
}

function documents_public_diagrams_dir(): string
{
    return dirname(__DIR__, 2) . '/images/documents/diagrams';
}

function documents_public_uploads_dir(): string
{
    return dirname(__DIR__, 2) . '/images/documents/uploads';
}

function documents_log(string $message): void
{
    documents_ensure_structure();
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    @file_put_contents(documents_logs_dir() . '/documents.log', $line, FILE_APPEND | LOCK_EX);
}

function documents_ensure_dir(string $path): void
{
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}

function documents_ensure_structure(): void
{
    documents_ensure_dir(documents_base_dir());
    documents_ensure_dir(documents_settings_dir());
    documents_ensure_dir(documents_templates_dir());
    documents_ensure_dir(documents_media_dir());
    documents_ensure_dir(documents_logs_dir());
    documents_ensure_dir(documents_quotations_dir());
    documents_ensure_dir(documents_proformas_dir());
    documents_ensure_dir(documents_agreements_dir());
    documents_ensure_dir(documents_public_branding_dir());
    documents_ensure_dir(documents_public_backgrounds_dir());
    documents_ensure_dir(documents_public_diagrams_dir());
    documents_ensure_dir(documents_public_uploads_dir());

    $companyPath = documents_settings_dir() . '/company_profile.json';
    if (!is_file($companyPath)) {
        json_save($companyPath, documents_company_profile_defaults());
    }

    $rulesPath = documents_settings_dir() . '/numbering_rules.json';
    if (!is_file($rulesPath)) {
        json_save($rulesPath, documents_numbering_defaults());
    }

    $templatesPath = documents_templates_dir() . '/template_sets.json';
    if (!is_file($templatesPath)) {
        json_save($templatesPath, []);
    }

    $templateBlocksPath = documents_templates_dir() . '/template_blocks.json';
    if (!is_file($templateBlocksPath)) {
        json_save($templateBlocksPath, []);
    }

    $libraryPath = documents_media_dir() . '/library.json';
    if (!is_file($libraryPath)) {
        json_save($libraryPath, []);
    }

    $logPath = documents_logs_dir() . '/documents.log';
    if (!is_file($logPath)) {
        @file_put_contents($logPath, '', LOCK_EX);
    }
}

function safe_text($s): string
{
    $value = trim((string) $s);
    return preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
}

function safe_slug($s): string
{
    $value = strtolower(safe_text((string) $s));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function safe_filename($name): string
{
    $clean = basename((string) $name);
    $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $clean) ?? '';
    return trim($clean, '._-');
}

function json_load(string $absPath, $default)
{
    if (!is_file($absPath)) {
        return $default;
    }

    $raw = @file_get_contents($absPath);
    if (!is_string($raw) || trim($raw) === '') {
        return $default;
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    } catch (Throwable $exception) {
        documents_log('JSON parse error at ' . $absPath . ': ' . $exception->getMessage());
        return $default;
    }
}

function json_save(string $absPath, $data): array
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $msg = 'JSON encode failed for ' . $absPath;
        documents_log($msg);
        return ['ok' => false, 'error' => $msg];
    }

    $dir = dirname($absPath);
    documents_ensure_dir($dir);

    $tmp = $absPath . '.tmp';
    $bytes = @file_put_contents($tmp, $json . PHP_EOL, LOCK_EX);
    if ($bytes === false) {
        $msg = 'Write failed for ' . $absPath;
        documents_log($msg);
        return ['ok' => false, 'error' => $msg];
    }

    if (!@rename($tmp, $absPath)) {
        @unlink($tmp);
        $msg = 'Atomic rename failed for ' . $absPath;
        documents_log($msg);
        return ['ok' => false, 'error' => $msg];
    }

    return ['ok' => true, 'error' => ''];
}

function current_fy_string(int $fyStartMonth = 4): string
{
    $month = (int) date('n');
    $year = (int) date('Y');

    if ($month < $fyStartMonth) {
        $start = $year - 1;
        $end = $year;
    } else {
        $start = $year;
        $end = $year + 1;
    }

    return substr((string) $start, -2) . '-' . substr((string) $end, -2);
}

function documents_company_profile_defaults(): array
{
    return [
        'company_name' => '',
        'brand_name' => 'Dakshayani Enterprises',
        'address_line' => '',
        'city' => '',
        'district' => '',
        'state' => '',
        'pin' => '',
        'phone_primary' => '',
        'phone_secondary' => '',
        'email_primary' => '',
        'email_secondary' => '',
        'website' => '',
        'gstin' => '',
        'udyam' => '',
        'jreda_license' => '',
        'dwsd_license' => '',
        'bank_name' => '',
        'bank_account_name' => '',
        'bank_account_no' => '',
        'bank_ifsc' => '',
        'bank_branch' => '',
        'upi_id' => '',
        'default_cta_line' => '',
        'logo_path' => '',
        'updated_at' => '',
    ];
}

function documents_numbering_defaults(): array
{
    return [
        'financial_year_mode' => 'FY',
        'fy_start_month' => 4,
        'rules' => [],
        'updated_at' => '',
    ];
}

function documents_template_starters(): array
{
    $items = [
        ['PM Surya Ghar – Residential Ongrid (Subsidy)', 'RES'],
        ['Residential Hybrid', 'RES'],
        ['Commercial Ongrid', 'COM'],
        ['Industrial Ongrid', 'IND'],
        ['Institutional (School/Hospital/Govt)', 'INST'],
        ['Product Quotation (Street Light / High Mast / Others)', 'PROD'],
    ];

    $now = date('c');
    $out = [];
    foreach ($items as $item) {
        $name = (string) $item[0];
        $segment = (string) $item[1];
        $out[] = [
            'id' => safe_slug($name),
            'name' => $name,
            'segment' => $segment,
            'default_doc_theme' => [
                'page_background_image' => '',
                'page_background_opacity' => 1.0,
            ],
            'notes' => '',
            'archived_flag' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    return $out;
}

function documents_seed_template_sets_if_empty(): void
{
    $path = documents_templates_dir() . '/template_sets.json';
    $rows = json_load($path, []);
    if (!is_array($rows) || count($rows) > 0) {
        return;
    }

    json_save($path, documents_template_starters());
}

function documents_handle_image_upload(array $file, string $targetDir, string $prefix = 'file_'): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'No file uploaded.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        documents_log('Upload failed: invalid tmp upload source.');
        return ['ok' => false, 'error' => 'Invalid upload source.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        documents_log('Upload failed: file size out of bounds.');
        return ['ok' => false, 'error' => 'File must be less than or equal to 5MB.'];
    }

    $info = @getimagesize($tmp);
    if ($info === false) {
        documents_log('Upload failed: getimagesize validation failed.');
        return ['ok' => false, 'error' => 'Uploaded file is not a valid image.'];
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $mime = (string) ($info['mime'] ?? '');
    if (!isset($allowed[$mime])) {
        documents_log('Upload failed: unsupported mime type ' . $mime);
        return ['ok' => false, 'error' => 'Only JPG, PNG, and WEBP are allowed.'];
    }

    documents_ensure_dir($targetDir);
    $filename = safe_filename($prefix . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime]);
    if ($filename === '') {
        documents_log('Upload failed: filename sanitization resulted in empty name.');
        return ['ok' => false, 'error' => 'Unable to generate filename.'];
    }

    $target = rtrim($targetDir, '/') . '/' . $filename;
    if (!@move_uploaded_file($tmp, $target)) {
        documents_log('Upload failed: move_uploaded_file failed.');
        return ['ok' => false, 'error' => 'Failed to store uploaded file.'];
    }

    return ['ok' => true, 'filename' => $filename, 'error' => ''];
}

function documents_quote_defaults(): array
{
    return [
        'id' => '',
        'quote_no' => '',
        'revision' => 0,
        'status' => 'Draft',
        'template_set_id' => '',
        'segment' => 'RES',
        'created_by_type' => '',
        'created_by_id' => '',
        'created_by_name' => '',
        'created_at' => '',
        'updated_at' => '',
        'party_type' => 'lead',
        'customer_mobile' => '',
        'customer_name' => '',
        'billing_address' => '',
        'site_address' => '',
        'district' => '',
        'city' => '',
        'state' => 'Jharkhand',
        'pin' => '',
        'system_type' => 'Ongrid',
        'capacity_kwp' => '',
        'project_summary_line' => '',
        'valid_until' => '',
        'pricing_mode' => 'solar_split_70_30',
        'place_of_supply_state' => 'Jharkhand',
        'tax_type' => 'CGST_SGST',
        'input_total_gst_inclusive' => 0,
        'calc' => [
            'basic_total' => 0,
            'bucket_5_basic' => 0,
            'bucket_5_gst' => 0,
            'bucket_18_basic' => 0,
            'bucket_18_gst' => 0,
            'gst_split' => [
                'cgst_5' => 0,
                'sgst_5' => 0,
                'cgst_18' => 0,
                'sgst_18' => 0,
                'igst_5' => 0,
                'igst_18' => 0,
            ],
            'grand_total' => 0,
        ],
        'special_requests_inclusive' => '',
        'special_requests_override_note' => true,
        'annexures_overrides' => [
            'cover_notes' => '',
            'system_inclusions' => '',
            'payment_terms' => '',
            'warranty' => '',
            'system_type_explainer' => '',
            'transportation' => '',
            'terms_conditions' => '',
            'pm_subsidy_info' => '',
        ],
        'template_attachments' => documents_template_attachment_defaults(),
        'links' => [
            'proforma_id' => '',
            'agreement_id' => '',
        ],
        'approval' => [
            'approved_flag' => false,
            'approved_by' => '',
            'approved_at' => '',
        ],
        'rendering' => [
            'background_image' => '',
            'background_opacity' => 1.0,
        ],
        'pdf_path' => '',
        'pdf_generated_at' => '',
    ];
}

function documents_calc_pricing(float $grandTotal, string $pricingMode, string $taxType): array
{
    $grandTotal = max(0, $grandTotal);
    $pricingMode = in_array($pricingMode, ['solar_split_70_30', 'flat_5'], true) ? $pricingMode : 'solar_split_70_30';
    $taxType = $taxType === 'IGST' ? 'IGST' : 'CGST_SGST';

    $basicTotal = 0.0;
    $bucket5Basic = 0.0;
    $bucket18Basic = 0.0;

    if ($pricingMode === 'flat_5') {
        $basicTotal = $grandTotal / 1.05;
        $bucket5Basic = $basicTotal;
    } else {
        $basicTotal = $grandTotal / 1.089;
        $bucket5Basic = 0.70 * $basicTotal;
        $bucket18Basic = 0.30 * $basicTotal;
    }

    $bucket5Gst = $bucket5Basic * 0.05;
    $bucket18Gst = $bucket18Basic * 0.18;

    $calc = [
        'basic_total' => round($basicTotal, 2),
        'bucket_5_basic' => round($bucket5Basic, 2),
        'bucket_5_gst' => round($bucket5Gst, 2),
        'bucket_18_basic' => round($bucket18Basic, 2),
        'bucket_18_gst' => round($bucket18Gst, 2),
        'gst_split' => [
            'cgst_5' => 0.0,
            'sgst_5' => 0.0,
            'cgst_18' => 0.0,
            'sgst_18' => 0.0,
            'igst_5' => 0.0,
            'igst_18' => 0.0,
        ],
        'grand_total' => round($grandTotal, 2),
    ];

    if ($taxType === 'IGST') {
        $calc['gst_split']['igst_5'] = round($bucket5Gst, 2);
        $calc['gst_split']['igst_18'] = round($bucket18Gst, 2);
    } else {
        $calc['gst_split']['cgst_5'] = round($bucket5Gst / 2, 2);
        $calc['gst_split']['sgst_5'] = round($bucket5Gst / 2, 2);
        $calc['gst_split']['cgst_18'] = round($bucket18Gst / 2, 2);
        $calc['gst_split']['sgst_18'] = round($bucket18Gst / 2, 2);
    }

    return $calc;
}

function documents_get_template_blocks(): array
{
    $path = documents_templates_dir() . '/template_blocks.json';
    $rows = documents_normalize_template_blocks(json_load($path, []));
    return is_array($rows) ? $rows : [];
}

function documents_template_block_defaults(): array
{
    return [
        'cover_notes' => '',
        'pm_subsidy_info' => '',
        'system_inclusions' => '',
        'payment_terms' => '',
        'warranty' => '',
        'system_type_explainer' => '',
        'transportation' => '',
        'terms_conditions' => '',
        'vendor_consumer_agreement' => '',
    ];
}

function documents_template_attachment_defaults(): array
{
    return [
        'include_ongrid_diagram' => false,
        'include_hybrid_diagram' => false,
        'include_offgrid_diagram' => false,
        'ongrid_diagram_media_id' => '',
        'hybrid_diagram_media_id' => '',
        'offgrid_diagram_media_id' => '',
        'additional_media_ids' => [],
    ];
}

function documents_default_template_block_entry(): array
{
    return [
        'blocks' => documents_template_block_defaults(),
        'attachments' => documents_template_attachment_defaults(),
        'updated_at' => '',
    ];
}

function documents_normalize_template_blocks($rows): array
{
    $normalized = [];
    if (!is_array($rows)) {
        return $normalized;
    }

    foreach ($rows as $templateId => $entry) {
        if (!is_string($templateId) || $templateId === '') {
            continue;
        }
        $base = documents_default_template_block_entry();
        if (!is_array($entry)) {
            $normalized[$templateId] = $base;
            continue;
        }

        $rawBlocks = is_array($entry['blocks'] ?? null) ? $entry['blocks'] : $entry;
        foreach ($base['blocks'] as $key => $defaultValue) {
            $base['blocks'][$key] = is_string($rawBlocks[$key] ?? null) ? (string) $rawBlocks[$key] : $defaultValue;
        }

        $rawAttachments = is_array($entry['attachments'] ?? null) ? $entry['attachments'] : [];
        foreach ($base['attachments'] as $key => $defaultValue) {
            if (is_bool($defaultValue)) {
                $base['attachments'][$key] = !empty($rawAttachments[$key]);
                continue;
            }
            if (is_array($defaultValue)) {
                $base['attachments'][$key] = is_array($rawAttachments[$key] ?? null) ? array_values($rawAttachments[$key]) : [];
                continue;
            }
            $base['attachments'][$key] = safe_text($rawAttachments[$key] ?? '');
        }

        $base['updated_at'] = safe_text($entry['updated_at'] ?? '');
        $normalized[$templateId] = $base;
    }

    return $normalized;
}

function documents_sync_template_block_entries(array $templateSets): array
{
    $path = documents_templates_dir() . '/template_blocks.json';
    $rows = documents_normalize_template_blocks(json_load($path, []));
    $changed = false;
    foreach ($templateSets as $template) {
        if (!is_array($template)) {
            continue;
        }
        $templateId = safe_text($template['id'] ?? '');
        if ($templateId === '' || isset($rows[$templateId])) {
            continue;
        }
        $rows[$templateId] = documents_default_template_block_entry();
        $rows[$templateId]['updated_at'] = date('c');
        $changed = true;
    }

    if ($changed) {
        json_save($path, $rows);
    }

    return $rows;
}

function documents_quote_annexure_from_template(array $templateBlocks, string $templateSetId): array
{
    $annexure = documents_template_block_defaults();
    $entry = $templateBlocks[$templateSetId] ?? null;
    if (!is_array($entry) || !is_array($entry['blocks'] ?? null)) {
        return $annexure;
    }

    foreach ($annexure as $key => $_) {
        $annexure[$key] = safe_text($entry['blocks'][$key] ?? '');
    }

    return $annexure;
}

function documents_get_media_library(): array
{
    $rows = json_load(documents_media_dir() . '/library.json', []);
    return is_array($rows) ? $rows : [];
}

function documents_quote_pdf_dir(): string
{
    return documents_quotations_dir() . '/pdfs';
}

function resolve_public_image_to_absolute(string $publicPath): ?string
{
    $path = trim($publicPath);
    if ($path === '') {
        return null;
    }

    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'file://')) {
        return $path;
    }

    $localPath = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
    if (!is_file($localPath)) {
        return null;
    }

    return $localPath;
}

function documents_find_customer_by_mobile(string $mobile): ?array
{
    $normalized = normalize_customer_mobile($mobile);
    if ($normalized === '') {
        return null;
    }

    $path = dirname(__DIR__, 2) . '/storage/customer-records/records.json';
    $payload = json_load($path, []);
    if (!is_array($payload)) {
        return null;
    }

    $records = isset($payload['records']) && is_array($payload['records']) ? $payload['records'] : [];
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $phone = normalize_customer_mobile((string) ($record['phone'] ?? ($record['mobile_number'] ?? '')));
        if ($phone !== $normalized) {
            continue;
        }
        return [
            'customer_name' => safe_text($record['full_name'] ?? $record['consumer_name'] ?? ''),
            'billing_address' => safe_text($record['address_line'] ?? $record['address'] ?? ''),
            'site_address' => safe_text($record['address_line'] ?? $record['address'] ?? ''),
            'district' => safe_text($record['district'] ?? ''),
            'city' => safe_text($record['city'] ?? ''),
            'state' => safe_text($record['state_name'] ?? 'Jharkhand'),
            'pin' => safe_text($record['pin_code'] ?? ''),
        ];
    }

    return null;
}

function documents_list_quotes(): array
{
    documents_ensure_structure();
    $files = glob(documents_quotations_dir() . '/*.json') ?: [];
    $quotes = [];
    foreach ($files as $file) {
        if (!is_string($file)) {
            continue;
        }
        $row = json_load($file, []);
        if (!is_array($row)) {
            continue;
        }
        $quotes[] = array_merge(documents_quote_defaults(), $row);
    }

    usort($quotes, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    return $quotes;
}

function documents_get_quote(string $id): ?array
{
    $id = safe_filename($id);
    if ($id === '') {
        return null;
    }
    $path = documents_quotations_dir() . '/' . $id . '.json';
    if (!is_file($path)) {
        return null;
    }
    $row = json_load($path, []);
    if (!is_array($row)) {
        return null;
    }
    return array_merge(documents_quote_defaults(), $row);
}

function documents_save_quote(array $quote): array
{
    $id = safe_filename((string) ($quote['id'] ?? ''));
    if ($id === '') {
        return ['ok' => false, 'error' => 'Missing quote ID'];
    }
    $path = documents_quotations_dir() . '/' . $id . '.json';
    return json_save($path, $quote);
}

function documents_generate_quote_number(string $segment): array
{
    $generated = documents_generate_document_number('quotation', $segment);
    if (!$generated['ok']) {
        return ['ok' => false, 'error' => (string) ($generated['error'] ?? 'Unable to generate quote number.')];
    }

    return ['ok' => true, 'quote_no' => (string) ($generated['number'] ?? ''), 'error' => ''];
}

function documents_generate_document_number(string $docType, string $segment): array
{
    $numberingPath = documents_settings_dir() . '/numbering_rules.json';
    $payload = json_load($numberingPath, documents_numbering_defaults());
    $payload = array_merge(documents_numbering_defaults(), is_array($payload) ? $payload : []);
    $rules = isset($payload['rules']) && is_array($payload['rules']) ? $payload['rules'] : [];

    $fy = current_fy_string((int) ($payload['fy_start_month'] ?? 4));
    $selectedIndex = null;
    foreach ($rules as $index => $rule) {
        if (!is_array($rule)) {
            continue;
        }
        if (($rule['archived_flag'] ?? false) || !($rule['active'] ?? false)) {
            continue;
        }
        if (($rule['doc_type'] ?? '') !== $docType || ($rule['segment'] ?? '') !== $segment) {
            continue;
        }
        $selectedIndex = $index;
        break;
    }

    if ($selectedIndex === null) {
        return ['ok' => false, 'error' => 'No active numbering rule for ' . $docType . ' and segment ' . $segment . '.'];
    }

    $rule = $rules[$selectedIndex];
    $seqCurrent = max((int) ($rule['seq_start'] ?? 1), (int) ($rule['seq_current'] ?? 1));
    $seq = str_pad((string) $seqCurrent, max(2, (int) ($rule['seq_digits'] ?? 4)), '0', STR_PAD_LEFT);
    $format = (string) ($rule['format'] ?? '{{prefix}}/{{segment}}/{{fy}}/{{seq}}');
    $number = strtr($format, [
        '{{prefix}}' => (string) ($rule['prefix'] ?? ''),
        '{{segment}}' => $segment,
        '{{fy}}' => $fy,
        '{{seq}}' => $seq,
    ]);

    $rules[$selectedIndex]['seq_current'] = $seqCurrent + 1;
    $payload['rules'] = $rules;
    $payload['updated_at'] = date('c');
    $saved = json_save($numberingPath, $payload);
    if (!$saved['ok']) {
        return ['ok' => false, 'error' => 'Failed to update numbering rule counter.'];
    }

    return ['ok' => true, 'number' => $number, 'error' => ''];
}

function documents_proforma_defaults(): array
{
    return [
        'id' => '',
        'proforma_no' => '',
        'status' => 'Draft',
        'source_quote_id' => '',
        'source_quote_no' => '',
        'template_set_id' => '',
        'segment' => 'RES',
        'created_by_id' => '',
        'created_by_name' => '',
        'created_at' => '',
        'updated_at' => '',
        'customer_mobile' => '',
        'customer_name' => '',
        'billing_address' => '',
        'site_address' => '',
        'district' => '',
        'city' => '',
        'state' => '',
        'pin' => '',
        'system_type' => '',
        'capacity_kwp' => '',
        'pricing_mode' => 'solar_split_70_30',
        'tax_type' => 'CGST_SGST',
        'input_total_gst_inclusive' => 0,
        'calc' => documents_quote_defaults()['calc'],
        'notes_top' => '',
        'notes_bottom' => '',
        'annexures_snapshot' => documents_template_block_defaults(),
        'rendering' => ['background_image' => '', 'background_opacity' => 1.0],
        'pdf_path' => '',
        'pdf_generated_at' => '',
    ];
}

function documents_agreement_defaults(): array
{
    return [
        'id' => '',
        'agreement_no' => '',
        'status' => 'Draft',
        'source_quote_id' => '',
        'source_quote_no' => '',
        'source_proforma_id' => '',
        'source_proforma_no' => '',
        'template_set_id' => '',
        'segment' => 'RES',
        'created_by_id' => '',
        'created_by_name' => '',
        'created_at' => '',
        'updated_at' => '',
        'customer_mobile' => '',
        'customer_name' => '',
        'address' => '',
        'district' => '',
        'state' => '',
        'pin' => '',
        'system_type' => '',
        'capacity_kwp' => '',
        'agreement_text' => '',
        'special_terms_override' => '',
        'rendering' => ['background_image' => '', 'background_opacity' => 1.0],
        'pdf_path' => '',
        'pdf_generated_at' => '',
    ];
}

function documents_list_proformas(): array
{
    $files = glob(documents_proformas_dir() . '/*.json') ?: [];
    $rows = [];
    foreach ($files as $file) {
        $row = json_load((string) $file, []);
        if (is_array($row)) {
            $rows[] = array_merge(documents_proforma_defaults(), $row);
        }
    }
    usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
    return $rows;
}

function documents_get_proforma(string $id): ?array
{
    $id = safe_filename($id);
    if ($id === '') {
        return null;
    }
    $path = documents_proformas_dir() . '/' . $id . '.json';
    if (!is_file($path)) {
        return null;
    }
    $row = json_load($path, []);
    return is_array($row) ? array_merge(documents_proforma_defaults(), $row) : null;
}

function documents_save_proforma(array $row): array
{
    $id = safe_filename((string) ($row['id'] ?? ''));
    if ($id === '') {
        return ['ok' => false, 'error' => 'Missing proforma ID'];
    }
    return json_save(documents_proformas_dir() . '/' . $id . '.json', $row);
}

function documents_list_agreements(): array
{
    $files = glob(documents_agreements_dir() . '/*.json') ?: [];
    $rows = [];
    foreach ($files as $file) {
        $row = json_load((string) $file, []);
        if (is_array($row)) {
            $rows[] = array_merge(documents_agreement_defaults(), $row);
        }
    }
    usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
    return $rows;
}

function documents_get_agreement(string $id): ?array
{
    $id = safe_filename($id);
    if ($id === '') {
        return null;
    }
    $path = documents_agreements_dir() . '/' . $id . '.json';
    if (!is_file($path)) {
        return null;
    }
    $row = json_load($path, []);
    return is_array($row) ? array_merge(documents_agreement_defaults(), $row) : null;
}

function documents_save_agreement(array $row): array
{
    $id = safe_filename((string) ($row['id'] ?? ''));
    if ($id === '') {
        return ['ok' => false, 'error' => 'Missing agreement ID'];
    }
    return json_save(documents_agreements_dir() . '/' . $id . '.json', $row);
}

function documents_proforma_pdf_dir(): string
{
    return documents_proformas_dir() . '/pdfs';
}

function documents_agreement_pdf_dir(): string
{
    return documents_agreements_dir() . '/pdfs';
}
