<?php
declare(strict_types=1);

ob_start();

$adminQuotationsLogDir = __DIR__ . '/data/logs';
if (!is_dir($adminQuotationsLogDir)) {
    @mkdir($adminQuotationsLogDir, 0775, true);
}

$adminQuotationsErrorLog = $adminQuotationsLogDir . '/admin-quotations-errors.log';
$adminQuotationsTraceLog = $adminQuotationsLogDir . '/admin-quotations-trace.log';

ini_set('log_errors', '1');
ini_set('error_log', $adminQuotationsErrorLog);
ini_set('display_errors', '0');
error_reporting(E_ALL);

$adminQuotationsWriteErrorLog = static function (string $message) use ($adminQuotationsErrorLog): void {
    error_log('[admin-quotations] ' . $message);
    @error_log('[' . date('c') . '] ' . $message . PHP_EOL, 3, $adminQuotationsErrorLog);
};

set_exception_handler(static function (Throwable $exception) use ($adminQuotationsWriteErrorLog): void {
    $adminQuotationsWriteErrorLog(sprintf(
        'uncaught_exception message="%s" file=%s line=%d trace=%s',
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        str_replace(["\n", "\r"], ' | ', $exception->getTraceAsString())
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo 'Quotation module encountered an error. Please contact admin.';
});

register_shutdown_function(static function () use ($adminQuotationsWriteErrorLog): void {
    $lastError = error_get_last();
    if (!is_array($lastError)) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array((int) ($lastError['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    $adminQuotationsWriteErrorLog(sprintf(
        'fatal_error type=%d message="%s" file=%s line=%d',
        (int) ($lastError['type'] ?? 0),
        (string) ($lastError['message'] ?? ''),
        (string) ($lastError['file'] ?? ''),
        (int) ($lastError['line'] ?? 0)
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo 'Quotation module encountered an error. Please contact admin.';
});

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
documents_repair_broken_quote_revisions();
require_once __DIR__ . '/includes/solar_finance_reports.php';

require_login_any_role(['admin', 'employee']);
documents_ensure_structure();
documents_seed_template_sets_if_empty();

$templatesRaw = json_load(documents_templates_dir() . '/template_sets.json', []);
if (!is_array($templatesRaw)) {
    $templatesRaw = [];
    @error_log('[' . date('c') . '] template_sets invalid JSON payload; defaulted to []' . PHP_EOL, 3, $adminQuotationsErrorLog);
}
$templates = array_values(array_filter($templatesRaw, static function ($row): bool {
    return is_array($row) && !($row['archived_flag'] ?? false);
}));
$templateBlocks = documents_sync_template_block_entries($templates);
$templateBlocks = is_array($templateBlocks) ? $templateBlocks : [];
$defaultTemplateNameForNewQuote = 'pm surya ghar - residential (subsidy) (res)';
$resolveDefaultTemplateIdForNewQuote = static function (array $templateRows) use ($defaultTemplateNameForNewQuote): string {
    foreach ($templateRows as $tplRow) {
        $templateName = trim((string) ($tplRow['name'] ?? ''));
        $segmentName = trim((string) ($tplRow['segment'] ?? ''));
        $displayName = trim($templateName . ($segmentName !== '' ? (' (' . $segmentName . ')') : ''));
        if (strcasecmp($displayName, $defaultTemplateNameForNewQuote) === 0) {
            return safe_text((string) ($tplRow['id'] ?? ''));
        }
    }
    return '';
};
$company = load_company_profile();
$quoteDefaults = load_quote_defaults();
$quoteDefaults = is_array($quoteDefaults) ? $quoteDefaults : documents_quote_defaults_settings();
$inventoryComponents = documents_inventory_components(false);
$inventoryComponents = is_array($inventoryComponents) ? $inventoryComponents : [];
$inventoryKits = documents_inventory_kits(false);
$inventoryKits = is_array($inventoryKits) ? $inventoryKits : [];
$inventoryTaxProfiles = documents_inventory_tax_profiles(false);
$inventoryTaxProfiles = is_array($inventoryTaxProfiles) ? $inventoryTaxProfiles : [];
$inventoryVariants = documents_inventory_component_variants(false);
$inventoryVariants = is_array($inventoryVariants) ? $inventoryVariants : [];
$variantsByComponent = [];
foreach ($inventoryVariants as $variant) {
    $componentId = (string) ($variant['component_id'] ?? '');
    if ($componentId === '') {
        continue;
    }
    if (!isset($variantsByComponent[$componentId])) {
        $variantsByComponent[$componentId] = [];
    }
    $variantsByComponent[$componentId][] = $variant;
}

$redirectWith = static function (string $type, string $msg): void {
    header('Location: admin-quotations.php?' . http_build_query(['status' => $type, 'message' => $msg]));
    exit;
};

$isAjaxRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
    || strpos(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json') !== false
    || safe_text((string) ($_POST['ajax'] ?? '')) === '1';
$respondAction = static function (bool $ok, string $message, array $extra = []) use ($isAjaxRequest, $redirectWith): void {
    if ($isAjaxRequest) {
        http_response_code($ok ? 200 : 422);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
        exit;
    }
    $redirectWith($ok ? 'success' : 'error', $message);
};
$quoteActionState = static function (array $quote): array {
    $quote = documents_quote_prepare($quote);
    return [
        'quote_id' => (string) ($quote['id'] ?? ''),
        'status' => documents_quote_normalize_status((string) ($quote['status'] ?? 'draft')),
        'status_label' => documents_status_label($quote, 'admin'),
        'updated_at' => (string) ($quote['updated_at'] ?? ''),
        'archived_flag' => documents_is_archived($quote),
        'locked_flag' => documents_quote_is_locked($quote),
        'public_share_enabled' => !empty($quote['public_share_enabled']),
    ];
};

$traceRole = 'guest';
$traceUser = current_user();
if (is_array($traceUser) && safe_text((string) ($traceUser['role_name'] ?? '')) !== '') {
    $traceRole = safe_text((string) ($traceUser['role_name'] ?? ''));
}
$traceMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$traceAction = safe_text((string) ($_POST['action'] ?? $_GET['action'] ?? ''));
$traceTab = safe_text((string) ($_POST['tab'] ?? $_GET['tab'] ?? ''));
$traceQuoteId = safe_text((string) ($_POST['quote_id'] ?? $_GET['quote_id'] ?? $_GET['edit'] ?? ''));
$traceItemType = safe_text((string) ($_POST['item_type'] ?? $_GET['item_type'] ?? ''));
if ($traceItemType === '' && is_array($_POST['quote_item_type'] ?? null)) {
    $traceItemType = implode(',', array_values(array_filter(array_map(static fn($type): string => safe_text((string) $type), $_POST['quote_item_type']), static fn(string $type): bool => $type !== '')));
}
@error_log(sprintf(
    "[%s] role=%s method=%s action=%s tab=%s quote_id=%s item_type=%s\n",
    date('c'),
    $traceRole,
    $traceMethod,
    $traceAction,
    $traceTab,
    $traceQuoteId,
    $traceItemType
), 3, $adminQuotationsTraceLog);

$sanitizeHexColor = static function ($raw, string $fallback = ''): string {
    $value = strtoupper(trim((string) $raw));
    if (preg_match('/^#([0-9A-F]{3})$/', $value, $m)) {
        return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
    }
    if (preg_match('/^#([0-9A-F]{6})$/', $value)) {
        return $value;
    }
    return $fallback;
};

$quotationExtractMobile = static function (array $quote): string {
    $snapshot = is_array($quote['customer_snapshot'] ?? null) ? $quote['customer_snapshot'] : [];
    $source = is_array($quote['source'] ?? null) ? $quote['source'] : [];
    $candidates = [
        (string) ($quote['customer_mobile'] ?? ''),
        (string) ($quote['mobile'] ?? ''),
        (string) ($snapshot['customer_mobile'] ?? ''),
        (string) ($snapshot['mobile'] ?? ''),
        (string) ($source['lead_mobile'] ?? ''),
    ];
    foreach ($candidates as $candidate) {
        $normalized = documents_normalize_whatsapp_mobile($candidate);
        if ($normalized !== '') {
            return $normalized;
        }
    }
    return '';
};

$quotationPublicShareUrl = static function (array $quote): string {
    $token = safe_text((string) ($quote['public_share_token'] ?? ''));
    if ($token === '') {
        return '';
    }
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    return $scheme . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/quotation-public.php?t=' . urlencode($token);
};

$quotationDefaultWhatsappTemplate = 'Namaste {{name}}, your quotation for {{system_size}} kW {{system_type}} solar system for {{city}} is ready. Total price considered is ₹{{price}}. Please open the quotation link and click Accept Quotation after reviewing it: {{quotation_link}}';

$quotationResolveWhatsappTemplate = static function (array $defaults) use ($quotationDefaultWhatsappTemplate): string {
    $configured = trim((string) ($defaults['global']['quotation_ui']['whatsapp_message_template'] ?? ''));
    return $configured !== '' ? $configured : $quotationDefaultWhatsappTemplate;
};

$quotationResolveCustomerFacingPrice = static function (array $quote): float {
    $amount = (float) ($quote['input_total_gst_inclusive'] ?? 0);
    if ($amount > 0) {
        return $amount;
    }

    $primaryScenario = safe_text((string) ($quote['primary_finance_scenario'] ?? ''));
    $scenarioPrices = is_array($quote['scenario_prices'] ?? null) ? $quote['scenario_prices'] : [];
    $scenarioMap = [
        'self_funded' => 'self_funded',
        'loan_upto_2_lacs_subsidy_to_loan' => 'loan_upto_2_lacs',
        'loan_upto_2_lacs_subsidy_not_to_loan' => 'loan_upto_2_lacs',
        'loan_above_2_lacs_subsidy_to_loan' => 'loan_above_2_lacs',
        'loan_above_2_lacs_subsidy_not_to_loan' => 'loan_above_2_lacs',
        'loan_upto_2_lacs' => 'loan_upto_2_lacs',
        'loan_above_2_lacs' => 'loan_above_2_lacs',
    ];
    $scenarioKey = $scenarioMap[$primaryScenario] ?? '';
    if ($scenarioKey !== '') {
        $scenarioAmount = (float) ($scenarioPrices[$scenarioKey]['price'] ?? 0);
        if ($scenarioAmount > 0) {
            return $scenarioAmount;
        }
    }

    $calc = is_array($quote['calc'] ?? null) ? $quote['calc'] : [];
    $finalInclGst = (float) ($calc['final_price_incl_gst'] ?? 0);
    if ($finalInclGst > 0) {
        return $finalInclGst;
    }
    return (float) ($calc['grand_total'] ?? 0);
};

$quotationResolveWhatsappMessage = static function (array $quote, string $template, string $shareUrl = '') use ($quotationResolveCustomerFacingPrice): string {
    $snapshot = is_array($quote['customer_snapshot'] ?? null) ? $quote['customer_snapshot'] : [];
    $capacity = trim((string) ($quote['capacity_kwp'] ?? ''));
    if ($capacity === '') {
        $systemCapacity = (float) ($quote['system_capacity_kwp'] ?? 0);
        $capacity = $systemCapacity > 0 ? rtrim(rtrim(number_format($systemCapacity, 2, '.', ''), '0'), '.') : '';
    }

    $amount = $quotationResolveCustomerFacingPrice($quote);
    $replacements = [
        '{{name}}' => safe_text((string) ($quote['customer_name'] ?? $snapshot['name'] ?? '')),
        '{{city}}' => safe_text((string) ($quote['city'] ?? $snapshot['city'] ?? '')),
        '{{system_size}}' => safe_text($capacity),
        '{{system_type}}' => safe_text((string) ($quote['system_type'] ?? '')),
        '{{price}}' => $amount > 0 ? number_format($amount, 2, '.', '') : '',
        '{{quotation_link}}' => trim($shareUrl),
    ];

    $resolved = strtr($template, $replacements);
    return trim(preg_replace('/[ 	]+/', ' ', $resolved) ?? $resolved);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $respondAction(false, 'Security validation failed.');
    }

    $action = safe_text($_POST['action'] ?? '');
    if ($action === 'save_settings') {
        $d = load_quote_defaults();
        $decodeRateChart = static function (string $field, string $label, array $existing) use ($redirectWith): array {
            $raw = trim((string) ($_POST[$field] ?? ''));
            if ($raw === '') {
                return $existing;
            }
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || !array_is_list($decoded)) {
                $detail = json_last_error() === JSON_ERROR_NONE ? 'the top-level value must be a JSON array' : json_last_error_msg();
                $redirectWith('error', $label . ' rate chart JSON is invalid: ' . $detail . '. Existing rate-chart settings were preserved.');
            }
            foreach ($decoded as $index => $row) {
                if (!is_array($row)) {
                    $redirectWith('error', $label . ' rate chart JSON is invalid: row ' . ($index + 1) . ' must be an object. Existing rate-chart settings were preserved.');
                }
            }
            return $decoded;
        };
        $decodedRateChartOnGrid = $decodeRateChart('rate_chart_on_grid_json', 'On-Grid', (array) ($d['rate_chart']['on_grid'] ?? []));
        $decodedRateChartHybrid = $decodeRateChart('rate_chart_hybrid_json', 'Hybrid', (array) ($d['rate_chart']['hybrid'] ?? []));
        foreach (['primary','accent','text','muted_text','page_bg','card_bg','border'] as $k) {
            $existing = (string) ($d['global']['ui_tokens']['colors'][$k] ?? '');
            $d['global']['ui_tokens']['colors'][$k] = $sanitizeHexColor($_POST['ui_' . $k . '_hex'] ?? '', $existing);
        }
        foreach (['header','footer'] as $part) {
            $d['global']['ui_tokens']['gradients'][$part]['enabled'] = isset($_POST[$part . '_gradient_enabled']);
            $gradientAExisting = (string) ($d['global']['ui_tokens']['gradients'][$part]['a'] ?? '');
            $gradientBExisting = (string) ($d['global']['ui_tokens']['gradients'][$part]['b'] ?? '');
            $d['global']['ui_tokens']['gradients'][$part]['a'] = $sanitizeHexColor($_POST[$part . '_gradient_a_hex'] ?? '', $gradientAExisting);
            $d['global']['ui_tokens']['gradients'][$part]['b'] = $sanitizeHexColor($_POST[$part . '_gradient_b_hex'] ?? '', $gradientBExisting);
            $d['global']['ui_tokens']['gradients'][$part]['direction'] = safe_text($_POST[$part . '_gradient_direction'] ?? ($d['global']['ui_tokens']['gradients'][$part]['direction'] ?? 'to right'));
        }
        $headerTextExisting = (string) ($d['global']['ui_tokens']['header_footer']['header_text_color'] ?? '#FFFFFF');
        $footerTextExisting = (string) ($d['global']['ui_tokens']['header_footer']['footer_text_color'] ?? '#FFFFFF');
        $d['global']['ui_tokens']['header_footer']['header_text_color'] = $sanitizeHexColor($_POST['header_text_color_hex'] ?? '', $headerTextExisting);
        $d['global']['ui_tokens']['header_footer']['footer_text_color'] = $sanitizeHexColor($_POST['footer_text_color_hex'] ?? '', $footerTextExisting);
        $d['global']['ui_tokens']['shadow'] = safe_text($_POST['ui_shadow'] ?? ($d['global']['ui_tokens']['shadow'] ?? 'soft'));
        $d['global']['ui_tokens']['typography']['base_px'] = (int)($_POST['ui_base_px'] ?? 14);
        $d['global']['ui_tokens']['typography']['h1_px'] = (int)($_POST['ui_h1_px'] ?? 24);
        $d['global']['ui_tokens']['typography']['h2_px'] = (int)($_POST['ui_h2_px'] ?? 18);
        $d['global']['ui_tokens']['typography']['h3_px'] = (int)($_POST['ui_h3_px'] ?? 16);
        $d['global']['ui_tokens']['typography']['line_height'] = (float)($_POST['ui_line_height'] ?? 1.6);
        foreach (['RES','COM','IND','INST'] as $seg) { $d['segments'][$seg]['unit_rate_rs_per_kwh'] = (float)($_POST['unit_rate_' . $seg] ?? ($d['segments'][$seg]['unit_rate_rs_per_kwh'] ?? 0)); }
        $d['segments']['RES']['loan_bestcase']['interest_pct'] = (float)($_POST['res_interest_pct'] ?? 6.0);
        $d['segments']['RES']['loan_bestcase']['tenure_years'] = (int)($_POST['res_tenure_years'] ?? 10);
        $d['global']['energy_defaults']['annual_generation_per_kw'] = (float)($_POST['annual_generation_per_kw'] ?? 1450);
        $d['global']['energy_defaults']['emission_factor_kg_per_kwh'] = (float)($_POST['emission_factor_kg_per_kwh'] ?? 0.82);
        $d['global']['energy_defaults']['tree_absorption_kg_per_tree_per_year'] = (float)($_POST['tree_absorption_kg_per_tree_per_year'] ?? 20);
        $d['global']['quotation_ui']['show_decimals'] = isset($_POST['show_decimals']);
        $d['global']['quotation_ui']['qr_target'] = safe_text($_POST['qr_target'] ?? 'quotation') === 'website' ? 'website' : 'quotation';
        $d['global']['quotation_ui']['footer_disclaimer'] = trim((string)($_POST['footer_disclaimer'] ?? ($d['global']['quotation_ui']['footer_disclaimer'] ?? '')));
        $d['global']['quotation_ui']['whatsapp_message_template'] = trim((string) ($_POST['whatsapp_message_template'] ?? ($d['global']['quotation_ui']['whatsapp_message_template'] ?? $quotationDefaultWhatsappTemplate)));
        $whyRaw = trim((string)($_POST['why_dakshayani_points'] ?? ''));
        $whyPoints = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $whyRaw) ?: []), static fn($v): bool => $v !== ''));
        if ($whyPoints !== []) {
            $d['global']['quotation_ui']['why_dakshayani_points'] = $whyPoints;
        }
        $decoded = $decodedRateChartOnGrid;
        {
                $d['rate_chart']['on_grid'] = array_values(array_filter(array_map(static function ($row): array {
                    $scenarioModelNumbers = is_array($row['scenario_model_numbers'] ?? null) ? $row['scenario_model_numbers'] : [];
                    return [
                        'model_number' => safe_text((string) ($row['model_number'] ?? '')),
                        'solar_size_kwp' => (float) ($row['solar_size_kwp'] ?? 0),
                        'phase' => safe_text((string) ($row['phase'] ?? '')),
                        'variant' => safe_text((string) ($row['variant'] ?? '')),
                        'self_funded_price' => (float) ($row['self_funded_price'] ?? 0),
                        'loan_upto_2_lacs_price' => (float) ($row['loan_upto_2_lacs_price'] ?? 0),
                        'loan_above_2_lacs_price' => (float) ($row['loan_above_2_lacs_price'] ?? 0),
                        'scenario_model_numbers' => [
                            'self_funded' => safe_text((string) ($scenarioModelNumbers['self_funded'] ?? '')),
                            'loan_upto_2_lacs' => safe_text((string) ($scenarioModelNumbers['loan_upto_2_lacs'] ?? '')),
                            'loan_above_2_lacs' => safe_text((string) ($scenarioModelNumbers['loan_above_2_lacs'] ?? '')),
                        ],
                    ];
                }, $decoded), static fn(array $row): bool => $row['solar_size_kwp'] > 0));
        }
        $decoded = $decodedRateChartHybrid;
        {
                $d['rate_chart']['hybrid'] = array_values(array_filter(array_map(static function ($row): array {
                    $scenarioModelNumbers = is_array($row['scenario_model_numbers'] ?? null) ? $row['scenario_model_numbers'] : [];
                    return [
                        'model_number' => safe_text((string) ($row['model_number'] ?? '')),
                        'solar_size_kwp' => (float) ($row['solar_size_kwp'] ?? 0),
                        'inverter_kva' => (float) ($row['inverter_kva'] ?? 0),
                        'phase' => safe_text((string) ($row['phase'] ?? '')),
                        'battery_count' => (int) ($row['battery_count'] ?? 0),
                        'battery_code' => safe_text((string) ($row['battery_code'] ?? '')),
                        'inverter_code' => safe_text((string) ($row['inverter_code'] ?? '')),
                        'variant' => safe_text((string) ($row['variant'] ?? '')),
                        'self_funded_price' => (float) ($row['self_funded_price'] ?? 0),
                        'loan_upto_2_lacs_price' => (float) ($row['loan_upto_2_lacs_price'] ?? 0),
                        'loan_above_2_lacs_price' => (float) ($row['loan_above_2_lacs_price'] ?? 0),
                        'scenario_model_numbers' => [
                            'self_funded' => safe_text((string) ($scenarioModelNumbers['self_funded'] ?? '')),
                            'loan_upto_2_lacs' => safe_text((string) ($scenarioModelNumbers['loan_upto_2_lacs'] ?? '')),
                            'loan_above_2_lacs' => safe_text((string) ($scenarioModelNumbers['loan_above_2_lacs'] ?? '')),
                        ],
                    ];
                }, $decoded), static fn(array $row): bool => $row['inverter_kva'] > 0 && $row['phase'] !== ''));
        }
        $saved = save_quote_defaults($d);
        if (!($saved['ok'] ?? false)) { $redirectWith('error', 'Unable to save quotation settings.'); }
        header('Location: admin-quotations.php?' . http_build_query(['tab' => 'settings', 'status' => 'success', 'message' => 'Quotation settings saved.']));
        exit;
    }

    if ($action === 'save_quote') {
        $quoteId = safe_text($_POST['quote_id'] ?? '');
        $existing = $quoteId !== '' ? documents_get_quote($quoteId) : null;
        if ($existing !== null) {
            if (documents_quote_is_locked($existing)) {
                $redirectWith('error', 'This quotation is locked because it was accepted. Create a revision to make changes.');
            }
            $existingStatus = documents_quote_normalize_status((string) ($existing['status'] ?? 'draft'));
            if ($existingStatus !== 'draft') {
                $redirectWith('error', 'This quotation is locked because it was accepted. Create a revision to make changes.');
            }
        }

        $templateSetId = safe_text($_POST['template_set_id'] ?? '');
        $selectedTemplate = null;
        foreach ($templates as $tpl) {
            if ((string) ($tpl['id'] ?? '') === $templateSetId) {
                $selectedTemplate = $tpl;
                break;
            }
        }
        if ($selectedTemplate === null) {
            $redirectWith('error', 'Please select a template set.');
        }

        $partyType = safe_text($_POST['party_type'] ?? 'lead');
        $mobile = documents_normalize_mobile((string) ($_POST['customer_mobile'] ?? ''));
        $customerName = safe_text($_POST['customer_name'] ?? '');
        $customerRecord = $partyType === 'customer' ? documents_find_customer_by_mobile($mobile) : null;

        if ($mobile === '' || $customerName === '') {
            $redirectWith('error', 'Customer mobile and name are required.');
        }

        $capacity = safe_text($_POST['capacity_kwp'] ?? '');
        $submittedMainSolarKwpRaw = trim((string) ($_POST['main_solar_kwp'] ?? ''));
        $submittedComplimentaryRaw = trim((string) ($_POST['complimentary_non_dcr_kwp'] ?? ''));
        $submittedMainSolarKwp = safe_text($submittedMainSolarKwpRaw);
        $existingHasMainSolar = $existing !== null && safe_text((string) ($existing['main_solar_kwp'] ?? '')) !== '';
        $shouldUseSplitCapacity = $submittedMainSolarKwp !== '' || $existingHasMainSolar;

        if ($shouldUseSplitCapacity) {
            $mainSolar = (float) $submittedMainSolarKwp;
            $complimentarySolar = $submittedComplimentaryRaw !== '' ? (float) $submittedComplimentaryRaw : 0.0;
            $totalCapacity = $mainSolar + $complimentarySolar;
            if ($mainSolar <= 0) {
                $redirectWith('error', 'Main Solar Size (kWp) must be greater than 0.');
            }
            if ($totalCapacity <= 0) {
                $redirectWith('error', 'Total System Capacity (kWp) must be greater than 0.');
            }
            $capacity = (string) $totalCapacity;
        } elseif ($capacity === '') {
            $redirectWith('error', 'Capacity kWp is required.');
        }


        $pricingMode = safe_text($_POST['pricing_mode'] ?? 'solar_split_70_30');
        if (!in_array($pricingMode, ['solar_split_70_30', 'flat_5', 'itemized'], true)) {
            $pricingMode = 'solar_split_70_30';
        }
        if ($pricingMode === 'itemized') {
            $redirectWith('error', 'Itemized mode is reserved for Phase 3.');
        }

        $placeOfSupply = safe_text($_POST['place_of_supply_state'] ?? 'Jharkhand');
        $companyState = strtolower(trim((string) ($company['state'] ?? 'Jharkhand')));
        $taxType = strtolower($placeOfSupply) === $companyState ? 'CGST_SGST' : 'IGST';

        $annexure = [
            'cover_notes' => safe_text($_POST['ann_cover_notes'] ?? ''),
            'system_inclusions' => safe_text($_POST['ann_system_inclusions'] ?? ''),
            'payment_terms' => safe_text($_POST['ann_payment_terms'] ?? ''),
            'warranty' => safe_text($_POST['ann_warranty'] ?? ''),
            'system_type_explainer' => safe_text($_POST['ann_system_type_explainer'] ?? ''),
            'transportation' => safe_text($_POST['ann_transportation'] ?? ''),
            'terms_conditions' => safe_text($_POST['ann_terms_conditions'] ?? ''),
            'pm_subsidy_info' => safe_text($_POST['ann_pm_subsidy_info'] ?? ''),
            'completion_milestones' => safe_text($_POST['ann_completion_milestones'] ?? ''),
            'next_steps' => safe_text($_POST['ann_next_steps'] ?? ''),
        ];

        if ($existing === null) {
            $number = documents_generate_quote_number((string) ($selectedTemplate['segment'] ?? 'RES'));
            if (!$number['ok']) {
                $redirectWith('error', (string) $number['error']);
            }

            $blockDefaults = documents_quote_annexure_from_template($templateBlocks, $templateSetId);
            foreach ($annexure as $k => $v) {
                if ($v === '' && $blockDefaults[$k] !== '') {
                    $annexure[$k] = $blockDefaults[$k];
                }
            }

            $id = 'qtn_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
            $quote = documents_quote_defaults();
            $quote['id'] = $id;
            $quote['quote_no'] = (string) $number['quote_no'];
            $quote['created_at'] = date('c');
            $user = current_user();
            $roleName = (string) ($user['role_name'] ?? 'admin');
            $quote['created_by_type'] = $roleName === 'employee' ? 'employee' : 'admin';
            $quote['created_by_id'] = (string) ($user['id'] ?? '');
            $quote['created_by_name'] = (string) ($user['full_name'] ?? ($quote['created_by_type'] === 'employee' ? 'Employee' : 'Admin'));
            $quote['segment'] = (string) ($selectedTemplate['segment'] ?? 'RES');
            $quote['status'] = 'Draft';
        } else {
            $quote = $existing;
            if (($quote['auto_created'] ?? false) && ($quote['auto_sync_enabled'] ?? true)) {
                $quote['auto_sync_enabled'] = false;
                $quote['auto_sync_disabled_at'] = date('c');
                $user = current_user();
                $quote['auto_sync_disabled_by'] = [
                    'type' => (string) ($user['role_name'] ?? 'admin'),
                    'id' => (string) ($user['id'] ?? ''),
                    'name' => (string) ($user['full_name'] ?? ''),
                ];
            }
        }

        $quote['template_set_id'] = $templateSetId;

        // Browser-disabled controls are omitted from POST. Preserve the saved quote value when a
        // key is absent, while still treating an explicitly submitted 0 (or blank) as user input.
        $requestValue = static function (string $key, $fallback = null) {
            return array_key_exists($key, $_POST) ? $_POST[$key] : $fallback;
        };
        $savedFinanceInputs = is_array($quote['finance_inputs'] ?? null) ? $quote['finance_inputs'] : [];
        $savedLoanInputs = is_array($savedFinanceInputs['loan'] ?? null) ? $savedFinanceInputs['loan'] : [];
        $savedScenarioPrices = is_array($quote['scenario_prices'] ?? null) ? $quote['scenario_prices'] : [];
        $savedFinanceScenarios = is_array($quote['finance_scenarios'] ?? null) ? $quote['finance_scenarios'] : [];
        $savedRateChartSnapshot = is_array($quote['rate_chart_snapshot'] ?? null) ? $quote['rate_chart_snapshot'] : [];
        $savedScenario = static function (string $name) use ($savedFinanceScenarios): array {
            if (is_array($savedFinanceScenarios[$name] ?? null) && $savedFinanceScenarios[$name] !== []) {
                return $savedFinanceScenarios[$name];
            }
            $derivedKey = $name . '_subsidy_to_loan';
            return is_array($savedFinanceScenarios[$derivedKey] ?? null) ? $savedFinanceScenarios[$derivedKey] : [];
        };

        $sourceType = safe_text($_POST['source_type'] ?? (string) ($quote['source']['type'] ?? ''));
        if ($sourceType === 'lead') {
            $quote['source'] = [
                'type' => 'lead',
                'lead_id' => safe_text($_POST['source_lead_id'] ?? (string) ($quote['source']['lead_id'] ?? '')),
                'lead_mobile' => documents_normalize_mobile((string) ($_POST['source_lead_mobile'] ?? ($quote['source']['lead_mobile'] ?? ''))),
            ];
        }
        $quote['party_type'] = in_array($partyType, ['customer', 'lead'], true) ? $partyType : 'lead';

        $snapshot = documents_build_quote_snapshot_from_request($_POST, $quote['party_type'], $customerRecord);
        $quote['customer_snapshot'] = $snapshot;
        $quote['customer_mobile'] = $mobile;
        $quote['customer_name'] = $customerName;
        $quote['billing_address'] = safe_text($_POST['billing_address'] ?? '') ?: $snapshot['address'];
        $quote['site_address'] = safe_text($_POST['site_address'] ?? '') ?: $snapshot['address'];
        $quote['district'] = safe_text($_POST['district'] ?? '') ?: $snapshot['district'];
        $quote['city'] = safe_text($_POST['city'] ?? '') ?: $snapshot['city'];
        $quote['state'] = safe_text($_POST['state'] ?? 'Jharkhand') ?: $snapshot['state'];
        $quote['pin'] = safe_text($_POST['pin'] ?? '') ?: $snapshot['pin_code'];
        $quote['meter_number'] = safe_text($_POST['meter_number'] ?? '') ?: $snapshot['meter_number'];
        $quote['meter_serial_number'] = safe_text($_POST['meter_serial_number'] ?? '') ?: $snapshot['meter_serial_number'];
        $quote['consumer_account_no'] = safe_text($_POST['consumer_account_no'] ?? '') ?: $snapshot['consumer_account_no'];
        $quote['application_id'] = safe_text($_POST['application_id'] ?? '') ?: $snapshot['application_id'];
        $quote['application_submitted_date'] = safe_text($_POST['application_submitted_date'] ?? '') ?: $snapshot['application_submitted_date'];
        $quote['sanction_load_kwp'] = safe_text($_POST['sanction_load_kwp'] ?? '') ?: $snapshot['sanction_load_kwp'];
        $quote['installed_pv_module_capacity_kwp'] = safe_text($_POST['installed_pv_module_capacity_kwp'] ?? '') ?: $snapshot['installed_pv_module_capacity_kwp'];
        $quote['circle_name'] = safe_text($_POST['circle_name'] ?? '') ?: $snapshot['circle_name'];
        $quote['division_name'] = safe_text($_POST['division_name'] ?? '') ?: $snapshot['division_name'];
        $quote['sub_division_name'] = safe_text($_POST['sub_division_name'] ?? '') ?: $snapshot['sub_division_name'];

        $quote['customer_snapshot'] = array_merge(documents_customer_snapshot_defaults(), $snapshot, [
            'mobile' => $quote['customer_mobile'],
            'name' => $quote['customer_name'],
            'address' => $quote['site_address'],
            'city' => $quote['city'],
            'district' => $quote['district'],
            'pin_code' => $quote['pin'],
            'state' => $quote['state'],
            'meter_number' => $quote['meter_number'],
            'meter_serial_number' => $quote['meter_serial_number'],
            'consumer_account_no' => $quote['consumer_account_no'],
            'application_id' => $quote['application_id'],
            'application_submitted_date' => $quote['application_submitted_date'],
            'sanction_load_kwp' => $quote['sanction_load_kwp'],
            'installed_pv_module_capacity_kwp' => $quote['installed_pv_module_capacity_kwp'],
            'circle_name' => $quote['circle_name'],
            'division_name' => $quote['division_name'],
            'sub_division_name' => $quote['sub_division_name'],
        ]);

        $quote['system_type'] = safe_text((string) $requestValue('system_type', $quote['system_type'] ?? 'Ongrid'));
        if ($shouldUseSplitCapacity) {
            $mainSolar = (float) $submittedMainSolarKwp;
            $complimentarySolar = $submittedComplimentaryRaw !== '' ? (float) $submittedComplimentaryRaw : 0.0;
            $totalCapacity = $mainSolar + $complimentarySolar;
            $quote['main_solar_kwp'] = (string) $mainSolar;
            $quote['complimentary_non_dcr_kwp'] = (string) $complimentarySolar;
            $quote['capacity_kwp'] = (string) $totalCapacity;
            $quote['system_capacity_kwp'] = max(0, $totalCapacity);
        } else {
            $quote['capacity_kwp'] = $capacity;
            $quote['system_capacity_kwp'] = max(0, (float) $capacity);
        }
        $quote['project_summary_line'] = safe_text($_POST['project_summary_line'] ?? '');
        $quote['quotation_date'] = safe_text($_POST['quotation_date'] ?? '');
        $quote['valid_until'] = safe_text($_POST['valid_until'] ?? '');
        $quote['pricing_mode'] = $pricingMode;
        $quote['show_tax_breakup'] = isset($_POST['show_tax_breakup']);
        $quote['place_of_supply_state'] = $placeOfSupply;
        $quote['tax_type'] = $taxType;
        $quote['tax_profile_id'] = safe_text((string) ($_POST['tax_profile_id'] ?? ''));
        $defaultHsn = safe_text((string) ($quoteDefaults['defaults']['hsn_solar'] ?? '8541')) ?: '8541';
        $activeKitMap = [];
        foreach ($inventoryKits as $kit) {
            $activeKitMap[(string) ($kit['id'] ?? '')] = $kit;
        }
        $activeComponentMap = [];
        foreach ($inventoryComponents as $component) {
            $activeComponentMap[(string) ($component['id'] ?? '')] = $component;
        }
        $activeVariantMap = [];
        foreach ($inventoryVariants as $variantRow) {
            $activeVariantMap[(string) ($variantRow['id'] ?? '')] = $variantRow;
        }

        $structuredTypes = is_array($_POST['quote_item_type'] ?? null) ? $_POST['quote_item_type'] : [];
        $structuredKitIds = is_array($_POST['quote_item_kit_id'] ?? null) ? $_POST['quote_item_kit_id'] : [];
        $structuredComponentIds = is_array($_POST['quote_item_component_id'] ?? null) ? $_POST['quote_item_component_id'] : [];
        $structuredQtys = is_array($_POST['quote_item_qty'] ?? null) ? $_POST['quote_item_qty'] : [];
        $structuredUnits = is_array($_POST['quote_item_unit'] ?? null) ? $_POST['quote_item_unit'] : [];
        $structuredVariantIds = is_array($_POST['quote_item_variant_id'] ?? null) ? $_POST['quote_item_variant_id'] : [];
        $structuredCustomDescriptions = is_array($_POST['quote_item_custom_description'] ?? null) ? $_POST['quote_item_custom_description'] : [];
        $structuredItems = [];
        $itemSummaryRows = [];
        $structuredCount = count($structuredTypes);
        if ($structuredCount === 0) {
            $redirectWith('error', 'Add at least one kit/component from Items Master.');
        }
        for ($i = 0; $i < $structuredCount; $i++) {
            $lineType = safe_text((string) ($structuredTypes[$i] ?? 'component'));
            if (!in_array($lineType, ['kit', 'component'], true)) {
                $lineType = 'component';
            }
            $qty = max(0, (float) ($structuredQtys[$i] ?? 0));
            if ($qty <= 0) {
                continue;
            }

            $kitId = safe_text((string) ($structuredKitIds[$i] ?? ''));
            $componentId = safe_text((string) ($structuredComponentIds[$i] ?? ''));
            $variantId = safe_text((string) ($structuredVariantIds[$i] ?? ''));
            $unit = safe_text((string) ($structuredUnits[$i] ?? ''));

            if ($lineType === 'kit') {
                $kit = $activeKitMap[$kitId] ?? null;
                if (!is_array($kit)) {
                    $redirectWith('error', 'Quotation contains an invalid or archived kit selection.');
                }
                $lineName = (string) ($kit['name'] ?? 'Kit');
                $lineUnit = $unit !== '' ? $unit : 'set';
                $lineDescription = safe_text((string) ($kit['description'] ?? ''));
                $lineHsn = safe_text((string) ($kit['hsn'] ?? '')) ?: $defaultHsn;

                $structuredItems[] = [
                    'type' => 'kit',
                    'kit_id' => $kitId,
                    'component_id' => '',
                    'qty' => $qty,
                    'unit' => $lineUnit,
                    'variant_id' => '',
                    'variant_snapshot' => [],
                    'name_snapshot' => $lineName,
                    'description_snapshot' => $lineDescription,
                    'master_description_snapshot' => $lineDescription,
                    'custom_description' => safe_multiline_text((string) ($structuredCustomDescriptions[$i] ?? '')),
                    'hsn_snapshot' => $lineHsn,
                    'meta' => [],
                ];
                $itemSummaryRows[] = [
                    'name' => $lineName,
                    'description' => $lineDescription,
                    'hsn' => $lineHsn,
                    'qty' => $qty,
                    'unit' => $lineUnit,
                    'gst_slab' => '5',
                    'basic_amount' => 0,
                ];
                continue;
            }

            $component = $activeComponentMap[$componentId] ?? null;
            if (!is_array($component)) {
                $redirectWith('error', 'Quotation contains an invalid or archived component selection.');
            }
            $variant = null;
            if ($variantId !== '') {
                $variant = $activeVariantMap[$variantId] ?? null;
                if (!is_array($variant) || (string) ($variant['component_id'] ?? '') !== $componentId) {
                    $redirectWith('error', 'Quotation contains an invalid or archived variant selection.');
                }
            }

            $componentName = (string) ($component['name'] ?? 'Component');
            $lineName = $componentName;
            if (is_array($variant) && safe_text((string) ($variant['display_name'] ?? '')) !== '') {
                $lineName .= ' (' . safe_text((string) ($variant['display_name'] ?? '')) . ')';
            }
            $lineUnit = $unit !== '' ? $unit : ((string) ($component['default_unit'] ?? 'pcs'));
            $lineHsn = safe_text((string) ($component['hsn'] ?? '')) ?: $defaultHsn;
            $lineDescription = safe_text((string) ($component['description'] ?? ''));
            if ($lineDescription === '') {
                $lineDescription = safe_text((string) ($component['notes'] ?? ''));
            }

            $structuredItems[] = [
                'type' => 'component',
                'kit_id' => '',
                'component_id' => $componentId,
                'qty' => $qty,
                'unit' => $lineUnit,
                'variant_id' => $variantId,
                'variant_snapshot' => is_array($variant) ? [
                    'id' => (string) ($variant['id'] ?? ''),
                    'display_name' => (string) ($variant['display_name'] ?? ''),
                    'brand' => (string) ($variant['brand'] ?? ''),
                    'technology' => (string) ($variant['technology'] ?? ''),
                    'wattage_wp' => (float) ($variant['wattage_wp'] ?? 0),
                    'model_no' => (string) ($variant['model_no'] ?? ''),
                ] : [],
                'name_snapshot' => $lineName,
                'description_snapshot' => $lineDescription,
                'master_description_snapshot' => $lineDescription,
                'custom_description' => safe_multiline_text((string) ($structuredCustomDescriptions[$i] ?? '')),
                'hsn_snapshot' => $lineHsn,
                'meta' => [],
            ];
            $itemSummaryRows[] = [
                'name' => $lineName,
                'description' => $lineDescription,
                'hsn' => $lineHsn,
                'qty' => $qty,
                'unit' => $lineUnit,
                'gst_slab' => '5',
                'basic_amount' => 0,
            ];
        }

        if ($structuredItems === []) {
            $redirectWith('error', 'Add at least one kit/component from Items Master.');
        }

        foreach ($itemSummaryRows as &$summaryRow) {
            unset($summaryRow['__sort']);
        }
        unset($summaryRow);

        $quote['quote_items'] = documents_normalize_quote_structured_items($structuredItems);
        $quote['items'] = documents_normalize_quote_items($itemSummaryRows, $quote['system_type'], (float) $quote['capacity_kwp'], $defaultHsn);
        if ($quote['tax_profile_id'] === '') {
            foreach ($quote['quote_items'] as $line) {
                if (!is_array($line) || (string) ($line['type'] ?? '') !== 'kit') {
                    continue;
                }
                $kit = documents_inventory_get_kit((string) ($line['kit_id'] ?? ''));
                $kitTaxProfileId = safe_text((string) ($kit['tax_profile_id'] ?? ''));
                if ($kitTaxProfileId !== '') {
                    $quote['tax_profile_id'] = $kitTaxProfileId;
                    break;
                }
            }
        }
        if ($quote['tax_profile_id'] === '') {
            $quote['tax_profile_id'] = safe_text((string) ($quoteDefaults['defaults']['quotation_tax_profile_id'] ?? ''));
        }
        $legacySystemTotalInclGstRs = max(0, (float) $requestValue('system_total_incl_gst_rs', $quote['input_total_gst_inclusive'] ?? 0));
        $systemTotalInclGstRs = $legacySystemTotalInclGstRs;
        $transportationRs = (float) $requestValue('transportation_rs', $savedFinanceInputs['transportation_rs'] ?? ($quote['calc']['transportation_rs'] ?? 0));
        $subsidyExpectedRs = (float) $requestValue('subsidy_expected_rs', $savedFinanceInputs['subsidy_expected_rs'] ?? ($quote['calc']['subsidy_expected_rs'] ?? 0));
        $discountRs = (float) $requestValue('discount_rs', $savedFinanceInputs['discount_rs'] ?? ($quote['discount_rs'] ?? 0));
        $discountNote = safe_text((string) $requestValue('discount_note', $savedFinanceInputs['discount_note'] ?? ($quote['discount_note'] ?? '')));
        if ($discountRs < 0) {
            $redirectWith('error', 'Discount cannot be negative.');
        }
        $grossPayableBeforeDiscount = max(0, $systemTotalInclGstRs) + max(0, $transportationRs);
        if ($discountRs > $grossPayableBeforeDiscount) {
            $redirectWith('error', 'Discount cannot exceed Gross Payable (System + Transportation).');
        }
        $quote['discount_rs'] = $discountRs;
        $quote['discount_note'] = $discountNote;

        $primaryScenario = safe_text((string) $requestValue('primary_finance_scenario', $quote['primary_finance_scenario'] ?? 'loan_upto_2_lacs_subsidy_to_loan'));
        if (!in_array($primaryScenario, ['self_funded', 'loan_upto_2_lacs_subsidy_to_loan', 'loan_upto_2_lacs_subsidy_not_to_loan', 'loan_above_2_lacs_subsidy_to_loan', 'loan_above_2_lacs_subsidy_not_to_loan', 'loan_upto_2_lacs', 'loan_above_2_lacs'], true)) {
            $primaryScenario = 'loan_upto_2_lacs_subsidy_to_loan';
        }
        $quote['primary_finance_scenario'] = $primaryScenario;
        $priceSelfFunded = max(0, (float) $requestValue('scenario_price_self_funded', $savedScenarioPrices['self_funded']['price'] ?? $quote['input_total_gst_inclusive'] ?? 0));
        $priceLoanUp2 = max(0, (float) $requestValue('scenario_price_loan_upto_2_lacs', $savedScenarioPrices['loan_upto_2_lacs']['price'] ?? $quote['input_total_gst_inclusive'] ?? 0));
        $priceLoanAbove2 = max(0, (float) $requestValue('scenario_price_loan_above_2_lacs', $savedScenarioPrices['loan_above_2_lacs']['price'] ?? $quote['input_total_gst_inclusive'] ?? 0));
        $loanAboveApplicable = ($priceLoanAbove2 * 0.9) > 200000;
        $quote['scenario_prices'] = [
            'self_funded' => ['price' => $priceSelfFunded],
            'loan_upto_2_lacs_subsidy_to_loan' => ['price' => $priceLoanUp2],
            'loan_upto_2_lacs_subsidy_not_to_loan' => ['price' => $priceLoanUp2],
            'loan_above_2_lacs_subsidy_to_loan' => ['price' => $priceLoanAbove2, 'applicable' => $loanAboveApplicable],
            'loan_above_2_lacs_subsidy_not_to_loan' => ['price' => $priceLoanAbove2, 'applicable' => $loanAboveApplicable],
            'loan_upto_2_lacs' => ['price' => $priceLoanUp2],
            'loan_above_2_lacs' => ['price' => $priceLoanAbove2, 'applicable' => $loanAboveApplicable],
        ];
        $selectedModelNumber = safe_text((string) $requestValue('selected_model_number', $savedRateChartSnapshot['model_number'] ?? ''));
        $selectedRateChartRow = [];
        if ($selectedModelNumber !== '') {
            $rateChartType = strtolower($quote['system_type']) === 'hybrid' ? 'hybrid' : 'on_grid';
            foreach ((array) ($quoteDefaults['rate_chart'][$rateChartType] ?? []) as $rateChartRow) {
                if (is_array($rateChartRow) && safe_text((string) ($rateChartRow['model_number'] ?? '')) === $selectedModelNumber) {
                    $selectedRateChartRow = $rateChartRow;
                    break;
                }
            }
        }
        if ($selectedRateChartRow === [] && $selectedModelNumber !== '') {
            $selectedRateChartRow = $savedRateChartSnapshot;
        }
        $selectedScenarioModelNumbers = is_array($selectedRateChartRow['scenario_model_numbers'] ?? null) ? $selectedRateChartRow['scenario_model_numbers'] : [];
        $quote['rate_chart_snapshot'] = array_merge($savedRateChartSnapshot, [
            'system_type' => safe_text((string) $requestValue('system_type', $savedRateChartSnapshot['system_type'] ?? $quote['system_type'] ?? '')),
            'model_number' => safe_text((string) ($selectedRateChartRow['model_number'] ?? $selectedModelNumber)),
            'scenario_model_numbers' => [
                'self_funded' => safe_text((string) ($selectedScenarioModelNumbers['self_funded'] ?? '')),
                'loan_upto_2_lacs' => safe_text((string) ($selectedScenarioModelNumbers['loan_upto_2_lacs'] ?? '')),
                'loan_above_2_lacs' => safe_text((string) ($selectedScenarioModelNumbers['loan_above_2_lacs'] ?? '')),
            ],
            'variant' => safe_text((string) ($selectedRateChartRow['variant'] ?? '')),
            'battery_code' => safe_text((string) ($selectedRateChartRow['battery_code'] ?? '')),
            'inverter_code' => safe_text((string) ($selectedRateChartRow['inverter_code'] ?? '')),
            'solar_size_kwp' => (float) ($quote['capacity_kwp'] ?? 0),
            'dcr_size_kwp' => (float) ($quote['main_solar_kwp'] ?? 0),
            'non_dcr_size_kwp' => (float) ($quote['complimentary_non_dcr_kwp'] ?? 0),
            'total_system_size_kwp' => (float) ($quote['capacity_kwp'] ?? 0),
            'hybrid_inverter_kva' => (float) $requestValue('hybrid_inverter_kva', $savedRateChartSnapshot['hybrid_inverter_kva'] ?? 0),
            'hybrid_phase' => solar_finance_normalize_phase_label((string) $requestValue('hybrid_phase', $savedRateChartSnapshot['hybrid_phase'] ?? '')),
            'hybrid_battery_count' => (int) $requestValue('hybrid_battery_count', $savedRateChartSnapshot['hybrid_battery_count'] ?? 0),
            'self_funded_price' => $priceSelfFunded,
            'loan_upto_2_lacs_price' => $priceLoanUp2,
            'loan_above_2_lacs_price' => $priceLoanAbove2,
            'captured_at' => date('c'),
        ]);
        $quote = solar_finance_sync_hybrid_summary_into_quote_items($quote);
        $priceForPrimary = 0.0;
        if ($primaryScenario === 'self_funded') {
            $priceForPrimary = $priceSelfFunded;
        } elseif (in_array($primaryScenario, ['loan_upto_2_lacs', 'loan_upto_2_lacs_subsidy_to_loan', 'loan_upto_2_lacs_subsidy_not_to_loan'], true)) {
            $priceForPrimary = $priceLoanUp2;
        } elseif (in_array($primaryScenario, ['loan_above_2_lacs', 'loan_above_2_lacs_subsidy_to_loan', 'loan_above_2_lacs_subsidy_not_to_loan'], true)) {
            $priceForPrimary = $priceLoanAbove2;
        }
        if ($priceForPrimary <= 0) {
            $priceForPrimary = $legacySystemTotalInclGstRs;
        }
        $quote['calc'] = documents_calc_quote_pricing_with_tax_profile($quote, $transportationRs, $subsidyExpectedRs, $priceForPrimary, $quoteDefaults);
        $quote['tax_breakdown'] = is_array($quote['calc']['tax_breakdown'] ?? null) ? (array) $quote['calc']['tax_breakdown'] : ['basic_total' => 0, 'gst_total' => 0, 'gross_incl_gst' => 0, 'slabs' => []];
        $quote['gst_mode_snapshot'] = (string) ($quote['tax_breakdown']['mode'] ?? 'single');
        $quote['gst_slabs_snapshot'] = is_array($quote['tax_breakdown']['slabs'] ?? null) ? $quote['tax_breakdown']['slabs'] : [];
        $quote['input_total_gst_inclusive'] = $priceForPrimary;
        $quote['special_requests_text'] = trim((string) ($_POST['special_requests_text'] ?? ''));
        $quote['special_requests_inclusive'] = $quote['special_requests_text'];
        $quote['special_requests_override_note'] = true;
        $quote['annexures_overrides'] = $annexure;
        $quote['cover_notes_html_snapshot'] = trim((string) ($annexure['cover_notes'] ?? ''));
        $quote['template_attachments'] = (($templateBlocks[$templateSetId]['attachments'] ?? null) && is_array($templateBlocks[$templateSetId]['attachments'])) ? $templateBlocks[$templateSetId]['attachments'] : documents_template_attachment_defaults();
        $quote['finance_inputs']['monthly_bill_rs'] = safe_text((string) $requestValue('monthly_bill_rs', $savedFinanceInputs['monthly_bill_rs'] ?? ''));
        $quote['finance_inputs']['unit_rate_rs_per_kwh'] = safe_text((string) $requestValue('unit_rate_rs_per_kwh', $savedFinanceInputs['unit_rate_rs_per_kwh'] ?? ''));
        $quote['finance_inputs']['annual_generation_per_kw'] = safe_text((string) $requestValue('annual_generation_per_kw', $savedFinanceInputs['annual_generation_per_kw'] ?? ''));
        $postData = $_POST;
        $buildLoanScenario = static function (string $name, float $price, bool $applicable) use ($postData, $subsidyExpectedRs, $savedScenario, $savedLoanInputs): array {
            $saved = $savedScenario($name);
            $value = static function (string $key, $fallback = null) use ($postData) {
                return array_key_exists($key, $postData) ? $postData[$key] : $fallback;
            };
            $mode = safe_text((string) $value($name . '_finance_mode', $saved['finance_mode'] ?? 'ratio')) === 'manual' ? 'manual' : 'ratio';
            $isUpTo2 = $name === 'loan_upto_2_lacs';
            $defaultMarginRatio = $isUpTo2 ? 10.0 : 20.0;
            $defaultLoanRatio = $isUpTo2 ? 90.0 : 80.0;
            $defaultInterestPct = $isUpTo2 ? 5.75 : 8.15;
            $marginRatio = max(0, min(100, (float) $value($name . '_margin_ratio_pct', $saved['margin_ratio_pct'] ?? $defaultMarginRatio)));
            $loanRatio = max(0, min(100, (float) $value($name . '_loan_ratio_pct', $saved['loan_ratio_pct'] ?? $defaultLoanRatio)));
            if (abs(($marginRatio + $loanRatio) - 100.0) > 0.001) {
                $loanRatio = max(0, 100 - $marginRatio);
            }
            $marginMoney = $mode === 'manual' ? max(0, (float) $value($name . '_margin_money_rs', $saved['margin_money_rs'] ?? 0)) : ($price * $marginRatio / 100);
            $loanAmount = $mode === 'manual' ? max(0, (float) $value($name . '_loan_amount_rs', $saved['loan_amount_rs'] ?? 0)) : ($price * $loanRatio / 100);
            if ($isUpTo2) {
                $loanAmount = min($loanAmount, 200000, $price);
                $marginMoney = max(0, $price - $loanAmount);
            }
            return [
                'price' => $price,
                'subsidy' => $subsidyExpectedRs,
                'gross_payable' => $price,
                'net_investment_after_subsidy' => max(0, $price - $subsidyExpectedRs),
                'monthly_outflow' => 0,
                'payback' => 0,
                'applicable' => $applicable,
                'margin_money_rs' => $marginMoney,
                'loan_amount_rs' => $loanAmount,
                'effective_loan_principal_rs' => max(0, $loanAmount - $subsidyExpectedRs),
                'interest_pct' => max(0, (float) $value($name . '_interest_pct', $saved['interest_pct'] ?? $defaultInterestPct)),
                'tenure_years' => max(0, (float) $value($name . '_tenure_years', $saved['tenure_years'] ?? ($savedLoanInputs['tenure_years'] ?? 0))),
                'emi_rs' => 0,
                'residual_bill_rs' => 0,
                'finance_mode' => $mode,
                'margin_ratio_pct' => $marginRatio,
                'loan_ratio_pct' => $loanRatio,
            ];
        };
        $loanUp2Base = $buildLoanScenario('loan_upto_2_lacs', $priceLoanUp2, isset($_POST['loan_upto_2_lacs_applicable']));
        $loanAbove2Base = $buildLoanScenario('loan_above_2_lacs', $priceLoanAbove2, $loanAboveApplicable);
        $deriveLoanScenario = static function (array $base, bool $subsidyToLoan, float $subsidyExpectedRs): array {
            $marginMoney = max(0, (float) ($base['margin_money_rs'] ?? 0));
            $loanAmount = max(0, (float) ($base['loan_amount_rs'] ?? 0));
            $remainingSubsidyAfterMargin = max(0, $subsidyExpectedRs - $marginMoney);
            $base['effective_loan_principal_rs'] = $subsidyToLoan
                ? max(0, $loanAmount - $subsidyExpectedRs)
                : max(0, $loanAmount - $remainingSubsidyAfterMargin);
            $base['initial_investment_after_subsidy_credit_rs'] = max(0, $marginMoney - $subsidyExpectedRs);
            $base['remaining_subsidy_after_margin_adjustment_rs'] = $subsidyToLoan ? 0 : $remainingSubsidyAfterMargin;
            $base['net_own_investment_after_subsidy'] = $base['initial_investment_after_subsidy_credit_rs'];
            return $base;
        };
        $quote['finance_scenarios'] = [
            'self_funded' => [
                'price' => $priceSelfFunded,
                'subsidy' => $subsidyExpectedRs,
                'gross_payable' => $priceSelfFunded,
                'net_investment_after_subsidy' => max(0, $priceSelfFunded - $subsidyExpectedRs),
                'monthly_outflow' => 0,
                'payback' => 0,
                'applicable' => true,
                'margin_money_rs' => 0,
                'loan_amount_rs' => 0,
                'effective_loan_principal_rs' => 0,
                'interest_pct' => 0,
                'tenure_years' => 0,
                'emi_rs' => 0,
                'residual_bill_rs' => 0,
            ],
            'loan_upto_2_lacs_subsidy_to_loan' => $deriveLoanScenario($loanUp2Base, true, $subsidyExpectedRs),
            'loan_upto_2_lacs_subsidy_not_to_loan' => $deriveLoanScenario($loanUp2Base, false, $subsidyExpectedRs),
            'loan_above_2_lacs_subsidy_to_loan' => $deriveLoanScenario($loanAbove2Base, true, $subsidyExpectedRs),
            'loan_above_2_lacs_subsidy_not_to_loan' => $deriveLoanScenario($loanAbove2Base, false, $subsidyExpectedRs),
            'loan_upto_2_lacs' => $loanUp2Base,
            'loan_above_2_lacs' => $loanAbove2Base,
        ];
        $primaryScenarioMap = [
            'loan_upto_2_lacs' => 'loan_upto_2_lacs_subsidy_to_loan',
            'loan_above_2_lacs' => 'loan_above_2_lacs_subsidy_to_loan',
        ];
        $resolvedPrimaryScenario = $primaryScenarioMap[$primaryScenario] ?? $primaryScenario;
        $primaryScenarioRow = is_array($quote['finance_scenarios'][$resolvedPrimaryScenario] ?? null)
            ? $quote['finance_scenarios'][$resolvedPrimaryScenario]
            : [];
        $quote['finance_inputs']['loan']['enabled'] = str_contains($resolvedPrimaryScenario, 'loan_');
        $quote['finance_inputs']['loan']['interest_pct'] = (string) (float) ($primaryScenarioRow['interest_pct'] ?? 0);
        $quote['finance_inputs']['loan']['tenure_years'] = (string) (float) ($primaryScenarioRow['tenure_years'] ?? 0);
        $quote['finance_inputs']['loan']['margin_pct'] = (string) (float) ($primaryScenarioRow['margin_ratio_pct'] ?? 0);
        $quote['finance_inputs']['loan']['loan_amount'] = (string) (float) ($primaryScenarioRow['loan_amount_rs'] ?? 0);
        $quote = documents_quote_apply_customer_savings_inputs($quote, $_POST, $quoteDefaults);
        $monthlyBillTouched = safe_text((string) ($_POST['monthly_bill_touched'] ?? '0')) === '1';
        $savedMonthlyBill = (float) ($quote['customer_savings_inputs']['monthly_bill_before_rs'] ?? 0);
        $savedAnnualGeneration = (float) ($quote['customer_savings_inputs']['annual_generation_kwh_per_kw'] ?? 0);
        $savedUnitRate = (float) ($quote['customer_savings_inputs']['unit_rate_rs_per_kwh'] ?? 0);
        $savedCapacity = max(0, (float) ($quote['capacity_kwp'] ?? 0));
        if (!$monthlyBillTouched && $savedMonthlyBill <= 0 && $savedCapacity > 0 && $savedAnnualGeneration > 0 && $savedUnitRate > 0) {
            $computedMonthlyBill = ($savedCapacity * $savedAnnualGeneration * $savedUnitRate) / 12;
            $computedMonthlyBill = (float) round($computedMonthlyBill);
            $quote['finance_inputs']['monthly_bill_rs'] = (string) $computedMonthlyBill;
            $quote['customer_savings_inputs']['monthly_bill_before_rs'] = $computedMonthlyBill;
        }
        $quote['finance_inputs']['subsidy_expected_rs'] = (string) $subsidyExpectedRs;
        $quote['finance_inputs']['transportation_rs'] = (string) $transportationRs;
        $quote['finance_inputs']['discount_rs'] = (string) $discountRs;
        $quote['finance_inputs']['discount_note'] = $discountNote;
        $quoteNormalizedFinance = solar_finance_normalize_for_quote_render($quote, is_array($quote['calc'] ?? null) ? $quote['calc'] : [], [
            'monthly_bill_before_rs' => (float) ($quote['customer_savings_inputs']['monthly_bill_before_rs'] ?? ($quote['finance_inputs']['monthly_bill_rs'] ?? 0)),
            'unit_rate_rs_per_kwh' => (float) ($quote['customer_savings_inputs']['unit_rate_rs_per_kwh'] ?? ($quote['finance_inputs']['unit_rate_rs_per_kwh'] ?? 0)),
            'annual_generation_kwh_per_kw' => (float) ($quote['customer_savings_inputs']['annual_generation_kwh_per_kw'] ?? ($quote['finance_inputs']['annual_generation_per_kw'] ?? 0)),
            'loan_interest_rate_percent' => (float) ($quote['customer_savings_inputs']['loan_interest_rate_percent'] ?? ($quote['finance_inputs']['loan']['interest_pct'] ?? 0)),
            'loan_tenure_months' => (float) ($quote['customer_savings_inputs']['loan_tenure_months'] ?? 0),
        ]);
        if (is_array($quoteNormalizedFinance['finance_scenarios'] ?? null)) {
            $quote['finance_scenarios'] = $quoteNormalizedFinance['finance_scenarios'];
        }
        $quote['style_overrides']['typography']['base_font_px'] = safe_text($_POST['style_base_font_px'] ?? '');
        $quote['style_overrides']['typography']['heading_scale'] = safe_text($_POST['style_heading_scale'] ?? '');
        $quote['style_overrides']['typography']['density'] = safe_text($_POST['style_density'] ?? '');
        $quote['style_overrides']['watermark']['enabled'] = isset($_POST['watermark_enabled']) ? '1' : '';
        $quote['style_overrides']['watermark']['opacity'] = safe_text($_POST['watermark_opacity'] ?? '');
        $quote['style_overrides']['watermark']['image_path'] = safe_text($_POST['watermark_image_path'] ?? '');
        $quote['updated_at'] = date('c');

        $saved = documents_save_quote($quote);
        if (!$saved['ok']) {
            $redirectWith('error', 'Failed to save quotation.');
        }

        header('Location: quotation-view.php?id=' . urlencode((string) $quote['id']) . '&ok=1');
        exit;
    }

    if ($action === 'create_revision') {
        $originalId = safe_text($_POST['quote_id'] ?? '');
        $revisionReason = trim((string) ($_POST['revision_reason'] ?? ''));
        $archiveExistingDocs = isset($_POST['archive_existing_documents']);
        $original = $originalId !== '' ? documents_get_quote($originalId) : null;
        if ($original === null) {
            $redirectWith('error', 'Original quotation not found for revision.');
        }
        if (!documents_quote_is_locked($original)) {
            $redirectWith('error', 'Only accepted quotations can be revised.');
        }

        $newQuote = documents_quote_prepare($original);
        $newId = 'qtn_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $newQuote['id'] = $newId;
        $newQuote['quote_series_id'] = (string) ($original['quote_series_id'] ?? $original['id'] ?? $newId);
        $newQuote['version_no'] = (int) ($original['version_no'] ?? 1) + 1;
        $newQuote['is_current_version'] = false;
        $newQuote['revised_from_quote_id'] = (string) ($original['id'] ?? '');
        $newQuote['revision_reason'] = $revisionReason !== '' ? $revisionReason : null;
        $newQuote['revision_child_ids'] = [];
        $newQuote['status'] = 'draft';
        $newQuote['locked_flag'] = false;
        $newQuote['locked_at'] = null;
        $newQuote['accepted_at'] = '';
        $newQuote['accepted_by'] = ['type' => '', 'id' => '', 'name' => ''];
        $newQuote['acceptance'] = [
            'accepted_by_admin_id' => '',
            'accepted_by_admin_name' => '',
            'accepted_at' => '',
            'accepted_note' => '',
        ];
        $newQuote['workflow'] = documents_quote_workflow_defaults();
        $newQuote['links'] = array_merge(['customer_mobile' => '', 'agreement_id' => '', 'proforma_id' => '', 'invoice_id' => ''], is_array($newQuote['links'] ?? null) ? $newQuote['links'] : []);
        $newQuote['links']['agreement_id'] = '';
        $newQuote['links']['proforma_id'] = '';
        $newQuote['links']['invoice_id'] = '';
        $newQuote['created_at'] = date('c');
        $newQuote['updated_at'] = date('c');
        $newQuote['created_by_type'] = 'admin';
        $user = current_user();
        $newQuote['created_by_id'] = (string) ($user['id'] ?? '');
        $newQuote['created_by_name'] = (string) ($user['full_name'] ?? 'Admin');

        $original = documents_quote_prepare($original);
        $original['status'] = 'accepted';
        $original['locked_flag'] = true;
        $original['locked_at'] = safe_text((string) ($original['locked_at'] ?? '')) ?: (safe_text((string) ($original['accepted_at'] ?? '')) ?: date('c'));
        $original['is_current_version'] = false;
        $children = is_array($original['revision_child_ids'] ?? null) ? $original['revision_child_ids'] : [];
        if (!in_array($newId, $children, true)) {
            $children[] = $newId;
        }
        $original['revision_child_ids'] = array_values($children);
        $original['updated_at'] = date('c');

        $savedOriginal = documents_save_quote($original);
        $savedNew = documents_save_quote($newQuote);
        if (!($savedOriginal['ok'] ?? false) || !($savedNew['ok'] ?? false)) {
            $redirectWith('error', 'Unable to create quotation revision.');
        }

        if ($archiveExistingDocs) {
            documents_archive_quote_sales_documents($original, [
                'type' => 'admin',
                'id' => (string) ($user['id'] ?? ''),
                'name' => (string) ($user['full_name'] ?? 'Admin'),
            ]);
        }

        header('Location: admin-quotations.php?edit=' . urlencode($newId) . '&status=success&message=' . urlencode('Revision created. You are editing the new draft version.'));
        exit;
    }


    if ($action === 'share_whatsapp_payload') {
        header('Content-Type: application/json; charset=UTF-8');
        $quoteId = safe_text($_POST['quote_id'] ?? '');
        $quote = $quoteId !== '' ? documents_get_quote($quoteId) : null;
        if ($quote === null) {
            echo json_encode(['ok' => false, 'message' => 'Quotation not found.']);
            exit;
        }

        $mobile = $quotationExtractMobile($quote);
        if ($mobile === '') {
            echo json_encode(['ok' => false, 'message' => 'Customer mobile number is missing or invalid for WhatsApp sharing.']);
            exit;
        }

        $template = $quotationResolveWhatsappTemplate($quoteDefaults);
        $needsQuotationLink = strpos($template, '{{quotation_link}}') !== false;
        if ($needsQuotationLink) {
            if (safe_text((string) ($quote['public_share_token'] ?? '')) === '') {
                $quote['public_share_token'] = documents_generate_quote_public_share_token();
                $quote['public_share_created_at'] = date('c');
            }
            $quote['public_share_enabled'] = true;
            $quote['public_share_revoked_at'] = null;
            $quote['updated_at'] = date('c');
            $saved = documents_save_quote($quote);
            if (!($saved['ok'] ?? false)) {
                echo json_encode(['ok' => false, 'message' => 'Unable to prepare quotation share link.']);
                exit;
            }
        }

        $shareUrl = $quotationPublicShareUrl($quote);
        $message = $quotationResolveWhatsappMessage($quote, $template, $shareUrl);
        if ($message === '') {
            $fallbackMessage = 'Please review your quotation.';
            if ($shareUrl !== '') {
                $fallbackMessage .= ' ' . $shareUrl;
            }
            $message = $fallbackMessage;
        }

        echo json_encode([
            'ok' => true,
            'mobile' => $mobile,
            'share_url' => $shareUrl,
            'message' => $message,
        ]);
        exit;
    }

    if ($action === 'approve_quote' || $action === 'accept_quote') {
        $quoteId = safe_text($_POST['quote_id'] ?? '');
        $quote = $quoteId !== '' ? documents_get_quote($quoteId) : null;
        if ($quote === null) {
            $respondAction(false, 'Quotation not found.');
        }

        $user = current_user();
        if ((string) ($user['role_name'] ?? '') !== 'admin') {
            $respondAction(false, $action === 'approve_quote' ? 'Only administrators can approve quotations.' : 'Only administrators can accept quotations.');
        }

        $targetStatus = $action === 'approve_quote' ? 'approved' : 'accepted';
        $transition = documents_quote_apply_admin_status_transition($quote, $targetStatus, [
            'id' => (string) ($user['id'] ?? ''),
            'name' => (string) ($user['full_name'] ?? 'Admin'),
        ]);
        $quote = is_array($transition['quote'] ?? null) ? $transition['quote'] : $quote;
        if (!($transition['ok'] ?? false)) {
            $respondAction(false, (string) ($transition['error'] ?? 'Unable to update quotation.'), $quoteActionState($quote));
        }

        $respondAction(
            true,
            $targetStatus === 'approved' ? 'Quotation approved successfully.' : 'Quotation accepted and locked successfully.',
            $quoteActionState($quote)
        );
    }

    if ($action === 'archive_quote' || $action === 'unarchive_quote') {
        $quoteId = safe_text($_POST['quote_id'] ?? '');
        $quote = $quoteId !== '' ? documents_get_quote($quoteId) : null;
        if ($quote === null) {
            $respondAction(false, 'Quotation not found.');
        }

        $isUnarchive = $action === 'unarchive_quote';
        if (!$isUnarchive && documents_is_archived($quote)) {
            $respondAction(true, 'Quotation is already archived.', $quoteActionState($quote));
        }
        if ($isUnarchive && !documents_is_archived($quote)) {
            $respondAction(true, 'Quotation is already unarchived.', $quoteActionState($quote));
        }

        $user = current_user();
        $transition = documents_quote_apply_admin_status_transition($quote, $isUnarchive ? 'unarchived' : 'archived', [
            'type' => (string) ($user['role_name'] ?? 'admin'),
            'id' => (string) ($user['id'] ?? ''),
            'name' => (string) ($user['full_name'] ?? 'Admin'),
        ]);
        $quote = is_array($transition['quote'] ?? null) ? $transition['quote'] : $quote;
        if (!($transition['ok'] ?? false)) {
            $respondAction(false, (string) ($transition['error'] ?? 'Unable to update quotation archive status.'), $quoteActionState($quote));
        }
        $respondAction(true, $isUnarchive ? 'Quotation unarchived successfully.' : 'Quotation archived successfully.', $quoteActionState($quote));
    }

    if ($action === 'clone_quote') {
        $quoteId = safe_text($_POST['quote_id'] ?? '');
        $original = $quoteId !== '' ? documents_get_quote($quoteId) : null;
        if ($original === null) {
            $respondAction(false, 'Quotation not found for cloning.');
        }

        $original = documents_quote_prepare($original);
        $number = documents_generate_quote_number((string) ($original['segment'] ?? 'RES'));
        if (!($number['ok'] ?? false)) {
            $respondAction(false, (string) ($number['error'] ?? 'Unable to generate quotation number for clone.'));
        }

        $user = current_user();
        $newId = 'qtn_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $cloned = $original;
        $cloned['id'] = $newId;
        $cloned['quote_no'] = (string) ($number['quote_no'] ?? '');
        $cloned = documents_quote_reset_clone_state($cloned, $newId);
        $cloned['quotation_date'] = date('Y-m-d');
        $cloned['created_at'] = date('c');
        $cloned['updated_at'] = date('c');
        $cloned['created_by_type'] = (string) ($user['role_name'] ?? 'admin');
        $cloned['created_by_id'] = (string) ($user['id'] ?? '');
        $cloned['created_by_name'] = (string) ($user['full_name'] ?? 'Admin');
        $cloned['public_share_token'] = documents_generate_quote_public_share_token();
        $cloned['public_share_enabled'] = false;
        $cloned['public_share_created_at'] = date('c');
        $cloned['public_share_revoked_at'] = null;
        $cloned['public_share_expires_at'] = null;
        $cloned['accepted_at'] = '';
        $cloned['accepted_by'] = ['type' => '', 'id' => '', 'name' => ''];
        $cloned['acceptance'] = [
            'accepted_by_admin_id' => '',
            'accepted_by_admin_name' => '',
            'accepted_at' => '',
            'accepted_note' => '',
        ];
        $cloned['approval'] = [
            'approved_by_id' => '',
            'approved_by_name' => '',
            'approved_at' => '',
        ];
        $cloned['locked_flag'] = false;
        $cloned['locked_at'] = null;
        $cloned['archived_flag'] = false;
        $cloned['archived_at'] = '';
        $cloned['archived_by'] = ['type' => '', 'id' => '', 'name' => ''];
        $cloned['workflow'] = documents_quote_workflow_defaults();
        $cloned['links'] = ['customer_mobile' => '', 'agreement_id' => '', 'proforma_id' => '', 'invoice_id' => ''];
        $cloned['quote_series_id'] = $newId;
        $cloned['version_no'] = 1;
        $cloned['is_current_version'] = true;
        $cloned['revised_from_quote_id'] = null;
        $cloned['revision_reason'] = null;
        $cloned['revision_child_ids'] = [];

        $saved = documents_save_quote($cloned);
        if (!($saved['ok'] ?? false)) {
            $respondAction(false, 'Unable to clone quotation.');
        }

        $cloneUrl = 'admin-quotations.php?tab=editor&edit=' . urlencode($newId) . '&status=success&message=' . urlencode('Quotation cloned successfully. You can now edit the draft copy.');
        if ($isAjaxRequest) {
            $respondAction(true, 'Quotation cloned successfully.', ['redirect_url' => $cloneUrl, 'quote_id' => $newId]);
        }
        header('Location: ' . $cloneUrl);
        exit;
    }

    if ($action === 'toggle_public_share') {
        $quoteId = safe_text($_POST['quote_id'] ?? '');
        $shareMode = safe_text($_POST['share_mode'] ?? '');
        $quote = $quoteId !== '' ? documents_get_quote($quoteId) : null;
        if ($quote === null) {
            $respondAction(false, 'Quotation not found.');
        }

        if ($shareMode === 'enable') {
            if (safe_text((string) ($quote['public_share_token'] ?? '')) === '') {
                $quote['public_share_token'] = documents_generate_quote_public_share_token();
                $quote['public_share_created_at'] = date('c');
            }
            $quote['public_share_enabled'] = true;
            $quote['public_share_revoked_at'] = null;
            $quote['updated_at'] = date('c');
            $saved = documents_save_quote($quote);
            if (!($saved['ok'] ?? false)) {
                $respondAction(false, 'Unable to enable public link.');
            }
            $respondAction(true, 'Public share link enabled.', array_merge($quoteActionState($quote), ['public_share_url' => $quotationPublicShareUrl($quote)]));
        }

        if ($shareMode === 'disable') {
            $quote['public_share_enabled'] = false;
            $quote['public_share_revoked_at'] = date('c');
            $quote['updated_at'] = date('c');
            $saved = documents_save_quote($quote);
            if (!($saved['ok'] ?? false)) {
                $respondAction(false, 'Unable to disable public link.');
            }
            $respondAction(true, 'Public share link disabled.', $quoteActionState($quote));
        }

        $respondAction(false, 'Invalid share action.');
    }

    if ($action === 'bulk_quote_action') {
        $bulkAction = safe_text($_POST['bulk_action'] ?? '');
        $selected = is_array($_POST['selected_ids'] ?? null) ? $_POST['selected_ids'] : [];
        $selected = array_values(array_filter(array_map(static fn($v): string => safe_text((string)$v), $selected), static fn($v): bool => $v !== ''));
        if ($selected === []) {
            $redirectWith('error', 'No quotations selected.');
        }
        $updated = 0;
        foreach ($selected as $qid) {
            $q = documents_get_quote($qid);
            if ($q === null) {
                continue;
            }
            $targets = ['archive' => 'archived', 'unarchive' => 'unarchived', 'set_approved' => 'approved', 'set_accepted' => 'accepted'];
            if (!isset($targets[$bulkAction])) {
                continue;
            }
            $user = current_user();
            $transition = documents_quote_apply_admin_status_transition($q, $targets[$bulkAction], [
                'type' => (string) ($user['role_name'] ?? 'admin'),
                'id' => (string) ($user['id'] ?? ''),
                'name' => (string) ($user['full_name'] ?? 'Admin'),
            ]);
            if ($transition['ok'] ?? false) {
                $updated++;
            }
        }
        $redirectWith('success', 'Bulk action applied on ' . $updated . ' quotation(s).');
    }

}

