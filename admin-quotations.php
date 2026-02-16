<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();
documents_seed_template_sets_if_empty();

$templates = array_values(array_filter(json_load(documents_templates_dir() . '/template_sets.json', []), static function ($row): bool {
    return is_array($row) && !($row['archived_flag'] ?? false);
}));
$templateBlocks = documents_sync_template_block_entries($templates);
$company = load_company_profile();
$inventoryComponents = documents_inventory_components(false);
$inventoryKits = documents_inventory_kits(false);
$inventoryTaxProfiles = documents_inventory_tax_profiles(false);
$inventoryVariants = documents_inventory_component_variants(false);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirectWith('error', 'Security validation failed.');
    }

    $action = safe_text($_POST['action'] ?? '');
    if ($action === 'save_settings') {
        $d = load_quote_defaults();
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
        $whyRaw = trim((string)($_POST['why_dakshayani_points'] ?? ''));
        $whyPoints = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $whyRaw) ?: []), static fn($v): bool => $v !== ''));
        if ($whyPoints !== []) {
            $d['global']['quotation_ui']['why_dakshayani_points'] = $whyPoints;
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
        $mobile = normalize_customer_mobile((string) ($_POST['customer_mobile'] ?? ''));
        $customerName = safe_text($_POST['customer_name'] ?? '');
        $customerRecord = $partyType === 'customer' ? documents_find_customer_by_mobile($mobile) : null;

        if ($mobile === '' || $customerName === '') {
            $redirectWith('error', 'Customer mobile and name are required.');
        }

        $capacity = safe_text($_POST['capacity_kwp'] ?? '');
        if ($capacity === '') {
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
            $quote['created_by_type'] = 'admin';
            $user = current_user();
            $quote['created_by_id'] = (string) ($user['id'] ?? '');
            $quote['created_by_name'] = (string) ($user['full_name'] ?? 'Admin');
            $quote['segment'] = (string) ($selectedTemplate['segment'] ?? 'RES');
            $quote['status'] = 'Draft';
        } else {
            $quote = $existing;
        }

        $quote['template_set_id'] = $templateSetId;
        $sourceType = safe_text($_POST['source_type'] ?? (string) ($quote['source']['type'] ?? ''));
        if ($sourceType === 'lead') {
            $quote['source'] = [
                'type' => 'lead',
                'lead_id' => safe_text($_POST['source_lead_id'] ?? (string) ($quote['source']['lead_id'] ?? '')),
                'lead_mobile' => normalize_customer_mobile((string) ($_POST['source_lead_mobile'] ?? ($quote['source']['lead_mobile'] ?? ''))),
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

        $quote['system_type'] = safe_text($_POST['system_type'] ?? 'Ongrid');
        $quote['capacity_kwp'] = $capacity;
        $quote['project_summary_line'] = safe_text($_POST['project_summary_line'] ?? '');
        $quote['valid_until'] = safe_text($_POST['valid_until'] ?? '');
        $quote['pricing_mode'] = $pricingMode;
        $quote['place_of_supply_state'] = $placeOfSupply;
        $quote['tax_type'] = $taxType;
        $quote['tax_profile_id'] = safe_text((string) ($_POST['tax_profile_id'] ?? ''));
        $itemNames = is_array($_POST['item_name'] ?? null) ? $_POST['item_name'] : [];
        $itemDescs = is_array($_POST['item_description'] ?? null) ? $_POST['item_description'] : [];
        $itemHsns = is_array($_POST['item_hsn'] ?? null) ? $_POST['item_hsn'] : [];
        $itemQtys = is_array($_POST['item_qty'] ?? null) ? $_POST['item_qty'] : [];
        $itemUnits = is_array($_POST['item_unit'] ?? null) ? $_POST['item_unit'] : [];
        $rawItems = [];
        $count = count($itemNames);
        for ($i=0; $i<$count; $i++) {
            $rawItems[] = [
                'name' => safe_text((string) ($itemNames[$i] ?? '')),
                'description' => safe_text((string) ($itemDescs[$i] ?? '')),
                'hsn' => safe_text((string) ($itemHsns[$i] ?? '')),
                'qty' => (float) ($itemQtys[$i] ?? 0),
                'unit' => safe_text((string) ($itemUnits[$i] ?? '')),
                'gst_slab' => '5',
                'basic_amount' => 0,
            ];
        }
        $defaultHsn = safe_text((string) ($quoteDefaults['defaults']['hsn_solar'] ?? '8541')) ?: '8541';
        $quote['items'] = documents_normalize_quote_items($rawItems, $quote['system_type'], (float) $quote['capacity_kwp'], $defaultHsn);
        $structuredTypes = is_array($_POST['quote_item_type'] ?? null) ? $_POST['quote_item_type'] : [];
        $structuredKitIds = is_array($_POST['quote_item_kit_id'] ?? null) ? $_POST['quote_item_kit_id'] : [];
        $structuredComponentIds = is_array($_POST['quote_item_component_id'] ?? null) ? $_POST['quote_item_component_id'] : [];
        $structuredQtys = is_array($_POST['quote_item_qty'] ?? null) ? $_POST['quote_item_qty'] : [];
        $structuredUnits = is_array($_POST['quote_item_unit'] ?? null) ? $_POST['quote_item_unit'] : [];
        $structuredVariantIds = is_array($_POST['quote_item_variant_id'] ?? null) ? $_POST['quote_item_variant_id'] : [];
        $structuredItems = [];
        $structuredCount = count($structuredTypes);
        for ($i = 0; $i < $structuredCount; $i++) {
            $variantId = safe_text((string) ($structuredVariantIds[$i] ?? ''));
            $variant = $variantId !== '' ? documents_inventory_get_component_variant($variantId) : null;
            $structuredItems[] = [
                'type' => safe_text((string) ($structuredTypes[$i] ?? 'component')),
                'kit_id' => safe_text((string) ($structuredKitIds[$i] ?? '')),
                'component_id' => safe_text((string) ($structuredComponentIds[$i] ?? '')),
                'qty' => (float) ($structuredQtys[$i] ?? 0),
                'unit' => safe_text((string) ($structuredUnits[$i] ?? '')),
                'variant_id' => $variantId,
                'variant_snapshot' => is_array($variant) ? [
                    'id' => (string) ($variant['id'] ?? ''),
                    'display_name' => (string) ($variant['display_name'] ?? ''),
                    'brand' => (string) ($variant['brand'] ?? ''),
                    'technology' => (string) ($variant['technology'] ?? ''),
                    'wattage_wp' => (float) ($variant['wattage_wp'] ?? 0),
                    'model_no' => (string) ($variant['model_no'] ?? ''),
                ] : [],
                'name_snapshot' => safe_text((string) ($_POST['quote_item_name_snapshot'][$i] ?? '')),
                'meta' => [],
            ];
        }
        $quote['quote_items'] = documents_normalize_quote_structured_items($structuredItems);
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
        $systemTotalInclGstRs = (float) ($_POST['system_total_incl_gst_rs'] ?? 0);
        $transportationRs = (float) ($_POST['transportation_rs'] ?? 0);
        $subsidyExpectedRs = (float) ($_POST['subsidy_expected_rs'] ?? 0);
        $quote['calc'] = documents_calc_quote_pricing_with_tax_profile($quote, $transportationRs, $subsidyExpectedRs, $systemTotalInclGstRs, $quoteDefaults);
        $quote['tax_breakdown'] = is_array($quote['calc']['tax_breakdown'] ?? null) ? (array) $quote['calc']['tax_breakdown'] : ['basic_total' => 0, 'gst_total' => 0, 'gross_incl_gst' => 0, 'slabs' => []];
        $quote['gst_mode_snapshot'] = (string) ($quote['tax_breakdown']['mode'] ?? 'single');
        $quote['gst_slabs_snapshot'] = is_array($quote['tax_breakdown']['slabs'] ?? null) ? $quote['tax_breakdown']['slabs'] : [];
        $quote['input_total_gst_inclusive'] = $systemTotalInclGstRs;
        $quote['cover_note_text'] = trim((string) ($_POST['cover_note_text'] ?? ''));
        $quote['special_requests_text'] = trim((string) ($_POST['special_requests_text'] ?? ''));
        $quote['special_requests_inclusive'] = $quote['special_requests_text'];
        $quote['special_requests_override_note'] = true;
        $quote['annexures_overrides'] = $annexure;
        $quote['template_attachments'] = (($templateBlocks[$templateSetId]['attachments'] ?? null) && is_array($templateBlocks[$templateSetId]['attachments'])) ? $templateBlocks[$templateSetId]['attachments'] : documents_template_attachment_defaults();
        $quote['finance_inputs']['monthly_bill_rs'] = safe_text($_POST['monthly_bill_rs'] ?? '');
        $quote['finance_inputs']['unit_rate_rs_per_kwh'] = safe_text($_POST['unit_rate_rs_per_kwh'] ?? '');
        $quote['finance_inputs']['annual_generation_per_kw'] = safe_text($_POST['annual_generation_per_kw'] ?? '');
        $quote['finance_inputs']['loan']['enabled'] = isset($_POST['loan_enabled']);
        $quote['finance_inputs']['loan']['interest_pct'] = safe_text($_POST['loan_interest_pct'] ?? '');
        $quote['finance_inputs']['loan']['tenure_years'] = safe_text($_POST['loan_tenure_years'] ?? '');
        $quote['finance_inputs']['loan']['margin_pct'] = safe_text($_POST['loan_margin_pct'] ?? '');
        $quote['finance_inputs']['loan']['loan_amount'] = safe_text($_POST['loan_amount'] ?? '');
        $quote['finance_inputs']['subsidy_expected_rs'] = (string) $subsidyExpectedRs;
        $quote['finance_inputs']['transportation_rs'] = (string) $transportationRs;
        $quote['finance_inputs']['notes_for_customer'] = trim((string) ($_POST['notes_for_customer'] ?? ''));
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
            $statusNorm = documents_quote_normalize_status((string)($q['status'] ?? 'draft'));
            if ($bulkAction === 'archive') {
                $q['status'] = 'archived';
                $q['archived_flag'] = true;
                $q['archived_at'] = date('c');
                $q['archived_by'] = ['type' => 'admin', 'id' => (string)((current_user()['id'] ?? '')), 'name' => (string)((current_user()['full_name'] ?? 'Admin'))];
            } elseif ($bulkAction === 'unarchive') {
                $q['archived_flag'] = false;
                $q['archived_at'] = '';
                $q['archived_by'] = ['type' => '', 'id' => '', 'name' => ''];
                $q['status'] = (string)($q['accepted_at'] ?? '') !== '' ? 'accepted' : ($statusNorm === 'archived' ? 'approved' : $statusNorm);
            } elseif ($bulkAction === 'set_approved') {
                $q['status'] = 'approved';
            } elseif ($bulkAction === 'set_accepted') {
                if ($statusNorm !== 'approved' && $statusNorm !== 'accepted') {
                    continue;
                }
                $q['status'] = 'accepted';
                if ((string)($q['accepted_at'] ?? '') === '') {
                    $q['accepted_at'] = date('c');
                }
                $q['locked_flag'] = true;
                $q['locked_at'] = date('c');
                $q['is_current_version'] = true;
                $syncResult = documents_sync_after_quote_accepted($q);
                $q = $syncResult['quote'];
                documents_quote_set_current_for_series($q);
            } else {
                continue;
            }
            $q['updated_at'] = date('c');
            $saved = documents_save_quote($q);
            if ($saved['ok']) { $updated++; }
        }
        $redirectWith('success', 'Bulk action applied on ' . $updated . ' quotation(s).');
    }

}

