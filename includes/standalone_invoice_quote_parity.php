<?php
declare(strict_types=1);

/**
 * Additive quotation-parity editor for standalone invoices only.
 *
 * This file deliberately reuses quotation field concepts and Items Master data,
 * but never creates, accepts, or links a quotation. Existing quotation-backed
 * and legacy-project invoice flows are not routed through these helpers.
 */

function standalone_invoice_quote_parity_is_invoice_page(): bool
{
    foreach ([(string)($_SERVER['SCRIPT_NAME'] ?? ''), (string)($_SERVER['PHP_SELF'] ?? '')] as $path) {
        if ($path !== '' && basename($path) === 'admin-invoices.php') {
            return true;
        }
    }
    return false;
}

function standalone_invoice_quote_parity_actor(): array
{
    $user = current_user();
    return [
        'type' => 'admin',
        'id' => (string)($user['id'] ?? ''),
        'name' => (string)($user['full_name'] ?? $user['name'] ?? 'Admin'),
    ];
}

function standalone_invoice_quote_parity_redirect(string $invoiceId, string $status, string $message): void
{
    $query = $invoiceId !== '' ? ['id' => $invoiceId] : [];
    $query['status'] = $status;
    $query['message'] = $message;
    header('Location: admin-invoices.php?' . http_build_query($query));
    exit;
}

function standalone_invoice_quote_parity_safe_customer(array $customer): array
{
    $fields = [
        'id','serial_number','mobile','mobile_key','name','customer_type','address','city','district','pin_code','state',
        'meter_number','meter_serial_number','jbvnl_account_number','application_id','application_submitted_date',
        'sanction_load_kwp','installed_pv_module_capacity_kwp','circle_name','division_name','sub_division_name',
        'solar_plant_installation_date','status',
    ];
    $out = [];
    foreach ($fields as $field) {
        $out[$field] = is_scalar($customer[$field] ?? null) ? (string)$customer[$field] : '';
    }
    return $out;
}

function standalone_invoice_quote_parity_catalog_payload(): array
{
    $store = new CustomerFsStore();
    $customers = array_map('standalone_invoice_quote_parity_safe_customer', $store->listActiveCustomers());

    $components = [];
    foreach (documents_inventory_components(false) as $component) {
        if (!is_array($component)) continue;
        $components[] = [
            'id' => (string)($component['id'] ?? ''),
            'name' => (string)($component['name'] ?? ''),
            'description' => (string)($component['description'] ?? $component['notes'] ?? ''),
            'hsn' => (string)($component['hsn'] ?? ''),
            'default_unit' => (string)($component['default_unit'] ?? 'pcs'),
            'tax_profile_id' => (string)($component['tax_profile_id'] ?? ''),
            'has_variants' => !empty($component['has_variants']),
        ];
    }

    $kits = [];
    foreach (documents_inventory_kits(false) as $kit) {
        if (!is_array($kit)) continue;
        $kits[] = [
            'id' => (string)($kit['id'] ?? ''),
            'name' => (string)($kit['name'] ?? ''),
            'description' => (string)($kit['description'] ?? ''),
            'hsn' => (string)($kit['hsn'] ?? ''),
            'default_unit' => (string)($kit['default_unit'] ?? 'set'),
            'tax_profile_id' => (string)($kit['tax_profile_id'] ?? ''),
        ];
    }

    $variants = [];
    foreach (documents_inventory_component_variants(false) as $variant) {
        if (!is_array($variant)) continue;
        $variants[] = [
            'id' => (string)($variant['id'] ?? ''),
            'component_id' => (string)($variant['component_id'] ?? ''),
            'display_name' => (string)($variant['display_name'] ?? $variant['name'] ?? ''),
            'description' => (string)($variant['description'] ?? ''),
        ];
    }

    $taxProfiles = [];
    foreach (documents_inventory_tax_profiles(false) as $profile) {
        if (!is_array($profile)) continue;
        $taxProfiles[] = [
            'id' => (string)($profile['id'] ?? ''),
            'name' => (string)($profile['name'] ?? ''),
            'rate_pct' => (float)($profile['rate_pct'] ?? $profile['gst_rate'] ?? $profile['gst_pct'] ?? $profile['rate'] ?? 0),
            'slabs' => is_array($profile['slabs'] ?? null) ? $profile['slabs'] : (is_array($profile['gst_slabs'] ?? null) ? $profile['gst_slabs'] : []),
        ];
    }

    $settings = documents_get_quote_defaults_settings();
    $rateChart = is_array($settings['rate_chart'] ?? null) ? $settings['rate_chart'] : [];

    return [
        'customers' => $customers,
        'components' => $components,
        'kits' => $kits,
        'variants' => $variants,
        'tax_profiles' => $taxProfiles,
        'rate_chart' => $rateChart,
    ];
}

function standalone_invoice_quote_parity_master_item(string $type, string $kitId, string $componentId, string $variantId): ?array
{
    $settings = documents_get_quote_defaults_settings();
    $defaultHsn = safe_text((string)($settings['defaults']['hsn_solar'] ?? '8541')) ?: '8541';

    if ($type === 'kit') {
        $kit = $kitId !== '' ? documents_inventory_get_kit($kitId) : null;
        if (!is_array($kit)) return null;
        return [
            'type' => 'kit',
            'kit_id' => $kitId,
            'component_id' => '',
            'variant_id' => '',
            'name' => safe_text((string)($kit['name'] ?? 'Kit')),
            'description' => safe_multiline_text((string)($kit['description'] ?? '')),
            'hsn' => safe_text((string)($kit['hsn'] ?? '')) ?: $defaultHsn,
            'unit' => safe_text((string)($kit['default_unit'] ?? '')) ?: 'set',
            'tax_profile_id' => safe_text((string)($kit['tax_profile_id'] ?? '')),
            'variant_snapshot' => [],
        ];
    }

    $component = $componentId !== '' ? documents_inventory_get_component($componentId) : null;
    if (!is_array($component)) return null;
    $variant = $variantId !== '' ? documents_inventory_get_component_variant($variantId) : null;
    if (is_array($variant) && (string)($variant['component_id'] ?? '') !== $componentId) {
        $variant = null;
    }
    $name = safe_text((string)($component['name'] ?? 'Component'));
    $variantName = is_array($variant) ? safe_text((string)($variant['display_name'] ?? $variant['name'] ?? '')) : '';
    if ($variantName !== '') $name .= ' (' . $variantName . ')';
    return [
        'type' => 'component',
        'kit_id' => '',
        'component_id' => $componentId,
        'variant_id' => is_array($variant) ? $variantId : '',
        'name' => $name,
        'description' => safe_multiline_text((string)($component['description'] ?? $component['notes'] ?? '')),
        'hsn' => safe_text((string)($component['hsn'] ?? '')) ?: $defaultHsn,
        'unit' => safe_text((string)($component['default_unit'] ?? '')) ?: 'pcs',
        'tax_profile_id' => safe_text((string)($component['tax_profile_id'] ?? '')),
        'variant_snapshot' => is_array($variant) ? [
            'id' => (string)($variant['id'] ?? ''),
            'display_name' => (string)($variant['display_name'] ?? $variant['name'] ?? ''),
            'description' => (string)($variant['description'] ?? ''),
        ] : [],
    ];
}

