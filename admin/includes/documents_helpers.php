<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/customer_admin.php';
require_once __DIR__ . '/../../includes/leads.php';

function documents_php_error_log_path(): string
{
    return dirname(__DIR__, 2) . '/data/logs/php_errors.log';
}

function documents_configure_php_error_logging(): void
{
    static $configured = false;
    if ($configured) {
        return;
    }
    $configured = true;

    $logDir = dirname(documents_php_error_log_path());
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    $currentErrorLog = (string) ini_get('error_log');
    if (trim($currentErrorLog) === '') {
        ini_set('error_log', documents_php_error_log_path());
    }
}

documents_configure_php_error_logging();

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

function documents_inventory_verification_log_path(): string
{
    return documents_inventory_dir() . '/verification_log.json';
}

function documents_inventory_locations_path(): string
{
    return documents_inventory_dir() . '/locations.json';
}

function documents_inventory_edits_log_path(): string
{
    return documents_inventory_dir() . '/inventory_edits_log.json';
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
    return documents_base_dir() . '/payment_receipts.json';
}

function documents_sales_receipts_legacy_store_path(): string
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

    $receiptsPath = documents_sales_receipts_store_path();
    $legacyReceiptsPath = documents_sales_receipts_legacy_store_path();
    if (!is_file($receiptsPath)) {
        if (is_file($legacyReceiptsPath)) {
            $legacyReceipts = json_load($legacyReceiptsPath, []);
            json_save($receiptsPath, is_array($legacyReceipts) ? $legacyReceipts : []);
        } else {
            json_save($receiptsPath, []);
        }
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
        documents_inventory_locations_path(),
        documents_inventory_transactions_path(),
        documents_inventory_verification_log_path(),
        documents_inventory_edits_log_path(),
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
                'whatsapp_message_template' => 'Namaste {{name}}, your quotation for {{system_size}} kW {{system_type}} solar system for {{city}} is ready. Total price considered is ₹{{price}}. Please open the quotation link and click Accept Quotation after reviewing it: {{quotation_link}}',
            ],
        ],
        'defaults' => [
            'hsn_solar' => '8541',
            'quotation_tax_profile_id' => '',
            'cover_note_template' => '<p>Namaste! Thank you for considering Dakshayani Enterprises for your rooftop solar journey.</p><p>As a Jharkhand-based EPC partner, we handle complete design, installation, and support with local execution accountability.</p><p>Our team will also guide you through DISCOM approvals, net-metering paperwork, and PM Surya Ghar process steps so your transition stays smooth and transparent.</p>',
            'cover_note_presentations' => [
                'RES' => ['kicker'=>'A note for your home', 'heading'=>'Dear Homeowner'],
                'COM' => ['kicker'=>'A note for your business', 'heading'=>'Dear Business Customer'],
                'IND' => ['kicker'=>'A note for your facility', 'heading'=>'Dear Industrial Customer'],
                'INST' => ['kicker'=>'A note for your organization', 'heading'=>'Dear Organization'],
                'PROD' => ['kicker'=>'A note for you', 'heading'=>'Dear Customer'],
            ],
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
        'important_points' => documents_quote_important_points_defaults(),
        'rate_chart' => [
            'on_grid' => [
                ['solar_size_kwp' => 2, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 3, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 4, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 5, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 6, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 7, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 8, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 9, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 10, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
            ],
            'hybrid' => [
                ['solar_size_kwp' => 0, 'inverter_kva' => 3.0, 'phase' => '1', 'battery_count' => 2, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 0, 'inverter_kva' => 3.0, 'phase' => '1', 'battery_count' => 4, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 0, 'inverter_kva' => 5.0, 'phase' => '1', 'battery_count' => 4, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 0, 'inverter_kva' => 7.5, 'phase' => '1', 'battery_count' => 8, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 0, 'inverter_kva' => 10.0, 'phase' => '1', 'battery_count' => 10, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 0, 'inverter_kva' => 10.0, 'phase' => '1', 'battery_count' => 15, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 0, 'inverter_kva' => 15.0, 'phase' => '1', 'battery_count' => 20, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 0, 'inverter_kva' => 7.5, 'phase' => '3', 'battery_count' => 8, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 0, 'inverter_kva' => 10.0, 'phase' => '3', 'battery_count' => 10, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
                ['solar_size_kwp' => 0, 'inverter_kva' => 15.0, 'phase' => '3', 'battery_count' => 15, 'self_funded_price' => 0, 'loan_upto_2_lacs_price' => 0, 'loan_above_2_lacs_price' => 0],
            ],
        ],
    ];
}


function documents_quote_important_points_defaults(): array
{
    return [
        'enabled' => true,
        'title' => 'Important Points',
        'intro' => 'Please review the following before approving this quotation.',
        'points' => [
            ['id'=>'system_inclusions_annexure','text'=>'All items shall be supplied as per the “System Inclusions” mentioned in the Annexure, unless specifically mentioned otherwise in this quotation.','active'=>true,'sort_order'=>10],
            ['id'=>'annexure_conditions_prevail','text'=>'The conditions mentioned in the Annexure shall apply and prevail unless this quotation specifically states otherwise.','active'=>true,'sort_order'=>20],
            ['id'=>'no_verbal_commitments','text'=>'Verbal commitments or informal discussions shall not be considered part of the agreed scope. Please ask us to mention every requirement, clarification, or commitment in this quotation before approval.','active'=>true,'sort_order'=>30],
        ],
    ];
}

function documents_quote_normalize_important_points_settings(array $raw): array
{
    $defaults = documents_quote_important_points_defaults();
    $settings = array_merge($defaults, $raw);
    $settings['enabled'] = (bool) ($settings['enabled'] ?? true);
    $settings['title'] = substr(safe_text((string) ($settings['title'] ?? $defaults['title'])), 0, 120) ?: $defaults['title'];
    $settings['intro'] = substr(safe_multiline_text((string) ($settings['intro'] ?? '')), 0, 500);
    $points = [];
    foreach ((array) ($settings['points'] ?? []) as $idx => $point) {
        if (!is_array($point)) { continue; }
        $text = substr(safe_multiline_text((string) ($point['text'] ?? '')), 0, 1000);
        if (trim($text) === '') { continue; }
        $id = safe_filename((string) ($point['id'] ?? '')) ?: ('important_point_' . ($idx + 1) . '_' . substr(hash('sha1', $text), 0, 8));
        $points[] = ['id'=>$id, 'text'=>$text, 'active'=>!empty($point['active']), 'sort_order'=>(int) ($point['sort_order'] ?? (($idx + 1) * 10))];
        if (count($points) >= 20) { break; }
    }
    if ($points === []) { $points = $defaults['points']; }
    usort($points, static fn(array $a, array $b): int => ((int)$a['sort_order'] <=> (int)$b['sort_order']) ?: strcmp((string)$a['id'], (string)$b['id']));
    $settings['points'] = array_values($points);
    return $settings;
}

function documents_quote_resolve_important_points(array $quote, array $quoteDefaults): array
{
    $snapshot = is_array($quote['important_points_snapshot'] ?? null) ? $quote['important_points_snapshot'] : [];
    if ($snapshot !== []) { return documents_quote_normalize_important_points_settings($snapshot); }
    return documents_quote_normalize_important_points_settings(is_array($quoteDefaults['important_points'] ?? null) ? $quoteDefaults['important_points'] : []);
}

function documents_quote_active_important_points(array $settings): array
{
    if (empty($settings['enabled'])) { return []; }
    return array_values(array_filter((array) ($settings['points'] ?? []), static fn($p): bool => is_array($p) && !empty($p['active']) && trim((string)($p['text'] ?? '')) !== ''));
}

function documents_quote_render_important_points(array $settings): string
{
    $points = documents_quote_active_important_points($settings);
    if ($points === []) { return ''; }
    $title = htmlspecialchars((string) ($settings['title'] ?? 'Important Points'), ENT_QUOTES, 'UTF-8');
    $intro = trim((string) ($settings['intro'] ?? ''));
    $html = '<section class="card important-points-card"><div class="section-kicker">Customer attention notes</div><div class="h sec">'.$title.'</div>';
    if ($intro !== '') { $html .= '<p class="important-points-intro">'.nl2br(htmlspecialchars($intro, ENT_QUOTES, 'UTF-8')).'</p>'; }
    $html .= '<ol class="important-points-list">';
    foreach ($points as $point) { $html .= '<li>'.nl2br(htmlspecialchars((string)$point['text'], ENT_QUOTES, 'UTF-8')).'</li>'; }
    return $html . '</ol></section>';
}

function documents_quote_ensure_important_points_snapshot(array $quote, ?array $quoteDefaults = null): array
{
    $existing = is_array($quote['important_points_snapshot'] ?? null) ? $quote['important_points_snapshot'] : [];
    if ($existing !== []) { $quote['important_points_snapshot'] = documents_quote_normalize_important_points_settings($existing); return $quote; }
    $quote['important_points_snapshot'] = documents_quote_normalize_important_points_settings(is_array(($quoteDefaults ?? documents_get_quote_defaults_settings())['important_points'] ?? null) ? ($quoteDefaults ?? documents_get_quote_defaults_settings())['important_points'] : []);
    return $quote;
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

function safe_multiline_text($s): string
{
    $value = trim((string) $s);
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
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
        if (is_array($default) && !is_array($decoded)) {
            documents_log('JSON shape mismatch at ' . $absPath . ': expected array payload');
            return $default;
        }
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
                if (($rule['doc_type'] ?? '') === $entry['doc_type'] && ($rule['segment'] ?? '') === $segment && documents_numbering_rule_is_active(is_array($rule) ? $rule : [])) {
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
                'is_active' => true,
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

function documents_numbering_rule_is_active(array $rule): bool
{
    if (($rule['archived_flag'] ?? false) || ($rule['is_active'] ?? true) === false) {
        return false;
    }
    return (bool) ($rule['active'] ?? true);
}

function documents_first_non_empty_string(array $sources): string
{
    foreach ($sources as $value) {
        $text = safe_text((string) $value);
        if ($text !== '') {
            return $text;
        }
    }
    return '';
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
        'main_solar_kwp' => '',
        'complimentary_non_dcr_kwp' => '',
        'capacity_kwp' => '',
        'system_capacity_kwp' => 0,
        'project_summary_line' => '',
        'quotation_date' => '',
        'valid_until' => '',
        'pricing_mode' => 'solar_split_70_30',
        'show_tax_breakup' => true,
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
            'gross_payable_before_discount' => 0,
            'discount_rs' => 0,
            'discount_note' => '',
            'gross_payable' => 0,
            'subsidy_expected_rs' => 0,
            'net_after_subsidy' => 0,
        ],
        'cover_note_text' => '',
        'cover_notes_html_snapshot' => '',
        'cover_note_presentation_snapshot' => [],
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
            'completion_milestones' => '',
            'next_steps' => '',
        ],
        'template_attachments' => documents_template_attachment_defaults(),
        'panel_orientation' => documents_quote_panel_orientation_defaults(),
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
            'discount_rs' => '0',
            'discount_note' => '',
        ],
        'primary_finance_scenario' => 'loan_above_2_lacs',
        'scenario_prices' => [
            'self_funded' => ['price' => 0],
            'loan_upto_2_lacs' => ['price' => 0],
            'loan_above_2_lacs' => ['price' => 0, 'applicable' => true],
        ],
        'finance_scenarios' => [
            'self_funded' => [],
            'loan_upto_2_lacs' => [],
            'loan_above_2_lacs' => [],
        ],
        'rate_chart_snapshot' => [],
        'customer_savings_inputs' => [
            'unit_rate_rs_per_kwh' => null,
            'annual_generation_kwh_per_kw' => null,
            'bank_loan_enabled' => true,
            'loan_interest_rate_percent' => null,
            'loan_tenure_months' => null,
            'loan_cap_rs' => null,
            'margin_rule_percent' => null,
            'margin_amount_rs' => null,
            'monthly_bill_before_rs' => null,
            'monthly_units_before' => null,
        ],
        'style_overrides' => [
            'typography' => ['base_font_px' => '', 'heading_scale' => '', 'density' => ''],
            'watermark' => ['enabled' => '', 'image_path' => '', 'opacity' => ''],
        ],
        'public_share_enabled' => false,
        'public_share_token' => '',
        'public_share_created_at' => '',
        'public_share_revoked_at' => null,
        'public_share_expires_at' => null,
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
        'customer_visible_change_history' => [],
    ];
}

function documents_quote_default_finance_scenario_shape(): array
{
    return [
        'price' => 0.0,
        'subsidy' => 0.0,
        'gross_payable' => 0.0,
        'net_investment_after_subsidy' => 0.0,
        'monthly_outflow' => 0.0,
        'payback' => 0.0,
        'applicable' => true,
        'margin_money_rs' => 0.0,
        'loan_amount_rs' => 0.0,
        'effective_loan_principal_rs' => 0.0,
        'interest_pct' => 0.0,
        'tenure_years' => 0.0,
        'emi_rs' => 0.0,
        'residual_bill_rs' => 0.0,
        'finance_mode' => 'ratio',
        'margin_ratio_pct' => 20.0,
        'loan_ratio_pct' => 80.0,
    ];
}

function documents_quote_find_rate_chart_row(array $rateChart, string $systemType, float $solarSizeKwp, float $inverterKva = 0.0, string $phase = '', int $batteryCount = 0): array
{
    $type = strtolower(trim($systemType)) === 'hybrid' ? 'hybrid' : 'on_grid';
    $rows = is_array($rateChart[$type] ?? null) ? $rateChart[$type] : [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (abs(((float) ($row['solar_size_kwp'] ?? 0)) - $solarSizeKwp) > 0.01) {
            continue;
        }
        if ($type === 'hybrid') {
            if (abs(((float) ($row['inverter_kva'] ?? 0)) - $inverterKva) > 0.01) {
                continue;
            }
            if ((string) ($row['phase'] ?? '') !== $phase) {
                continue;
            }
            if ((int) ($row['battery_count'] ?? 0) !== $batteryCount) {
                continue;
            }
        }
        return $row;
    }
    return [];
}

function documents_quote_resolve_customer_savings_inputs(array $quote, ?array $quoteDefaults = null): array
{
    $defaults = $quoteDefaults ?? documents_get_quote_defaults_settings();
    $segmentCode = strtoupper(trim((string) ($quote['segment'] ?? 'RES')));
    $segments = is_array($defaults['segments'] ?? null) ? $defaults['segments'] : [];
    $segment = is_array($segments[$segmentCode] ?? null) ? $segments[$segmentCode] : [];
    $fallbackRes = is_array($segments['RES'] ?? null) ? $segments['RES'] : [];
    $loanSegment = is_array($segment['loan_bestcase'] ?? null) ? $segment['loan_bestcase'] : [];
    $loanRes = is_array($fallbackRes['loan_bestcase'] ?? null) ? $fallbackRes['loan_bestcase'] : [];
    $financeInputs = is_array($quote['finance_inputs'] ?? null) ? $quote['finance_inputs'] : [];
    $loanFinance = is_array($financeInputs['loan'] ?? null) ? $financeInputs['loan'] : [];
    $saved = is_array($quote['customer_savings_inputs'] ?? null) ? $quote['customer_savings_inputs'] : [];

    $toNullableFloat = static function ($value): ?float {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && trim($value) === '') {
            return null;
        }
        return (float) $value;
    };
    $toNullableInt = static function ($value): ?int {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && trim($value) === '') {
            return null;
        }
        return (int) round((float) $value);
    };

    $resolved = [
        'unit_rate_rs_per_kwh' => $toNullableFloat($saved['unit_rate_rs_per_kwh'] ?? $financeInputs['unit_rate_rs_per_kwh'] ?? $segment['unit_rate_rs_per_kwh'] ?? $fallbackRes['unit_rate_rs_per_kwh'] ?? 0),
        'annual_generation_kwh_per_kw' => $toNullableFloat($saved['annual_generation_kwh_per_kw'] ?? $financeInputs['annual_generation_per_kw'] ?? $segment['annual_generation_per_kw'] ?? $defaults['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450),
        'bank_loan_enabled' => array_key_exists('bank_loan_enabled', $saved) ? (bool) $saved['bank_loan_enabled'] : (bool) ($loanFinance['enabled'] ?? true),
        'loan_interest_rate_percent' => $toNullableFloat($saved['loan_interest_rate_percent'] ?? $loanFinance['interest_pct'] ?? $loanSegment['interest_pct'] ?? $loanRes['interest_pct'] ?? 6.0),
        'loan_tenure_months' => $toNullableInt($saved['loan_tenure_months'] ?? ((float) ($loanFinance['tenure_years'] ?? $loanSegment['tenure_years'] ?? $loanRes['tenure_years'] ?? 10) * 12)),
        'loan_cap_rs' => $toNullableFloat($saved['loan_cap_rs'] ?? $loanFinance['loan_amount'] ?? $loanSegment['max_loan_rs'] ?? $loanRes['max_loan_rs'] ?? 200000),
        'margin_rule_percent' => $toNullableFloat($saved['margin_rule_percent'] ?? $loanSegment['min_margin_pct'] ?? $loanRes['min_margin_pct'] ?? 10),
        'margin_amount_rs' => $toNullableFloat($saved['margin_amount_rs'] ?? $loanFinance['margin_pct'] ?? null),
        'monthly_bill_before_rs' => $toNullableFloat($saved['monthly_bill_before_rs'] ?? $financeInputs['monthly_bill_rs'] ?? null),
        'monthly_units_before' => $toNullableFloat($saved['monthly_units_before'] ?? null),
    ];

    if (($resolved['loan_tenure_months'] ?? 0) <= 0) {
        $resolved['loan_tenure_months'] = 120;
    }

    return $resolved;
}

function documents_quote_apply_customer_savings_inputs(array $quote, array $request, ?array $quoteDefaults = null): array
{
    $resolved = documents_quote_resolve_customer_savings_inputs($quote, $quoteDefaults);
    $setFloat = static function (string $key, string $postKey) use (&$resolved, $request): void {
        if (!array_key_exists($postKey, $request)) {
            return;
        }
        $raw = $request[$postKey];
        if (is_string($raw) && trim($raw) === '') {
            return;
        }
        $resolved[$key] = (float) $raw;
    };

    $setFloat('unit_rate_rs_per_kwh', 'unit_rate_rs_per_kwh');
    $setFloat('annual_generation_kwh_per_kw', 'annual_generation_per_kw');
    $setFloat('loan_interest_rate_percent', 'loan_interest_pct');
    $setFloat('loan_cap_rs', 'loan_amount');
    $setFloat('margin_amount_rs', 'loan_margin_pct');
    $setFloat('monthly_bill_before_rs', 'monthly_bill_rs');
    $setFloat('monthly_units_before', 'monthly_units_before');
    if (array_key_exists('loan_tenure_years', $request) && trim((string) $request['loan_tenure_years']) !== '') {
        $resolved['loan_tenure_months'] = max(1, (int) round(((float) $request['loan_tenure_years']) * 12));
    }
    if (array_key_exists('loan_enabled', $request)) {
        $resolved['bank_loan_enabled'] = true;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $resolved['bank_loan_enabled'] = false;
    }

    $quote['customer_savings_inputs'] = $resolved;
    return $quote;
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
        'invoice_date' => '',
        'invoice_date_source' => '',
        'status' => 'draft',
        'revision_no' => 0,
        'finalized_at' => '',
        'finalized_by' => [],
        'finalized_snapshot' => [],
        'revisions' => [],
        'audit_events' => [],
        'invoice_kind' => 'public',
        'linked_quote_id' => '',
        'quotation_id' => '',
        'quotation_no' => '',
        'source_quote_version' => 1,
        'source_quote_hash' => '',
        'commercial_items' => [],
        'linked_dispatch_advice_ids' => [],
        'linked_challan_ids' => [],
        'delivery_summary' => [],
        'delivery_details' => [],
        'customer_mobile' => '',
        'customer_snapshot' => documents_customer_snapshot_defaults(),
        'capacity_kwp' => '',
        'pricing_mode' => 'solar_split_70_30',
        'input_total_gst_inclusive' => 0,
        'calc' => [],
        'quotation_snapshot' => [],
        'replacement_for_invoice_id' => '',
        'replaced_by_invoice_id' => '',
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

function documents_quote_restore_status_after_unarchive(array $quote): string
{
    if (documents_quote_is_locked($quote) || safe_text((string) ($quote['accepted_at'] ?? '')) !== '') {
        return 'accepted';
    }
    $approval = is_array($quote['approval'] ?? null) ? $quote['approval'] : [];
    if (safe_text((string) ($approval['approved_at'] ?? '')) !== '') {
        return 'approved';
    }
    return 'draft';
}

function documents_challan_defaults(): array
{
    return [
        'id' => '',
        'dc_id' => '',
        'dc_number' => '',
        'challan_no' => '',
        'status' => 'draft',
        'delivery_status' => 'not_dispatched',
        'workflow_status' => 'created',
        'dispatch_status' => 'not_dispatched',
        'dispatch_advice_id' => '',
        'dispatch_advice_no' => '',
        'dispatch_advice_acceptance_ref' => '',
        'workflow_source_type' => '',
        'source_type' => '',
        'driver_mobile' => '',
        'eway_bill_ref' => '',
        'dispatch_time' => '',
        'public_share_enabled' => false,
        'public_token' => '',
        'public_expires_at' => '',
        'customer_acceptance' => [],
        'customer_acceptance_request' => [],
        'customer_receipt_status' => '',
        'delivered_at' => '',
        'delivery_issue_flag' => false,
        'delivery_issue_remarks' => '',
        'share_audit' => [],
        'approved_at' => '',
        'approved_by' => [],
        'dispatched_at' => '',
        'dispatched_by' => [],
        'customer_receipt' => [],
        'admin_delivery_confirmation' => [],
        'delivery_exception' => [],
        'segment' => 'RES',
        'template_set_id' => '',
        'linked_quote_id' => '',
        'linked_quote_no' => '',
        'quote_id' => '',
        'party_type' => 'lead',
        'customer_mobile' => '',
        'customer_name_snapshot' => '',
        'customer_snapshot' => documents_customer_snapshot_defaults(),
        'site_address' => '',
        'site_address_snapshot' => '',
        'delivery_address' => '',
        'delivery_date' => '',
        'vehicle_no' => '',
        'driver_name' => '',
        'delivery_notes' => '',
        'lines' => [],
        'items' => [],
        'created_by' => ['role' => '', 'id' => '', 'name' => ''],
        'created_by_type' => '',
        'created_by_id' => '',
        'created_by_name' => '',
        'inventory_txn_ids' => [],
        'archived_flag' => false,
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
        'line_id' => '',
        'mode' => 'fixed_qty',
        'variant_id' => '',
        'variant_name_snapshot' => '',
        'wattage_wp' => 0,
        'dispatch_wp' => 0,
        'manual_note' => '',
        'dispatch_qty' => 0,
        'dispatch_ft' => 0,
        'brand_model' => '',
        'source_dispatch_advice_id' => '',
        'source_dispatch_advice_line_id' => '',
    ];
}

function documents_challan_line_defaults(): array
{
    return [
        'line_id' => '',
        'component_id' => '',
        'component_name_snapshot' => '',
        'has_variants_snapshot' => false,
        'variant_id' => '',
        'variant_name_snapshot' => '',
        'is_cuttable_snapshot' => false,
        'qty' => 0,
        'length_ft' => 0,
        'pieces' => 0,
        'piece_length_ft' => 0,
        'source_location_id' => '',
        'lot_ids' => [],
        'selected_lot_ids' => [],
        'lot_cuts' => [],
        'cut_plan_mode' => 'suggested',
        'lot_allocations' => [],
        'total_length_ft' => 0,
        'stock_changed_warning' => false,
        'line_errors' => [],
        'unit_snapshot' => '',
        'hsn_snapshot' => '',
        'notes' => '',
        'line_origin' => 'extra',
        'packing_line_id' => '',
    ];
}

function documents_normalize_challan_lines(array $lines): array
{
    $rows = [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $row = array_merge(documents_challan_line_defaults(), $line);
        $row['line_id'] = safe_text((string) ($row['line_id'] ?? ''));
        if ($row['line_id'] === '') {
            $row['line_id'] = 'line_' . bin2hex(random_bytes(4));
        }
        $row['component_id'] = safe_text((string) ($row['component_id'] ?? ''));
        $row['component_name_snapshot'] = safe_text((string) ($row['component_name_snapshot'] ?? ''));
        $row['has_variants_snapshot'] = !empty($row['has_variants_snapshot']);
        $row['variant_id'] = safe_text((string) ($row['variant_id'] ?? ''));
        $row['variant_name_snapshot'] = safe_text((string) ($row['variant_name_snapshot'] ?? ''));
        $row['is_cuttable_snapshot'] = !empty($row['is_cuttable_snapshot']);
        $row['qty'] = max(0, (float) ($row['qty'] ?? 0));
        $lengthAlias = $row['length_ft'] ?? $row['piece_length_ft'] ?? 0;
        $piecesAlias = $row['pieces'] ?? $row['piece_count'] ?? 0;
        $row['length_ft'] = max(0, (float) $lengthAlias);
        $row['piece_length_ft'] = max(0, (float) ($row['piece_length_ft'] ?? $row['length_ft'] ?? 0));
        $row['pieces'] = max(0, (int) $piecesAlias);
        $row['source_location_id'] = safe_text((string) ($row['source_location_id'] ?? ''));
        $row['lot_ids'] = array_values(array_filter(array_map(static fn($lotId): string => safe_text((string) $lotId), is_array($row['lot_ids'] ?? null) ? $row['lot_ids'] : []), static fn(string $lotId): bool => $lotId !== ''));
        $row['selected_lot_ids'] = array_values(array_filter(array_map(static fn($lotId): string => safe_text((string) $lotId), is_array($row['selected_lot_ids'] ?? null) ? $row['selected_lot_ids'] : []), static fn(string $lotId): bool => $lotId !== ''));
        if ($row['selected_lot_ids'] === [] && $row['lot_ids'] !== []) {
            $row['selected_lot_ids'] = $row['lot_ids'];
        }
        $lotCutsRaw = is_array($row['lot_cuts'] ?? null) ? $row['lot_cuts'] : [];
        $row['lot_cuts'] = [];
        foreach ($lotCutsRaw as $cut) {
            if (!is_array($cut)) {
                continue;
            }
            $lotId = safe_text((string) ($cut['lot_id'] ?? ''));
            $count = max(0, (int) ($cut['count'] ?? 0));
            $cutLength = max(0, (float) ($cut['cut_length_ft'] ?? 0));
            if ($lotId === '' || $count <= 0 || $cutLength <= 0) {
                continue;
            }
            $row['lot_cuts'][] = ['lot_id' => $lotId, 'count' => $count, 'cut_length_ft' => $cutLength];
        }
        $cutPlanMode = strtolower(safe_text((string) ($row['cut_plan_mode'] ?? 'suggested')));
        $row['cut_plan_mode'] = in_array($cutPlanMode, ['suggested', 'manual'], true) ? $cutPlanMode : 'suggested';
        $allocationsRaw = is_array($row['lot_allocations'] ?? null) ? $row['lot_allocations'] : [];
        $allocations = [];
        foreach ($allocationsRaw as $allocation) {
            if (!is_array($allocation)) {
                continue;
            }
            $lotId = safe_text((string) ($allocation['lot_id'] ?? ''));
            $variantId = safe_text((string) ($allocation['variant_id'] ?? $row['variant_id'] ?? ''));
            $pieceLength = max(0, (float) ($allocation['piece_length_ft'] ?? $allocation['cut_length_ft'] ?? 0));
            $pieces = max(1, (int) ($allocation['pieces'] ?? $allocation['cut_pieces'] ?? $allocation['count'] ?? 1));
            $cutLength = round($pieceLength * $pieces, 4);
            $locationSnapshot = safe_text((string) ($allocation['location_id_snapshot'] ?? ''));
            if ($lotId === '' || $pieceLength <= 0 || $cutLength <= 0) {
                continue;
            }
            $allocations[] = [
                'lot_id' => $lotId,
                'variant_id' => $variantId,
                'piece_length_ft' => $pieceLength,
                'pieces' => $pieces,
                'cut_length_ft' => $cutLength,
                'location_id_snapshot' => $locationSnapshot,
            ];
        }
        if ($allocations === [] && $row['lot_cuts'] !== []) {
            foreach ($row['lot_cuts'] as $cut) {
                $pieceLength = max(0, (float) ($cut['cut_length_ft'] ?? 0));
                $pieces = max(1, (int) ($cut['count'] ?? 1));
                $allocations[] = [
                    'lot_id' => (string) ($cut['lot_id'] ?? ''),
                    'variant_id' => (string) ($row['variant_id'] ?? ''),
                    'piece_length_ft' => $pieceLength,
                    'pieces' => $pieces,
                    'cut_length_ft' => round($pieceLength * $pieces, 4),
                    'location_id_snapshot' => '',
                ];
            }
        }
        $row['lot_allocations'] = $allocations;
        $totalLengthFt = 0.0;
        foreach ($row['lot_allocations'] as $allocation) {
            $totalLengthFt += max(0, (float) ($allocation['cut_length_ft'] ?? 0));
        }
        $row['total_length_ft'] = round($totalLengthFt, 4);
        $row['stock_changed_warning'] = !empty($row['stock_changed_warning']);
        $row['line_errors'] = array_values(array_filter(array_map(static fn($error): string => safe_text((string) $error), is_array($row['line_errors'] ?? null) ? $row['line_errors'] : []), static fn(string $error): bool => $error !== ''));
        $row['unit_snapshot'] = safe_text((string) ($row['unit_snapshot'] ?? ''));
        $row['hsn_snapshot'] = safe_text((string) ($row['hsn_snapshot'] ?? ''));
        $row['notes'] = safe_text((string) ($row['notes'] ?? ''));
        $origin = strtolower(safe_text((string) ($row['line_origin'] ?? 'extra')));
        $row['line_origin'] = $origin === 'quotation' ? 'quotation' : 'extra';
        $row['packing_line_id'] = safe_text((string) ($row['packing_line_id'] ?? ''));
        if ($row['component_id'] === '') {
            continue;
        }
        $rows[] = $row;
    }
    return $rows;
}

function documents_migrate_challan_items_to_lines(array $challan): array
{
    $lines = documents_normalize_challan_lines(is_array($challan['lines'] ?? null) ? $challan['lines'] : []);
    if ($lines !== []) {
        return $lines;
    }

    $items = documents_normalize_challan_items(is_array($challan['items'] ?? null) ? $challan['items'] : []);
    $migrated = [];
    foreach ($items as $item) {
        $componentId = safe_text((string) ($item['component_id'] ?? ''));
        if ($componentId === '') {
            continue;
        }
        $component = documents_inventory_get_component($componentId);
        $isCuttable = is_array($component) && !empty($component['is_cuttable']);
        $qty = max(0, (float) ($item['dispatch_qty'] ?? $item['qty'] ?? 0));
        $lengthFt = max(0, (float) ($item['dispatch_ft'] ?? 0));
        if ($isCuttable && $lengthFt <= 0) {
            $lengthFt = $qty;
            $qty = 0;
        }
        $migrated[] = [
            'line_id' => safe_text((string) ($item['line_id'] ?? '')) ?: ('line_' . bin2hex(random_bytes(4))),
            'component_id' => $componentId,
            'component_name_snapshot' => safe_text((string) ($item['name'] ?? $item['description'] ?? '')),
            'has_variants_snapshot' => safe_text((string) ($item['variant_id'] ?? '')) !== '',
            'variant_id' => safe_text((string) ($item['variant_id'] ?? '')),
            'variant_name_snapshot' => safe_text((string) ($item['variant_name_snapshot'] ?? '')),
            'is_cuttable_snapshot' => $isCuttable,
            'qty' => $isCuttable ? 0 : $qty,
            'length_ft' => $isCuttable ? $lengthFt : 0,
            'pieces' => 0,
            'lot_ids' => [],
            'unit_snapshot' => safe_text((string) ($item['unit'] ?? ($isCuttable ? 'ft' : 'Nos'))),
            'hsn_snapshot' => '',
            'notes' => safe_text((string) ($item['remarks'] ?? $item['manual_note'] ?? '')),
        ];
    }
    return documents_normalize_challan_lines($migrated);
}

function documents_challan_pick_first(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && trim((string) $row[$key]) !== '') {
            return safe_text((string) $row[$key]);
        }
    }
    return $default;
}

function documents_normalize_challan_items(array $items): array
{
    $rows = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $row = array_merge(documents_challan_item_defaults(), $item);
        $row['line_id'] = documents_challan_pick_first($item, ['line_id', 'id'], 'chl_' . bin2hex(random_bytes(4)));
        $row['name'] = documents_challan_pick_first($item, ['name', 'item_name', 'component_name_snapshot', 'component_name']);
        $row['description'] = documents_challan_pick_first($item, ['description', 'notes', 'item_description', 'manual_note']);
        $row['brand_model'] = documents_challan_pick_first($item, ['brand_model', 'variant_name_snapshot', 'model', 'brand']);
        $row['unit'] = documents_challan_pick_first($item, ['unit', 'unit_snapshot'], 'Nos') ?: 'Nos';
        $row['qty'] = max(0, (float) ($item['qty'] ?? $item['quantity'] ?? $item['dispatch_qty'] ?? $item['pieces'] ?? 0));
        if ($row['qty'] <= 0 && isset($item['length_ft'])) {
            $row['qty'] = max(0, (float) $item['length_ft']);
        }
        $row['remarks'] = documents_challan_pick_first($item, ['remarks', 'planned_note', 'note']);
        $row['source_dispatch_advice_id'] = documents_challan_pick_first($item, ['source_dispatch_advice_id', 'dispatch_advice_id']);
        $row['source_dispatch_advice_line_id'] = documents_challan_pick_first($item, ['source_dispatch_advice_line_id', 'dispatch_advice_line_id', 'source_line_id']);
        foreach (['component_id','mode','variant_id','variant_name_snapshot','manual_note'] as $key) {
            $row[$key] = safe_text((string) ($row[$key] ?? ''));
        }
        $row['wattage_wp'] = max(0, (float) ($row['wattage_wp'] ?? 0));
        $row['dispatch_wp'] = max(0, (float) ($row['dispatch_wp'] ?? 0));
        $row['dispatch_qty'] = max(0, (float) ($item['dispatch_qty'] ?? $row['qty'] ?? 0));
        $row['dispatch_ft'] = max(0, (float) ($row['dispatch_ft'] ?? 0));
        if ($row['name'] === '' && $row['description'] === '' && $row['brand_model'] === '' && $row['qty'] <= 0) {
            continue;
        }
        $rows[] = $row;
    }
    return $rows;
}

function documents_challan_customer_items(array $challan): array
{
    $items = documents_normalize_challan_items(is_array($challan['items'] ?? null) ? $challan['items'] : []);
    if ($items !== []) {
        return $items;
    }
    return documents_normalize_challan_items(is_array($challan['lines'] ?? null) ? $challan['lines'] : []);
}

function documents_challan_items_from_dispatch_advice(array $d): array
{
    return array_map(static fn($i): array => [
        'line_id' => 'chl_' . bin2hex(random_bytes(4)),
        'name' => $i['name'] ?? '',
        'description' => $i['description'] ?? '',
        'brand_model' => $i['brand_model'] ?? '',
        'qty' => $i['qty'] ?? 0,
        'unit' => $i['unit'] ?? 'Nos',
        'remarks' => $i['remarks'] ?? '',
        'source_dispatch_advice_id' => $d['id'] ?? '',
        'source_dispatch_advice_line_id' => $i['line_id'] ?? '',
    ], documents_normalize_dispatch_advice_items((array) ($d['items'] ?? [])));
}