documents_normalize_quotes_store();
$allQuotes = documents_list_quotes();
$statusFilter = safe_text($_GET['status_filter'] ?? '');
$tab = safe_text($_GET['tab'] ?? 'quotations');
if (!in_array($tab, ['quotations','editor','archived','bulk','settings'], true)) { $tab = 'quotations'; }
$isQuotationListPartialRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'quotation-list'
    || safe_text((string) ($_GET['partial'] ?? '')) === 'quotation_list';
if ($isQuotationListPartialRequest && $tab !== 'archived') {
    $tab = 'quotations';
}
if ($tab === 'archived') {
    $allQuotes = array_values(array_filter($allQuotes, static function (array $q): bool {
        return documents_is_archived($q);
    }));
} elseif ($tab !== 'bulk') {
    $allQuotes = array_values(array_filter($allQuotes, static function (array $q): bool {
        return !documents_is_archived($q);
    }));
}
if ($statusFilter !== '') {
    $allQuotes = array_values(array_filter($allQuotes, static function (array $q) use ($statusFilter): bool {
        $status = documents_quote_normalize_status((string) ($q['status'] ?? 'draft'));
        if ($statusFilter === 'needs_approval') {
            return $status === 'draft' && (string) ($q['created_by_type'] ?? '') === 'employee';
        }
        return strtolower($status) === strtolower($statusFilter);
    }));
}
if ($isQuotationListPartialRequest) {
    header('Content-Type: text/html; charset=UTF-8');
    require __DIR__ . '/admin/partials/quotation-list.php';
    exit;
}
$editingId = safe_text($_GET['edit'] ?? '');
if ($editingId !== '') { $tab = 'editor'; }
$editing = $editingId !== '' ? documents_get_quote($editingId) : null;
$resolveSegmentDefaults = static function (string $segmentCode) use ($quoteDefaults): array {
    $segments = is_array($quoteDefaults['segments'] ?? null) ? $quoteDefaults['segments'] : [];
    $segmentCode = strtoupper(trim($segmentCode));
    $segment = is_array($segments[$segmentCode] ?? null) ? $segments[$segmentCode] : [];
    $fallbackRes = is_array($segments['RES'] ?? null) ? $segments['RES'] : [];

    $loanSegment = is_array($segment['loan_bestcase'] ?? null) ? $segment['loan_bestcase'] : [];
    $loanRes = is_array($fallbackRes['loan_bestcase'] ?? null) ? $fallbackRes['loan_bestcase'] : [];

    return [
        'unit_rate_rs_per_kwh' => (float) ($segment['unit_rate_rs_per_kwh'] ?? ($fallbackRes['unit_rate_rs_per_kwh'] ?? 0)),
        'annual_generation_per_kw' => (float) ($segment['annual_generation_per_kw'] ?? ($quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450)),
        'loan_bestcase' => [
            'max_loan_rs' => (float) ($loanSegment['max_loan_rs'] ?? ($loanRes['max_loan_rs'] ?? 200000)),
            'interest_pct' => (float) ($loanSegment['interest_pct'] ?? ($loanRes['interest_pct'] ?? 6.0)),
            'tenure_years' => (int) ($loanSegment['tenure_years'] ?? ($loanRes['tenure_years'] ?? (($segment['loan_defaults']['tenure_years'] ?? ($fallbackRes['loan_defaults']['tenure_years'] ?? 10))))),
            'min_margin_pct' => (float) ($loanSegment['min_margin_pct'] ?? ($loanRes['min_margin_pct'] ?? 10)),
        ],
    ];
};
$editingSegment = safe_text((string) ($editing['segment'] ?? ''));
if ($editingSegment === '' && (string) ($editing['id'] ?? '') === '') {
    $selectedTemplateId = safe_text((string) ($editing['template_set_id'] ?? ''));
    foreach ($templates as $tpl) {
        if ((string) ($tpl['id'] ?? '') === $selectedTemplateId) {
            $editingSegment = safe_text((string) ($tpl['segment'] ?? ''));
            break;
        }
    }
}
$segmentDefaults = $resolveSegmentDefaults($editingSegment !== '' ? $editingSegment : 'RES');
$autofillSegments = [];
foreach (['RES', 'COM', 'IND', 'INST'] as $segCode) {
    $autofillSegments[$segCode] = $resolveSegmentDefaults($segCode);
}

