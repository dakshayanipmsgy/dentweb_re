<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/customer_admin.php';
require_once __DIR__ . '/../../includes/leads.php';

function documents_base_dir(): string
{
    return dirname(__DIR__, 2) . '/data/documents';
}

function documents_inventory_dir(): string
{
    return dirname(__DIR__, 2) . '/data/inventory';
}

function documents_inventory_components_path(): string
{
    return documents_inventory_dir() . '/components.json';
}

function documents_inventory_kits_path(): string
{
    return documents_inventory_dir() . '/kits.json';
}

function documents_inventory_tax_profiles_path(): string
{
    return documents_inventory_dir() . '/tax_profiles.json';
}

function documents_inventory_component_variants_path(): string
{
    return documents_inventory_dir() . '/component_variants.json';
}

function documents_inventory_stock_path(): string
{
    return documents_inventory_dir() . '/stock.json';
}

function documents_inventory_transactions_path(): string
{
    return documents_inventory_dir() . '/transactions.json';
}

function documents_packing_lists_path(): string
{
    return documents_base_dir() . '/packing_lists.json';
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

function documents_sales_documents_dir(): string
{
    return documents_base_dir() . '/documents';
}

function documents_sales_agreements_store_path(): string
{
    return documents_sales_documents_dir() . '/agreements/agreements.json';
}

function documents_sales_receipts_store_path(): string
{
    return documents_sales_documents_dir() . '/receipts/receipts.json';
}

function documents_sales_delivery_challans_store_path(): string
{
    return documents_sales_documents_dir() . '/delivery_challans/delivery_challans.json';
}

function documents_sales_proforma_store_path(): string
{
    return documents_sales_documents_dir() . '/proforma_invoices/pi.json';
}

function documents_sales_invoice_store_path(): string
{
    return documents_sales_documents_dir() . '/invoices/invoices.json';
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

function documents_public_watermarks_dir(): string
{
    return dirname(__DIR__, 2) . '/images/documents/watermarks';
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
    documents_ensure_dir(documents_sales_documents_dir() . '/agreements');
    documents_ensure_dir(documents_sales_documents_dir() . '/receipts');
    documents_ensure_dir(documents_sales_documents_dir() . '/delivery_challans');
    documents_ensure_dir(documents_sales_documents_dir() . '/proforma_invoices');
    documents_ensure_dir(documents_sales_documents_dir() . '/invoices');
    documents_ensure_dir(documents_public_branding_dir());
    documents_ensure_dir(documents_public_backgrounds_dir());
    documents_ensure_dir(documents_public_watermarks_dir());
    documents_ensure_dir(documents_public_diagrams_dir());
    documents_ensure_dir(documents_public_uploads_dir());
    documents_ensure_dir(documents_inventory_dir());

    $companyPath = documents_company_profile_path();
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

    $quoteDefaultsPath = documents_quote_defaults_path();
    if (!is_file($quoteDefaultsPath)) {
        json_save($quoteDefaultsPath, documents_quote_defaults_settings());
    }

    foreach ([
        documents_sales_agreements_store_path(),
        documents_sales_receipts_store_path(),
        documents_sales_delivery_challans_store_path(),
        documents_sales_proforma_store_path(),
        documents_sales_invoice_store_path(),
        documents_inventory_components_path(),
        documents_inventory_kits_path(),
        documents_inventory_tax_profiles_path(),
        documents_inventory_component_variants_path(),
        documents_inventory_transactions_path(),
        documents_packing_lists_path(),
    ] as $storePath) {
        if (!is_file($storePath)) {
            json_save($storePath, []);
        }
    }

    if (!is_file(documents_inventory_stock_path())) {
        json_save(documents_inventory_stock_path(), ['stock_by_component_id' => []]);
    }
}

function documents_quote_defaults_path(): string
{
    return documents_settings_dir() . '/quote_defaults.json';
}

function documents_company_profile_path(): string
{
    return documents_settings_dir() . '/company_profile.json';
}

function documents_quote_defaults_settings(): array
{
    return [
        'global' => [
            'branding' => [
                'primary_color' => '#0f766e',
                'secondary_color' => '#22c55e',
                'accent_color' => '#f59e0b',
                'header_bg' => '#0f766e',
                'header_text' => '#ecfeff',
                'footer_bg' => '#0f172a',
                'footer_text' => '#e2e8f0',
                'chip_bg' => '#ccfbf1',
                'chip_text' => '#134e4a',
                'header_gradient_text' => '#ecfeff',
                'footer_gradient_text' => '#e2e8f0',
                'logo_path' => '',
                'tagline' => '',
                'contact_line' => '',
                'watermark' => [
                    'enabled' => true,
                    'image_path' => '',
                    'opacity' => 0.08,
                ],
            ],
            'typography' => [
                'base_font_px' => 14,
                'heading_scale' => 1.0,
                'density' => 'comfortable',
                'h1_px' => 24,
                'h2_px' => 18,
                'h3_px' => 16,
                'line_height' => 1.6,
            ],
            'ui_tokens' => [
                'colors' => [
                    'primary' => '#0ea5e9',
                    'accent' => '#22c55e',
                    'text' => '#0f172a',
                    'muted_text' => '#475569',
                    'page_bg' => '#f8fafc',
                    'card_bg' => '#ffffff',
                    'border' => '#e2e8f0',
                ],
                'gradients' => [
                    'header' => ['enabled' => true, 'a' => '#0ea5e9', 'b' => '#22c55e', 'direction' => 'to right'],
                    'footer' => ['enabled' => true, 'a' => '#0ea5e9', 'b' => '#22c55e', 'direction' => 'to right'],
                ],
                'header_footer' => [
                    'header_text_color' => '#ffffff',
                    'footer_text_color' => '#ffffff',
                ],
                'shadow' => 'soft',
                'typography' => [
                    'base_px' => 14,
                    'h1_px' => 24,
                    'h2_px' => 18,
                    'h3_px' => 16,
                    'line_height' => 1.6,
                ],
                'border_radius' => 12,
            ],
            'energy_defaults' => [
                'annual_generation_per_kw' => 1450,
                'emission_factor_kg_per_kwh' => 0.82,
                'tree_absorption_kg_per_tree_per_year' => 20,
            ],
            'quotation_ui' => [
                'show_decimals' => false,
                'qr_target' => 'quotation',
                'footer_disclaimer' => 'Values are indicative and subject to site conditions, DISCOM approvals, and policy updates.',
                'why_dakshayani_points' => [
                    'Local Jharkhand EPC team',
                    'DISCOM process and net-metering experience',
                    'In-house design, installation, and commissioning',
                    'Strong post-installation service support',
                ],
            ],
        ],
        'defaults' => [
            'hsn_solar' => '8541',
            'quotation_tax_profile_id' => '',
            'cover_note_template' => '<p>Namaste! Thank you for considering Dakshayani Enterprises for your rooftop solar journey.</p><p>As a Jharkhand-based EPC partner, we handle complete design, installation, and support with local execution accountability.</p><p>Our team will also guide you through DISCOM approvals, net-metering paperwork, and PM Surya Ghar process steps so your transition stays smooth and transparent.</p>',
        ],
        'segments' => [
            'RES' => [
                'unit_rate_rs_per_kwh' => 8,
                'subsidy' => ['enabled' => true, 'cap_2kw' => 60000, 'cap_3kw_plus' => 78000],
                'annual_generation_per_kw' => 1450,
                'loan_defaults' => [
                    'enabled' => true,
                    'tenure_years' => 10,
                    'slabs' => [
                        ['max_loan' => 200000, 'margin_pct' => 10, 'interest_pct' => 6.0],
                        ['min_loan' => 200001, 'max_loan' => 600000, 'margin_pct' => 20, 'interest_pct' => 8.15],
                    ],
                ],
                'loan_bestcase' => [
                    'max_loan_rs' => 200000,
                    'interest_pct' => 6.0,
                    'tenure_years' => 10,
                    'min_margin_pct' => 10,
                ],
                'loan_info' => [
                    'slab2_interest_pct' => 8.15,
                    'slab2_min_margin_pct' => 20,
                    'slab2_range' => '₹2L–₹6L',
                ],
            ],
            'COM' => ['unit_rate_rs_per_kwh' => 10, 'subsidy' => ['enabled' => false], 'loan_defaults' => ['enabled' => true, 'tenure_years' => 7]],
            'IND' => ['unit_rate_rs_per_kwh' => 11, 'subsidy' => ['enabled' => false], 'loan_defaults' => ['enabled' => true, 'tenure_years' => 7]],
            'INST' => ['unit_rate_rs_per_kwh' => 9, 'subsidy' => ['enabled' => false,], 'loan_defaults' => ['enabled' => true, 'tenure_years' => 7]],
        ],
    ];
}

function documents_get_quote_defaults_settings(): array
{
    $path = documents_quote_defaults_path();
    $stored = json_load($path, []);
    return array_replace_recursive(documents_quote_defaults_settings(), is_array($stored) ? $stored : []);
}

function load_quote_defaults(): array
{
    return documents_get_quote_defaults_settings();
}

function save_quote_defaults(array $settings): array
{
    $merged = array_replace_recursive(documents_quote_defaults_settings(), $settings);
    return json_save(documents_quote_defaults_path(), $merged);
}

function load_company_profile(): array
{
    $profile = json_load(documents_company_profile_path(), []);
    $merged = array_merge(documents_company_profile_defaults(), is_array($profile) ? $profile : []);
    $merged['logo_path'] = documents_normalize_public_asset_path($merged['logo_path'] ?? '');
    return $merged;
}

function save_company_profile(array $profile): array
{
    $merged = array_merge(documents_company_profile_defaults(), $profile);
    $merged['updated_at'] = date('c');
    return json_save(documents_company_profile_path(), $merged);
}

function documents_normalize_public_asset_path($rawPath): string
{
    $path = trim((string) $rawPath);
    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $isWindowsPath = strlen($path) > 2 && ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/');
    $isFilesystemPath = str_starts_with($path, '/') || $isWindowsPath;
    if ($isFilesystemPath && !str_starts_with($path, '/images/') && !str_starts_with($path, '/uploads/')) {
        return '';
    }

    if (str_starts_with($path, '/')) {
        return $path;
    }

    if (strpos($path, 'images/') === 0 || strpos($path, 'uploads/') === 0) {
        return '/' . ltrim($path, '/');
    }

    return '/images/documents/branding/' . ltrim($path, '/');
}

function documents_get_company_profile_for_quotes(): array
{
    $profile = array_merge(
        documents_company_profile_defaults(),
        load_company_profile()
    );

    $brand = json_load(dirname(__DIR__, 2) . '/data/marketing/brand_profile.json', []);
    if (is_array($brand)) {
        $profile['brand_name'] = (string) ($profile['brand_name'] ?: ($brand['firm_name'] ?? ''));
        $profile['company_name'] = (string) ($profile['company_name'] ?: ($brand['firm_name'] ?? ''));
        $profile['phone_primary'] = (string) ($profile['phone_primary'] ?: ($brand['primary_contact_number'] ?? ''));
        $profile['phone_secondary'] = (string) ($profile['phone_secondary'] ?: ($brand['whatsapp_number'] ?? ''));
        $profile['email_primary'] = (string) ($profile['email_primary'] ?: ($brand['email'] ?? ''));
        $profile['website'] = (string) ($profile['website'] ?: ($brand['website_url'] ?? ''));
        $profile['address_line'] = (string) ($profile['address_line'] ?: ($brand['physical_address'] ?? ''));
    }

    $profile['logo_path'] = documents_normalize_public_asset_path($profile['logo_path'] ?? '');

    return $profile;
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
        'whatsapp_number' => '',
        'email_primary' => '',
        'email_secondary' => '',
        'website' => '',
        'gstin' => '',
        'udyam' => '',
        'pan' => '',
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
        'archived_flag' => false,
        'archived_at' => '',
        'archived_by' => ['type' => '', 'id' => '', 'name' => ''],
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
        'accepted_at' => '',
        'accepted_by' => ['type' => '', 'id' => '', 'name' => ''],
        'workflow' => [
            'agreement_id' => '',
            'proforma_invoice_id' => '',
            'invoice_id' => '',
            'receipt_ids' => [],
            'delivery_challan_ids' => [],
            'packing_list_id' => '',
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
        'tax_profile_id' => '',
        'gst_mode_snapshot' => 'single',
        'gst_slabs_snapshot' => [],
        'tax_breakdown' => [
            'basic_total' => 0,
            'gst_total' => 0,
            'gross_incl_gst' => 0,
            'slabs' => [],
        ],
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
            'final_price_incl_gst' => 0,
            'transportation_rs' => 0,
            'gross_payable' => 0,
            'subsidy_expected_rs' => 0,
            'net_after_subsidy' => 0,
        ],
        'cover_note_text' => '',
        'special_requests_text' => '',
        'special_requests_inclusive' => '',
        'items' => [],
        'quote_items' => [],
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
        'finance_inputs' => [
            'monthly_bill_rs' => '',
            'unit_rate_rs_per_kwh' => '',
            'annual_generation_per_kw' => '',
            'funding_mode_show_both' => true,
            'customer_plans_bank_loan' => false,
            'loan' => [
                'enabled' => true,
                'interest_pct' => '',
                'tenure_years' => '',
                'margin_pct' => '',
                'loan_amount' => '',
            ],
            'subsidy_expected_rs' => '',
            'transportation_rs' => '',
            'notes_for_customer' => '',
        ],
        'style_overrides' => [
            'typography' => ['base_font_px' => '', 'heading_scale' => '', 'density' => ''],
            'watermark' => ['enabled' => '', 'image_path' => '', 'opacity' => ''],
        ],
        'share' => [
            'public_enabled' => false,
            'public_token' => '',
            'public_created_at' => '',
        ],
        'rendering' => [
            'background_image' => '',
            'background_opacity' => 1.0,
        ],
        'pdf_path' => '',
        'pdf_generated_at' => '',
        'quote_series_id' => '',
        'version_no' => 1,
        'is_current_version' => true,
        'revised_from_quote_id' => null,
        'revision_reason' => null,
        'revision_child_ids' => [],
        'locked_flag' => false,
        'locked_at' => null,
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
        'archived_flag' => false,
        'archived_at' => '',
        'archived_by' => ['type' => '', 'id' => '', 'name' => ''],
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
        'packing_list_id' => '',
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

function documents_is_archived(array $record): bool
{
    if (!empty($record['archived_flag']) || !empty($record['is_archived'])) {
        return true;
    }
    return strtolower(trim((string) ($record['status'] ?? ''))) === 'archived';
}

function documents_set_archived(array $record, array $byUser = []): array
{
    $record['archived_flag'] = true;
    $record['archived_at'] = date('c');
    $record['archived_by'] = [
        'type' => (string) ($byUser['type'] ?? 'admin'),
        'id' => (string) ($byUser['id'] ?? ''),
        'name' => (string) ($byUser['name'] ?? 'Admin'),
    ];
    $record['status'] = 'archived';
    return $record;
}

function documents_set_unarchived(array $record): array
{
    $record['archived_flag'] = false;
    $record['archived_at'] = '';
    $record['archived_by'] = ['type' => '', 'id' => '', 'name' => ''];
    if (strtolower(trim((string) ($record['status'] ?? ''))) === 'archived') {
        $record['status'] = 'active';
    }
    return $record;
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
        'component_id' => '',
        'dispatch_qty' => 0,
        'dispatch_ft' => 0,
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
        $row['component_id'] = safe_text((string) ($row['component_id'] ?? ''));
        $row['dispatch_qty'] = max(0, (float) ($row['dispatch_qty'] ?? 0));
        $row['dispatch_ft'] = max(0, (float) ($row['dispatch_ft'] ?? 0));
        if ($row['name'] === '' && $row['description'] === '' && $row['qty'] <= 0 && $row['dispatch_qty'] <= 0 && $row['dispatch_ft'] <= 0) {
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


function documents_quote_item_defaults(): array
{
    return [
        'name' => '',
        'description' => '',
        'hsn' => '8541',
        'qty' => 1,
        'unit' => 'set',
        'gst_slab' => '5',
        'basic_amount' => 0,
    ];
}

function documents_normalize_quote_items(array $items, string $systemType = 'Ongrid', float $capacityKwp = 0.0, string $defaultHsn = '8541'): array
{
    $rows = [];
    foreach ($items as $row) {
        if (!is_array($row)) { continue; }
        $item = array_merge(documents_quote_item_defaults(), $row);
        $item['name'] = safe_text((string) ($item['name'] ?? ''));
        $item['description'] = safe_text((string) ($item['description'] ?? ''));
        $item['hsn'] = safe_text((string) ($item['hsn'] ?? '')) ?: $defaultHsn;
        $item['qty'] = (float) ($item['qty'] ?? 0);
        $item['unit'] = safe_text((string) ($item['unit'] ?? ''));
        $slab = strtoupper(safe_text((string) ($item['gst_slab'] ?? '5')));
        $item['gst_slab'] = in_array($slab, ['5', '18', 'NA'], true) ? $slab : '5';
        $item['basic_amount'] = round((float) ($item['basic_amount'] ?? 0), 2);
        if ($item['name'] === '' && $item['basic_amount'] <= 0) { continue; }
        $rows[] = $item;
    }

    if ($rows === []) {
        $label = strtolower($systemType) === 'hybrid' ? 'Hybrid Solar System' : 'On-grid Solar System';
        $rows[] = [
            'name' => $label,
            'description' => 'System package',
            'hsn' => $defaultHsn,
            'qty' => $capacityKwp > 0 ? $capacityKwp : 1,
            'unit' => $capacityKwp > 0 ? 'kWp' : 'set',
            'gst_slab' => '5',
            'basic_amount' => 0,
        ];
    }

    return $rows;
}

function documents_calc_pricing_from_items(array $items, string $pricingMode, string $taxType, float $transportationRs = 0.0, float $subsidyExpectedRs = 0.0, ?float $systemTotalInclGstRs = null): array
{
    $pricingMode = in_array($pricingMode, ['solar_split_70_30', 'flat_5'], true) ? $pricingMode : 'solar_split_70_30';
    $taxType = $taxType === 'IGST' ? 'IGST' : 'CGST_SGST';
    $finalPrice = max(0, (float) ($systemTotalInclGstRs ?? 0));
    if ($finalPrice <= 0) {
        $baseTotal = 0.0;
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $baseTotal += max(0, (float) ($item['basic_amount'] ?? 0));
        }
        if ($pricingMode === 'flat_5') {
            $bucket5Basic = $baseTotal;
            $bucket18Basic = 0.0;
            $bucket5Gst = $bucket5Basic * 0.05;
            $bucket18Gst = 0.0;
            $finalPrice = $baseTotal + $bucket5Gst;
        } else {
            $bucket5Basic = $baseTotal * 0.70;
            $bucket18Basic = $baseTotal * 0.30;
            $bucket5Gst = $bucket5Basic * 0.05;
            $bucket18Gst = $bucket18Basic * 0.18;
            $finalPrice = $baseTotal + $bucket5Gst + $bucket18Gst;
        }
    } elseif ($pricingMode === 'flat_5') {
        $baseTotal = $finalPrice / 1.05;
        $bucket5Basic = $baseTotal;
        $bucket18Basic = 0.0;
        $bucket5Gst = $bucket5Basic * 0.05;
        $bucket18Gst = 0.0;
    } else {
        $baseTotal = $finalPrice / 1.089;
        $bucket5Basic = $baseTotal * 0.70;
        $bucket18Basic = $baseTotal * 0.30;
        $bucket5Gst = $bucket5Basic * 0.05;
        $bucket18Gst = $bucket18Basic * 0.18;
    }
    $transportationRs = max(0, $transportationRs);
    $grossPayable = $finalPrice + $transportationRs;
    $subsidyExpectedRs = max(0, $subsidyExpectedRs);

    $calc = [
        'basic_total' => round($baseTotal, 2),
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
        'grand_total' => round($finalPrice, 2),
        'final_price_incl_gst' => round($finalPrice, 2),
        'transportation_rs' => round($transportationRs, 2),
        'gross_payable' => round($grossPayable, 2),
        'subsidy_expected_rs' => round($subsidyExpectedRs, 2),
        'net_after_subsidy' => round($grossPayable - $subsidyExpectedRs, 2),
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

function documents_resolve_tax_profile_for_quote(array $quote, array $settings = []): array
{
    $selectedId = safe_text((string) ($quote['tax_profile_id'] ?? ''));
    if ($selectedId !== '') {
        $selected = documents_inventory_get_tax_profile($selectedId);
        if (is_array($selected)) {
            return $selected;
        }
    }

    $defaultId = safe_text((string) ($settings['defaults']['quotation_tax_profile_id'] ?? ''));
    if ($defaultId !== '') {
        $selected = documents_inventory_get_tax_profile($defaultId);
        if (is_array($selected)) {
            return $selected;
        }
    }

    $mode = safe_text((string) ($quote['pricing_mode'] ?? 'solar_split_70_30'));
    return $mode === 'flat_5' ? documents_flat5_tax_profile() : documents_default_tax_profile();
}

function documents_calc_quote_pricing_with_tax_profile(array $quote, float $transportationRs = 0.0, float $subsidyExpectedRs = 0.0, ?float $systemTotalInclGstRs = null, array $settings = []): array
{
    $taxType = ((string) ($quote['tax_type'] ?? 'CGST_SGST')) === 'IGST' ? 'IGST' : 'CGST_SGST';
    $gross = max(0, (float) ($systemTotalInclGstRs ?? ($quote['input_total_gst_inclusive'] ?? 0)));
    $profile = documents_resolve_tax_profile_for_quote($quote, $settings);
    $breakdown = documents_calc_tax_breakdown_from_gross($gross, $profile);

    $bucket5Basic = 0.0;
    $bucket18Basic = 0.0;
    $bucket5Gst = 0.0;
    $bucket18Gst = 0.0;
    $gstByRate = [];
    foreach ((array) ($breakdown['slabs'] ?? []) as $slab) {
        if (!is_array($slab)) {
            continue;
        }
        $rateKey = number_format((float) ($slab['rate_pct'] ?? 0), 2, '.', '');
        if (!isset($gstByRate[$rateKey])) {
            $gstByRate[$rateKey] = ['rate_pct' => (float) $rateKey, 'base_total' => 0.0, 'gst_total' => 0.0];
        }
        $gstByRate[$rateKey]['base_total'] += (float) ($slab['base_amount'] ?? 0);
        $gstByRate[$rateKey]['gst_total'] += (float) ($slab['gst_amount'] ?? 0);
    }
    foreach ($gstByRate as &$bucket) {
        $bucket['base_total'] = documents_money_round((float) $bucket['base_total']);
        $bucket['gst_total'] = documents_money_round((float) $bucket['gst_total']);
    }
    unset($bucket);

    $bucket5Basic = (float) ($gstByRate['5.00']['base_total'] ?? 0);
    $bucket18Basic = (float) ($gstByRate['18.00']['base_total'] ?? 0);
    $bucket5Gst = (float) ($gstByRate['5.00']['gst_total'] ?? 0);
    $bucket18Gst = (float) ($gstByRate['18.00']['gst_total'] ?? 0);

    $transportationRs = max(0, $transportationRs);
    $grossPayable = $gross + $transportationRs;
    $subsidyExpectedRs = max(0, $subsidyExpectedRs);
    $calc = [
        'basic_total' => documents_money_round((float) ($breakdown['basic_total'] ?? 0)),
        'bucket_5_basic' => documents_money_round($bucket5Basic),
        'bucket_5_gst' => documents_money_round($bucket5Gst),
        'bucket_18_basic' => documents_money_round($bucket18Basic),
        'bucket_18_gst' => documents_money_round($bucket18Gst),
        'tax_breakdown' => [
            'profile_id' => (string) ($profile['id'] ?? ''),
            'profile_name' => (string) ($profile['name'] ?? ''),
            'mode' => (string) ($profile['mode'] ?? 'single'),
            'slabs' => array_values((array) ($breakdown['slabs'] ?? [])),
            'basic_total' => documents_money_round((float) ($breakdown['basic_total'] ?? 0)),
            'gst_total' => documents_money_round((float) ($breakdown['gst_total'] ?? 0)),
            'gross_incl_gst' => documents_money_round((float) ($breakdown['gross_incl_gst'] ?? 0)),
            'gst_by_rate' => array_values($gstByRate),
        ],
        'gst_split' => [
            'cgst_5' => 0.0,
            'sgst_5' => 0.0,
            'cgst_18' => 0.0,
            'sgst_18' => 0.0,
            'igst_5' => 0.0,
            'igst_18' => 0.0,
        ],
        'grand_total' => documents_money_round($gross),
        'final_price_incl_gst' => documents_money_round($gross),
        'transportation_rs' => documents_money_round($transportationRs),
        'gross_payable' => documents_money_round($grossPayable),
        'subsidy_expected_rs' => documents_money_round($subsidyExpectedRs),
        'net_after_subsidy' => documents_money_round($grossPayable - $subsidyExpectedRs),
    ];

    if ($taxType === 'IGST') {
        $calc['gst_split']['igst_5'] = documents_money_round($bucket5Gst);
        $calc['gst_split']['igst_18'] = documents_money_round($bucket18Gst);
    } else {
        $calc['gst_split']['cgst_5'] = documents_money_round($bucket5Gst / 2);
        $calc['gst_split']['sgst_5'] = documents_money_round($bucket5Gst / 2);
        $calc['gst_split']['cgst_18'] = documents_money_round($bucket18Gst / 2);
        $calc['gst_split']['sgst_18'] = documents_money_round($bucket18Gst / 2);
    }

    return $calc;
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


function documents_get_quote_prefill_from_lead(string $leadId): array
{
    $leadId = trim($leadId);
    if ($leadId === '') {
        return ['ok' => false, 'error' => 'Missing lead reference.', 'lead' => null, 'prefill' => []];
    }

    $lead = find_lead_by_id($leadId);
    if ($lead === null) {
        return ['ok' => false, 'error' => 'Lead not found for prefill.', 'lead' => null, 'prefill' => []];
    }

    if (!empty($lead['archived_flag'])) {
        return ['ok' => false, 'error' => 'Archived lead cannot be used for new quotation.', 'lead' => $lead, 'prefill' => []];
    }

    $name = trim((string) ($lead['name'] ?? ''));
    $mobile = normalize_customer_mobile((string) ($lead['mobile'] ?? ''));
    if ($mobile === '') {
        $mobile = normalize_customer_mobile((string) ($lead['alt_mobile'] ?? ''));
    }
    if ($name === '' || $mobile === '') {
        return ['ok' => false, 'error' => 'Lead is missing required name/mobile fields.', 'lead' => $lead, 'prefill' => []];
    }

    $location = is_array($lead['location'] ?? null) ? $lead['location'] : [];
    $leadCity = trim((string) ($lead['city'] ?? ''));
    if ($leadCity === '') {
        $leadCity = trim((string) ($lead['lead_city'] ?? ''));
    }
    if ($leadCity === '') {
        $leadCity = trim((string) ($location['city'] ?? ''));
    }

    $prefill = [
        'customer_name' => $name,
        'customer_mobile' => $mobile,
        'city' => $leadCity,
        'district' => trim((string) ($lead['district'] ?? ($location['district'] ?? ''))),
        'state' => trim((string) ($lead['state'] ?? ($location['state'] ?? ''))),
        'locality' => trim((string) ($lead['area_or_locality'] ?? ($location['area_or_locality'] ?? ($location['locality'] ?? '')))),
        'notes' => trim((string) ($lead['notes'] ?? '')),
        'source' => [
            'type' => 'lead',
            'lead_id' => (string) ($lead['id'] ?? ''),
            'lead_mobile' => $mobile,
        ],
    ];

    return ['ok' => true, 'error' => '', 'lead' => $lead, 'prefill' => $prefill];
}

function documents_archive_lead_from_quote_source(array $quote): bool
{
    $source = is_array($quote['source'] ?? null) ? $quote['source'] : [];
    if ((string) ($source['type'] ?? '') !== 'lead') {
        return false;
    }

    $leadId = trim((string) ($source['lead_id'] ?? ''));
    if ($leadId === '') {
        return false;
    }

    $lead = find_lead_by_id($leadId);
    if ($lead === null) {
        return false;
    }

    $result = update_lead($leadId, [
        'archived_flag' => true,
        'archived_at' => date('Y-m-d H:i:s'),
        'converted_flag' => 'Yes',
        'converted_date' => date('Y-m-d'),
        'status' => 'Converted',
    ]);

    return $result !== null;
}

/**
 * @return array{quote: array<string, mixed>, customer_upserted: bool, lead_archived: bool}
 */
function documents_sync_after_quote_accepted(array $quote): array
{
    $quote = documents_quote_prepare($quote);
    $quote['locked_flag'] = true;
    $quote['locked_at'] = date('c');
    $quote['is_current_version'] = true;

    $customerResult = documents_upsert_customer_from_quote($quote);
    if (($customerResult['ok'] ?? false) && is_array($customerResult['customer'] ?? null)) {
        $quote['links'] = array_merge(['customer_mobile' => '', 'agreement_id' => '', 'proforma_id' => '', 'invoice_id' => ''], is_array($quote['links'] ?? null) ? $quote['links'] : []);
        $quote['links']['customer_mobile'] = normalize_customer_mobile((string) ($quote['customer_mobile'] ?? ''));
    }

    $packingListCreated = false;
    $packingWarning = '';
    $existingPackingId = safe_text((string) ($quote['workflow']['packing_list_id'] ?? ''));
    if ($existingPackingId !== '') {
        $existing = documents_get_packing_list_for_quote((string) ($quote['id'] ?? ''), false);
        if ($existing !== null) {
            $packingListCreated = true;
        }
    }

    if (!$packingListCreated) {
        $existing = documents_get_packing_list_for_quote((string) ($quote['id'] ?? ''), false);
        if ($existing !== null) {
            $quote['workflow']['packing_list_id'] = (string) ($existing['id'] ?? '');
            $packingListCreated = true;
        } else {
            $packResult = documents_create_packing_list_from_quote($quote);
            if (($packResult['ok'] ?? false) && is_array($packResult['packing_list'] ?? null)) {
                $quote['workflow']['packing_list_id'] = (string) (($packResult['packing_list']['id'] ?? ''));
                $packingListCreated = true;
            } else {
                $packingWarning = (string) ($packResult['error'] ?? 'No structured items selected');
            }
        }
    }

    $leadArchived = documents_archive_lead_from_quote_source($quote);

    return [
        'quote' => $quote,
        'customer_upserted' => (bool) ($customerResult['ok'] ?? false),
        'lead_archived' => $leadArchived,
        'packing_list_created' => $packingListCreated,
        'packing_warning' => $packingWarning,
    ];
}


function documents_normalize_quotes_store(): void
{
    documents_ensure_structure();
    $files = glob(documents_quotations_dir() . '/*.json') ?: [];
    foreach ($files as $file) {
        if (!is_string($file) || !is_file($file)) {
            continue;
        }
        $row = json_load($file, []);
        if (!is_array($row)) {
            continue;
        }
        $prepared = documents_quote_prepare($row);
        $defaultHsn = safe_text((string) (documents_get_quote_defaults_settings()['defaults']['hsn_solar'] ?? '8541')) ?: '8541';
        $prepared['items'] = documents_normalize_quote_items(is_array($prepared['items'] ?? null) ? $prepared['items'] : [], (string) ($prepared['system_type'] ?? 'Ongrid'), (float) ($prepared['capacity_kwp'] ?? 0), $defaultHsn);
        json_save($file, $prepared);
    }
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
        $quote = documents_quote_prepare(is_array($row) ? $row : []);
        $defaultHsn = safe_text((string) (documents_get_quote_defaults_settings()['defaults']['hsn_solar'] ?? '8541')) ?: '8541';
        $quote['items'] = documents_normalize_quote_items(is_array($quote['items'] ?? null) ? $quote['items'] : [], (string) ($quote['system_type'] ?? 'Ongrid'), (float) ($quote['capacity_kwp'] ?? 0), $defaultHsn);
        $quotes[] = $quote;
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
    $quote = documents_quote_prepare($row);
    $defaultHsn = safe_text((string) (documents_get_quote_defaults_settings()['defaults']['hsn_solar'] ?? '8541')) ?: '8541';
    $quote['items'] = documents_normalize_quote_items(is_array($quote['items'] ?? null) ? $quote['items'] : [], (string) ($quote['system_type'] ?? 'Ongrid'), (float) ($quote['capacity_kwp'] ?? 0), $defaultHsn);
    return $quote;
}

function documents_save_quote(array $quote): array
{
    $id = safe_filename((string) ($quote['id'] ?? ''));
    if ($id === '') {
        return ['ok' => false, 'error' => 'Missing quote ID'];
    }
    $path = documents_quotations_dir() . '/' . $id . '.json';
    $defaultHsn = safe_text((string) (documents_get_quote_defaults_settings()['defaults']['hsn_solar'] ?? '8541')) ?: '8541';
    $quote = documents_quote_prepare($quote);
    $quote['items'] = documents_normalize_quote_items(is_array($quote['items'] ?? null) ? $quote['items'] : [], (string) ($quote['system_type'] ?? 'Ongrid'), (float) ($quote['capacity_kwp'] ?? 0), $defaultHsn);
    if (safe_text((string) ($quote['special_requests_text'] ?? '')) === '' && safe_text((string) ($quote['special_requests_inclusive'] ?? '')) !== '') {
        $quote['special_requests_text'] = (string) $quote['special_requests_inclusive'];
    }
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

function documents_quote_can_edit(array $quote, string $viewerType, string $viewerId = ''): bool
{
    if (documents_quote_is_locked($quote) || documents_quote_normalize_status((string) ($quote['status'] ?? 'draft')) !== 'draft') {
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

function documents_quote_normalize_status(string $status): string
{
    $normalized = strtolower(trim($status));
    if ($normalized === 'pending admin approval') {
        return 'pending_admin_approval';
    }
    if (in_array($normalized, ['draft', 'pending_admin_approval', 'approved', 'accepted', 'archived'], true)) {
        return $normalized;
    }
    if ($normalized === 'approved') {
        return 'approved';
    }
    if ($normalized === 'accepted') {
        return 'accepted';
    }
    return 'draft';
}

function documents_quote_workflow_defaults(): array
{
    return [
        'agreement_id' => '',
        'proforma_invoice_id' => '',
        'invoice_id' => '',
        'receipt_ids' => [],
        'delivery_challan_ids' => [],
        'packing_list_id' => '',
    ];
}

function documents_quote_prepare(array $quote): array
{
    $original = $quote;
    $quote = array_merge(documents_quote_defaults(), $quote);
    $quote['status'] = documents_quote_normalize_status((string) ($quote['status'] ?? 'draft'));
    $quote['workflow'] = array_merge(documents_quote_workflow_defaults(), is_array($quote['workflow'] ?? null) ? $quote['workflow'] : []);
    $quote['quote_items'] = documents_normalize_quote_structured_items(is_array($quote['quote_items'] ?? null) ? $quote['quote_items'] : []);
    $quote['tax_profile_id'] = safe_text((string) ($quote['tax_profile_id'] ?? ''));
    $quote['gst_mode_snapshot'] = safe_text((string) ($quote['gst_mode_snapshot'] ?? ''));
    $quote['gst_slabs_snapshot'] = is_array($quote['gst_slabs_snapshot'] ?? null) ? $quote['gst_slabs_snapshot'] : [];
    $quote['tax_breakdown'] = is_array($quote['tax_breakdown'] ?? null) ? $quote['tax_breakdown'] : [];
    if ($quote['tax_breakdown'] === [] && is_array($quote['calc']['tax_breakdown'] ?? null)) {
        $quote['tax_breakdown'] = (array) $quote['calc']['tax_breakdown'];
    }
    $quote['accepted_by'] = array_merge(['type' => '', 'id' => '', 'name' => ''], is_array($quote['accepted_by'] ?? null) ? $quote['accepted_by'] : []);
    $quote['archived_by'] = array_merge(['type' => '', 'id' => '', 'name' => ''], is_array($quote['archived_by'] ?? null) ? $quote['archived_by'] : []);
    if (!array_key_exists('quote_series_id', $original) || safe_text((string) ($quote['quote_series_id'] ?? '')) === '') {
        $quote['quote_series_id'] = (string) ($quote['id'] ?? '');
    }
    if (!array_key_exists('version_no', $original) || (int) ($quote['version_no'] ?? 0) <= 0) {
        $quote['version_no'] = 1;
    } else {
        $quote['version_no'] = (int) $quote['version_no'];
    }
    if (!array_key_exists('is_current_version', $original)) {
        $quote['is_current_version'] = true;
    } else {
        $quote['is_current_version'] = (bool) ($quote['is_current_version'] ?? false);
    }
    $quote['revised_from_quote_id'] = safe_text((string) ($quote['revised_from_quote_id'] ?? '')) ?: null;
    $revisionReason = trim((string) ($quote['revision_reason'] ?? ''));
    $quote['revision_reason'] = $revisionReason === '' ? null : $revisionReason;
    $quote['revision_child_ids'] = array_values(array_filter(array_map(static fn($v): string => safe_text((string) $v), is_array($quote['revision_child_ids'] ?? null) ? $quote['revision_child_ids'] : []), static fn(string $v): bool => $v !== ''));
    $isAccepted = documents_quote_normalize_status((string) ($quote['status'] ?? 'draft')) === 'accepted';
    $quote['locked_flag'] = (bool) ($quote['locked_flag'] ?? false) || $isAccepted;
    if ($quote['locked_flag'] === true) {
        $lockedAt = safe_text((string) ($quote['locked_at'] ?? ''));
        if ($lockedAt === '') {
            $quote['locked_at'] = safe_text((string) ($quote['accepted_at'] ?? '')) ?: date('c');
        }
    } else {
        $quote['locked_at'] = safe_text((string) ($quote['locked_at'] ?? '')) ?: null;
    }
    $quote['archived_flag'] = documents_is_archived($quote);
    return $quote;
}

function documents_quote_is_locked(array $quote): bool
{
    $status = strtolower(trim((string) ($quote['status'] ?? 'draft')));
    return ($quote['locked_flag'] ?? false) === true || $status === 'accepted';
}

function documents_quote_has_workflow_documents(array $quote): bool
{
    $workflow = array_merge(documents_quote_workflow_defaults(), is_array($quote['workflow'] ?? null) ? $quote['workflow'] : []);
    if (safe_text((string) ($workflow['proforma_invoice_id'] ?? '')) !== '') {
        return true;
    }
    if (safe_text((string) ($workflow['invoice_id'] ?? '')) !== '') {
        return true;
    }
    if (is_array($workflow['receipt_ids'] ?? null) && count($workflow['receipt_ids']) > 0) {
        return true;
    }
    if (is_array($workflow['delivery_challan_ids'] ?? null) && count($workflow['delivery_challan_ids']) > 0) {
        return true;
    }
    return false;
}

function documents_archive_quote_sales_documents(array $quote, array $viewer): void
{
    $workflow = array_merge(documents_quote_workflow_defaults(), is_array($quote['workflow'] ?? null) ? $quote['workflow'] : []);
    $archiveDoc = static function (string $type, string $id) use ($viewer): void {
        if ($id === '') {
            return;
        }
        $doc = documents_get_sales_document($type, $id);
        if ($doc === null) {
            return;
        }
        $doc['archived_flag'] = true;
        $doc['archived_at'] = date('c');
        $doc['archived_by'] = [
            'type' => (string) ($viewer['type'] ?? 'admin'),
            'id' => (string) ($viewer['id'] ?? ''),
            'name' => (string) ($viewer['name'] ?? 'Admin'),
        ];
        documents_save_sales_document($type, $doc);
    };

    $archiveDoc('proforma', safe_text((string) ($workflow['proforma_invoice_id'] ?? '')));
    $archiveDoc('invoice', safe_text((string) ($workflow['invoice_id'] ?? '')));
}

function documents_quote_versions(string $seriesId): array
{
    $seriesId = safe_text($seriesId);
    if ($seriesId === '') {
        return [];
    }
    $versions = array_values(array_filter(documents_list_quotes(), static function (array $quote) use ($seriesId): bool {
        return (string) ($quote['quote_series_id'] ?? '') === $seriesId;
    }));
    usort($versions, static fn(array $a, array $b): int => ((int) ($a['version_no'] ?? 1)) <=> ((int) ($b['version_no'] ?? 1)));
    return $versions;
}

function documents_quote_set_current_for_series(array $acceptedQuote): void
{
    $seriesId = safe_text((string) ($acceptedQuote['quote_series_id'] ?? ''));
    $acceptedId = safe_text((string) ($acceptedQuote['id'] ?? ''));
    if ($seriesId === '' || $acceptedId === '') {
        return;
    }
    foreach (documents_quote_versions($seriesId) as $seriesQuote) {
        $seriesQuote['is_current_version'] = ((string) ($seriesQuote['id'] ?? '') === $acceptedId);
        if ((string) ($seriesQuote['id'] ?? '') === $acceptedId) {
            $seriesQuote['status'] = 'accepted';
            $seriesQuote['locked_flag'] = true;
            $seriesQuote['locked_at'] = safe_text((string) ($seriesQuote['locked_at'] ?? '')) ?: date('c');
        }
        $seriesQuote['updated_at'] = date('c');
        documents_save_quote($seriesQuote);
    }
}

function documents_read_sales_store(string $path): array
{
    $rows = json_load($path, []);
    return is_array($rows) ? $rows : [];
}

function documents_write_sales_store(string $path, array $rows): array
{
    return json_save($path, array_values($rows));
}

function documents_generate_simple_document_id(string $prefix): string
{
    return strtoupper($prefix) . '-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function documents_sales_document_defaults(string $type): array
{
    return [
        'id' => '',
        'doc_type' => $type,
        'quotation_id' => '',
        'customer_mobile' => '',
        'customer_name' => '',
        'created_at' => '',
        'updated_at' => '',
        'created_by' => ['type' => '', 'id' => '', 'name' => ''],
        'status' => 'draft',
        'archived_flag' => false,
        'archived_at' => '',
        'archived_by' => ['type' => '', 'id' => '', 'name' => ''],
        'notes' => '',
    ];
}

function documents_list_sales_documents(string $type): array
{
    $pathMap = [
        'agreement' => documents_sales_agreements_store_path(),
        'receipt' => documents_sales_receipts_store_path(),
        'delivery_challan' => documents_sales_delivery_challans_store_path(),
        'proforma' => documents_sales_proforma_store_path(),
        'invoice' => documents_sales_invoice_store_path(),
    ];
    $path = $pathMap[$type] ?? '';
    if ($path === '') {
        return [];
    }
    $rows = documents_read_sales_store($path);
    $rows = array_values(array_filter($rows, static fn($row): bool => is_array($row)));
    usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
    return $rows;
}

function documents_get_sales_document(string $type, string $id): ?array
{
    foreach (documents_list_sales_documents($type) as $row) {
        if ((string) ($row['id'] ?? '') === $id) {
            return $row;
        }
    }
    return null;
}

function documents_save_sales_document(string $type, array $document): array
{
    $pathMap = [
        'agreement' => documents_sales_agreements_store_path(),
        'receipt' => documents_sales_receipts_store_path(),
        'delivery_challan' => documents_sales_delivery_challans_store_path(),
        'proforma' => documents_sales_proforma_store_path(),
        'invoice' => documents_sales_invoice_store_path(),
    ];
    $path = $pathMap[$type] ?? '';
    if ($path === '') {
        return ['ok' => false, 'error' => 'Invalid document type'];
    }

    $rows = documents_read_sales_store($path);
    $base = documents_sales_document_defaults($type);
    $document = array_merge($base, $document);
    $document['updated_at'] = date('c');

    $found = false;
    foreach ($rows as $index => $row) {
        if (is_array($row) && (string) ($row['id'] ?? '') === (string) ($document['id'] ?? '')) {
            $rows[$index] = array_merge($base, $row, $document);
            $found = true;
            break;
        }
    }
    if (!$found) {
        if ((string) ($document['id'] ?? '') === '') {
            return ['ok' => false, 'error' => 'Missing document ID'];
        }
        if ((string) ($document['created_at'] ?? '') === '') {
            $document['created_at'] = date('c');
        }
        $rows[] = $document;
    }

    return documents_write_sales_store($path, $rows);
}

function documents_quote_link_workflow_doc(array &$quote, string $type, string $id): void
{
    $quote = documents_quote_prepare($quote);
    if ($type === 'agreement') {
        $quote['workflow']['agreement_id'] = $id;
    } elseif ($type === 'proforma') {
        $quote['workflow']['proforma_invoice_id'] = $id;
    } elseif ($type === 'invoice') {
        $quote['workflow']['invoice_id'] = $id;
    } elseif ($type === 'receipt') {
        $ids = is_array($quote['workflow']['receipt_ids'] ?? null) ? $quote['workflow']['receipt_ids'] : [];
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }
        $quote['workflow']['receipt_ids'] = array_values($ids);
    } elseif ($type === 'delivery_challan') {
        $ids = is_array($quote['workflow']['delivery_challan_ids'] ?? null) ? $quote['workflow']['delivery_challan_ids'] : [];
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }
        $quote['workflow']['delivery_challan_ids'] = array_values($ids);
    }
}

function documents_status_label(array $quote, string $viewerType = 'admin'): string
{
    $status = documents_quote_normalize_status((string) ($quote['status'] ?? 'draft'));
    if ($status === 'draft') {
        if (($quote['created_by_type'] ?? '') === 'employee') {
            return $viewerType === 'employee' ? 'Pending Admin Approval' : 'Needs Approval';
        }
        return 'Draft';
    }
    $labels = [
        'pending_admin_approval' => 'Pending Admin Approval',
        'approved' => 'Approved',
        'accepted' => 'Accepted',
        'archived' => 'Archived',
    ];
    return $labels[$status] ?? ucfirst($status);
}

function documents_inventory_component_defaults(): array
{
    return [
        'id' => '',
        'name' => '',
        'category' => '',
        'hsn' => '',
        'default_unit' => 'pcs',
        'tax_profile_id' => '',
        'has_variants' => false,
        'is_cuttable' => false,
        'standard_length_ft' => 0,
        'min_issue_ft' => 1,
        'notes' => '',
        'archived_flag' => false,
        'created_at' => '',
        'updated_at' => '',
    ];
}

function documents_inventory_kit_defaults(): array
{
    return [
        'id' => '',
        'name' => '',
        'category' => '',
        'description' => '',
        'tax_profile_id' => '',
        'items' => [],
        'archived_flag' => false,
        'created_at' => '',
        'updated_at' => '',
    ];
}

function documents_tax_profile_defaults(): array
{
    return [
        'id' => '',
        'name' => '',
        'mode' => 'single',
        'slabs' => [
            ['share_pct' => 100, 'rate_pct' => 5],
        ],
        'notes' => '',
        'archived_flag' => false,
        'created_at' => '',
        'updated_at' => '',
    ];
}

function documents_component_variant_defaults(): array
{
    return [
        'id' => '',
        'component_id' => '',
        'display_name' => '',
        'brand' => '',
        'technology' => '',
        'wattage_wp' => 0,
        'model_no' => '',
        'hsn_override' => '',
        'tax_profile_id_override' => '',
        'default_unit_override' => '',
        'notes' => '',
        'archived_flag' => false,
        'created_at' => '',
        'updated_at' => '',
    ];
}

function documents_default_tax_profile(): array
{
    return [
        'id' => 'legacy_solar_split_70_30',
        'name' => 'Legacy Solar Split 70/30 (5%/18%)',
        'mode' => 'split',
        'slabs' => [
            ['share_pct' => 70, 'rate_pct' => 5],
            ['share_pct' => 30, 'rate_pct' => 18],
        ],
        'notes' => 'Compatibility fallback profile',
    ];
}

function documents_flat5_tax_profile(): array
{
    return [
        'id' => 'legacy_flat_5',
        'name' => 'Legacy Flat GST @5%',
        'mode' => 'single',
        'slabs' => [
            ['share_pct' => 100, 'rate_pct' => 5],
        ],
        'notes' => 'Compatibility fallback profile',
    ];
}

function documents_validate_tax_profile(array $profile): array
{
    $name = safe_text((string) ($profile['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'error' => 'Tax profile name is required.'];
    }
    $mode = in_array((string) ($profile['mode'] ?? 'single'), ['single', 'split'], true) ? (string) $profile['mode'] : 'single';
    $slabs = is_array($profile['slabs'] ?? null) ? $profile['slabs'] : [];
    if ($mode === 'single') {
        $slabs = [
            [
                'share_pct' => 100,
                'rate_pct' => (float) (($slabs[0]['rate_pct'] ?? $profile['rate_pct'] ?? 0)),
            ],
        ];
    }
    if ($slabs === []) {
        return ['ok' => false, 'error' => 'At least one tax slab is required.'];
    }
    $totalShare = 0.0;
    $normalized = [];
    foreach ($slabs as $idx => $slab) {
        if (!is_array($slab)) {
            continue;
        }
        $share = (float) ($slab['share_pct'] ?? 0);
        $rate = (float) ($slab['rate_pct'] ?? 0);
        if ($share < 0) {
            return ['ok' => false, 'error' => 'Share percentage cannot be negative.'];
        }
        if ($rate < 0) {
            return ['ok' => false, 'error' => 'Rate percentage cannot be negative.'];
        }
        if ($mode === 'single') {
            $share = 100.0;
        }
        $totalShare += $share;
        $normalized[] = ['share_pct' => round($share, 4), 'rate_pct' => round($rate, 4)];
    }
    if (abs($totalShare - 100.0) > 0.0001) {
        return ['ok' => false, 'error' => 'Tax slab share total must be exactly 100%.'];
    }

    $profile['name'] = $name;
    $profile['mode'] = $mode;
    $profile['slabs'] = $normalized;
    return ['ok' => true, 'profile' => $profile];
}

function documents_money_round(float $amount): float
{
    return round($amount, 2);
}

function documents_calc_tax_breakdown_from_gross(float $grossInclGst, array $taxProfile): array
{
    $grossInclGst = max(0, documents_money_round($grossInclGst));
    $validated = documents_validate_tax_profile($taxProfile);
    if (!($validated['ok'] ?? false)) {
        $taxProfile = documents_default_tax_profile();
        $validated = documents_validate_tax_profile($taxProfile);
    }
    $profile = (array) ($validated['profile'] ?? documents_default_tax_profile());
    $slabs = is_array($profile['slabs'] ?? null) ? $profile['slabs'] : [];

    $factor = 0.0;
    foreach ($slabs as $slab) {
        $share = ((float) ($slab['share_pct'] ?? 0)) / 100;
        $rate = ((float) ($slab['rate_pct'] ?? 0)) / 100;
        $factor += $share * (1 + $rate);
    }
    if ($factor <= 0) {
        $factor = 1;
    }

    $baseTotal = documents_money_round($grossInclGst / $factor);
    $computedSlabs = [];
    $gstTotal = 0.0;
    foreach ($slabs as $slab) {
        $sharePct = (float) ($slab['share_pct'] ?? 0);
        $ratePct = (float) ($slab['rate_pct'] ?? 0);
        $baseAmount = documents_money_round($baseTotal * ($sharePct / 100));
        $gstAmount = documents_money_round($baseAmount * ($ratePct / 100));
        $gstTotal += $gstAmount;
        $computedSlabs[] = [
            'share_pct' => $sharePct,
            'rate_pct' => $ratePct,
            'base_amount' => $baseAmount,
            'gst_amount' => $gstAmount,
        ];
    }

    $expectedGst = documents_money_round($grossInclGst - $baseTotal);
    $diff = documents_money_round($expectedGst - $gstTotal);
    if ($computedSlabs !== [] && abs($diff) >= 0.01) {
        $last = count($computedSlabs) - 1;
        $computedSlabs[$last]['gst_amount'] = documents_money_round((float) $computedSlabs[$last]['gst_amount'] + $diff);
        $gstTotal = documents_money_round($gstTotal + $diff);
    } else {
        $gstTotal = $expectedGst;
    }

    return [
        'gross_incl_gst' => $grossInclGst,
        'basic_total' => $baseTotal,
        'slabs' => $computedSlabs,
        'gst_total' => documents_money_round($gstTotal),
    ];
}

function documents_calc_gross_from_base(float $baseTotal, array $taxProfile): array
{
    $baseTotal = max(0, documents_money_round($baseTotal));
    $validated = documents_validate_tax_profile($taxProfile);
    $profile = (array) (($validated['ok'] ?? false) ? ($validated['profile'] ?? []) : documents_default_tax_profile());
    $slabs = is_array($profile['slabs'] ?? null) ? $profile['slabs'] : [];
    $gstTotal = 0.0;
    foreach ($slabs as $slab) {
        $baseAmount = documents_money_round($baseTotal * (((float) ($slab['share_pct'] ?? 0)) / 100));
        $gstTotal += documents_money_round($baseAmount * (((float) ($slab['rate_pct'] ?? 0)) / 100));
    }
    return [
        'basic_total' => $baseTotal,
        'gst_total' => documents_money_round($gstTotal),
        'gross_incl_gst' => documents_money_round($baseTotal + $gstTotal),
    ];
}

function documents_inventory_stock_defaults(): array
{
    return ['stock_by_component_id' => []];
}

function documents_inventory_transaction_defaults(): array
{
    return [
        'id' => '',
        'type' => 'IN',
        'component_id' => '',
        'qty' => 0,
        'length_ft' => 0,
        'lot_consumption' => [],
        'ref_type' => 'manual',
        'ref_id' => '',
        'created_at' => '',
        'created_by' => '',
    ];
}

function documents_inventory_component_entry_defaults(): array
{
    return ['on_hand_qty' => 0, 'lots' => [], 'updated_at' => ''];
}

function documents_packing_list_defaults(): array
{
    return [
        'id' => '',
        'quotation_id' => '',
        'customer_mobile' => '',
        'customer_name' => '',
        'created_at' => '',
        'status' => 'active',
        'required_items' => [],
        'dispatch_log' => [],
        'archived_flag' => false,
        'updated_at' => '',
    ];
}

function documents_quote_structured_item_defaults(): array
{
    return [
        'type' => 'component',
        'kit_id' => '',
        'component_id' => '',
        'name_snapshot' => '',
        'qty' => 0,
        'unit' => '',
        'variant_id' => '',
        'variant_snapshot' => [],
        'meta' => [],
    ];
}

function documents_inventory_tax_profiles(bool $includeArchived = true): array
{
    $rows = json_load(documents_inventory_tax_profiles_path(), []);
    if (!is_array($rows)) {
        return [];
    }
    $list = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $profile = array_merge(documents_tax_profile_defaults(), $row);
        $validated = documents_validate_tax_profile($profile);
        if (!($validated['ok'] ?? false)) {
            continue;
        }
        $profile = (array) ($validated['profile'] ?? $profile);
        if (!$includeArchived && !empty($profile['archived_flag'])) {
            continue;
        }
        $list[] = $profile;
    }
    usort($list, static fn(array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    return $list;
}

function documents_inventory_save_tax_profiles(array $rows): array
{
    return json_save(documents_inventory_tax_profiles_path(), array_values($rows));
}

function documents_inventory_get_tax_profile(string $id): ?array
{
    foreach (documents_inventory_tax_profiles(true) as $profile) {
        if ((string) ($profile['id'] ?? '') === $id) {
            return $profile;
        }
    }
    return null;
}

function documents_inventory_component_variants(bool $includeArchived = true): array
{
    $rows = json_load(documents_inventory_component_variants_path(), []);
    if (!is_array($rows)) {
        return [];
    }
    $list = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $variant = array_merge(documents_component_variant_defaults(), $row);
        $variant['wattage_wp'] = max(0, (float) ($variant['wattage_wp'] ?? 0));
        $variant['variant_snapshot'] = is_array($variant['variant_snapshot'] ?? null) ? $variant['variant_snapshot'] : [];
        if (!$includeArchived && !empty($variant['archived_flag'])) {
            continue;
        }
        $list[] = $variant;
    }
    usort($list, static fn(array $a, array $b): int => strcasecmp((string) ($a['display_name'] ?? ''), (string) ($b['display_name'] ?? '')));
    return $list;
}

function documents_inventory_save_component_variants(array $rows): array
{
    return json_save(documents_inventory_component_variants_path(), array_values($rows));
}

function documents_inventory_get_component_variant(string $id): ?array
{
    foreach (documents_inventory_component_variants(true) as $variant) {
        if ((string) ($variant['id'] ?? '') === $id) {
            return $variant;
        }
    }
    return null;
}

function documents_inventory_components(bool $includeArchived = true): array
{
    $rows = json_load(documents_inventory_components_path(), []);
    if (!is_array($rows)) {
        return [];
    }
    $list = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $component = array_merge(documents_inventory_component_defaults(), $row);
        $component['is_cuttable'] = (bool) ($component['is_cuttable'] ?? false);
        $component['has_variants'] = (bool) ($component['has_variants'] ?? false);
        $component['standard_length_ft'] = (float) ($component['standard_length_ft'] ?? 0);
        $component['min_issue_ft'] = max(0.01, (float) ($component['min_issue_ft'] ?? 1));
        if (!$includeArchived && !empty($component['archived_flag'])) {
            continue;
        }
        $list[] = $component;
    }
    usort($list, static fn(array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    return $list;
}

function documents_inventory_kits(bool $includeArchived = true): array
{
    $rows = json_load(documents_inventory_kits_path(), []);
    if (!is_array($rows)) {
        return [];
    }
    $list = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $kit = array_merge(documents_inventory_kit_defaults(), $row);
        $kit['items'] = is_array($kit['items'] ?? null) ? $kit['items'] : [];
        if (!$includeArchived && !empty($kit['archived_flag'])) {
            continue;
        }
        $list[] = $kit;
    }
    usort($list, static fn(array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    return $list;
}

function documents_inventory_get_component(string $id): ?array
{
    foreach (documents_inventory_components(true) as $component) {
        if ((string) ($component['id'] ?? '') === $id) {
            return $component;
        }
    }
    return null;
}

function documents_inventory_get_kit(string $id): ?array
{
    foreach (documents_inventory_kits(true) as $kit) {
        if ((string) ($kit['id'] ?? '') === $id) {
            return $kit;
        }
    }
    return null;
}

function documents_inventory_save_components(array $components): array
{
    return json_save(documents_inventory_components_path(), array_values($components));
}

function documents_inventory_save_kits(array $kits): array
{
    return json_save(documents_inventory_kits_path(), array_values($kits));
}

function documents_inventory_load_stock(): array
{
    $stock = json_load(documents_inventory_stock_path(), documents_inventory_stock_defaults());
    $stock = array_merge(documents_inventory_stock_defaults(), is_array($stock) ? $stock : []);
    $stock['stock_by_component_id'] = is_array($stock['stock_by_component_id'] ?? null) ? $stock['stock_by_component_id'] : [];
    return $stock;
}

function documents_inventory_save_stock(array $stock): array
{
    return json_save(documents_inventory_stock_path(), $stock);
}

function documents_inventory_load_transactions(): array
{
    $rows = json_load(documents_inventory_transactions_path(), []);
    return is_array($rows) ? $rows : [];
}

function documents_inventory_append_transaction(array $tx): array
{
    $rows = documents_inventory_load_transactions();
    $rows[] = array_merge(documents_inventory_transaction_defaults(), $tx);
    return json_save(documents_inventory_transactions_path(), $rows);
}

function documents_inventory_component_stock(array $stock, string $componentId): array
{
    $entry = $stock['stock_by_component_id'][$componentId] ?? [];
    $entry = array_merge(documents_inventory_component_entry_defaults(), is_array($entry) ? $entry : []);
    $entry['on_hand_qty'] = (float) ($entry['on_hand_qty'] ?? 0);
    $entry['lots'] = is_array($entry['lots'] ?? null) ? $entry['lots'] : [];
    return $entry;
}

function documents_inventory_total_remaining_ft(array $entry): float
{
    $sum = 0.0;
    foreach ((array) ($entry['lots'] ?? []) as $lot) {
        if (!is_array($lot)) {
            continue;
        }
        $sum += max(0, (float) ($lot['remaining_length_ft'] ?? 0));
    }
    return $sum;
}

function documents_inventory_has_sufficient(array $component, array $stock, float $required): bool
{
    $entry = documents_inventory_component_stock($stock, (string) ($component['id'] ?? ''));
    if (!empty($component['is_cuttable'])) {
        return documents_inventory_total_remaining_ft($entry) + 0.00001 >= $required;
    }
    return (float) ($entry['on_hand_qty'] ?? 0) + 0.00001 >= $required;
}

function documents_packing_lists(bool $includeArchived = true): array
{
    $rows = json_load(documents_packing_lists_path(), []);
    if (!is_array($rows)) {
        return [];
    }
    $list = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $pack = array_merge(documents_packing_list_defaults(), $row);
        if (!$includeArchived && !empty($pack['archived_flag'])) {
            continue;
        }
        $pack['required_items'] = is_array($pack['required_items'] ?? null) ? $pack['required_items'] : [];
        $pack['dispatch_log'] = is_array($pack['dispatch_log'] ?? null) ? $pack['dispatch_log'] : [];
        $list[] = $pack;
    }
    return $list;
}

function documents_save_packing_lists(array $rows): array
{
    return json_save(documents_packing_lists_path(), array_values($rows));
}

function documents_get_packing_list_for_quote(string $quotationId, bool $includeArchived = false): ?array
{
    foreach (documents_packing_lists(true) as $row) {
        if ((string) ($row['quotation_id'] ?? '') !== $quotationId) {
            continue;
        }
        if (!$includeArchived && !empty($row['archived_flag'])) {
            continue;
        }
        return $row;
    }
    return null;
}

function documents_save_packing_list(array $packingList): array
{
    $rows = documents_packing_lists(true);
    $id = (string) ($packingList['id'] ?? '');
    $found = false;
    foreach ($rows as $idx => $row) {
        if ((string) ($row['id'] ?? '') === $id) {
            $rows[$idx] = $packingList;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $rows[] = $packingList;
    }
    return documents_save_packing_lists($rows);
}

function documents_normalize_quote_structured_items(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $item = array_merge(documents_quote_structured_item_defaults(), $row);
        $item['type'] = in_array((string) ($item['type'] ?? 'component'), ['kit', 'component'], true) ? (string) $item['type'] : 'component';
        $item['qty'] = max(0, (float) ($item['qty'] ?? 0));
        $item['variant_id'] = safe_text((string) ($item['variant_id'] ?? ''));
        $item['variant_snapshot'] = is_array($item['variant_snapshot'] ?? null) ? $item['variant_snapshot'] : [];
        $item['meta'] = is_array($item['meta'] ?? null) ? $item['meta'] : [];
        if ($item['qty'] <= 0) {
            continue;
        }
        if ($item['type'] === 'kit' && (string) ($item['kit_id'] ?? '') === '') {
            continue;
        }
        if ($item['type'] === 'component' && (string) ($item['component_id'] ?? '') === '') {
            continue;
        }
        $out[] = $item;
    }
    return $out;
}

function documents_create_packing_list_from_quote(array $quote): array
{
    $quoteItems = documents_normalize_quote_structured_items(is_array($quote['quote_items'] ?? null) ? $quote['quote_items'] : []);
    if ($quoteItems === []) {
        return ['ok' => false, 'error' => 'No structured items selected'];
    }

    $components = documents_inventory_components(true);
    $componentMap = [];
    foreach ($components as $component) {
        $componentMap[(string) ($component['id'] ?? '')] = $component;
    }

    $totals = [];
    foreach ($quoteItems as $item) {
        if ((string) ($item['type'] ?? '') === 'kit') {
            $kit = documents_inventory_get_kit((string) ($item['kit_id'] ?? ''));
            if ($kit === null) {
                continue;
            }
            foreach ((array) ($kit['items'] ?? []) as $bomLine) {
                if (!is_array($bomLine)) {
                    continue;
                }
                $componentId = (string) ($bomLine['component_id'] ?? '');
                if ($componentId === '') {
                    continue;
                }
                $qty = (float) ($bomLine['qty'] ?? 0) * (float) ($item['qty'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                if (!isset($totals[$componentId])) {
                    $totals[$componentId] = 0.0;
                }
                $totals[$componentId] += $qty;
            }
            continue;
        }

        $componentId = (string) ($item['component_id'] ?? '');
        if ($componentId === '') {
            continue;
        }
        if (!isset($totals[$componentId])) {
            $totals[$componentId] = 0.0;
        }
        $totals[$componentId] += (float) ($item['qty'] ?? 0);
    }

    if ($totals === []) {
        return ['ok' => false, 'error' => 'No structured items selected'];
    }

    $required = [];
    foreach ($totals as $componentId => $qty) {
        $component = $componentMap[$componentId] ?? null;
        if (!is_array($component)) {
            continue;
        }
        $isCuttable = !empty($component['is_cuttable']);
        $line = [
            'component_id' => $componentId,
            'component_name_snapshot' => (string) ($component['name'] ?? 'Component'),
            'unit' => $isCuttable ? 'ft' : (string) ($component['default_unit'] ?? 'pcs'),
            'required_qty' => $isCuttable ? 0 : $qty,
            'required_ft' => $isCuttable ? $qty : 0,
            'dispatched_qty' => 0,
            'dispatched_ft' => 0,
            'pending_qty' => $isCuttable ? 0 : $qty,
            'pending_ft' => $isCuttable ? $qty : 0,
        ];
        $required[] = $line;
    }

    if ($required === []) {
        return ['ok' => false, 'error' => 'No structured items selected'];
    }

    $quote = documents_quote_prepare($quote);
    $packingList = documents_packing_list_defaults();
    $packingList['id'] = 'pl_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    $packingList['quotation_id'] = (string) ($quote['id'] ?? '');
    $packingList['customer_mobile'] = normalize_customer_mobile((string) ($quote['customer_mobile'] ?? ''));
    $packingList['customer_name'] = (string) ($quote['customer_name'] ?? '');
    $packingList['status'] = 'active';
    $packingList['required_items'] = $required;
    $packingList['created_at'] = date('c');
    $packingList['updated_at'] = date('c');

    $saved = documents_save_packing_list($packingList);
    if (!($saved['ok'] ?? false)) {
        return ['ok' => false, 'error' => 'Unable to save packing list'];
    }

    $quote['workflow'] = array_merge(documents_quote_workflow_defaults(), is_array($quote['workflow'] ?? null) ? $quote['workflow'] : []);
    $quote['workflow']['packing_list_id'] = (string) $packingList['id'];
    $quote['updated_at'] = date('c');
    $savedQuote = documents_save_quote($quote);
    if (!($savedQuote['ok'] ?? false)) {
        return ['ok' => false, 'error' => 'Packing list created but quote update failed'];
    }

    return ['ok' => true, 'packing_list' => $packingList, 'error' => ''];
}

function documents_apply_dispatch_to_packing_list(array $packingList, string $challanId, array $dispatchRows): array
{
    $required = is_array($packingList['required_items'] ?? null) ? $packingList['required_items'] : [];
    $byId = [];
    foreach ($required as $idx => $line) {
        if (!is_array($line)) {
            continue;
        }
        $byId[(string) ($line['component_id'] ?? '')] = $idx;
    }

    $dispatchLogItems = [];
    foreach ($dispatchRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $componentId = (string) ($row['component_id'] ?? '');
        if ($componentId === '' || !isset($byId[$componentId])) {
            continue;
        }
        $idx = $byId[$componentId];
        $line = array_merge([
            'required_qty' => 0,
            'required_ft' => 0,
            'dispatched_qty' => 0,
            'dispatched_ft' => 0,
            'pending_qty' => 0,
            'pending_ft' => 0,
            'unit' => 'pcs',
        ], $required[$idx]);

        $dispatchQty = max(0, (float) ($row['dispatch_qty'] ?? 0));
        $dispatchFt = max(0, (float) ($row['dispatch_ft'] ?? 0));
        $isCuttable = strtolower((string) ($line['unit'] ?? '')) === 'ft';

        if ($isCuttable) {
            $line['dispatched_ft'] = (float) ($line['dispatched_ft'] ?? 0) + $dispatchFt;
            $line['pending_ft'] = max(0, (float) ($line['required_ft'] ?? 0) - (float) ($line['dispatched_ft'] ?? 0));
        } else {
            $line['dispatched_qty'] = (float) ($line['dispatched_qty'] ?? 0) + $dispatchQty;
            $line['pending_qty'] = max(0, (float) ($line['required_qty'] ?? 0) - (float) ($line['dispatched_qty'] ?? 0));
        }

        $required[$idx] = $line;
        $dispatchLogItems[] = [
            'component_id' => $componentId,
            'qty' => $dispatchQty,
            'ft' => $dispatchFt,
        ];
    }

    $packingList['required_items'] = $required;
    $packingList['dispatch_log'][] = [
        'delivery_challan_id' => $challanId,
        'at' => date('c'),
        'items' => $dispatchLogItems,
    ];

    $allPendingDone = true;
    foreach ($required as $line) {
        if (!is_array($line)) {
            continue;
        }
        if ((float) ($line['pending_qty'] ?? 0) > 0 || (float) ($line['pending_ft'] ?? 0) > 0) {
            $allPendingDone = false;
            break;
        }
    }
    $packingList['status'] = $allPendingDone ? 'complete' : 'active';
    $packingList['updated_at'] = date('c');

    return $packingList;
}

function documents_inventory_consume_fifo_lots(array $lots, float $requiredFt): array
{
    $remaining = $requiredFt;
    $consumed = [];
    foreach ($lots as $idx => $lot) {
        if (!is_array($lot)) {
            continue;
        }
        if ($remaining <= 0) {
            break;
        }
        $lotRemaining = max(0, (float) ($lot['remaining_length_ft'] ?? 0));
        if ($lotRemaining <= 0) {
            continue;
        }
        $used = min($lotRemaining, $remaining);
        $lots[$idx]['remaining_length_ft'] = round($lotRemaining - $used, 4);
        $consumed[] = ['lot_id' => (string) ($lot['lot_id'] ?? ''), 'used_ft' => $used];
        $remaining -= $used;
    }

    return [
        'ok' => $remaining <= 0.00001,
        'lots' => $lots,
        'lot_consumption' => $consumed,
        'remaining_ft' => max(0, $remaining),
    ];
}