function documents_challan_backfill_items_from_dispatch_advice(array &$challan, bool $save = false): array
{
    if (documents_challan_customer_items($challan) !== []) {
        $challan['items'] = documents_challan_customer_items($challan);
        return ['ok' => true, 'changed' => false, 'items' => $challan['items'], 'message' => 'Existing Challan item snapshot preserved.'];
    }
    $link = documents_repair_challan_dispatch_advice_link($challan, $save);
    $dispatchAdviceId = safe_text((string) ($challan['dispatch_advice_id'] ?? ''));
    if ($dispatchAdviceId === '') {
        return ['ok' => false, 'changed' => false, 'items' => [], 'message' => 'No linked Dispatch Advice.'];
    }
    $dispatchAdvice = is_array($link['advice'] ?? null) ? $link['advice'] : documents_get_dispatch_advice($dispatchAdviceId);
    if (!$dispatchAdvice) {
        return ['ok' => false, 'changed' => false, 'items' => [], 'message' => 'Linked Dispatch Advice not found.'];
    }
    $items = documents_challan_items_from_dispatch_advice($dispatchAdvice);
    if ($items === []) {
        return ['ok' => false, 'changed' => false, 'items' => [], 'message' => 'Linked Dispatch Advice has no material items.'];
    }
    $challan['items'] = documents_normalize_challan_items($items);
    $challan['material_snapshot_locked'] = true;
    $challan['repair_audit'][] = ['event' => 'items_backfilled_from_dispatch_advice', 'dispatch_advice_id' => $dispatchAdviceId, 'item_count' => count($challan['items']), 'at' => date('c')];
    $challan['updated_at'] = date('c');
    if ($save) {
        documents_save_challan($challan);
    }
    return ['ok' => true, 'changed' => true, 'items' => $challan['items'], 'message' => 'Challan items backfilled from linked Dispatch Advice.'];
}