function standalone_invoice_quote_parity_post_array(array $post, string $key): array
{
    $value = $post[$key] ?? [];
    return is_array($value) ? array_values($value) : [];
}

function standalone_invoice_quote_parity_build_items(array $post): array
{
    $types = standalone_invoice_quote_parity_post_array($post, 'quote_item_type');
    $kits = standalone_invoice_quote_parity_post_array($post, 'quote_item_kit_id');
    $components = standalone_invoice_quote_parity_post_array($post, 'quote_item_component_id');
    $variants = standalone_invoice_quote_parity_post_array($post, 'quote_item_variant_id');
    $qtys = standalone_invoice_quote_parity_post_array($post, 'quote_item_qty');
    $units = standalone_invoice_quote_parity_post_array($post, 'quote_item_unit');
    $autoDescriptions = standalone_invoice_quote_parity_post_array($post, 'quote_item_auto_description');
    $customDescriptions = standalone_invoice_quote_parity_post_array($post, 'quote_item_custom_description');
    $descriptionModes = standalone_invoice_quote_parity_post_array($post, 'quote_item_description_mode');
    $unitPrices = standalone_invoice_quote_parity_post_array($post, 'invoice_item_unit_price_incl_gst');
    $gstRates = standalone_invoice_quote_parity_post_array($post, 'invoice_item_gst_rate');

    $count = max(count($types), count($kits), count($components), count($qtys), count($unitPrices));
    $items = [];
    $taxItems = [];
    $basicPaise = 0;
    $gstPaise = 0;
    $grossPaise = 0;

    for ($i = 0; $i < $count; $i++) {
        $type = safe_text((string)($types[$i] ?? 'component')) === 'kit' ? 'kit' : 'component';
        $master = standalone_invoice_quote_parity_master_item(
            $type,
            safe_text((string)($kits[$i] ?? '')),
            safe_text((string)($components[$i] ?? '')),
            safe_text((string)($variants[$i] ?? ''))
        );
        if (!is_array($master)) continue;

        $qty = max(0.0, (float)($qtys[$i] ?? 0));
        if ($qty <= 0) continue;
        $unitPricePaise = max(0, documents_invoice_money_to_paise((float)($unitPrices[$i] ?? 0)));
        $lineGrossPaise = (int)round($unitPricePaise * $qty);
        $gstRate = max(0.0, (float)($gstRates[$i] ?? 0));
        $lineTaxablePaise = $gstRate > 0 ? (int)round($lineGrossPaise / (1 + ($gstRate / 100))) : $lineGrossPaise;
        $lineGstPaise = $lineGrossPaise - $lineTaxablePaise;

        $auto = safe_multiline_text((string)($autoDescriptions[$i] ?? ''));
        $custom = safe_multiline_text((string)($customDescriptions[$i] ?? ''));
        $mode = safe_text((string)($descriptionModes[$i] ?? ($custom !== '' ? 'manual' : 'auto')));
        $description = $custom !== '' ? $custom : ($auto !== '' ? $auto : (string)$master['description']);
        $unit = safe_text((string)($units[$i] ?? '')) ?: (string)$master['unit'];

        $item = [
            'type' => (string)$master['type'],
            'kit_id' => (string)$master['kit_id'],
            'component_id' => (string)$master['component_id'],
            'variant_id' => (string)$master['variant_id'],
            'variant_snapshot' => $master['variant_snapshot'],
            'name' => (string)$master['name'],
            'name_snapshot' => (string)$master['name'],
            'description' => $description,
            'description_snapshot' => (string)$master['description'],
            'master_description_snapshot' => (string)$master['description'],
            'auto_description' => $auto,
            'custom_description' => $custom,
            'description_mode' => $mode,
            'hsn' => (string)$master['hsn'],
            'hsn_snapshot' => (string)$master['hsn'],
            'tax_profile_id' => (string)$master['tax_profile_id'],
            'quantity' => $qty,
            'qty' => $qty,
            'unit' => $unit,
            'unit_price_incl_gst' => documents_invoice_paise_to_money($unitPricePaise),
            'gross_incl_gst' => documents_invoice_paise_to_money($lineGrossPaise),
            'slabs' => [['share_pct' => 100, 'rate_pct' => $gstRate]],
        ];
        $items[] = $item;
        $taxItems[] = array_merge($item, [
            'taxable_value' => documents_invoice_paise_to_money($lineTaxablePaise),
            'gst_amount' => documents_invoice_paise_to_money($lineGstPaise),
        ]);
        $basicPaise += $lineTaxablePaise;
        $gstPaise += $lineGstPaise;
        $grossPaise += $lineGrossPaise;
    }

    if ($items === []) {
        return ['ok' => false, 'error' => 'Add at least one kit or component from Items Master with quantity and invoice value.', 'items' => []];
    }

    return [
        'ok' => true,
        'error' => '',
        'items' => $items,
        'tax_breakdown' => [
            'basic_total' => documents_invoice_paise_to_money($basicPaise),
            'gst_total' => documents_invoice_paise_to_money($gstPaise),
            'gross_incl_gst' => documents_invoice_paise_to_money($grossPaise),
            'items' => $taxItems,
            'rounding_rule' => 'Standalone invoice lines are calculated in paise from Items Master selection, quantity, GST-inclusive unit value, and GST rate.',
        ],
        'gross' => documents_invoice_paise_to_money($grossPaise),
    ];
}

