<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/customer_admin.php';

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

function documents_agreements_dir(): string
{
    return documents_base_dir() . '/agreements';
}

function documents_challans_dir(): string
{
    return documents_base_dir() . '/challans';
}

function documents_proformas_dir(): string
{
    return documents_base_dir() . '/proformas';
}

function documents_invoices_dir(): string
{
    return documents_base_dir() . '/invoices';
}

function documents_challan_pdf_dir(): string
{
    return documents_challans_dir() . '/pdfs';
}

function documents_agreement_pdf_dir(): string
{
    return documents_agreements_dir() . '/pdfs';
}

function documents_agreement_templates_path(): string
{
    return documents_templates_dir() . '/agreement_templates.json';
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
    documents_ensure_dir(documents_agreements_dir());
    documents_ensure_dir(documents_agreement_pdf_dir());
    documents_ensure_dir(documents_challans_dir());
    documents_ensure_dir(documents_challan_pdf_dir());
    documents_ensure_dir(documents_proformas_dir());
    documents_ensure_dir(documents_invoices_dir());
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
    documents_ensure_numbering_rules_for_proforma_invoice();

    $templatesPath = documents_templates_dir() . '/template_sets.json';
    if (!is_file($templatesPath)) {
        json_save($templatesPath, []);
    }

    $templateBlocksPath = documents_templates_dir() . '/template_blocks.json';
    if (!is_file($templateBlocksPath)) {
        json_save($templateBlocksPath, []);
    }

    $agreementTemplatesPath = documents_agreement_templates_path();
    if (!is_file($agreementTemplatesPath)) {
        json_save($agreementTemplatesPath, documents_agreement_template_defaults());
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

function documents_agreement_template_defaults(): array
{
    $now = date('c');
    $html = <<<'HTML'
<h2 style="text-align:center;margin:0 0 12px 0;">PM Surya Ghar – Vendor Consumer Agreement</h2>
<p>This Agreement is executed on <strong>{{execution_date}}</strong> between:</p>
<p><strong>Vendor:</strong> {{vendor_name}}, {{vendor_address}}</p>
<p><strong>Consumer:</strong> {{consumer_name}}, Consumer Account No. {{consumer_account_no}}, Address: {{consumer_address}} ({{consumer_location}})</p>
<p>Whereas the Consumer has engaged the Vendor for design, supply, installation, testing, and commissioning of rooftop solar photovoltaic system of <strong>{{system_capacity_kwp}} kWp</strong> at the consumer site address: <strong>{{consumer_site_address}}</strong>.</p>
<p>The total RTS system cost agreed between both parties is <strong>₹{{total_cost}}</strong> (inclusive of applicable taxes, unless otherwise stated in linked quotation/work order).</p>
<ol>
  <li>The Vendor shall execute the work as per approved technical standards, DISCOM norms, and applicable PM Surya Ghar guidelines.</li>
  <li>The Consumer shall provide site readiness, access, and required statutory documents for execution and subsidy-related processing.</li>
  <li>Any subsidy is subject to government policy, portal validation, and discom approval, and will be credited to consumer account as per applicable process.</li>
  <li>Payment milestones, material specifications, and commercial conditions shall follow the accepted quotation and mutually agreed written terms.</li>
  <li>Post-installation support, warranty, and service commitments shall be as per issued handover/warranty documents and accepted quotation.</li>
</ol>
<p>This Agreement is read, understood, and accepted by both parties.</p>
<table style="width:100%; margin-top:24px; border-collapse:collapse;">
  <tr>
    <td style="width:50%; vertical-align:top; padding-top:24px;">For Vendor<br><strong>{{vendor_name}}</strong><br>Authorized Signatory</td>
    <td style="width:50%; vertical-align:top; padding-top:24px;">For Consumer<br><strong>{{consumer_name}}</strong><br>Signature</td>
  </tr>
</table>
HTML;

    return [
        'default_pm_surya_ghar_agreement' => [
            'id' => 'default_pm_surya_ghar_agreement',
            'name' => 'PM Surya Ghar – Vendor Consumer Agreement (Default)',
            'archived_flag' => false,
            'html_template' => $html,
            'placeholders' => [
                '{{execution_date}}',
                '{{system_capacity_kwp}}',
                '{{consumer_name}}',
                '{{consumer_account_no}}',
                '{{consumer_address}}',
                '{{consumer_site_address}}',
                '{{consumer_location}}',
                '{{vendor_name}}',
                '{{vendor_address}}',
                '{{total_cost}}',
            ],
            'updated_at' => $now,
        ],
    ];
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


function documents_ensure_numbering_rules_for_proforma_invoice(): void
{
    $path = documents_settings_dir() . '/numbering_rules.json';
    $payload = json_load($path, documents_numbering_defaults());
    $payload = array_merge(documents_numbering_defaults(), is_array($payload) ? $payload : []);
    $rules = isset($payload['rules']) && is_array($payload['rules']) ? $payload['rules'] : [];

    $required = [
        ['doc_type' => 'proforma', 'prefix' => 'PI'],
        ['doc_type' => 'invoice_public', 'prefix' => 'INV'],
    ];
    $segments = ['RES', 'COM', 'IND', 'INST', 'PROD'];

    $changed = false;
    foreach ($required as $entry) {
        foreach ($segments as $segment) {
            $exists = false;
            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                if (($rule['doc_type'] ?? '') === $entry['doc_type'] && ($rule['segment'] ?? '') === $segment && !($rule['archived_flag'] ?? false) && ($rule['active'] ?? false)) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) {
                continue;
            }

            $rules[] = [
                'id' => safe_slug($entry['doc_type'] . '_' . $segment . '_' . bin2hex(random_bytes(3))),
                'doc_type' => $entry['doc_type'],
                'segment' => $segment,
                'prefix' => $entry['prefix'],
                'format' => '{{prefix}}/{{segment}}/{{fy}}/{{seq}}',
                'seq_start' => 1,
                'seq_current' => 1,
                'seq_digits' => 4,
                'active' => true,
                'archived_flag' => false,
                'notes' => 'Auto-seeded by workflow.',
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ];
            $changed = true;
        }
    }

    if ($changed) {
        $payload['rules'] = $rules;
        $payload['updated_at'] = date('c');
        $saved = json_save($path, $payload);
        if (!$saved['ok']) {
            documents_log('Failed to auto seed proforma/invoice numbering rules.');
        }
    }
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

function documents_customer_snapshot_defaults(): array
{
    return [
        'mobile' => '',
        'name' => '',
        'address' => '',
        'city' => '',
        'district' => '',
        'pin_code' => '',
        'state' => '',
        'meter_number' => '',
        'meter_serial_number' => '',
        'consumer_account_no' => '',
        'application_id' => '',
        'application_submitted_date' => '',
        'sanction_load_kwp' => '',
        'installed_pv_module_capacity_kwp' => '',
        'circle_name' => '',
        'division_name' => '',
        'sub_division_name' => '',
    ];
}

function documents_quote_defaults(): array
{
    return [
        'id' => '',
        'quote_no' => '',
        'revision' => 0,
        'status' => 'Draft',
        'approval' => [
            'approved_by_id' => '',
            'approved_by_name' => '',
            'approved_at' => '',
        ],
        'acceptance' => [
            'accepted_by_admin_id' => '',
            'accepted_by_admin_name' => '',
            'accepted_at' => '',
            'accepted_note' => '',
        ],
        'links' => [
            'customer_mobile' => '',
            'agreement_id' => '',
            'proforma_id' => '',
            'invoice_id' => '',
        ],
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
        'consumer_account_no' => '',
        'billing_address' => '',
        'site_address' => '',
        'district' => '',
        'city' => '',
        'state' => 'Jharkhand',
        'pin' => '',
        'meter_number' => '',
        'meter_serial_number' => '',
        'application_id' => '',
        'application_submitted_date' => '',
        'sanction_load_kwp' => '',
        'installed_pv_module_capacity_kwp' => '',
        'circle_name' => '',
        'division_name' => '',
        'sub_division_name' => '',
        'customer_snapshot' => documents_customer_snapshot_defaults(),
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
        'rendering' => [
            'background_image' => '',
            'background_opacity' => 1.0,
        ],
        'pdf_path' => '',
        'pdf_generated_at' => '',
    ];
}

function documents_proforma_defaults(): array
{
    return [
        'id' => '',
        'proforma_no' => '',
        'status' => 'Draft',
        'linked_quote_id' => '',
        'customer_mobile' => '',
        'customer_snapshot' => documents_customer_snapshot_defaults(),
        'capacity_kwp' => '',
        'pricing_mode' => 'solar_split_70_30',
        'input_total_gst_inclusive' => 0,
        'calc' => [],
        'created_at' => '',
        'updated_at' => '',
    ];
}

function documents_invoice_defaults(): array
{
    return [
        'id' => '',
        'invoice_no' => '',
        'status' => 'Draft',
        'invoice_kind' => 'public',
        'linked_quote_id' => '',
        'customer_mobile' => '',
        'customer_snapshot' => documents_customer_snapshot_defaults(),
        'capacity_kwp' => '',
        'pricing_mode' => 'solar_split_70_30',
        'input_total_gst_inclusive' => 0,
        'calc' => [],
        'created_at' => '',
        'updated_at' => '',
    ];
}

function documents_normalize_consumer_account_no(array $row): string
{
    foreach (['jbvnl_account_number', 'consumer_no', 'consumer_number'] as $key) {
        $value = safe_text((string) ($row[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function documents_map_customer_record(array $customer): array
{
    return [
        'mobile' => normalize_customer_mobile((string) ($customer['mobile'] ?? '')),
        'name' => safe_text((string) ($customer['name'] ?? '')),
        'address' => safe_text((string) ($customer['address'] ?? '')),
        'city' => safe_text((string) ($customer['city'] ?? '')),
        'district' => safe_text((string) ($customer['district'] ?? '')),
        'pin_code' => safe_text((string) ($customer['pin_code'] ?? '')),
        'state' => safe_text((string) ($customer['state'] ?? '')),
        'meter_number' => safe_text((string) ($customer['meter_number'] ?? '')),
        'meter_serial_number' => safe_text((string) ($customer['meter_serial_number'] ?? '')),
        'consumer_account_no' => documents_normalize_consumer_account_no($customer),
        'application_id' => safe_text((string) ($customer['application_id'] ?? '')),
        'application_submitted_date' => safe_text((string) ($customer['application_submitted_date'] ?? '')),
        'sanction_load_kwp' => safe_text((string) ($customer['sanction_load_kwp'] ?? '')),
        'installed_pv_module_capacity_kwp' => safe_text((string) ($customer['installed_pv_module_capacity_kwp'] ?? '')),
        'circle_name' => safe_text((string) ($customer['circle_name'] ?? '')),
        'division_name' => safe_text((string) ($customer['division_name'] ?? '')),
        'sub_division_name' => safe_text((string) ($customer['sub_division_name'] ?? '')),
    ];
}

function documents_snapshot_from_customer(array $customer): array
{
    return array_merge(documents_customer_snapshot_defaults(), documents_map_customer_record($customer));
}

function documents_quote_resolve_snapshot(array $quote): array
{
    $snapshot = is_array($quote['customer_snapshot'] ?? null)
        ? array_merge(documents_customer_snapshot_defaults(), $quote['customer_snapshot'])
        : documents_customer_snapshot_defaults();

    if ($snapshot['mobile'] === '' && safe_text((string) ($quote['customer_mobile'] ?? '')) !== '') {
        $snapshot['mobile'] = normalize_customer_mobile((string) ($quote['customer_mobile'] ?? ''));
    }
    if ($snapshot['name'] === '' && safe_text((string) ($quote['customer_name'] ?? '')) !== '') {
        $snapshot['name'] = safe_text((string) ($quote['customer_name'] ?? ''));
    }

    $hasData = false;
    foreach ($snapshot as $value) {
        if (safe_text((string) $value) !== '') {
            $hasData = true;
            break;
        }
    }

    if (!$hasData) {
        $customer = documents_find_customer_by_mobile((string) ($quote['customer_mobile'] ?? ''));
        if (is_array($customer)) {
            $snapshot = array_merge($snapshot, documents_snapshot_from_customer($customer));
        }
    }

    return $snapshot;
}

function documents_build_quote_snapshot_from_request(array $input, string $partyType, ?array $customer): array
{
    $snapshot = documents_customer_snapshot_defaults();
    if ($customer !== null) {
        $snapshot = array_merge($snapshot, documents_snapshot_from_customer($customer));
    }

    $snapshot['mobile'] = normalize_customer_mobile((string) ($input['customer_mobile'] ?? $snapshot['mobile']));
    $snapshot['name'] = safe_text((string) ($input['customer_name'] ?? $snapshot['name']));
    $snapshot['address'] = safe_text((string) ($input['site_address'] ?? $snapshot['address']));
    $snapshot['city'] = safe_text((string) ($input['city'] ?? $snapshot['city']));
    $snapshot['district'] = safe_text((string) ($input['district'] ?? $snapshot['district']));
    $snapshot['pin_code'] = safe_text((string) ($input['pin'] ?? $snapshot['pin_code']));
    $snapshot['state'] = safe_text((string) ($input['state'] ?? $snapshot['state']));
    $snapshot['meter_number'] = safe_text((string) ($input['meter_number'] ?? $snapshot['meter_number']));
    $snapshot['meter_serial_number'] = safe_text((string) ($input['meter_serial_number'] ?? $snapshot['meter_serial_number']));
    $snapshot['consumer_account_no'] = safe_text((string) ($input['consumer_account_no'] ?? $snapshot['consumer_account_no']));
    $snapshot['application_id'] = safe_text((string) ($input['application_id'] ?? $snapshot['application_id']));
    $snapshot['application_submitted_date'] = safe_text((string) ($input['application_submitted_date'] ?? $snapshot['application_submitted_date']));
    $snapshot['sanction_load_kwp'] = safe_text((string) ($input['sanction_load_kwp'] ?? $snapshot['sanction_load_kwp']));
    $snapshot['installed_pv_module_capacity_kwp'] = safe_text((string) ($input['installed_pv_module_capacity_kwp'] ?? $snapshot['installed_pv_module_capacity_kwp']));
    $snapshot['circle_name'] = safe_text((string) ($input['circle_name'] ?? $snapshot['circle_name']));
    $snapshot['division_name'] = safe_text((string) ($input['division_name'] ?? $snapshot['division_name']));
    $snapshot['sub_division_name'] = safe_text((string) ($input['sub_division_name'] ?? $snapshot['sub_division_name']));

    if ($partyType === 'lead') {
        $leadSnapshot = documents_customer_snapshot_defaults();
        $leadSnapshot['mobile'] = $snapshot['mobile'];
        $leadSnapshot['name'] = $snapshot['name'];
        $leadSnapshot['address'] = $snapshot['address'];
        $leadSnapshot['consumer_account_no'] = $snapshot['consumer_account_no'];
        return $leadSnapshot;
    }

    return $snapshot;
}

function documents_agreement_defaults(): array
{
    return [
        'id' => '',
        'agreement_no' => '',
        'status' => 'Draft',
        'template_id' => 'default_pm_surya_ghar_agreement',
        'customer_mobile' => '',
        'customer_name' => '',
        'consumer_account_no' => '',
        'consumer_address' => '',
        'site_address' => '',
        'execution_date' => '',
        'system_capacity_kwp' => '',
        'total_cost' => '',
        'linked_quote_id' => '',
        'linked_quote_no' => '',
        'district' => '',
        'city' => '',
        'state' => '',
        'pin_code' => '',
        'party_snapshot' => [
            'customer_mobile' => '',
            'customer_name' => '',
            'consumer_account_no' => '',
            'consumer_address' => '',
            'site_address' => '',
            'district' => '',
            'city' => '',
            'state' => '',
            'pin_code' => '',
            'system_capacity_kwp' => '',
            'total_cost' => '',
        ],
        'overrides' => [
            'html_override' => '',
            'fields_override' => [
                'execution_date' => '',
                'system_capacity_kwp' => '',
                'total_cost' => '',
                'consumer_account_no' => '',
                'consumer_address' => '',
                'site_address' => '',
            ],
        ],
        'rendering' => [
            'background_image' => '',
            'background_opacity' => 1.0,
        ],
        'created_by_type' => 'admin',
        'created_by_id' => '',
        'created_by_name' => '',
        'created_at' => '',
        'updated_at' => '',
        'pdf_path' => '',
        'pdf_generated_at' => '',
    ];
}

function documents_challan_defaults(): array
{
    return [
        'id' => '',
        'challan_no' => '',
        'status' => 'Draft',
        'segment' => 'RES',
        'template_set_id' => '',
        'linked_quote_id' => '',
        'linked_quote_no' => '',
        'party_type' => 'lead',
        'customer_snapshot' => documents_customer_snapshot_defaults(),
        'site_address' => '',
        'delivery_address' => '',
        'delivery_date' => '',
        'vehicle_no' => '',
        'driver_name' => '',
        'delivery_notes' => '',
        'items' => [],
        'created_by_type' => '',
        'created_by_id' => '',
        'created_by_name' => '',
        'created_at' => '',
        'updated_at' => '',
        'rendering' => [
            'background_image' => '',
            'background_opacity' => 1.0,
        ],
        'pdf_path' => '',
        'pdf_generated_at' => '',
    ];
}

function documents_challan_item_defaults(): array
{
    return [
        'name' => '',
        'description' => '',
        'unit' => 'Nos',
        'qty' => 0,
        'remarks' => '',
    ];
}

function documents_normalize_challan_items(array $items): array
{
    $rows = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $row = array_merge(documents_challan_item_defaults(), $item);
        $row['name'] = safe_text((string) ($row['name'] ?? ''));
        $row['description'] = safe_text((string) ($row['description'] ?? ''));
        $row['unit'] = safe_text((string) ($row['unit'] ?? 'Nos')) ?: 'Nos';
        $row['qty'] = max(0, (float) ($row['qty'] ?? 0));
        $row['remarks'] = safe_text((string) ($row['remarks'] ?? ''));
        if ($row['name'] === '' && $row['description'] === '' && $row['qty'] <= 0) {
            continue;
        }
        $rows[] = $row;
    }

    return $rows;
}

function documents_challan_has_valid_items(array $challan): bool
{
    $items = documents_normalize_challan_items(is_array($challan['items'] ?? null) ? $challan['items'] : []);
    foreach ($items as $row) {
        if ((string) ($row['name'] ?? '') !== '' && (float) ($row['qty'] ?? 0) > 0) {
            return true;
        }
    }

    return false;
}

function documents_generate_challan_number(string $segment): array
{
    $number = documents_generate_document_number('challan', $segment);
    if (!$number['ok']) {
        return $number;
    }

    return ['ok' => true, 'challan_no' => (string) ($number['doc_no'] ?? ''), 'error' => ''];
}

function documents_get_challan(string $id): ?array
{
    $id = safe_filename($id);
    if ($id === '') {
        return null;
    }

    $path = documents_challans_dir() . '/' . $id . '.json';
    if (!is_file($path)) {
        return null;
    }

    $row = json_load($path, []);
    if (!is_array($row)) {
        return null;
    }

    $challan = array_merge(documents_challan_defaults(), $row);
    $challan['customer_snapshot'] = array_merge(documents_customer_snapshot_defaults(), is_array($challan['customer_snapshot'] ?? null) ? $challan['customer_snapshot'] : []);
    $challan['items'] = documents_normalize_challan_items(is_array($challan['items'] ?? null) ? $challan['items'] : []);
    return $challan;
}

function documents_list_challans(): array
{
    documents_ensure_structure();
    $files = glob(documents_challans_dir() . '/*.json') ?: [];
    $rows = [];
    foreach ($files as $file) {
        if (!is_string($file)) {
            continue;
        }
        $row = json_load($file, []);
        if (!is_array($row)) {
            continue;
        }
        $challan = array_merge(documents_challan_defaults(), $row);
        $challan['customer_snapshot'] = array_merge(documents_customer_snapshot_defaults(), is_array($challan['customer_snapshot'] ?? null) ? $challan['customer_snapshot'] : []);
        $challan['items'] = documents_normalize_challan_items(is_array($challan['items'] ?? null) ? $challan['items'] : []);
        $rows[] = $challan;
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    return $rows;
}

function documents_save_challan(array $challan): array
{
    $id = safe_filename((string) ($challan['id'] ?? ''));
    if ($id === '') {
        return ['ok' => false, 'error' => 'Missing challan ID'];
    }
    return json_save(documents_challans_dir() . '/' . $id . '.json', $challan);
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

    $store = new CustomerFsStore();
    $record = $store->findByMobile($normalized);
    if ($record === null) {
        return null;
    }

    return documents_map_customer_record($record);
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
    if (!is_array($row)) {
        return null;
    }
    $doc = array_merge(documents_proforma_defaults(), $row);
    $doc['customer_snapshot'] = array_merge(documents_customer_snapshot_defaults(), is_array($doc['customer_snapshot'] ?? null) ? $doc['customer_snapshot'] : []);
    return $doc;
}

function documents_save_proforma(array $doc): array
{
    $id = safe_filename((string) ($doc['id'] ?? ''));
    if ($id === '') {
        return ['ok' => false, 'error' => 'Missing proforma ID'];
    }
    return json_save(documents_proformas_dir() . '/' . $id . '.json', $doc);
}

function documents_get_invoice(string $id): ?array
{
    $id = safe_filename($id);
    if ($id === '') {
        return null;
    }
    $path = documents_invoices_dir() . '/' . $id . '.json';
    if (!is_file($path)) {
        return null;
    }
    $row = json_load($path, []);
    if (!is_array($row)) {
        return null;
    }
    $doc = array_merge(documents_invoice_defaults(), $row);
    $doc['customer_snapshot'] = array_merge(documents_customer_snapshot_defaults(), is_array($doc['customer_snapshot'] ?? null) ? $doc['customer_snapshot'] : []);
    return $doc;
}

function documents_save_invoice(array $doc): array
{
    $id = safe_filename((string) ($doc['id'] ?? ''));
    if ($id === '') {
        return ['ok' => false, 'error' => 'Missing invoice ID'];
    }
    return json_save(documents_invoices_dir() . '/' . $id . '.json', $doc);
}

function documents_generate_proforma_number(string $segment): array
{
    $number = documents_generate_document_number('proforma', $segment);
    if (!$number['ok']) {
        return $number;
    }
    return ['ok' => true, 'proforma_no' => (string) ($number['doc_no'] ?? ''), 'error' => ''];
}

function documents_generate_invoice_public_number(string $segment): array
{
    $number = documents_generate_document_number('invoice_public', $segment);
    if (!$number['ok']) {
        return $number;
    }
    return ['ok' => true, 'invoice_no' => (string) ($number['doc_no'] ?? ''), 'error' => ''];
}

function documents_status_label(array $quote, string $viewerType = 'admin'): string
{
    $status = (string) ($quote['status'] ?? 'Draft');
    if ($status === 'Draft') {
        if (($quote['created_by_type'] ?? '') === 'employee') {
            return $viewerType === 'employee' ? 'Pending Admin Approval' : 'Needs Approval';
        }
        return 'Draft';
    }
    return $status;
}

function documents_quote_can_edit(array $quote, string $viewerType, string $viewerId = ''): bool
{
    if ((string) ($quote['status'] ?? 'Draft') !== 'Draft') {
        return false;
    }
    if ($viewerType === 'admin') {
        return true;
    }
    if ($viewerType === 'employee') {
        return ((string) ($quote['created_by_type'] ?? '') === 'employee') && ((string) ($quote['created_by_id'] ?? '') === $viewerId);
    }
    return false;
}

function documents_quote_has_valid_acceptance_data(array $quote): array
{
    $snapshot = documents_quote_resolve_snapshot($quote);
    $name = safe_text((string) ($snapshot['name'] ?? $quote['customer_name'] ?? ''));
    $mobile = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
    $siteAddress = safe_text((string) ($quote['site_address'] ?? $snapshot['address'] ?? ''));
    $capacity = safe_text((string) ($quote['capacity_kwp'] ?? ''));
    $amount = (float) ($quote['input_total_gst_inclusive'] ?? 0);

    if ($name === '' || $mobile === '' || $siteAddress === '') {
        return ['ok' => false, 'error' => 'Customer name, mobile, and site address are required before acceptance.'];
    }
    if ($capacity === '' || $amount <= 0) {
        return ['ok' => false, 'error' => 'Capacity and total amount are required before acceptance.'];
    }

    return ['ok' => true, 'error' => ''];
}

function documents_upsert_customer_from_quote(array $quote): array
{
    $snapshot = documents_quote_resolve_snapshot($quote);
    $mobile = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
    $name = safe_text((string) ($snapshot['name'] ?? $quote['customer_name'] ?? ''));
    if ($mobile === '' || $name === '') {
        return ['ok' => false, 'error' => 'Cannot create customer without mobile and name.', 'customer' => null];
    }

    $store = new CustomerFsStore();
    $existing = $store->findByMobile($mobile);

    $fields = [
        'mobile' => $mobile,
        'name' => $name,
        'address' => safe_text((string) ($quote['site_address'] ?? $snapshot['address'] ?? '')),
        'city' => safe_text((string) ($quote['city'] ?? $snapshot['city'] ?? '')),
        'district' => safe_text((string) ($quote['district'] ?? $snapshot['district'] ?? '')),
        'pin_code' => safe_text((string) ($quote['pin'] ?? $snapshot['pin_code'] ?? '')),
        'state' => safe_text((string) ($quote['state'] ?? $snapshot['state'] ?? '')),
        'jbvnl_account_number' => safe_text((string) ($quote['consumer_account_no'] ?? $snapshot['consumer_account_no'] ?? '')),
        'application_id' => safe_text((string) ($quote['application_id'] ?? $snapshot['application_id'] ?? '')),
        'application_submitted_date' => safe_text((string) ($quote['application_submitted_date'] ?? $snapshot['application_submitted_date'] ?? '')),
        'circle_name' => safe_text((string) ($quote['circle_name'] ?? $snapshot['circle_name'] ?? '')),
        'division_name' => safe_text((string) ($quote['division_name'] ?? $snapshot['division_name'] ?? '')),
        'sub_division_name' => safe_text((string) ($quote['sub_division_name'] ?? $snapshot['sub_division_name'] ?? '')),
        'sanction_load_kwp' => safe_text((string) ($quote['sanction_load_kwp'] ?? $snapshot['sanction_load_kwp'] ?? '')),
        'installed_pv_module_capacity_kwp' => safe_text((string) ($quote['installed_pv_module_capacity_kwp'] ?? $snapshot['installed_pv_module_capacity_kwp'] ?? '')),
        'created_from_quote_id' => safe_text((string) ($quote['id'] ?? '')),
        'created_from_quote_no' => safe_text((string) ($quote['quote_no'] ?? '')),
    ];

    if ($existing === null) {
        $fields['password_hash'] = password_hash('abcd1234', PASSWORD_DEFAULT);
        $created = $store->addCustomer($fields);
        if (!($created['success'] ?? false)) {
            documents_log('customer creation failed for quote ' . (string) ($quote['id'] ?? '') . ': ' . implode('; ', $created['errors'] ?? []));
            return ['ok' => false, 'error' => 'Customer creation failed.', 'customer' => null];
        }
        return ['ok' => true, 'error' => '', 'customer' => $created['customer'] ?? null];
    }

    $update = $existing;
    foreach ($fields as $key => $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        if (!isset($update[$key]) || trim((string) $update[$key]) === '') {
            $update[$key] = $value;
        }
    }

    $saved = $store->updateCustomer($mobile, $update);
    if (!($saved['success'] ?? false)) {
        documents_log('customer update failed for quote ' . (string) ($quote['id'] ?? '') . ': ' . implode('; ', $saved['errors'] ?? []));
        return ['ok' => false, 'error' => 'Customer update failed.', 'customer' => null];
    }
    return ['ok' => true, 'error' => '', 'customer' => $saved['customer'] ?? null];
}

function documents_create_agreement_from_quote(array $quote, array $adminUser): array
{
    $links = is_array($quote['links'] ?? null) ? $quote['links'] : [];
    $existingId = safe_text((string) ($links['agreement_id'] ?? ''));
    if ($existingId !== '' && documents_get_agreement($existingId) !== null) {
        return ['ok' => true, 'agreement_id' => $existingId, 'error' => ''];
    }

    $segment = safe_text((string) ($quote['segment'] ?? 'RES')) ?: 'RES';
    $number = documents_generate_agreement_number($segment);
    if (!$number['ok']) {
        documents_log('numbering rule missing for agreement quote ' . (string) ($quote['id'] ?? ''));
        return ['ok' => false, 'error' => (string) ($number['error'] ?? 'Unable to generate agreement number.')];
    }

    $snapshot = documents_quote_resolve_snapshot($quote);
    $templates = documents_get_agreement_templates();
    $templateId = isset($templates['default_pm_surya_ghar_agreement']) ? 'default_pm_surya_ghar_agreement' : array_key_first($templates);

    $agreement = documents_agreement_defaults();
    $agreement['id'] = 'agr_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    $agreement['agreement_no'] = (string) ($number['agreement_no'] ?? '');
    $agreement['status'] = 'Draft';
    $agreement['template_id'] = is_string($templateId) ? $templateId : 'default_pm_surya_ghar_agreement';
    $agreement['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
    $agreement['customer_name'] = safe_text((string) ($snapshot['name'] ?? $quote['customer_name'] ?? ''));
    $agreement['consumer_account_no'] = safe_text((string) ($quote['consumer_account_no'] ?? $snapshot['consumer_account_no'] ?? ''));
    $agreement['consumer_address'] = safe_text((string) ($snapshot['address'] ?? ''));
    $agreement['site_address'] = safe_text((string) ($quote['site_address'] ?? $snapshot['address'] ?? ''));
    $agreement['execution_date'] = date('Y-m-d');
    $agreement['system_capacity_kwp'] = safe_text((string) ($quote['capacity_kwp'] ?? ''));
    $agreement['total_cost'] = documents_format_money_indian((float) ($quote['input_total_gst_inclusive'] ?? 0));
    $agreement['linked_quote_id'] = safe_text((string) ($quote['id'] ?? ''));
    $agreement['linked_quote_no'] = safe_text((string) ($quote['quote_no'] ?? ''));
    $agreement['district'] = safe_text((string) ($quote['district'] ?? $snapshot['district'] ?? ''));
    $agreement['city'] = safe_text((string) ($quote['city'] ?? $snapshot['city'] ?? ''));
    $agreement['state'] = safe_text((string) ($quote['state'] ?? $snapshot['state'] ?? ''));
    $agreement['pin_code'] = safe_text((string) ($quote['pin'] ?? $snapshot['pin_code'] ?? ''));
    $agreement['party_snapshot'] = [
        'customer_mobile' => $agreement['customer_mobile'],
        'customer_name' => $agreement['customer_name'],
        'consumer_account_no' => $agreement['consumer_account_no'],
        'consumer_address' => $agreement['consumer_address'],
        'site_address' => $agreement['site_address'],
        'district' => $agreement['district'],
        'city' => $agreement['city'],
        'state' => $agreement['state'],
        'pin_code' => $agreement['pin_code'],
        'system_capacity_kwp' => $agreement['system_capacity_kwp'],
        'total_cost' => $agreement['total_cost'],
    ];
    $agreement['created_by_type'] = 'admin';
    $agreement['created_by_id'] = safe_text((string) ($adminUser['id'] ?? ''));
    $agreement['created_by_name'] = safe_text((string) ($adminUser['full_name'] ?? 'Admin'));
    $agreement['created_at'] = date('c');
    $agreement['updated_at'] = date('c');

    $saved = documents_save_agreement($agreement);
    if (!$saved['ok']) {
        documents_log('file save failed for agreement quote ' . (string) ($quote['id'] ?? ''));
        return ['ok' => false, 'error' => 'Failed to create agreement draft.'];
    }

    return ['ok' => true, 'agreement_id' => (string) $agreement['id'], 'error' => ''];
}

function documents_create_proforma_from_quote(array $quote): array
{
    $links = is_array($quote['links'] ?? null) ? $quote['links'] : [];
    $existingId = safe_text((string) ($links['proforma_id'] ?? ''));
    if ($existingId !== '' && documents_get_proforma($existingId) !== null) {
        return ['ok' => true, 'proforma_id' => $existingId, 'error' => ''];
    }

    $segment = safe_text((string) ($quote['segment'] ?? 'RES')) ?: 'RES';
    $number = documents_generate_proforma_number($segment);
    if (!$number['ok']) {
        documents_log('numbering rule missing for proforma quote ' . (string) ($quote['id'] ?? ''));
        return ['ok' => false, 'error' => (string) ($number['error'] ?? 'Unable to generate proforma number.')];
    }

    $snapshot = documents_quote_resolve_snapshot($quote);
    $doc = documents_proforma_defaults();
    $doc['id'] = 'pi_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    $doc['proforma_no'] = (string) ($number['proforma_no'] ?? '');
    $doc['status'] = 'Draft';
    $doc['linked_quote_id'] = safe_text((string) ($quote['id'] ?? ''));
    $doc['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
    $doc['customer_snapshot'] = $snapshot;
    $doc['capacity_kwp'] = safe_text((string) ($quote['capacity_kwp'] ?? ''));
    $doc['pricing_mode'] = safe_text((string) ($quote['pricing_mode'] ?? 'solar_split_70_30'));
    $doc['input_total_gst_inclusive'] = (float) ($quote['input_total_gst_inclusive'] ?? 0);
    $doc['calc'] = is_array($quote['calc'] ?? null) ? $quote['calc'] : [];
    $doc['created_at'] = date('c');
    $doc['updated_at'] = date('c');

    $saved = documents_save_proforma($doc);
    if (!$saved['ok']) {
        documents_log('file save failed for proforma quote ' . (string) ($quote['id'] ?? ''));
        return ['ok' => false, 'error' => 'Failed to create proforma draft.'];
    }

    return ['ok' => true, 'proforma_id' => (string) $doc['id'], 'error' => ''];
}

function documents_create_invoice_from_quote(array $quote): array
{
    $links = is_array($quote['links'] ?? null) ? $quote['links'] : [];
    $existingId = safe_text((string) ($links['invoice_id'] ?? ''));
    if ($existingId !== '' && documents_get_invoice($existingId) !== null) {
        return ['ok' => true, 'invoice_id' => $existingId, 'error' => ''];
    }

    $segment = safe_text((string) ($quote['segment'] ?? 'RES')) ?: 'RES';
    $number = documents_generate_invoice_public_number($segment);
    if (!$number['ok']) {
        documents_log('numbering rule missing for invoice_public quote ' . (string) ($quote['id'] ?? ''));
        return ['ok' => false, 'error' => (string) ($number['error'] ?? 'Unable to generate invoice number.')];
    }

    $snapshot = documents_quote_resolve_snapshot($quote);
    $doc = documents_invoice_defaults();
    $doc['id'] = 'inv_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    $doc['invoice_no'] = (string) ($number['invoice_no'] ?? '');
    $doc['status'] = 'Draft';
    $doc['invoice_kind'] = 'public';
    $doc['linked_quote_id'] = safe_text((string) ($quote['id'] ?? ''));
    $doc['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
    $doc['customer_snapshot'] = $snapshot;
    $doc['capacity_kwp'] = safe_text((string) ($quote['capacity_kwp'] ?? ''));
    $doc['pricing_mode'] = safe_text((string) ($quote['pricing_mode'] ?? 'solar_split_70_30'));
    $doc['input_total_gst_inclusive'] = (float) ($quote['input_total_gst_inclusive'] ?? 0);
    $doc['calc'] = is_array($quote['calc'] ?? null) ? $quote['calc'] : [];
    $doc['created_at'] = date('c');
    $doc['updated_at'] = date('c');

    $saved = documents_save_invoice($doc);
    if (!$saved['ok']) {
        documents_log('file save failed for invoice quote ' . (string) ($quote['id'] ?? ''));
        return ['ok' => false, 'error' => 'Failed to create invoice draft.'];
    }

    return ['ok' => true, 'invoice_id' => (string) $doc['id'], 'error' => ''];
}

function documents_generate_quote_number(string $segment): array
{
    $number = documents_generate_document_number('quotation', $segment);
    if (!$number['ok']) {
        return $number;
    }
    return ['ok' => true, 'quote_no' => (string) ($number['doc_no'] ?? ''), 'error' => ''];
}

function documents_generate_agreement_number(string $segment = 'RES'): array
{
    $number = documents_generate_document_number('agreement', $segment);
    if (!$number['ok']) {
        return $number;
    }
    return ['ok' => true, 'agreement_no' => (string) ($number['doc_no'] ?? ''), 'error' => ''];
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
        return ['ok' => false, 'error' => 'No active ' . $docType . ' numbering rule for segment ' . $segment . '.'];
    }

    $rule = $rules[$selectedIndex];
    $seqCurrent = max((int) ($rule['seq_start'] ?? 1), (int) ($rule['seq_current'] ?? 1));
    $seq = str_pad((string) $seqCurrent, max(2, (int) ($rule['seq_digits'] ?? 4)), '0', STR_PAD_LEFT);
    $format = (string) ($rule['format'] ?? '{{prefix}}/{{segment}}/{{fy}}/{{seq}}');
    $docNo = strtr($format, [
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

    return ['ok' => true, 'doc_no' => $docNo, 'error' => ''];
}

function documents_list_agreements(): array
{
    documents_ensure_structure();
    $files = glob(documents_agreements_dir() . '/*.json') ?: [];
    $rows = [];
    foreach ($files as $file) {
        if (!is_string($file) || str_ends_with($file, '/agreement_templates.json')) {
            continue;
        }
        $row = json_load($file, []);
        if (!is_array($row)) {
            continue;
        }
        $rows[] = array_merge(documents_agreement_defaults(), $row);
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

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
    if (!is_array($row)) {
        return null;
    }
    return array_merge(documents_agreement_defaults(), $row);
}

function documents_save_agreement(array $agreement): array
{
    $id = safe_filename((string) ($agreement['id'] ?? ''));
    if ($id === '') {
        return ['ok' => false, 'error' => 'Missing agreement ID'];
    }
    return json_save(documents_agreements_dir() . '/' . $id . '.json', $agreement);
}

function documents_get_agreement_templates(): array
{
    documents_ensure_structure();
    $rows = json_load(documents_agreement_templates_path(), documents_agreement_template_defaults());
    if (!is_array($rows) || $rows === []) {
        $rows = documents_agreement_template_defaults();
    }
    return $rows;
}

function documents_save_agreement_templates(array $templates): array
{
    return json_save(documents_agreement_templates_path(), $templates);
}

function documents_company_vendor_name(array $company): string
{
    $brand = safe_text($company['brand_name'] ?? '');
    if ($brand !== '') {
        return $brand;
    }
    return safe_text($company['company_name'] ?? '');
}

function documents_company_vendor_address(array $company): string
{
    $parts = [
        safe_text($company['address_line'] ?? ''),
        safe_text($company['city'] ?? ''),
        safe_text($company['district'] ?? ''),
        safe_text($company['state'] ?? ''),
        safe_text($company['pin'] ?? ''),
    ];
    $parts = array_values(array_filter($parts, static fn(string $v): bool => $v !== ''));
    return implode(', ', $parts);
}

function documents_format_money_indian($value): string
{
    return number_format((float) $value, 2, '.', '');
}

function documents_build_agreement_placeholders(array $agreement, array $company): array
{
    $override = is_array($agreement['overrides']['fields_override'] ?? null) ? $agreement['overrides']['fields_override'] : [];
    $partySnapshot = is_array($agreement['party_snapshot'] ?? null) ? $agreement['party_snapshot'] : [];

    $executionDate = safe_text((string) ($override['execution_date'] ?? '')) ?: safe_text((string) ($agreement['execution_date'] ?? ''));
    $capacity = safe_text((string) ($override['system_capacity_kwp'] ?? '')) ?: safe_text((string) ($agreement['system_capacity_kwp'] ?? ($partySnapshot['system_capacity_kwp'] ?? '')));
    $totalCost = safe_text((string) ($override['total_cost'] ?? '')) ?: safe_text((string) ($agreement['total_cost'] ?? ($partySnapshot['total_cost'] ?? '')));
    $accountNo = safe_text((string) ($override['consumer_account_no'] ?? '')) ?: safe_text((string) ($agreement['consumer_account_no'] ?? ($partySnapshot['consumer_account_no'] ?? '')));
    $consumerAddress = safe_text((string) ($override['consumer_address'] ?? '')) ?: safe_text((string) ($agreement['consumer_address'] ?? ($partySnapshot['consumer_address'] ?? '')));
    $siteAddress = safe_text((string) ($override['site_address'] ?? '')) ?: safe_text((string) ($agreement['site_address'] ?? ($partySnapshot['site_address'] ?? '')));

    $locationParts = array_filter([
        safe_text((string) ($agreement['district'] ?? ($partySnapshot['district'] ?? ''))),
        safe_text((string) ($agreement['city'] ?? ($partySnapshot['city'] ?? ''))),
        safe_text((string) ($agreement['state'] ?? ($partySnapshot['state'] ?? ''))),
    ], static fn(string $v): bool => $v !== '');

    return [
        '{{execution_date}}' => $executionDate,
        '{{system_capacity_kwp}}' => $capacity,
        '{{consumer_name}}' => safe_text((string) ($agreement['customer_name'] ?? ($partySnapshot['customer_name'] ?? ''))),
        '{{consumer_account_no}}' => $accountNo,
        '{{consumer_address}}' => $consumerAddress,
        '{{consumer_site_address}}' => $siteAddress,
        '{{consumer_location}}' => implode(', ', array_values($locationParts)),
        '{{vendor_name}}' => documents_company_vendor_name($company),
        '{{vendor_address}}' => documents_company_vendor_address($company),
        '{{total_cost}}' => $totalCost,
    ];
}

function documents_render_agreement_body_html(array $agreement, array $company): string
{
    $templates = documents_get_agreement_templates();
    $templateId = safe_text((string) ($agreement['template_id'] ?? 'default_pm_surya_ghar_agreement'));
    $template = $templates[$templateId] ?? $templates['default_pm_surya_ghar_agreement'] ?? null;
    if (!is_array($template)) {
        $fallback = documents_agreement_template_defaults();
        $template = $fallback['default_pm_surya_ghar_agreement'];
    }

    $htmlOverride = safe_text((string) ($agreement['overrides']['html_override'] ?? ''));
    $html = $htmlOverride !== '' ? $htmlOverride : (string) ($template['html_template'] ?? '');
    return strtr($html, documents_build_agreement_placeholders($agreement, $company));
}