function documents_challan_has_valid_items(array $challan): bool
{
    if (documents_migrate_challan_items_to_lines($challan) !== []) {
        return true;
    }
    foreach (documents_normalize_challan_items(is_array($challan['items'] ?? null) ? $challan['items'] : []) as $item) {
        if (safe_text((string) ($item['name'] ?? '')) !== '' && max(0, (float) ($item['qty'] ?? 0)) > 0) {
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
    $challan['dc_id'] = (string) ($challan['dc_id'] ?: $challan['id']);
    $resolvedDcNumber = documents_first_non_empty_string([
        $challan['dc_number'] ?? '',
        $challan['challan_no'] ?? '',
        $row['dc_no'] ?? '',
        $row['document_number'] ?? '',
    ]);
    $challan['dc_number'] = $resolvedDcNumber;
    if (safe_text((string) ($challan['challan_no'] ?? '')) === '') {
        $challan['challan_no'] = $resolvedDcNumber;
    }
    $challan['quote_id'] = (string) ($challan['quote_id'] ?: $challan['linked_quote_id']);
    $challan['customer_mobile'] = (string) ($challan['customer_mobile'] ?: ($challan['customer_snapshot']['mobile'] ?? ''));
    $challan['customer_name_snapshot'] = (string) ($challan['customer_name_snapshot'] ?: ($challan['customer_snapshot']['name'] ?? ''));
    $challan['site_address_snapshot'] = (string) ($challan['site_address_snapshot'] ?: $challan['site_address']);
    $challan['customer_snapshot'] = array_merge(documents_customer_snapshot_defaults(), is_array($challan['customer_snapshot'] ?? null) ? $challan['customer_snapshot'] : []);
    $challan['created_by'] = array_merge(['role' => '', 'id' => '', 'name' => ''], is_array($challan['created_by'] ?? null) ? $challan['created_by'] : []);
    if ((string) $challan['created_by']['role'] === '') {
        $challan['created_by'] = ['role' => (string) ($challan['created_by_type'] ?? ''), 'id' => (string) ($challan['created_by_id'] ?? ''), 'name' => (string) ($challan['created_by_name'] ?? '')];
    }
    $challan['inventory_txn_ids'] = array_values(array_filter(array_map(static fn($txnId): string => safe_text((string) $txnId), is_array($challan['inventory_txn_ids'] ?? null) ? $challan['inventory_txn_ids'] : []), static fn(string $txnId): bool => $txnId !== ''));
    $challan['status'] = in_array(strtolower((string) ($challan['status'] ?? 'draft')), ['draft', 'final', 'archived'], true) ? strtolower((string) $challan['status']) : 'draft';
    if ((string) ($row['status'] ?? '') === 'Draft') { $challan['status'] = 'draft'; }
    if ((string) ($row['status'] ?? '') === 'Issued') { $challan['status'] = 'final'; }
    if ((string) ($row['status'] ?? '') === 'Archived') { $challan['status'] = 'archived'; }
    $challan['lines'] = documents_migrate_challan_items_to_lines($challan);
    documents_repair_challan_dispatch_advice_link($challan, true);
    documents_challan_backfill_items_from_dispatch_advice($challan, true);
    $challan['items'] = documents_challan_customer_items($challan);
    if ($resolvedDcNumber !== '' && (
        safe_text((string) ($row['dc_number'] ?? '')) === ''
        || safe_text((string) ($row['challan_no'] ?? '')) === ''
    )) {
        json_save($path, $challan);
    }
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
        $challan['dc_id'] = (string) ($challan['dc_id'] ?: $challan['id']);
        $challan['dc_number'] = (string) ($challan['dc_number'] ?: $challan['challan_no']);
        $challan['quote_id'] = (string) ($challan['quote_id'] ?: $challan['linked_quote_id']);
        $challan['customer_mobile'] = (string) ($challan['customer_mobile'] ?: ($challan['customer_snapshot']['mobile'] ?? ''));
        $challan['customer_name_snapshot'] = (string) ($challan['customer_name_snapshot'] ?: ($challan['customer_snapshot']['name'] ?? ''));
        $challan['site_address_snapshot'] = (string) ($challan['site_address_snapshot'] ?: $challan['site_address']);
        $challan['customer_snapshot'] = array_merge(documents_customer_snapshot_defaults(), is_array($challan['customer_snapshot'] ?? null) ? $challan['customer_snapshot'] : []);
        $challan['created_by'] = array_merge(['role' => '', 'id' => '', 'name' => ''], is_array($challan['created_by'] ?? null) ? $challan['created_by'] : []);
        if ((string) $challan['created_by']['role'] === '') {
            $challan['created_by'] = ['role' => (string) ($challan['created_by_type'] ?? ''), 'id' => (string) ($challan['created_by_id'] ?? ''), 'name' => (string) ($challan['created_by_name'] ?? '')];
        }
        $challan['inventory_txn_ids'] = array_values(array_filter(array_map(static fn($txnId): string => safe_text((string) $txnId), is_array($challan['inventory_txn_ids'] ?? null) ? $challan['inventory_txn_ids'] : []), static fn(string $txnId): bool => $txnId !== ''));
        $challan['status'] = in_array(strtolower((string) ($challan['status'] ?? 'draft')), ['draft', 'final', 'archived'], true) ? strtolower((string) $challan['status']) : 'draft';
        if ((string) ($row['status'] ?? '') === 'Draft') { $challan['status'] = 'draft'; }
        if ((string) ($row['status'] ?? '') === 'Issued') { $challan['status'] = 'final'; }
        if ((string) ($row['status'] ?? '') === 'Archived') { $challan['status'] = 'archived'; }
        $challan['lines'] = documents_migrate_challan_items_to_lines($challan);
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
    $grossPayableBeforeDiscount = $gross + $transportationRs;
    $discountRs = max(0, (float) ($quote['discount_rs'] ?? $quote['finance_inputs']['discount_rs'] ?? 0));
    $discountRs = min($discountRs, $grossPayableBeforeDiscount);
    $grossPayable = $grossPayableBeforeDiscount - $discountRs;
    $discountNote = safe_text((string) ($quote['discount_note'] ?? $quote['finance_inputs']['discount_note'] ?? ''));
    $subsidyExpectedRs = max(0, $subsidyExpectedRs);
    $itemTaxBreakup = documents_calc_quote_item_tax_breakup($quote, $profile, $breakdown);

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
            'items' => array_values((array) ($itemTaxBreakup['items'] ?? [])),
            'item_allocation_basis' => (string) ($itemTaxBreakup['allocation_basis'] ?? 'none'),
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
        'gross_payable_before_discount' => documents_money_round($grossPayableBeforeDiscount),
        'discount_rs' => documents_money_round($discountRs),
        'discount_note' => $discountNote,
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

function documents_calc_quote_item_tax_breakup(array $quote, array $taxProfile, array $taxBreakdown): array
{
    $basicTotal = max(0, (float) ($taxBreakdown['basic_total'] ?? 0));
    if ($basicTotal <= 0) {
        return ['items' => [], 'allocation_basis' => 'none'];
    }

    $legacyItems = documents_normalize_quote_items(
        is_array($quote['items'] ?? null) ? $quote['items'] : [],
        (string) ($quote['system_type'] ?? 'Ongrid'),
        (float) ($quote['capacity_kwp'] ?? 0),
        '8541'
    );
    $structuredItems = documents_normalize_quote_structured_items(is_array($quote['quote_items'] ?? null) ? $quote['quote_items'] : []);

    $sourceRows = [];
    $allocationBasis = 'none';

    foreach ($legacyItems as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = safe_text((string) ($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $sourceRows[] = [
            'name' => $name,
            'hsn' => safe_text((string) ($row['hsn'] ?? '')),
            'qty' => max(0, (float) ($row['qty'] ?? 0)),
            'weight' => max(0, (float) ($row['basic_amount'] ?? 0)),
            'gst_slab' => safe_text((string) ($row['gst_slab'] ?? '')),
            'explicit_slabs' => is_array($row['slabs'] ?? null) ? $row['slabs'] : [],
        ];
    }

    $weightTotal = 0.0;
    foreach ($sourceRows as $row) {
        $weightTotal += (float) ($row['weight'] ?? 0);
    }
    if ($weightTotal > 0) {
        $allocationBasis = 'basic_amount';
    } else {
        $sourceRows = [];
    }

    if ($sourceRows === []) {
        foreach ($structuredItems as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = safe_text((string) ($row['name_snapshot'] ?? ''));
            if ($name === '') {
                continue;
            }
            $sourceRows[] = [
                'name' => $name,
                'hsn' => safe_text((string) ($row['hsn_snapshot'] ?? '')),
                'qty' => max(0, (float) ($row['qty'] ?? 0)),
                'weight' => max(0, (float) ($row['qty'] ?? 0)),
                'gst_slab' => '',
                'explicit_slabs' => [],
            ];
        }
        $allocationBasis = 'quantity';
        $weightTotal = 0.0;
        foreach ($sourceRows as $row) {
            $weightTotal += (float) ($row['weight'] ?? 0);
        }
    }

    if ($sourceRows === [] || $weightTotal <= 0) {
        return ['items' => [], 'allocation_basis' => 'none'];
    }

    $profileSlabs = [];
    foreach ((array) ($taxProfile['slabs'] ?? []) as $slab) {
        if (!is_array($slab)) {
            continue;
        }
        $sharePct = max(0, (float) ($slab['share_pct'] ?? 0));
        $ratePct = max(0, (float) ($slab['rate_pct'] ?? 0));
        if ($sharePct <= 0) {
            continue;
        }
        $profileSlabs[] = ['share_pct' => $sharePct, 'rate_pct' => $ratePct];
    }
    if ($profileSlabs === []) {
        $profileSlabs[] = ['share_pct' => 100.0, 'rate_pct' => 0.0];
    }

    $items = [];
    $itemCount = count($sourceRows);
    $allocatedBasic = 0.0;
    $allocatedGst = 0.0;
    $grandGstTotal = max(0, (float) ($taxBreakdown['gst_total'] ?? 0));
    foreach ($sourceRows as $idx => $source) {
        $isLastItem = $idx === ($itemCount - 1);
        $itemBasic = $isLastItem
            ? documents_money_round($basicTotal - $allocatedBasic)
            : documents_money_round($basicTotal * (((float) ($source['weight'] ?? 0)) / $weightTotal));
        $allocatedBasic += $itemBasic;

        $itemSlabs = [];
        $explicitSlabs = [];
        foreach ((array) ($source['explicit_slabs'] ?? []) as $explicit) {
            if (!is_array($explicit)) {
                continue;
            }
            $share = max(0, (float) ($explicit['share_pct'] ?? 0));
            $rate = max(0, (float) ($explicit['rate_pct'] ?? 0));
            if ($share <= 0) {
                continue;
            }
            $explicitSlabs[] = ['share_pct' => $share, 'rate_pct' => $rate];
        }

        if ($explicitSlabs !== []) {
            $itemSlabs = $explicitSlabs;
        } else {
            $slabRate = safe_text((string) ($source['gst_slab'] ?? ''));
            if ($slabRate !== '' && strtoupper($slabRate) !== 'NA') {
                $itemSlabs[] = ['share_pct' => 100.0, 'rate_pct' => max(0, (float) $slabRate)];
            } else {
                $itemSlabs = $profileSlabs;
            }
        }

        $slabShareTotal = 0.0;
        foreach ($itemSlabs as $slab) {
            $slabShareTotal += (float) ($slab['share_pct'] ?? 0);
        }
        if ($slabShareTotal <= 0) {
            $itemSlabs = [['share_pct' => 100.0, 'rate_pct' => 0.0]];
            $slabShareTotal = 100.0;
        }

        $computedSlabs = [];
        $itemGst = 0.0;
        $itemBasicDistributed = 0.0;
        $itemSlabCount = count($itemSlabs);
        foreach ($itemSlabs as $slabIdx => $slab) {
            $isLastSlab = $slabIdx === ($itemSlabCount - 1);
            $sharePct = max(0, (float) ($slab['share_pct'] ?? 0));
            $shareFraction = $slabShareTotal > 0 ? ($sharePct / $slabShareTotal) : 0;
            $slabBasic = $isLastSlab
                ? documents_money_round($itemBasic - $itemBasicDistributed)
                : documents_money_round($itemBasic * $shareFraction);
            $itemBasicDistributed += $slabBasic;
            $slabRatePct = max(0, (float) ($slab['rate_pct'] ?? 0));
            $slabGst = documents_money_round($slabBasic * ($slabRatePct / 100));
            $itemGst += $slabGst;
            $computedSlabs[] = [
                'share_pct' => $sharePct,
                'rate_pct' => $slabRatePct,
                'taxable_value' => $slabBasic,
                'gst_amount' => $slabGst,
            ];
        }

        $itemGst = documents_money_round($itemGst);
        if ($isLastItem) {
            $itemGst = documents_money_round($grandGstTotal - $allocatedGst);
        }
        $allocatedGst += $itemGst;

        if ($computedSlabs !== []) {
            $slabGstTotal = 0.0;
            foreach ($computedSlabs as $entry) {
                $slabGstTotal += (float) ($entry['gst_amount'] ?? 0);
            }
            $slabGstDiff = documents_money_round($itemGst - $slabGstTotal);
            if (abs($slabGstDiff) >= 0.01) {
                $last = count($computedSlabs) - 1;
                $computedSlabs[$last]['gst_amount'] = documents_money_round((float) ($computedSlabs[$last]['gst_amount'] ?? 0) + $slabGstDiff);
            }
        }

        $items[] = [
            'name' => (string) ($source['name'] ?? ''),
            'hsn' => (string) ($source['hsn'] ?? ''),
            'taxable_value' => documents_money_round($itemBasic),
            'gst_amount' => documents_money_round($itemGst),
            'gross_incl_gst' => documents_money_round($itemBasic + $itemGst),
            'slabs' => $computedSlabs,
        ];
    }

    return ['items' => $items, 'allocation_basis' => $allocationBasis];
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
        'completion_milestones' => '',
        'next_steps' => '',
        'system_inclusions' => '',
        'payment_terms' => '',
        'warranty' => '',
        'system_type_explainer' => '',
        'transportation' => '',
        'terms_conditions' => '',
    ];
}

/** Presentation labels stored with new quotations; cover-note content is deliberately untouched. */
function documents_quote_cover_note_labels(string $segment): array
{
    $segment = strtoupper(trim($segment));
    $labels = [
        'RES' => ['kicker' => 'A note for your home', 'heading' => 'Dear Homeowner'],
        'COM' => ['kicker' => 'A note for your business', 'heading' => 'Dear Business Customer'],
        'IND' => ['kicker' => 'A note for your facility', 'heading' => 'Dear Industrial Customer'],
        'INST' => ['kicker' => 'A note for your organization', 'heading' => 'Dear Organization'],
    ];
    return $labels[$segment] ?? ['kicker' => 'A note for you', 'heading' => 'Dear Customer'];
}

function documents_quote_snapshot_cover_note_presentation(array $quote): array
{
    $quote['cover_note_presentation_snapshot'] = documents_quote_cover_note_labels((string) ($quote['segment'] ?? ''));
    return $quote;
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
    $normalized = documents_normalize_mobile($mobile);
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

function documents_normalize_mobile(string $input): string
{
    $digits = preg_replace('/\D+/', '', trim($input));
    if (!is_string($digits) || $digits === '') {
        return '';
    }

    if (strlen($digits) === 10) {
        return $digits;
    }

    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        return substr($digits, -10);
    }

    return strlen($digits) > 10 ? substr($digits, -10) : '';
}

function documents_normalize_whatsapp_mobile(string $raw): string
{
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return '';
    }

    $hasLeadingPlus = str_starts_with($trimmed, '+');
    $digitsOnly = preg_replace('/\D+/', '', $trimmed);
    if (!is_string($digitsOnly) || $digitsOnly === '') {
        return '';
    }

    $validIndianCore = static function (string $number): bool {
        return (bool) preg_match('/^[6-9]\d{9}$/', $number);
    };

    if (strlen($digitsOnly) === 10 && $validIndianCore($digitsOnly)) {
        return '91' . $digitsOnly;
    }

    if (strlen($digitsOnly) === 11 && str_starts_with($digitsOnly, '0')) {
        $core = substr($digitsOnly, 1);
        if ($validIndianCore($core)) {
            return '91' . $core;
        }
        return '';
    }

    if (strlen($digitsOnly) === 12 && str_starts_with($digitsOnly, '91')) {
        $core = substr($digitsOnly, 2);
        return $validIndianCore($core) ? $digitsOnly : '';
    }

    if ($hasLeadingPlus && str_starts_with($trimmed, '+91') && strlen($digitsOnly) === 12) {
        $core = substr($digitsOnly, 2);
        return $validIndianCore($core) ? $digitsOnly : '';
    }

    return '';
}

function documents_map_lead_record(array $lead, string $matchedMobile = ''): array
{
    $location = is_array($lead['location'] ?? null) ? $lead['location'] : [];
    $mobile = documents_normalize_mobile((string) ($lead['mobile'] ?? ''));
    if ($mobile === '') {
        $mobile = documents_normalize_mobile((string) ($lead['alt_mobile'] ?? ''));
    }
    if ($matchedMobile !== '') {
        $mobile = $matchedMobile;
    }

    $city = safe_text((string) ($lead['city'] ?? ''));
    if ($city === '') {
        $city = safe_text((string) ($lead['lead_city'] ?? ($location['city'] ?? '')));
    }

    $locality = safe_text((string) ($lead['area_or_locality'] ?? ($location['area_or_locality'] ?? ($location['locality'] ?? ''))));

    return [
        'id' => safe_text((string) ($lead['id'] ?? '')),
        'mobile' => $mobile,
        'name' => safe_text((string) ($lead['name'] ?? '')),
        'city' => $city,
        'district' => safe_text((string) ($lead['district'] ?? ($location['district'] ?? ''))),
        'state' => safe_text((string) ($lead['state'] ?? ($location['state'] ?? ''))),
        'locality' => $locality,
        'address' => $locality,
        'notes' => safe_text((string) ($lead['notes'] ?? '')),
    ];
}

function documents_find_lead_by_mobile(string $mobile): ?array
{
    $normalized = documents_normalize_mobile($mobile);
    if ($normalized === '') {
        return null;
    }

    $best = null;
    $bestTs = PHP_INT_MIN;
    foreach (load_all_leads() as $lead) {
        if (!is_array($lead) || !empty($lead['archived_flag'])) {
            continue;
        }

        $primary = documents_normalize_mobile((string) ($lead['mobile'] ?? ''));
        $alt = documents_normalize_mobile((string) ($lead['alt_mobile'] ?? ''));
        $matchedMobile = '';
        $rank = 0;
        if ($primary !== '' && $primary === $normalized) {
            $matchedMobile = $primary;
            $rank = 2;
        } elseif ($alt !== '' && $alt === $normalized) {
            $matchedMobile = $alt;
            $rank = 1;
        }

        if ($rank === 0) {
            continue;
        }

        $updatedAt = trim((string) ($lead['updated_at'] ?? ''));
        if ($updatedAt === '') {
            $updatedAt = trim((string) ($lead['created_at'] ?? ''));
        }
        $ts = strtotime($updatedAt);
        if ($ts === false) {
            $ts = 0;
        }

        if ($best === null || $rank > $best['rank'] || ($rank === $best['rank'] && $ts > $bestTs)) {
            $best = ['rank' => $rank, 'mapped' => documents_map_lead_record($lead, $matchedMobile)];
            $bestTs = $ts;
        }
    }

    return is_array($best) ? $best['mapped'] : null;
}

function documents_lookup_party_by_mobile(string $mobile): ?array
{
    $normalized = documents_normalize_mobile($mobile);
    if ($normalized === '') {
        return null;
    }

    $customer = documents_find_customer_by_mobile($normalized);
    if ($customer !== null) {
        return [
            'type' => 'customer',
            'record' => $customer,
            'source' => ['type' => 'customer', 'lead_id' => '', 'lead_mobile' => ''],
            'note' => '',
        ];
    }

    $lead = documents_find_lead_by_mobile($normalized);
    if ($lead === null) {
        return null;
    }

    return [
        'type' => 'lead',
        'record' => $lead,
        'source' => ['type' => 'lead', 'lead_id' => (string) ($lead['id'] ?? ''), 'lead_mobile' => $normalized],
        'note' => 'Lead data loaded. Please fill missing address/meter fields if needed.',
    ];
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
    $resolvedQuoteNo = documents_first_non_empty_string([
        $quote['quote_no'] ?? '',
        $row['quotation_number'] ?? '',
        $row['document_number'] ?? '',
    ]);
    if ($resolvedQuoteNo !== '' && safe_text((string) ($quote['quote_no'] ?? '')) === '') {
        $quote['quote_no'] = $resolvedQuoteNo;
        json_save($path, $quote);
    }
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


/**
 * Apply and persist the status rules shared by quotation row actions and Bulk Tools.
 *
 * @return array{ok: bool, error: string, quote: array<string, mixed>}
 */
function documents_quote_apply_admin_status_transition(array $quote, string $targetStatus, array $actor = []): array
{
    $quote = documents_quote_prepare($quote);
    $requestedStatus = strtolower(trim($targetStatus));
    $targetStatus = in_array($requestedStatus, ['archive', 'archived'], true)
        ? 'archived'
        : (in_array($requestedStatus, ['unarchive', 'unarchived'], true) ? 'unarchived' : documents_quote_normalize_status($requestedStatus));
    $currentStatus = documents_quote_normalize_status((string) ($quote['status'] ?? 'draft'));
    $isArchived = documents_is_archived($quote);

    if ($isArchived && $targetStatus !== 'unarchived') {
        return ['ok' => false, 'error' => 'Archived quotations must be unarchived before another status change.', 'quote' => $quote];
    }
    if (!$isArchived && $targetStatus === 'unarchived') {
        return ['ok' => true, 'error' => '', 'quote' => $quote];
    }

    $actorId = safe_text((string) ($actor['id'] ?? ''));
    $actorName = safe_text((string) ($actor['name'] ?? '')) ?: 'Admin';
    $actorType = safe_text((string) ($actor['type'] ?? 'admin')) ?: 'admin';
    $now = date('c');

    if ($targetStatus === 'approved') {
        if (documents_quote_is_locked($quote) || $currentStatus === 'accepted') {
            return ['ok' => false, 'error' => 'Accepted or locked quotations cannot be approved.', 'quote' => $quote];
        }
        if (!in_array($currentStatus, ['draft', 'pending_admin_approval', 'update_requested'], true)) {
            return ['ok' => false, 'error' => 'Only draft, pending, or update-requested quotations can be approved.', 'quote' => $quote];
        }
        $quote['status'] = 'approved';
        $quote = documents_quote_append_customer_visible_history($quote, 'approved', 'Revised quotation approved and ready for review.', ['actor_name' => $actorName]);
        $quote['approval'] = ['approved_by_id' => $actorId, 'approved_by_name' => $actorName, 'approved_at' => $now];
        if (is_array($quote['approved_edit'] ?? null)) {
            $quote['approved_edit']['status'] = 'reapproved';
            $quote['approved_edit']['reapproved_at'] = $now;
            $quote['approved_edit']['reapproved_by_id'] = $actorId;
            $quote['approved_edit']['reapproved_by_name'] = $actorName;
        }
        $quote = documents_quote_ensure_important_points_snapshot($quote);
    } elseif ($targetStatus === 'accepted') {
        if (!in_array($currentStatus, ['approved', 'accepted', 'update_requested'], true)) {
            return ['ok' => false, 'error' => 'Only approved quotations can be accepted.', 'quote' => $quote];
        }
        $acceptedAt = safe_text((string) ($quote['accepted_at'] ?? '')) ?: $now;
        $quote['status'] = 'accepted';
        $quote = documents_quote_ensure_important_points_snapshot($quote);
        $quote = documents_quote_append_customer_visible_history($quote, 'accepted', 'Quotation accepted and locked for processing.', ['actor_name' => $actorName]);
        $quote['accepted_at'] = $acceptedAt;
        $quote['accepted_by'] = ['type' => $actorType, 'id' => $actorId, 'name' => $actorName];
        $quote['acceptance'] = array_merge(
            ['accepted_by_admin_id' => '', 'accepted_by_admin_name' => '', 'accepted_at' => '', 'accepted_note' => ''],
            is_array($quote['acceptance'] ?? null) ? $quote['acceptance'] : [],
            ['accepted_by_admin_id' => $actorId, 'accepted_by_admin_name' => $actorName, 'accepted_at' => $acceptedAt]
        );
        $quote['locked_flag'] = true;
        $quote['locked_at'] = safe_text((string) ($quote['locked_at'] ?? '')) ?: $now;
        $quote['is_current_version'] = true;
        $syncResult = documents_sync_after_quote_accepted($quote);
        $quote = is_array($syncResult['quote'] ?? null) ? $syncResult['quote'] : $quote;
        documents_quote_set_current_for_series($quote);
    } elseif ($targetStatus === 'archived') {
        $quote = documents_set_archived($quote, ['type' => $actorType, 'id' => $actorId, 'name' => $actorName]);
    } elseif ($targetStatus === 'unarchived') {
        $restoredStatus = documents_quote_restore_status_after_unarchive($quote);
        $quote = documents_set_unarchived($quote);
        $quote['status'] = $restoredStatus;
    } else {
        return ['ok' => false, 'error' => 'Unsupported quotation status transition.', 'quote' => $quote];
    }

    $quote['updated_at'] = $now;
    $saved = documents_save_quote($quote);
    if (!($saved['ok'] ?? false)) {
        return ['ok' => false, 'error' => (string) ($saved['error'] ?? 'Unable to update quotation.'), 'quote' => $quote];
    }
    return ['ok' => true, 'error' => '', 'quote' => $quote];
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
    $resolvedProformaNo = documents_first_non_empty_string([
        $doc['proforma_no'] ?? '',
        $row['proforma_number'] ?? '',
        $row['document_number'] ?? '',
    ]);
    if ($resolvedProformaNo !== '' && safe_text((string) ($doc['proforma_no'] ?? '')) === '') {
        $doc['proforma_no'] = $resolvedProformaNo;
        json_save($path, $doc);
    }
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


const DOCUMENTS_INVOICE_ADJUSTMENT_NONE = 'none';
const DOCUMENTS_INVOICE_ADJUSTMENT_DISCOUNT = 'discount';
const DOCUMENTS_INVOICE_ADJUSTMENT_SURCHARGE = 'surcharge';
const DOCUMENTS_INVOICE_MONEY_TOLERANCE = 0.01;

function documents_invoice_money_to_paise(float $amount): int
{
    return (int) round($amount * 100);
}

function documents_invoice_paise_to_money(int $paise): float
{
    return round($paise / 100, 2);
}

function documents_invoice_parse_money($value): array
{
    $text = trim((string) $value);
    if ($text === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $text)) {
        return ['ok' => false, 'value' => 0.0, 'error' => 'Final invoice total must be a valid non-negative amount with up to two decimals.'];
    }
    $float = (float) $text;
    if (!is_finite($float) || $float < 0) {
        return ['ok' => false, 'value' => 0.0, 'error' => 'Final invoice total must be finite and non-negative.'];
    }
    return ['ok' => true, 'value' => documents_invoice_paise_to_money(documents_invoice_money_to_paise($float)), 'error' => ''];
}

function documents_invoice_quotation_reference_total(array $invoice): float
{
    if (isset($invoice['pricing']['quotation_total_incl_gst']) && is_numeric($invoice['pricing']['quotation_total_incl_gst'])) {
        return documents_invoice_paise_to_money(documents_invoice_money_to_paise((float) $invoice['pricing']['quotation_total_incl_gst']));
    }
    $snap = is_array($invoice['quotation_snapshot'] ?? null) ? $invoice['quotation_snapshot'] : [];
    foreach ([$snap['input_total_gst_inclusive'] ?? null, $snap['calc']['gross_payable'] ?? null, $snap['calc']['grand_total'] ?? null, $snap['tax_breakdown']['gross_incl_gst'] ?? null] as $value) {
        if (is_numeric($value)) { return documents_invoice_paise_to_money(documents_invoice_money_to_paise((float) $value)); }
    }
    return documents_invoice_final_total($invoice, false);
}

function documents_invoice_final_total(array $invoice, bool $allowQuotationFallback = true): float
{
    $sources = [
        $invoice['pricing']['final_invoice_total_incl_gst'] ?? null,
        $invoice['calc']['gross_payable'] ?? null,
        $invoice['calc']['grand_total'] ?? null,
        $invoice['input_total_gst_inclusive'] ?? null,
        $invoice['amount'] ?? null,
        $invoice['total'] ?? null,
    ];
    if ($allowQuotationFallback) {
        $snap = is_array($invoice['quotation_snapshot'] ?? null) ? $invoice['quotation_snapshot'] : [];
        $sources[] = $snap['input_total_gst_inclusive'] ?? null;
        $sources[] = $snap['calc']['gross_payable'] ?? null;
        $sources[] = $snap['calc']['grand_total'] ?? null;
    }
    foreach ($sources as $value) {
        if (is_numeric($value)) { return documents_invoice_paise_to_money(documents_invoice_money_to_paise((float) $value)); }
    }
    return 0.0;
}

function documents_invoice_adjustment_type(array $invoice): string
{
    $type = (string) ($invoice['pricing']['adjustment_type'] ?? DOCUMENTS_INVOICE_ADJUSTMENT_NONE);
    return in_array($type, [DOCUMENTS_INVOICE_ADJUSTMENT_NONE, DOCUMENTS_INVOICE_ADJUSTMENT_DISCOUNT, DOCUMENTS_INVOICE_ADJUSTMENT_SURCHARGE], true) ? $type : DOCUMENTS_INVOICE_ADJUSTMENT_NONE;
}

function documents_invoice_adjustment_amount(array $invoice): float
{
    return documents_invoice_paise_to_money(documents_invoice_money_to_paise((float) ($invoice['pricing']['adjustment_amount_incl_gst'] ?? 0)));
}

function documents_invoice_has_price_adjustment(array $invoice): bool
{
    return documents_invoice_adjustment_type($invoice) !== DOCUMENTS_INVOICE_ADJUSTMENT_NONE && documents_invoice_adjustment_amount($invoice) > DOCUMENTS_INVOICE_MONEY_TOLERANCE;
}

function documents_invoice_amount_due(array $invoice, array $receipts = []): float
{
    if ($receipts === []) { return documents_invoice_final_total($invoice); }
    $paid = 0.0;
    foreach ($receipts as $receipt) {
        if (is_array($receipt) && documents_receipt_is_finalized_active($receipt)) { $paid += documents_receipt_amount_total($receipt); }
    }
    return documents_invoice_paise_to_money(documents_invoice_money_to_paise(documents_invoice_final_total($invoice) - $paid));
}

function documents_invoice_source_tax_items(array $invoice): array
{
    $tax = is_array($invoice['tax_breakdown'] ?? null) ? $invoice['tax_breakdown'] : [];
    if (!empty($tax['items']) && is_array($tax['items'])) { return array_values(array_filter($tax['items'], 'is_array')); }
    $snap = is_array($invoice['quotation_snapshot'] ?? null) ? $invoice['quotation_snapshot'] : [];
    $snapTax = is_array($snap['tax_breakdown'] ?? null) ? $snap['tax_breakdown'] : [];
    if (!empty($snapTax['items']) && is_array($snapTax['items'])) { return array_values(array_filter($snapTax['items'], 'is_array')); }
    return [[
        'name' => 'Invoice value', 'hsn' => '', 'taxable_value' => (float) (($snapTax['basic_total'] ?? 0) ?: 0),
        'gst_amount' => (float) (($snapTax['gst_total'] ?? 0) ?: 0), 'gross_incl_gst' => documents_invoice_quotation_reference_total($invoice),
        'slabs' => $snapTax['slabs'] ?? [['share_pct' => 100, 'rate_pct' => 18]],
    ]];
}

function documents_invoice_recalculate_pricing(array $invoice, float $requestedFinalTotal, string $adjustmentReason): array
{
    $finalPaise = max(0, documents_invoice_money_to_paise($requestedFinalTotal));
    $final = documents_invoice_paise_to_money($finalPaise);
    $quote = documents_invoice_quotation_reference_total($invoice);
    $quotePaise = documents_invoice_money_to_paise($quote);
    $diffPaise = $finalPaise - $quotePaise;
    $type = abs($diffPaise) <= 1 ? DOCUMENTS_INVOICE_ADJUSTMENT_NONE : ($diffPaise < 0 ? DOCUMENTS_INVOICE_ADJUSTMENT_DISCOUNT : DOCUMENTS_INVOICE_ADJUSTMENT_SURCHARGE);
    $adjustPaise = abs($diffPaise) <= 1 ? 0 : abs($diffPaise);

    $sourceItems = documents_invoice_source_tax_items($invoice);
    $weights = [];
    $totalWeight = 0;
    foreach ($sourceItems as $i => $item) {
        $w = max(0, documents_invoice_money_to_paise((float) ($item['gross_incl_gst'] ?? 0)));
        $weights[$i] = $w; $totalWeight += $w;
    }
    if ($totalWeight <= 0 && $sourceItems !== []) { $weights = array_fill(0, count($sourceItems), 1); $totalWeight = count($sourceItems); }
    $alloc = array_fill(0, count($sourceItems), 0); $used = 0;
    foreach ($sourceItems as $i => $_) { $alloc[$i] = intdiv($finalPaise * $weights[$i], $totalWeight ?: 1); $used += $alloc[$i]; }
    $remainder = $finalPaise - $used;
    arsort($weights, SORT_NUMERIC);
    foreach (array_keys($weights) as $i) { if ($remainder === 0) break; $alloc[$i] += $remainder > 0 ? 1 : -1; $remainder += $remainder > 0 ? -1 : 1; }

    $items = []; $basicPaise = 0; $gstPaise = 0;
    foreach ($sourceItems as $i => $item) {
        $grossPaise = max(0, $alloc[$i]);
        $slabs = is_array($item['slabs'] ?? null) ? $item['slabs'] : [['share_pct' => 100, 'rate_pct' => 0]];
        $factor = 0.0;
        foreach ($slabs as $slab) { if (is_array($slab)) { $factor += ((float)($slab['share_pct'] ?? 100) / 100) * (1 + ((float)($slab['rate_pct'] ?? 0) / 100)); } }
        if ($factor <= 0) { $factor = 1; }
        $taxablePaise = (int) round($grossPaise / $factor);
        $gstItemPaise = $grossPaise - $taxablePaise;
        $basicPaise += $taxablePaise; $gstPaise += $gstItemPaise;
        $items[] = array_merge($item, [
            'taxable_value' => documents_invoice_paise_to_money($taxablePaise),
            'gst_amount' => documents_invoice_paise_to_money($gstItemPaise),
            'gross_incl_gst' => documents_invoice_paise_to_money($grossPaise),
        ]);
    }
    $taxBreakdown = ['basic_total' => documents_invoice_paise_to_money($basicPaise), 'gst_total' => documents_invoice_paise_to_money($gstPaise), 'gross_incl_gst' => $final, 'items' => $items, 'rounding_rule' => 'Amounts are allocated in paise proportionally by original gross line value; any remainder is applied to the largest line with stable index tie-break.'];
    $invoice['pricing'] = ['quotation_total_incl_gst' => $quote, 'final_invoice_total_incl_gst' => $final, 'adjustment_type' => $type, 'adjustment_amount_incl_gst' => documents_invoice_paise_to_money($adjustPaise), 'adjustment_percent' => $quotePaise > 0 ? round(($adjustPaise / $quotePaise) * 100, 4) : 0.0, 'adjustment_reason' => safe_multiline_text($adjustmentReason), 'currency' => 'INR'];
    $invoice['input_total_gst_inclusive'] = $final;
    $invoice['calc'] = array_merge(is_array($invoice['calc'] ?? null) ? $invoice['calc'] : [], ['gross_payable' => $final, 'grand_total' => $final, 'final_price_incl_gst' => $final, 'tax_breakdown' => $taxBreakdown]);
    $invoice['tax_breakdown'] = $taxBreakdown;
    $invoice['amount_due'] = documents_invoice_amount_due($invoice);
    return ['ok' => true, 'invoice' => $invoice, 'pricing' => $invoice['pricing'], 'calc' => $invoice['calc'], 'tax_breakdown' => $taxBreakdown, 'reconciliation' => ['final_total' => $final, 'item_gross_sum' => $final]];
}


function documents_invoice_date_validate(string $date, bool $allowFuture = false): array
{
    $date = trim($date);
    if ($date === '') { return ['ok' => false, 'error' => 'Set a valid invoice date before finalizing this invoice.']; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return ['ok' => false, 'error' => 'Invoice date must use YYYY-MM-DD format.']; }
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    if (!checkdate($m, $d, $y)) { return ['ok' => false, 'error' => 'Invoice date must be a real calendar date.']; }
    if (!$allowFuture && $date > date('Y-m-d')) { return ['ok' => false, 'error' => 'Invoice date cannot be in the future.']; }
    return ['ok' => true, 'date' => $date, 'error' => ''];
}

function documents_invoice_date_is_valid(string $date): bool
{
    return !empty(documents_invoice_date_validate($date, true)['ok']);
}

function documents_invoice_authoritative_date(array $invoice): string
{
    $ownDate = (string)($invoice['invoice_date'] ?? '');
    if (documents_invoice_is_draft($invoice) && $ownDate !== '' && documents_invoice_date_is_valid($ownDate)) { return $ownDate; }
    $snapDate = (string)($invoice['finalized_snapshot']['invoice_date'] ?? '');
    if ($snapDate !== '' && documents_invoice_date_is_valid($snapDate)) { return $snapDate; }
    if ($ownDate !== '' && documents_invoice_date_is_valid($ownDate)) { return $ownDate; }
    $created = substr((string)($invoice['created_at'] ?? ''), 0, 10);
    return documents_invoice_date_is_valid($created) ? $created : date('Y-m-d');
}

function documents_invoice_normalize_date(array $invoice): array
{
    $date = (string)($invoice['invoice_date'] ?? '');
    if ($date !== '' && documents_invoice_date_is_valid($date)) {
        $invoice['invoice_date'] = $date;
        $invoice['invoice_date_source'] = (string)($invoice['invoice_date_source'] ?? 'explicit') ?: 'explicit';
        return $invoice;
    }
    foreach (['document_date', 'date', 'created_at'] as $key) {
        $candidate = substr((string)($invoice[$key] ?? ''), 0, 10);
        if ($candidate !== '' && documents_invoice_date_is_valid($candidate)) {
            $invoice['invoice_date'] = $candidate;
            $invoice['invoice_date_source'] = $key === 'created_at' ? 'legacy_created_at_fallback' : 'legacy_' . $key;
            return $invoice;
        }
    }
    $invoice['invoice_date'] = date('Y-m-d');
    $invoice['invoice_date_source'] = 'legacy_current_date_fallback';
    return $invoice;
}

function documents_invoice_set_date(array $invoice, string $date, array $actor = []): array
{
    $valid = documents_invoice_date_validate($date, false);
    if (empty($valid['ok'])) { return ['ok' => false, 'error' => $valid['error'], 'invoice' => $invoice]; }
    $previous = documents_invoice_authoritative_date($invoice);
    $invoice['invoice_date'] = $valid['date'];
    $invoice['invoice_date_source'] = 'explicit';
    if ($previous !== $valid['date']) {
        $events = is_array($invoice['audit_events'] ?? null) ? $invoice['audit_events'] : [];
        $events[] = ['invoice_id'=>(string)($invoice['id']??''),'revision_no'=>(int)($invoice['revision_no']??0),'event_type'=>'invoice_date_changed','previous_invoice_date'=>$previous,'new_invoice_date'=>$valid['date'],'actor_id'=>(string)($actor['id']??''),'actor_name'=>(string)($actor['name']??$actor['full_name']??'Admin'),'timestamp'=>date('c')];
        $invoice['audit_events'] = $events;
    }
    return ['ok' => true, 'invoice' => $invoice, 'date' => $valid['date']];
}

function documents_invoice_normalize_commercial_snapshot(array $invoice): array
{
    $invoice = documents_invoice_normalize_date($invoice);
    if (is_array($invoice['pricing'] ?? null) && isset($invoice['pricing']['final_invoice_total_incl_gst'])) { return $invoice; }
    $requested = documents_invoice_final_total($invoice, false);
    if ($requested <= 0) { $requested = documents_invoice_quotation_reference_total($invoice); }
    $result = documents_invoice_recalculate_pricing($invoice, $requested, (string) ($invoice['pricing']['adjustment_reason'] ?? ''));
    return (array) ($result['invoice'] ?? $invoice);
}

function documents_invoice_record_pricing_history(array $invoice, float $previousTotal, array $actor, string $reason): array
{
    $newTotal = documents_invoice_final_total($invoice);
    if (abs($newTotal - $previousTotal) <= DOCUMENTS_INVOICE_MONEY_TOLERANCE) { return $invoice; }
    $entry = ['event' => 'invoice_pricing_changed', 'invoice_id' => (string)($invoice['id'] ?? ''), 'previous_final_total' => round($previousTotal,2), 'new_final_total' => round($newTotal,2), 'quotation_reference_total' => documents_invoice_quotation_reference_total($invoice), 'adjustment_type' => documents_invoice_adjustment_type($invoice), 'adjustment_amount' => documents_invoice_adjustment_amount($invoice), 'adjustment_percent' => (float)($invoice['pricing']['adjustment_percent'] ?? 0), 'reason' => safe_multiline_text($reason), 'actor_id' => (string)($actor['id'] ?? ''), 'actor_name' => (string)($actor['name'] ?? $actor['full_name'] ?? 'Admin'), 'timestamp' => date('c')];
    $history = is_array($invoice['pricing_history'] ?? null) ? $invoice['pricing_history'] : [];
    $history[] = $entry; $invoice['pricing_history'] = $history;
    return $invoice;
}



function documents_invoice_normalize_status(string $status): string
{
    $s = strtolower(trim($status));
    $s = str_replace([' ', '-'], '_', $s);
    if (in_array($s, ['final', 'issued', 'active', 'completed', 'finalized'], true)) { return 'finalized'; }
    if (in_array($s, ['cancelled', 'canceled'], true)) { return 'cancelled'; }
    if (in_array($s, ['superseded', 'revised'], true)) { return 'superseded'; }
    return 'draft';
}

function documents_invoice_status_label(string $status): string
{
    return ['draft'=>'Draft','finalized'=>'Finalized','superseded'=>'Superseded','cancelled'=>'Cancelled'][documents_invoice_normalize_status($status)] ?? 'Draft';
}

function documents_invoice_is_draft(array $invoice): bool { return documents_invoice_normalize_status((string)($invoice['status'] ?? 'draft')) === 'draft'; }
function documents_invoice_is_finalized(array $invoice): bool { return documents_invoice_normalize_status((string)($invoice['status'] ?? 'draft')) === 'finalized'; }
function documents_invoice_is_cancelled(array $invoice): bool { return documents_invoice_normalize_status((string)($invoice['status'] ?? 'draft')) === 'cancelled'; }

function documents_invoice_payment_status_label(string $status): string
{
    return ['not_applicable'=>'Not applicable','unpaid'=>'Unpaid','partially_paid'=>'Partially paid','paid'=>'Paid','overpaid'=>'Overpaid'][$status] ?? 'Unpaid';
}

function documents_receipt_is_finalized_active(array $receipt): bool
{
    $status = strtolower(trim((string)($receipt['status'] ?? '')));
    return in_array($status, ['final','finalized','posted','paid'], true)
        && !in_array($status, ['draft','cancelled','canceled','reversed','voided','archived'], true)
        && empty($receipt['archived_flag']) && empty($receipt['archived']) && empty($receipt['voided']) && empty($receipt['reversed']);
}

function documents_receipt_amount_total(array $receipt): float
{
    foreach (['amount_rs','amount_received','amount','total_received'] as $k) { if (is_numeric($receipt[$k] ?? null)) return documents_invoice_paise_to_money(documents_invoice_money_to_paise((float)$receipt[$k])); }
    return 0.0;
}

function documents_all_invoices(): array
{
    documents_ensure_structure(); $out=[];
    foreach (glob(documents_invoices_dir().'/*.json') ?: [] as $file) { $row=json_load((string)$file, []); if (is_array($row)) { $out[]=documents_invoice_normalize_commercial_snapshot(array_merge(documents_invoice_defaults(), $row)); } }
    return $out;
}


function documents_invoice_is_active_for_quote(array $invoice): bool
{
    $status = documents_invoice_normalize_status((string)($invoice['status'] ?? 'draft'));
    return in_array($status, ['draft', 'finalized'], true) && !documents_is_archived($invoice) && empty($invoice['superseded_by_invoice_id']);
}

function documents_invoices_for_quote(string $quoteId, bool $includeCancelled = true): array
{
    $quoteId = safe_text($quoteId); if ($quoteId === '') return [];
    $out = [];
    foreach (documents_all_invoices() as $inv) {
        if ((string)($inv['linked_quote_id'] ?? $inv['quotation_id'] ?? '') !== $quoteId) continue;
        if (!$includeCancelled && documents_invoice_is_cancelled($inv)) continue;
        $out[] = $inv;
    }
    usort($out, static fn(array $a, array $b): int => strcmp((string)($a['created_at'] ?? '').(string)($a['id'] ?? ''), (string)($b['created_at'] ?? '').(string)($b['id'] ?? '')));
    return $out;
}

function documents_active_invoices_for_quote(string $quoteId): array
{
    return array_values(array_filter(documents_invoices_for_quote($quoteId, true), 'documents_invoice_is_active_for_quote'));
}

function documents_quote_invoice_totals_summary(array $quote, ?float $proposedTotal = null): array
{
    $quoteTotal = documents_invoice_quotation_reference_total(['quotation_snapshot' => documents_invoice_quote_snapshot($quote), 'calc' => $quote['calc'] ?? [], 'input_total_gst_inclusive' => $quote['input_total_gst_inclusive'] ?? 0]);
    $active = documents_active_invoices_for_quote((string)($quote['id'] ?? ''));
    $cancelled = array_values(array_filter(documents_invoices_for_quote((string)($quote['id'] ?? ''), true), 'documents_invoice_is_cancelled'));
    $activeTotal = 0.0; foreach ($active as $inv) $activeTotal += documents_invoice_final_total($inv);
    $proposed = $proposedTotal ?? $quoteTotal;
    return ['quotation_total'=>$quoteTotal,'active_invoice_total'=>$activeTotal,'proposed_invoice_total'=>$proposed,'remaining_uninvoiced'=>round($quoteTotal-$activeTotal,2),'would_exceed'=>($activeTotal+$proposed) > ($quoteTotal + DOCUMENTS_INVOICE_MONEY_TOLERANCE),'active_invoices'=>$active,'cancelled_invoices'=>$cancelled];
}

function documents_quote_can_create_invoice(array $quote): array
{
    if (!documents_dispatch_quote_eligible($quote)) return ['ok'=>false,'errors'=>['Invoice requires an accepted current quotation.'],'summary'=>documents_quote_invoice_totals_summary($quote)];
    return ['ok'=>true,'errors'=>[],'summary'=>documents_quote_invoice_totals_summary($quote)];
}

function documents_quote_repair_invoice_workflow(array $quote): array
{
    $quote = documents_quote_prepare($quote); $qid=(string)($quote['id']??''); $ids=[];
    foreach ([(string)($quote['workflow']['invoice_id']??''), (string)($quote['links']['invoice_id']??'')] as $id) if($id!=='') $ids[]=$id;
    foreach ((array)($quote['workflow']['invoice_ids']??[]) as $id) if((string)$id!=='') $ids[]=(string)$id;
    foreach (documents_invoices_for_quote($qid, true) as $inv) $ids[]=(string)($inv['id']??'');
    $ids=array_values(array_unique(array_filter($ids, static fn($id)=>documents_get_invoice((string)$id)!==null)));
    $active=[]; foreach($ids as $id){ $inv=documents_get_invoice((string)$id); if($inv && documents_invoice_is_active_for_quote($inv)) $active[]=$id; }
    $quote['workflow']['invoice_ids']=$ids; $quote['workflow']['active_invoice_ids']=array_values(array_unique($active));
    $latest=end($active); if(!$latest) $latest=end($ids) ?: '';
    $quote['workflow']['latest_invoice_id']=(string)$latest; $quote['workflow']['invoice_id']=(string)$latest;
    $quote['links']['invoice_id']=(string)$latest;
    return $quote;
}

function documents_receipt_allocations_normalize(array $receipt, array $invoices = null): array
{
    $invoices = $invoices ?? documents_all_invoices(); $byId=[]; foreach($invoices as $inv){ $byId[(string)($inv['id']??'')]=$inv; }
    $receiptTotal = documents_invoice_money_to_paise(documents_receipt_amount_total($receipt)); $allocs=[]; $seen=[]; $errors=[];
    $raw = is_array($receipt['allocations'] ?? null) ? $receipt['allocations'] : [];
    if ($raw === [] && (string)($receipt['invoice_id'] ?? '') !== '') { $raw[]=['invoice_id'=>(string)$receipt['invoice_id'],'amount_rs'=>documents_receipt_amount_total($receipt)]; }
    if ($raw === [] && (string)($receipt['quotation_id'] ?? '') !== '') {
        $qid=(string)$receipt['quotation_id']; $eligible=array_values(array_filter($invoices, static fn($i):bool => (string)($i['linked_quote_id']??$i['quotation_id']??'')===$qid && in_array(documents_invoice_normalize_status((string)($i['status']??'draft')), ['draft','finalized'], true) && !documents_is_archived($i)));
        if (count($eligible) === 1) { $raw[]=['invoice_id'=>(string)$eligible[0]['id'], 'amount_rs'=>documents_receipt_amount_total($receipt), 'migrated_from'=>'quotation']; }
    }
    $sum=0;
    foreach($raw as $a){ if(!is_array($a)) continue; $iid=(string)($a['invoice_id']??''); $paise=documents_invoice_money_to_paise((float)($a['amount_rs']??$a['amount']??0)); if($iid===''||!isset($byId[$iid])){$errors[]='invalid_invoice_id'; continue;} if($paise<0){$errors[]='negative_allocation'; continue;} $key=$iid.':'.$paise; if(isset($seen[$key])) continue; $seen[$key]=true; $inv=$byId[$iid]; $rc=(string)($receipt['customer_mobile']??''); $ic=(string)($inv['customer_mobile']??$inv['customer_snapshot']['mobile']??''); if($rc!==''&&$ic!==''&&normalize_customer_mobile($rc)!==normalize_customer_mobile($ic) && empty($a['authorized_override'])){$errors[]='cross_customer_allocation'; continue;} $sum+=$paise; $allocs[]=['invoice_id'=>$iid,'amount_rs'=>documents_invoice_paise_to_money($paise)]; }
    if($sum>$receiptTotal){ $errors[]='allocation_exceeds_receipt'; return ['ok'=>false,'allocations'=>[],'errors'=>array_values(array_unique($errors))]; }
    return ['ok'=>$errors===[], 'allocations'=>$allocs, 'errors'=>array_values(array_unique($errors))];
}

function documents_receipt_allocation_for_invoice(array $receipt, string $invoiceId, array $invoices = null): float
{
    if (!documents_receipt_is_finalized_active($receipt)) return 0.0;
    $norm=documents_receipt_allocations_normalize($receipt,$invoices); if(empty($norm['ok'])) return 0.0; $sum=0;
    foreach($norm['allocations'] as $a){ if((string)$a['invoice_id']===$invoiceId) $sum += documents_invoice_money_to_paise((float)$a['amount_rs']); }
    return documents_invoice_paise_to_money($sum);
}

function documents_invoice_payment_summary(array $invoice): array
{
    $invoice=documents_invoice_normalize_commercial_snapshot($invoice); $iid=(string)($invoice['id']??''); $invoices=documents_all_invoices(); if($iid!=='' && !isset(array_column($invoices,null,'id')[$iid])) $invoices[]=$invoice;
    $totalPaise=documents_invoice_money_to_paise(documents_invoice_final_total($invoice)); $received=0; $receipts=[]; $unallocated=[]; $last=''; $seen=[]; $qid=(string)($invoice['linked_quote_id']??$invoice['quotation_id']??'');
    foreach(documents_list_sales_documents('receipt') as $r){ if(!is_array($r)||!documents_receipt_is_finalized_active($r)) continue; $rid=(string)($r['id']??$r['receipt_id']??$r['receipt_number']??''); if(isset($seen[$rid])) continue; $seen[$rid]=true; $norm=documents_receipt_allocations_normalize($r,$invoices); $amt=0; if(!empty($norm['ok'])) foreach($norm['allocations'] as $a){ if((string)$a['invoice_id']===$iid) $amt += documents_invoice_money_to_paise((float)$a['amount_rs']); }
        $date=(string)($r['date_received']??$r['receipt_date']??$r['created_at']??''); if($amt>0){$received+=$amt; if(substr($date,0,10)>$last)$last=substr($date,0,10); $receipts[]=['id'=>$rid,'receipt_number'=>(string)($r['receipt_number']??$rid),'date'=>$date,'amount_rs'=>documents_invoice_paise_to_money($amt)];}
        elseif($qid!=='' && (string)($r['quotation_id']??'')===$qid && (is_array($r['allocations']??null)?$r['allocations']:[])===[]) $unallocated[]=['id'=>$rid,'receipt_number'=>(string)($r['receipt_number']??$rid),'date'=>$date,'amount_rs'=>documents_receipt_amount_total($r)]; }
    usort($receipts, static fn($a,$b)=>strcmp(($a['date']??'').($a['id']??''),($b['date']??'').($b['id']??''))); usort($unallocated, static fn($a,$b)=>strcmp(($a['date']??'').($a['id']??''),($b['date']??'').($b['id']??'')));
    $diff=$totalPaise-$received; $tol=1; $status=$totalPaise<=0?'not_applicable':($received===0?'unpaid':(abs($diff)<=$tol?'paid':($diff>0?'partially_paid':'overpaid')));
    return ['invoice_id'=>$iid,'invoice_total'=>documents_invoice_paise_to_money($totalPaise),'total_received'=>documents_invoice_paise_to_money($received),'outstanding'=>documents_invoice_paise_to_money(max(0,$diff)),'overpayment'=>documents_invoice_paise_to_money(max(0,-$diff)),'payment_status'=>$status,'receipt_count'=>count($receipts),'receipts'=>$receipts,'unallocated_receipts'=>$unallocated,'last_payment_at'=>$last];
}


function documents_project_quotation_amount(array $quote): float
{
    foreach ([$quote['calc']['gross_payable'] ?? null, $quote['calc']['final_price_incl_gst'] ?? null, $quote['calc']['grand_total'] ?? null, $quote['input_total_gst_inclusive'] ?? null] as $value) {
        if (is_numeric($value)) { return documents_invoice_paise_to_money(documents_invoice_money_to_paise((float) $value)); }
    }
    return 0.0;
}

function documents_project_active_finalized_invoices(array $quote): array
{
    $seen = []; $latest = [];
    foreach (documents_invoices_for_quote((string)($quote['id'] ?? ''), true) as $invoice) {
        $id = (string)($invoice['id'] ?? '');
        if ($id === '' || isset($seen[$id]) || !documents_invoice_is_finalized($invoice) || documents_is_archived($invoice) || documents_invoice_is_cancelled($invoice) || !empty($invoice['superseded_by_invoice_id']) || !empty($invoice['replaced_by_invoice_id'])) { continue; }
        $seen[$id] = true; $latest[$id] = $invoice;
    }
    return array_values($latest);
}

function documents_project_invoice_set_snapshot(array $quote): array
{
    $rows = []; $total = 0;
    foreach (documents_project_active_finalized_invoices($quote) as $invoice) {
        $amountPaise = documents_invoice_money_to_paise(documents_invoice_final_total($invoice));
        $row = ['id'=>(string)($invoice['id'] ?? ''), 'revision_no'=>(int)($invoice['revision_no'] ?? 1), 'amount_paise'=>$amountPaise];
        $rows[] = $row; $total += $amountPaise;
    }
    usort($rows, static fn(array $a, array $b): int => strcmp($a['id'].'#'.$a['revision_no'], $b['id'].'#'.$b['revision_no']));
    return ['rows'=>$rows, 'ids'=>array_column($rows, 'id'), 'revisions'=>array_column($rows, 'revision_no'), 'total_paise'=>$total, 'total'=>documents_invoice_paise_to_money($total), 'hash'=>hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES) ?: '')];
}

function documents_project_financial_summary(array $quote, array $receipts = null): array
{
    $quoteAmount = documents_project_quotation_amount($quote); $quotePaise = documents_invoice_money_to_paise($quoteAmount);
    $snapshot = documents_project_invoice_set_snapshot($quote); $invoicePaise = (int)$snapshot['total_paise'];
    $paidPaise = 0; $allocatedPaise = 0; $receiptIds = [];
    foreach (($receipts ?? documents_final_receipts_for_quote((string)($quote['id'] ?? ''))) as $receipt) {
        if (!is_array($receipt) || !documents_receipt_is_finalized_active($receipt)) { continue; }
        $rid = (string)($receipt['id'] ?? $receipt['receipt_id'] ?? md5(json_encode($receipt)));
        if (isset($receiptIds[$rid])) { continue; }
        $receiptIds[$rid] = true; $amountPaise = documents_invoice_money_to_paise(documents_receipt_amount_total($receipt)); $paidPaise += $amountPaise;
        foreach ((array)($receipt['allocations'] ?? []) as $allocation) { if (is_array($allocation)) { $allocatedPaise += min($amountPaise, documents_invoice_money_to_paise((float)($allocation['amount_rs'] ?? $allocation['amount'] ?? 0))); } }
    }
    $settlement = is_array($quote['commercial_settlement'] ?? null) ? $quote['commercial_settlement'] : [];
    $basis = in_array((string)($settlement['basis'] ?? 'quotation'), ['quotation','finalized_invoices'], true) ? (string)($settlement['basis'] ?? 'quotation') : 'quotation';
    $status = (string)($settlement['status'] ?? 'not_confirmed');
    if ($basis === 'finalized_invoices' && $status === 'confirmed' && (string)($settlement['invoice_set_hash'] ?? '') !== (string)$snapshot['hash']) { $status = 'needs_reconfirmation'; }
    if (!in_array($status, ['not_confirmed','confirmed','needs_reconfirmation'], true)) { $status = 'not_confirmed'; }
    $refPaise = $basis === 'finalized_invoices' && in_array($status, ['confirmed','needs_reconfirmation'], true) ? documents_invoice_money_to_paise((float)($settlement['confirmed_reference_amount'] ?? 0)) : $quotePaise;
    $diff = $refPaise - $paidPaise;
    return ['quotation_amount'=>documents_invoice_paise_to_money($quotePaise),'active_finalized_invoice_total'=>documents_invoice_paise_to_money($invoicePaise),'active_finalized_invoice_ids'=>$snapshot['ids'],'active_finalized_invoice_revisions'=>$snapshot['revisions'],'active_finalized_invoice_set_hash'=>$snapshot['hash'],'total_payment_received'=>documents_invoice_paise_to_money($paidPaise),'allocated_payment_received'=>documents_invoice_paise_to_money($allocatedPaise),'unallocated_payment_received'=>documents_invoice_paise_to_money(max(0,$paidPaise-$allocatedPaise)),'remaining_by_quotation'=>documents_invoice_paise_to_money(max(0,$quotePaise-$paidPaise)),'remaining_by_finalized_invoices'=>documents_invoice_paise_to_money(max(0,$invoicePaise-$paidPaise)),'calculation_basis'=>$basis,'calculation_reference_amount'=>documents_invoice_paise_to_money($refPaise),'remaining_amount'=>documents_invoice_paise_to_money(max(0,$diff)),'overpayment'=>documents_invoice_paise_to_money(max(0,-$diff)),'basis_status'=>$status,'settlement'=>$settlement];
}


function documents_project_financial_due_date(array $quote): string
{
    foreach (['accepted_at','approved_at','updated_at','created_at'] as $key) {
        $value = substr((string)($quote[$key] ?? ''), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) { return $value; }
    }
    return '';
}

function documents_project_financial_presentation(array $quote, array $receipts = null): array
{
    $summary = documents_project_financial_summary($quote, $receipts);
    $reference = (float)$summary['calculation_reference_amount'];
    $received = (float)$summary['total_payment_received'];
    $outstanding = (float)$summary['remaining_amount'];
    $overpayment = (float)$summary['overpayment'];
    $basis = (string)$summary['calculation_basis'];
    $status = (string)$summary['basis_status'];
    $quotation = (float)$summary['quotation_amount'];
    $invoiceTotal = (float)$summary['active_finalized_invoice_total'];
    $adjustment = round($quotation - $invoiceTotal, 2);
    $hasReceivable = $outstanding > 0.009;
    $collectionPct = $reference > 0 ? round(min($received, $reference) / $reference * 100, 1) : null;
    return array_merge($summary, [
        'project_amount' => $reference,
        'received_amount' => $received,
        'outstanding_amount' => $outstanding,
        'customer_credit' => $overpayment,
        'collection_pct' => $collectionPct,
        'basis_label' => ucwords(str_replace('_', ' ', $basis)),
        'basis_status_label' => ucwords(str_replace('_', ' ', $status)),
        'has_receivable' => $hasReceivable,
        'due_since' => $hasReceivable ? documents_project_financial_due_date($quote) : '',
        'quotation_to_invoice_difference' => abs($adjustment),
        'quotation_to_invoice_difference_signed' => $adjustment,
        'quotation_to_invoice_difference_label' => $adjustment >= 0 ? 'Quotation-to-invoice reduction' : 'Invoice increase over quotation',
        'quotation_to_invoice_difference_note' => ($basis === 'finalized_invoices' && in_array($status, ['confirmed','needs_reconfirmation'], true)) ? 'Excluded from payment calculations because finalized invoices are the confirmed project basis.' : 'Shown for comparison only; payment calculations follow the selected project basis.',
        'needs_reconfirmation_warning' => ($basis === 'finalized_invoices' && $status === 'needs_reconfirmation') ? 'Finalized invoice set changed after confirmation. Values below preserve the prior confirmed snapshot until an administrator reconfirms.' : '',
    ]);
}

function documents_project_reference_amount(array $quote): float { return (float) documents_project_financial_summary($quote)['calculation_reference_amount']; }

/** Explicit project lifecycle state. Payment settlement never changes this value. */
function documents_project_completion_state(array $quote): string
{
    $state = (string)($quote['project_completion']['state'] ?? 'pending');
    return in_array($state, ['pending', 'completed', 'reopened'], true) ? $state : 'pending';
}

function documents_project_completion_review(array $quote, array $receipts = null): array
{
    $summary = documents_project_financial_summary($quote, $receipts);
    $completion = is_array($quote['project_completion'] ?? null) ? $quote['project_completion'] : [];
    $snapshot = is_array($completion['snapshot'] ?? null) ? $completion['snapshot'] : [];
    $currentInvoiceSnapshot = documents_project_invoice_set_snapshot($quote);
    $basis = (string)($summary['calculation_basis'] ?? '');
    $basisStatus = (string)($summary['basis_status'] ?? '');
    $reference = (float)($summary['calculation_reference_amount'] ?? 0);
    $outstanding = (float)($summary['remaining_amount'] ?? 0);
    $active = documents_quote_normalize_status((string)($quote['status'] ?? 'draft')) === 'accepted'
        && !empty($quote['is_current_version']) && !documents_is_archived($quote);
    $basisValid = in_array($basis, ['quotation', 'finalized_invoices'], true)
        && is_finite($reference) && $reference >= 0
        && !($basis === 'finalized_invoices' && $basisStatus !== 'confirmed');
    $changed = documents_project_completion_state($quote) === 'completed' && $snapshot !== [] && (
        abs((float)($snapshot['reference_amount'] ?? 0) - $reference) > 0.01
        || abs((float)($snapshot['paid_amount'] ?? 0) - (float)($summary['total_payment_received'] ?? 0)) > 0.01
        || (string)($snapshot['calculation_basis'] ?? '') !== $basis
        || (string)($snapshot['active_invoice_snapshot']['hash'] ?? '') !== (string)$currentInvoiceSnapshot['hash']
    );
    return ['summary'=>$summary, 'completion'=>$completion, 'basis_valid'=>$basisValid,
        'can_complete'=>$active && $basisValid && $basisStatus !== 'needs_reconfirmation' && abs($outstanding) <= 0.01,
        'financial_data_changed'=>$changed, 'active'=>$active];
}

function documents_project_mark_completed(array $quote, array $actor, string $note = ''): array
{
    if (documents_project_completion_state($quote) === 'completed') return ['ok'=>false,'error'=>'Project is already completed.','quote'=>$quote];
    $review = documents_project_completion_review($quote);
    if (empty($review['can_complete'])) return ['ok'=>false,'error'=>'Project must be active, have a valid confirmed calculation basis, and have no outstanding amount.','quote'=>$quote];
    $summary = $review['summary']; $at = date('c');
    $snapshot = ['active_invoice_snapshot'=>documents_project_invoice_set_snapshot($quote),
        'calculation_basis'=>(string)$summary['calculation_basis'], 'basis_status'=>(string)$summary['basis_status'],
        'reference_amount'=>(float)$summary['calculation_reference_amount'], 'paid_amount'=>(float)$summary['total_payment_received'],
        'outstanding'=>(float)$summary['remaining_amount'], 'overpayment'=>(float)$summary['overpayment']];
    $event = ['event'=>'project_completed','timestamp'=>$at,'actor_id'=>(string)($actor['id']??''),'actor_name'=>(string)($actor['name']??$actor['full_name']??'Admin'),'note'=>safe_multiline_text($note),'snapshot'=>$snapshot];
    $audit = is_array($quote['project_completion_audit']??null)?$quote['project_completion_audit']:[]; $audit[]=$event;
    $quote['project_completion']=['state'=>'completed','completed_at'=>$at,'completed_by'=>['id'=>$event['actor_id'],'name'=>$event['actor_name']],'note'=>$event['note'],'snapshot'=>$snapshot];
    $quote['project_completion_audit']=$audit; $quote['updated_at']=$at;
    return ['ok'=>true,'error'=>'','quote'=>$quote];
}

function documents_project_reopen(array $quote, array $actor, string $reason): array
{
    $reason=safe_multiline_text($reason);
    if (documents_project_completion_state($quote)!=='completed') return ['ok'=>false,'error'=>'Only completed projects can be reopened.','quote'=>$quote];
    if ($reason==='') return ['ok'=>false,'error'=>'A reopening reason is required.','quote'=>$quote];
    $at=date('c'); $audit=is_array($quote['project_completion_audit']??null)?$quote['project_completion_audit']:[];
    $audit[]=['event'=>'project_reopened','timestamp'=>$at,'actor_id'=>(string)($actor['id']??''),'actor_name'=>(string)($actor['name']??$actor['full_name']??'Admin'),'reason'=>$reason,'completion_snapshot'=>$quote['project_completion']['snapshot']??[]];
    $quote['project_completion']['state']='reopened'; $quote['project_completion']['reopened_at']=$at; $quote['project_completion']['reopened_by']=['id'=>(string)($actor['id']??''),'name'=>(string)($actor['name']??$actor['full_name']??'Admin')]; $quote['project_completion']['reopen_reason']=$reason;
    $quote['project_completion_audit']=$audit; $quote['updated_at']=$at;
    return ['ok'=>true,'error'=>'','quote'=>$quote];
}

function documents_project_confirm_calculation_basis(array $quote, string $basis, array $actor, string $reason, string $expectedHash): array
{
    $basis = safe_text($basis); $reason = safe_multiline_text($reason); $summary = documents_project_financial_summary($quote); $snap = documents_project_invoice_set_snapshot($quote);
    if (!in_array($basis, ['quotation','finalized_invoices'], true)) return ['ok'=>false,'error'=>'Unsupported calculation basis.','quote'=>$quote,'summary'=>$summary];
    if ($basis === 'finalized_invoices' && (string)$snap['hash'] !== $expectedHash) return ['ok'=>false,'error'=>'Finalized invoice set changed. Refresh and confirm again.','quote'=>$quote,'summary'=>$summary];
    if ($basis === 'quotation' && $reason === '' && (string)($summary['calculation_basis'] ?? '') === 'finalized_invoices') return ['ok'=>false,'error'=>'Reason is required to switch back to quotation basis.','quote'=>$quote,'summary'=>$summary];
    $previous = is_array($quote['commercial_settlement'] ?? null) ? $quote['commercial_settlement'] : [];
    $amount = $basis === 'finalized_invoices' ? (float)$snap['total'] : (float)$summary['quotation_amount'];
    $quote['commercial_settlement'] = ['basis'=>$basis,'status'=>$basis === 'finalized_invoices' ? 'confirmed' : 'not_confirmed','confirmed_reference_amount'=>$amount,'finalized_invoice_total_at_confirmation'=>(float)$snap['total'],'included_invoice_ids'=>$snap['ids'],'included_invoice_revisions'=>$snap['revisions'],'invoice_set_hash'=>(string)$snap['hash'],'confirmed_at'=>date('c'),'confirmed_by'=>['id'=>(string)($actor['id']??''),'name'=>(string)($actor['name']??$actor['full_name']??'Admin')],'confirmation_reason'=>$reason];
    $events = is_array($quote['commercial_settlement_audit'] ?? null) ? $quote['commercial_settlement_audit'] : [];
    $events[] = ['event'=>$basis === 'quotation' ? 'calculation_basis_switched_to_quotation' : ((string)($previous['basis'] ?? '') === 'finalized_invoices' ? 'calculation_basis_reconfirmed' : 'calculation_basis_confirmed'),'previous_basis'=>(string)($previous['basis'] ?? 'quotation'),'new_basis'=>$basis,'previous_amount'=>(float)($previous['confirmed_reference_amount'] ?? $summary['quotation_amount']),'new_amount'=>$amount,'invoice_ids'=>$snap['ids'],'invoice_revisions'=>$snap['revisions'],'invoice_set_hash'=>(string)$snap['hash'],'quotation_amount'=>(float)$summary['quotation_amount'],'paid_amount'=>(float)$summary['total_payment_received'],'remaining_amount'=>max(0,$amount-(float)$summary['total_payment_received']),'overpayment'=>max(0,(float)$summary['total_payment_received']-$amount),'reason'=>$reason,'actor_id'=>(string)($actor['id']??''),'actor_name'=>(string)($actor['name']??$actor['full_name']??'Admin'),'timestamp'=>date('c')];
    $quote['commercial_settlement_audit'] = $events; $quote['updated_at'] = date('c');
    return ['ok'=>true,'quote'=>$quote,'summary'=>documents_project_financial_summary($quote),'error'=>''];
}

function documents_project_mark_basis_reconfirmation_if_needed(array $quote, string $reason = 'invoice_set_changed'): array
{
    $summary = documents_project_financial_summary($quote);
    if (($summary['calculation_basis'] ?? '') === 'finalized_invoices' && ($summary['basis_status'] ?? '') === 'needs_reconfirmation') {
        $quote['commercial_settlement']['status'] = 'needs_reconfirmation';
        $events = is_array($quote['commercial_settlement_audit'] ?? null) ? $quote['commercial_settlement_audit'] : [];
        $events[] = ['event'=>'calculation_basis_needs_reconfirmation','reason'=>$reason,'invoice_set_hash'=>(string)($summary['active_finalized_invoice_set_hash'] ?? ''),'timestamp'=>date('c')];
        $quote['commercial_settlement_audit'] = $events;
    }
    return $quote;
}

function documents_invoice_payment_status(array $invoice): string { return (string)documents_invoice_payment_summary($invoice)['payment_status']; }

function documents_invoice_append_audit_event(array $invoice, string $type, array $actor, string $reason = '', array $previous = []): array
{ $summary=documents_invoice_payment_summary($invoice); $events=is_array($invoice['audit_events']??null)?$invoice['audit_events']:[]; $events[]=['invoice_id'=>(string)($invoice['id']??''),'revision_no'=>(int)($invoice['revision_no']??1),'event_type'=>$type,'previous_document_status'=>(string)($previous['status']??''),'new_document_status'=>documents_invoice_normalize_status((string)($invoice['status']??'draft')),'previous_payment_status'=>(string)($previous['payment_status']??''),'new_payment_status'=>$summary['payment_status'],'previous_invoice_date'=>(string)($previous['invoice_date']??''),'new_invoice_date'=>documents_invoice_authoritative_date($invoice),'invoice_total'=>$summary['invoice_total'],'total_received'=>$summary['total_received'],'outstanding'=>$summary['outstanding'],'overpayment'=>$summary['overpayment'],'reason'=>safe_multiline_text($reason),'actor_id'=>(string)($actor['id']??''),'actor_name'=>(string)($actor['name']??$actor['full_name']??'Admin'),'timestamp'=>date('c')]; $invoice['audit_events']=$events; return $invoice; }

function documents_invoice_can_finalize(array $invoice): array
{ $errors=[]; if(!documents_invoice_is_draft($invoice))$errors[]='Invoice is not Draft.'; if(documents_is_archived($invoice))$errors[]='Archived invoices cannot be finalized.'; if(trim((string)($invoice['invoice_no']??''))==='')$errors[]='Invoice number is required.'; $dateCheck=documents_invoice_date_validate((string)($invoice['invoice_date']??''), false); if(empty($dateCheck['ok']))$errors[]='Set a valid invoice date before finalizing this invoice.'; if(documents_invoice_final_total($invoice)<0)$errors[]='Invoice total must be non-negative.'; documents_invoice_payment_summary($invoice); return ['ok'=>$errors===[], 'errors'=>$errors]; }

function documents_invoice_finalize(array $invoice, array $actor): array
{ $can=documents_invoice_can_finalize($invoice); if(empty($can['ok'])) return ['ok'=>false,'errors'=>$can['errors'],'invoice'=>$invoice]; $invoice=documents_invoice_normalize_date($invoice); $prev=['status'=>(string)($invoice['status']??'draft'),'payment_status'=>documents_invoice_payment_status($invoice),'invoice_date'=>(string)($invoice['invoice_date']??documents_invoice_authoritative_date($invoice))]; $rev=max(1,(int)($invoice['revision_no']??0)); $invoice['status']='finalized'; $invoice['revision_no']=$rev; $invoice['finalized_at']=date('c'); $invoice['finalized_by']=['id'=>(string)($actor['id']??''),'name'=>(string)($actor['name']??$actor['full_name']??'Admin')]; $invoice['finalized_snapshot']=['revision_no'=>$rev,'finalized_at'=>$invoice['finalized_at'],'finalized_by'=>$invoice['finalized_by'],'invoice_no'=>(string)($invoice['invoice_no']??''),'invoice_date'=>(string)($invoice['invoice_date']??documents_invoice_authoritative_date($invoice)),'customer_snapshot'=>$invoice['customer_snapshot']??[],'pricing'=>$invoice['pricing']??[],'calc'=>$invoice['calc']??[],'tax_breakdown'=>$invoice['tax_breakdown']??[],'commercial_items'=>$invoice['commercial_items']??[],'quotation_reference'=>['quotation_id'=>(string)($invoice['linked_quote_id']??$invoice['quotation_id']??''),'quotation_no'=>(string)($invoice['quotation_no']??'')],'payment_summary_at_finalization'=>documents_invoice_payment_summary($invoice)]; $invoice=documents_invoice_append_audit_event($invoice,'invoice_finalized',$actor,'',$prev); $invoice['updated_at']=date('c'); return ['ok'=>true,'invoice'=>$invoice]; }

function documents_invoice_start_revision(array $invoice, array $actor, string $reason): array
{ $reason=safe_multiline_text($reason); if($reason==='') return ['ok'=>false,'error'=>'Revision reason is required.','invoice'=>$invoice]; if(!documents_invoice_is_finalized($invoice)) return ['ok'=>false,'error'=>'Only finalized invoices can be revised.','invoice'=>$invoice]; $invoice=documents_invoice_normalize_date($invoice); $prev=$invoice['finalized_snapshot']??$invoice; $revs=is_array($invoice['revisions']??null)?$invoice['revisions']:[]; $revs[]=['revision_no'=>(int)($invoice['revision_no']??1),'status'=>'finalized','snapshot'=>$prev,'finalized_at'=>(string)($invoice['finalized_at']??''),'finalized_by'=>$invoice['finalized_by']??[]]; $invoice['revisions']=$revs; $invoice['status']='draft'; $invoice['revision_no']=(int)($invoice['revision_no']??1)+1; $invoice['revision_parent_no']=$invoice['revision_no']-1; $invoice['revision_reason']=$reason; $invoice['revision_started_at']=date('c'); $invoice['revision_started_by']=['id'=>(string)($actor['id']??''),'name'=>(string)($actor['name']??$actor['full_name']??'Admin')]; $invoice=documents_invoice_append_audit_event($invoice,'invoice_revision_started',$actor,$reason,['status'=>'finalized','invoice_date'=>(string)($prev['invoice_date']??'')]); $invoice['updated_at']=date('c'); return ['ok'=>true,'invoice'=>$invoice]; }

function documents_invoice_cancel(array $invoice, array $actor, string $reason): array
{ $reason=safe_multiline_text($reason); if($reason==='') return ['ok'=>false,'error'=>'Cancellation reason is required.','invoice'=>$invoice]; $prev=['status'=>(string)($invoice['status']??''),'payment_status'=>documents_invoice_payment_status($invoice)]; $invoice['status']='cancelled'; $invoice['cancelled_at']=date('c'); $invoice['cancelled_by']=['id'=>(string)($actor['id']??''),'name'=>(string)($actor['name']??$actor['full_name']??'Admin')]; $invoice['cancellation_reason']=$reason; $invoice=documents_invoice_append_audit_event($invoice,'invoice_cancelled',$actor,$reason,$prev); $invoice['updated_at']=date('c'); return ['ok'=>true,'invoice'=>$invoice]; }

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
    $doc = documents_invoice_normalize_date(array_merge(documents_invoice_defaults(), $row));
    $doc['status'] = documents_invoice_normalize_status((string) ($doc['status'] ?? 'draft'));
    $resolvedInvoiceNo = documents_first_non_empty_string([
        $doc['invoice_no'] ?? '',
        $row['invoice_number'] ?? '',
        $row['document_number'] ?? '',
    ]);
    if ($resolvedInvoiceNo !== '' && safe_text((string) ($doc['invoice_no'] ?? '')) === '') {
        $doc['invoice_no'] = $resolvedInvoiceNo;
        json_save($path, $doc);
    }
    $doc['customer_snapshot'] = array_merge(documents_customer_snapshot_defaults(), is_array($doc['customer_snapshot'] ?? null) ? $doc['customer_snapshot'] : []);
    $normalized = documents_invoice_normalize_commercial_snapshot($doc);
    if ($normalized !== $doc) {
        json_save($path, $normalized);
        $doc = $normalized;
    }
    return $doc;
}

function documents_save_invoice(array $doc): array
{
    $id = safe_filename((string) ($doc['id'] ?? ''));
    if ($id === '') {
        return ['ok' => false, 'error' => 'Missing invoice ID'];
    }
    $doc = documents_invoice_normalize_date(documents_invoice_normalize_commercial_snapshot($doc));
    $doc['status'] = documents_invoice_normalize_status((string) ($doc['status'] ?? 'draft'));
    $saved = json_save(documents_invoices_dir() . '/' . $id . '.json', $doc);
    if (!empty($saved['ok'])) {
        $quoteId = (string)($doc['linked_quote_id'] ?? $doc['quotation_id'] ?? '');
        $quote = $quoteId !== '' ? documents_get_quote($quoteId) : null;
        if (is_array($quote)) {
            $updated = documents_project_mark_basis_reconfirmation_if_needed($quote, 'invoice_saved');
            if ($updated !== $quote) { documents_save_quote($updated); }
        }
    }
    return $saved;
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

function documents_quote_admin_history_add(array $quote, string $event, string $message, array $meta = []): array
{
    $history = is_array($quote['admin_change_history'] ?? null) ? $quote['admin_change_history'] : [];
    $history[] = array_merge([
        'event' => safe_text($event),
        'message' => safe_text($message),
        'recorded_at' => date('c'),
        'visible_to_customer' => false,
    ], $meta);
    $quote['admin_change_history'] = array_values(array_filter($history, static fn($entry): bool => is_array($entry)));
    return $quote;
}

function documents_quote_can_reopen_approved_for_edit(array $quote): bool
{
    $quote = documents_quote_prepare($quote);
    $status = documents_quote_normalize_status((string) ($quote['status'] ?? 'draft'));
    $acceptance = is_array($quote['customer_acceptance'] ?? null) ? $quote['customer_acceptance'] : [];
    $acceptedAt = safe_text((string) ($quote['accepted_at'] ?? ''));
    $customerAcceptedAt = safe_text((string) ($acceptance['confirmed_at'] ?? $acceptance['accepted_at'] ?? ''));
    return $status === 'approved'
        && !documents_quote_is_locked($quote)
        && !documents_is_archived($quote)
        && $acceptedAt === ''
        && $customerAcceptedAt === ''
        && empty($quote['superseded_by_quote_id'])
        && empty($quote['superseded_by_quote_no']);
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


function documents_quote_invoice_item_summary(array $quote): array
{
    $settings = documents_get_quote_defaults_settings();
    $quoteItems = documents_normalize_quote_structured_items(is_array($quote['quote_items'] ?? null) ? $quote['quote_items'] : []);
    $rows = [];
    foreach ($quoteItems as $quoteItem) {
        if (!is_array($quoteItem)) {
            continue;
        }
        $qty = (float) ($quoteItem['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        if ((string) ($quoteItem['type'] ?? 'component') === 'kit') {
            $kit = documents_inventory_get_kit((string) ($quoteItem['kit_id'] ?? ''));
            $name = safe_text((string) ($quoteItem['name_snapshot'] ?? '')) ?: safe_text((string) ($kit['name'] ?? 'Kit'));
            $description = safe_multiline_text((string) ($quoteItem['master_description_snapshot'] ?? $quoteItem['description_snapshot'] ?? '')) ?: safe_text((string) ($kit['description'] ?? ''));
            $hsn = safe_text((string) ($quoteItem['hsn_snapshot'] ?? '')) ?: (safe_text((string) ($settings['defaults']['hsn_solar'] ?? '8541')) ?: '8541');
            $unit = safe_text((string) ($quoteItem['unit'] ?? '')) ?: 'set';
        } else {
            $component = documents_inventory_get_component((string) ($quoteItem['component_id'] ?? ''));
            $variantSnapshot = is_array($quoteItem['variant_snapshot'] ?? null) ? $quoteItem['variant_snapshot'] : [];
            $name = safe_text((string) ($quoteItem['name_snapshot'] ?? ''));
            if ($name === '') {
                $name = safe_text((string) ($component['name'] ?? 'Component'));
                $variantName = safe_text((string) ($variantSnapshot['display_name'] ?? ''));
                if ($variantName !== '') {
                    $name .= ' (' . $variantName . ')';
                }
            }
            $description = safe_multiline_text((string) ($quoteItem['master_description_snapshot'] ?? $quoteItem['description_snapshot'] ?? '')) ?: safe_text((string) ($component['description'] ?? $component['notes'] ?? ''));
            $hsn = safe_text((string) ($quoteItem['hsn_snapshot'] ?? '')) ?: (safe_text((string) ($component['hsn'] ?? '')) ?: (safe_text((string) ($settings['defaults']['hsn_solar'] ?? '8541')) ?: '8541'));
            $unit = safe_text((string) ($quoteItem['unit'] ?? '')) ?: safe_text((string) ($component['default_unit'] ?? ''));
        }
        $rows[] = [
            'name' => $name,
            'description' => $description,
            'auto_description' => safe_multiline_text((string) ($quoteItem['auto_description'] ?? '')),
            'custom_description' => safe_multiline_text((string) ($quoteItem['custom_description'] ?? '')),
            'hsn' => $hsn,
            'qty' => $qty,
            'unit' => $unit,
        ];
    }
    return $rows;
}

function documents_quote_invoice_customer_fields(array $quote): array
{
    $snapshot = documents_quote_resolve_snapshot($quote);
    $value = static fn($v): string => trim((string) ($v ?? ''));
    $fields = [
        ['label' => 'Name', 'value' => $value($quote['customer_name'] ?? $snapshot['name'] ?? '')],
        ['label' => 'Mobile', 'value' => $value($quote['customer_mobile'] ?? $snapshot['mobile'] ?? '')],
        ['label' => 'Site Address', 'value' => $value($quote['site_address'] ?? $snapshot['address'] ?? '')],
        ['label' => 'District', 'value' => $value($quote['district'] ?? $snapshot['district'] ?? '')],
        ['label' => 'City', 'value' => $value($quote['city'] ?? $snapshot['city'] ?? '')],
        ['label' => 'State', 'value' => $value($quote['state'] ?? $snapshot['state'] ?? '')],
        ['label' => 'PIN', 'value' => $value($quote['pin'] ?? $snapshot['pin_code'] ?? '')],
        ['label' => 'Billing Address', 'value' => $value($quote['billing_address'] ?? '')],
        ['label' => 'Place of Supply State', 'value' => $value($quote['place_of_supply_state'] ?? '')],
        ['label' => 'Consumer Account No. (JBVNL)', 'value' => $value($quote['consumer_account_no'] ?? $snapshot['consumer_account_no'] ?? '')],
        ['label' => 'Meter Number', 'value' => $value($quote['meter_number'] ?? $snapshot['meter_number'] ?? '')],
        ['label' => 'Meter Serial Number', 'value' => $value($quote['meter_serial_number'] ?? $snapshot['meter_serial_number'] ?? '')],
        ['label' => 'Application ID', 'value' => $value($quote['application_id'] ?? $snapshot['application_id'] ?? '')],
        ['label' => 'Application Submitted Date', 'value' => $value($quote['application_submitted_date'] ?? $snapshot['application_submitted_date'] ?? '')],
        ['label' => 'Sanction Load', 'value' => $value($quote['sanction_load_kwp'] ?? $snapshot['sanction_load_kwp'] ?? '')],
        ['label' => 'Installed PV Capacity', 'value' => $value($quote['installed_pv_module_capacity_kwp'] ?? $snapshot['installed_pv_module_capacity_kwp'] ?? '')],
        ['label' => 'Circle', 'value' => $value($quote['circle_name'] ?? $snapshot['circle_name'] ?? '')],
        ['label' => 'Division', 'value' => $value($quote['division_name'] ?? $snapshot['division_name'] ?? '')],
        ['label' => 'Sub Division', 'value' => $value($quote['sub_division_name'] ?? $snapshot['sub_division_name'] ?? '')],
    ];
    return array_values(array_filter($fields, static fn(array $f): bool => trim((string) ($f['value'] ?? '')) !== ''));
}

function documents_invoice_quote_snapshot(array $quote): array
{
    return [
        'quote_id' => safe_text((string) ($quote['id'] ?? '')),
        'quote_no' => safe_text((string) ($quote['quote_no'] ?? '')),
        'item_summary' => documents_quote_invoice_item_summary($quote),
        'customer_site_fields' => documents_quote_invoice_customer_fields($quote),
        'special_requests_text' => trim((string) ($quote['special_requests_text'] ?? $quote['special_requests_inclusive'] ?? '')),
        'pricing_mode' => safe_text((string) ($quote['pricing_mode'] ?? 'solar_split_70_30')),
        'input_total_gst_inclusive' => (float) ($quote['input_total_gst_inclusive'] ?? 0),
        'calc' => is_array($quote['calc'] ?? null) ? $quote['calc'] : [],
        'tax_breakdown' => is_array($quote['calc']['tax_breakdown'] ?? null) ? $quote['calc']['tax_breakdown'] : (is_array($quote['tax_breakdown'] ?? null) ? $quote['tax_breakdown'] : []),
    ];
}

function documents_create_invoice_from_quote(array $quote, array $options = []): array
{
    $quote = documents_quote_repair_invoice_workflow($quote);
    $quoteId = safe_text((string) ($quote['id'] ?? ''));
    $can = documents_quote_can_create_invoice($quote);
    if (empty($can['ok'])) return ['ok' => false, 'error' => implode(' ', $can['errors'])];
    $token = safe_text((string)($options['idempotency_key'] ?? ''));
    if ($token !== '') {
        $tokens = is_array($quote['workflow']['invoice_creation_tokens'] ?? null) ? $quote['workflow']['invoice_creation_tokens'] : [];
        if (!empty($tokens[$token]) && documents_get_invoice((string)$tokens[$token]) !== null) return ['ok'=>true,'invoice_id'=>(string)$tokens[$token],'deduplicated'=>true,'error'=>''];
    }
    $reason = safe_multiline_text((string)($options['exceed_reason'] ?? ''));
    $replacementFor = safe_text((string)($options['replacement_for_invoice_id'] ?? ''));
    $summary = documents_quote_invoice_totals_summary($quote);
    if (!empty($summary['would_exceed']) && $reason === '') return ['ok'=>false,'error'=>'Reason is required when active invoice totals exceed the quotation amount.','summary'=>$summary];
    $segment = safe_text((string) ($quote['segment'] ?? 'RES')) ?: 'RES';
    $number = documents_generate_invoice_public_number($segment);
    if (!$number['ok']) { documents_log('numbering rule missing for invoice_public quote ' . $quoteId); return ['ok'=>false,'error'=>(string)($number['error'] ?? 'Unable to generate invoice number.')]; }
    $snapshot = documents_quote_resolve_snapshot($quote); $doc = documents_invoice_defaults();
    $doc['id'] = 'inv_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    $doc['invoice_no'] = (string) ($number['invoice_no'] ?? ''); $doc['status'] = 'draft'; $doc['invoice_kind'] = 'public';
    $doc['linked_quote_id'] = $quoteId; $doc['quotation_id'] = $quoteId; $doc['quotation_no'] = safe_text((string) ($quote['quote_no'] ?? ''));
    $doc['source_quote_version'] = max(1, (int) ($quote['version'] ?? $quote['revision_no'] ?? 1));
    $doc['commercial_items'] = is_array($quote['items'] ?? null) ? $quote['items'] : (array) ($quote['quote_items'] ?? []);
    $doc['source_quote_hash'] = hash('sha256', json_encode([$doc['commercial_items'], $quote['calc'] ?? [], $quote['input_total_gst_inclusive'] ?? 0], JSON_UNESCAPED_SLASHES) ?: '');
    $doc['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? '')); $doc['customer_snapshot'] = $snapshot;
    $doc['capacity_kwp'] = safe_text((string) ($quote['capacity_kwp'] ?? '')); $doc['pricing_mode'] = safe_text((string) ($quote['pricing_mode'] ?? 'solar_split_70_30'));
    $doc['input_total_gst_inclusive'] = (float) ($quote['input_total_gst_inclusive'] ?? 0); $doc['calc'] = is_array($quote['calc'] ?? null) ? $quote['calc'] : [];
    $doc['quotation_snapshot'] = documents_invoice_quote_snapshot($quote); $doc['tax_breakdown'] = is_array($doc['quotation_snapshot']['tax_breakdown'] ?? null) ? $doc['quotation_snapshot']['tax_breakdown'] : [];
    $doc['replacement_for_invoice_id'] = $replacementFor; if ($reason !== '') $doc['invoice_creation_reason'] = $reason;
    $doc = documents_invoice_normalize_date(documents_invoice_normalize_commercial_snapshot($doc)); $doc['created_at'] = date('c'); $doc['invoice_date'] = date('Y-m-d'); $doc['invoice_date_source'] = 'explicit'; $doc['updated_at'] = date('c');
    $delivery = documents_update_draft_invoice_delivery($doc, $quoteId); $doc = $delivery['invoice'];
    $saved = documents_save_invoice($doc); if (!$saved['ok']) { documents_log('file save failed for invoice quote ' . $quoteId); return ['ok'=>false,'error'=>'Failed to create invoice draft.']; }
    if ($replacementFor !== '') { $old=documents_get_invoice($replacementFor); if($old){ $old['replaced_by_invoice_id']=(string)$doc['id']; $old=documents_invoice_append_audit_event($old,'invoice_replacement_linked',(array)($options['actor']??[]),'Replacement invoice created'); documents_save_invoice($old); } }
    return ['ok'=>true,'invoice_id'=>(string)$doc['id'],'error'=>'','summary'=>$summary];
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
        if (!documents_numbering_rule_is_active(is_array($rule) ? $rule : [])) {
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
    if (in_array($normalized, ['draft', 'pending_admin_approval', 'approved', 'accepted', 'update_requested', 'archived'], true)) {
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


function documents_quote_append_customer_visible_history(array $quote, string $event, string $message, array $meta = []): array
{
    $history = is_array($quote['customer_visible_change_history'] ?? null) ? $quote['customer_visible_change_history'] : [];
    $history[] = array_merge([
        'event' => safe_text($event),
        'message' => safe_text($message),
        'recorded_at' => date('c'),
        'visible_to_customer' => true,
    ], $meta);
    $quote['customer_visible_change_history'] = array_values(array_filter($history, static fn($entry): bool => is_array($entry)));
    return $quote;
}

function documents_quote_customer_visible_history(array $quote): array
{
    $history = is_array($quote['customer_visible_change_history'] ?? null) ? $quote['customer_visible_change_history'] : [];
    usort($history, static function ($a, $b): int {
        return strcmp((string) ($b['recorded_at'] ?? ''), (string) ($a['recorded_at'] ?? ''));
    });
    return array_values(array_filter($history, static fn($entry): bool => is_array($entry) && !empty($entry['visible_to_customer'])));
}

function documents_quote_workflow_defaults(): array
{
    return [
        'agreement_id' => '',
        'proforma_invoice_id' => '',
        'invoice_id' => '',
        'invoice_ids' => [],
        'dispatch_advice_id' => '',
        'dispatch_advice_ids' => [],
        'challan_id' => '',
        'challan_ids' => [],
        'receipt_ids' => [],
        'delivery_challan_ids' => [],
        'packing_list_id' => '',
    ];
}


function quotation_number_input_value($value, int $precision = 2): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if (is_string($value)) {
        $value = str_replace(',', '', trim($value));
        if ($value === '') {
            return '';
        }
    }
    if (!is_numeric($value)) {
        return '';
    }
    $number = (float) $value;
    if (!is_finite($number)) {
        return '';
    }
    $precision = max(0, min(6, $precision));
    $number = round($number, $precision);
    if (abs($number) < (0.5 / (10 ** max(0, $precision)))) {
        $number = 0.0;
    }
    $formatted = number_format($number, $precision, '.', '');
    return rtrim(rtrim($formatted, '0'), '.') ?: '0';
}

function documents_quote_normalize_editable_finance_values(array $quote): array
{
    $moneyKeys = ['price','gross_payable','net_investment_after_subsidy','monthly_outflow','payback','margin_money_rs','loan_amount_rs','effective_loan_principal_rs','emi_rs','residual_bill_rs','initial_investment_after_subsidy_credit_rs','remaining_subsidy_after_margin_adjustment_rs','net_own_investment_after_subsidy','subsidy'];
    $pctKeys = ['margin_ratio_pct','loan_ratio_pct','interest_pct'];
    $tenureKeys = ['tenure_years'];
    $normalize = static function ($value, int $precision, ?float $min = 0.0, ?float $max = null) {
        $text = quotation_number_input_value($value, $precision);
        if ($text === '') {
            return 0.0;
        }
        $number = (float) $text;
        if ($min !== null) {
            $number = max($min, $number);
        }
        if ($max !== null) {
            $number = min($max, $number);
        }
        return round($number, $precision);
    };
    if (isset($quote['scenario_prices']) && is_array($quote['scenario_prices'])) {
        foreach ($quote['scenario_prices'] as $key => $row) {
            if (is_array($row) && array_key_exists('price', $row)) {
                $quote['scenario_prices'][$key]['price'] = $normalize($row['price'], 2, 0.0, null);
            }
        }
    }
    if (isset($quote['finance_scenarios']) && is_array($quote['finance_scenarios'])) {
        foreach ($quote['finance_scenarios'] as $scenarioKey => $scenario) {
            if (!is_array($scenario)) continue;
            foreach ($moneyKeys as $key) if (array_key_exists($key, $scenario)) $scenario[$key] = $normalize($scenario[$key], 2, 0.0, null);
            foreach ($pctKeys as $key) if (array_key_exists($key, $scenario)) $scenario[$key] = $normalize($scenario[$key], 2, 0.0, 100.0);
            foreach ($tenureKeys as $key) if (array_key_exists($key, $scenario)) $scenario[$key] = $normalize($scenario[$key], 2, 0.0, null);
            if (str_contains((string) $scenarioKey, 'loan_upto_2_lacs')) {
                $price = (float) ($scenario['price'] ?? ($quote['scenario_prices']['loan_upto_2_lacs']['price'] ?? 0));
                $loan = min((float) ($scenario['loan_amount_rs'] ?? 0), 200000.0, max(0.0, $price));
                $margin = max(0.0, $price - $loan);
                $scenario['loan_amount_rs'] = round($loan, 2);
                $scenario['margin_money_rs'] = round($margin, 2);
                $scenario['margin_ratio_pct'] = $price > 0 ? round(($margin / $price) * 100, 2) : 0.0;
                $scenario['loan_ratio_pct'] = round(max(0.0, 100.0 - (float) $scenario['margin_ratio_pct']), 2);
            }
            $quote['finance_scenarios'][$scenarioKey] = $scenario;
        }
    }
    if (isset($quote['finance_inputs']) && is_array($quote['finance_inputs'])) {
        foreach (['monthly_bill_rs','unit_rate_rs_per_kwh','annual_generation_per_kw','subsidy_expected_rs','transportation_rs','discount_rs'] as $key) {
            if (array_key_exists($key, $quote['finance_inputs'])) $quote['finance_inputs'][$key] = quotation_number_input_value($quote['finance_inputs'][$key], 2);
        }
        if (isset($quote['finance_inputs']['loan']) && is_array($quote['finance_inputs']['loan'])) {
            foreach (['interest_pct','tenure_years','margin_pct','loan_amount'] as $key) {
                if (array_key_exists($key, $quote['finance_inputs']['loan'])) $quote['finance_inputs']['loan'][$key] = quotation_number_input_value($quote['finance_inputs']['loan'][$key], 2);
            }
        }
    }
    return $quote;
}



function documents_quote_panel_size_profiles(): array
{
    return [
        'portrait' => ['w' => 2, 'h' => 4, 'aspect' => 0.52],
        'landscape' => ['w' => 4, 'h' => 2, 'aspect' => 1.92],
    ];
}

function documents_quote_panel_size_profile(string $orientation): array
{
    $profiles = documents_quote_panel_size_profiles();
    return $profiles[$orientation === 'landscape' ? 'landscape' : 'portrait'];
}

function documents_quote_panel_orientation_defaults(): array
{
    return [
        'enabled' => false,
        'layout_mode' => 'generated',
        'diagram_style' => 'freeform_roof',
        'site_area_type' => 'terrace',
        'site_area_label' => 'Main roof',
        'roof_label' => 'Main roof',
        'north_direction' => 'up',
        'default_facing_direction' => 'South',
        'default_tilt_deg' => '15',
        'row_layout' => 'portrait_rows',
        'shade_note' => '',
        'customer_note' => 'The panel layout shown below is the proposed arrangement for customer understanding and approval. Minor placement adjustments may be made during installation for safety, roof condition, shade avoidance, cable routing, and service access. Any major change affecting system capacity, price, or commercial scope will require confirmation or a revised quotation.',
        'groups' => [[
            'group_id' => 'A', 'label' => 'Group A', 'roof_section' => 'Main roof',
            'x' => 10, 'y' => 10, 'rows' => 1, 'columns' => 4, 'panel_count' => 4,
            'panel_orientation' => 'portrait', 'panel_rotation_deg' => 0,
            'facing_direction' => 'South', 'tilt_deg' => '15', 'row_gap' => 8, 'column_gap' => 6, 'remarks' => '',
        ]],
        'custom_panels' => [],
        'obstructions' => [],
        'uploaded_diagram_path' => '',
        'grid' => ['columns' => 36, 'rows' => 24, 'cell_unit' => 'grid', 'editor_cell_px' => 18, 'major_line_every' => 5, 'customer_grid_visible' => false, 'panel_size_profile' => 'standard_module'],
        'panel_size_profile' => 'standard_module',
        'panel_aspect_ratio' => 1.92,
        'objects' => [],
    ];
}

function documents_quote_panel_orientation_allowed_directions(): array
{
    return ['South','South-East','South-West','East','West','North-East','North-West','North','Mixed / multiple directions','To be finalized after site marking'];
}

function documents_quote_panel_orientation_allowed_layouts(): array
{
    return ['portrait_rows'=>'Portrait rows','landscape_rows'=>'Landscape rows','mixed_portrait_landscape'=>'Mixed portrait/landscape','follows_roof_structure'=>'Row direction follows roof structure','to_be_finalized'=>'To be finalized during installation'];
}

function documents_quote_panel_orientation_allowed_area_types(): array
{
    return ['main_roof'=>'Main roof','terrace'=>'Terrace','shed_roof'=>'Shed roof','ground_mounted'=>'Ground-mounted area','multiple_roof_sections'=>'Multiple roof sections','other'=>'Other'];
}

function documents_quote_clean_orientation_direction(string $raw, string $fallback = 'South'): string
{
    $value = safe_text($raw);
    if ($value === '') return $fallback;
    if (in_array($value, documents_quote_panel_orientation_allowed_directions(), true)) return $value;
    return mb_substr($value, 0, 80);
}

function documents_quote_clean_orientation_tilt($raw): string
{
    $value = trim((string) $raw);
    if ($value === '') return '';
    if (!is_numeric($value)) return '';
    $n = max(0, min(45, (float) $value));
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

function documents_quote_int_range($raw, int $min, int $max, int $fallback): int
{
    if (!is_numeric($raw)) return $fallback;
    return max($min, min($max, (int) round((float) $raw)));
}

function documents_quote_panel_orientation_from_layout(string $rowLayout): string
{
    return $rowLayout === 'landscape_rows' ? 'landscape' : 'portrait';
}

function documents_quote_normalize_panel_orientation(array $raw): array
{
    $defaults = documents_quote_panel_orientation_defaults();
    $o = array_merge($defaults, $raw);
    $o['enabled'] = !empty($raw['enabled']);
    $areas = documents_quote_panel_orientation_allowed_area_types();
    $areaType = safe_text((string) ($o['site_area_type'] ?? 'terrace'));
    $o['site_area_type'] = array_key_exists($areaType, $areas) ? $areaType : 'other';
    $o['site_area_label'] = safe_text((string) ($o['site_area_label'] ?? $o['roof_label'] ?? '')) ?: $areas[$o['site_area_type']];
    $o['roof_label'] = safe_text((string) ($o['roof_label'] ?? $o['site_area_label']));
    $o['north_direction'] = in_array(($o['north_direction'] ?? 'up'), ['up','right','down','left'], true) ? (string)$o['north_direction'] : 'up';
    $o['default_facing_direction'] = documents_quote_clean_orientation_direction((string) ($o['default_facing_direction'] ?? ''), 'South');
    $o['default_tilt_deg'] = documents_quote_clean_orientation_tilt($o['default_tilt_deg'] ?? '');
    $layouts = documents_quote_panel_orientation_allowed_layouts();
    $layout = safe_text((string) ($o['row_layout'] ?? 'portrait_rows'));
    $o['row_layout'] = array_key_exists($layout, $layouts) ? $layout : 'portrait_rows';
    $o['shade_note'] = safe_multiline_text(strip_tags((string) ($o['shade_note'] ?? '')));
    $o['customer_note'] = safe_multiline_text(strip_tags((string) ($o['customer_note'] ?? ''))) ?: $defaults['customer_note'];
    $o['layout_mode'] = safe_text((string)($o['layout_mode'] ?? 'generated'));
    if (!in_array($o['layout_mode'], ['generated','grid_editor'], true)) $o['layout_mode'] = 'generated';
    $o['diagram_style'] = 'freeform_roof';
    $o['uploaded_diagram_path'] = safe_text((string) ($o['uploaded_diagram_path'] ?? ''));


    $gridRaw = is_array($raw['grid'] ?? null) ? $raw['grid'] : [];
    $grid = [
        'columns' => documents_quote_int_range($gridRaw['columns'] ?? 36, 8, 100, 36),
        'rows' => documents_quote_int_range($gridRaw['rows'] ?? 24, 8, 100, 24),
        'cell_unit' => 'grid',
        'editor_cell_px' => documents_quote_int_range($gridRaw['editor_cell_px'] ?? 18, 14, 28, 18),
        'major_line_every' => documents_quote_int_range($gridRaw['major_line_every'] ?? 5, 2, 10, 5),
        'customer_grid_visible' => !empty($gridRaw['customer_grid_visible']),
        'panel_size_profile' => 'standard_module',
    ];
    $objects = [];
    $boxes = [];
    $sourceObjects = is_array($raw['objects'] ?? null) ? $raw['objects'] : [];
    foreach (array_slice($sourceObjects, 0, 300) as $object) {
        if (!is_array($object)) continue;
        $type = safe_text((string)($object['type'] ?? ''));
        if (!in_array($type, ['panel','text','obstruction','arrow'], true)) continue;
        $orientationHint = safe_text((string)($object['orientation'] ?? ''));
        if (!in_array($orientationHint, ['portrait','landscape'], true)) $orientationHint = ((int)($object['w'] ?? 0) > (int)($object['h'] ?? 0)) ? 'landscape' : 'portrait';
        $profile = $type === 'panel' ? documents_quote_panel_size_profile($orientationHint) : ['w' => 6, 'h' => 2, 'aspect' => 1];
        $defaultW = $type === 'panel' ? (int)$profile['w'] : 6;
        $defaultH = $type === 'panel' ? (int)$profile['h'] : 2;
        $w = $type === 'panel' ? $defaultW : documents_quote_int_range($object['w'] ?? $defaultW, 1, $grid['columns'], $defaultW);
        $h = $type === 'panel' ? $defaultH : documents_quote_int_range($object['h'] ?? $defaultH, 1, $grid['rows'], $defaultH);
        $x = documents_quote_int_range($object['x'] ?? 0, 0, max(0, $grid['columns'] - $w), 0);
        $y = documents_quote_int_range($object['y'] ?? 0, 0, max(0, $grid['rows'] - $h), 0);
        $blocks = in_array($type, ['panel','text','obstruction'], true);
        if ($blocks) {
            foreach ($boxes as $box) {
                if ($x < $box['x'] + $box['w'] && $x + $w > $box['x'] && $y < $box['y'] + $box['h'] && $y + $h > $box['y']) {
                    continue 2;
                }
            }
        }
        $idPrefix = $type === 'panel' ? 'panel' : ($type === 'text' ? 'text' : $type);
        $clean = ['id' => safe_text((string)($object['id'] ?? ($idPrefix . '_' . (count($objects) + 1)))), 'type' => $type, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
        if ($type === 'panel') {
            $orientation = $orientationHint;
            $panelProfile = documents_quote_panel_size_profile($orientation);
            $clean += ['orientation' => $orientation, 'rotation_deg' => $orientation === 'landscape' ? 90 : 0, 'visual_aspect_ratio' => (float)$panelProfile['aspect'], 'label' => mb_substr(safe_text((string)($object['label'] ?? (string)(count($objects) + 1))), 0, 20), 'group' => mb_substr(safe_text((string)($object['group'] ?? '')), 0, 20), 'facing_direction' => documents_quote_clean_orientation_direction((string)($object['facing_direction'] ?? ''), $o['default_facing_direction'])];
        } elseif ($type === 'text') {
            $clean['text'] = mb_substr(safe_multiline_text(strip_tags((string)($object['text'] ?? $object['label'] ?? 'Note'))), 0, 120);
        } elseif ($type === 'obstruction') {
            $clean['label'] = mb_substr(safe_text((string)($object['label'] ?? 'Keep-out')), 0, 80);
        } else {
            $clean['label'] = mb_substr(safe_text((string)($object['label'] ?? 'North')), 0, 40);
        }
        if ($blocks) $boxes[] = ['x'=>$x,'y'=>$y,'w'=>$w,'h'=>$h];
        $objects[] = $clean;
    }
    $o['grid'] = $grid;
    $o['objects'] = $objects;
    if ($objects !== []) $o['layout_mode'] = 'grid_editor';

    $sourceGroups = (array)($raw['groups'] ?? $raw['layout_groups'] ?? []);
    $groups = [];
    foreach ($sourceGroups as $group) {
        if (!is_array($group)) continue;
        $label = safe_text((string) ($group['label'] ?? ''));
        $roof = safe_text((string) ($group['roof_section'] ?? ''));
        $rowLayout = safe_text((string) ($group['row_layout'] ?? $o['row_layout']));
        if (!array_key_exists($rowLayout, $layouts)) $rowLayout = $o['row_layout'];
        $orientation = safe_text((string)($group['panel_orientation'] ?? documents_quote_panel_orientation_from_layout($rowLayout)));
        if (!in_array($orientation, ['portrait','landscape'], true)) $orientation = 'portrait';
        $rows = documents_quote_int_range($group['rows'] ?? 0, 1, 12, 1);
        $columns = documents_quote_int_range($group['columns'] ?? 0, 1, 24, max(1, (int)($group['panel_count'] ?? 4)));
        $count = max(0, (int)($group['panel_count'] ?? ($rows * $columns)));
        if ($count > 0 && empty($group['rows']) && empty($group['columns'])) { $columns = min(12, $count); $rows = (int)ceil($count / $columns); }
        $remarks = safe_multiline_text(strip_tags((string) ($group['remarks'] ?? '')));
        if ($label === '' && $roof === '' && $count === 0 && $remarks === '') continue;
        $groups[] = [
            'group_id' => safe_text((string)($group['group_id'] ?? chr(65 + min(count($groups), 25)))),
            'label' => $label ?: 'Group ' . chr(65 + min(count($groups), 25)),
            'roof_section' => $roof ?: $o['site_area_label'],
            'x' => documents_quote_int_range($group['x'] ?? (10 + count($groups) * 18), 0, 100, 10),
            'y' => documents_quote_int_range($group['y'] ?? (10 + count($groups) * 14), 0, 100, 10),
            'rows' => $rows, 'columns' => $columns, 'panel_count' => $count ?: ($rows * $columns),
            'panel_orientation' => $orientation,
            'panel_rotation_deg' => documents_quote_int_range($group['panel_rotation_deg'] ?? 0, -180, 180, 0),
            'facing_direction' => documents_quote_clean_orientation_direction((string) ($group['facing_direction'] ?? ''), $o['default_facing_direction']),
            'tilt_deg' => documents_quote_clean_orientation_tilt($group['tilt_deg'] ?? $o['default_tilt_deg']),
            'row_gap' => documents_quote_int_range($group['row_gap'] ?? 8, 0, 40, 8),
            'column_gap' => documents_quote_int_range($group['column_gap'] ?? 6, 0, 40, 6),
            'row_layout' => $rowLayout, 'remarks' => $remarks,
        ];
    }
    if ($groups === []) $groups = $defaults['groups'];
    $o['groups'] = array_slice($groups, 0, 24);
    $o['layout_groups'] = $o['groups'];
    $obs = [];
    foreach ((array)($raw['obstructions'] ?? []) as $ob) {
        if (!is_array($ob)) continue;
        $label = safe_text((string)($ob['label'] ?? ''));
        $remarks = safe_multiline_text(strip_tags((string)($ob['remarks'] ?? '')));
        if ($label === '' && $remarks === '') continue;
        $obs[] = ['label'=>$label ?: 'Keep-out area','x'=>documents_quote_int_range($ob['x'] ?? 70,0,100,70),'y'=>documents_quote_int_range($ob['y'] ?? 15,0,100,15),'width'=>documents_quote_int_range($ob['width'] ?? 12,1,100,12),'height'=>documents_quote_int_range($ob['height'] ?? 12,1,100,12),'remarks'=>$remarks];
    }
    $o['obstructions'] = array_slice($obs, 0, 12);
    $o['custom_panels'] = is_array($raw['custom_panels'] ?? null) ? array_slice((array)$raw['custom_panels'], 0, 100) : [];
    return $o;
}

function documents_quote_panel_orientation_is_enabled(array $quote): bool
{
    if (!is_array($quote['panel_orientation'] ?? null)) return false;
    $o = documents_quote_normalize_panel_orientation((array) $quote['panel_orientation']);
    return !empty($o['enabled']);
}

function documents_quote_render_panel_orientation_diagram(array $orientation): string
{
    $o = documents_quote_normalize_panel_orientation($orientation);
    $esc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    if (($o['layout_mode'] ?? '') === 'grid_editor' && !empty($o['objects'])) {
        $grid = is_array($o['grid'] ?? null) ? $o['grid'] : ['columns'=>24,'rows'=>16];
        $cols = max(8, min(100, (int)($grid['columns'] ?? 36))); $rows = max(8, min(100, (int)($grid['rows'] ?? 24)));
        $minX = $cols; $minY = $rows; $maxX = 0; $maxY = 0;
        foreach ((array)$o['objects'] as $obj) { $minX = min($minX, (int)($obj['x'] ?? 0)); $minY = min($minY, (int)($obj['y'] ?? 0)); $maxX = max($maxX, (int)($obj['x'] ?? 0) + max(1, (int)($obj['w'] ?? 1))); $maxY = max($maxY, (int)($obj['y'] ?? 0) + max(1, (int)($obj['h'] ?? 1))); }
        $pad = 2; $viewMinX = max(0, $minX - $pad); $viewMinY = max(0, $minY - $pad); $viewMaxX = min($cols, $maxX + $pad); $viewMaxY = min($rows, $maxY + $pad);
        if ($viewMaxX <= $viewMinX || $viewMaxY <= $viewMinY) { $viewMinX = 0; $viewMinY = 0; $viewMaxX = $cols; $viewMaxY = $rows; }
        $viewCols = max(1, $viewMaxX - $viewMinX); $viewRows = max(1, $viewMaxY - $viewMinY); $cw = 500 / $viewCols; $ch = 240 / $viewRows; $cell = min($cw, $ch); $offsetX = 30 + (500 - ($viewCols * $cell)) / 2; $offsetY = 45 + (240 - ($viewRows * $cell)) / 2; $svg = '';
        foreach ((array)$o['objects'] as $obj) {
            $x = $offsetX + (((int)$obj['x'] - $viewMinX) * $cell); $y = $offsetY + (((int)$obj['y'] - $viewMinY) * $cell); $w = max(1,(int)$obj['w']) * $cell; $h = max(1,(int)$obj['h']) * $cell;
            if (($obj['type'] ?? '') === 'panel') { $aspect = max(0.2, min(5.0, (float)($obj['visual_aspect_ratio'] ?? documents_quote_panel_size_profile((string)($obj['orientation'] ?? 'portrait'))['aspect']))); $availW = max(1, $w - 2); $availH = max(1, $h - 2); $vw = $availW; $vh = $vw / $aspect; if ($vh > $availH) { $vh = $availH; $vw = $vh * $aspect; } $vx = $x + ($w - $vw) / 2; $vy = $y + ($h - $vh) / 2; $svg .= '<g><rect x="'.$vx.'" y="'.$vy.'" width="'.$vw.'" height="'.$vh.'" rx="4" fill="#0f766e" stroke="#064e3b" stroke-width="1.4"/><text x="'.($vx+$vw/2).'" y="'.($vy+$vh/2+4).'" text-anchor="middle" font-size="13" font-weight="700" fill="#ecfeff">'.$esc($obj['label'] ?? '').'</text></g>'; }
            elseif (($obj['type'] ?? '') === 'text') $svg .= '<foreignObject x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'"><div xmlns="http://www.w3.org/1999/xhtml" style="font:700 12px Arial;color:#334155;overflow:hidden;line-height:1.2">'.$esc($obj['text'] ?? '').'</div></foreignObject>';
            elseif (($obj['type'] ?? '') === 'obstruction') $svg .= '<g><rect x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" rx="4" fill="#fee2e2" stroke="#dc2626" stroke-dasharray="5 4"/><text x="'.($x+5).'" y="'.($y+16).'" font-size="11" fill="#991b1b">'.$esc($obj['label'] ?? 'Keep-out').'</text></g>';
            elseif (($obj['type'] ?? '') === 'arrow') $svg .= '<g><line x1="'.($x+$w/2).'" y1="'.($y+$h).'" x2="'.($x+$w/2).'" y2="'.$y.'" stroke="#dc2626" stroke-width="3"/><polygon points="'.($x+$w/2).','.($y-7).' '.($x+$w/2-7).','.($y+7).' '.($x+$w/2+7).','.($y+7).'" fill="#dc2626"/><text x="'.($x+$w/2-6).'" y="'.($y-12).'" font-size="14" font-weight="700" fill="#dc2626">N</text></g>';
        }
        return '<div class="panel-orientation-diagram"><svg viewBox="0 0 560 330" role="img" aria-label="Solar panel grid layout diagram" xmlns="http://www.w3.org/2000/svg"><defs><pattern id="grid" width="'.$cell.'" height="'.$cell.'" patternUnits="userSpaceOnUse"><path d="M '.$cell.' 0 L 0 0 0 '.$cell.'" fill="none" stroke="#e2e8f0" stroke-width="1"/></pattern></defs><rect x="30" y="45" width="500" height="240" rx="16" fill="#f8fafc" stroke="#94a3b8" stroke-width="2"/>'.(!empty($grid['customer_grid_visible']) ? '<rect x="30" y="45" width="500" height="240" fill="url(#grid)" opacity=".22"/>' : '').''.$svg.'<text x="42" y="315" font-size="11" fill="#475569">Grid layout: '.$esc($o['site_area_label']).' · facing '.$esc($o['default_facing_direction']).'</text></svg></div>';
    }
    $groups = (array) ($o['groups'] ?? []);
    $sx = static fn($v): int => 40 + (int)round(max(0, min(100, (float)$v)) * 4.8);
    $sy = static fn($v): int => 55 + (int)round(max(0, min(100, (float)$v)) * 2.25);
    $panelSvg = '';
    foreach ($groups as $i => $g) {
        $baseX = $sx($g['x'] ?? 10); $baseY = $sy($g['y'] ?? 10);
        $rows = max(1, (int)($g['rows'] ?? 1)); $cols = max(1, (int)($g['columns'] ?? 1));
        $maxPanels = max(1, min($rows * $cols, (int)($g['panel_count'] ?? ($rows*$cols))));
        $portrait = ($g['panel_orientation'] ?? 'portrait') !== 'landscape';
        $pw = $portrait ? 16 : 26; $ph = $portrait ? 26 : 16;
        $cg = max(1, (int)($g['column_gap'] ?? 6)); $rg = max(1, (int)($g['row_gap'] ?? 8));
        $blocks = '';
        for ($pnl = 0; $pnl < $maxPanels; $pnl++) {
            $c = $pnl % $cols; $r = intdiv($pnl, $cols);
            $px = $baseX + $c * ($pw + $cg); $py = $baseY + $r * ($ph + $rg);
            $blocks .= '<rect x="'.$px.'" y="'.$py.'" width="'.$pw.'" height="'.$ph.'" rx="2" fill="#0f766e" stroke="#064e3b" stroke-width="1" opacity=".9"/>';
            if ($pw >= 20) $blocks .= '<line x1="'.($px+$pw/2).'" y1="'.($py+2).'" x2="'.($px+$pw/2).'" y2="'.($py+$ph-2).'" stroke="#ccfbf1" stroke-width=".7"/>';
        }
        $labelY = $baseY + $rows * ($ph + $rg) + 13;
        $panelSvg .= '<g><title>'.$esc(($g['label'] ?? '').' '.$maxPanels.' panels').'</title>'.$blocks.'<text x="'.$baseX.'" y="'.$labelY.'" font-size="11" font-weight="700" fill="#0f172a">'.$esc($g['label'] ?? ('Group '.($i+1))).'</text><text x="'.$baseX.'" y="'.($labelY+13).'" font-size="10" fill="#475569">'.$esc(($g['roof_section'] ?? '').' · '.($g['facing_direction'] ?? '').(trim((string)($g['tilt_deg'] ?? ''))!==''?' · '.$g['tilt_deg'].'°':'')).'</text></g>';
    }
    $obSvg = '';
    foreach ((array)($o['obstructions'] ?? []) as $ob) {
        $x=$sx($ob['x']??70); $y=$sy($ob['y']??15); $w=max(8,(int)round(((float)($ob['width']??12))*4.8)); $h=max(8,(int)round(((float)($ob['height']??12))*2.25));
        $obSvg .= '<g><rect x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" rx="4" fill="#fee2e2" stroke="#dc2626" stroke-dasharray="5 4"/><text x="'.($x+4).'" y="'.($y+15).'" font-size="10" fill="#991b1b">'.$esc($ob['label'] ?? 'Keep-out').'</text></g>';
    }
    return '<div class="panel-orientation-diagram"><svg viewBox="0 0 560 330" role="img" aria-label="Solar panel orientation layout diagram" xmlns="http://www.w3.org/2000/svg"><rect x="25" y="45" width="510" height="245" rx="16" fill="#f8fafc" stroke="#94a3b8" stroke-width="2"/><text x="42" y="70" font-size="13" fill="#475569">Roof / terrace layout: '.$esc($o['site_area_label']).'</text><g><line x1="500" y1="82" x2="500" y2="36" stroke="#dc2626" stroke-width="3"/><polygon points="500,28 493,42 507,42" fill="#dc2626"/><text x="489" y="22" font-size="18" font-weight="700" fill="#dc2626">N</text></g>'.$panelSvg.$obSvg.'<defs><marker id="arrow" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="#f59e0b"/></marker></defs><line x1="260" y1="275" x2="260" y2="235" stroke="#f59e0b" stroke-width="3" marker-end="url(#arrow)"/><text x="278" y="255" font-size="12" fill="#92400e">Typical facing: '.$esc($o['default_facing_direction']).'</text><text x="42" y="315" font-size="11" fill="#475569">Legend: green rectangles = panels; dashed red = obstruction/keep-out; red arrow = North; yellow arrow = typical panel facing.</text></svg></div>';
}

function documents_quote_prepare(array $quote): array
{
    $original = $quote;
    $quote = array_merge(documents_quote_defaults(), $quote);
    $quote['status'] = documents_quote_normalize_status((string) ($quote['status'] ?? 'draft'));
    $quote['workflow'] = array_merge(documents_quote_workflow_defaults(), is_array($quote['workflow'] ?? null) ? $quote['workflow'] : []);
    $quote['main_solar_kwp'] = safe_text((string) ($quote['main_solar_kwp'] ?? ''));
    $quote['complimentary_non_dcr_kwp'] = safe_text((string) ($quote['complimentary_non_dcr_kwp'] ?? ''));
    $quote['capacity_kwp'] = safe_text((string) ($quote['capacity_kwp'] ?? ''));
    $quote['system_capacity_kwp'] = max(0, (float) ($quote['system_capacity_kwp'] ?? $quote['capacity_kwp'] ?? 0));
    $quote['quote_items'] = documents_normalize_quote_structured_items(is_array($quote['quote_items'] ?? null) ? $quote['quote_items'] : []);
    $quote['tax_profile_id'] = safe_text((string) ($quote['tax_profile_id'] ?? ''));
    $quote['show_tax_breakup'] = !array_key_exists('show_tax_breakup', $original)
        ? true
        : (bool) ($quote['show_tax_breakup'] ?? false);
    $quote['gst_mode_snapshot'] = safe_text((string) ($quote['gst_mode_snapshot'] ?? ''));
    $quote['gst_slabs_snapshot'] = is_array($quote['gst_slabs_snapshot'] ?? null) ? $quote['gst_slabs_snapshot'] : [];
    $quote['calc'] = is_array($quote['calc'] ?? null) ? $quote['calc'] : [];
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
    $quote['panel_orientation'] = documents_quote_normalize_panel_orientation(is_array($quote['panel_orientation'] ?? null) ? $quote['panel_orientation'] : []);
    $quote['important_points_snapshot'] = is_array($quote['important_points_snapshot'] ?? null) ? documents_quote_normalize_important_points_settings($quote['important_points_snapshot']) : [];
    $quote = documents_quote_normalize_editable_finance_values($quote);
    $quote['revision_child_ids'] = array_values(array_filter(array_map(static fn($v): string => safe_text((string) $v), is_array($quote['revision_child_ids'] ?? null) ? $quote['revision_child_ids'] : []), static fn(string $v): bool => $v !== ''));
    $legacyShare = is_array($quote['share'] ?? null) ? $quote['share'] : [];
    $quote['public_share_enabled'] = (bool) ($quote['public_share_enabled'] ?? $legacyShare['public_enabled'] ?? false);
    $quote['public_share_token'] = safe_text((string) ($quote['public_share_token'] ?? $legacyShare['public_token'] ?? ''));
    $quote['public_share_created_at'] = safe_text((string) ($quote['public_share_created_at'] ?? $legacyShare['public_created_at'] ?? ''));
    $revokedAt = safe_text((string) ($quote['public_share_revoked_at'] ?? ''));
    $quote['public_share_revoked_at'] = $revokedAt === '' ? null : $revokedAt;
    $expiresAt = safe_text((string) ($quote['public_share_expires_at'] ?? ''));
    $quote['public_share_expires_at'] = $expiresAt === '' ? null : $expiresAt;
    unset($quote['share']);
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

function documents_generate_quote_public_share_token(int $bytes = 24): string
{
    $bytes = max(16, $bytes);
    do {
        $token = bin2hex(random_bytes($bytes));
    } while (documents_get_quote_by_public_share_token($token) !== null);

    return $token;
}

function documents_quote_reset_clone_state(array $quote, string $newId): array
{
    $sourceId = safe_text((string) ($quote['id'] ?? ''));
    $sourceNo = safe_text((string) ($quote['quote_no'] ?? ''));
    foreach (['customer_acceptance','customer_acceptance_request','customer_change_request','acceptance_reference','acceptance_ref','acceptance_token','acceptance_token_hash','acceptance_hash','agreement','dispatch_advice','packing_list','challan','invoice','receipt','whatsapp_verification','whatsapp_verified_at','whatsapp_verified_by','change_request_history','revision_history','customer_visible_history','public_history','history','audit_timeline','revised_from_quote_no','revision_parent_id','revision_root_id','generated_draft_revision_id','generated_draft_revision_no','superseded_by_quote_id','superseded_by_quote_no','approved_at','approved_by'] as $field) unset($quote[$field]);
    $quote['status']='draft'; $quote['approval']=['approved_by_id'=>'','approved_by_name'=>'','approved_at'=>''];
    $quote['accepted_at']=''; $quote['accepted_by']=['type'=>'','id'=>'','name'=>''];
    $quote['acceptance']=['accepted_by_admin_id'=>'','accepted_by_admin_name'=>'','accepted_at'=>'','accepted_note'=>''];
    $quote['customer_visible_change_history']=[];
    $quote['admin_change_history']=[[
        'event'=>'clone_created',
        'message'=>'Quotation created as a new draft from an existing quotation.',
        'recorded_at'=>date('c'),
        'visible_to_customer'=>false,
        'source_quote_id'=>$sourceId,
        'source_quote_no'=>$sourceNo,
    ]];
    $quote['cloned_from_quote_id']=$sourceId;
    $quote['cloned_from_quote_no']=$sourceNo;
    $quote['cloned_at']=date('c');
    $quote['locked_flag']=false; $quote['locked_at']=null; $quote['workflow']=documents_quote_workflow_defaults();
    $quote['links']=['customer_mobile'=>'','agreement_id'=>'','proforma_id'=>'','invoice_id'=>''];
    $quote['public_share_token']=''; $quote['public_share_enabled']=false;
    $quote['public_share_created_at']=''; $quote['public_share_revoked_at']=null; $quote['public_share_expires_at']=null;
    $quote['quote_series_id']=$newId; $quote['version_no']=1; $quote['is_current_version']=true;
    $quote['revised_from_quote_id']=null; $quote['revision_reason']=null; $quote['revision_child_ids']=[];
    $quote = documents_quote_snapshot_cover_note_presentation($quote);
    return documents_quote_normalize_editable_finance_values($quote);
}

function documents_repair_cloned_quote_history_leaks(): int
{
    $repaired = 0;
    foreach (documents_list_quotes() as $quote) {
        $quote = documents_quote_prepare($quote);
        if (safe_text((string) ($quote['cloned_from_quote_id'] ?? '')) === '' && safe_text((string) ($quote['cloned_from_quote_no'] ?? '')) === '') {
            continue;
        }
        if (documents_quote_is_locked($quote) || documents_quote_normalize_status((string) ($quote['status'] ?? 'draft')) === 'accepted') {
            continue;
        }
        $history = is_array($quote['customer_visible_change_history'] ?? null) ? $quote['customer_visible_change_history'] : [];
        if ($history === []) {
            continue;
        }
        $quote['customer_visible_change_history'] = [];
        foreach (['customer_visible_history','public_history','change_request_history','revision_history','history','audit_timeline'] as $field) {
            if (array_key_exists($field, $quote)) {
                unset($quote[$field]);
            }
        }
        $quote = documents_quote_admin_history_add($quote, 'clone_history_repaired', 'Removed source quotation customer-visible history from cloned quotation.', [
            'repaired_at' => date('c'),
            'source_quote_id' => safe_text((string) ($quote['cloned_from_quote_id'] ?? '')),
            'source_quote_no' => safe_text((string) ($quote['cloned_from_quote_no'] ?? '')),
        ]);
        $quote['updated_at'] = date('c');
        $saved = documents_save_quote($quote);
        if (($saved['ok'] ?? false)) {
            $repaired++;
        }
    }
    return $repaired;
}

function documents_quote_number_exists(string $quoteNo, string $exceptId = ''): bool
{
    foreach (documents_list_quotes() as $quote) {
        if ((string) ($quote['id'] ?? '') !== $exceptId && (string) ($quote['quote_no'] ?? '') === $quoteNo) return true;
    }
    return false;
}

/** @return array{ok:bool,error:string,quote?:array,source?:array} */
function documents_create_quote_revision(array $source, string $reason, string $changeRequestRef = ''): array
{
    $source = documents_quote_prepare($source);
    $sourceId = safe_text((string) ($source['id'] ?? ''));
    if ($sourceId === '') return ['ok' => false, 'error' => 'Missing source quotation.'];

    $existingRef = safe_text($changeRequestRef);
    foreach (documents_list_quotes() as $quote) {
        if (($existingRef !== '' && (string) ($quote['generated_from_change_request_ref'] ?? '') === $existingRef)
            || ((string) ($quote['revised_from_quote_id'] ?? '') === $sourceId && (string) ($quote['status'] ?? '') === 'draft' && $existingRef !== '')) {
            return ['ok' => true, 'error' => '', 'quote' => $quote, 'source' => $source];
        }
    }

    $number = [];
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $number = documents_generate_quote_number((string) ($source['segment'] ?? 'RES'));
        if (!($number['ok'] ?? false) || !documents_quote_number_exists((string) ($number['quote_no'] ?? ''))) break;
    }
    if (!($number['ok'] ?? false) || documents_quote_number_exists((string) ($number['quote_no'] ?? ''))) {
        return ['ok' => false, 'error' => (string) ($number['error'] ?? 'Unable to generate a unique quotation number.')];
    }

    $now = date('c');
    $newId = 'qtn_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    $draft = documents_quote_reset_clone_state($source, $newId);
    $draft['id'] = $newId;
    $draft['quote_no'] = (string) $number['quote_no'];
    $draft['quote_series_id'] = (string) ($source['quote_series_id'] ?? $sourceId);
    $draft['version_no'] = (int) ($source['version_no'] ?? 1) + 1;
    $draft['is_current_version'] = true;
    $draft['revised_from_quote_id'] = $sourceId;
    $draft['revised_from_quote_no'] = (string) ($source['quote_no'] ?? '');
    $draft['revision_reason'] = trim($reason) ?: null;
    $draft['generated_from_change_request_ref'] = $existingRef;
    $draft = documents_quote_append_customer_visible_history($draft, 'revision_created', 'A revised quotation draft was created for your requested changes.', ['request_ref' => $existingRef]);
    $draft['created_at'] = $now;
    $draft['updated_at'] = $now;

    $source['status'] = 'update_requested';
    $source = documents_quote_append_customer_visible_history($source, 'update_requested', 'Customer requested changes. A revised quotation is being prepared.', ['request_ref' => $existingRef]);
    $source['is_current_version'] = false;
    $source['superseded_by_quote_id'] = $newId;
    $source['superseded_by_quote_no'] = $draft['quote_no'];
    $source['revision_child_ids'] = array_values(array_unique(array_merge((array) ($source['revision_child_ids'] ?? []), [$newId])));
    $source['updated_at'] = $now;
    if (!documents_save_quote($draft)['ok'] || !documents_save_quote($source)['ok']) return ['ok' => false, 'error' => 'Unable to save quotation revision.'];
    return ['ok' => true, 'error' => '', 'quote' => $draft, 'source' => $source];
}

/** Repairs the narrowly-defined broken customer revisions created by the legacy flow. */
function documents_repair_broken_quote_revisions(): array
{
    $repaired = [];
    foreach (documents_list_quotes() as $draft) {
        $sourceId = safe_text((string) ($draft['revised_from_quote_id'] ?? ''));
        if ($sourceId === '' || (string) ($draft['status'] ?? '') !== 'draft' || !empty($draft['is_current_version']) || safe_text((string) ($draft['generated_from_change_request_ref'] ?? '')) !== '') continue;
        $source = documents_get_quote($sourceId);
        if (!$source || (string) ($draft['quote_no'] ?? '') !== (string) ($source['quote_no'] ?? '') || empty($source['is_current_version'])) continue;
        $number = documents_generate_quote_number((string) ($draft['segment'] ?? 'RES'));
        if (!($number['ok'] ?? false) || documents_quote_number_exists((string) ($number['quote_no'] ?? ''))) continue;
        $draft['quote_no'] = (string) $number['quote_no'];
        $draft['is_current_version'] = true;
        $draft['revised_from_quote_no'] = (string) ($source['quote_no'] ?? '');
        $draft['generated_from_change_request_ref'] = 'LEGACY-REPAIR-' . $sourceId;
        $source['is_current_version'] = false;
        $source['superseded_by_quote_id'] = (string) $draft['id'];
        $source['superseded_by_quote_no'] = (string) $draft['quote_no'];
        if (documents_save_quote($draft)['ok'] && documents_save_quote($source)['ok']) $repaired[] = (string) $draft['id'];
    }
    if ($repaired !== []) documents_log('Repaired broken quotation revisions: ' . implode(', ', $repaired));
    return $repaired;
}

function documents_get_quote_by_public_share_token(string $token): ?array
{
    $token = safe_text($token);
    if ($token === '') {
        return null;
    }

    foreach (documents_list_quotes() as $quote) {
        if (hash_equals((string) ($quote['public_share_token'] ?? ''), $token)) {
            return $quote;
        }
    }

    return null;
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
        $quote['workflow']['invoice_ids'] = array_values(array_unique(array_merge((array) $quote['workflow']['invoice_ids'], [$id])));
    } elseif ($type === 'dispatch_advice') {
        $quote['workflow']['dispatch_advice_id'] = $id;
        $quote['workflow']['dispatch_advice_ids'] = array_values(array_unique(array_merge((array) $quote['workflow']['dispatch_advice_ids'], [$id])));
    } elseif ($type === 'challan') {
        $quote['workflow']['challan_id'] = $id;
        $quote['workflow']['challan_ids'] = array_values(array_unique(array_merge((array) $quote['workflow']['challan_ids'], [$id])));
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

function documents_dispatch_advices_for_quote(string $quoteId): array
{
    return array_values(array_filter(documents_list_dispatch_advices(), static fn(array $row): bool =>
        (string) ($row['quotation_id'] ?? '') === $quoteId && !in_array((string) ($row['status'] ?? ''), ['archived', 'cancelled', 'superseded'], true)
    ));
}

function documents_challans_for_quote(string $quoteId): array
{
    return array_values(array_filter(documents_list_challans(), static fn(array $row): bool =>
        (string) ($row['quote_id'] ?? $row['linked_quote_id'] ?? '') === $quoteId && empty($row['archived_flag']) && (string) ($row['status'] ?? '') !== 'archived'
    ));
}

function documents_delivered_challans_for_quote(string $quoteId): array
{
    $rows = array_values(array_filter(documents_challans_for_quote($quoteId), static fn(array $row): bool =>
        in_array((string) ($row['delivery_status'] ?? ''), ['admin_delivered', 'completed'], true)
    ));
    usort($rows, static fn(array $a, array $b): int => strcmp(
        (string) ($a['admin_delivery_confirmation']['delivered_at'] ?? $a['delivery_date'] ?? '') . (string) ($a['challan_no'] ?? ''),
        (string) ($b['admin_delivery_confirmation']['delivered_at'] ?? $b['delivery_date'] ?? '') . (string) ($b['challan_no'] ?? '')
    ));
    return $rows;
}

function documents_quote_delivery_summary(string $quoteId): array
{
    $challans = documents_challans_for_quote($quoteId);
    $completed = documents_delivered_challans_for_quote($quoteId);
    $customerConfirmed = array_filter($challans, static fn(array $c): bool => !empty($c['customer_receipt']['confirmed_at']) || !empty($c['customer_acceptance']['accepted_at']));
    $exceptions = array_filter($challans, static fn(array $c): bool => !empty($c['delivery_exception']) || (string) ($c['delivery_status'] ?? '') === 'exception');
    return [
        'status' => $exceptions ? 'exception' : (!$completed ? 'not_started' : (count($completed) === count($challans) ? 'delivered' : 'partial')),
        'dispatch_advice_count' => count(documents_dispatch_advices_for_quote($quoteId)),
        'challan_count' => count($challans),
        'completed_challan_count' => count($completed),
        'customer_confirmed_count' => count($customerConfirmed),
        'has_exception' => (bool) $exceptions,
        'last_delivery_at' => $completed ? (string) (end($completed)['admin_delivery_confirmation']['delivered_at'] ?? end($completed)['delivery_date'] ?? '') : '',
    ];
}

function documents_update_draft_invoice_delivery(array $invoice, string $quoteId): array
{
    if (strtolower((string) ($invoice['status'] ?? 'draft')) !== 'draft') {
        return ['ok' => false, 'error' => 'Issued/finalized invoices cannot be updated silently.', 'invoice' => $invoice];
    }
    $challans = documents_delivered_challans_for_quote($quoteId);
    $invoice['linked_challan_ids'] = array_values(array_unique(array_map(static fn(array $c): string => (string) $c['id'], $challans)));
    $invoice['linked_dispatch_advice_ids'] = array_values(array_unique(array_filter(array_map(static fn(array $c): string => (string) ($c['dispatch_advice_id'] ?? ''), $challans))));
    $invoice['delivery_details'] = array_map(static fn(array $c): array => [
        'challan_id' => $c['id'], 'challan_no' => $c['challan_no'] ?? $c['dc_number'] ?? '', 'delivery_date' => $c['delivery_date'] ?? '',
        'dispatch_advice_no' => $c['dispatch_advice_no'] ?? '', 'delivery_status' => $c['delivery_status'] ?? '', 'customer_receipt' => $c['customer_receipt'] ?? [],
        'delivery_exception' => $c['delivery_exception'] ?? [], 'items' => $c['items'] ?? [],
    ], $challans);
    $invoice['delivery_summary'] = documents_quote_delivery_summary($quoteId);
    if (!is_array($invoice['quotation_snapshot'] ?? null) || $invoice['quotation_snapshot'] === []) {
        $quote = documents_get_quote($quoteId);
        if (is_array($quote)) {
            $invoice['quotation_snapshot'] = documents_invoice_quote_snapshot($quote);
            $invoice['tax_breakdown'] = is_array($invoice['quotation_snapshot']['tax_breakdown'] ?? null) ? $invoice['quotation_snapshot']['tax_breakdown'] : [];
            $invoice = documents_invoice_normalize_commercial_snapshot($invoice);
        }
    }
    $invoice['updated_at'] = date('c');
    return ['ok' => true, 'error' => '', 'invoice' => $invoice];
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
        'update_requested' => 'Update Requested',
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
        'inventory_tracked' => false,
        'standard_length_ft' => 0,
        'min_issue_ft' => 1,
        'description' => '',
        'notes' => '',
        'archived_flag' => false,
        'created_at' => '',
        'updated_at' => '',
    ];
}

function documents_inventory_component_is_tracked(array $component): bool
{
    return !empty($component['is_cuttable']);
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

function documents_inventory_kit_bom_line_defaults(): array
{
    return [
        'line_id' => '',
        'component_id' => '',
        'mode' => 'fixed_qty',
        'unit' => '',
        'fixed_qty' => 0,
        'capacity_rule' => [
            'type' => 'formula',
            'expr' => 'kwp * 1',
            'slabs' => [],
        ],
        'rule' => [
            'rule_type' => 'min_total_wp',
            'target_expr' => 'kwp * 1000',
            'allow_overbuild_pct' => 0,
            'requires_variants' => true,
        ],
        'manual_note' => '',
        'remarks' => '',
    ];
}

function documents_normalize_kit_bom_line(array $line, ?array $component = null): array
{
    $legacyQty = (float) ($line['qty'] ?? 0);
    $defaults = documents_inventory_kit_bom_line_defaults();
    $normalized = array_merge($defaults, $line);
    $normalized['component_id'] = safe_text((string) ($normalized['component_id'] ?? ''));
    if ($normalized['line_id'] === '') {
        $normalized['line_id'] = 'kline_' . bin2hex(random_bytes(4));
    }

    $mode = safe_text((string) ($normalized['mode'] ?? ''));
    if ($mode === '') {
        $mode = $legacyQty > 0 ? 'fixed_qty' : 'fixed_qty';
    }
    if (!in_array($mode, ['fixed_qty', 'capacity_qty', 'rule_fulfillment', 'unfixed_manual'], true)) {
        $mode = 'fixed_qty';
    }
    $normalized['mode'] = $mode;

    $componentUnit = is_array($component)
        ? (!empty($component['is_cuttable']) ? 'ft' : ((string) ($component['default_unit'] ?? 'pcs')))
        : 'pcs';
    $unit = safe_text((string) ($normalized['unit'] ?? ''));
    if ($unit === '') {
        $unit = safe_text((string) ($line['unit'] ?? '')) ?: $componentUnit;
    }
    if (is_array($component) && !empty($component['is_cuttable'])) {
        $unit = 'ft';
    }
    $normalized['unit'] = $unit;

    $normalized['fixed_qty'] = max(0, (float) (($normalized['fixed_qty'] ?? 0) ?: $legacyQty));

    $capacityRule = is_array($normalized['capacity_rule'] ?? null) ? $normalized['capacity_rule'] : [];
    $capacityRule = array_merge($defaults['capacity_rule'], $capacityRule);
    $capacityType = safe_text((string) ($capacityRule['type'] ?? 'formula'));
    if (!in_array($capacityType, ['formula', 'slab'], true)) {
        $capacityType = 'formula';
    }
    $capacityRule['type'] = $capacityType;
    $capacityRule['expr'] = safe_text((string) ($capacityRule['expr'] ?? 'kwp * 1'));
    $slabs = [];
    foreach ((array) ($capacityRule['slabs'] ?? []) as $slab) {
        if (!is_array($slab)) {
            continue;
        }
        $slabs[] = [
            'kwp_min' => (float) ($slab['kwp_min'] ?? 0),
            'kwp_max' => (float) ($slab['kwp_max'] ?? 0),
            'qty' => max(0, (float) ($slab['qty'] ?? 0)),
        ];
    }
    $capacityRule['slabs'] = $slabs;
    $normalized['capacity_rule'] = $capacityRule;

    $rule = is_array($normalized['rule'] ?? null) ? $normalized['rule'] : [];
    $rule = array_merge($defaults['rule'], $rule);
    $rule['rule_type'] = safe_text((string) ($rule['rule_type'] ?? 'min_total_wp'));
    if ($rule['rule_type'] === '') {
        $rule['rule_type'] = 'min_total_wp';
    }
    $rule['target_expr'] = safe_text((string) ($rule['target_expr'] ?? 'kwp * 1000'));
    $rule['allow_overbuild_pct'] = max(0, (float) ($rule['allow_overbuild_pct'] ?? 0));
    $rule['requires_variants'] = (bool) ($rule['requires_variants'] ?? true);
    $normalized['rule'] = $rule;

    $normalized['manual_note'] = safe_text((string) ($normalized['manual_note'] ?? ''));
    $normalized['remarks'] = safe_text((string) ($normalized['remarks'] ?? ($line['remarks'] ?? '')));
    return $normalized;
}

function documents_evaluate_safe_expression(string $expr, float $kwp): array
{
    $cleanExpr = strtolower(trim($expr));
    if ($cleanExpr === '') {
        return ['ok' => false, 'error' => 'Expression is required', 'value' => 0.0];
    }
    if (!preg_match('/^[0-9\s\.\+\-\*\/\(\)kwp]+$/', $cleanExpr)) {
        return ['ok' => false, 'error' => 'Expression contains invalid characters', 'value' => 0.0];
    }

    preg_match_all('/kwp|\d*\.?\d+|[\+\-\*\/\(\)]/', $cleanExpr, $matches);
    $tokens = $matches[0] ?? [];
    if ($tokens === []) {
        return ['ok' => false, 'error' => 'Expression is empty', 'value' => 0.0];
    }

    $output = [];
    $ops = [];
    $prec = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];
    $prevToken = '';
    foreach ($tokens as $token) {
        if ($token === 'kwp' || preg_match('/^\d*\.?\d+$/', $token)) {
            $output[] = $token === 'kwp' ? (string) $kwp : $token;
        } elseif (isset($prec[$token])) {
            if (($prevToken === '' || isset($prec[$prevToken]) || $prevToken === '(') && $token === '-') {
                $output[] = '0';
            }
            while ($ops !== []) {
                $top = end($ops);
                if ($top === '(' || !isset($prec[$top]) || $prec[$top] < $prec[$token]) {
                    break;
                }
                $output[] = array_pop($ops);
            }
            $ops[] = $token;
        } elseif ($token === '(') {
            $ops[] = $token;
        } elseif ($token === ')') {
            $matched = false;
            while ($ops !== []) {
                $top = array_pop($ops);
                if ($top === '(') {
                    $matched = true;
                    break;
                }
                $output[] = $top;
            }
            if (!$matched) {
                return ['ok' => false, 'error' => 'Mismatched parentheses', 'value' => 0.0];
            }
        }
        $prevToken = $token;
    }
    while ($ops !== []) {
        $op = array_pop($ops);
        if ($op === '(' || $op === ')') {
            return ['ok' => false, 'error' => 'Mismatched parentheses', 'value' => 0.0];
        }
        $output[] = $op;
    }

    $stack = [];
    foreach ($output as $token) {
        if (isset($prec[$token])) {
            if (count($stack) < 2) {
                return ['ok' => false, 'error' => 'Invalid expression', 'value' => 0.0];
            }
            $b = (float) array_pop($stack);
            $a = (float) array_pop($stack);
            if ($token === '/' && abs($b) < 0.0000001) {
                return ['ok' => false, 'error' => 'Division by zero', 'value' => 0.0];
            }
            if ($token === '+') {
                $stack[] = $a + $b;
            } elseif ($token === '-') {
                $stack[] = $a - $b;
            } elseif ($token === '*') {
                $stack[] = $a * $b;
            } else {
                $stack[] = $a / $b;
            }
        } else {
            $stack[] = (float) $token;
        }
    }

    if (count($stack) !== 1) {
        return ['ok' => false, 'error' => 'Invalid expression', 'value' => 0.0];
    }

    return ['ok' => true, 'error' => '', 'value' => (float) $stack[0]];
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
        'variant_id' => '',
        'variant_name_snapshot' => '',
        'qty' => 0,
        'unit' => '',
        'length_ft' => 0,
        'lot_consumption' => [],
        'batch_consumption' => [],
        'batch_ids' => [],
        'location_consumption' => [],
        'lots_created' => [],
        'batches_created' => [],
        'location_id' => '',
        'consume_location_id' => '',
        'from_location_id' => '',
        'to_location_id' => '',
        'ref_type' => 'manual',
        'ref_id' => '',
        'reason' => '',
        'notes' => '',
        'created_at' => '',
        'created_by' => ['role' => '', 'id' => '', 'name' => ''],
        'updated_at' => '',
        'updated_by' => ['role' => '', 'id' => '', 'name' => ''],
        'edit_history' => [],
        'archived_flag' => false,
        'archived_at' => '',
        'archived_by' => ['role' => '', 'id' => '', 'name' => ''],
        'voided_flag' => false,
        'voided_at' => '',
        'voided_by' => ['role' => '', 'id' => '', 'name' => ''],
        'reverses_txn_id' => '',
        'reversed_by_txn_id' => '',
    ];
}

function documents_inventory_component_entry_defaults(): array
{
    return ['on_hand_qty' => 0, 'location_breakdown' => [], 'lots' => [], 'batches' => [], 'updated_at' => ''];
}

function documents_inventory_verification_defaults(): array
{
    return [
        'txn_id' => '',
        'txn_type' => 'IN',
        'created_by' => ['role' => '', 'id' => '', 'name' => ''],
        'created_at' => '',
        'status' => 'not_verified',
        'admin_note' => '',
        'verified_by' => null,
        'verified_at' => null,
    ];
}

function documents_inventory_location_defaults(): array
{
    return [
        'id' => '',
        'name' => '',
        'type' => '',
        'notes' => '',
        'archived_flag' => false,
        'created_at' => '',
        'updated_at' => '',
    ];
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

function documents_packing_required_line_defaults(): array
{
    return [
        'line_id' => '',
        'component_id' => '',
        'component_name_snapshot' => '',
        'unit' => 'pcs',
        'mode' => 'fixed_qty',
        'required_qty' => 0,
        'required_ft' => 0,
        'dispatched_qty' => 0,
        'dispatched_ft' => 0,
        'pending_qty' => 0,
        'pending_ft' => 0,
        'rule_type' => '',
        'target_wp' => 0,
        'dispatched_wp' => 0,
        'fulfilled_flag' => false,
        'allow_overbuild_pct' => 0,
        'dispatch_variant_breakdown' => [],
        'planned_note' => '',
        'dispatched_summary' => '',
        'remarks' => '',
        'source_kit_id' => '',
        'source_kit_name_snapshot' => '',
    ];
}

function documents_quote_system_capacity_kwp(array $quote): float
{
    $primary = (float) ($quote['system_capacity_kwp'] ?? 0);
    if ($primary > 0) {
        return $primary;
    }
    return max(0, (float) ($quote['capacity_kwp'] ?? 0));
}

function documents_quote_structured_item_defaults(): array
{
    return [
        'type' => 'component',
        'kit_id' => '',
        'component_id' => '',
        'name_snapshot' => '',
        'description_snapshot' => '',
        'master_description_snapshot' => '',
        'auto_description' => '',
        'custom_description' => '',
        'description_mode' => 'auto',
        'hsn_snapshot' => '',
        'qty' => 0,
        'unit' => '',
        'variant_id' => '',
        'variant_snapshot' => [],
        'meta' => [],
    ];
}

function documents_quote_normalize_system_type(string $value): string
{
    $normalized = strtolower(trim(str_replace(['-', ' '], '_', $value)));
    if (in_array($normalized, ['hybrid', 'hyb'], true)) {
        return 'hybrid';
    }
    if (in_array($normalized, ['ongrid', 'on_grid', 'on__grid'], true)) {
        return 'ongrid';
    }
    return $normalized !== '' ? $normalized : 'ongrid';
}

function documents_quote_system_type_label(string $value): string
{
    return documents_quote_normalize_system_type($value) === 'hybrid' ? 'Hybrid' : 'Ongrid';
}

function documents_quote_known_system_kit_name(string $systemType, string $hybridVariant = ''): string
{
    if (documents_quote_normalize_system_type($systemType) !== 'hybrid') {
        return 'Ongrid Solar Power Generation System';
    }
    return strtoupper($hybridVariant) === 'TL'
        ? 'Hybrid Solar Power Generation System TLess'
        : 'Hybrid Solar Power Generation System TBased';
}

function documents_quote_find_system_kit_by_name(string $name): ?array
{
    $wanted = strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
    foreach (documents_inventory_kits(false) as $kit) {
        $kitName = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($kit['name'] ?? '')) ?? ''));
        if ($kitName === $wanted) {
            return $kit;
        }
    }
    return null;
}

function documents_quote_is_known_system_kit_item(array $item): bool
{
    if (!empty($item['meta']['managed_system_kit'])) {
        return true;
    }
    $name = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($item['name_snapshot'] ?? '')) ?? ''));
    return in_array($name, [
        'ongrid solar power generation system',
        'hybrid solar power generation system tbased',
        'hybrid solar power generation system tless',
    ], true);
}

function documents_quote_hybrid_variant_from_snapshot(array $snapshot): string
{
    $haystack = strtoupper((string) ($snapshot['model_number'] ?? '') . ' ' . (string) ($snapshot['inverter_code'] ?? '') . ' ' . (string) ($snapshot['variant'] ?? ''));
    if (preg_match('/(^|[-_\s])TL($|[-_\s])/', $haystack)) {
        return 'TL';
    }
    return 'TB';
}

function documents_quote_reconcile_system_configuration(array $quote): array
{
    $systemType = documents_quote_normalize_system_type((string) ($quote['system_type'] ?? 'ongrid'));
    $quote['system_type'] = $systemType;
    $snapshot = is_array($quote['rate_chart_snapshot'] ?? null) ? $quote['rate_chart_snapshot'] : [];
    $snapshot['system_type'] = $systemType;
    if ($systemType !== 'hybrid') {
        foreach (['hybrid_inverter_kva', 'hybrid_phase', 'hybrid_battery_count', 'battery_code', 'inverter_code'] as $field) {
            $snapshot[$field] = in_array($field, ['hybrid_inverter_kva', 'hybrid_battery_count'], true) ? 0 : '';
        }
    }
    $quote['rate_chart_snapshot'] = $snapshot;
    $variant = $systemType === 'hybrid' ? documents_quote_hybrid_variant_from_snapshot($snapshot) : '';
    $requiredName = documents_quote_known_system_kit_name($systemType, $variant);
    $requiredKit = documents_quote_find_system_kit_by_name($requiredName);
    if (!is_array($requiredKit)) {
        $quote['system_reconcile_error'] = 'Matching Items Master kit not found: ' . $requiredName;
        return $quote;
    }

    $items = documents_normalize_quote_structured_items(is_array($quote['quote_items'] ?? null) ? $quote['quote_items'] : []);
    $managedIndex = null;
    foreach ($items as $idx => $item) {
        if (!documents_quote_is_known_system_kit_item($item)) {
            continue;
        }
        if ($managedIndex === null) {
            $managedIndex = $idx;
            continue;
        }
        unset($items[$idx]);
    }
    $autoDescription = safe_multiline_text((string) ($snapshot['auto_description'] ?? ''));
    if ($systemType === 'hybrid' && function_exists('solar_finance_build_hybrid_configuration_summary')) {
        $autoDescription = solar_finance_build_hybrid_configuration_summary($snapshot);
    }
    $base = [
        'type' => 'kit',
        'kit_id' => (string) ($requiredKit['id'] ?? ''),
        'component_id' => '',
        'qty' => 1,
        'unit' => 'set',
        'variant_id' => '',
        'variant_snapshot' => [],
        'name_snapshot' => (string) ($requiredKit['name'] ?? $requiredName),
        'description_snapshot' => safe_multiline_text((string) ($requiredKit['description'] ?? '')),
        'master_description_snapshot' => safe_multiline_text((string) ($requiredKit['description'] ?? '')),
        'auto_description' => $autoDescription,
        'custom_description' => '',
        'description_mode' => 'auto',
        'hsn_snapshot' => safe_text((string) ($requiredKit['hsn'] ?? '')),
        'meta' => [
            'managed_system_kit' => true,
            'system_type' => $systemType,
            'model_number' => safe_text((string) ($snapshot['model_number'] ?? '')),
            'hybrid_variant' => $variant,
        ],
    ];
    if ($managedIndex !== null && isset($items[$managedIndex])) {
        $old = $items[$managedIndex];
        $base['custom_description'] = safe_multiline_text((string) ($old['custom_description'] ?? ''));
        $base['description_mode'] = (string) ($old['description_mode'] ?? '') === 'manual' || $base['custom_description'] !== '' ? 'manual' : 'auto';
        $items[$managedIndex] = $base;
    } else {
        array_unshift($items, $base);
    }
    $quote['quote_items'] = documents_normalize_quote_structured_items(array_values($items));
    return $quote;
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
        $component['inventory_tracked'] = documents_inventory_component_is_tracked($component);
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
        $normalizedItems = [];
        foreach ($kit['items'] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $component = documents_inventory_get_component((string) ($line['component_id'] ?? ''));
            $normalizedItems[] = documents_normalize_kit_bom_line($line, $component);
        }
        $kit['items'] = $normalizedItems;
        if (!$includeArchived && !empty($kit['archived_flag'])) {
            continue;
        }
        $list[] = $kit;
    }
    usort($list, static fn(array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    return $list;
}


function documents_inventory_kit_active_items(array $kit): array
{
    $items = is_array($kit['items'] ?? null) ? $kit['items'] : [];
    $active = [];
    foreach ($items as $line) {
        if (!is_array($line)) {
            continue;
        }
        $componentId = safe_text((string) ($line['component_id'] ?? ''));
        if ($componentId === '') {
            continue;
        }
        $component = documents_inventory_get_component($componentId);
        if (!is_array($component) || !empty($component['archived_flag'])) {
            continue;
        }
        $active[] = $line;
    }
    return $active;
}

function documents_inventory_cleanup_archived_kit_components(array $kits): array
{
    $changed = false;
    foreach ($kits as $idx => $kit) {
        if (!is_array($kit)) {
            continue;
        }
        $mergedKit = array_merge(documents_inventory_kit_defaults(), $kit);
        $cleanItems = documents_inventory_kit_active_items($mergedKit);
        if (count($cleanItems) !== count((array) ($mergedKit['items'] ?? []))) {
            $mergedKit['items'] = array_values($cleanItems);
            $mergedKit['updated_at'] = date('c');
            $kits[$idx] = $mergedKit;
            $changed = true;
        }
    }
    if ($changed) {
        documents_inventory_save_kits($kits);
    }
    return $kits;
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

function get_active_components(): array
{
    return documents_inventory_components(false);
}

function get_active_kits(): array
{
    return documents_inventory_kits(false);
}

function get_active_variants(?string $componentId = null): array
{
    $variants = documents_inventory_component_variants(false);
    $componentId = trim((string) $componentId);
    if ($componentId === '') {
        return $variants;
    }
    return array_values(array_filter($variants, static function ($row) use ($componentId): bool {
        return is_array($row) && (string) ($row['component_id'] ?? '') === $componentId;
    }));
}

function get_active_tax_profiles(): array
{
    return documents_inventory_tax_profiles(false);
}

function get_active_locations(): array
{
    return documents_inventory_locations(false);
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
    foreach ($stock['stock_by_component_id'] as $componentId => $componentEntry) {
        if (!is_array($componentEntry)) {
            $componentEntry = [];
        }
        if (
            (!isset($componentEntry['stock_by_variant_id']) || !is_array($componentEntry['stock_by_variant_id']))
            && isset($componentEntry['variants'])
            && is_array($componentEntry['variants'])
        ) {
            $componentEntry['stock_by_variant_id'] = $componentEntry['variants'];
        }
        if (!isset($componentEntry['stock_by_variant_id']) || !is_array($componentEntry['stock_by_variant_id'])) {
            $legacyEntry = array_merge(documents_inventory_component_entry_defaults(), $componentEntry);
            $componentEntry = ['stock_by_variant_id' => [documents_inventory_stock_bucket_key('') => $legacyEntry]];
        }
        foreach ($componentEntry['stock_by_variant_id'] as $bucketKey => $bucketEntry) {
            $componentEntry['stock_by_variant_id'][$bucketKey] = array_merge(
                documents_inventory_component_entry_defaults(),
                is_array($bucketEntry) ? $bucketEntry : []
            );
        }
        $stock['stock_by_component_id'][$componentId] = $componentEntry;
    }
    return $stock;
}

function documents_inventory_save_stock(array $stock): array
{
    if (is_array($stock['stock_by_component_id'] ?? null)) {
        foreach ($stock['stock_by_component_id'] as $componentId => $componentEntry) {
            if (!is_array($componentEntry)) {
                continue;
            }
            if (isset($componentEntry['stock_by_variant_id']) && is_array($componentEntry['stock_by_variant_id'])) {
                $stock['stock_by_component_id'][$componentId]['variants'] = $componentEntry['stock_by_variant_id'];
            }
        }
    }
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

function documents_inventory_save_transactions(array $transactions): array
{
    return json_save(documents_inventory_transactions_path(), array_values($transactions));
}

function documents_inventory_load_verification_log(): array
{
    $rows = json_load(documents_inventory_verification_log_path(), []);
    if (!is_array($rows)) {
        return [];
    }

    $list = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $record = array_merge(documents_inventory_verification_defaults(), $row);
        if (!in_array((string) ($record['status'] ?? ''), ['not_verified', 'verified', 'needs_clarification'], true)) {
            $record['status'] = 'not_verified';
        }
        if (!is_array($record['created_by'] ?? null)) {
            $record['created_by'] = ['role' => '', 'id' => '', 'name' => ''];
        }
        if (!is_array($record['verified_by'] ?? null)) {
            $record['verified_by'] = null;
        }
        $list[] = $record;
    }
    return $list;
}

function documents_inventory_save_verification_log(array $rows): array
{
    return json_save(documents_inventory_verification_log_path(), array_values($rows));
}

function documents_inventory_is_employee_actor(array $actor): bool
{
    return strtolower((string) ($actor['role'] ?? '')) === 'employee';
}

function documents_inventory_sync_verification_log(array $transactions, bool $persist = false): array
{
    $logRows = documents_inventory_load_verification_log();
    $byTxnId = [];
    foreach ($logRows as $idx => $row) {
        $txnId = (string) ($row['txn_id'] ?? '');
        if ($txnId === '' || isset($byTxnId[$txnId])) {
            continue;
        }
        $byTxnId[$txnId] = $idx;
    }

    $dirty = false;
    foreach ($transactions as $tx) {
        if (!is_array($tx)) {
            continue;
        }
        $row = array_merge(documents_inventory_transaction_defaults(), $tx);
        $txnId = (string) ($row['id'] ?? '');
        if ($txnId === '') {
            continue;
        }
        $component = documents_inventory_get_component((string) ($row['component_id'] ?? ''));
        if (!is_array($component) || !documents_inventory_component_is_tracked($component)) {
            continue;
        }
        $creator = is_array($row['created_by'] ?? null) ? $row['created_by'] : ['role' => '', 'id' => '', 'name' => ''];
        if (!documents_inventory_is_employee_actor($creator)) {
            continue;
        }
        if (isset($byTxnId[$txnId])) {
            continue;
        }

        $logRows[] = [
            'txn_id' => $txnId,
            'txn_type' => (string) ($row['type'] ?? 'IN'),
            'created_by' => [
                'role' => (string) ($creator['role'] ?? ''),
                'id' => (string) ($creator['id'] ?? ''),
                'name' => (string) ($creator['name'] ?? ''),
            ],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'status' => 'not_verified',
            'admin_note' => '',
            'verified_by' => null,
            'verified_at' => null,
        ];
        $dirty = true;
    }

    if ($persist && $dirty) {
        documents_inventory_save_verification_log($logRows);
    }

    return ['rows' => $logRows, 'dirty' => $dirty];
}

function documents_inventory_load_edits_log(): array
{
    $rows = json_load(documents_inventory_edits_log_path(), []);
    return is_array($rows) ? $rows : [];
}

function documents_inventory_append_edits_log(array $entry): array
{
    $rows = documents_inventory_load_edits_log();
    $rows[] = $entry;
    return json_save(documents_inventory_edits_log_path(), array_values($rows));
}

function documents_inventory_build_usage_index(array $transactions): array
{
    $componentBlocked = [];
    $variantBlocked = [];
    $lotBlocked = [];
    $batchBlocked = [];

    foreach ($transactions as $tx) {
        if (!is_array($tx)) {
            continue;
        }
        $row = array_merge(documents_inventory_transaction_defaults(), $tx);
        if (!empty($row['archived_flag']) || !empty($row['voided_flag'])) {
            continue;
        }
        $type = strtoupper((string) ($row['type'] ?? ''));
        if (!in_array($type, ['OUT', 'ADJUST', 'MOVE'], true)) {
            continue;
        }

        $componentId = (string) ($row['component_id'] ?? '');
        $variantId = (string) ($row['variant_id'] ?? '');
        if ($componentId !== '' && $type !== 'MOVE') {
            if ($variantId === '') {
                $componentBlocked[$componentId] = 'Used in ' . $type . ' transaction.';
            } else {
                $variantBlocked[$variantId] = 'Used in ' . $type . ' transaction.';
            }
        }

        foreach ((array) ($row['lot_consumption'] ?? []) as $consumed) {
            if (!is_array($consumed)) {
                continue;
            }
            $lotId = (string) ($consumed['lot_id'] ?? '');
            if ($lotId === '') {
                continue;
            }
            $lotBlocked[$lotId] = $type === 'MOVE' ? 'Lot moved in transaction.' : 'Lot consumed in transaction.';
        }

        foreach ((array) ($row['batch_consumption'] ?? []) as $consumed) {
            if (!is_array($consumed)) {
                continue;
            }
            $batchId = (string) ($consumed['batch_id'] ?? '');
            if ($batchId === '') {
                continue;
            }
            $batchBlocked[$batchId] = 'Batch moved/consumed in transaction.';
        }
        foreach ((array) ($row['batch_ids'] ?? []) as $batchId) {
            $batchId = (string) $batchId;
            if ($batchId === '') {
                continue;
            }
            $batchBlocked[$batchId] = 'Batch moved/consumed in transaction.';
        }
    }

    return [
        'component_blocked' => $componentBlocked,
        'variant_blocked' => $variantBlocked,
        'lot_blocked' => $lotBlocked,
        'batch_blocked' => $batchBlocked,
    ];
}

function is_lot_editable(array $lot, array $transactionsIndex = []): array
{
    $lotId = (string) ($lot['lot_id'] ?? '');
    if ($lotId === '') {
        return ['editable' => false, 'reason' => 'Missing lot id.'];
    }
    $remainingFt = (float) ($lot['remaining_length_ft'] ?? 0);
    $originalFt = (float) ($lot['original_length_ft'] ?? 0);
    if (abs($remainingFt - $originalFt) > 0.00001) {
        return ['editable' => false, 'reason' => 'Lot is partially used/cut.'];
    }
    $blocked = (array) ($transactionsIndex['lot_blocked'] ?? []);
    if (isset($blocked[$lotId])) {
        return ['editable' => false, 'reason' => (string) $blocked[$lotId]];
    }
    if (!empty((array) ($lot['cuts_history'] ?? []))) {
        return ['editable' => false, 'reason' => 'Lot has cut history.'];
    }
    if (!empty((array) ($lot['consumed_by_txn_ids'] ?? []))) {
        return ['editable' => false, 'reason' => 'Lot has consumption reference.'];
    }
    return ['editable' => true, 'reason' => ''];
}

function documents_reverse_dispatch_from_packing_list(array $packingList, string $challanId): array
{
    $challanId = safe_text($challanId);
    if ($challanId === '') {
        return $packingList;
    }
    $required = is_array($packingList['required_items'] ?? null) ? $packingList['required_items'] : [];
    $logs = is_array($packingList['dispatch_log'] ?? null) ? $packingList['dispatch_log'] : [];
    foreach ($logs as $log) {
        if (!is_array($log) || (string) ($log['delivery_challan_id'] ?? '') !== $challanId) {
            continue;
        }
        foreach ((array) ($log['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $lineId = (string) ($item['line_id'] ?? '');
            if ($lineId === '') {
                continue;
            }
            foreach ($required as $idx => $line) {
                if (!is_array($line) || (string) ($line['line_id'] ?? '') !== $lineId) {
                    continue;
                }
                $mode = (string) ($line['mode'] ?? 'fixed_qty');
                $qty = max(0, (float) ($item['qty'] ?? 0));
                $ft = max(0, (float) ($item['ft'] ?? 0));
                $wp = max(0, (float) ($item['dispatch_wp'] ?? 0));
                if (in_array($mode, ['fixed_qty', 'capacity_qty'], true)) {
                    if (strtolower((string) ($line['unit'] ?? '')) === 'ft') {
                        $line['dispatched_ft'] = max(0, (float) ($line['dispatched_ft'] ?? 0) - $ft);
                        $line['pending_ft'] = max(0, (float) ($line['required_ft'] ?? 0) - (float) ($line['dispatched_ft'] ?? 0));
                    } else {
                        $line['dispatched_qty'] = max(0, (float) ($line['dispatched_qty'] ?? 0) - $qty);
                        $line['pending_qty'] = max(0, (float) ($line['required_qty'] ?? 0) - (float) ($line['dispatched_qty'] ?? 0));
                    }
                } elseif ($mode === 'rule_fulfillment') {
                    $line['dispatched_wp'] = max(0, (float) ($line['dispatched_wp'] ?? 0) - $wp);
                    $line['fulfilled_flag'] = (float) ($line['dispatched_wp'] ?? 0) >= (float) ($line['target_wp'] ?? 0);
                } elseif ($mode === 'unfixed_manual') {
                    if (strtolower((string) ($line['unit'] ?? '')) === 'ft') {
                        $line['dispatched_ft'] = max(0, (float) ($line['dispatched_ft'] ?? 0) - $ft);
                    } else {
                        $line['dispatched_qty'] = max(0, (float) ($line['dispatched_qty'] ?? 0) - $qty);
                    }
                }
                $required[$idx] = $line;
                break;
            }
        }
    }
    $packingList['required_items'] = array_values($required);
    $packingList['dispatch_log'] = array_values(array_filter($logs, static function ($log) use ($challanId): bool {
        return !is_array($log) || (string) ($log['delivery_challan_id'] ?? '') !== $challanId;
    }));
    $allPendingDone = true;
    foreach ($packingList['required_items'] as $line) {
        if (!is_array($line)) { continue; }
        $mode = (string) ($line['mode'] ?? 'fixed_qty');
        if (in_array($mode, ['fixed_qty', 'capacity_qty'], true)) {
            if ((float) ($line['pending_qty'] ?? 0) > 0.00001 || (float) ($line['pending_ft'] ?? 0) > 0.00001) { $allPendingDone = false; break; }
        } elseif ($mode === 'rule_fulfillment' && empty($line['fulfilled_flag'])) {
            $allPendingDone = false; break;
        }
    }
    $packingList['status'] = $allPendingDone ? 'complete' : 'active';
    $packingList['updated_at'] = date('c');
    return $packingList;
}

function documents_inventory_actor(array $viewer): array
{
    return [
        'role' => (string) ($viewer['role_name'] ?? 'admin'),
        'id' => (string) ($viewer['id'] ?? ''),
        'name' => (string) ($viewer['full_name'] ?? 'Unknown'),
    ];
}

function documents_inventory_stock_bucket_key(string $variantId): string
{
    return $variantId === '' ? '__default' : $variantId;
}

function documents_inventory_component_stock(array $stock, string $componentId, string $variantId = ''): array
{
    $componentEntry = $stock['stock_by_component_id'][$componentId] ?? [];
    if (!is_array($componentEntry)) {
        $componentEntry = [];
    }

    if (isset($componentEntry['stock_by_variant_id']) && is_array($componentEntry['stock_by_variant_id'])) {
        $bucketKey = documents_inventory_stock_bucket_key($variantId);
        $entry = $componentEntry['stock_by_variant_id'][$bucketKey] ?? [];
    } else {
        $entry = $componentEntry;
    }

    $entry = array_merge(documents_inventory_component_entry_defaults(), is_array($entry) ? $entry : []);
    $entry['on_hand_qty'] = max(0, (float) ($entry['on_hand_qty'] ?? 0));
    $entry['location_breakdown'] = documents_inventory_normalize_location_breakdown((array) ($entry['location_breakdown'] ?? []));
    $breakdownTotal = documents_inventory_location_breakdown_total($entry['location_breakdown']);
    if ($breakdownTotal <= 0 && $entry['on_hand_qty'] > 0) {
        $entry['location_breakdown'] = [['location_id' => '', 'qty' => $entry['on_hand_qty']]];
        $breakdownTotal = $entry['on_hand_qty'];
    }
    $entry['on_hand_qty'] = $breakdownTotal > 0 ? $breakdownTotal : $entry['on_hand_qty'];
    $entry['lots'] = is_array($entry['lots'] ?? null) ? $entry['lots'] : [];
    foreach ($entry['lots'] as $idx => $lot) {
        if (!is_array($lot)) {
            $lot = [];
        }
        $lot['location_id'] = (string) ($lot['location_id'] ?? '');
        $lot['created_at'] = (string) ($lot['created_at'] ?? ($lot['received_at'] ?? ''));
        $lot['created_by'] = is_array($lot['created_by'] ?? null) ? $lot['created_by'] : ['role' => 'system', 'id' => '', 'name' => 'Legacy'];
        $entry['lots'][$idx] = $lot;
    }

    $entry['batches'] = is_array($entry['batches'] ?? null) ? $entry['batches'] : [];
    if ($entry['batches'] === [] && $entry['location_breakdown'] !== [] && empty($entry['lots'])) {
        foreach ($entry['location_breakdown'] as $legacyRow) {
            if (!is_array($legacyRow)) {
                continue;
            }
            $qty = max(0, (float) ($legacyRow['qty'] ?? 0));
            if ($qty <= 0) {
                continue;
            }
            $entry['batches'][] = [
                'batch_id' => 'legacy-' . substr(sha1((string) $componentId . '|' . (string) $variantId . '|' . (string) ($legacyRow['location_id'] ?? '')), 0, 10),
                'location_id' => (string) ($legacyRow['location_id'] ?? ''),
                'qty_remaining' => $qty,
                'created_by' => ['role' => 'system', 'id' => '', 'name' => 'Legacy'],
                'created_at' => '',
                'source_txn_id' => '',
            ];
        }
    }
    foreach ($entry['batches'] as $idx => $batch) {
        if (!is_array($batch)) {
            $batch = [];
        }
        $batch['batch_id'] = (string) ($batch['batch_id'] ?? ('batch-' . $idx));
        $batch['location_id'] = (string) ($batch['location_id'] ?? '');
        $batch['qty_remaining'] = max(0, (float) ($batch['qty_remaining'] ?? 0));
        $batch['created_by'] = is_array($batch['created_by'] ?? null) ? $batch['created_by'] : ['role' => 'system', 'id' => '', 'name' => 'Legacy'];
        $batch['created_at'] = (string) ($batch['created_at'] ?? '');
        $batch['source_txn_id'] = (string) ($batch['source_txn_id'] ?? '');
        $entry['batches'][$idx] = $batch;
    }
    $entry['batches'] = array_values(array_filter($entry['batches'], static fn(array $batch): bool => (float) ($batch['qty_remaining'] ?? 0) > 0));
    if ($entry['batches'] !== []) {
        $entry['location_breakdown'] = [];
        foreach ($entry['batches'] as $batch) {
            $entry['location_breakdown'][] = ['location_id' => (string) ($batch['location_id'] ?? ''), 'qty' => (float) ($batch['qty_remaining'] ?? 0)];
        }
        $entry['location_breakdown'] = documents_inventory_normalize_location_breakdown($entry['location_breakdown']);
        $entry['on_hand_qty'] = documents_inventory_location_breakdown_total($entry['location_breakdown']);
    }
    return $entry;
}

function documents_inventory_stock_lookup_debug_enabled(): bool
{
    if (((string) ($_GET['debug_stock_lookup'] ?? '')) !== '1') {
        return false;
    }
    if (!function_exists('current_user')) {
        return false;
    }
    $user = current_user();
    return is_array($user) && ((string) ($user['role_name'] ?? '')) === 'admin';
}

function documents_inventory_log_stock_lookup(string $componentId, string $variantId, string $branch, float $computed): void
{
    if (!documents_inventory_stock_lookup_debug_enabled()) {
        return;
    }
    $path = dirname(__DIR__, 2) . '/data/logs/stock_lookup_debug.log';
    $line = sprintf(
        "[%s] component_id=%s variant_id=%s branch=%s computed=%s\n",
        date('c'),
        $componentId,
        $variantId,
        $branch,
        rtrim(rtrim(sprintf('%.4F', $computed), '0'), '.')
    );
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

function documents_inventory_stock_entry_raw(array $stock, string $componentId, string $variantId = ''): array
{
    $entry = [];

    $componentEntry = $stock['stock_by_component_id'][$componentId] ?? null;
    if (is_array($componentEntry)) {
        if (isset($componentEntry['stock_by_variant_id']) && is_array($componentEntry['stock_by_variant_id'])) {
            $entry = $componentEntry['stock_by_variant_id'][documents_inventory_stock_bucket_key($variantId)] ?? [];
        } elseif (isset($componentEntry['variants']) && is_array($componentEntry['variants'])) {
            $entry = $componentEntry['variants'][documents_inventory_stock_bucket_key($variantId)] ?? $componentEntry['variants'][$variantId] ?? [];
        } else {
            $entry = $componentEntry;
        }
    }

    if (!is_array($entry) || $entry === []) {
        $legacyStock = $stock['stock'][$componentId] ?? null;
        if (is_array($legacyStock)) {
            if ($variantId !== '') {
                $entry = (array) (($legacyStock['variants'][$variantId] ?? $legacyStock['stock_by_variant_id'][$variantId] ?? $legacyStock['stock_by_variant_id'][documents_inventory_stock_bucket_key($variantId)] ?? []));
            } else {
                $entry = $legacyStock;
            }
        }
    }

    if (!is_array($entry)) {
        $entry = [];
    }

    return array_merge(documents_inventory_component_entry_defaults(), $entry);
}

function documents_inventory_component_variant_entries(array $stock, string $componentId): array
{
    $entries = [];
    $componentEntry = $stock['stock_by_component_id'][$componentId] ?? null;
    $variantBuckets = [];
    if (is_array($componentEntry) && isset($componentEntry['stock_by_variant_id']) && is_array($componentEntry['stock_by_variant_id'])) {
        $variantBuckets = $componentEntry['stock_by_variant_id'];
    } elseif (is_array($componentEntry) && isset($componentEntry['variants']) && is_array($componentEntry['variants'])) {
        $variantBuckets = $componentEntry['variants'];
    }

    if ($variantBuckets !== []) {
        foreach ($variantBuckets as $bucketKey => $bucketEntry) {
            if ($bucketKey === documents_inventory_stock_bucket_key('')) {
                continue;
            }
            if (is_array($bucketEntry)) {
                $entries[] = $bucketEntry;
            }
        }
    }

    $legacyStock = $stock['stock'][$componentId] ?? null;
    if ($entries === [] && is_array($legacyStock)) {
        $legacyVariants = null;
        if (isset($legacyStock['variants']) && is_array($legacyStock['variants'])) {
            $legacyVariants = $legacyStock['variants'];
        } elseif (isset($legacyStock['stock_by_variant_id']) && is_array($legacyStock['stock_by_variant_id'])) {
            $legacyVariants = $legacyStock['stock_by_variant_id'];
        }
        if (is_array($legacyVariants)) {
            foreach ($legacyVariants as $variantKey => $variantEntry) {
                if ((string) $variantKey === documents_inventory_stock_bucket_key('')) {
                    continue;
                }
                if (is_array($variantEntry)) {
                    $entries[] = $variantEntry;
                }
            }
        }
    }

    return $entries;
}

function documents_inventory_compute_entry_on_hand(array $entry, bool $isCuttable, string &$branch): float
{
    if ($isCuttable) {
        $branch = 'lots';
        $sum = 0.0;
        foreach ((array) ($entry['lots'] ?? []) as $lot) {
            if (!is_array($lot)) {
                continue;
            }
            $sum += (float) ($lot['remaining_length_ft'] ?? 0);
        }
        return $sum;
    }

    $batches = (array) ($entry['batches'] ?? []);
    if ($batches !== []) {
        $branch = 'batches';
        $sum = 0.0;
        foreach ($batches as $batch) {
            if (!is_array($batch)) {
                continue;
            }
            $sum += (float) ($batch['qty_remaining'] ?? 0);
        }
        return $sum;
    }

    $locationBreakdown = (array) ($entry['location_breakdown'] ?? []);
    if ($locationBreakdown !== []) {
        $branch = 'location_breakdown';
        $sum = 0.0;
        foreach ($locationBreakdown as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sum += (float) ($row['qty'] ?? 0);
        }
        return $sum;
    }

    $branch = 'legacy_on_hand_qty';
    return (float) ($entry['on_hand_qty'] ?? 0);
}

function documents_inventory_compute_on_hand(array $stock, string $componentId, string $variantId = '', bool $isCuttable = false): float
{
    if ($componentId === '') {
        return 0.0;
    }

    if ($variantId !== '') {
        $entry = documents_inventory_stock_entry_raw($stock, $componentId, $variantId);
        $branch = '';
        $computed = documents_inventory_compute_entry_on_hand($entry, $isCuttable, $branch);
        documents_inventory_log_stock_lookup($componentId, $variantId, $branch, $computed);
        return $computed;
    }

    $variantEntries = documents_inventory_component_variant_entries($stock, $componentId);
    if ($variantEntries !== []) {
        $sum = 0.0;
        $branchUsed = 'variants_total';
        foreach ($variantEntries as $entry) {
            $branch = '';
            $sum += documents_inventory_compute_entry_on_hand(array_merge(documents_inventory_component_entry_defaults(), $entry), $isCuttable, $branch);
            $branchUsed = 'variants_total:' . $branch;
        }
        documents_inventory_log_stock_lookup($componentId, '', $branchUsed, $sum);
        return $sum;
    }

    $entry = documents_inventory_stock_entry_raw($stock, $componentId, '');
    $branch = '';
    $computed = documents_inventory_compute_entry_on_hand($entry, $isCuttable, $branch);
    documents_inventory_log_stock_lookup($componentId, '', $branch, $computed);
    return $computed;
}

function documents_inventory_set_component_stock(array &$stock, string $componentId, string $variantId, array $entry): void
{
    if (!isset($stock['stock_by_component_id'][$componentId]) || !is_array($stock['stock_by_component_id'][$componentId])) {
        $stock['stock_by_component_id'][$componentId] = ['stock_by_variant_id' => []];
    }

    if (!isset($stock['stock_by_component_id'][$componentId]['stock_by_variant_id']) || !is_array($stock['stock_by_component_id'][$componentId]['stock_by_variant_id'])) {
        $existing = documents_inventory_component_stock($stock, $componentId);
        $stock['stock_by_component_id'][$componentId] = ['stock_by_variant_id' => [documents_inventory_stock_bucket_key('') => $existing]];
    }

    $bucketKey = documents_inventory_stock_bucket_key($variantId);
    $stock['stock_by_component_id'][$componentId]['stock_by_variant_id'][$bucketKey] = array_merge(documents_inventory_component_entry_defaults(), $entry);
    $stock['stock_by_component_id'][$componentId]['variants'] = $stock['stock_by_component_id'][$componentId]['stock_by_variant_id'];
}

function documents_inventory_format_number(float $value, int $decimals = 2): string
{
    $value = (float) $value;
    if (abs($value - round($value)) <= 0.00001) {
        return (string) (int) round($value);
    }
    return rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
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

function documents_inventory_normalize_location_breakdown(array $rows): array
{
    $byLocation = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $locationId = trim((string) ($row['location_id'] ?? ''));
        $qty = max(0, (float) ($row['qty'] ?? 0));
        if ($qty <= 0) {
            continue;
        }
        if (!isset($byLocation[$locationId])) {
            $byLocation[$locationId] = 0.0;
        }
        $byLocation[$locationId] += $qty;
    }

    $normalized = [];
    ksort($byLocation);
    foreach ($byLocation as $locationId => $qty) {
        $normalized[] = ['location_id' => $locationId, 'qty' => $qty];
    }
    return $normalized;
}

function documents_inventory_location_breakdown_total(array $rows): float
{
    $sum = 0.0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sum += max(0, (float) ($row['qty'] ?? 0));
    }
    return $sum;
}

function documents_inventory_add_to_location_breakdown(array $entry, float $qty, string $locationId): array
{
    $qty = max(0, $qty);
    if ($qty <= 0) {
        return $entry;
    }
    $locationId = trim($locationId);
    $rows = documents_inventory_normalize_location_breakdown((array) ($entry['location_breakdown'] ?? []));
    $found = false;
    foreach ($rows as &$row) {
        if ((string) ($row['location_id'] ?? '') === $locationId) {
            $row['qty'] = (float) ($row['qty'] ?? 0) + $qty;
            $found = true;
            break;
        }
    }
    unset($row);
    if (!$found) {
        $rows[] = ['location_id' => $locationId, 'qty' => $qty];
    }
    $rows = documents_inventory_normalize_location_breakdown($rows);
    $entry['location_breakdown'] = $rows;
    $entry['on_hand_qty'] = documents_inventory_location_breakdown_total($rows);
    return $entry;
}


function documents_inventory_create_batch(string $locationId, float $qty, array $actor, string $sourceTxnId = ''): array
{
    return [
        'batch_id' => 'BATCH-' . date('YmdHis') . '-' . bin2hex(random_bytes(2)),
        'location_id' => trim($locationId),
        'qty_remaining' => max(0, $qty),
        'created_by' => $actor,
        'created_at' => date('c'),
        'source_txn_id' => $sourceTxnId,
    ];
}

function documents_inventory_consume_from_batches(array $entry, float $qty, string $preferredLocationId = ''): array
{
    $qty = max(0, $qty);
    if ($qty <= 0) {
        return ['ok' => false, 'error' => 'Quantity must be greater than zero.'];
    }

    $batches = is_array($entry['batches'] ?? null) ? $entry['batches'] : [];
    $available = 0.0;
    foreach ($batches as $batch) {
        if (!is_array($batch)) {
            continue;
        }
        $available += max(0, (float) ($batch['qty_remaining'] ?? 0));
    }
    if ($available + 0.00001 < $qty) {
        return ['ok' => false, 'error' => 'Insufficient stock.'];
    }

    usort($batches, static fn(array $a, array $b): int => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));

    $remaining = $qty;
    $batchConsumption = [];
    $locationConsumption = [];
    foreach ($batches as &$batch) {
        if ($remaining <= 0) {
            break;
        }
        if ($preferredLocationId !== '' && (string) ($batch['location_id'] ?? '') !== $preferredLocationId) {
            continue;
        }
        $batchQty = max(0, (float) ($batch['qty_remaining'] ?? 0));
        if ($batchQty <= 0) {
            continue;
        }
        $used = min($batchQty, $remaining);
        $batch['qty_remaining'] = $batchQty - $used;
        $remaining -= $used;
        $batchConsumption[] = ['batch_id' => (string) ($batch['batch_id'] ?? ''), 'used_qty' => $used];
        $locationConsumption[] = ['location_id' => (string) ($batch['location_id'] ?? ''), 'qty' => $used];
    }
    unset($batch);

    if ($remaining > 0.00001) {
        return ['ok' => false, 'error' => $preferredLocationId !== '' ? 'Insufficient stock at selected location.' : 'Insufficient stock.'];
    }

    $batches = array_values(array_filter($batches, static fn(array $batch): bool => (float) ($batch['qty_remaining'] ?? 0) > 0));
    $entry['batches'] = $batches;
    $entry['location_breakdown'] = [];
    foreach ($batches as $batch) {
        $entry['location_breakdown'][] = ['location_id' => (string) ($batch['location_id'] ?? ''), 'qty' => (float) ($batch['qty_remaining'] ?? 0)];
    }
    $entry['location_breakdown'] = documents_inventory_normalize_location_breakdown($entry['location_breakdown']);
    $entry['on_hand_qty'] = documents_inventory_location_breakdown_total($entry['location_breakdown']);

    return [
        'ok' => true,
        'entry' => $entry,
        'batch_consumption' => $batchConsumption,
        'location_consumption' => documents_inventory_normalize_location_breakdown($locationConsumption),
    ];
}

function documents_inventory_consume_from_location_breakdown(array $entry, float $qty, string $preferredLocationId = ''): array
{
    $qty = max(0, $qty);
    if ($qty <= 0) {
        return ['ok' => false, 'error' => 'Quantity must be greater than zero.'];
    }

    $rows = documents_inventory_normalize_location_breakdown((array) ($entry['location_breakdown'] ?? []));
    if ($rows === []) {
        $legacyQty = max(0, (float) ($entry['on_hand_qty'] ?? 0));
        if ($legacyQty > 0) {
            $rows = [['location_id' => '', 'qty' => $legacyQty]];
        }
    }

    $available = documents_inventory_location_breakdown_total($rows);
    if ($available + 0.00001 < $qty) {
        return ['ok' => false, 'error' => 'Insufficient stock.'];
    }

    $preferredLocationId = trim($preferredLocationId);
    if ($preferredLocationId !== '') {
        foreach ($rows as $row) {
            if ((string) ($row['location_id'] ?? '') === $preferredLocationId) {
                if ((float) ($row['qty'] ?? 0) + 0.00001 < $qty) {
                    return ['ok' => false, 'error' => 'Insufficient stock at selected location.'];
                }
                break;
            }
        }
    }

    usort($rows, static function (array $a, array $b): int {
        $qtyCompare = (float) ($b['qty'] ?? 0) <=> (float) ($a['qty'] ?? 0);
        if ($qtyCompare !== 0) {
            return $qtyCompare;
        }
        return strcmp((string) ($a['location_id'] ?? ''), (string) ($b['location_id'] ?? ''));
    });

    if ($preferredLocationId !== '') {
        usort($rows, static function (array $a, array $b) use ($preferredLocationId): int {
            $aIsPreferred = ((string) ($a['location_id'] ?? '') === $preferredLocationId) ? 0 : 1;
            $bIsPreferred = ((string) ($b['location_id'] ?? '') === $preferredLocationId) ? 0 : 1;
            if ($aIsPreferred !== $bIsPreferred) {
                return $aIsPreferred <=> $bIsPreferred;
            }
            $qtyCompare = (float) ($b['qty'] ?? 0) <=> (float) ($a['qty'] ?? 0);
            if ($qtyCompare !== 0) {
                return $qtyCompare;
            }
            return strcmp((string) ($a['location_id'] ?? ''), (string) ($b['location_id'] ?? ''));
        });
    }

    $remaining = $qty;
    $consumption = [];
    foreach ($rows as &$row) {
        if ($remaining <= 0) {
            break;
        }
        $rowQty = max(0, (float) ($row['qty'] ?? 0));
        if ($rowQty <= 0) {
            continue;
        }
        if ($preferredLocationId !== '' && (string) ($row['location_id'] ?? '') !== $preferredLocationId) {
            continue;
        }
        $take = min($rowQty, $remaining);
        if ($take <= 0) {
            continue;
        }
        $row['qty'] = $rowQty - $take;
        $remaining -= $take;
        $consumption[] = ['location_id' => (string) ($row['location_id'] ?? ''), 'qty' => $take];
    }
    unset($row);

    if ($remaining > 0.00001 && $preferredLocationId === '') {
        foreach ($rows as &$row) {
            if ($remaining <= 0) {
                break;
            }
            $rowQty = max(0, (float) ($row['qty'] ?? 0));
            if ($rowQty <= 0) {
                continue;
            }
            $take = min($rowQty, $remaining);
            $row['qty'] = $rowQty - $take;
            $remaining -= $take;
            $consumption[] = ['location_id' => (string) ($row['location_id'] ?? ''), 'qty' => $take];
        }
        unset($row);
    }

    if ($remaining > 0.00001) {
        return ['ok' => false, 'error' => 'Insufficient stock.'];
    }

    $normalizedConsumption = documents_inventory_normalize_location_breakdown($consumption);
    $entry['location_breakdown'] = documents_inventory_normalize_location_breakdown($rows);
    $entry['on_hand_qty'] = documents_inventory_location_breakdown_total($entry['location_breakdown']);

    $consumedLocationId = '';
    if (count($normalizedConsumption) === 1) {
        $consumedLocationId = (string) ($normalizedConsumption[0]['location_id'] ?? '');
    } elseif (count($normalizedConsumption) > 1) {
        $consumedLocationId = 'mixed';
    }

    return [
        'ok' => true,
        'entry' => $entry,
        'location_consumption' => $normalizedConsumption,
        'location_id' => $consumedLocationId,
    ];
}

function documents_inventory_locations(bool $includeArchived = true): array
{
    $rows = json_load(documents_inventory_locations_path(), []);
    if (!is_array($rows)) {
        return [];
    }
    $list = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $location = array_merge(documents_inventory_location_defaults(), $row);
        if (!$includeArchived && !empty($location['archived_flag'])) {
            continue;
        }
        $list[] = $location;
    }
    usort($list, static fn(array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    return $list;
}

function load_locations(bool $includeArchived = true): array
{
    return documents_inventory_locations($includeArchived);
}

function documents_inventory_save_locations(array $rows): array
{
    return json_save(documents_inventory_locations_path(), array_values($rows));
}

function documents_inventory_get_location(string $id): ?array
{
    foreach (documents_inventory_locations(true) as $location) {
        if ((string) ($location['id'] ?? '') === $id) {
            return $location;
        }
    }
    return null;
}

function documents_inventory_resolve_location_name(string $locationId, ?array $locationsMap = null): string
{
    $locationId = trim($locationId);
    if ($locationId === '') {
        return 'Unassigned';
    }
    if ($locationId === 'mixed') {
        return 'Mixed';
    }

    static $cache = null;
    if (is_array($locationsMap)) {
        $cache = $locationsMap;
    }
    if (!is_array($cache)) {
        $cache = [];
        foreach (documents_inventory_locations(true) as $row) {
            $cache[(string) ($row['id'] ?? '')] = (string) ($row['name'] ?? '');
        }
    }

    $name = trim((string) ($cache[$locationId] ?? ''));
    return $name !== '' ? $name : 'Unassigned';
}

function resolve_location_name(string $locationId, ?array $locationsMap = null): string
{
    return documents_inventory_resolve_location_name($locationId, $locationsMap);
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
        $item['name_snapshot'] = safe_text((string) ($item['name_snapshot'] ?? ''));
        $item['description_snapshot'] = safe_multiline_text((string) ($item['description_snapshot'] ?? ''));
        $masterDescriptionSnapshot = safe_multiline_text((string) ($item['master_description_snapshot'] ?? ''));
        if ($masterDescriptionSnapshot === '') {
            $masterDescriptionSnapshot = $item['description_snapshot'];
        }
        $item['master_description_snapshot'] = $masterDescriptionSnapshot;
        $item['description_snapshot'] = $masterDescriptionSnapshot;
        $item['auto_description'] = safe_multiline_text((string) ($item['auto_description'] ?? ''));
        $item['custom_description'] = safe_multiline_text((string) ($item['custom_description'] ?? ''));
        $item['description_mode'] = (string) ($item['description_mode'] ?? '') === 'manual' ? 'manual' : 'auto';
        $item['hsn_snapshot'] = safe_text((string) ($item['hsn_snapshot'] ?? ''));
        $item['unit'] = safe_text((string) ($item['unit'] ?? ''));
        $item['variant_id'] = safe_text((string) ($item['variant_id'] ?? ''));
        $item['variant_snapshot'] = is_array($item['variant_snapshot'] ?? null) ? $item['variant_snapshot'] : [];
        $item['meta'] = is_array($item['meta'] ?? null) ? $item['meta'] : [];
        if (!empty($item['meta']['managed_system_kit'])) {
            $item['meta']['managed_system_kit'] = true;
            $item['meta']['system_type'] = documents_quote_normalize_system_type((string) ($item['meta']['system_type'] ?? ''));
            $item['meta']['model_number'] = safe_text((string) ($item['meta']['model_number'] ?? ''));
            $item['meta']['hybrid_variant'] = safe_text((string) ($item['meta']['hybrid_variant'] ?? ''));
        }
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
    $quote = documents_quote_prepare($quote);
    $quoteItems = documents_normalize_quote_structured_items(is_array($quote['quote_items'] ?? null) ? $quote['quote_items'] : []);
    if ($quoteItems === []) {
        return ['ok' => false, 'error' => 'No structured items selected'];
    }

    $components = documents_inventory_components(true);
    $componentMap = [];
    foreach ($components as $component) {
        $componentMap[(string) ($component['id'] ?? '')] = $component;
    }

    $required = [];
    $kwp = documents_quote_system_capacity_kwp($quote);

    foreach ($quoteItems as $item) {
        $multiplier = max(0, (float) ($item['qty'] ?? 0));
        if ($multiplier <= 0) {
            continue;
        }

        if ((string) ($item['type'] ?? '') === 'kit') {
            $kit = documents_inventory_get_kit((string) ($item['kit_id'] ?? ''));
            if ($kit === null) {
                continue;
            }
            foreach ((array) ($kit['items'] ?? []) as $bomLineRaw) {
                if (!is_array($bomLineRaw)) {
                    continue;
                }
                $componentId = (string) ($bomLineRaw['component_id'] ?? '');
                if ($componentId === '') {
                    continue;
                }
                $component = $componentMap[$componentId] ?? null;
                if (!is_array($component)) {
                    continue;
                }
                $bomLine = documents_normalize_kit_bom_line($bomLineRaw, $component);
                $line = array_merge(documents_packing_required_line_defaults(), [
                    'line_id' => (string) ($bomLine['line_id'] ?? ('line_' . bin2hex(random_bytes(4)))),
                    'component_id' => $componentId,
                    'component_name_snapshot' => (string) ($component['name'] ?? 'Component'),
                    'unit' => (string) ($bomLine['unit'] ?? ($component['default_unit'] ?? 'pcs')),
                    'mode' => (string) ($bomLine['mode'] ?? 'fixed_qty'),
                    'remarks' => (string) ($bomLine['remarks'] ?? ''),
                    'source_kit_id' => (string) ($kit['id'] ?? ''),
                    'source_kit_name_snapshot' => (string) (($item['name_snapshot'] ?? '') ?: ($kit['name'] ?? 'Kit')),
                ]);

                if ($line['mode'] === 'fixed_qty') {
                    $baseQty = max(0, (float) ($bomLine['fixed_qty'] ?? 0));
                    $needQty = $baseQty * $multiplier;
                    if ($line['unit'] === 'ft') {
                        $line['required_ft'] = $needQty;
                        $line['pending_ft'] = $needQty;
                    } else {
                        $line['required_qty'] = $needQty;
                        $line['pending_qty'] = $needQty;
                    }
                } elseif ($line['mode'] === 'capacity_qty') {
                    $computed = 0.0;
                    $capRule = is_array($bomLine['capacity_rule'] ?? null) ? $bomLine['capacity_rule'] : [];
                    if ((string) ($capRule['type'] ?? 'formula') === 'slab') {
                        foreach ((array) ($capRule['slabs'] ?? []) as $slab) {
                            if (!is_array($slab)) {
                                continue;
                            }
                            $min = (float) ($slab['kwp_min'] ?? 0);
                            $max = (float) ($slab['kwp_max'] ?? 0);
                            if ($kwp >= $min && ($kwp <= $max || $max <= 0)) {
                                $computed = max(0, (float) ($slab['qty'] ?? 0));
                                break;
                            }
                        }
                    } else {
                        $eval = documents_evaluate_safe_expression((string) ($capRule['expr'] ?? 'kwp * 1'), $kwp);
                        if (!($eval['ok'] ?? false)) {
                            return ['ok' => false, 'error' => 'Invalid capacity formula in kit BOM for ' . ((string) ($component['name'] ?? $componentId))];
                        }
                        $computed = max(0, (float) ($eval['value'] ?? 0));
                    }
                    $needQty = $computed * $multiplier;
                    if ($line['unit'] === 'ft') {
                        $line['required_ft'] = $needQty;
                        $line['pending_ft'] = $needQty;
                    } else {
                        $line['required_qty'] = $needQty;
                        $line['pending_qty'] = $needQty;
                    }
                } elseif ($line['mode'] === 'rule_fulfillment') {
                    $rule = is_array($bomLine['rule'] ?? null) ? $bomLine['rule'] : [];
                    $eval = documents_evaluate_safe_expression((string) ($rule['target_expr'] ?? 'kwp * 1000'), $kwp);
                    if (!($eval['ok'] ?? false)) {
                        return ['ok' => false, 'error' => 'Invalid rule target formula in kit BOM for ' . ((string) ($component['name'] ?? $componentId))];
                    }
                    $line['rule_type'] = (string) ($rule['rule_type'] ?? 'min_total_wp');
                    $line['target_wp'] = max(0, (float) ($eval['value'] ?? 0)) * $multiplier;
                    $line['allow_overbuild_pct'] = max(0, (float) ($rule['allow_overbuild_pct'] ?? 0));
                    $line['dispatch_variant_breakdown'] = [];
                } else {
                    $line['planned_note'] = safe_text((string) ($bomLine['manual_note'] ?? 'planned at dispatch'));
                }

                $required[] = $line;
            }
            continue;
        }

        $componentId = (string) ($item['component_id'] ?? '');
        if ($componentId === '') {
            continue;
        }
        $component = $componentMap[$componentId] ?? null;
        if (!is_array($component)) {
            continue;
        }
        $isCuttable = !empty($component['is_cuttable']);
        $line = array_merge(documents_packing_required_line_defaults(), [
            'line_id' => 'line_' . bin2hex(random_bytes(4)),
            'component_id' => $componentId,
            'component_name_snapshot' => (string) ($component['name'] ?? 'Component'),
            'unit' => $isCuttable ? 'ft' : (string) ($item['unit'] ?: ($component['default_unit'] ?? 'pcs')),
            'mode' => 'fixed_qty',
            'source_kit_id' => '',
            'source_kit_name_snapshot' => '',
        ]);
        if ($isCuttable) {
            $line['required_ft'] = $multiplier;
            $line['pending_ft'] = $multiplier;
        } else {
            $line['required_qty'] = $multiplier;
            $line['pending_qty'] = $multiplier;
        }
        $required[] = $line;
    }

    if ($required === []) {
        return ['ok' => false, 'error' => 'No structured items selected'];
    }

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
    $byLineId = [];
    $byComponent = [];
    foreach ($required as $idx => $line) {
        if (!is_array($line)) {
            continue;
        }
        $lineId = (string) ($line['line_id'] ?? '');
        if ($lineId !== '') {
            $byLineId[$lineId] = $idx;
        }
        $componentId = (string) ($line['component_id'] ?? '');
        if ($componentId !== '' && !isset($byComponent[$componentId])) {
            $byComponent[$componentId] = $idx;
        }
    }

    $dispatchLogItems = [];
    foreach ($dispatchRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lineId = (string) ($row['line_id'] ?? '');
        $componentId = (string) ($row['component_id'] ?? '');
        $idx = null;
        if ($lineId !== '' && isset($byLineId[$lineId])) {
            $idx = $byLineId[$lineId];
        } elseif ($componentId !== '' && isset($byComponent[$componentId])) {
            $idx = $byComponent[$componentId];
        }
        if ($idx === null) {
            continue;
        }

        $line = array_merge(documents_packing_required_line_defaults(), $required[$idx]);
        $dispatchQty = max(0, (float) ($row['dispatch_qty'] ?? 0));
        $dispatchFt = max(0, (float) ($row['dispatch_ft'] ?? 0));
        $dispatchWp = max(0, (float) ($row['dispatch_wp'] ?? 0));
        $mode = (string) ($line['mode'] ?? 'fixed_qty');

        if (in_array($mode, ['fixed_qty', 'capacity_qty'], true)) {
            $isCuttable = strtolower((string) ($line['unit'] ?? '')) === 'ft';
            if ($isCuttable) {
                $line['dispatched_ft'] = (float) ($line['dispatched_ft'] ?? 0) + $dispatchFt;
                $line['pending_ft'] = max(0, (float) ($line['required_ft'] ?? 0) - (float) ($line['dispatched_ft'] ?? 0));
            } else {
                $line['dispatched_qty'] = (float) ($line['dispatched_qty'] ?? 0) + $dispatchQty;
                $line['pending_qty'] = max(0, (float) ($line['required_qty'] ?? 0) - (float) ($line['dispatched_qty'] ?? 0));
            }
        } elseif ($mode === 'rule_fulfillment') {
            $line['dispatched_wp'] = (float) ($line['dispatched_wp'] ?? 0) + $dispatchWp;
            $line['fulfilled_flag'] = (float) ($line['dispatched_wp'] ?? 0) >= (float) ($line['target_wp'] ?? 0);
            $breakdown = is_array($line['dispatch_variant_breakdown'] ?? null) ? $line['dispatch_variant_breakdown'] : [];
            $variantId = (string) ($row['variant_id'] ?? '');
            if ($variantId !== '') {
                $found = false;
                foreach ($breakdown as &$bucket) {
                    if ((string) ($bucket['variant_id'] ?? '') !== $variantId) {
                        continue;
                    }
                    $bucket['dispatched_qty'] = (float) ($bucket['dispatched_qty'] ?? 0) + $dispatchQty;
                    $bucket['dispatched_wp'] = (float) ($bucket['dispatched_wp'] ?? 0) + $dispatchWp;
                    $found = true;
                    break;
                }
                unset($bucket);
                if (!$found) {
                    $breakdown[] = [
                        'variant_id' => $variantId,
                        'variant_name_snapshot' => (string) ($row['variant_name_snapshot'] ?? ''),
                        'wattage_wp' => (float) ($row['wattage_wp'] ?? 0),
                        'dispatched_qty' => $dispatchQty,
                        'dispatched_wp' => $dispatchWp,
                    ];
                }
                $line['dispatch_variant_breakdown'] = array_values($breakdown);
            }
        } elseif ($mode === 'unfixed_manual') {
            if (strtolower((string) ($line['unit'] ?? '')) === 'ft') {
                $line['dispatched_ft'] = (float) ($line['dispatched_ft'] ?? 0) + $dispatchFt;
            } else {
                $line['dispatched_qty'] = (float) ($line['dispatched_qty'] ?? 0) + $dispatchQty;
            }
            $line['dispatched_summary'] = safe_text((string) ($row['manual_note'] ?? ($line['dispatched_summary'] ?? '')));
        }

        $required[$idx] = $line;
        $dispatchLogItems[] = [
            'line_id' => (string) ($line['line_id'] ?? ''),
            'component_id' => (string) ($line['component_id'] ?? ''),
            'mode' => $mode,
            'qty' => $dispatchQty,
            'ft' => $dispatchFt,
            'dispatch_wp' => $dispatchWp,
            'variant_id' => (string) ($row['variant_id'] ?? ''),
            'variant_name_snapshot' => (string) ($row['variant_name_snapshot'] ?? ''),
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
        $mode = (string) ($line['mode'] ?? 'fixed_qty');
        if (in_array($mode, ['fixed_qty', 'capacity_qty'], true)) {
            if ((float) ($line['pending_qty'] ?? 0) > 0 || (float) ($line['pending_ft'] ?? 0) > 0) {
                $allPendingDone = false;
                break;
            }
        } elseif ($mode === 'rule_fulfillment') {
            if (empty($line['fulfilled_flag'])) {
                $allPendingDone = false;
                break;
            }
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

function documents_inventory_consume_planned_lot_cuts(array $lots, array $lotCuts): array
{
    $consumed = [];
    foreach ($lotCuts as $cut) {
        if (!is_array($cut)) {
            continue;
        }
        $lotId = (string) ($cut['lot_id'] ?? '');
        $count = max(0, (int) ($cut['count'] ?? 0));
        $cutLength = max(0, (float) ($cut['cut_length_ft'] ?? 0));
        if ($lotId === '' || $count <= 0 || $cutLength <= 0) {
            continue;
        }
        $remainingNeed = $count * $cutLength;
        foreach ($lots as $idx => $lot) {
            if (!is_array($lot) || (string) ($lot['lot_id'] ?? '') !== $lotId || $remainingNeed <= 0) {
                continue;
            }
            $lotRemaining = max(0, (float) ($lot['remaining_length_ft'] ?? 0));
            if ($lotRemaining <= 0) {
                continue;
            }
            $used = min($lotRemaining, $remainingNeed);
            $lots[$idx]['remaining_length_ft'] = round($lotRemaining - $used, 4);
            $remainingNeed -= $used;
            $consumed[] = ['lot_id' => $lotId, 'used_ft' => $used];
        }
    }

    return ['lots' => $lots, 'lot_consumption' => $consumed];
}

/* Material Dispatch Advice: customer-safe, file-backed commercial document. */
function documents_dispatch_advices_dir(): string { return documents_base_dir() . '/dispatch_advices'; }
function documents_dispatch_advice_settings_path(): string { return documents_settings_dir() . '/dispatch_advice.json'; }
function documents_dispatch_catalog_path(): string { return documents_settings_dir() . '/dispatch_advice_item_catalog.json'; }
function documents_dispatch_advice_defaults(): array { return ['id'=>'','dispatch_advice_no'=>'','quotation_id'=>'','quotation_no'=>'','agreement_id'=>'','agreement_no'=>'','segment'=>'RES','customer_name'=>'','customer_mobile'=>'','delivery_address'=>'','planned_dispatch_date'=>'','date_to_be_confirmed'=>false,'items'=>[],'catalog_item_ids'=>[],'customer_note'=>'','disclaimer'=>'This advice lists items planned for dispatch. It is not proof of dispatch or delivery.','status'=>'draft','revision_no'=>1,'supersedes_id'=>'','public_share_enabled'=>false,'public_token'=>'','public_expires_at'=>'','share_audit'=>[],'customer_acceptance'=>[],'accepted_at'=>'','generated_challan_id'=>'','generated_challan_at'=>'','created_at'=>'','updated_at'=>'']; }
function documents_normalize_dispatch_advice_items(array $items): array { $out=[]; foreach($items as $r){if(!is_array($r))continue;if(array_is_list($r))$r=array_combine(['name','description','brand_model','qty','unit','remarks','catalog_item_id','line_id'],array_pad($r,8,''));$x=['line_id'=>safe_text((string)($r['line_id']??''))?:'dal_'.bin2hex(random_bytes(4)),'catalog_item_id'=>safe_text((string)($r['catalog_item_id']??'')),'name'=>safe_text((string)($r['name']??'')),'description'=>safe_text((string)($r['description']??'')),'brand_model'=>safe_text((string)($r['brand_model']??'')),'qty'=>max(0,(float)($r['qty']??$r['required_qty']??0)),'unit'=>safe_text((string)($r['unit']??'Nos')),'remarks'=>safe_text((string)($r['remarks']??$r['planned_note']??''))]; if($x['name']!==''&&$x['qty']>0&&$x['unit']!=='')$out[]=$x;} return $out; }
function documents_dispatch_catalog_seed(): array { $now=date('c');$rows=[['Solar Panels','Nos',['pv module','module','panel']],['Solar Inverter','Nos',['inverter']],['Batteries','Nos',['battery']],['Module Mounting Structure','Set',['mms','mounting structure']],['DC Solar Wire / Cable','Meter',['dc cable','solar cable']],['AC Wire / Cable','Meter',['ac cable']],['Earthing System / Earthing Kit','Set',['earthing']],['Lightning Arrestor','Set',['la','lightning protection']]];$out=[];foreach($rows as $i=>$r)$out[]=['id'=>'da_item_'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT),'name'=>$r[0],'default_description'=>'','default_brand_model'=>'','default_unit'=>$r[1],'aliases'=>$r[2],'sort_order'=>($i+1)*10,'active'=>true,'created_at'=>$now,'updated_at'=>$now];return $out; }
function documents_dispatch_normalized_name(string $name): string { return trim(preg_replace('/\s+/',' ',strtolower($name))??''); }
function documents_dispatch_catalog(): array { documents_ensure_dir(documents_settings_dir());$rows=json_load(documents_dispatch_catalog_path(),[]);if(!is_array($rows)||!$rows){$rows=documents_dispatch_catalog_seed();json_save(documents_dispatch_catalog_path(),$rows);}usort($rows,fn($a,$b)=>(int)($a['sort_order']??0)<=>(int)($b['sort_order']??0));return $rows; }
function documents_save_dispatch_catalog(array $rows): array { return json_save(documents_dispatch_catalog_path(),array_values($rows)); }
function documents_dispatch_catalog_match(string $name,array $catalog=[]): ?array { $n=documents_dispatch_normalized_name($name);foreach($catalog?:documents_dispatch_catalog() as $r){$names=array_merge([(string)($r['name']??'')],(array)($r['aliases']??[]));foreach($names as $x)if(documents_dispatch_normalized_name((string)$x)===$n)return $r;}return null; }
function documents_dispatch_catalog_upsert(array $input): array { $catalog=documents_dispatch_catalog();$match=documents_dispatch_catalog_match((string)($input['name']??''),$catalog);if($match)return ['ok'=>true,'item'=>$match,'created'=>false];$name=safe_text((string)($input['name']??''));if($name==='')return ['ok'=>false,'error'=>'Item name is required.'];$row=['id'=>'da_item_'.bin2hex(random_bytes(5)),'name'=>$name,'default_description'=>safe_text((string)($input['default_description']??'')),'default_brand_model'=>safe_text((string)($input['default_brand_model']??'')),'default_unit'=>safe_text((string)($input['default_unit']??'Nos'))?:'Nos','aliases'=>[],'sort_order'=>(count($catalog)+1)*10,'active'=>true,'created_at'=>date('c'),'updated_at'=>date('c')];$catalog[]=$row;documents_save_dispatch_catalog($catalog);return ['ok'=>true,'item'=>$row,'created'=>true]; }
function documents_dispatch_quote_eligible(array $q): bool { $status=strtolower(safe_text((string)($q['status']??documents_quote_derived_status($q))));$current=!isset($q['is_current_version'])||!empty($q['is_current_version']);$archived=!empty($q['archived_flag'])||!empty($q['archived']);$name=safe_text((string)($q['customer_name']??$q['customer_snapshot']['name']??''));$mobile=normalize_customer_mobile((string)($q['customer_mobile']??$q['customer_snapshot']['mobile']??''));return $status==='accepted'&&$current&&!$archived&&$name!==''&&$mobile!==''; }
function documents_dispatch_advice_suggested_items(array $quote): array { $source=[];$pack=documents_get_packing_list_for_quote((string)($quote['id']??''),false);foreach((array)($pack['required_items']??[]) as $r)$source[]=['name'=>$r['component_name_snapshot']??'','description'=>$r['planned_note']??'','qty'=>$r['required_qty']??0,'unit'=>$r['unit']??'Nos','remarks'=>$r['remarks']??''];if(!$source)foreach((array)($quote['items']??[]) as $r)$source[]=['name'=>$r['name']??$r['name_snapshot']??'','description'=>$r['description']??$r['description_snapshot']??'','brand_model'=>$r['brand_model']??'','qty'=>$r['qty']??0,'unit'=>$r['unit']??'Nos'];$out=[];foreach($source as $r){$m=documents_dispatch_catalog_match((string)($r['name']??''));if($m){$r['catalog_item_id']=$m['id'];$r['name']=$m['name'];}$out[]=$r;}return documents_normalize_dispatch_advice_items($out); }
function documents_dispatch_advice_customer_mobile(array $advice,array $quote=[]): string { foreach([$advice['customer_mobile']??'',$advice['customer_snapshot']['mobile']??'',$quote['customer_mobile']??'',$quote['customer_snapshot']['mobile']??''] as $value){$mobile=normalize_customer_mobile((string)$value);if($mobile!=='')return $mobile;}return ''; }
function documents_dispatch_advice_public_url(array $advice): string { $token=safe_text((string)($advice['public_token']??''));if($token==='')return '';$scheme=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https://':'http://';return $scheme.($_SERVER['HTTP_HOST']??'localhost').'/dispatch-advice-public.php?token='.rawurlencode($token); }
function documents_get_dispatch_advice(string $id): ?array {$id=safe_filename($id);if(!$id)return null;$r=json_load(documents_dispatch_advices_dir().'/'.$id.'.json',[]);return is_array($r)&&$r?array_merge(documents_dispatch_advice_defaults(),$r):null;}
function documents_get_dispatch_advice_by_public_token(string $token): ?array { $token=trim($token); if($token==='')return null; documents_ensure_dir(documents_dispatch_advices_dir()); foreach(glob(documents_dispatch_advices_dir().'/*.json')?:[] as $f){$r=json_load($f,[]); if(!is_array($r)||!$r)continue; $public=(string)($r['public_token']??''); if($public!==''&&hash_equals($public,$token))return array_merge(documents_dispatch_advice_defaults(),$r);} return null; }
function documents_list_dispatch_advices(): array {documents_ensure_dir(documents_dispatch_advices_dir());$a=[];foreach(glob(documents_dispatch_advices_dir().'/*.json')?:[] as $f){$r=json_load($f,[]);if(is_array($r))$a[]=array_merge(documents_dispatch_advice_defaults(),$r);}usort($a,fn($x,$y)=>strcmp((string)$y['created_at'],(string)$x['created_at']));return $a;}
function documents_dispatch_advice_tab(array $d): string { $status=strtolower(safe_text((string)($d['status']??'draft')));if(in_array($status,['archived','cancelled'],true))return 'archived_cancelled';if($status==='draft')return 'drafts';if(in_array($status,['acknowledged','customer_accepted'],true)||!empty($d['accepted_at'])||in_array(strtolower((string)($d['customer_acceptance']['status']??'')),['accepted','confirmed'],true))return 'customer_accepted';return 'active'; }
function documents_dispatch_advices_for_tab(string $tab,array $rows=[]): array { $allowed=['active','drafts','customer_accepted','archived_cancelled'];if(!in_array($tab,$allowed,true))$tab='active';return array_values(array_filter($rows?:documents_list_dispatch_advices(),static fn(array $d): bool=>documents_dispatch_advice_tab($d)===$tab)); }
function documents_save_dispatch_advice(array $d): array {documents_ensure_dir(documents_dispatch_advices_dir());return json_save(documents_dispatch_advices_dir().'/'.safe_filename((string)$d['id']).'.json',$d);}
function documents_generate_dispatch_advice_number(string $segment): string {$segments=['RES','COM','IND','INST','PROD'];if(!in_array($segment,$segments,true))$segment='RES';$path=documents_dispatch_advice_settings_path();$s=json_load($path,['counters'=>[],'whatsapp_template'=>'']);$fy=current_fy_string(4);$key=$segment.'_'.$fy;$n=max(1,(int)($s['counters'][$key]??1));$s['counters'][$key]=$n+1;$s['updated_at']=date('c');json_save($path,$s);return 'DE/DA/'.$segment.'/'.$fy.'/'.str_pad((string)$n,4,'0',STR_PAD_LEFT);}
function documents_latest_finalized_dispatch_advice(string $quoteId): ?array {foreach(documents_list_dispatch_advices() as $d)if((string)$d['quotation_id']===$quoteId&&in_array($d['status'],['finalized','shared','customer_accepted'],true))return $d;return null;}
function documents_dispatch_advice_items_differ(array $advice,array $challan): bool {$canon=fn($rows)=>array_map(fn($r)=>strtolower(trim((string)($r['name']??''))).'|'.(float)($r['qty']??$r['dispatch_qty']??0).'|'.strtolower(trim((string)($r['unit']??''))),documents_normalize_dispatch_advice_items((array)$rows));return $canon($advice['items']??[])!==$canon($challan['items']??$challan['lines']??[]);}
function documents_challan_dispatch_advice_link(array $challan, array $advices = []): array
{
    $challanId = safe_text((string) ($challan['id'] ?? ''));
    $rawId = safe_text((string) ($challan['dispatch_advice_id'] ?? ''));
    if ($rawId !== '') {
        $dispatchAdvice = documents_get_dispatch_advice($rawId);
        if ($dispatchAdvice) {
            return ['found' => true, 'advice' => $dispatchAdvice, 'reason' => 'dispatch_advice_id'];
        }
    }

    $advices = $advices ?: documents_list_dispatch_advices();
    $challanNo = safe_text((string) ($challan['dispatch_advice_no'] ?? ''));
    if ($challanNo !== '') {
        foreach ($advices as $advice) {
            if (safe_text((string) ($advice['dispatch_advice_no'] ?? '')) === $challanNo) {
                return ['found' => true, 'advice' => $advice, 'reason' => 'dispatch_advice_no'];
            }
        }
    }

    foreach ($advices as $advice) {
        if ($challanId !== '' && safe_text((string) ($advice['generated_challan_id'] ?? '')) === $challanId) {
            return ['found' => true, 'advice' => $advice, 'reason' => 'generated_challan_id'];
        }
    }

    $sourceIds = [];
    foreach (documents_challan_customer_items($challan) as $item) {
        $sourceId = safe_text((string) ($item['source_dispatch_advice_id'] ?? ''));
        if ($sourceId !== '') {
            $sourceIds[$sourceId] = true;
        }
    }
    if (count($sourceIds) === 1) {
        $sourceId = (string) array_key_first($sourceIds);
        $dispatchAdvice = documents_get_dispatch_advice($sourceId);
        if ($dispatchAdvice) {
            return ['found' => true, 'advice' => $dispatchAdvice, 'reason' => 'source_item_ids'];
        }
    }

    $quoteId = safe_text((string) ($challan['quote_id'] ?? $challan['linked_quote_id'] ?? ''));
    $quoteNo = safe_text((string) ($challan['linked_quote_no'] ?? ''));
    $customerMobile = normalize_customer_mobile((string) ($challan['customer_snapshot']['mobile'] ?? $challan['customer_mobile'] ?? ''));
    $customerName = strtolower(safe_text((string) ($challan['customer_snapshot']['name'] ?? $challan['customer_name_snapshot'] ?? '')));
    $matches = [];
    foreach ($advices as $advice) {
        $quoteMatches = ($quoteId !== '' && safe_text((string) ($advice['quotation_id'] ?? '')) === $quoteId)
            || ($quoteNo !== '' && safe_text((string) ($advice['quotation_no'] ?? '')) === $quoteNo);
        if (!$quoteMatches) {
            continue;
        }
        $adviceMobile = normalize_customer_mobile((string) ($advice['customer_mobile'] ?? ''));
        $adviceName = strtolower(safe_text((string) ($advice['customer_name'] ?? '')));
        if (($customerMobile !== '' && $adviceMobile === $customerMobile) || ($customerMobile === '' && $customerName !== '' && $adviceName === $customerName)) {
            $matches[] = $advice;
        }
    }
    if (count($matches) === 1) {
        return ['found' => true, 'advice' => $matches[0], 'reason' => 'unique_quote_customer'];
    }

    return ['found' => false, 'advice' => null, 'reason' => count($matches) > 1 ? 'ambiguous_quote_customer' : 'not_found'];
}

function documents_challan_is_from_dispatch_advice(array $challan): bool
{
    return !empty(documents_challan_dispatch_advice_link($challan)['found']);
}

function documents_repair_challan_dispatch_advice_link(array &$challan, bool $save = false): array
{
    $link = documents_challan_dispatch_advice_link($challan);
    if (empty($link['found']) || empty($link['advice']) || !is_array($link['advice'])) {
        return ['ok' => false, 'changed' => false, 'reason' => $link['reason'] ?? 'not_found'];
    }
    $advice = $link['advice'];
    $before = $challan;
    $challan['workflow_source_type'] = 'dispatch_advice';
    $challan['source_type'] = 'dispatch_advice';
    $challan['dispatch_advice_id'] = (string) ($advice['id'] ?? '');
    $challan['dispatch_advice_no'] = (string) ($advice['dispatch_advice_no'] ?? '');
    $challan['dispatch_advice_acceptance_ref'] = (string) ($advice['customer_acceptance']['acceptance_ref'] ?? $challan['dispatch_advice_acceptance_ref'] ?? '');
    $challan['linked_quote_id'] = (string) ($challan['linked_quote_id'] ?: ($advice['quotation_id'] ?? ''));
    $challan['quote_id'] = (string) ($challan['quote_id'] ?: ($advice['quotation_id'] ?? ''));
    $challan['linked_quote_no'] = (string) ($challan['linked_quote_no'] ?: ($advice['quotation_no'] ?? ''));
    $challan['dispatch_status'] = safe_text((string) ($challan['dispatch_status'] ?? '')) ?: 'not_dispatched';
    $challan['workflow_status'] = safe_text((string) ($challan['workflow_status'] ?? '')) ?: 'created';
    if (safe_text((string) ($challan['delivery_address'] ?? '')) === '') {
        $challan['delivery_address'] = (string) ($advice['delivery_address'] ?? '');
    }
    if (safe_text((string) ($challan['customer_snapshot']['name'] ?? '')) === '') {
        $challan['customer_snapshot']['name'] = (string) ($advice['customer_name'] ?? '');
    }
    if (safe_text((string) ($challan['customer_snapshot']['mobile'] ?? '')) === '') {
        $challan['customer_snapshot']['mobile'] = (string) ($advice['customer_mobile'] ?? '');
    }
    $changed = $before != $challan;
    if ($changed) {
        $challan['repair_audit'][] = ['event' => 'dispatch_advice_link_repaired', 'reason' => (string) ($link['reason'] ?? ''), 'dispatch_advice_id' => (string) ($advice['id'] ?? ''), 'at' => date('c')];
        $challan['updated_at'] = date('c');
        if ($save) {
            documents_save_challan($challan);
        }
    }
    return ['ok' => true, 'changed' => $changed, 'reason' => (string) ($link['reason'] ?? ''), 'advice' => $advice];
}

function documents_challan_for_dispatch_advice(string $id): ?array { foreach(documents_list_challans() as $c){$link=documents_challan_dispatch_advice_link($c);if(!empty($link['found'])&&(string)($link['advice']['id']??'')===$id)return $c;}return null; }
function documents_dispatch_advice_has_current_acceptance(array $d): bool { $e=(array)($d['customer_acceptance']??[]); if(empty($e['confirmed_at'])&&empty($d['accepted_at'])&&empty($d['acknowledged_at']))return false; if((string)($e['document_id']??($d['id']??''))!==''&&(string)($e['document_id']??'')!==''&&(string)$e['document_id']!==(string)($d['id']??''))return false; if((string)($e['document_no']??'')!==''&&(string)$e['document_no']!==(string)($d['dispatch_advice_no']??''))return false; if((int)($e['document_version']??($d['revision_no']??1))!==(int)($d['revision_no']??1))return false; return true; }
function documents_dispatch_advice_challan_eligible(array $d): bool { $status=strtolower((string)($d['status']??'')); if(in_array($status,['draft','archived','cancelled','superseded'],true)||!empty($d['archived_flag'])||!empty($d['supersedes_id']))return false; $q=documents_get_quote((string)($d['quotation_id']??'')); return documents_dispatch_advice_has_current_acceptance($d)&&$q&&documents_dispatch_quote_eligible($q); }
function documents_challan_workflow_status(array $dispatchAdvice, ?array $challan): string { if(!$challan)return 'Pending'; if(in_array(strtolower((string)($challan['status']??'')),['archived','cancelled'],true)||!empty($challan['archived_flag']))return 'Archived_Cancelled'; if(!empty($challan['customer_acceptance']['confirmed_at'])||!empty($challan['customer_receipt']['confirmed_at'])||!empty($challan['delivered_at']))return 'Delivered'; if((string)($challan['dispatch_status']??'')==='dispatched'||!empty($challan['dispatched_at']))return 'Dispatched'; return 'Created'; }
function documents_create_draft_challan_from_dispatch_advice(array &$d): array { return documents_create_challan_from_accepted_dispatch_advice($d, ['role'=>'system','name'=>'System']); }
function documents_create_challan_from_accepted_dispatch_advice(array &$d,array $actor=[]): array { $existing=documents_challan_for_dispatch_advice((string)$d['id']);if($existing){$d['generated_challan_id']=$existing['id'];return ['ok'=>true,'challan'=>$existing,'created'=>false];} if(!documents_dispatch_advice_challan_eligible($d))return ['ok'=>false,'error'=>'Dispatch Advice is not eligible for Challan creation.']; $num=documents_generate_challan_number((string)$d['segment']);if(empty($num['ok']))return $num;$c=documents_challan_defaults();$c['id']='dc_'.date('YmdHis').'_'.bin2hex(random_bytes(3));$c['challan_no']=$c['dc_number']=$num['challan_no'];$c['status']='draft';$c['workflow_status']='created';$c['dispatch_status']='not_dispatched';$c['workflow_source_type']='dispatch_advice';$c['source_type']='dispatch_advice';$c['dispatch_advice_id']=$d['id'];$c['dispatch_advice_no']=$d['dispatch_advice_no'];$c['dispatch_advice_acceptance_ref']=$d['customer_acceptance']['acceptance_ref']??'';$c['linked_quote_id']=$c['quote_id']=$d['quotation_id'];$c['linked_quote_no']=$d['quotation_no'];$c['agreement_id']=$d['agreement_id'];$c['customer_snapshot']=array_merge(documents_customer_snapshot_defaults(),['name'=>$d['customer_name'],'mobile'=>$d['customer_mobile'],'address'=>$d['delivery_address']]);$c['customer_mobile']=$d['customer_mobile'];$c['customer_name_snapshot']=$d['customer_name'];$c['delivery_address']=$d['delivery_address'];$c['site_address']=$d['delivery_address'];$c['delivery_date']=$d['planned_dispatch_date'];$c['items']=documents_challan_items_from_dispatch_advice($d);$c['material_snapshot_locked']=true;$c['created_by']=$actor;$c['created_at']=$c['updated_at']=date('c');$r=documents_save_challan($c);if(empty($r['ok']))return $r;$saved=documents_get_challan((string)$c['id']);foreach(['workflow_source_type','dispatch_advice_id','dispatch_advice_no','dispatch_advice_acceptance_ref','linked_quote_id','linked_quote_no','workflow_status','dispatch_status'] as $k){if(!$saved||safe_text((string)($saved[$k]??''))=== '')return ['ok'=>false,'error'=>'Challan source link did not persist: '.$k];}$d['generated_challan_id']=$c['id'];$d['generated_challan_at']=date('c');documents_save_dispatch_advice($d);return ['ok'=>true,'challan'=>$c,'created'=>true]; }
function documents_challan_materials_match_dispatch_advice(array $d,array $c): bool { $a=array_map(static fn($i)=>[(string)($i['line_id']??''),(string)($i['name']??''),(string)($i['description']??''),(string)($i['brand_model']??''),(float)($i['qty']??0),(string)($i['unit']??''),(string)($i['remarks']??'')],documents_normalize_dispatch_advice_items((array)($d['items']??[]))); $b=array_map(static fn($i)=>[(string)($i['source_dispatch_advice_line_id']??''),(string)($i['name']??''),(string)($i['description']??''),(string)($i['brand_model']??''),(float)($i['qty']??0),(string)($i['unit']??''),(string)($i['remarks']??'')],documents_normalize_challan_items((array)($c['items']??[]))); return $a===$b; }
function documents_mark_challan_dispatched(array $c,array $actor,array $input): array { if((string)($c['dispatch_status']??'')==='dispatched')return ['ok'=>true,'challan'=>$c,'changed'=>false]; documents_repair_challan_dispatch_advice_link($c, false); $d=documents_get_dispatch_advice((string)($c['dispatch_advice_id']??'')); if(!$d||!documents_dispatch_advice_challan_eligible($d))return ['ok'=>false,'error'=>'Linked accepted Dispatch Advice is required.']; if(!documents_challan_materials_match_dispatch_advice($d,$c))return ['ok'=>false,'error'=>'Challan materials differ from the accepted Dispatch Advice. Create a revised Dispatch Advice first.']; $date=safe_text((string)($input['delivery_date']??$input['dispatch_date']??$c['delivery_date']??'')); if($date==='')return ['ok'=>false,'error'=>'Dispatch date is required.']; if(!documents_challan_has_valid_items($c))return ['ok'=>false,'error'=>'At least one material line is required.']; foreach(['vehicle_no','driver_name','driver_mobile','eway_bill_ref','delivery_notes','dispatch_time'] as $k)$c[$k]=safe_text((string)($input[$k]??$c[$k]??'')); $c['delivery_date']=$date;$c['dispatch_status']='dispatched';$c['workflow_status']='dispatched';$c['status']='final';$c['dispatched_at']=date('c');$c['dispatched_by']=$actor;$c['material_snapshot_locked']=true;$c['public_share_enabled']=true;if(safe_text((string)($c['public_token']??''))==='')$c['public_token']=bin2hex(random_bytes(32));$c['updated_at']=date('c');$r=documents_save_challan($c);return empty($r['ok'])?$r:['ok'=>true,'challan'=>$c,'changed'=>true]; }
function documents_get_challan_by_public_token(string $token): ?array { $token=trim($token); if($token==='')return null; foreach(documents_list_challans() as $c){$public=(string)($c['public_token']??''); if($public!==''&&hash_equals($public,$token))return $c;} return null; }
function documents_challan_public_url(array $c): string { $token=safe_text((string)($c['public_token']??'')); if($token==='')return ''; $scheme=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https://':'http://'; return $scheme.($_SERVER['HTTP_HOST']??'localhost').'/challan-public.php?token='.rawurlencode($token); }
function documents_challan_share_message(array $c,array $d=[],array $company=[]): string { $companyName=(string)($company['brand_name']??$company['company_name']??'Dakshayani Enterprises'); return "Namaste ".($c['customer_snapshot']['name']??'Customer')." Sir/Madam,\n\nYour Delivery Challan ".($c['challan_no']??'')." for quotation ".($c['linked_quote_no']??'')." has been prepared by {$companyName}.\n\nDispatch Advice reference: ".($c['dispatch_advice_no']??'')."\nDispatch date: ".($c['delivery_date']??'')."\nDelivery location: ".($c['delivery_address']??'')."\nNumber of listed items: ".count((array)($c['items']??[]))."\n\nPlease review the dispatched materials using this secure link:\n".documents_challan_public_url($c)."\nAfter receiving the materials, please click Confirm Delivery on the page and complete the registered-mobile verification.\n\nIf any item is missing or damaged, mention it in the remarks before confirming.\n\nRegards,\n{$companyName}\n".($company['phone_primary']??''); }

function documents_validate_dispatch_advice(array $d): array { $q=documents_get_quote((string)$d['quotation_id']);if(!$q||!documents_dispatch_quote_eligible($q))return ['ok'=>false,'error'=>'Dispatch Advice requires an accepted, current, non-archived quotation.'];if(safe_text((string)$d['customer_name'])===''||normalize_customer_mobile((string)$d['customer_mobile'])===''||safe_text((string)$d['delivery_address'])==='')return ['ok'=>false,'error'=>'Customer name, valid mobile, and delivery address are required.'];if(empty($d['planned_dispatch_date'])&&empty($d['date_to_be_confirmed']))return ['ok'=>false,'error'=>'Choose a dispatch date or mark date to be confirmed.'];if(!documents_normalize_dispatch_advice_items((array)$d['items']))return ['ok'=>false,'error'=>'Select at least one item with quantity and unit.'];return ['ok'=>true,'error'=>'']; }

function documents_dispatch_advice_apply_admin_transition(array $d, string $action, array $actor = [], array $context = []): array
{
    $action = strtolower($action);
    $status = strtolower((string)($d['status'] ?? 'draft'));
    if ($action === 'archive') {
        if ($status === 'archived') return ['ok'=>true,'changed'=>false,'document'=>$d,'message'=>'Already archived.'];
        if ($status === 'cancelled') return ['ok'=>false,'changed'=>false,'document'=>$d,'message'=>'Cancelled Dispatch Advice is already in the archive bucket.'];
        $d['pre_archive_status'] = $status;
        $d['status'] = 'archived'; $d['archived_flag'] = true; $d['archived_at'] = date('c');
    } elseif ($action === 'cancel') {
        if ($status === 'cancelled') return ['ok'=>true,'changed'=>false,'document'=>$d,'message'=>'Already cancelled.'];
        $reason = safe_text((string)($context['reason'] ?? ''));
        if ($reason === '') return ['ok'=>false,'changed'=>false,'document'=>$d,'message'=>'Cancellation reason is required.'];
        $challan = documents_challan_for_dispatch_advice((string)($d['id'] ?? ''));
        if ($challan && documents_challan_workflow_status($d, $challan) !== 'Created') return ['ok'=>false,'changed'=>false,'document'=>$d,'message'=>'Cannot bulk cancel after Challan dispatch/delivery.'];
        $d['pre_cancel_status'] = $status; $d['status'] = 'cancelled'; $d['cancel_reason'] = $reason; $d['cancelled_at'] = date('c');
    } elseif ($action === 'restore') {
        if (!in_array($status, ['archived','cancelled'], true) && empty($d['archived_flag'])) return ['ok'=>false,'changed'=>false,'document'=>$d,'message'=>'Only archived/cancelled records can be restored.'];
        $prior = safe_text((string)($d['pre_cancel_status'] ?? $d['pre_archive_status'] ?? ''));
        if ($prior === '' || in_array($prior, ['archived','cancelled','superseded'], true)) return ['ok'=>false,'changed'=>false,'document'=>$d,'message'=>'No safe prior status is available.'];
        $d['status'] = $prior; $d['archived_flag'] = false; $d['restored_at'] = date('c');
    } else return ['ok'=>false,'changed'=>false,'document'=>$d,'message'=>'Unsupported action.'];
    $d['updated_at'] = date('c'); $d['status_audit'][] = ['event'=>$action,'at'=>date('c'),'actor'=>$actor,'reason'=>$context['reason'] ?? ''];
    documents_save_dispatch_advice($d); return ['ok'=>true,'changed'=>true,'document'=>$d,'message'=>'Updated.'];
}

function documents_challan_apply_admin_transition(array $c, string $action, array $actor = [], array $context = []): array
{
    $action = strtolower($action); $status = strtolower((string)($c['status'] ?? 'draft')); $wf = documents_challan_workflow_status([], $c);
    if ($action === 'archive') { if ($status === 'archived') return ['ok'=>true,'changed'=>false,'document'=>$c,'message'=>'Already archived.']; $c['pre_archive_status']=$status; $c['status']='archived'; $c['archived_flag']=true; $c['archived_at']=date('c'); }
    elseif ($action === 'cancel') { $reason=safe_text((string)($context['reason']??'')); if($reason==='') return ['ok'=>false,'changed'=>false,'document'=>$c,'message'=>'Cancellation reason is required.']; if(in_array($wf,['Dispatched','Delivered'],true)) return ['ok'=>false,'changed'=>false,'document'=>$c,'message'=>'Cannot bulk cancel dispatched/delivered Challans.']; $c['pre_cancel_status']=$status; $c['status']='cancelled'; $c['cancel_reason']=$reason; $c['cancelled_at']=date('c'); }
    elseif ($action === 'restore') { if(!in_array($status,['archived','cancelled'],true)&&empty($c['archived_flag'])) return ['ok'=>false,'changed'=>false,'document'=>$c,'message'=>'Only archived/cancelled Challans can be restored.']; $prior=safe_text((string)($c['pre_cancel_status']??$c['pre_archive_status']??'')); if($prior===''||in_array($prior,['archived','cancelled'],true)) return ['ok'=>false,'changed'=>false,'document'=>$c,'message'=>'No safe prior status is available.']; if(!empty($c['delivered_at']) && $prior==='draft') $prior='final'; $c['status']=$prior; $c['archived_flag']=false; $c['restored_at']=date('c'); }
    else return ['ok'=>false,'changed'=>false,'document'=>$c,'message'=>'Unsupported action.'];
    $c['updated_at']=date('c'); $c['status_audit'][]=['event'=>$action,'at'=>date('c'),'actor'=>$actor,'reason'=>$context['reason']??'']; documents_save_challan($c); return ['ok'=>true,'changed'=>true,'document'=>$c,'message'=>'Updated.'];
}

function documents_payment_requests_path(): string
{
    return documents_base_dir() . '/payment_requests.json';
}

function documents_ensure_payment_request_store(): void
{
    $path = documents_payment_requests_path();
    $dir = dirname($path);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (!is_file($path)) { json_save($path, []); }
}

function documents_list_payment_requests(): array
{
    documents_ensure_payment_request_store();
    $rows = json_load(documents_payment_requests_path(), []);
    $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
    return $rows;
}

function documents_save_payment_requests(array $rows): array
{
    documents_ensure_payment_request_store();
    return json_save(documents_payment_requests_path(), array_values(array_filter($rows, 'is_array')));
}

function documents_generate_payment_request_id(): string
{
    return documents_generate_simple_document_id('PAYREQ');
}

function documents_payment_request_defaults(): array
{
    return ['id'=>'','quotation_id'=>'','customer_mobile'=>'','customer_name'=>'','quotation_amount'=>0,'amount_requested'=>0,'amount_paid_against_request'=>0,'outstanding_against_request'=>0,'reason'=>'','custom_reason'=>'','message'=>'','due_date'=>'','status'=>'draft','visibility_to_customer'=>true,'request_mode'=>'portal_only','sent_via'=>'','sent_at'=>'','sent_by'=>['role'=>'','id'=>'','name'=>''],'created_by'=>['role'=>'','id'=>'','name'=>''],'created_at'=>'','updated_at'=>'','linked_receipt_ids'=>[],'internal_notes'=>'','customer_response'=>'','follow_up_date'=>'','archived_flag'=>false,'archived_at'=>'','archived_by'=>['role'=>'','id'=>'','name'=>'']];
}

function documents_get_payment_request(string $id): ?array
{
    foreach (documents_list_payment_requests() as $row) { if ((string)($row['id'] ?? '') === $id) { return array_merge(documents_payment_request_defaults(), $row); } }
    return null;
}

function documents_save_payment_request(array $request): array
{
    $base = documents_payment_request_defaults();
    $request = array_merge($base, $request);
    if ((string)$request['id'] === '') { $request['id'] = documents_generate_payment_request_id(); }
    if ((string)$request['created_at'] === '') { $request['created_at'] = date('c'); }
    $request['updated_at'] = date('c');
    $request['customer_mobile'] = normalize_customer_mobile((string)$request['customer_mobile']);
    $request['archived_flag'] = !empty($request['archived_flag']);
    $request['archived_by'] = is_array($request['archived_by'] ?? null) ? array_merge(['role'=>'','id'=>'','name'=>''], $request['archived_by']) : ['role'=>'','id'=>'','name'=>''];
    $request['amount_requested'] = round((float)$request['amount_requested'], 2);
    $request['amount_paid_against_request'] = round((float)$request['amount_paid_against_request'], 2);
    $request['outstanding_against_request'] = max(0, round($request['amount_requested'] - $request['amount_paid_against_request'], 2));
    $rows = documents_list_payment_requests(); $found = false;
    foreach ($rows as $i => $row) { if ((string)($row['id'] ?? '') === (string)$request['id']) { $rows[$i] = $request; $found = true; break; } }
    if (!$found) { $rows[] = $request; }
    return documents_save_payment_requests($rows);
}

function documents_payment_requests_by_quote(string $quoteId): array
{
    return array_values(array_filter(documents_list_payment_requests(), static fn(array $r): bool => (string)($r['quotation_id'] ?? '') === $quoteId));
}

function documents_payment_requests_by_mobile(string $mobile): array
{
    $mobile = normalize_customer_mobile($mobile);
    return array_values(array_filter(documents_list_payment_requests(), static fn(array $r): bool => normalize_customer_mobile((string)($r['customer_mobile'] ?? '')) === $mobile));
}

function documents_final_receipts_for_quote(string $quoteId): array
{
    return array_values(array_filter(documents_list_sales_documents('receipt'), static function(array $r) use ($quoteId): bool { return (string)($r['quotation_id'] ?? '') === $quoteId && strtolower(trim((string)($r['status'] ?? ''))) === 'final' && empty($r['archived_flag']) && empty($r['archived']); }));
}

function documents_payment_request_refresh_from_receipts(array $request): array
{
    $paid = 0.0; $linked = is_array($request['linked_receipt_ids'] ?? null) ? $request['linked_receipt_ids'] : [];
    foreach (documents_final_receipts_for_quote((string)($request['quotation_id'] ?? '')) as $receipt) {
        if ($linked !== [] && !in_array((string)($receipt['id'] ?? ''), $linked, true)) { continue; }
        if ($linked === []) { continue; }
        $paid += (float)($receipt['amount_rs'] ?? $receipt['amount_received'] ?? $receipt['amount'] ?? 0);
    }
    $request['amount_paid_against_request'] = round($paid, 2);
    $request['outstanding_against_request'] = max(0, round((float)($request['amount_requested'] ?? 0) - $paid, 2));
    $status = strtolower((string)($request['status'] ?? 'draft'));
    if (!in_array($status, ['cancelled'], true) && $paid > 0) { $request['status'] = $request['outstanding_against_request'] <= 0 ? 'paid' : 'partially_paid'; }
    return $request;
}

function documents_payment_summary_for_quote(array $quote, array $receipts = null): array
{
    $project = documents_project_financial_summary($quote, $receipts);
    $quoteId = (string)($quote['id'] ?? '');
    $requests = documents_payment_requests_by_quote($quoteId);
    $active = array_values(array_filter($requests, static fn(array $r): bool => empty($r['archived_flag']) && !in_array(strtolower((string)($r['status'] ?? '')), ['cancelled','paid'], true)));
    return array_merge($project, ['quotation_amount'=>$project['calculation_reference_amount'], 'project_quotation_amount'=>$project['quotation_amount'], 'total_received'=>$project['total_payment_received'], 'outstanding'=>$project['remaining_amount'], 'requests'=>$requests, 'active_request_count'=>count($active), 'last_request'=>$requests[0] ?? null]);
}

function documents_payment_request_reason_label(array $request): string
{
    return (string)($request['reason'] ?? '') === 'Custom Reason' ? (string)($request['custom_reason'] ?? '') : (string)($request['reason'] ?? '');
}

function documents_build_payment_request_message(array $request, array $summary = []): string
{
    $fmt = static fn($n): string => '₹' . number_format((float)$n, 2);
    $lines = ['Dear ' . ((string)($request['customer_name'] ?? '') ?: 'Customer') . ',', '', 'This is a payment request from Dakshayani Enterprises for your solar project.', '', 'Requested Amount: ' . $fmt($request['amount_requested'] ?? 0), 'Reason: ' . documents_payment_request_reason_label($request)];
    if ((string)($request['due_date'] ?? '') !== '') { $lines[] = 'Due Date: ' . (string)$request['due_date']; }
    $lines[] = 'Project / Quotation Reference: ' . (string)($request['quotation_id'] ?? '');
    $lines[] = 'Total Project Amount: ' . $fmt($request['quotation_amount'] ?? ($summary['quotation_amount'] ?? 0));
    $lines[] = 'Total Paid So Far: ' . $fmt($summary['total_received'] ?? 0);
    $lines[] = 'Total Outstanding: ' . $fmt($summary['outstanding'] ?? 0);
    if (trim((string)($request['message'] ?? '')) !== '') { $lines[] = ''; $lines[] = (string)$request['message']; }
    $lines[] = ''; $lines[] = 'Kindly make the payment so that we can proceed with the next stage of work.'; $lines[] = ''; $lines[] = 'Regards,'; $lines[] = 'Dakshayani Enterprises';
    return implode("\n", $lines);
}

function documents_payment_request_whatsapp_url(array $request, string $message): string
{
    $digits = preg_replace('/\D+/', '', (string)($request['customer_mobile'] ?? '')) ?? '';
    if (strlen($digits) === 10) { $digits = '91' . $digits; }
    return $digits !== '' ? 'https://wa.me/' . rawurlencode($digits) . '?text=' . rawurlencode($message) : 'https://wa.me/?text=' . rawurlencode($message);
}

function documents_payment_request_mailto(array $request, string $message): string
{
    $subject = 'Payment Request - Dakshayani Enterprises - ' . (string)($request['customer_name'] ?? '') . ' - ' . (string)($request['quotation_id'] ?? '');
    return 'mailto:?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($message);
}


function documents_payment_request_is_archived(array $request): bool
{
    return !empty($request['archived_flag']);
}

function documents_payment_request_archive(array $request, array $byUser = []): array
{
    $request = array_merge(documents_payment_request_defaults(), $request);
    $request['archived_flag'] = true;
    $request['archived_at'] = date('c');
    $request['archived_by'] = [
        'role' => safe_text((string) ($byUser['role'] ?? $byUser['type'] ?? '')),
        'id' => safe_text((string) ($byUser['id'] ?? '')),
        'name' => safe_text((string) ($byUser['name'] ?? '')),
    ];
    return $request;
}

function documents_payment_request_unarchive(array $request): array
{
    $request = array_merge(documents_payment_request_defaults(), $request);
    $request['archived_flag'] = false;
    $request['archived_at'] = '';
    $request['archived_by'] = ['role'=>'','id'=>'','name'=>''];
    return $request;
}