$editRestrictionMessage = '';
if ($editing !== null && documents_quote_is_locked($editing)) {
    $editRestrictionMessage = 'This quotation was accepted and is locked. Create a revision to make changes.';
    $editing = null;
} elseif ($editing !== null && documents_quote_normalize_status((string) ($editing['status'] ?? 'draft')) === 'approved') {
    $editRestrictionMessage = 'This quotation is approved and cannot be edited. Accept it or archive it, or clone it into a new draft.';
    $editing = null;
} elseif ($editing !== null && documents_quote_normalize_status((string) ($editing['status'] ?? 'draft')) !== 'draft') {
    $editRestrictionMessage = 'Only draft quotations can be edited.';
    $editing = null;
}

if ($editing === null) {
    $editing = documents_quote_defaults();
    $editing['quotation_date'] = date('Y-m-d');
    $editing['valid_until'] = date('Y-m-d', strtotime('+7 days'));
    if (trim((string) ($editing['template_set_id'] ?? '')) === '') {
        $defaultTemplateId = $resolveDefaultTemplateIdForNewQuote($templates);
        if ($defaultTemplateId !== '') {
            $editing['template_set_id'] = $defaultTemplateId;
            foreach ($templates as $tpl) {
                if ((string) ($tpl['id'] ?? '') === $defaultTemplateId) {
                    $editing['segment'] = safe_text((string) ($tpl['segment'] ?? $editing['segment']));
                    break;
                }
            }
        }
    }
}