function standalone_invoice_quote_parity_customer_fields(array $snapshot, array $input): array
{
    $first = static function (array $values): string {
        foreach ($values as $value) {
            $text = trim((string)$value);
            if ($text !== '') return $text;
        }
        return '';
    };
    $rows = [
        ['label' => 'Name', 'value' => $first([$snapshot['name'] ?? '', $input['customer_name'] ?? ''])],
        ['label' => 'Mobile', 'value' => $first([$snapshot['mobile'] ?? '', $input['customer_mobile'] ?? ''])],
        ['label' => 'Site Address', 'value' => $first([$input['site_address'] ?? '', $snapshot['address'] ?? ''])],
        ['label' => 'District', 'value' => $first([$input['district'] ?? '', $snapshot['district'] ?? ''])],
        ['label' => 'City', 'value' => $first([$input['city'] ?? '', $snapshot['city'] ?? ''])],
        ['label' => 'State', 'value' => $first([$input['state'] ?? '', $snapshot['state'] ?? ''])],
        ['label' => 'PIN', 'value' => $first([$input['pin'] ?? '', $snapshot['pin_code'] ?? ''])],
        ['label' => 'Billing Address', 'value' => $first([$input['billing_address'] ?? ''])],
        ['label' => 'Place of Supply State', 'value' => $first([$input['place_of_supply_state'] ?? ''])],
        ['label' => 'Consumer Account No. (JBVNL)', 'value' => $first([$input['consumer_account_no'] ?? '', $snapshot['consumer_account_no'] ?? '', $snapshot['jbvnl_account_number'] ?? ''])],
        ['label' => 'Meter Number', 'value' => $first([$input['meter_number'] ?? '', $snapshot['meter_number'] ?? ''])],
        ['label' => 'Meter Serial Number', 'value' => $first([$input['meter_serial_number'] ?? '', $snapshot['meter_serial_number'] ?? ''])],
        ['label' => 'Application ID', 'value' => $first([$input['application_id'] ?? '', $snapshot['application_id'] ?? ''])],
        ['label' => 'Application Submitted Date', 'value' => $first([$input['application_submitted_date'] ?? '', $snapshot['application_submitted_date'] ?? ''])],
        ['label' => 'Sanction Load', 'value' => $first([$input['sanction_load_kwp'] ?? '', $snapshot['sanction_load_kwp'] ?? ''])],
        ['label' => 'Installed PV Capacity', 'value' => $first([$input['installed_pv_module_capacity_kwp'] ?? '', $snapshot['installed_pv_module_capacity_kwp'] ?? ''])],
        ['label' => 'Circle', 'value' => $first([$input['circle_name'] ?? '', $snapshot['circle_name'] ?? ''])],
        ['label' => 'Division', 'value' => $first([$input['division_name'] ?? '', $snapshot['division_name'] ?? ''])],
        ['label' => 'Sub Division', 'value' => $first([$input['sub_division_name'] ?? '', $snapshot['sub_division_name'] ?? ''])],
    ];
    return array_values(array_filter($rows, static fn(array $row): bool => trim((string)($row['value'] ?? '')) !== ''));
}