documents_normalize_quotes_store();
$allQuotes = documents_list_quotes();
$statusFilter = safe_text($_GET['status_filter'] ?? '');
$tab = safe_text($_GET['tab'] ?? 'quotations');
if (!in_array($tab, ['quotations','archived','settings'], true)) { $tab = 'quotations'; }
if ($tab === 'archived') {
    $allQuotes = array_values(array_filter($allQuotes, static function (array $q): bool {
        return documents_is_archived($q);
    }));
} else {
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
$editingId = safe_text($_GET['edit'] ?? '');
$editing = $editingId !== '' ? documents_get_quote($editingId) : null;
$quoteDefaults = load_quote_defaults();
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
if ($editingSegment === '' && $editing['id'] === '') {
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

if ($editing !== null && documents_quote_is_locked($editing)) {
    $editing = null;
} elseif ($editing !== null && documents_quote_normalize_status((string) ($editing['status'] ?? 'draft')) !== 'draft') {
    $editing = null;
}

if ($editing === null) {
    $editing = documents_quote_defaults();
    $editing['valid_until'] = date('Y-m-d', strtotime('+7 days'));
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
        if ($editing['cover_note_text'] === '' && (string) ($prefill['notes'] ?? '') !== '') {
            $editing['cover_note_text'] = (string) ($prefill['notes'] ?? '');
        }
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
$lookup = $lookupMobile !== '' ? documents_find_customer_by_mobile($lookupMobile) : null;
$quoteSnapshot = documents_quote_resolve_snapshot($editing);
if ($lookup !== null) {
    $quoteSnapshot = array_merge($quoteSnapshot, $lookup);
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Quotations</title>
<style>body{font-family:Arial,sans-serif;background:#f4f6fa;margin:0}.wrap{padding:16px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}label{font-size:12px;font-weight:700;display:block;margin-bottom:4px}input,select,textarea{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}textarea{min-height:70px}.btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;border:none;border-radius:8px;padding:8px 12px;cursor:pointer}.btn.secondary{background:#fff;color:#1f2937;border:1px solid #cbd5e1}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe1ea;padding:8px;text-align:left;font-size:13px}.muted{color:#64748b}.alert{padding:8px;border-radius:8px;margin-bottom:12px}.ok{background:#ecfdf5}.err{background:#fef2f2}</style></head>
<body><main class="wrap">
<div class="card"><h1>Quotations</h1><a class="btn secondary" href="admin-documents.php">Back to Documents</a> <a class="btn secondary" href="admin-quotations.php?tab=quotations">Quotations</a> <a class="btn secondary" href="admin-quotations.php?tab=archived">Archived</a> <a class="btn" href="admin-quotations.php?tab=settings">Settings</a> <a class="btn" href="admin-quotations.php?tab=quotations">Create New</a></div>
<?php if ($message !== ''): ?><div class="alert <?= $status === 'success' ? 'ok' : 'err' ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>
<?php if ($prefillMessage !== ''): ?><div class="alert ok"><?= htmlspecialchars($prefillMessage, ENT_QUOTES) ?></div><?php endif; ?>
<div class="card">
<h2><?= $editing['id'] === '' ? 'Create Quotation' : 'Edit Quotation' ?></h2>
<form method="get" style="margin-bottom:10px">
<label>Customer Lookup by Mobile</label><div style="display:flex;gap:8px"><input type="text" name="lookup_mobile" value="<?= htmlspecialchars($lookupMobile, ENT_QUOTES) ?>"><button class="btn secondary" type="submit">Lookup</button></div>
</form>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
<input type="hidden" name="action" value="save_quote"><input type="hidden" name="quote_id" value="<?= htmlspecialchars((string) $editing['id'], ENT_QUOTES) ?>">
<input type="hidden" name="source_type" value="<?= htmlspecialchars((string) ($editing['source']['type'] ?? ''), ENT_QUOTES) ?>">
<input type="hidden" name="source_lead_id" value="<?= htmlspecialchars((string) ($editing['source']['lead_id'] ?? ''), ENT_QUOTES) ?>">
<input type="hidden" name="source_lead_mobile" value="<?= htmlspecialchars((string) ($editing['source']['lead_mobile'] ?? ''), ENT_QUOTES) ?>">
<div class="grid">
<div><label>Template Set</label><select name="template_set_id" required><?php foreach ($templates as $tpl): ?><option value="<?= htmlspecialchars((string)$tpl['id'], ENT_QUOTES) ?>" data-segment="<?= htmlspecialchars((string)($tpl['segment'] ?? 'RES'), ENT_QUOTES) ?>" <?= ((string)$editing['template_set_id']===(string)$tpl['id'])?'selected':'' ?>><?= htmlspecialchars((string)$tpl['name'], ENT_QUOTES) ?> (<?= htmlspecialchars((string)$tpl['segment'], ENT_QUOTES) ?>)</option><?php endforeach; ?></select></div>
<div><label>Party Type</label><select name="party_type"><option value="customer" <?= $editing['party_type']==='customer'?'selected':'' ?>>Customer</option><option value="lead" <?= $editing['party_type']!=='customer'?'selected':'' ?>>Lead</option></select></div>
<div><label>Mobile</label><input name="customer_mobile" required value="<?= htmlspecialchars((string)(($lookupMobile !== '' && $lookup !== null) ? $lookupMobile : $editing['customer_mobile']), ENT_QUOTES) ?>"></div>
<div><label>Name</label><input name="customer_name" required value="<?= htmlspecialchars((string)($quoteSnapshot['name'] ?? $editing['customer_name']), ENT_QUOTES) ?>"></div>
<div><label>Consumer Account No. (JBVNL)</label><input name="consumer_account_no" value="<?= htmlspecialchars((string)(($editing['consumer_account_no'] !== '') ? $editing['consumer_account_no'] : ($quoteSnapshot['consumer_account_no'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Meter Number</label><input name="meter_number" value="<?= htmlspecialchars((string)(($editing['meter_number'] !== '') ? $editing['meter_number'] : ($quoteSnapshot['meter_number'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Meter Serial Number</label><input name="meter_serial_number" value="<?= htmlspecialchars((string)(($editing['meter_serial_number'] !== '') ? $editing['meter_serial_number'] : ($quoteSnapshot['meter_serial_number'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>System Type</label><select name="system_type"><?php foreach (['Ongrid','Hybrid','Offgrid','Product'] as $t): ?><option value="<?= $t ?>" <?= $editing['system_type']===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></div>
<div><label>Capacity kWp</label><input name="capacity_kwp" required value="<?= htmlspecialchars((string)$editing['capacity_kwp'], ENT_QUOTES) ?>"></div>
<div><label>Valid Until</label><input type="date" name="valid_until" value="<?= htmlspecialchars((string)$editing['valid_until'], ENT_QUOTES) ?>"></div>
<div><label>Cover note paragraph</label><textarea name="cover_note_text"><?= htmlspecialchars((string)($editing['cover_note_text'] ?: ($quoteDefaults['defaults']['cover_note_template'] ?? '')), ENT_QUOTES) ?></textarea></div>
<div><label>Pricing Mode</label><select name="pricing_mode"><option value="solar_split_70_30" <?= $editing['pricing_mode']==='solar_split_70_30'?'selected':'' ?>>solar_split_70_30</option><option value="flat_5" <?= $editing['pricing_mode']==='flat_5'?'selected':'' ?>>flat_5</option></select></div><div><label>Total system price (including GST) ₹</label><input type="number" step="0.01" required name="system_total_incl_gst_rs" value="<?= htmlspecialchars((string)($editing['input_total_gst_inclusive'] ?? 0), ENT_QUOTES) ?>"></div>
<div><label>Tax Profile</label><select name="tax_profile_id"><option value="">-- none --</option><?php foreach ($inventoryTaxProfiles as $profile): ?><option value="<?= htmlspecialchars((string)($profile['id'] ?? ''), ENT_QUOTES) ?>" <?= (string)($editing['tax_profile_id'] ?? '')===(string)($profile['id'] ?? '')?'selected':'' ?>><?= htmlspecialchars((string)($profile['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
<div><label>Place of Supply State</label><input name="place_of_supply_state" value="<?= htmlspecialchars((string)$editing['place_of_supply_state'], ENT_QUOTES) ?>"></div>
<div><label>District</label><input name="district" value="<?= htmlspecialchars((string)($editing['district'] !== '' ? $editing['district'] : ($quoteSnapshot['district'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>City</label><input name="city" value="<?= htmlspecialchars((string)($editing['city'] !== '' ? $editing['city'] : ($quoteSnapshot['city'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>State</label><input name="state" value="<?= htmlspecialchars((string)($editing['state'] !== '' ? $editing['state'] : ($quoteSnapshot['state'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>PIN</label><input name="pin" value="<?= htmlspecialchars((string)($quoteSnapshot['pin_code'] ?? $editing['pin']), ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><label>Billing Address</label><textarea name="billing_address"><?= htmlspecialchars((string)((($editing['billing_address'] !== '') ? $editing['billing_address'] : ($quoteSnapshot['address'] ?? ''))), ENT_QUOTES) ?></textarea></div>
<div style="grid-column:1/-1"><label>Site Address</label><textarea name="site_address"><?= htmlspecialchars((string)((($editing['site_address'] !== '') ? $editing['site_address'] : ($quoteSnapshot['address'] ?? ''))), ENT_QUOTES) ?></textarea></div>
<div><label>Application ID</label><input name="application_id" value="<?= htmlspecialchars((string)(($editing['application_id'] !== '') ? $editing['application_id'] : ($quoteSnapshot['application_id'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Application Submitted Date</label><input name="application_submitted_date" value="<?= htmlspecialchars((string)(($editing['application_submitted_date'] !== '') ? $editing['application_submitted_date'] : ($quoteSnapshot['application_submitted_date'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Sanction Load (kWp)</label><input name="sanction_load_kwp" value="<?= htmlspecialchars((string)(($editing['sanction_load_kwp'] !== '') ? $editing['sanction_load_kwp'] : ($quoteSnapshot['sanction_load_kwp'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Installed PV Capacity (kWp)</label><input name="installed_pv_module_capacity_kwp" value="<?= htmlspecialchars((string)(($editing['installed_pv_module_capacity_kwp'] !== '') ? $editing['installed_pv_module_capacity_kwp'] : ($quoteSnapshot['installed_pv_module_capacity_kwp'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Circle</label><input name="circle_name" value="<?= htmlspecialchars((string)(($editing['circle_name'] !== '') ? $editing['circle_name'] : ($quoteSnapshot['circle_name'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Division</label><input name="division_name" value="<?= htmlspecialchars((string)(($editing['division_name'] !== '') ? $editing['division_name'] : ($quoteSnapshot['division_name'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Sub Division</label><input name="sub_division_name" value="<?= htmlspecialchars((string)(($editing['sub_division_name'] !== '') ? $editing['sub_division_name'] : ($quoteSnapshot['sub_division_name'] ?? '')), ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><label>Project Summary</label><input name="project_summary_line" value="<?= htmlspecialchars((string)$editing['project_summary_line'], ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><label>Special Requests From Consumer (Inclusive in the rate)</label><textarea name="special_requests_text"><?= htmlspecialchars((string)($editing['special_requests_text'] ?: $editing['special_requests_inclusive']), ENT_QUOTES) ?></textarea><div class="muted">In case of conflict between annexures and special requests, special requests will be prioritized.</div></div>
<div style="grid-column:1/-1"><h3>Items Table</h3><table id="itemsTable"><thead><tr><th>Sr No</th><th>Item Name</th><th>Description/Specs</th><th>HSN</th><th>Qty</th><th>Unit</th><th></th></tr></thead><tbody><?php $qItems = is_array($editing['items'] ?? null) && $editing['items'] !== [] ? $editing['items'] : documents_normalize_quote_items([], (string)$editing['system_type'], (float)$editing['capacity_kwp'], (string)($quoteDefaults['defaults']['hsn_solar'] ?? '8541')); foreach ($qItems as $ix => $item): ?><tr><td><?= $ix+1 ?></td><td><input name="item_name[]" value="<?= htmlspecialchars((string)($item['name'] ?? ''), ENT_QUOTES) ?>"></td><td><input name="item_description[]" value="<?= htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES) ?>"></td><td><input name="item_hsn[]" value="<?= htmlspecialchars((string)($item['hsn'] ?? ($quoteDefaults['defaults']['hsn_solar'] ?? '8541')), ENT_QUOTES) ?>"></td><td><input type="number" step="0.01" name="item_qty[]" value="<?= htmlspecialchars((string)($item['qty'] ?? 1), ENT_QUOTES) ?>"></td><td><input name="item_unit[]" value="<?= htmlspecialchars((string)($item['unit'] ?? 'set'), ENT_QUOTES) ?>"></td><td><button type="button" class="btn secondary rm-item">Remove</button></td></tr><?php endforeach; ?></tbody></table><button type="button" class="btn secondary" id="addItemBtn">Add item</button></div><div style="grid-column:1/-1"><h3>Item Builder (Structured)</h3><div class="muted">Optional: add kits/components for packing list and dispatch workflows.</div><table id="structuredItemsTable"><thead><tr><th>Type</th><th>Kit</th><th>Component</th><th>Variant</th><th>Qty</th><th>Unit</th><th>Name Snapshot</th><th></th></tr></thead><tbody><?php foreach ($editingQuoteItems as $sItem): ?><tr><td><select name="quote_item_type[]"><option value="kit" <?= (string)($sItem['type'] ?? '')==='kit'?'selected':'' ?>>Kit</option><option value="component" <?= (string)($sItem['type'] ?? '')==='component'?'selected':'' ?>>Component</option></select></td><td><select name="quote_item_kit_id[]"><option value="">-- select --</option><?php foreach ($inventoryKits as $kit): ?><option value="<?= htmlspecialchars((string)($kit['id'] ?? ''), ENT_QUOTES) ?>" <?= (string)($sItem['kit_id'] ?? '')===(string)($kit['id'] ?? '')?'selected':'' ?>><?= htmlspecialchars((string)($kit['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></td><td><select name="quote_item_component_id[]"><option value="">-- select --</option><?php foreach ($inventoryComponents as $cmp): ?><option value="<?= htmlspecialchars((string)($cmp['id'] ?? ''), ENT_QUOTES) ?>" <?= (string)($sItem['component_id'] ?? '')===(string)($cmp['id'] ?? '')?'selected':'' ?>><?= htmlspecialchars((string)($cmp['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></td><td><select name="quote_item_variant_id[]"><option value="">-- none --</option><?php $cmpId=(string)($sItem['component_id'] ?? ''); foreach (($variantsByComponent[$cmpId] ?? []) as $variant): ?><option value="<?= htmlspecialchars((string)($variant['id'] ?? ''), ENT_QUOTES) ?>" <?= (string)($sItem['variant_id'] ?? '')===(string)($variant['id'] ?? '')?'selected':'' ?>><?= htmlspecialchars((string)($variant['display_name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0" name="quote_item_qty[]" value="<?= htmlspecialchars((string)($sItem['qty'] ?? 0), ENT_QUOTES) ?>"></td><td><input name="quote_item_unit[]" value="<?= htmlspecialchars((string)($sItem['unit'] ?? ''), ENT_QUOTES) ?>"></td><td><input name="quote_item_name_snapshot[]" value="<?= htmlspecialchars((string)($sItem['name_snapshot'] ?? ''), ENT_QUOTES) ?>"></td><td><button type="button" class="btn secondary rm-structured-item">Remove</button></td></tr><?php endforeach; ?></tbody></table><button type="button" class="btn secondary" id="addStructuredItemBtn">Add Structured Item</button></div><div style="grid-column:1/-1"><h3>Customer Savings Inputs</h3><div class="muted">Used for dynamic savings/EMI charts in proposal view.</div></div>
<div><label>Monthly electricity bill (₹)</label><input type="number" step="0.01" name="monthly_bill_rs" value="<?= htmlspecialchars((string)($editing['finance_inputs']['monthly_bill_rs'] ?? ''), ENT_QUOTES) ?>"><div class="muted">Suggested bill based on generation & tariff. You can change it. <a href="#" id="resetMonthlySuggestion">Reset suggestion</a></div></div>
<div><label>Unit rate (₹/kWh)</label><input type="number" step="0.01" name="unit_rate_rs_per_kwh" value="<?= htmlspecialchars((string)($editing['finance_inputs']['unit_rate_rs_per_kwh'] ?: ($segmentDefaults['unit_rate_rs_per_kwh'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Annual generation per kW</label><input type="number" step="0.01" name="annual_generation_per_kw" value="<?= htmlspecialchars((string)($editing['finance_inputs']['annual_generation_per_kw'] ?: ($quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? '')), ENT_QUOTES) ?>"></div>
<div><label>Transportation ₹</label><input type="number" step="0.01" name="transportation_rs" value="<?= htmlspecialchars((string)($editing['finance_inputs']['transportation_rs'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Subsidy ₹</label><input type="number" step="0.01" name="subsidy_expected_rs" value="<?= htmlspecialchars((string)($editing['finance_inputs']['subsidy_expected_rs'] ?? ''), ENT_QUOTES) ?>"><div class="muted"><a href="#" id="resetSubsidyDefault">Reset to scheme default</a></div></div>
<div><label>Loan amount ₹</label><input type="number" step="0.01" name="loan_amount" value="<?= htmlspecialchars((string)($editing['finance_inputs']['loan']['loan_amount'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label><input type="checkbox" name="loan_enabled" <?= !empty($editing['finance_inputs']['loan']['enabled']) ? 'checked' : '' ?>> Loan enabled</label></div>
<div><label>Loan interest %</label><input type="number" step="0.01" name="loan_interest_pct" value="<?= htmlspecialchars((string)($editing['finance_inputs']['loan']['interest_pct'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Loan tenure years</label><input type="number" step="1" name="loan_tenure_years" value="<?= htmlspecialchars((string)($editing['finance_inputs']['loan']['tenure_years'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Margin money ₹</label><input type="number" step="0.01" name="loan_margin_pct" value="<?= htmlspecialchars((string)($editing['finance_inputs']['loan']['margin_pct'] ?? ''), ENT_QUOTES) ?>"><div class="muted"><a href="#" id="resetLoanDefaults">Reset to defaults</a></div></div>
<div style="grid-column:1/-1"><label>Notes for customer</label><textarea name="notes_for_customer"><?= htmlspecialchars((string)($editing['finance_inputs']['notes_for_customer'] ?? ''), ENT_QUOTES) ?></textarea></div>
<div style="grid-column:1/-1"><h3>Typography & Watermark Overrides</h3></div>
<div><label>Base font px</label><input type="number" step="1" name="style_base_font_px" value="<?= htmlspecialchars((string)($editing['style_overrides']['typography']['base_font_px'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Heading scale</label><input type="number" step="0.1" name="style_heading_scale" value="<?= htmlspecialchars((string)($editing['style_overrides']['typography']['heading_scale'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Density</label><select name="style_density"><option value="">Default</option><option value="compact" <?= (($editing['style_overrides']['typography']['density'] ?? '')==='compact')?'selected':'' ?>>Compact</option><option value="comfortable" <?= (($editing['style_overrides']['typography']['density'] ?? '')==='comfortable')?'selected':'' ?>>Comfortable</option><option value="spacious" <?= (($editing['style_overrides']['typography']['density'] ?? '')==='spacious')?'selected':'' ?>>Spacious</option></select></div>
<div><label><input type="checkbox" name="watermark_enabled" <?= (($editing['style_overrides']['watermark']['enabled'] ?? '')==='1') ? 'checked' : '' ?>> Enable watermark override</label></div>
<div><label>Watermark image path</label><input name="watermark_image_path" value="<?= htmlspecialchars((string)($editing['style_overrides']['watermark']['image_path'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Watermark opacity</label><input type="number" step="0.01" min="0" max="1" name="watermark_opacity" value="<?= htmlspecialchars((string)($editing['style_overrides']['watermark']['opacity'] ?? ''), ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><h3>Annexure Overrides</h3><div class="muted">Annexures are based on template snapshot; edit below.</div></div>
<?php foreach (['cover_notes'=>'Cover Notes','system_inclusions'=>'System Inclusions','payment_terms'=>'Payment Terms','warranty'=>'Warranty','system_type_explainer'=>'System Type Explainer','transportation'=>'Transportation','terms_conditions'=>'Terms & Conditions','pm_subsidy_info'=>'PM Subsidy Info'] as $key=>$label): ?>
<div style="grid-column:1/-1"><label><?= $label ?></label><textarea name="ann_<?= $key ?>"><?= htmlspecialchars((string)($editing['annexures_overrides'][$key] ?? ''), ENT_QUOTES) ?></textarea></div>
<?php endforeach; ?>
</div><br><button class="btn" type="submit">Save Quotation</button>
</form></div>
<div class="card"><h2>Quotation List</h2><form method="get" style="margin-bottom:10px;display:flex;gap:8px;align-items:end"><div><label>Status Filter</label><select name="status_filter"><option value="">All</option><option value="needs_approval" <?= $statusFilter==='needs_approval'?'selected':'' ?>>Needs Approval</option><option value="Approved" <?= $statusFilter==='Approved'?'selected':'' ?>>Approved</option><option value="Accepted" <?= $statusFilter==='Accepted'?'selected':'' ?>>Accepted</option></select></div><button class="btn secondary" type="submit">Apply</button></form>
<table><thead><tr><th>Quote No</th><th>Name</th><th>Created By</th><th>Status</th><th>Amount</th><th>Updated</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($allQuotes as $q): ?><tr>
<td><?= htmlspecialchars((string)$q['quote_no'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$q['customer_name'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$q['created_by_name'], ENT_QUOTES) ?></td><td><?= htmlspecialchars(documents_status_label($q, 'admin'), ENT_QUOTES) ?></td><td>₹<?= number_format((float)$q['calc']['grand_total'],2) ?></td><td><?= htmlspecialchars((string)$q['updated_at'], ENT_QUOTES) ?></td>
<td><a class="btn secondary" href="quotation-view.php?id=<?= urlencode((string)$q['id']) ?>">View</a> <?php if (documents_quote_can_edit($q, 'admin')): ?><a class="btn secondary" href="admin-quotations.php?edit=<?= urlencode((string)$q['id']) ?>">Edit</a><?php endif; ?><?php if (documents_quote_is_locked($q)): ?><details style="margin-top:6px"><summary class="muted" style="cursor:pointer">✅ Create Revision</summary><form method="post" style="margin-top:6px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="create_revision"><input type="hidden" name="quote_id" value="<?= htmlspecialchars((string)$q['id'], ENT_QUOTES) ?>"><label>Revision reason (optional)</label><textarea name="revision_reason" placeholder="Reason for revision"></textarea><?php if (documents_quote_has_workflow_documents($q)): ?><p class="muted" style="margin:6px 0">Documents already exist for this accepted quotation. Creating a revision will not change existing PI/Invoice. New documents must be created under the revised quotation.</p><label><input type="checkbox" name="archive_existing_documents" value="1"> Archive existing PI/Invoice after creating revision</label><?php endif; ?><div style="margin-top:6px"><button class="btn" type="submit" onclick="return confirm('Create a new revision from this accepted quotation?');">Create Revision</button></div></form></details><?php endif; ?></td>
</tr><?php endforeach; if ($allQuotes===[]): ?><tr><td colspan="7">No quotations yet.</td></tr><?php endif; ?></tbody></table>
</div>
<?php if ($tab === "settings"): $d = $quoteDefaults; ?><div class="card"><h2>Quotation Settings</h2><form method="post" class="grid"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ""), ENT_QUOTES) ?>"><input type="hidden" name="action" value="save_settings"><div><label>Primary color</label><div style="display:flex;gap:6px"><input type="color" name="ui_primary" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['primary'] ?? "#0ea5e9"), ENT_QUOTES) ?>"><input name="ui_primary_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['primary'] ?? "#0ea5e9"), ENT_QUOTES) ?>"></div></div><div><label>Accent color</label><div style="display:flex;gap:6px"><input type="color" name="ui_accent" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['accent'] ?? "#22c55e"), ENT_QUOTES) ?>"><input name="ui_accent_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['accent'] ?? "#22c55e"), ENT_QUOTES) ?>"></div></div><div><label>Text color</label><div style="display:flex;gap:6px"><input type="color" name="ui_text" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['text'] ?? "#0f172a"), ENT_QUOTES) ?>"><input name="ui_text_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['text'] ?? "#0f172a"), ENT_QUOTES) ?>"></div></div><div><label>Muted text color</label><div style="display:flex;gap:6px"><input type="color" name="ui_muted_text" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['muted_text'] ?? "#475569"), ENT_QUOTES) ?>"><input name="ui_muted_text_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['muted_text'] ?? "#475569"), ENT_QUOTES) ?>"></div></div><div><label>Page background color</label><div style="display:flex;gap:6px"><input type="color" name="ui_page_bg" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['page_bg'] ?? "#f8fafc"), ENT_QUOTES) ?>"><input name="ui_page_bg_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['page_bg'] ?? "#f8fafc"), ENT_QUOTES) ?>"></div></div><div><label>Card background color</label><div style="display:flex;gap:6px"><input type="color" name="ui_card_bg" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['card_bg'] ?? "#ffffff"), ENT_QUOTES) ?>"><input name="ui_card_bg_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['card_bg'] ?? "#ffffff"), ENT_QUOTES) ?>"></div></div><div><label>Border color</label><div style="display:flex;gap:6px"><input type="color" name="ui_border" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['border'] ?? "#e2e8f0"), ENT_QUOTES) ?>"><input name="ui_border_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['colors']['border'] ?? "#e2e8f0"), ENT_QUOTES) ?>"></div></div><div><label>Header gradient A</label><div style="display:flex;gap:6px"><input type="color" name="header_gradient_a" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['gradients']['header']['a'] ?? "#0ea5e9"), ENT_QUOTES) ?>"><input name="header_gradient_a_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['gradients']['header']['a'] ?? "#0ea5e9"), ENT_QUOTES) ?>"></div></div><div><label>Header gradient B</label><div style="display:flex;gap:6px"><input type="color" name="header_gradient_b" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['gradients']['header']['b'] ?? "#22c55e"), ENT_QUOTES) ?>"><input name="header_gradient_b_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['gradients']['header']['b'] ?? "#22c55e"), ENT_QUOTES) ?>"></div></div><div><label>Footer gradient A</label><div style="display:flex;gap:6px"><input type="color" name="footer_gradient_a" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['gradients']['footer']['a'] ?? "#0ea5e9"), ENT_QUOTES) ?>"><input name="footer_gradient_a_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['gradients']['footer']['a'] ?? "#0ea5e9"), ENT_QUOTES) ?>"></div></div><div><label>Footer gradient B</label><div style="display:flex;gap:6px"><input type="color" name="footer_gradient_b" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['gradients']['footer']['b'] ?? "#22c55e"), ENT_QUOTES) ?>"><input name="footer_gradient_b_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['gradients']['footer']['b'] ?? "#22c55e"), ENT_QUOTES) ?>"></div></div><div><label>Header font color</label><div style="display:flex;gap:6px"><input type="color" name="header_text_color" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['header_footer']['header_text_color'] ?? "#ffffff"), ENT_QUOTES) ?>"><input name="header_text_color_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['header_footer']['header_text_color'] ?? "#ffffff"), ENT_QUOTES) ?>"></div></div><div><label>Footer font color</label><div style="display:flex;gap:6px"><input type="color" name="footer_text_color" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['header_footer']['footer_text_color'] ?? "#ffffff"), ENT_QUOTES) ?>"><input name="footer_text_color_hex" value="<?= htmlspecialchars((string)($d['global']['ui_tokens']['header_footer']['footer_text_color'] ?? "#ffffff"), ENT_QUOTES) ?>"></div></div><div><label>Header gradient direction</label><select name="header_gradient_direction"><option value="to right">left→right</option><option value="to bottom">top→bottom</option></select></div><div><label><input type="checkbox" name="header_gradient_enabled" <?= !empty($d['global']['ui_tokens']['gradients']['header']['enabled'])?'checked':'' ?>> Enable header gradient</label></div><div><label>Footer gradient direction</label><select name="footer_gradient_direction"><option value="to right">left→right</option><option value="to bottom">top→bottom</option></select></div><div><label><input type="checkbox" name="footer_gradient_enabled" <?= !empty($d['global']['ui_tokens']['gradients']['footer']['enabled'])?'checked':'' ?>> Enable footer gradient</label></div><div><label>Default interest rate (%)</label><input type="number" step="0.01" name="res_interest_pct" value="<?= htmlspecialchars((string)($d['segments']['RES']['loan_bestcase']['interest_pct'] ?? 6), ENT_QUOTES) ?>"></div><div><label>Default loan tenure (years)</label><input type="number" name="res_tenure_years" value="<?= htmlspecialchars((string)($d['segments']['RES']['loan_bestcase']['tenure_years'] ?? 10), ENT_QUOTES) ?>"></div><div><label>Annual generation per kW</label><input type="number" step="0.01" name="annual_generation_per_kw" value="<?= htmlspecialchars((string)($d['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450), ENT_QUOTES) ?>"></div><div><label>Emission factor (kg CO2/kWh)</label><input type="number" step="0.01" name="emission_factor_kg_per_kwh" value="<?= htmlspecialchars((string)($d['global']['energy_defaults']['emission_factor_kg_per_kwh'] ?? 0.82), ENT_QUOTES) ?>"></div><div><label>CO2 absorbed per tree per year (kg)</label><input type="number" step="0.01" name="tree_absorption_kg_per_tree_per_year" value="<?= htmlspecialchars((string)($d['global']['energy_defaults']['tree_absorption_kg_per_tree_per_year'] ?? 20), ENT_QUOTES) ?>"></div><div><label><input type="checkbox" name="show_decimals" <?= !empty($d['global']['quotation_ui']['show_decimals'])?'checked':'' ?>> Show INR decimals in quotation</label></div><div><label>QR target</label><select name="qr_target"><option value="quotation" <?= (($d['global']['quotation_ui']['qr_target'] ?? "quotation")==="quotation")?'selected':'' ?>>This quotation link</option><option value="website" <?= (($d['global']['quotation_ui']['qr_target'] ?? "quotation")==="website")?'selected':'' ?>>Company website</option></select></div><div style="grid-column:1/-1"><label>Why Dakshayani points (one per line)</label><textarea name="why_dakshayani_points"><?= htmlspecialchars(implode("\n", (array)($d['global']['quotation_ui']['why_dakshayani_points'] ?? [])), ENT_QUOTES) ?></textarea></div><div style="grid-column:1/-1"><label>Footer disclaimer</label><textarea name="footer_disclaimer"><?= htmlspecialchars((string)($d['global']['quotation_ui']['footer_disclaimer'] ?? ''), ENT_QUOTES) ?></textarea></div><div style="grid-column:1/-1"><button class="btn" type="submit">Save Settings</button></div></form></div><?php endif; ?>
<div class="card"><h2>Bulk Modify Quotations</h2>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
<input type="hidden" name="action" value="bulk_quote_action">
<div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:8px"><div><label>Action</label><select name="bulk_action"><option value="archive">Archive selected</option><option value="unarchive">Unarchive selected</option><option value="set_approved">Set status approved</option><option value="set_accepted">Set status accepted</option></select></div><button class="btn" type="submit">Apply</button></div>
<table><thead><tr><th></th><th>Quote No</th><th>Customer</th><th>Status</th><th>Updated</th></tr></thead><tbody>
<?php foreach ($allQuotes as $q): ?><tr><td><input type="checkbox" name="selected_ids[]" value="<?= htmlspecialchars((string)$q['id'], ENT_QUOTES) ?>"></td><td><?= htmlspecialchars((string)$q['quote_no'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$q['customer_name'], ENT_QUOTES) ?></td><td><?= htmlspecialchars(documents_status_label($q, 'admin'), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$q['updated_at'], ENT_QUOTES) ?></td></tr><?php endforeach; ?>
</tbody></table>
</form></div>
<script>
document.addEventListener('click', function (e) {
    if (e.target && e.target.id === 'addItemBtn') {
        const tb = document.querySelector('#itemsTable tbody');
        if (!tb) return;
        const tr = document.createElement('tr');
        const dH = '<?= htmlspecialchars((string)($quoteDefaults['defaults']['hsn_solar'] ?? '8541'), ENT_QUOTES) ?>';
        tr.innerHTML = '<td></td><td><input name="item_name[]"></td><td><input name="item_description[]"></td><td><input name="item_hsn[]" value="' + dH + '"></td><td><input type="number" step="0.01" name="item_qty[]" value="1"></td><td><input name="item_unit[]" value="set"></td><td><button type="button" class="btn secondary rm-item">Remove</button></td>';
        tb.appendChild(tr);
        renumberItems();
    }
    if (e.target && e.target.classList.contains('rm-item')) {
        e.target.closest('tr')?.remove();
        renumberItems();
    }
});

function renumberItems() {
    document.querySelectorAll('#itemsTable tbody tr').forEach((tr, i) => {
        const td = tr.querySelector('td');
        if (td) td.textContent = String(i + 1);
    });
}
renumberItems();

document.addEventListener('click', function (e) {
    if (e.target && e.target.id === 'addStructuredItemBtn') {
        const tb = document.querySelector('#structuredItemsTable tbody');
        if (!tb) return;
        const tr = document.createElement('tr');
        tr.innerHTML = '<td><select name="quote_item_type[]"><option value="kit">Kit</option><option value="component" selected>Component</option></select></td><td><select name="quote_item_kit_id[]"><option value="">-- select --</option><?php foreach ($inventoryKits as $kit): ?><option value="<?= htmlspecialchars((string)($kit['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string)($kit['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></td><td><select name="quote_item_component_id[]"><option value="">-- select --</option><?php foreach ($inventoryComponents as $cmp): ?><option value="<?= htmlspecialchars((string)($cmp['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string)($cmp['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></td><td><select name="quote_item_variant_id[]"><option value="">-- none --</option></select></td><td><input type="number" step="0.01" min="0" name="quote_item_qty[]" value="1"></td><td><input name="quote_item_unit[]" value=""></td><td><input name="quote_item_name_snapshot[]" value=""></td><td><button type="button" class="btn secondary rm-structured-item">Remove</button></td>';
        tb.appendChild(tr);
    }
    if (e.target && e.target.classList.contains('rm-structured-item')) {
        e.target.closest('tr')?.remove();
    }
});

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

window.quoteFormAutofillConfig = {
    settingsBySegment: <?= json_encode($autofillSegments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    defaultEnergy: <?= json_encode((float)($quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450)) ?>
};

</script><script src="assets/js/quote-form-autofill.js"></script></main></body></html>