$prefillMessage = '';
$fromLeadId = safe_text($_GET['from_lead_id'] ?? '');
if ($editing['id'] === '' && $fromLeadId !== '') {
    $leadPrefill = documents_get_quote_prefill_from_lead($fromLeadId);
    if (($leadPrefill['ok'] ?? false) === true) {
        $prefill = $leadPrefill['prefill'];
        $editing['party_type'] = 'lead';
        $editing['customer_name'] = (string) ($prefill['customer_name'] ?? '');
        $editing['customer_mobile'] = (string) ($prefill['customer_mobile'] ?? '');
        $editing['city'] = (string) ($prefill['city'] ?? '');
        $editing['district'] = (string) ($prefill['district'] ?? '');
        $editing['state'] = (string) ($prefill['state'] ?? '');
        $editing['billing_address'] = (string) ($prefill['locality'] ?? '');
        $editing['site_address'] = (string) ($prefill['locality'] ?? '');
        $editing['area_or_locality'] = (string) ($prefill['locality'] ?? '');
        $editing['customer_snapshot']['city'] = (string) ($prefill['city'] ?? '');
        $editing['customer_snapshot']['district'] = (string) ($prefill['district'] ?? '');
        $editing['customer_snapshot']['state'] = (string) ($prefill['state'] ?? '');
        $editing['customer_snapshot']['address'] = (string) ($prefill['locality'] ?? '');
        $editing['source'] = is_array($prefill['source'] ?? null) ? $prefill['source'] : ['type' => '', 'lead_id' => '', 'lead_mobile' => ''];
        $prefillMessage = 'Prefilled from Lead: ' . (string) ($prefill['customer_name'] ?? '');
    } else {
        $prefillMessage = (string) ($leadPrefill['error'] ?? 'Lead prefill was not possible.');
    }
}

$status = safe_text($_GET['status'] ?? '');
$editingQuoteItems = documents_normalize_quote_structured_items(is_array($editing['quote_items'] ?? null) ? $editing['quote_items'] : []);
$message = safe_text($_GET['message'] ?? '');
$lookupMobile = safe_text($_GET['lookup_mobile'] ?? '');
$lookupResult = $lookupMobile !== '' ? documents_lookup_party_by_mobile($lookupMobile) : null;
$lookup = is_array($lookupResult['record'] ?? null) ? $lookupResult['record'] : null;
$lookupType = (string) ($lookupResult['type'] ?? '');
$lookupNote = (string) ($lookupResult['note'] ?? '');
$lookupBadge = $lookupType === 'customer' ? 'Customer' : ($lookupType === 'lead' ? 'Lead' : '');
$quoteSnapshot = documents_quote_resolve_snapshot($editing);
if ($lookup !== null) {
    $quoteSnapshot = array_merge($quoteSnapshot, $lookup);
    if ($lookupType === 'lead') {
        if (safe_text((string) ($editing['source']['lead_id'] ?? '')) === '') {
            $editing['source']['lead_id'] = (string) ($lookupResult['source']['lead_id'] ?? '');
        }
        $editing['source']['type'] = 'lead';
        $editing['source']['lead_mobile'] = (string) ($lookupResult['source']['lead_mobile'] ?? '');
        $editing['party_type'] = 'lead';
    } elseif ($lookupType === 'customer') {
        $editing['party_type'] = 'customer';
    }
}

$editingFinanceInputs = is_array($editing['finance_inputs'] ?? null) ? $editing['finance_inputs'] : [];
$editingSavingsInputs = is_array($editing['customer_savings_inputs'] ?? null) ? $editing['customer_savings_inputs'] : [];
$editingFinanceScenarios = is_array($editing['finance_scenarios'] ?? null) ? $editing['finance_scenarios'] : [];
$editingLoanUp2Scenario = is_array($editingFinanceScenarios['loan_upto_2_lacs'] ?? null) && $editingFinanceScenarios['loan_upto_2_lacs'] !== []
    ? $editingFinanceScenarios['loan_upto_2_lacs']
    : (is_array($editingFinanceScenarios['loan_upto_2_lacs_subsidy_to_loan'] ?? null) ? $editingFinanceScenarios['loan_upto_2_lacs_subsidy_to_loan'] : []);
$editingLoanAbove2Scenario = is_array($editingFinanceScenarios['loan_above_2_lacs'] ?? null) && $editingFinanceScenarios['loan_above_2_lacs'] !== []
    ? $editingFinanceScenarios['loan_above_2_lacs']
    : (is_array($editingFinanceScenarios['loan_above_2_lacs_subsidy_to_loan'] ?? null) ? $editingFinanceScenarios['loan_above_2_lacs_subsidy_to_loan'] : []);