function standalone_invoice_quote_parity_apply_post(array $invoice, array $post, ?CustomerFsStore $store = null): array
{
    if (!documents_invoice_is_standalone($invoice)) {
        return ['ok' => false, 'error' => 'Quotation-parity editor only applies to standalone invoices.', 'invoice' => $invoice];
    }
    if (!documents_invoice_is_draft($invoice)) {
        return ['ok' => false, 'error' => 'Only Draft standalone invoices can be edited.', 'invoice' => $invoice];
    }

    $mobile = normalize_customer_mobile((string)($post['customer_mobile'] ?? ''));
    $name = safe_text((string)($post['customer_name'] ?? ''));
    if ($mobile === '' || $name === '') {
        return ['ok' => false, 'error' => 'Customer name and mobile are required.', 'invoice' => $invoice];
    }

    $match = documents_standalone_match_customer($invoice, $mobile, $store ?? new CustomerFsStore(), true);
    $invoice = (array)($match['invoice'] ?? $invoice);
    $snapshot = array_merge(documents_customer_snapshot_defaults(), is_array($invoice['customer_snapshot'] ?? null) ? $invoice['customer_snapshot'] : []);

    $snapshot['name'] = $name;
    $snapshot['mobile'] = $mobile;
    $snapshot['address'] = safe_multiline_text((string)($post['site_address'] ?? $post['customer_address'] ?? $snapshot['address'] ?? ''));
    $map = [
        'city' => 'city', 'district' => 'district', 'state' => 'state', 'pin' => 'pin_code',
        'meter_number' => 'meter_number', 'meter_serial_number' => 'meter_serial_number',
        'consumer_account_no' => 'consumer_account_no', 'application_id' => 'application_id',
        'application_submitted_date' => 'application_submitted_date', 'sanction_load_kwp' => 'sanction_load_kwp',
        'installed_pv_module_capacity_kwp' => 'installed_pv_module_capacity_kwp', 'circle_name' => 'circle_name',
        'division_name' => 'division_name', 'sub_division_name' => 'sub_division_name',
    ];
    foreach ($map as $postKey => $snapKey) {
        $value = safe_text((string)($post[$postKey] ?? ''));
        if ($value !== '') $snapshot[$snapKey] = $value;
    }
    if (safe_text((string)($post['consumer_account_no'] ?? '')) !== '') {
        $snapshot['jbvnl_account_number'] = safe_text((string)$post['consumer_account_no']);
    }
    $invoice['customer_snapshot'] = $snapshot;
    $invoice['customer_mobile'] = $mobile;

    $systemType = documents_quote_normalize_system_type((string)($post['system_type'] ?? 'ongrid'));
    $mainSolar = max(0.0, (float)($post['main_solar_kwp'] ?? 0));
    $nonDcr = max(0.0, (float)($post['complimentary_non_dcr_kwp'] ?? 0));
    $capacity = $mainSolar + $nonDcr;
    if ($capacity <= 0) {
        return ['ok' => false, 'error' => 'Main Solar Size / DCR must be entered. Total system capacity must be above zero.', 'invoice' => $invoice];
    }

    $selectedModel = safe_text((string)($post['selected_model_number'] ?? ''));
    $settings = documents_get_quote_defaults_settings();
    $chartType = $systemType === 'hybrid' ? 'hybrid' : 'on_grid';
    $selectedRateRow = [];
    if ($selectedModel !== '') {
        foreach ((array)($settings['rate_chart'][$chartType] ?? []) as $row) {
            if (is_array($row) && safe_text((string)($row['model_number'] ?? '')) === $selectedModel) {
                $selectedRateRow = $row;
                break;
            }
        }
    }

    $invoice['system_type'] = $systemType;
    $invoice['main_solar_kwp'] = (string)$mainSolar;
    $invoice['complimentary_non_dcr_kwp'] = (string)$nonDcr;
    $invoice['capacity_kwp'] = (string)$capacity;
    $invoice['system_capacity_kwp'] = $capacity;
    $invoice['rate_chart_snapshot'] = [
        'system_type' => $systemType,
        'model_number' => safe_text((string)($selectedRateRow['model_number'] ?? $selectedModel)),
        'variant' => safe_text((string)($selectedRateRow['variant'] ?? '')),
        'solar_size_kwp' => (float)($selectedRateRow['solar_size_kwp'] ?? $capacity),
        'dcr_size_kwp' => $mainSolar,
        'non_dcr_size_kwp' => $nonDcr,
        'total_system_size_kwp' => $capacity,
        'phase' => safe_text((string)($post['hybrid_phase'] ?? $selectedRateRow['phase'] ?? '')),
        'inverter_kva' => (float)($post['hybrid_inverter_kva'] ?? $selectedRateRow['inverter_kva'] ?? 0),
        'battery_count' => (int)($post['hybrid_battery_count'] ?? $selectedRateRow['battery_count'] ?? 0),
        'battery_code' => safe_text((string)($selectedRateRow['battery_code'] ?? '')),
        'inverter_code' => safe_text((string)($selectedRateRow['inverter_code'] ?? '')),
    ];

    $itemResult = standalone_invoice_quote_parity_build_items($post);
    if (empty($itemResult['ok'])) {
        return ['ok' => false, 'error' => (string)($itemResult['error'] ?? 'Unable to build invoice items.'), 'invoice' => $invoice];
    }
    $invoice['commercial_items'] = $itemResult['items'];
    $invoice['tax_breakdown'] = $itemResult['tax_breakdown'];
    $gross = (float)$itemResult['gross'];
    $invoice['input_total_gst_inclusive'] = $gross;
    $invoice['calc'] = array_merge(is_array($invoice['calc'] ?? null) ? $invoice['calc'] : [], [
        'gross_payable' => $gross,
        'grand_total' => $gross,
        'final_price_incl_gst' => $gross,
        'tax_breakdown' => $invoice['tax_breakdown'],
    ]);
    $invoice['pricing'] = [
        'quotation_total_incl_gst' => null,
        'final_invoice_total_incl_gst' => $gross,
        'adjustment_type' => DOCUMENTS_INVOICE_ADJUSTMENT_NONE,
        'adjustment_amount_incl_gst' => 0.0,
        'adjustment_percent' => 0.0,
        'adjustment_reason' => '',
        'currency' => 'INR',
    ];
    $invoice['quotation_reference_known'] = false;
    $invoice['pricing_mode'] = safe_text((string)($post['pricing_mode'] ?? $invoice['pricing_mode'] ?? 'solar_split_70_30')) ?: 'solar_split_70_30';

    $invoice['manual_reference'] = [
        'quotation_no' => safe_text((string)($post['manual_quotation_no'] ?? '')),
        'quotation_date' => safe_text((string)($post['manual_quotation_date'] ?? '')),
        'external_reference' => safe_text((string)($post['external_reference'] ?? '')),
        'quotation_amount' => ($post['manual_quotation_amount'] ?? '') === '' ? null : max(0.0, (float)$post['manual_quotation_amount']),
    ];

    $invoice['standalone_quote_inputs'] = [
        'party_type' => 'customer',
        'customer_name' => $name,
        'customer_mobile' => $mobile,
        'consumer_account_no' => safe_text((string)($post['consumer_account_no'] ?? '')),
        'meter_number' => safe_text((string)($post['meter_number'] ?? '')),
        'meter_serial_number' => safe_text((string)($post['meter_serial_number'] ?? '')),
        'district' => safe_text((string)($post['district'] ?? '')),
        'city' => safe_text((string)($post['city'] ?? '')),
        'state' => safe_text((string)($post['state'] ?? '')),
        'pin' => safe_text((string)($post['pin'] ?? '')),
        'billing_address' => safe_multiline_text((string)($post['billing_address'] ?? '')),
        'site_address' => safe_multiline_text((string)($post['site_address'] ?? $snapshot['address'] ?? '')),
        'circle_name' => safe_text((string)($post['circle_name'] ?? '')),
        'division_name' => safe_text((string)($post['division_name'] ?? '')),
        'sub_division_name' => safe_text((string)($post['sub_division_name'] ?? '')),
        'system_type' => $systemType,
        'selected_model_number' => $selectedModel,
        'main_solar_kwp' => $mainSolar,
        'complimentary_non_dcr_kwp' => $nonDcr,
        'capacity_kwp' => $capacity,
        'application_id' => safe_text((string)($post['application_id'] ?? '')),
        'application_submitted_date' => safe_text((string)($post['application_submitted_date'] ?? '')),
        'sanction_load_kwp' => safe_text((string)($post['sanction_load_kwp'] ?? '')),
        'installed_pv_module_capacity_kwp' => safe_text((string)($post['installed_pv_module_capacity_kwp'] ?? '')),
        'project_summary_line' => safe_text((string)($post['project_summary_line'] ?? '')),
        'special_requests_text' => safe_multiline_text((string)($post['special_requests_text'] ?? '')),
        'hybrid_inverter_kva' => safe_text((string)($post['hybrid_inverter_kva'] ?? '')),
        'hybrid_phase' => safe_text((string)($post['hybrid_phase'] ?? '')),
        'hybrid_battery_count' => safe_text((string)($post['hybrid_battery_count'] ?? '')),
        'pricing_mode' => $invoice['pricing_mode'],
        'tax_profile_id' => safe_text((string)($post['tax_profile_id'] ?? '')),
        'place_of_supply_state' => safe_text((string)($post['place_of_supply_state'] ?? '')),
    ];

    $invoice['quotation_snapshot'] = [
        'source_type' => 'standalone_invoice_quote_parity',
        'quote_id' => '',
        'quote_no' => (string)($invoice['manual_reference']['quotation_no'] ?? ''),
        'item_summary' => array_map(static function (array $item): array {
            return [
                'name' => (string)($item['name'] ?? ''),
                'description' => (string)($item['description'] ?? ''),
                'auto_description' => (string)($item['auto_description'] ?? ''),
                'custom_description' => (string)($item['custom_description'] ?? ''),
                'hsn' => (string)($item['hsn'] ?? ''),
                'qty' => (float)($item['qty'] ?? 0),
                'unit' => (string)($item['unit'] ?? ''),
            ];
        }, $invoice['commercial_items']),
        'customer_site_fields' => standalone_invoice_quote_parity_customer_fields($snapshot, $invoice['standalone_quote_inputs']),
        'special_requests_text' => (string)$invoice['standalone_quote_inputs']['special_requests_text'],
        'pricing_mode' => $invoice['pricing_mode'],
        'input_total_gst_inclusive' => $gross,
        'calc' => $invoice['calc'],
        'tax_breakdown' => $invoice['tax_breakdown'],
        'main_solar_kwp' => $mainSolar,
        'complimentary_non_dcr_kwp' => $nonDcr,
        'capacity_kwp' => $capacity,
        'system_type' => $systemType,
        'rate_chart_snapshot' => $invoice['rate_chart_snapshot'],
    ];

    $dateResult = documents_invoice_set_date($invoice, (string)($post['invoice_date'] ?? ''), standalone_invoice_quote_parity_actor());
    if (empty($dateResult['ok'])) {
        return ['ok' => false, 'error' => (string)($dateResult['error'] ?? 'Invalid invoice date.'), 'invoice' => $invoice];
    }
    $invoice = (array)$dateResult['invoice'];
    $invoice['invoice_no'] = safe_text((string)($post['invoice_no'] ?? $invoice['invoice_no'] ?? ''));
    $invoice['internal_notes'] = safe_multiline_text((string)($post['internal_notes'] ?? $invoice['internal_notes'] ?? ''));
    $invoice['updated_at'] = date('c');

    return ['ok' => true, 'error' => '', 'invoice' => $invoice, 'customer' => $match['customer'] ?? null];
}

