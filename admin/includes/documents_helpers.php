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
    documents_ensure_dir(documents_public_branding_dir());
    documents_ensure_dir(documents_public_backgrounds_dir());

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
    if ($size <= 0 || $size > 3 * 1024 * 1024) {
        documents_log('Upload failed: file size out of bounds.');
        return ['ok' => false, 'error' => 'File must be less than or equal to 3MB.'];
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