$savedUnitRateForEdit = trim((string) ($editingFinanceInputs['unit_rate_rs_per_kwh'] ?? ''));
if ($savedUnitRateForEdit === '') {
    $savedUnitRateForEdit = trim((string) ($editingSavingsInputs['unit_rate_rs_per_kwh'] ?? ''));
}
$savedAnnualGenerationForEdit = trim((string) ($editingFinanceInputs['annual_generation_per_kw'] ?? ''));
if ($savedAnnualGenerationForEdit === '') {
    $savedAnnualGenerationForEdit = trim((string) ($editingSavingsInputs['annual_generation_kwh_per_kw'] ?? ''));
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Quotations</title>
<link rel="stylesheet" href="assets/css/admin-unified.css">
<style>
body{font-family:Arial,sans-serif;background:#f4f6fa;margin:0}.wrap{padding:16px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}label{font-size:12px;font-weight:700;display:block;margin-bottom:4px}input,select,textarea{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}textarea{min-height:70px}.btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;background:#1d4ed8;color:#fff;text-decoration:none;border:none;border-radius:8px;padding:8px 12px;cursor:pointer;font-weight:600}.btn.secondary{background:#fff;color:#1f2937;border:1px solid #cbd5e1}.btn.quiet{background:#f8fafc;color:#475569;border:1px solid #e2e8f0}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #e2e8f0;padding:11px 10px;text-align:left;font-size:13px;vertical-align:top}th{background:#f8fafc;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.04em}.muted{color:#64748b}.alert{padding:8px;border-radius:8px;margin-bottom:12px}.ok{background:#ecfdf5}.err{background:#fef2f2}.quotation-tabs{display:flex;gap:4px;align-items:center;flex-wrap:wrap;margin:0 0 14px;padding:5px;background:#fff;border:1px solid #dbe1ea;border-radius:12px}.quotation-tabs a{padding:9px 13px;border-radius:8px;color:#475569;text-decoration:none;font-weight:700}.quotation-tabs a.active{background:#1d4ed8;color:#fff}.quotation-tabs .primary-tab{margin-left:auto;background:#dbeafe;color:#1d4ed8}.quotation-tabs .primary-tab.active{background:#1d4ed8;color:#fff}.workspace-panel{display:none}.workspace-panel.active{display:block}.section-card{border:1px solid #dbe2ee;border-radius:12px;padding:0;background:#fcfdff;margin-bottom:14px;overflow:hidden}.section-card>h3{padding:13px 14px;margin:0}.section-card>summary{cursor:pointer;padding:13px 14px;font-size:15px;font-weight:700;list-style:none}.section-card>summary::-webkit-details-marker{display:none}.section-card>summary:after{content:'+';float:right;color:#64748b}.section-card[open]>summary:after{content:'−'}.section-grid{display:grid;grid-template-columns:repeat(4,minmax(170px,1fr));gap:12px;padding:0 14px 14px}.section-grid .full-span{grid-column:1/-1}.section-card.savings{background:#f8fbff;border-color:#bfdbfe}.section-card .muted{margin-bottom:8px}form.section-card{padding:14px}.editor-intro,.list-toolbar,.form-quick-actions{display:flex;gap:10px;align-items:end;justify-content:space-between;flex-wrap:wrap}.form-quick-actions{position:sticky;top:8px;z-index:20;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:9px 10px;margin-bottom:12px;align-items:center}.list-table-wrap{overflow-x:auto}.quote-customer{font-weight:700;color:#0f172a}.quote-meta{font-size:11px;color:#64748b;margin-top:3px}.quote-amount{text-align:right;white-space:nowrap;font-weight:700}.status-pill{display:inline-block;padding:4px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:11px;font-weight:700}.list-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;min-width:260px}.more-actions{position:relative}.more-actions>summary{list-style:none;cursor:pointer}.more-actions>summary::-webkit-details-marker{display:none}.more-menu{margin-top:7px;padding:9px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;box-shadow:0 12px 30px rgba(15,23,42,.12);min-width:280px}.more-menu .secondary-actions{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}.share-actions{padding-top:8px;border-top:1px solid #e2e8f0}.sticky-head th{position:sticky;top:0;background:#f8fafc;z-index:2}.ux-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.42);display:none;z-index:60}.ux-modal{position:fixed;inset:6vh 4vw;background:#fff;border-radius:12px;display:none;z-index:61;box-shadow:0 22px 56px rgba(0,0,0,.25);overflow:hidden}.ux-modal iframe{width:100%;height:100%;border:none}.ux-modal-head{padding:10px 12px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center}.ux-open{display:block}.toast{position:fixed;right:16px;top:16px;z-index:120;background:#111827;color:#fff;padding:10px 14px;border-radius:10px;display:none;max-width:420px}.toast.show{display:block}.toast.err{background:#b91c1c}@media (max-width:1100px){.section-grid{grid-template-columns:repeat(2,minmax(170px,1fr));}}@media (max-width:700px){.section-grid{grid-template-columns:1fr}.quotation-tabs .primary-tab{margin-left:0}.list-actions{min-width:220px}.wrap{padding:10px}.commercial-flow-strip{overflow:auto}.form-quick-actions{position:static}}
.quote-builder-progress{position:sticky;top:8px;z-index:25;display:flex;gap:6px;overflow-x:auto;padding:7px;margin:0 0 12px;background:rgba(255,255,255,.96);border:1px solid #dbe2ee;border-radius:12px;box-shadow:0 8px 22px rgba(15,23,42,.07)}.quote-builder-progress button{flex:0 0 auto;border:0;background:#f1f5f9;color:#334155;border-radius:999px;padding:7px 10px;cursor:pointer;font-size:12px}.quote-builder-progress b{display:inline-grid;place-items:center;width:19px;height:19px;margin-right:3px;border-radius:50%;background:#dbeafe;color:#1d4ed8}.builder-section{scroll-margin-top:76px}.builder-section>summary,.static-section-heading{display:flex;align-items:flex-start;gap:10px;padding:14px}.builder-section>summary small,.static-section-heading small{display:block;color:#64748b;font-size:12px;font-weight:400;margin-top:3px}.section-number{flex:0 0 25px;display:inline-grid;place-items:center;width:25px;height:25px;border-radius:50%;background:#1d4ed8;color:#fff;font-size:12px}.builder-section--advanced{background:#f8fafc}.builder-section--advanced>summary strong:after{content:'Advanced';margin-left:8px;padding:2px 6px;border-radius:999px;background:#e2e8f0;color:#475569;font-size:10px;text-transform:uppercase}.lookup-section{padding-bottom:14px}.lookup-form{padding:0 14px;margin:0!important}.lookup-controls{display:flex;gap:8px;max-width:620px}.lookup-result{display:grid;gap:3px;margin-top:10px;padding:10px 12px;border-radius:9px}.lookup-result span,.lookup-result small{font-size:12px}.lookup-result--matched{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}.lookup-result--empty{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412}.required-mark{color:#dc2626}.review-summary{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:10px;padding:0 14px 14px}.review-summary div{display:grid;gap:4px;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px}.review-summary span,.review-summary small{color:#64748b;font-size:11px}.save-actions{display:flex;gap:8px;flex-wrap:wrap;padding:0 14px 14px}.save-errors{padding:0 14px 14px}.validation-ok,.validation-warning{padding:10px 12px;border-radius:9px}.validation-ok{background:#ecfdf5;color:#065f46}.validation-warning{background:#fff7ed;color:#9a3412}.validation-links{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.validation-links button{border:1px solid #fdba74;background:#fff;color:#9a3412;border-radius:7px;padding:5px 8px;cursor:pointer}.quick-action-buttons{display:flex;gap:7px;flex-wrap:wrap}.section-grid>div:has(>label+input[required]),.section-grid>div:has(>label+select[required]){order:-1}.section-grid input:focus,.section-grid select:focus,.section-grid textarea:focus,.lookup-controls input:focus{outline:3px solid rgba(37,99,235,.16);border-color:#2563eb}.section-grid table{min-width:900px}.section-grid>div:has(>table){overflow-x:auto}@media(max-width:700px){.quote-builder-progress{top:0;margin-left:-4px;margin-right:-4px}.lookup-controls{display:grid}.lookup-controls .btn,.quick-action-buttons,.quick-action-buttons .btn,.save-actions .btn{width:100%}.form-quick-actions{align-items:stretch}.review-summary{grid-template-columns:1fr}.builder-section>summary,.static-section-heading{padding:12px}.section-grid{padding:0 10px 12px}.section-card>summary:after{margin-left:auto}}

</style></head>
<body class="admin-shell commercial-admin"><main class="wrap commercial-shell">
<header class="card commercial-header"><div><p class="admin-kicker">Commercial workspace</p><h1>Quotations</h1><p class="muted">Build the customer offer, then continue it through agreement, delivery, invoice, and receipt.</p></div><nav class="commercial-header__actions" aria-label="Page actions"><a class="btn secondary" href="admin-dashboard.php">Dashboard</a><a class="btn secondary" href="admin-documents.php">Document Center</a><a class="btn commercial-header__primary" href="admin-quotations.php?tab=editor">+ New Quotation</a></nav></header>
<nav class="commercial-flow-strip" aria-label="Commercial lifecycle"><a class="active" href="admin-quotations.php">Quotation</a><span>→</span><a href="admin-agreements.php">Agreement</a><span>→</span><a href="admin-dispatch-advices.php">Dispatch Advice</a><span>→</span><a href="admin-challans.php">Challan</a><span>→</span><a href="admin-invoices.php">Invoice</a><span>→</span><a href="admin-documents.php?tab=accepted_customers">Receipt</a></nav>
<div data-workspace-root>
<nav class="quotation-tabs workspace-tabs" data-workspace-tabs="fetch" aria-label="Quotation workspace">
<a class="<?= $tab === 'quotations' ? 'active' : '' ?>" data-workspace-tab href="admin-quotations.php?tab=quotations">Quotations</a>
<a class="<?= $tab === 'archived' ? 'active' : '' ?>" data-workspace-tab href="admin-quotations.php?tab=archived">Archived</a>
<a class="<?= $tab === 'bulk' ? 'active' : '' ?>" data-workspace-tab href="admin-quotations.php?tab=bulk">Bulk Tools</a>
<a class="<?= $tab === 'settings' ? 'active' : '' ?>" data-workspace-tab href="admin-quotations.php?tab=settings">Settings</a>
</nav>
<?php if ($message !== ''): ?><div class="alert <?= $status === 'success' ? 'ok' : 'err' ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>
<?php if ($editRestrictionMessage !== ''): ?><div class="alert err"><?= htmlspecialchars($editRestrictionMessage, ENT_QUOTES) ?></div><?php endif; ?>
<?php if ($prefillMessage !== ''): ?><div class="alert ok"><?= htmlspecialchars($prefillMessage, ENT_QUOTES) ?></div><?php endif; ?>
<div class="toast" id="uxToast" role="status" aria-live="polite"></div>
<div id="quotationEditor" class="card workspace-panel <?= $tab === 'editor' ? 'active' : '' ?>">
<div class="editor-intro"><div><h2><?= $editing['id'] === '' ? 'Create Quotation' : 'Edit Quotation' ?></h2><p class="muted">Start with customer and system details. Open advanced sections only when needed.</p></div><a class="btn secondary" href="admin-quotations.php?tab=quotations">Back to quotations</a></div>
<form method="get" style="margin-bottom:10px"><input type="hidden" name="tab" value="editor">
<label for="quoteLookupMobile">Mobile number</label><div class="lookup-controls"><input id="quoteLookupMobile" type="text" name="lookup_mobile" value="<?= htmlspecialchars($lookupMobile, ENT_QUOTES) ?>"><button class="btn" type="submit">Find customer / lead</button></div>
<?php if ($lookupMobile !== ""): ?>
    <?php if ($lookup !== null): ?>
        <div class="lookup-result lookup-result--matched"><span>Matched record</span><strong><?= htmlspecialchars($lookupBadge, ENT_QUOTES) ?></strong><?php if ($lookupType === "lead"): ?><small><?= htmlspecialchars($lookupNote, ENT_QUOTES) ?></small><?php endif; ?></div>
    <?php else: ?>
        <div class="lookup-result lookup-result--empty"><strong>No match found</strong><small>Continue below to create the quotation with new party details.</small></div>
    <?php endif; ?>
<?php endif; ?>
</form>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
<input type="hidden" name="action" value="save_quote"><input type="hidden" name="quote_id" value="<?= htmlspecialchars((string) $editing['id'], ENT_QUOTES) ?>">
<input type="hidden" name="source_type" value="<?= htmlspecialchars((string) ($editing['source']['type'] ?? ($lookupResult['source']['type'] ?? '')), ENT_QUOTES) ?>">
<input type="hidden" name="source_lead_id" value="<?= htmlspecialchars((string) ($editing['source']['lead_id'] ?? ($lookupResult['source']['lead_id'] ?? '')), ENT_QUOTES) ?>">
<input type="hidden" name="source_lead_mobile" value="<?= htmlspecialchars((string) ($editing['source']['lead_mobile'] ?? ($lookupResult['source']['lead_mobile'] ?? '')), ENT_QUOTES) ?>">
<div class="grid">
<div><label>Template Set</label><select name="template_set_id" required><?php foreach ($templates as $tpl): ?><option value="<?= htmlspecialchars((string)$tpl['id'], ENT_QUOTES) ?>" data-segment="<?= htmlspecialchars((string)($tpl['segment'] ?? 'RES'), ENT_QUOTES) ?>" <?= ((string)$editing['template_set_id']===(string)$tpl['id'])?'selected':'' ?>><?= htmlspecialchars((string)$tpl['name'], ENT_QUOTES) ?> (<?= htmlspecialchars((string)$tpl['segment'], ENT_QUOTES) ?>)</option><?php endforeach; ?></select></div>
<div><label>Party Type</label><select name="party_type"><option value="customer" <?= $editing['party_type']==='customer'?'selected':'' ?>>Customer</option><option value="lead" <?= $editing['party_type']!=='customer'?'selected':'' ?>>Lead</option></select></div>
<div><label>Mobile</label><input name="customer_mobile" required value="<?= htmlspecialchars((string)(($lookup !== null) ? (string) ($lookup['mobile'] ?? $lookupMobile) : $editing['customer_mobile']), ENT_QUOTES) ?>"></div>
<div><label>Name</label><input name="customer_name" required value="<?= htmlspecialchars((string)($quoteSnapshot['name'] ?? $editing['customer_name']), ENT_QUOTES) ?>"></div>
<div><label>Consumer Account No. (JBVNL)</label><input name="consumer_account_no" value="<?= htmlspecialchars((string)(($editing['consumer_account_no'] !== '') ? $editing['consumer_account_no'] : ($quoteSnapshot['consumer_account_no'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Meter Number</label><input name="meter_number" value="<?= htmlspecialchars((string)(($editing['meter_number'] !== '') ? $editing['meter_number'] : ($quoteSnapshot['meter_number'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Meter Serial Number</label><input name="meter_serial_number" value="<?= htmlspecialchars((string)(($editing['meter_serial_number'] !== '') ? $editing['meter_serial_number'] : ($quoteSnapshot['meter_serial_number'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>System Type</label><select name="system_type"><?php foreach (['Ongrid','Hybrid','Offgrid','Product'] as $t): ?><option value="<?= $t ?>" <?= $editing['system_type']===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></div>
<div id="rateChartModelField"><label>Rate Chart Model</label><select id="rateChartModelSelect"><option value="">-- select model --</option></select><input type="hidden" name="selected_model_number" value="<?= htmlspecialchars((string)($editing['rate_chart_snapshot']['model_number'] ?? ''), ENT_QUOTES) ?>"><div class="muted">Models come from the selected System Type rate chart. Solar split, matching kit, and prices fill on selection; all fields remain editable.</div><div class="muted" id="modelKitAutofillStatus" aria-live="polite"></div></div>
<?php $hasMainSolarOnQuote = safe_text((string)($editing['main_solar_kwp'] ?? '')) !== ''; ?>
<div id="splitCapacityFields" style="display:<?= ($editing['id'] === '' || $hasMainSolarOnQuote) ? 'block' : 'none' ?>;grid-column:span 2">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
        <div><label>Main Solar Size / DCR (kWp)</label><input type="number" step="0.01" min="0" name="main_solar_kwp" id="mainSolarKwpInput" value="<?= htmlspecialchars((string)($editing['main_solar_kwp'] ?? ''), ENT_QUOTES) ?>" <?= ($editing['id'] === '' || $hasMainSolarOnQuote) ? 'required' : '' ?>></div>
        <div><label>Complimentary Non-DCR Solar Size (kWp)</label><input type="number" step="0.01" min="0" name="complimentary_non_dcr_kwp" id="complimentaryNonDcrKwpInput" value="<?= htmlspecialchars((string)($editing['complimentary_non_dcr_kwp'] ?? ''), ENT_QUOTES) ?>"></div>
        <div><label>Total System Capacity (kWp)</label><input type="number" step="0.01" min="0" id="totalSystemCapacityDisplay" readonly value="<?= htmlspecialchars((string)$editing['capacity_kwp'], ENT_QUOTES) ?>"></div>
    </div>
</div>
<div id="legacyCapacityField" style="display:<?= ($editing['id'] === '' || $hasMainSolarOnQuote) ? 'none' : 'block' ?>"><label>Capacity kWp</label><input name="capacity_kwp" <?= ($editing['id'] === '' || $hasMainSolarOnQuote) ? '' : 'required' ?> value="<?= htmlspecialchars((string)$editing['capacity_kwp'], ENT_QUOTES) ?>"></div>
<input type="hidden" name="capacity_kwp" id="computedCapacityKwp" value="<?= htmlspecialchars((string)$editing['capacity_kwp'], ENT_QUOTES) ?>">
<div><label>Quotation Date</label><input type="date" name="quotation_date" value="<?= htmlspecialchars((string)($editing['quotation_date'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Valid Until</label><input type="date" name="valid_until" value="<?= htmlspecialchars((string)$editing['valid_until'], ENT_QUOTES) ?>"></div>
<div><label>Pricing Mode</label><select name="pricing_mode"><option value="solar_split_70_30" <?= $editing['pricing_mode']==='solar_split_70_30'?'selected':'' ?>>solar_split_70_30</option><option value="flat_5" <?= $editing['pricing_mode']==='flat_5'?'selected':'' ?>>flat_5</option></select></div>
<div><label>Tax Profile</label><select name="tax_profile_id"><option value="">-- none --</option><?php foreach ($inventoryTaxProfiles as $profile): ?><option value="<?= htmlspecialchars((string)($profile['id'] ?? ''), ENT_QUOTES) ?>" <?= (string)($editing['tax_profile_id'] ?? '')===(string)($profile['id'] ?? '')?'selected':'' ?>><?= htmlspecialchars((string)($profile['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
<div><label><input type="checkbox" name="show_tax_breakup" <?= !empty($editing['show_tax_breakup']) ? 'checked' : '' ?>> Show GST Tax Breakup in customer quotation</label></div>
<div><label>Place of Supply State</label><input name="place_of_supply_state" value="<?= htmlspecialchars((string)$editing['place_of_supply_state'], ENT_QUOTES) ?>"></div>
<div><label>District</label><input name="district" value="<?= htmlspecialchars((string)($editing['district'] !== '' ? $editing['district'] : ($quoteSnapshot['district'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>City</label><input name="city" value="<?= htmlspecialchars((string)($editing['city'] !== '' ? $editing['city'] : ($quoteSnapshot['city'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>State</label><input name="state" value="<?= htmlspecialchars((string)($editing['state'] !== '' ? $editing['state'] : ($quoteSnapshot['state'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>PIN</label><input name="pin" value="<?= htmlspecialchars((string)($quoteSnapshot['pin_code'] ?? $editing['pin']), ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><label>Billing Address</label><textarea name="billing_address"><?= htmlspecialchars((string)((($editing['billing_address'] !== '') ? $editing['billing_address'] : ($quoteSnapshot['address'] ?? ''))), ENT_QUOTES) ?></textarea></div>
<div style="grid-column:1/-1"><label>Site Address</label><div style="display:flex;gap:8px;align-items:flex-start"><textarea name="site_address" id="siteAddressField"><?= htmlspecialchars((string)((($editing['site_address'] !== '') ? $editing['site_address'] : ($quoteSnapshot['address'] ?? ''))), ENT_QUOTES) ?></textarea><button type="button" class="btn secondary" id="copyBillingAddressBtn" style="white-space:nowrap">Copy from Billing Address</button></div></div>
<div><label>Application ID</label><input name="application_id" value="<?= htmlspecialchars((string)(($editing['application_id'] !== '') ? $editing['application_id'] : ($quoteSnapshot['application_id'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Application Submitted Date</label><input name="application_submitted_date" value="<?= htmlspecialchars((string)(($editing['application_submitted_date'] !== '') ? $editing['application_submitted_date'] : ($quoteSnapshot['application_submitted_date'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Sanction Load (kWp)</label><input name="sanction_load_kwp" value="<?= htmlspecialchars((string)(($editing['sanction_load_kwp'] !== '') ? $editing['sanction_load_kwp'] : ($quoteSnapshot['sanction_load_kwp'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Installed PV Capacity (kWp)</label><input name="installed_pv_module_capacity_kwp" value="<?= htmlspecialchars((string)(($editing['installed_pv_module_capacity_kwp'] !== '') ? $editing['installed_pv_module_capacity_kwp'] : ($quoteSnapshot['installed_pv_module_capacity_kwp'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Circle</label><input name="circle_name" value="<?= htmlspecialchars((string)(($editing['circle_name'] !== '') ? $editing['circle_name'] : ($quoteSnapshot['circle_name'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Division</label><input name="division_name" value="<?= htmlspecialchars((string)(($editing['division_name'] !== '') ? $editing['division_name'] : ($quoteSnapshot['division_name'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Sub Division</label><input name="sub_division_name" value="<?= htmlspecialchars((string)(($editing['sub_division_name'] !== '') ? $editing['sub_division_name'] : ($quoteSnapshot['sub_division_name'] ?? '')), ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><label>Project Summary</label><input name="project_summary_line" value="<?= htmlspecialchars((string)$editing['project_summary_line'], ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><label>Special Requests From Consumer (Inclusive in the rate)</label><textarea name="special_requests_text"><?= htmlspecialchars((string)($editing['special_requests_text'] ?: $editing['special_requests_inclusive']), ENT_QUOTES) ?></textarea><div class="muted">In case of conflict between annexures and special requests, special requests will be prioritized.</div></div>
<div style="grid-column:1/-1"><h3>Items Table</h3><div class="muted">Item summary is auto-generated from the structured item builder below. Free-text item entry is disabled.</div></div><div style="grid-column:1/-1"><h3>Item Builder (Structured)</h3><div class="muted">Add kits/components from Items Master. Name/description snapshots are captured automatically.</div><table id="structuredItemsTable"><thead><tr><th>Kit / Component Type</th><th>Kit</th><th>Component</th><th>Variant</th><th>Qty</th><th>Unit</th><th>Quotation-specific description / note</th><th></th></tr></thead><tbody><?php foreach ($editingQuoteItems as $sItem): ?><tr><td><select name="quote_item_type[]" class="quote-item-type" required><option value="kit" <?= (string)($sItem['type'] ?? '')==='kit'?'selected':'' ?>>Kit</option><option value="component" <?= (string)($sItem['type'] ?? '')==='component'?'selected':'' ?>>Component</option></select></td><td><select name="quote_item_kit_id[]" class="quote-item-kit"><option value="">-- select kit --</option><?php foreach ($inventoryKits as $kit): ?><option value="<?= htmlspecialchars((string)($kit['id'] ?? ''), ENT_QUOTES) ?>" <?= (string)($sItem['kit_id'] ?? '')===(string)($kit['id'] ?? '')?'selected':'' ?>><?= htmlspecialchars((string)($kit['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></td><td><select name="quote_item_component_id[]" class="quote-item-component"><option value="">-- select component --</option><?php foreach ($inventoryComponents as $cmp): ?><option value="<?= htmlspecialchars((string)($cmp['id'] ?? ''), ENT_QUOTES) ?>" <?= (string)($sItem['component_id'] ?? '')===(string)($cmp['id'] ?? '')?'selected':'' ?>><?= htmlspecialchars((string)($cmp['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></td><td><select name="quote_item_variant_id[]" class="quote-item-variant"><option value="">-- none --</option><?php $cmpId=(string)($sItem['component_id'] ?? ''); foreach (($variantsByComponent[$cmpId] ?? []) as $variant): ?><option value="<?= htmlspecialchars((string)($variant['id'] ?? ''), ENT_QUOTES) ?>" <?= (string)($sItem['variant_id'] ?? '')===(string)($variant['id'] ?? '')?'selected':'' ?>><?= htmlspecialchars((string)($variant['display_name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0" name="quote_item_qty[]" value="<?= htmlspecialchars((string)($sItem['qty'] ?? 0), ENT_QUOTES) ?>"></td><td><input name="quote_item_unit[]" value="<?= htmlspecialchars((string)($sItem['unit'] ?? ''), ENT_QUOTES) ?>"></td><td><div class="muted" style="font-size:11px;margin-bottom:4px"><?= htmlspecialchars((string)($sItem['name_snapshot'] ?? ''), ENT_QUOTES) ?></div><?php $masterPreview = (string)($sItem['master_description_snapshot'] ?? ($sItem['description_snapshot'] ?? '')); if (trim($masterPreview) !== ''): ?><div class="muted" style="font-size:11px;margin-bottom:4px"><?= htmlspecialchars($masterPreview, ENT_QUOTES) ?></div><?php endif; ?><textarea name="quote_item_custom_description[]" rows="2" placeholder="Optional quotation-specific note"><?= htmlspecialchars((string)($sItem['custom_description'] ?? ''), ENT_QUOTES) ?></textarea></td><td><button type="button" class="btn secondary rm-structured-item">Remove</button></td></tr><?php endforeach; ?></tbody></table><button type="button" class="btn secondary" id="addStructuredItemBtn">Add Structured Item</button></div><div style="grid-column:1/-1"><h3>Customer Savings Inputs</h3><div class="muted">These are the common inputs used to calculate savings, residual bill, monthly outflow, cumulative expense, and payback.</div></div>
<div style="grid-column:1/-1"><h3>Section 6 — Scenario Prices</h3><div class="muted">Scenario pricing only (self funded, loan up to ₹2 lacs, loan above ₹2 lacs, hybrid configuration, and primary finance scenario).</div></div>
<div><label>Primary Finance Scenario</label><select name="primary_finance_scenario"><option value="self_funded" <?= (($editing['primary_finance_scenario'] ?? '')==='self_funded')?'selected':'' ?>>Self Funded</option><option value="loan_upto_2_lacs_subsidy_to_loan" <?= (($editing['primary_finance_scenario'] ?? '')==='loan_upto_2_lacs_subsidy_to_loan')?'selected':'' ?>>Loan up to ₹2 lacs (subsidy to loan)</option><option value="loan_upto_2_lacs_subsidy_not_to_loan" <?= (($editing['primary_finance_scenario'] ?? '')==='loan_upto_2_lacs_subsidy_not_to_loan')?'selected':'' ?>>Loan up to ₹2 lacs (subsidy self kept)</option><option value="loan_above_2_lacs_subsidy_to_loan" <?= (($editing['primary_finance_scenario'] ?? '')==='loan_above_2_lacs_subsidy_to_loan')?'selected':'' ?>>Loan above ₹2 lacs (subsidy to loan)</option><option value="loan_above_2_lacs_subsidy_not_to_loan" <?= (($editing['primary_finance_scenario'] ?? '')==='loan_above_2_lacs_subsidy_not_to_loan')?'selected':'' ?>>Loan above ₹2 lacs (subsidy self kept)</option></select></div>
<div><label>Self Funded Price ₹</label><input type="number" step="0.01" name="scenario_price_self_funded" value="<?= htmlspecialchars((string)($editing['scenario_prices']['self_funded']['price'] ?? $editing['input_total_gst_inclusive'] ?? 0), ENT_QUOTES) ?>"></div>
<div><label>Loan up to ₹2 lacs Price ₹</label><input type="number" step="0.01" name="scenario_price_loan_upto_2_lacs" value="<?= htmlspecialchars((string)($editing['scenario_prices']['loan_upto_2_lacs']['price'] ?? $editing['input_total_gst_inclusive'] ?? 0), ENT_QUOTES) ?>"></div>
<div><label>Loan above ₹2 lacs Price ₹</label><input type="number" step="0.01" name="scenario_price_loan_above_2_lacs" value="<?= htmlspecialchars((string)($editing['scenario_prices']['loan_above_2_lacs']['price'] ?? $editing['input_total_gst_inclusive'] ?? 0), ENT_QUOTES) ?>"></div>
<div><label>Hybrid Inverter (kVA)</label><input type="number" step="0.1" name="hybrid_inverter_kva" value="<?= htmlspecialchars((string)($editing['rate_chart_snapshot']['hybrid_inverter_kva'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Hybrid Phase</label><select name="hybrid_phase"><option value="">--</option><option value="1" <?= (($editing['rate_chart_snapshot']['hybrid_phase'] ?? '')==='1')?'selected':'' ?>>1 Phase</option><option value="3" <?= (($editing['rate_chart_snapshot']['hybrid_phase'] ?? '')==='3')?'selected':'' ?>>3 Phase</option></select></div>
<div><label>Hybrid Battery Count</label><input type="number" step="1" min="0" name="hybrid_battery_count" value="<?= htmlspecialchars((string)($editing['rate_chart_snapshot']['hybrid_battery_count'] ?? ''), ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><h3>Section 7 — Funding Scenario Financial Inputs</h3><div class="muted">Finance-input logic with selected primary scenario price and scenario applicability toggles.</div></div>
<div><label>Selected Primary Scenario Price (including GST) ₹</label><input type="number" step="0.01" readonly required name="system_total_incl_gst_rs" value="<?= htmlspecialchars((string)($editing['input_total_gst_inclusive'] ?? 0), ENT_QUOTES) ?>"></div>
<div><label><input type="checkbox" name="loan_upto_2_lacs_applicable" checked> Loan up to ₹2 lacs applicable</label></div>
<div><label><input type="checkbox" name="loan_above_2_lacs_applicable" <?= !empty($editingLoanAbove2Scenario['applicable']) ? 'checked' : '' ?>> Loan above ₹2 lacs applicable</label></div>
<div><label>Monthly electricity bill (₹)</label><input type="number" step="0.01" name="monthly_bill_rs" value="<?= htmlspecialchars((string)($editing['finance_inputs']['monthly_bill_rs'] ?? ''), ENT_QUOTES) ?>"><div class="muted">Suggested bill based on generation & tariff. You can change it. <a href="#" id="resetMonthlySuggestion">Reset suggestion</a></div></div>
<input type="hidden" name="monthly_bill_touched" id="monthlyBillTouched" value="0">
<div><label>Unit rate (₹/kWh)</label><input type="number" step="0.01" name="unit_rate_rs_per_kwh" value="<?= htmlspecialchars((string)($savedUnitRateForEdit !== '' ? $savedUnitRateForEdit : ($segmentDefaults['unit_rate_rs_per_kwh'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Annual generation per kW</label><input type="number" step="0.01" name="annual_generation_per_kw" value="<?= htmlspecialchars((string)($savedAnnualGenerationForEdit !== '' ? $savedAnnualGenerationForEdit : ($quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Transportation ₹</label><input type="number" step="0.01" name="transportation_rs" value="<?= htmlspecialchars((string)($editing['finance_inputs']['transportation_rs'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Discount (₹)</label><input type="number" step="0.01" min="0" name="discount_rs" value="<?= htmlspecialchars((string)($editing['finance_inputs']['discount_rs'] ?? ($editing['discount_rs'] ?? '0')), ENT_QUOTES) ?>"></div>
<div><label>Discount note</label><input name="discount_note" value="<?= htmlspecialchars((string)($editing['finance_inputs']['discount_note'] ?? ($editing['discount_note'] ?? '')), ENT_QUOTES) ?>" placeholder="Optional (e.g. Festival Offer)"></div>
<div><label>Subsidy ₹</label><input type="number" step="0.01" name="subsidy_expected_rs" value="<?= htmlspecialchars((string)($editing['finance_inputs']['subsidy_expected_rs'] ?? ''), ENT_QUOTES) ?>"><div class="muted"><a href="#" id="resetSubsidyDefault">Reset to scheme default</a></div></div>
<input type="hidden" name="loan_enabled" value="0">
<input type="hidden" name="loan_amount" value="<?= htmlspecialchars((string)($editing['finance_inputs']['loan']['loan_amount'] ?? ''), ENT_QUOTES) ?>">
<input type="hidden" name="loan_interest_pct" value="<?= htmlspecialchars((string)($editing['finance_inputs']['loan']['interest_pct'] ?? ''), ENT_QUOTES) ?>">
<input type="hidden" name="loan_tenure_years" value="<?= htmlspecialchars((string)($editing['finance_inputs']['loan']['tenure_years'] ?? ''), ENT_QUOTES) ?>">
<input type="hidden" name="loan_margin_pct" value="<?= htmlspecialchars((string)($editing['finance_inputs']['loan']['margin_pct'] ?? ''), ENT_QUOTES) ?>">
<div style="grid-column:1/-1"><h3>Loan up to ₹2 lacs</h3></div>
<div><label>Finance mode</label><select name="loan_upto_2_lacs_finance_mode"><option value="ratio" <?= (($editingLoanUp2Scenario['finance_mode'] ?? 'ratio')==='ratio')?'selected':'' ?>>Ratio</option><option value="manual" <?= (($editingLoanUp2Scenario['finance_mode'] ?? '')==='manual')?'selected':'' ?>>Manual</option></select></div>
<div><label>Margin %</label><input type="number" step="0.01" name="loan_upto_2_lacs_margin_ratio_pct" value="<?= htmlspecialchars((string)($editingLoanUp2Scenario['margin_ratio_pct'] ?? 10), ENT_QUOTES) ?>"></div>
<div><label>Loan %</label><input type="number" step="0.01" name="loan_upto_2_lacs_loan_ratio_pct" value="<?= htmlspecialchars((string)($editingLoanUp2Scenario['loan_ratio_pct'] ?? 90), ENT_QUOTES) ?>"></div>
<div><label>Margin ₹</label><input type="number" step="0.01" name="loan_upto_2_lacs_margin_money_rs" value="<?= htmlspecialchars((string)($editingLoanUp2Scenario['margin_money_rs'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Loan ₹</label><input type="number" step="0.01" name="loan_upto_2_lacs_loan_amount_rs" value="<?= htmlspecialchars((string)($editingLoanUp2Scenario['loan_amount_rs'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Interest %</label><input type="number" step="0.01" name="loan_upto_2_lacs_interest_pct" value="<?= htmlspecialchars((string)($editingLoanUp2Scenario['interest_pct'] ?? 5.75), ENT_QUOTES) ?>"></div>
<div><label>Tenure years</label><input type="number" step="0.01" name="loan_upto_2_lacs_tenure_years" value="<?= htmlspecialchars((string)($editingLoanUp2Scenario['tenure_years'] ?? ($editing['finance_inputs']['loan']['tenure_years'] ?? '')), ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><h3>Loan above ₹2 lacs</h3></div>
<div><label>Finance mode</label><select name="loan_above_2_lacs_finance_mode"><option value="ratio" <?= (($editingLoanAbove2Scenario['finance_mode'] ?? 'ratio')==='ratio')?'selected':'' ?>>Ratio</option><option value="manual" <?= (($editingLoanAbove2Scenario['finance_mode'] ?? '')==='manual')?'selected':'' ?>>Manual</option></select></div>
<div><label>Margin %</label><input type="number" step="0.01" name="loan_above_2_lacs_margin_ratio_pct" value="<?= htmlspecialchars((string)($editingLoanAbove2Scenario['margin_ratio_pct'] ?? 20), ENT_QUOTES) ?>"></div>
<div><label>Loan %</label><input type="number" step="0.01" name="loan_above_2_lacs_loan_ratio_pct" value="<?= htmlspecialchars((string)($editingLoanAbove2Scenario['loan_ratio_pct'] ?? 80), ENT_QUOTES) ?>"></div>
<div><label>Margin ₹</label><input type="number" step="0.01" name="loan_above_2_lacs_margin_money_rs" value="<?= htmlspecialchars((string)($editingLoanAbove2Scenario['margin_money_rs'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Loan ₹</label><input type="number" step="0.01" name="loan_above_2_lacs_loan_amount_rs" value="<?= htmlspecialchars((string)($editingLoanAbove2Scenario['loan_amount_rs'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Interest %</label><input type="number" step="0.01" name="loan_above_2_lacs_interest_pct" value="<?= htmlspecialchars((string)($editingLoanAbove2Scenario['interest_pct'] ?? 8.15), ENT_QUOTES) ?>"></div>
<div><label>Tenure years</label><input type="number" step="0.01" name="loan_above_2_lacs_tenure_years" value="<?= htmlspecialchars((string)($editingLoanAbove2Scenario['tenure_years'] ?? ''), ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><h3>Typography & Watermark Overrides</h3></div>
<div><label>Base font px</label><input type="number" step="1" name="style_base_font_px" value="<?= htmlspecialchars((string)($editing['style_overrides']['typography']['base_font_px'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Heading scale</label><input type="number" step="0.1" name="style_heading_scale" value="<?= htmlspecialchars((string)($editing['style_overrides']['typography']['heading_scale'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Density</label><select name="style_density"><option value="">Default</option><option value="compact" <?= (($editing['style_overrides']['typography']['density'] ?? '')==='compact')?'selected':'' ?>>Compact</option><option value="comfortable" <?= (($editing['style_overrides']['typography']['density'] ?? '')==='comfortable')?'selected':'' ?>>Comfortable</option><option value="spacious" <?= (($editing['style_overrides']['typography']['density'] ?? '')==='spacious')?'selected':'' ?>>Spacious</option></select></div>
<div><label><input type="checkbox" name="watermark_enabled" <?= (($editing['style_overrides']['watermark']['enabled'] ?? '')==='1') ? 'checked' : '' ?>> Enable watermark override</label></div>
<div><label>Watermark image path</label><input name="watermark_image_path" value="<?= htmlspecialchars((string)($editing['style_overrides']['watermark']['image_path'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Watermark opacity</label><input type="number" step="0.01" min="0" max="1" name="watermark_opacity" value="<?= htmlspecialchars((string)($editing['style_overrides']['watermark']['opacity'] ?? ''), ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><h3>Annexure Overrides</h3><div class="muted">Annexures are based on template snapshot; edit below.</div></div>
<?php foreach (['cover_notes'=>'Cover Notes','system_inclusions'=>'System Inclusions','payment_terms'=>'Payment Terms','warranty'=>'Warranty','system_type_explainer'=>'System Type Explainer','transportation'=>'Transportation','terms_conditions'=>'Terms & Conditions','pm_subsidy_info'=>'PM Subsidy Info','completion_milestones'=>'Completion Milestones','next_steps'=>'Next Steps'] as $key=>$label): ?>
<div style="grid-column:1/-1"><label><?= $label ?></label><textarea name="ann_<?= $key ?>"><?= htmlspecialchars((string)($editing['annexures_overrides'][$key] ?? ''), ENT_QUOTES) ?></textarea></div>
<?php endforeach; ?>
</div><br><button class="btn" type="submit">Save Quotation</button>
</form></div>
<?php require __DIR__ . '/admin/partials/quotation-list.php'; ?>
<?php if ($tab === "settings"): $d = $quoteDefaults; ?><div class="card workspace-panel active"><div class="editor-intro"><div><h2>Quotation Settings</h2><p class="muted">Defaults and helpers used when creating quotations.</p></div><a class="btn secondary" href="admin-quotations.php?tab=quotations">Back to quotations</a></div><form method="post" class="grid"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ""), ENT_QUOTES) ?>"><input type="hidden" name="action" value="save_settings"><div><label>Primary color</label><div style="display:flex;gap:6px"><input type="color" name="ui_primary" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['primary'] ?? "#0ea5e9"), ENT_QUOTES) ?>"><input name="ui_primary_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['primary'] ?? "#0ea5e9"), ENT_QUOTES) ?>"></div></div><div><label>Accent color</label><div style="display:flex;gap:6px"><input type="color" name="ui_accent" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['accent'] ?? "#22c55e"), ENT_QUOTES) ?>"><input name="ui_accent_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['accent'] ?? "#22c55e"), ENT_QUOTES) ?>"></div></div><div><label>Text color</label><div style="display:flex;gap:6px"><input type="color" name="ui_text" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['text'] ?? "#0f172a"), ENT_QUOTES) ?>"><input name="ui_text_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['text'] ?? "#0f172a"), ENT_QUOTES) ?>"></div></div><div><label>Muted text color</label><div style="display:flex;gap:6px"><input type="color" name="ui_muted_text" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['muted_text'] ?? "#475569"), ENT_QUOTES) ?>"><input name="ui_muted_text_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['muted_text'] ?? "#475569"), ENT_QUOTES) ?>"></div></div><div><label>Page background color</label><div style="display:flex;gap:6px"><input type="color" name="ui_page_bg" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['page_bg'] ?? "#f8fafc"), ENT_QUOTES) ?>"><input name="ui_page_bg_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['page_bg'] ?? "#f8fafc"), ENT_QUOTES) ?>"></div></div><div><label>Card background color</label><div style="display:flex;gap:6px"><input type="color" name="ui_card_bg" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['card_bg'] ?? "#ffffff"), ENT_QUOTES) ?>"><input name="ui_card_bg_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['card_bg'] ?? "#ffffff"), ENT_QUOTES) ?>"></div></div><div><label>Border color</label><div style="display:flex;gap:6px"><input type="color" name="ui_border" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['border'] ?? "#e2e8f0"), ENT_QUOTES) ?>"><input name="ui_border_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['border'] ?? "#e2e8f0"), ENT_QUOTES) ?>"></div></div><div><label>Header gradient A</label><div style="display:flex;gap:6px"><input type="color" name="header_gradient_a" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['gradients']['header']['a'] ?? "#0ea5e9"), ENT_QUOTES) ?>"><input name="header_gradient_a_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['gradients']['header']['a'] ?? "#0ea5e9"), ENT_QUOTES) ?>"></div></div><div><label>Header gradient B</label><div style="display:flex;gap:6px"><input type="color" name="header_gradient_b" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['gradients']['header']['b'] ?? "#22c55e"), ENT_QUOTES) ?>"><input name="header_gradient_b_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['gradients']['header']['b'] ?? "#22c55e"), ENT_QUOTES) ?>"></div></div><div><label>Footer gradient A</label><div style="display:flex;gap:6px"><input type="color" name="footer_gradient_a" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['gradients']['footer']['a'] ?? "#0ea5e9"), ENT_QUOTES) ?>"><input name="footer_gradient_a_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['gradients']['footer']['a'] ?? "#0ea5e9"), ENT_QUOTES) ?>"></div></div><div><label>Footer gradient B</label><div style="display:flex;gap:6px"><input type="color" name="footer_gradient_b" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['gradients']['footer']['b'] ?? "#22c55e"), ENT_QUOTES) ?>"><input name="footer_gradient_b_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['gradients']['footer']['b'] ?? "#22c55e"), ENT_QUOTES) ?>"></div></div><div><label>Header font color</label><div style="display:flex;gap:6px"><input type="color" name="header_text_color" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['header_footer']['header_text_color'] ?? "#ffffff"), ENT_QUOTES) ?>"><input name="header_text_color_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['header_footer']['header_text_color'] ?? "#ffffff"), ENT_QUOTES) ?>"></div></div><div><label>Footer font color</label><div style="display:flex;gap:6px"><input type="color" name="footer_text_color" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['header_footer']['footer_text_color'] ?? "#ffffff"), ENT_QUOTES) ?>"><input name="footer_text_color_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['header_footer']['footer_text_color'] ?? "#ffffff"), ENT_QUOTES) ?>"></div></div><div><label>Header gradient direction</label><select name="header_gradient_direction"><option value="to right">left→right</option><option value="to bottom">top→bottom</option></select></div><div><label><input type="checkbox" name="header_gradient_enabled" <?= !empty($d['global']['ui_tokens']['gradients']['header']['enabled'])?'checked':'' ?>> Enable header gradient</label></div><div><label>Footer gradient direction</label><select name="footer_gradient_direction"><option value="to right">left→right</option><option value="to bottom">top→bottom</option></select></div><div><label><input type="checkbox" name="footer_gradient_enabled" <?= !empty($d['global']['ui_tokens']['gradients']['footer']['enabled'])?'checked':'' ?>> Enable footer gradient</label></div><div><label>Default interest rate (%)</label><input type="number" step="0.01" name="res_interest_pct" value="<?= htmlspecialchars((string)($d['segments']['RES']['loan_bestcase']['interest_pct'] ?? 6), ENT_QUOTES) ?>"></div><div><label>Default loan tenure (years)</label><input type="number" name="res_tenure_years" value="<?= htmlspecialchars((string)($d['segments']['RES']['loan_bestcase']['tenure_years'] ?? 10), ENT_QUOTES) ?>"></div><div><label>Annual generation per kW</label><input type="number" step="0.01" name="annual_generation_per_kw" value="<?= htmlspecialchars((string)($d['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450), ENT_QUOTES) ?>"></div><div><label>Emission factor (kg CO2/kWh)</label><input type="number" step="0.01" name="emission_factor_kg_per_kwh" value="<?= htmlspecialchars((string)($d['global']['energy_defaults']['emission_factor_kg_per_kwh'] ?? 0.82), ENT_QUOTES) ?>"></div><div><label>CO2 absorbed per tree per year (kg)</label><input type="number" step="0.01" name="tree_absorption_kg_per_tree_per_year" value="<?= htmlspecialchars((string)($d['global']['energy_defaults']['tree_absorption_kg_per_tree_per_year'] ?? 20), ENT_QUOTES) ?>"></div><div><label><input type="checkbox" name="show_decimals" <?= !empty($d['global']['quotation_ui']['show_decimals'])?'checked':'' ?>> Show INR decimals in quotation</label></div><div><label>QR target</label><select name="qr_target"><option value="quotation" <?= (($d['global']['quotation_ui']['qr_target'] ?? "quotation")==="quotation")?'selected':'' ?>>This quotation link</option><option value="website" <?= (($d['global']['quotation_ui']['qr_target'] ?? "quotation")==="website")?'selected':'' ?>>Company website</option></select></div><div style="grid-column:1/-1"><label>Why Dakshayani points (one per line)</label><textarea name="why_dakshayani_points"><?= htmlspecialchars(implode("\n", (array)($d['global']['quotation_ui']['why_dakshayani_points'] ?? [])), ENT_QUOTES) ?></textarea></div><div style="grid-column:1/-1"><label>Footer disclaimer</label><textarea name="footer_disclaimer"><?= htmlspecialchars((string)($d['global']['quotation_ui']['footer_disclaimer'] ?? ''), ENT_QUOTES) ?></textarea></div><div style="grid-column:1/-1"><label>WhatsApp quotation message template</label><textarea name="whatsapp_message_template" placeholder="Use placeholders like {{name}}, {{city}}, {{system_size}}, {{system_type}}, {{price}}, {{quotation_link}}"><?= htmlspecialchars((string)($d['global']['quotation_ui']['whatsapp_message_template'] ?? $quotationDefaultWhatsappTemplate), ENT_QUOTES) ?></textarea><div class="muted">Supported placeholders: {{name}}, {{city}}, {{system_size}}, {{system_type}}, {{price}}, {{quotation_link}}</div></div><div style="grid-column:1/-1"><label>Rate chart — On-Grid (JSON)</label><textarea name="rate_chart_on_grid_json"><?= htmlspecialchars(json_encode((array)($d['rate_chart']['on_grid'] ?? []), JSON_PRETTY_PRINT), ENT_QUOTES) ?></textarea></div><div style="grid-column:1/-1"><label>Rate chart — Hybrid (JSON)</label><textarea name="rate_chart_hybrid_json"><?= htmlspecialchars(json_encode((array)($d['rate_chart']['hybrid'] ?? []), JSON_PRETTY_PRINT), ENT_QUOTES) ?></textarea></div><div style="grid-column:1/-1"><button class="btn" type="submit">Save Settings</button></div></form></div><?php endif; ?>
<div class="card workspace-panel <?= $tab === 'bulk' ? 'active' : '' ?>"><div class="editor-intro"><div><h2>Bulk Tools</h2><p class="muted">Apply one action to multiple quotations.</p></div><a class="btn secondary" href="admin-quotations.php?tab=quotations">Back to quotations</a></div>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
<input type="hidden" name="action" value="bulk_quote_action">
<div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:8px"><div><label>Action</label><select name="bulk_action"><option value="archive">Archive selected</option><option value="unarchive">Unarchive selected</option><option value="set_approved">Set status approved</option><option value="set_accepted">Set status accepted</option></select></div><button class="btn" type="submit">Apply</button></div>
<table><thead><tr><th></th><th>Quote No</th><th>Customer</th><th>Status</th><th>Updated</th></tr></thead><tbody>
<?php foreach ($allQuotes as $q): ?><tr><td><input type="checkbox" name="selected_ids[]" value="<?= htmlspecialchars((string)$q['id'], ENT_QUOTES) ?>"></td><td><?= htmlspecialchars((string)$q['quote_no'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$q['customer_name'], ENT_QUOTES) ?></td><td><?= htmlspecialchars(documents_status_label($q, 'admin'), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$q['updated_at'], ENT_QUOTES) ?></td></tr><?php endforeach; ?>
</tbody></table>
</form></div>
</div>
<div class="ux-backdrop" id="uxBackdrop"></div><div class="ux-modal" id="uxModal" aria-hidden="true"><div class="ux-modal-head"><strong id="uxModalTitle">Preview</strong><button class="btn secondary" type="button" id="uxModalClose">Close</button></div><iframe id="uxModalFrame" src="about:blank"></iframe></div>
<script>
(function(){
  const wrap=document.querySelector('.wrap');
  if(!wrap)return;

  let toastTimer=0;
  const showToast=(message,isError=false)=>{
    const toast=document.getElementById('uxToast');
    if(!toast)return;
    toast.textContent=String(message||'');
    toast.classList.toggle('err',!!isError);
    toast.classList.add('show');
    window.clearTimeout(toastTimer);
    toastTimer=window.setTimeout(()=>toast.classList.remove('show'),3000);
  };
  const responseLooksLikeLogin=(text)=>/<form[^>]+(?:login|sign-in)|<title>[^<]*(?:login|sign in)/i.test(text);
  const responseLooksLikePhpError=(text)=>/(fatal error|parse error|uncaught (?:error|exception)|<b>warning<\/b>|<b>notice<\/b>)/i.test(text);
  const responseFailureMessage=(response,text,context)=>{
    if(response.redirected||responseLooksLikeLogin(text))return 'Your session may have expired. Please reload the page, sign in, and try again.';
    if(responseLooksLikePhpError(text))return `${context} The server returned a PHP error.`;
    return `${context} The server returned an unexpected response${response.status?` (HTTP ${response.status})`:''}.`;
  };
  const readJsonResponse=async(response)=>{
    const text=await response.text();
    const contentType=String(response.headers.get('content-type')||'').toLowerCase();
    if(response.redirected||!contentType.includes('json'))throw new Error(responseFailureMessage(response,text,'Quotation action failed.'));
    try{
      return JSON.parse(text);
    }catch(error){
      throw new Error('Quotation action failed because the server returned malformed JSON. Please reload the page and try again.');
    }
  };
  const resolveFormAction=(form)=>{
    const rawAction=form.getAttribute('action')||window.location.href;
    try{
      return new URL(rawAction,window.location.href).href;
    }catch(error){
      return window.location.href;
    }
  };
  const quotationListUrl=()=>{
    const url=new URL('admin-quotations.php',window.location.href);
    url.searchParams.set('partial','quotation_list');
    const filterForm=document.querySelector('#quotationList form[method="get"]');
    const filterData=filterForm?new FormData(filterForm):null;
    ['tab','status_filter'].forEach((key)=>{
      const value=String(filterData?.get(key)||'').trim();
      if(value)url.searchParams.set(key,value);
    });
    return url.href;
  };
  const refreshQuotationList=async()=>{
    const currentList=document.getElementById('quotationList');
    if(!currentList)return;
    const response=await fetch(quotationListUrl(),{credentials:'same-origin',headers:{'X-Requested-With':'quotation-list'}});
    const text=await response.text();
    if(!response.ok||response.redirected||responseLooksLikeLogin(text)||responseLooksLikePhpError(text))throw new Error(responseFailureMessage(response,text,'Quotation was updated, but the quotation list could not be refreshed.'));
    const parsed=new DOMParser().parseFromString(text,'text/html');
    const nextList=parsed.getElementById('quotationList');
    if(!nextList)throw new Error('Quotation was updated, but the refreshed quotation list was not found. Please reload the page.');
    currentList.replaceWith(nextList);
  };

  if(window.fetch){
    document.addEventListener('submit',async(event)=>{
      const form=event.target instanceof HTMLFormElement?event.target:null;
      if(!form||!form.classList.contains('js-quote-action')||!form.closest('#quotationList'))return;
      event.preventDefault();
      const submitter=event.submitter instanceof HTMLButtonElement?event.submitter:null;
      const originalLabel=submitter?submitter.textContent:'';
      if(submitter){submitter.disabled=true;submitter.textContent='Working…';}
      try{
        const formData=new FormData(form);
        if(submitter?.name)formData.set(submitter.name,submitter.value);
        formData.set('ajax','1');
        const response=await fetch(resolveFormAction(form),{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},body:formData});
        const payload=await readJsonResponse(response);
        if(!response.ok||!payload?.ok)throw new Error(payload?.message||`Quotation action failed (HTTP ${response.status}).`);
        showToast(payload.message||'Quotation updated.');
        if(payload.redirect_url){window.location.assign(String(payload.redirect_url));return;}
        const row=form.closest('tr[data-quote-id]');
        const statusPill=row?.querySelector('.status-pill');
        if(statusPill&&payload.status_label)statusPill.textContent=String(payload.status_label);
        if(payload.status==='accepted')row?.querySelectorAll('form input[name="action"][value="approve_quote"],form input[name="action"][value="accept_quote"]').forEach((input)=>input.form?.remove());
        await refreshQuotationList();
      }catch(error){
        showToast(error instanceof Error?error.message:'Quotation action failed.',true);
        if(submitter){submitter.disabled=false;submitter.textContent=originalLabel;}
      }
    });
  }

  const editorCard=document.getElementById('quotationEditor');
  if(editorCard){
    const quoteForm=editorCard.querySelector('form input[name="action"][value="save_quote"]')?.form;
    const grid=quoteForm?.querySelector('.grid');
    const lookupForm=editorCard.querySelector('form[method="get"]');
    if(grid && quoteForm){
      const sectionDefinitions=[
        {id:'customer-details',title:'Customer details',help:'Confirm the matched party and the details that will appear on the quotation.',open:true},
        {id:'system-details',title:'System details',help:'Set the system type, main solar size, complimentary capacity, and project details.',open:true},
        {id:'pricing-template',title:'Pricing, tax, and quotation template',help:'Choose the template and confirm quotation dates, price, GST, and pricing mode.',open:true},
        {id:'finance-savings',title:'Finance, subsidy, and savings assumptions',help:'Optional assumptions used in finance, subsidy, savings, and payback calculations.',open:false,advanced:true},
        {id:'items-scope',title:'Items / scope / structured quotation items',help:'Build the customer-facing scope using the existing structured item tools.',open:true},
        {id:'notes-terms',title:'Notes, terms, annexures, customer-facing details',help:'Optional notes, terms, annexure text, and appearance overrides.',open:false,advanced:true}
      ];
      const sections=Object.fromEntries(sectionDefinitions.map((definition,index)=>{
        const card=document.createElement('details');
        card.className='section-card builder-section'+(definition.advanced?' builder-section--advanced':'');
        card.id='quote-'+definition.id;
        card.open=definition.open;
        card.style.gridColumn='1/-1';
        card.innerHTML='<summary><span class="section-number">'+(index+2)+'</span><span><strong>'+definition.title+'</strong><small>'+definition.help+'</small></span><span class="section-state" aria-hidden="true"></span></summary><div class="section-grid"></div>';
        return [definition.id,{...definition,card,inner:card.querySelector('.section-grid')}];
      }));
      const controls=[...grid.children];
      const fieldNames=(node)=>[...node.querySelectorAll('input[name],select[name],textarea[name]')].map((field)=>field.name.replace(/\[\]$/,''));
      const headingText=(node)=>node.querySelector(':scope > h3')?.textContent?.trim()||'';
      const textLabel=(node)=>node.querySelector(':scope > label')?.textContent?.trim()||'';
      const customerNames=['party_type','customer_mobile','customer_name','consumer_account_no','meter_number','meter_serial_number','district','city','state','pin','billing_address','site_address','circle_name','division_name','sub_division_name'];
      const systemNames=['system_type','selected_model_number','main_solar_kwp','complimentary_non_dcr_kwp','capacity_kwp','application_id','application_submitted_date','sanction_load_kwp','installed_pv_module_capacity_kwp','project_summary_line','special_requests_text','hybrid_inverter_kva','hybrid_phase','hybrid_battery_count'];
      const pricingNames=['template_set_id','quotation_date','valid_until','pricing_mode','tax_profile_id','show_tax_breakup','place_of_supply_state','scenario_price_self_funded','scenario_price_loan_upto_2_lacs','scenario_price_loan_above_2_lacs','transportation_cost','discount_rs','discount_note'];
      const financeNames=['primary_finance_scenario','system_total_incl_gst_rs','monthly_bill_rs','unit_rate_rs_per_kwh','annual_generation_per_kw','transportation_rs','subsidy_expected_rs'];
      const destinationFor=(node)=>{
        const names=fieldNames(node); const heading=headingText(node);
        if(node.id==='splitCapacityFields'||node.id==='legacyCapacityField'||names.some((name)=>systemNames.includes(name)))return sections['system-details'];
        if(heading.includes('Items Table')||heading.includes('Item Builder')||names.some((name)=>name.startsWith('quote_item_')))return sections['items-scope'];
        if(names.some((name)=>financeNames.includes(name))||names.some((name)=>name.startsWith('loan_'))||heading.includes('Funding Scenario Financial Inputs')||heading.includes('Loan up to')||heading.includes('Loan above')||heading.includes('Customer Savings Inputs'))return sections['finance-savings'];
        if(names.some((name)=>pricingNames.includes(name))||heading.includes('Scenario Prices'))return sections['pricing-template'];
        if(names.some((name)=>customerNames.includes(name)))return sections['customer-details'];
        return sections['notes-terms'];
      };
      controls.forEach((node)=>{
        if(node.matches('input[type="hidden"]'))return;
        if(headingText(node).includes('Section 6 — Scenario Prices')||headingText(node).includes('Section 7 — Funding Scenario Financial Inputs'))return;
        const target=destinationFor(node);
        if(node.querySelector('table')||node.querySelector('textarea')||headingText(node)||['Billing Address','Site Address','Project Summary','Special Requests From Consumer (Inclusive in the rate)'].includes(textLabel(node)))node.classList.add('full-span');
        target.inner.appendChild(node);
      });
      Object.values(sections).forEach((section)=>{
        const required=[...section.inner.children].filter((node)=>node.querySelector('[required]'));
        required.reverse().forEach((node)=>section.inner.prepend(node));
      });

      const lookupSection=document.createElement('section');
      lookupSection.className='section-card builder-section lookup-section';
      lookupSection.id='quote-find-customer';
      lookupSection.innerHTML='<div class="static-section-heading"><span class="section-number">1</span><span><strong>Find customer / lead</strong><small>Search by mobile first to quickly fill known customer or lead details.</small></span></div>';
      if(lookupForm){
        lookupForm.classList.add('lookup-form');
        lookupForm.querySelector('[name="lookup_mobile"]')?.setAttribute('inputmode','tel');
        lookupForm.querySelector('[name="lookup_mobile"]')?.setAttribute('autocomplete','tel');
        lookupForm.querySelector('[name="lookup_mobile"]')?.setAttribute('placeholder','Enter mobile number');
        lookupSection.appendChild(lookupForm);
        editorCard.querySelector('.editor-intro')?.after(lookupSection);
      }

      const progress=document.createElement('nav');
      progress.className='quote-builder-progress';
      progress.setAttribute('aria-label','Quotation builder sections');
      progress.innerHTML='<button type="button" data-target="quote-find-customer"><b>1</b> Find party</button>'+sectionDefinitions.map((section,index)=>'<button type="button" data-target="quote-'+section.id+'"><b>'+(index+2)+'</b> '+section.title.replace(' / scope / structured quotation items',' / scope').replace(', annexures, customer-facing details',' & terms')+'</button>').join('')+'<button type="button" data-target="quote-save-actions"><b>8</b> Save</button>';
      lookupSection.after(progress);
      progress.addEventListener('click',(event)=>{const button=event.target.closest('[data-target]');const target=button&&document.getElementById(button.dataset.target);if(!target)return;if(target.tagName==='DETAILS')target.open=true;target.scrollIntoView({behavior:'smooth',block:'start'});});

      const quickActions=document.createElement('div');
      quickActions.className='form-quick-actions';
      quickActions.innerHTML='<span><strong>Quote builder</strong><br><span class="muted" data-builder-status>Complete the required customer, system, pricing, and item details.</span></span><span class="quick-action-buttons"><button class="btn secondary" type="button" data-review-quote>Review & save</button><button class="btn" type="submit">Save Quotation</button></span>';
      grid.innerHTML=''; grid.appendChild(quickActions);
      sectionDefinitions.forEach((definition)=>{const section=sections[definition.id];if(section.inner.children.length)grid.appendChild(section.card);});

      const saveCard=document.createElement('section');
      saveCard.className='section-card builder-section save-section';saveCard.id='quote-save-actions';saveCard.style.gridColumn='1/-1';
      saveCard.innerHTML='<div class="static-section-heading"><span class="section-number">8</span><span><strong>Save and next actions</strong><small>Review the essentials, then save. Sharing, PDF, clone, archive, and next-document actions remain available after save.</small></span></div><div class="review-summary" id="quoteReviewSummary" aria-live="polite"></div><div class="save-errors" id="quoteSaveErrors" aria-live="polite"></div><div class="save-actions"></div>';
      const originalSubmit=quoteForm.querySelector(':scope > button[type="submit"]');
      if(originalSubmit){originalSubmit.classList.add('btn');originalSubmit.textContent='Save Quotation';saveCard.querySelector('.save-actions').appendChild(originalSubmit);}
      const cancel=document.createElement('a');cancel.className='btn secondary';cancel.href='admin-quotations.php?tab=quotations';cancel.textContent='Cancel / Back to quotations';saveCard.querySelector('.save-actions').appendChild(cancel);grid.appendChild(saveCard);

      quoteForm.querySelectorAll('[required]').forEach((field)=>{const label=field.closest('div')?.querySelector(':scope > label');if(label&&!label.querySelector('.required-mark'))label.insertAdjacentHTML('beforeend',' <span class="required-mark" title="Required">*</span>');});
      const escapeHtml=(value)=>String(value).replace(/[&<>\"']/g,(char)=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'}[char]));
      const valueOf=(name)=>{const field=quoteForm.querySelector('[name="'+name+'"]');if(!field)return '—';if(field instanceof HTMLSelectElement)return field.selectedOptions[0]?.textContent?.trim()||'—';return String(field.value||'').trim()||'—';};
      const updateReview=()=>{const capacity=(Number(quoteForm.querySelector('[name="main_solar_kwp"]')?.value||0)+Number(quoteForm.querySelector('[name="complimentary_non_dcr_kwp"]')?.value||0))||Number(quoteForm.querySelector('[name="capacity_kwp"]')?.value||0);const count=quoteForm.querySelectorAll('#structuredItemsTable tbody tr').length;document.getElementById('quoteReviewSummary').innerHTML='<div><span>Customer / lead</span><strong>'+escapeHtml(valueOf('customer_name'))+'</strong><small>'+escapeHtml(valueOf('customer_mobile'))+'</small></div><div><span>System</span><strong>'+escapeHtml(valueOf('system_type'))+'</strong><small>'+escapeHtml(Math.round(capacity*100)/100)+' kWp total capacity</small></div><div><span>Template & pricing</span><strong>'+escapeHtml(valueOf('template_set_id'))+'</strong><small>'+escapeHtml(valueOf('pricing_mode'))+'</small></div><div><span>Structured scope</span><strong>'+count+' item'+(count===1?'':'s')+'</strong><small>Ready for review</small></div>';};
      const revealInvalid=(field)=>{const details=field.closest('details');if(details)details.open=true;field.scrollIntoView({behavior:'smooth',block:'center'});field.focus({preventScroll:true});};
      const reviewValidity=()=>{const invalid=[...quoteForm.querySelectorAll(':invalid')];const box=document.getElementById('quoteSaveErrors');if(!invalid.length){box.innerHTML='<div class="validation-ok">Required fields are complete. You can save this quotation.</div>';return true;}box.innerHTML='<div class="validation-warning"><strong>'+invalid.length+' required field'+(invalid.length===1?' needs':'s need')+' attention.</strong> Use the links below to fix them before saving.</div><div class="validation-links">'+invalid.map((field,index)=>'<button type="button" data-invalid-index="'+index+'">'+escapeHtml(field.closest('div')?.querySelector('label')?.textContent?.trim()||field.name||'Required field')+'</button>').join('')+'</div>';box.querySelectorAll('[data-invalid-index]').forEach((button)=>button.addEventListener('click',()=>revealInvalid(invalid[Number(button.dataset.invalidIndex)])));return false;};
      quickActions.querySelector('[data-review-quote]').addEventListener('click',()=>{updateReview();reviewValidity();saveCard.scrollIntoView({behavior:'smooth',block:'start'});});
      quoteForm.addEventListener('invalid',(event)=>revealInvalid(event.target),true);
      quoteForm.addEventListener('input',updateReview);quoteForm.addEventListener('change',updateReview);updateReview();
      if(!quoteForm.querySelector('[name="customer_mobile"]')?.value)lookupForm?.querySelector('[name="lookup_mobile"]')?.focus();
    }
  }


  const copyBillingAddressBtn=document.getElementById('copyBillingAddressBtn');
  const billingAddressField=document.querySelector('textarea[name="billing_address"]');
  const siteAddressField=document.getElementById('siteAddressField')||document.querySelector('textarea[name="site_address"]');
  if(copyBillingAddressBtn&&billingAddressField&&siteAddressField){
    copyBillingAddressBtn.addEventListener('click',()=>{
      siteAddressField.value=billingAddressField.value;
      siteAddressField.dispatchEvent(new Event('input',{bubbles:true}));
    });
  }

  document.querySelectorAll('#quotationList table').forEach(t=>t.classList.add('sticky-head'));

  document.addEventListener('click',(e)=>{
    const link=e.target.closest('a');
    if(link){
      if(link.classList.contains('js-open-new-tab')){return;}
      const href=link.getAttribute('href')||'';
      if(href.includes('quotation-view.php')){e.preventDefault();openModal(href,'Quotation Preview');return;}
      if(href.includes('quotation-public.php')){e.preventDefault();openModal(href,'Share Link Preview');return;}
    }
    const waBtn=e.target.closest('.js-wa-share');
    if(waBtn){
      e.preventDefault();
      const mobileRaw=String(waBtn.getAttribute('data-customer-mobile')||'').replace(/\D+/g,'');
      if(!mobileRaw){showToast('Customer mobile number is missing or invalid for WhatsApp sharing.',true);return;}
      const formData=new URLSearchParams();
      formData.set('csrf_token',<?= json_encode((string)($_SESSION['csrf_token'] ?? '')) ?>);
      formData.set('action','share_whatsapp_payload');
      formData.set('quote_id',String(waBtn.getAttribute('data-quote-id')||''));
      fetch('admin-quotations.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body:formData.toString()})
        .then(r=>r.json())
        .then(payload=>{
          if(!payload || !payload.ok){showToast((payload && payload.message) ? payload.message : 'Unable to prepare WhatsApp share draft.',true);return;}
          const mobile=String(payload.mobile||'').replace(/\D+/g,'');
          if(!(mobile.length===12 && mobile.startsWith('91'))){showToast('Customer mobile number is missing or invalid for WhatsApp sharing.',true);return;}
          const text=encodeURIComponent(String(payload.message||''));
          window.open('https://wa.me/'+mobile+'?text='+text,'_blank','noopener');
          showToast('WhatsApp share draft prepared.');
        })
        .catch(()=>showToast('Unable to prepare WhatsApp share draft.',true));
    }
  });

  const modal=document.getElementById('uxModal');const backdrop=document.getElementById('uxBackdrop');const frame=document.getElementById('uxModalFrame');const title=document.getElementById('uxModalTitle');
  function openModal(url,t){title.textContent=t;frame.src=url;modal.classList.add('ux-open');backdrop.classList.add('ux-open');}
  function closeModal(){modal.classList.remove('ux-open');backdrop.classList.remove('ux-open');frame.src='about:blank';}
  document.getElementById('uxModalClose')?.addEventListener('click',closeModal);backdrop?.addEventListener('click',closeModal);
})();
</script>
<script>
window.quoteItemVariantsByComponent = <?= json_encode($variantsByComponent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
document.addEventListener('click', function(e){
    const target = e.target instanceof HTMLElement ? e.target : null;
    if (!target || !target.matches('[data-copy-target]')) {
        return;
    }
    const inputId = target.getAttribute('data-copy-target') || '';
    const input = document.getElementById(inputId);
    if (!(input instanceof HTMLInputElement)) {
        return;
    }
    input.select();
    input.setSelectionRange(0, 99999);
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value);
    } else {
        document.execCommand('copy');
    }
});
</script>
<script>
const createStructuredItemRow = () => {
    const tb = document.querySelector('#structuredItemsTable tbody');
    if (!tb) return null;
    const tr = document.createElement('tr');
    tr.innerHTML = '<td><select name="quote_item_type[]" class="quote-item-type" required><option value="kit">Kit</option><option value="component" selected>Component</option></select></td><td><select name="quote_item_kit_id[]" class="quote-item-kit"><option value="">-- select kit --</option><?php foreach ($inventoryKits as $kit): ?><option value="<?= htmlspecialchars((string)($kit['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string)($kit['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></td><td><select name="quote_item_component_id[]" class="quote-item-component"><option value="">-- select component --</option><?php foreach ($inventoryComponents as $cmp): ?><option value="<?= htmlspecialchars((string)($cmp['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string)($cmp['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></td><td><select name="quote_item_variant_id[]" class="quote-item-variant"><option value="">-- none --</option></select></td><td><input type="number" step="0.01" min="0" name="quote_item_qty[]" value="1"></td><td><input name="quote_item_unit[]" value=""></td><td><div class="muted" style="font-size:11px;margin-bottom:4px"></div><div class="muted" style="font-size:11px;margin-bottom:4px"></div><textarea name="quote_item_custom_description[]" rows="2" placeholder="Optional quotation-specific note"></textarea></td><td><button type="button" class="btn secondary rm-structured-item">Remove</button></td>';
    tb.appendChild(tr);
    syncStructuredItemRow(tr);
    return tr;
};

document.addEventListener('click', function (e) {
    if (e.target && e.target.id === 'addStructuredItemBtn') {
        createStructuredItemRow();
    }
    if (e.target && e.target.classList.contains('rm-structured-item')) {
        e.target.closest('tr')?.remove();
    }
});


const quoteItemVariants = window.quoteItemVariantsByComponent || {};
const quoteKitMeta = <?= json_encode(array_column(array_map(static function ($k) { return ['id' => (string)($k['id'] ?? ''), 'name' => (string)($k['name'] ?? ''), 'description' => safe_text((string)($k['description'] ?? ''))]; }, $inventoryKits), null, 'id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || {};
const quoteComponentMeta = <?= json_encode(array_column(array_map(static function ($c) { $desc = safe_text((string)($c['description'] ?? '')); if ($desc === '') { $desc = safe_text((string)($c['notes'] ?? '')); } return ['id' => (string)($c['id'] ?? ''), 'name' => (string)($c['name'] ?? ''), 'description' => $desc]; }, $inventoryComponents), null, 'id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || {};
const syncStructuredItemRow = (tr) => {
    if (!tr) return;
    const typeSel = tr.querySelector('.quote-item-type');
    const kitSel = tr.querySelector('.quote-item-kit');
    const componentSel = tr.querySelector('.quote-item-component');
    const variantSel = tr.querySelector('.quote-item-variant');
    if (!typeSel || !kitSel || !componentSel || !variantSel) return;

    const type = typeSel.value === 'kit' ? 'kit' : 'component';
    const componentId = String(componentSel.value || '');
    const currentVariant = String(variantSel.value || '');
    const variants = Array.isArray(quoteItemVariants[componentId]) ? quoteItemVariants[componentId] : [];

    variantSel.innerHTML = '<option value="">-- none --</option>';
    variants.forEach((row) => {
        const opt = document.createElement('option');
        opt.value = String(row.id || '');
        opt.textContent = String(row.display_name || row.id || 'Variant');
        variantSel.appendChild(opt);
    });
    if (currentVariant !== '') {
        variantSel.value = currentVariant;
    }

    const requiresVariant = variants.length > 0;
    variantSel.required = (type === 'component' && requiresVariant);
    variantSel.closest('td').style.display = type === 'component' ? '' : 'none';

    kitSel.disabled = type !== 'kit';
    componentSel.disabled = type !== 'component';
    variantSel.disabled = type !== 'component';

    if (type === 'kit') {
        componentSel.value = '';
        variantSel.value = '';
        componentSel.required = false;
        kitSel.required = true;
    } else {
        kitSel.value = '';
        kitSel.required = false;
        componentSel.required = true;
    }
    syncStructuredItemDescriptionPreview(tr);
};


const syncStructuredItemDescriptionPreview = (tr) => {
    if (!tr) return;
    const typeSel = tr.querySelector('.quote-item-type');
    const kitSel = tr.querySelector('.quote-item-kit');
    const componentSel = tr.querySelector('.quote-item-component');
    const noteCell = tr.children[6];
    if (!typeSel || !noteCell) return;
    const previewLines = noteCell.querySelectorAll('.muted');
    if (previewLines.length < 2) return;
    let itemName = '';
    let masterDescription = '';
    if (typeSel.value === 'kit') {
        const kitId = String(kitSel && kitSel.value ? kitSel.value : '');
        const kitMeta = quoteKitMeta[kitId] || null;
        itemName = kitMeta && kitMeta.name ? String(kitMeta.name) : '';
        masterDescription = kitMeta && kitMeta.description ? String(kitMeta.description) : '';
    } else {
        const componentId = String(componentSel && componentSel.value ? componentSel.value : '');
        const componentMeta = quoteComponentMeta[componentId] || null;
        itemName = componentMeta && componentMeta.name ? String(componentMeta.name) : '';
        masterDescription = componentMeta && componentMeta.description ? String(componentMeta.description) : '';
    }
    previewLines[0].textContent = itemName;
    previewLines[1].textContent = masterDescription;
};

document.addEventListener('change', function (e) {
    const tr = e.target && e.target.closest ? e.target.closest('#structuredItemsTable tbody tr') : null;
    if (!tr) return;
    if (e.target.classList.contains('quote-item-type') || e.target.classList.contains('quote-item-component')) {
        syncStructuredItemRow(tr);
    }
});

document.querySelectorAll('#structuredItemsTable tbody tr').forEach((tr) => syncStructuredItemRow(tr));

(function () {
    const settingsForm = document.querySelector('form.grid input[name="action"][value="save_settings"]')?.form;
    if (!settingsForm) return;
    const normalizeHex = (value) => {
        const v = String(value || '').trim().toUpperCase();
        const short = v.match(/^#([0-9A-F]{3})$/);
        if (short) {
            const c = short[1];
            return '#' + c[0] + c[0] + c[1] + c[1] + c[2] + c[2];
        }
        if (/^#[0-9A-F]{6}$/.test(v)) return v;
        return '';
    };
    settingsForm.querySelectorAll('div').forEach((pair) => {
        const picker = pair.querySelector('input[type="color"]');
        const hex = pair.querySelector('input[name$="_hex"]');
        if (!picker || !hex) return;
        picker.addEventListener('input', () => {
            hex.value = String(picker.value || '').toUpperCase();
            hex.setCustomValidity('');
        });
        hex.addEventListener('input', () => {
            const normalized = normalizeHex(hex.value);
            if (normalized === '') {
                hex.setCustomValidity('Invalid hex color');
                return;
            }
            hex.setCustomValidity('');
            if (picker.value.toUpperCase() !== normalized) picker.value = normalized;
        });
        const initial = normalizeHex(hex.value) || normalizeHex(picker.value);
        if (initial !== '') {
            picker.value = initial;
            hex.value = initial;
        }
    });
})();

(function () {
    const quoteForm = document.querySelector('form input[name="action"][value="save_quote"]')?.form;
    if (!quoteForm) return;

    const quoteIdInput = quoteForm.querySelector('[name="quote_id"]');
    const mainInput = quoteForm.querySelector('#mainSolarKwpInput');
    const complimentaryInput = quoteForm.querySelector('#complimentaryNonDcrKwpInput');
    const totalDisplay = quoteForm.querySelector('#totalSystemCapacityDisplay');
    const hiddenCapacity = quoteForm.querySelector('#computedCapacityKwp');
    const legacyWrap = quoteForm.querySelector('#legacyCapacityField');
    const splitWrap = quoteForm.querySelector('#splitCapacityFields');
    const legacyCapacityInput = legacyWrap ? legacyWrap.querySelector('input[name="capacity_kwp"]') : null;

    const isNewQuote = !quoteIdInput || String(quoteIdInput.value || '').trim() === '';
    const hasSplitData = mainInput && String(mainInput.value || '').trim() !== '';

    const parseNum = (value) => {
        const n = Number(value);
        return Number.isFinite(n) ? n : 0;
    };

    const enableSplitMode = isNewQuote || hasSplitData;
    if (splitWrap) splitWrap.style.display = enableSplitMode ? 'block' : 'none';
    if (legacyWrap) legacyWrap.style.display = enableSplitMode ? 'none' : 'block';
    if (mainInput) mainInput.required = enableSplitMode;

    const syncCapacity = () => {
        if (!hiddenCapacity) return;
        const previousValue = String(hiddenCapacity.value || '').trim();
        if (enableSplitMode) {
            const total = parseNum(mainInput?.value || 0) + parseNum(complimentaryInput?.value || 0);
            const formatted = (Math.round(total * 100) / 100).toString();
            hiddenCapacity.value = formatted;
            if (totalDisplay) totalDisplay.value = formatted;
        } else if (legacyCapacityInput) {
            hiddenCapacity.value = String(legacyCapacityInput.value || '').trim();
        }
        if (String(hiddenCapacity.value || '').trim() !== previousValue) {
            hiddenCapacity.dispatchEvent(new Event('input', { bubbles: true }));
        }
    };

    mainInput?.addEventListener('input', syncCapacity);
    complimentaryInput?.addEventListener('input', syncCapacity);
    legacyCapacityInput?.addEventListener('input', syncCapacity);
    syncCapacity();
})();

window.quoteFormAutofillConfig = {
    settingsBySegment: <?= json_encode($autofillSegments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    defaultEnergy: <?= json_encode((float)($quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450)) ?>
};

(() => {
    const rateChart = <?= json_encode((array)($quoteDefaults['rate_chart'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const systemType = document.querySelector('select[name="system_type"]');
    const solarSize = document.querySelector('input[name="main_solar_kwp"]') || document.querySelector('input[name="capacity_kwp"]');
    const nonDcrSolarSize = document.querySelector('input[name="complimentary_non_dcr_kwp"]');
    const inverter = document.querySelector('input[name="hybrid_inverter_kva"]');
    const phase = document.querySelector('select[name="hybrid_phase"]');
    const battery = document.querySelector('input[name="hybrid_battery_count"]');
    const selfPrice = document.querySelector('input[name="scenario_price_self_funded"]');
    const up2Price = document.querySelector('input[name="scenario_price_loan_upto_2_lacs"]');
    const above2Price = document.querySelector('input[name="scenario_price_loan_above_2_lacs"]');
    const above2Applicable = document.querySelector('input[name="loan_above_2_lacs_applicable"]');
    const primaryScenario = document.querySelector('select[name="primary_finance_scenario"]');
    const modelField = document.getElementById('rateChartModelField');
    const modelSelect = document.getElementById('rateChartModelSelect');
    const selectedModelNumber = document.querySelector('input[name="selected_model_number"]');
    const modelKitAutofillStatus = document.getElementById('modelKitAutofillStatus');

    const parseNum = (v) => { const n = Number(v); return Number.isFinite(n) ? n : 0; };
    const chartType = () => String(systemType?.value || '').toLowerCase() === 'hybrid' ? 'hybrid' : 'on_grid';
    const chartRows = () => Array.isArray(rateChart[chartType()]) ? rateChart[chartType()] : [];
    const normalizePhaseValue = (value) => String(value || '').trim().replace(/\s*Phase$/i, '');
    const modelVariant = (row) => {
        const modelSuffix = String(row?.model_number || '').trim().split('-').pop().toUpperCase();
        if (modelSuffix === 'DN' || modelSuffix === 'D') return modelSuffix;
        const variant = String(row?.variant || '').trim().toUpperCase();
        return variant === 'DN' || variant === 'D' ? variant : '';
    };
    const modelSolarSplit = (row) => {
        const total = Math.max(0, parseNum(row?.solar_size_kwp));
        const dcr = modelVariant(row) === 'DN' ? Math.min(3, total) : total;
        return { dcr, nonDcr: Math.max(0, total - dcr) };
    };
    const normalizeKitName = (value) => String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
    const hasModelToken = (row, token) => {
        const code = `${String(row?.model_number || '')} ${String(row?.inverter_code || '')} ${String(row?.variant || '')}`;
        return new RegExp(`(^|[-_\\s])${token}($|[-_\\s])`, 'i').test(code);
    };
    const modelKitName = (row) => {
        if (chartType() === 'on_grid') return 'Ongrid Solar Power Generation System';
        if (hasModelToken(row, 'TB')) return 'Hybrid Solar Power Generation System TBased';
        if (hasModelToken(row, 'TL')) return 'Hybrid Solar Power Generation System TLess';
        return '';
    };
    const findKitIdByName = (name) => Object.keys(quoteKitMeta).find((id) => normalizeKitName(quoteKitMeta[id]?.name) === normalizeKitName(name)) || '';
    const autofillModelKit = (row) => {
        const targetName = modelKitName(row);
        const kitId = targetName === '' ? '' : findKitIdByName(targetName);
        if (targetName === '') {
            if (modelKitAutofillStatus) modelKitAutofillStatus.textContent = '';
            return;
        }
        if (kitId === '') {
            if (modelKitAutofillStatus) modelKitAutofillStatus.textContent = `Matching Items Master kit not found: ${targetName}.`;
            return;
        }

        const rows = Array.from(document.querySelectorAll('#structuredItemsTable tbody tr'));
        const existingTarget = rows.find((itemRow) => String(itemRow.querySelector('.quote-item-kit')?.value || '') === kitId);
        if (existingTarget) {
            if (modelKitAutofillStatus) modelKitAutofillStatus.textContent = `Kit already present: ${targetName}.`;
            return;
        }

        let itemRow = rows.find((candidate) => candidate.dataset.modelKitManaged === 'true') || null;
        if (!itemRow) itemRow = createStructuredItemRow();
        if (!itemRow) return;

        const typeField = itemRow.querySelector('.quote-item-type');
        const kitField = itemRow.querySelector('.quote-item-kit');
        const qtyField = itemRow.querySelector('input[name="quote_item_qty[]"]');
        const unitField = itemRow.querySelector('input[name="quote_item_unit[]"]');
        if (typeField) typeField.value = 'kit';
        syncStructuredItemRow(itemRow);
        if (kitField) kitField.value = kitId;
        if (qtyField) qtyField.value = '1';
        if (unitField) unitField.value = 'set';
        itemRow.dataset.modelKitManaged = 'true';
        syncStructuredItemRow(itemRow);
        if (modelKitAutofillStatus) modelKitAutofillStatus.textContent = `Added kit: ${targetName}. Existing item rows were preserved.`;
    };
    const rowLabel = (row, index) => {
        if (String(row.model_number || '').trim() !== '') return String(row.model_number);
        const details = chartType() === 'hybrid'
            ? `${parseNum(row.solar_size_kwp)} kWp / ${parseNum(row.inverter_kva)} kVA / ${String(row.phase || '')} / ${parseNum(row.battery_count)} batteries`
            : `${parseNum(row.solar_size_kwp)} kWp${String(row.phase || '').trim() ? ` / ${String(row.phase)}` : ''}`;
        return `Legacy rate row ${index + 1} — ${details}`;
    };
    const populateModels = (preserveSelection = true) => {
        if (!modelSelect || !modelField) return;
        const typeSupported = ['ongrid', 'hybrid'].includes(String(systemType?.value || '').toLowerCase());
        modelField.style.display = typeSupported ? '' : 'none';
        const wantedModel = preserveSelection ? String(selectedModelNumber?.value || '') : '';
        modelSelect.innerHTML = '<option value="">-- select model --</option>';
        chartRows().forEach((row, index) => {
            const option = document.createElement('option');
            option.value = String(index);
            option.textContent = rowLabel(row, index);
            if (wantedModel !== '' && String(row.model_number || '') === wantedModel) option.selected = true;
            modelSelect.appendChild(option);
        });
        if (!preserveSelection && selectedModelNumber) selectedModelNumber.value = '';
    };
    const applyModel = () => {
        const rawIndex = String(modelSelect?.value || '');
        const index = rawIndex === '' ? -1 : Number(rawIndex);
        const row = Number.isInteger(index) && index >= 0 ? chartRows()[index] : null;
        if (!row) {
            if (selectedModelNumber) selectedModelNumber.value = '';
            return;
        }
        if (selectedModelNumber) selectedModelNumber.value = String(row.model_number || '');
        const split = modelSolarSplit(row);
        if (solarSize) solarSize.value = String(split.dcr);
        if (nonDcrSolarSize) nonDcrSolarSize.value = String(split.nonDcr);
        if (chartType() === 'hybrid') {
            if (inverter) inverter.value = String(parseNum(row.inverter_kva));
            if (phase) phase.value = normalizePhaseValue(row.phase);
            if (battery) battery.value = String(parseNum(row.battery_count));
        }
        [[selfPrice, row.self_funded_price], [up2Price, row.loan_upto_2_lacs_price], [above2Price, row.loan_above_2_lacs_price]].forEach(([field, value]) => {
            if (!field) return;
            field.value = String(parseNum(value));
            field.dispatchEvent(new Event('input', { bubbles: true }));
        });
        solarSize?.dispatchEvent(new Event('input', { bubbles: true }));
        nonDcrSolarSize?.dispatchEvent(new Event('input', { bubbles: true }));
        [solarSize, nonDcrSolarSize, inverter, phase, battery].forEach((field) => field?.dispatchEvent(new Event('change', { bubbles: true })));
        autofillModelKit(row);
    };
    populateModels(true);
    modelSelect?.addEventListener('change', applyModel);
    systemType?.addEventListener('change', () => populateModels(false));

    const pickRow = () => {
        const type = chartType();
        const rows = chartRows();
        return rows.find((row) => {
            if (Math.abs(modelSolarSplit(row).dcr - parseNum(solarSize?.value || 0)) > 0.01) return false;
            if (type === 'hybrid') {
                if (Math.abs(parseNum(row.inverter_kva) - parseNum(inverter?.value || 0)) > 0.01) return false;
                if (normalizePhaseValue(row.phase) !== normalizePhaseValue(phase?.value)) return false;
                if (parseNum(row.battery_count) !== parseNum(battery?.value || 0)) return false;
            }
            return true;
        });
    };
    const fillScenarioPrices = () => {
        const row = pickRow();
        if (!row) return;
        if (selfPrice && parseNum(selfPrice.value) <= 0) {
            selfPrice.value = String(parseNum(row.self_funded_price));
            selfPrice.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (up2Price && parseNum(up2Price.value) <= 0) {
            up2Price.value = String(parseNum(row.loan_upto_2_lacs_price));
            up2Price.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (above2Price && parseNum(above2Price.value) <= 0) {
            above2Price.value = String(parseNum(row.loan_above_2_lacs_price));
            above2Price.dispatchEvent(new Event('input', { bubbles: true }));
        }
    };
    [systemType, solarSize, inverter, phase, battery].forEach((el) => el?.addEventListener('change', fillScenarioPrices));
    fillScenarioPrices();

    const toggleAbove2Applicability = () => {
        const estimatedLoan = parseNum(above2Price?.value) * 0.9;
        const isApplicable = estimatedLoan > 200000;
        const above2Fields = [
            ...document.querySelectorAll('[name^="loan_above_2_lacs_"]'),
            above2Applicable
        ].filter(Boolean);
        above2Fields.forEach((el) => {
            if (el === above2Applicable) {
                if (!isApplicable) {
                    el.checked = false;
                    el.disabled = true;
                    el.closest('label')?.setAttribute('title', 'Loan above ₹2 lacs requires 90% finance > ₹2,00,000');
                } else {
                    el.disabled = false;
                }
                return;
            }
            el.disabled = !isApplicable || (above2Applicable && !above2Applicable.checked);
        });
        if (!isApplicable && primaryScenario && String(primaryScenario.value || '').includes('loan_above_2_lacs')) {
            primaryScenario.value = 'loan_upto_2_lacs_subsidy_to_loan';
        }
    };
    above2Price?.addEventListener('input', toggleAbove2Applicability);
    above2Applicable?.addEventListener('change', toggleAbove2Applicability);
    toggleAbove2Applicability();

    const bindRatioSync = (prefix) => {
        const marginPct = document.querySelector(`[name="${prefix}_margin_ratio_pct"]`);
        const loanPct = document.querySelector(`[name="${prefix}_loan_ratio_pct"]`);
        if (!marginPct || !loanPct) return;
        marginPct.addEventListener('input', () => { loanPct.value = String(Math.max(0, 100 - parseNum(marginPct.value))); });
        loanPct.addEventListener('input', () => { marginPct.value = String(Math.max(0, 100 - parseNum(loanPct.value))); });
    };
    bindRatioSync('loan_upto_2_lacs');
    bindRatioSync('loan_above_2_lacs');

    const updateHybridItemDescriptionPreview = () => {
        if (String(systemType?.value || '').toLowerCase() !== 'hybrid') return;
        const inverterText = parseNum(inverter?.value) > 0 ? `${parseNum(inverter.value).toFixed(1).replace(/\.0$/, '')} kVA inverter` : '';
        const phaseRaw = String(phase?.value || '').trim();
        const phaseText = phaseRaw === '' ? '' : (phaseRaw.includes('Phase') ? phaseRaw : `${phaseRaw} Phase`);
        const batteryCount = Math.max(0, Math.round(parseNum(battery?.value)));
        const batteryText = batteryCount > 0 ? `${batteryCount} Batterie${batteryCount === 1 ? 'y' : 's'}` : '';
        const parts = [inverterText, phaseText, batteryText].filter((part) => part !== '');
        if (!parts.length) return;
        const summary = `Hybrid configuration: ${parts.join(', ')}`;
        const rows = Array.from(document.querySelectorAll('#structuredItemsTable tbody tr'));
        const kitRows = rows.filter((row) => String(row.querySelector('select[name="quote_item_type[]"]')?.value || '') === 'kit');
        if (kitRows.length !== 1) return;
        const textarea = kitRows[0].querySelector('textarea[name="quote_item_custom_description[]"]');
        if (!textarea) return;
        const lines = String(textarea.value || '').split(/\r?\n/).filter((line) => !/^Hybrid configuration:/i.test(line.trim()));
        lines.push(summary);
        textarea.value = lines.filter((line, index) => line.trim() !== '' || index < lines.length - 1).join('\n').trim();
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    };
    [systemType, inverter, phase, battery].forEach((el) => {
        el?.addEventListener('input', updateHybridItemDescriptionPreview);
        el?.addEventListener('change', updateHybridItemDescriptionPreview);
    });
    updateHybridItemDescriptionPreview();
})();

</script><script src="assets/js/quote-form-autofill.js"></script><script src="assets/js/admin-workspace-tabs.js"></script></main></body></html>