function standalone_invoice_quote_parity_sync_sales_record(array $doc): void
{
    $invoiceId = (string)($doc['id'] ?? '');
    if ($invoiceId === '') return;
    $snap = array_merge(documents_customer_snapshot_defaults(), is_array($doc['customer_snapshot'] ?? null) ? $doc['customer_snapshot'] : []);
    $sales = documents_get_sales_document('invoice', $invoiceId) ?: documents_sales_document_defaults('invoice');
    $sales['id'] = $invoiceId;
    $sales['quotation_id'] = '';
    $sales['customer_mobile'] = (string)($doc['customer_mobile'] ?? $snap['mobile'] ?? '');
    $sales['customer_name'] = (string)($snap['name'] ?? '');
    $sales['invoice_no'] = (string)($doc['invoice_no'] ?? '');
    $sales['invoice_date'] = documents_invoice_authoritative_date($doc);
    $summary = documents_invoice_payment_summary($doc);
    $sales['amount'] = $summary['invoice_total'];
    $sales['status'] = documents_invoice_normalize_status((string)($doc['status'] ?? 'draft'));
    $sales['document_status'] = $sales['status'];
    $sales['payment_status'] = $summary['payment_status'];
    $sales['received_total'] = $summary['total_received'];
    $sales['outstanding'] = $summary['outstanding'];
    $sales['overpayment'] = $summary['overpayment'];
    $sales['revision_no'] = (int)($doc['revision_no'] ?? 0);
    $sales['finalized_at'] = (string)($doc['finalized_at'] ?? '');
    $sales['cancelled_flag'] = documents_invoice_is_cancelled($doc);
    $sales['archived_flag'] = !empty($doc['archived_flag']);
    $sales['created_at'] = (string)($sales['created_at'] ?: ($doc['created_at'] ?? date('c')));
    $sales['updated_at'] = (string)($doc['updated_at'] ?? date('c'));
    documents_save_sales_document('invoice', $sales);
}

function standalone_invoice_quote_parity_handle_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $action = safe_text((string)($_POST['action'] ?? ''));
    if (!in_array($action, ['save_invoice_draft', 'finalize_invoice'], true)) return;
    $invoiceId = safe_text((string)($_POST['invoice_id'] ?? ''));
    if ($invoiceId === '') return;
    $invoice = documents_get_invoice($invoiceId);
    if (!is_array($invoice) || !documents_invoice_is_standalone($invoice)) return;

    require_admin();
    if (!verify_csrf_token(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        standalone_invoice_quote_parity_redirect($invoiceId, 'error', 'Security token expired. Please try again.');
    }

    $result = standalone_invoice_quote_parity_apply_post($invoice, $_POST);
    if (empty($result['ok'])) {
        standalone_invoice_quote_parity_redirect($invoiceId, 'error', (string)($result['error'] ?? 'Unable to save standalone invoice.'));
    }
    $invoice = (array)$result['invoice'];
    $saved = documents_save_invoice($invoice);
    if (empty($saved['ok'])) {
        standalone_invoice_quote_parity_redirect($invoiceId, 'error', 'Unable to save standalone invoice draft.');
    }

    if ($action === 'finalize_invoice') {
        $finalized = documents_invoice_finalize($invoice, standalone_invoice_quote_parity_actor());
        if (empty($finalized['ok'])) {
            standalone_invoice_quote_parity_redirect($invoiceId, 'error', implode(' ', (array)($finalized['errors'] ?? ['Unable to finalize invoice.'])));
        }
        $invoice = (array)$finalized['invoice'];
        $saved = documents_save_invoice($invoice);
        if (empty($saved['ok'])) {
            standalone_invoice_quote_parity_redirect($invoiceId, 'error', 'Unable to save finalized standalone invoice.');
        }
        standalone_invoice_quote_parity_sync_sales_record($invoice);
        standalone_invoice_quote_parity_redirect($invoiceId, 'success', 'Standalone invoice finalized / issued. Payment status: ' . documents_invoice_payment_status_label(documents_invoice_payment_status($invoice)) . '.');
    }

    standalone_invoice_quote_parity_sync_sales_record($invoice);
    standalone_invoice_quote_parity_redirect($invoiceId, 'success', 'Standalone invoice draft saved with quotation-style customer, system, DCR/Non-DCR, and Items Master details.');
}

function standalone_invoice_quote_parity_html_payload(array $invoice): array
{
    $payload = standalone_invoice_quote_parity_catalog_payload();
    $payload['editable'] = documents_invoice_is_draft($invoice);
    $payload['invoice'] = [
        'id' => (string)($invoice['id'] ?? ''),
        'system_type' => (string)($invoice['system_type'] ?? $invoice['standalone_quote_inputs']['system_type'] ?? 'ongrid'),
        'main_solar_kwp' => (string)($invoice['main_solar_kwp'] ?? $invoice['standalone_quote_inputs']['main_solar_kwp'] ?? ''),
        'complimentary_non_dcr_kwp' => (string)($invoice['complimentary_non_dcr_kwp'] ?? $invoice['standalone_quote_inputs']['complimentary_non_dcr_kwp'] ?? ''),
        'selected_model_number' => (string)($invoice['rate_chart_snapshot']['model_number'] ?? $invoice['standalone_quote_inputs']['selected_model_number'] ?? ''),
        'standalone_quote_inputs' => is_array($invoice['standalone_quote_inputs'] ?? null) ? $invoice['standalone_quote_inputs'] : [],
        'commercial_items' => is_array($invoice['commercial_items'] ?? null) ? $invoice['commercial_items'] : [],
    ];
    return $payload;
}

function standalone_invoice_quote_parity_rewrite_html(string $html): string
{
    $invoiceId = safe_text((string)($_GET['id'] ?? ''));
    if ($invoiceId === '') return $html;
    $invoice = documents_get_invoice($invoiceId);
    if (!is_array($invoice) || !documents_invoice_is_standalone($invoice)) return $html;

    $payload = standalone_invoice_quote_parity_html_payload($invoice);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($json === false) return $html;

    $asset = <<<'HTML'
<style>
.si-lookup{position:relative;margin:0 0 14px}.si-lookup-results{position:absolute;z-index:40;left:0;right:0;top:100%;background:#fff;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 16px 40px rgba(15,23,42,.16);max-height:280px;overflow:auto;display:none}.si-lookup-results.open{display:block}.si-customer-option{display:block;width:100%;text-align:left;border:0;border-bottom:1px solid #eef2f7;background:#fff;padding:10px 12px;cursor:pointer}.si-customer-option:hover{background:#f0fdfa}.si-customer-option strong,.si-customer-option span{display:block}.si-customer-option span{font-size:12px;color:#64748b;margin-top:2px}.si-builder-table input,.si-builder-table select,.si-builder-table textarea{min-width:110px}.si-builder-table .wide{min-width:220px}.si-master-note{font-size:11px;color:#64748b;max-width:260px;white-space:pre-wrap}.si-system-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.si-total-box{padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc;font-weight:800}.si-link-state{margin-top:8px;font-size:12px;color:#475569}.si-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.si-item-summary{margin-top:10px;font-weight:800}.si-hidden-standalone-total{display:none!important}
</style>
<script>
(function(){
const data=__PAYLOAD__;
const editable=!!data.editable;
const esc=(s)=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const norm=(s)=>String(s??'').trim().toLowerCase().replace(/\s+/g,' ');
const byName=(name)=>document.querySelector('[name="'+name+'"]');
const editForm=document.querySelector('form input[name="invoice_id"][value="'+CSS.escape(String(data.invoice.id))+'"]')?.closest('form');
if(!editForm)return;
const cards=[...editForm.querySelectorAll('.form-section-card')];
const card=(title)=>cards.find(c=>c.querySelector('h3')?.textContent.trim()===title);
const q=data.invoice.standalone_quote_inputs||{};

const ensureField=(container,label,name,value,type='text',full=false)=>{let input=editForm.querySelector('[name="'+name+'"]');if(input)return input;const wrap=document.createElement('div');if(full)wrap.className='full-span';wrap.innerHTML='<label>'+esc(label)+'</label>'+(type==='textarea'?'<textarea name="'+esc(name)+'" rows="2">'+esc(value||'')+'</textarea>':'<input type="'+esc(type)+'" name="'+esc(name)+'" value="'+esc(value||'')+'">');container.appendChild(wrap);input=wrap.querySelector('[name="'+name+'"]');if(!editable)input.setAttribute('readonly','readonly');return input;};

const customerCard=card('Customer snapshot');
if(customerCard){
 const grid=customerCard.querySelector('.form-grid')||customerCard;
 const lookup=document.createElement('div');lookup.className='si-lookup full-span';lookup.innerHTML='<label>Find existing customer by name or mobile</label><input type="search" id="siCustomerSearch" autocomplete="off" placeholder="Start typing customer name or mobile number"><div class="si-lookup-results" id="siCustomerResults"></div><div class="si-link-state" id="siCustomerState">Selecting a customer fills all available Customer User fields; invoice edits do not overwrite the Customer User.</div>';
 grid.prepend(lookup);
 const extra=[['Consumer Account No. (JBVNL)','consumer_account_no',q.consumer_account_no],['Meter Number','meter_number',q.meter_number],['Meter Serial Number','meter_serial_number',q.meter_serial_number],['District','district',q.district],['City','city',q.city],['State','state',q.state],['PIN','pin',q.pin],['Billing Address','billing_address',q.billing_address],['Site Address','site_address',q.site_address||byName('customer_address')?.value],['Circle','circle_name',q.circle_name],['Division','division_name',q.division_name],['Sub Division','sub_division_name',q.sub_division_name],['Application ID','application_id',q.application_id],['Application Submitted Date','application_submitted_date',q.application_submitted_date,'date'],['Sanction Load (kWp)','sanction_load_kwp',q.sanction_load_kwp],['Installed PV Module Capacity (kWp)','installed_pv_module_capacity_kwp',q.installed_pv_module_capacity_kwp]];
 extra.forEach(r=>ensureField(grid,r[0],r[1],r[2],r[3]||'text',r[1]==='billing_address'||r[1]==='site_address'));
 const search=lookup.querySelector('#siCustomerSearch'),results=lookup.querySelector('#siCustomerResults'),state=lookup.querySelector('#siCustomerState');
 const render=()=>{const term=norm(search.value);if(!term){results.classList.remove('open');results.innerHTML='';return;}const matches=(data.customers||[]).filter(c=>norm(c.name).includes(term)||String(c.mobile||'').replace(/\D/g,'').includes(term.replace(/\D/g,''))||norm(c.serial_number).includes(term)||norm(c.city).includes(term)).slice(0,15);results.innerHTML=matches.map((c,i)=>'<button type="button" class="si-customer-option" data-i="'+i+'"><strong>'+esc(c.name||'Unnamed customer')+'</strong><span>'+esc(c.mobile||'')+(c.serial_number?' · '+esc(c.serial_number):'')+(c.city?' · '+esc(c.city):'')+'</span></button>').join('')||'<div style="padding:10px 12px;color:#64748b">No matching Customer User</div>';results.classList.add('open');results.querySelectorAll('[data-i]').forEach((b)=>b.addEventListener('click',()=>{const c=matches[Number(b.dataset.i)];const set=(n,v)=>{const f=byName(n);if(f&&v!==undefined&&v!==null)f.value=String(v);};set('customer_name',c.name);set('customer_mobile',c.mobile);set('customer_address',c.address);set('site_address',c.address);set('consumer_account_no',c.jbvnl_account_number);set('meter_number',c.meter_number);set('meter_serial_number',c.meter_serial_number);set('district',c.district);set('city',c.city);set('state',c.state);set('pin',c.pin_code);set('circle_name',c.circle_name);set('division_name',c.division_name);set('sub_division_name',c.sub_division_name);set('application_id',c.application_id);set('application_submitted_date',c.application_submitted_date);set('sanction_load_kwp',c.sanction_load_kwp);set('installed_pv_module_capacity_kwp',c.installed_pv_module_capacity_kwp);state.textContent='Selected Customer User: '+(c.name||'')+' · '+(c.mobile||'')+(c.serial_number?' · '+c.serial_number:'');search.value=(c.name||'')+' · '+(c.mobile||'');results.classList.remove('open');}));};
 search.addEventListener('input',render);search.addEventListener('focus',render);document.addEventListener('click',e=>{if(!lookup.contains(e.target))results.classList.remove('open');});
}

const invoiceCard=card('Invoice details');
if(invoiceCard){
 const grid=invoiceCard.querySelector('.form-grid')||invoiceCard;
 const system=document.createElement('div');system.className='full-span';system.innerHTML='<h3 style="margin-top:8px">System details (same concept as quotation creation)</h3><div class="si-system-grid"><div><label>System Type</label><select name="system_type"><option value="ongrid">Ongrid</option><option value="hybrid">Hybrid</option><option value="offgrid">Offgrid</option><option value="product">Product</option></select></div><div><label>Rate Chart Model</label><select id="siModelSelect"><option value="">-- select model --</option></select><input type="hidden" name="selected_model_number" value="'+esc(data.invoice.selected_model_number||'')+'"><div class="muted-helper" id="siModelStatus">Models use the same quotation rate chart. Selection can fill DCR/Non-DCR and matching kit.</div></div><div><label>Main Solar Size / DCR (kWp)</label><input type="number" min="0" step="0.01" name="main_solar_kwp" value="'+esc(data.invoice.main_solar_kwp||'')+'" required></div><div><label>Complimentary Non-DCR Solar Size (kWp)</label><input type="number" min="0" step="0.01" name="complimentary_non_dcr_kwp" value="'+esc(data.invoice.complimentary_non_dcr_kwp||'')+'"></div><div><label>Total System Capacity (kWp)</label><div class="si-total-box" id="siTotalCapacity">0</div></div><div><label>Hybrid Inverter (kVA)</label><input type="number" min="0" step="0.01" name="hybrid_inverter_kva" value="'+esc(q.hybrid_inverter_kva||'')+'"></div><div><label>Hybrid Phase</label><select name="hybrid_phase"><option value="">--</option><option value="1">1 Phase</option><option value="3">3 Phase</option></select></div><div><label>Hybrid Battery Count</label><input type="number" min="0" step="1" name="hybrid_battery_count" value="'+esc(q.hybrid_battery_count||'')+'"></div><div><label>Tax Profile</label><select name="tax_profile_id"><option value="">-- default / item profile --</option>'+((data.tax_profiles||[]).map(p=>'<option value="'+esc(p.id)+'">'+esc(p.name||p.id)+'</option>').join(''))+'</select></div><div><label>Place of Supply State</label><input name="place_of_supply_state" value="'+esc(q.place_of_supply_state||'')+'"></div><div class="full-span"><label>Project Summary Line</label><input name="project_summary_line" value="'+esc(q.project_summary_line||'')+'"></div><div class="full-span"><label>Special Requests From Consumer (Inclusive in the rate)</label><textarea name="special_requests_text" rows="2">'+esc(q.special_requests_text||'')+'</textarea></div></div>';
 grid.appendChild(system);
 const sys=byName('system_type');sys.value=data.invoice.system_type||'ongrid';const ph=byName('hybrid_phase');if(ph)ph.value=q.hybrid_phase||'';const tax=byName('tax_profile_id');if(tax)tax.value=q.tax_profile_id||'';
 const cap=byName('capacity_kwp');if(cap)cap.closest('div').classList.add('si-hidden-standalone-total');const total=()=>{const v=Math.max(0,Number(byName('main_solar_kwp')?.value||0))+Math.max(0,Number(byName('complimentary_non_dcr_kwp')?.value||0));document.getElementById('siTotalCapacity').textContent=(Math.round(v*100)/100)+' kWp';if(cap)cap.value=String(Math.round(v*100)/100);};byName('main_solar_kwp')?.addEventListener('input',total);byName('complimentary_non_dcr_kwp')?.addEventListener('input',total);total();
 const model=document.getElementById('siModelSelect'),hidden=byName('selected_model_number'),status=document.getElementById('siModelStatus');
 const chartType=()=>String(sys.value||'').toLowerCase()==='hybrid'?'hybrid':'on_grid';const rows=()=>Array.isArray(data.rate_chart?.[chartType()])?data.rate_chart[chartType()]:[];
 const variant=(r)=>{const suffix=String(r?.model_number||'').trim().split('-').pop().toUpperCase();if(['DN','D'].includes(suffix))return suffix;const x=String(r?.variant||'').trim().toUpperCase();return ['DN','D'].includes(x)?x:'';};
 const split=(r)=>{const t=Math.max(0,Number(r?.solar_size_kwp||0));const d=variant(r)==='DN'?Math.min(3,t):t;return{dcr:d,non:Math.max(0,t-d)}};
 const modelKit=(r)=>{if(chartType()==='on_grid')return'Ongrid Solar Power Generation System';const code=(String(r?.model_number||'')+' '+String(r?.inverter_code||'')+' '+String(r?.variant||'')).toUpperCase();if(/(^|[-_\s])TB($|[-_\s])/.test(code))return'Hybrid Solar Power Generation System TBased';if(/(^|[-_\s])TL($|[-_\s])/.test(code))return'Hybrid Solar Power Generation System TLess';return'';};
 const fillModels=()=>{const current=hidden?.value||'';model.innerHTML='<option value="">-- select model --</option>'+rows().map(r=>'<option value="'+esc(r.model_number||'')+'">'+esc(r.model_number||'')+' · '+esc(r.solar_size_kwp||'')+' kWp'+(r.self_funded_price?' · ₹'+Number(r.self_funded_price).toLocaleString('en-IN'):'')+'</option>').join('');model.value=current;};sys.addEventListener('change',()=>{hidden.value='';fillModels();});fillModels();
 model.addEventListener('change',()=>{hidden.value=model.value;const r=rows().find(x=>String(x.model_number||'')===model.value);if(!r)return;const s=split(r);byName('main_solar_kwp').value=String(s.dcr);byName('complimentary_non_dcr_kwp').value=String(s.non);if(byName('hybrid_inverter_kva'))byName('hybrid_inverter_kva').value=r.inverter_kva||'';if(ph)ph.value=String(r.phase||'').replace(/\s*Phase$/i,'');if(byName('hybrid_battery_count'))byName('hybrid_battery_count').value=r.battery_count||'';total();const kitName=modelKit(r);if(kitName&&window.siAddKitByName)window.siAddKitByName(kitName,Number(r.self_funded_price||0));status.textContent='Selected '+String(r.model_number||'')+'. DCR/Non-DCR and matching quotation kit were applied; fields remain editable.';});
}

const itemCard=card('Invoice items');
if(itemCard){
 const profiles=new Map((data.tax_profiles||[]).map(p=>[String(p.id||''),p]));const components=new Map((data.components||[]).map(c=>[String(c.id||''),c]));const kits=new Map((data.kits||[]).map(k=>[String(k.id||''),k]));const variantsBy={};(data.variants||[]).forEach(v=>(variantsBy[String(v.component_id||'')]??=[]).push(v));
 const profileRate=(id)=>{const p=profiles.get(String(id||''));if(!p)return 18;let r=Number(p.rate_pct||0);if(r>0)return r;const slabs=Array.isArray(p.slabs)?p.slabs:[];if(slabs.length){const cand=Number(slabs[0]?.rate_pct??slabs[0]?.rate??0);if(cand>=0)return cand;}return 18;};
 itemCard.innerHTML='<h3>Item Builder (same Items Master as quotations)</h3><p class="muted-helper">Kits, components, variants, HSN, units and master descriptions come only from the same Items Master used in quotation creation. Invoice value/GST are invoice-specific editable fields.</p><div class="responsive-table"><table class="si-builder-table" id="siItems"><thead><tr><th>Type</th><th>Kit</th><th>Component</th><th>Variant</th><th>Qty</th><th>Unit</th><th>Master / invoice description</th><th>Unit value incl GST</th><th>GST %</th><th></th></tr></thead><tbody></tbody></table></div><div class="si-actions"><button type="button" class="btn secondary" id="siAddItem">Add Structured Item</button><span class="si-item-summary" id="siItemSummary"></span></div>';
 const tbody=itemCard.querySelector('tbody');
 const options=(rows,placeholder)=>'<option value="">'+esc(placeholder)+'</option>'+rows.map(r=>'<option value="'+esc(r.id)+'">'+esc(r.name||r.display_name||r.id)+'</option>').join('');
 const calc=()=>{let total=0;tbody.querySelectorAll('tr').forEach(tr=>{const q=Math.max(0,Number(tr.querySelector('[name="quote_item_qty[]"]')?.value||0)),u=Math.max(0,Number(tr.querySelector('[name="invoice_item_unit_price_incl_gst[]"]')?.value||0));total+=q*u;});itemCard.querySelector('#siItemSummary').textContent='Invoice total from items: ₹'+total.toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});const f=byName('final_invoice_total_incl_gst');if(f){f.value=total.toFixed(2);f.readOnly=true;}};
 const sync=(tr)=>{const type=tr.querySelector('[name="quote_item_type[]"]').value,kitSel=tr.querySelector('[name="quote_item_kit_id[]"]'),cmpSel=tr.querySelector('[name="quote_item_component_id[]"]'),varSel=tr.querySelector('[name="quote_item_variant_id[]"]'),unit=tr.querySelector('[name="quote_item_unit[]"]'),gst=tr.querySelector('[name="invoice_item_gst_rate[]"]'),master=tr.querySelector('.si-master-note');kitSel.disabled=type!=='kit';cmpSel.disabled=type==='kit';varSel.disabled=type==='kit';let obj=null;if(type==='kit'){obj=kits.get(kitSel.value)||null;varSel.innerHTML='<option value="">-- none --</option>';}else{obj=components.get(cmpSel.value)||null;const selected=varSel.dataset.selected||varSel.value;varSel.innerHTML=options(variantsBy[cmpSel.value]||[],'-- none --');varSel.value=selected;varSel.dataset.selected='';}if(obj){unit.value=unit.value||obj.default_unit|| (type==='kit'?'set':'pcs');if(!gst.dataset.touched)gst.value=String(profileRate(obj.tax_profile_id));master.textContent=(obj.name||'')+(obj.description?' — '+obj.description:'')+(obj.hsn?' · HSN '+obj.hsn:'');}else master.textContent='Select from Items Master.';calc();};
 const add=(item={})=>{const tr=document.createElement('tr');const type=String(item.type|| (item.kit_id?'kit':'component'));tr.innerHTML='<td><select name="quote_item_type[]"><option value="kit">Kit</option><option value="component">Component</option></select></td><td><select name="quote_item_kit_id[]">'+options(data.kits||[],'-- select kit --')+'</select></td><td><select name="quote_item_component_id[]">'+options(data.components||[],'-- select component --')+'</select></td><td><select name="quote_item_variant_id[]"><option value="">-- none --</option></select></td><td><input type="number" min="0" step="0.01" name="quote_item_qty[]" value="'+esc(item.qty??item.quantity??1)+'"></td><td><input name="quote_item_unit[]" value="'+esc(item.unit||'')+'"></td><td><div class="si-master-note"></div><input type="hidden" name="quote_item_description_mode[]" value="'+esc(item.description_mode||((item.custom_description||'')?'manual':'auto'))+'"><textarea name="quote_item_auto_description[]" rows="2" readonly placeholder="Automatic configuration">'+esc(item.auto_description||'')+'</textarea><textarea name="quote_item_custom_description[]" rows="2" placeholder="Optional invoice-specific note">'+esc(item.custom_description||'')+'</textarea></td><td><input type="number" min="0" step="0.01" name="invoice_item_unit_price_incl_gst[]" value="'+esc(item.unit_price_incl_gst||'')+'"></td><td><input type="number" min="0" step="0.01" name="invoice_item_gst_rate[]" value="'+esc(item.slabs?.[0]?.rate_pct??18)+'"></td><td><button type="button" class="btn secondary si-remove">Remove</button></td>';tbody.appendChild(tr);tr.querySelector('[name="quote_item_type[]"]').value=type;tr.querySelector('[name="quote_item_kit_id[]"]').value=item.kit_id||'';tr.querySelector('[name="quote_item_component_id[]"]').value=item.component_id||'';tr.querySelector('[name="quote_item_variant_id[]"]').dataset.selected=item.variant_id||'';tr.querySelectorAll('select').forEach(e=>e.addEventListener('change',()=>sync(tr)));tr.querySelectorAll('input,textarea').forEach(e=>e.addEventListener('input',()=>{if(e.name==='invoice_item_gst_rate[]')e.dataset.touched='1';calc();}));tr.querySelector('.si-remove').addEventListener('click',()=>{tr.remove();calc();});sync(tr);return tr;};
 (data.invoice.commercial_items||[]).forEach(add);if(!tbody.children.length)add({});itemCard.querySelector('#siAddItem').addEventListener('click',()=>add({}));
 window.siAddKitByName=(name,price)=>{const target=(data.kits||[]).find(k=>norm(k.name)===norm(name));if(!target)return;let tr=[...tbody.children].find(r=>r.querySelector('[name="quote_item_type[]"]')?.value==='kit'&&r.querySelector('[name="quote_item_kit_id[]"]')?.value===target.id);if(!tr){tr=add({type:'kit',kit_id:target.id,qty:1,unit:'set',unit_price_incl_gst:price||''});}else if(price&&Number(tr.querySelector('[name="invoice_item_unit_price_incl_gst[]"]')?.value||0)<=0){tr.querySelector('[name="invoice_item_unit_price_incl_gst[]"]').value=String(price);calc();}};
 calc();
}

if(!editable){editForm.querySelectorAll('input,select,textarea,button').forEach(el=>{if(el.type!=='hidden')el.disabled=true;});}
})();
</script>
HTML;
    $asset = str_replace('__PAYLOAD__', $json, $asset);
    return str_replace('</body>', $asset . '</body>', $html);
}

if (standalone_invoice_quote_parity_is_invoice_page()) {
    standalone_invoice_quote_parity_handle_post();
    ob_start('standalone_invoice_quote_parity_rewrite_html');
}
