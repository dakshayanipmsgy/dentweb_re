<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/solar_finance_reports.php';

function standalone_invoice_quote_builder_is_enabled(array $invoice): bool
{
    return documents_invoice_is_standalone($invoice)
        && (string)($invoice['standalone_builder']['mode'] ?? '') === 'quotation_style';
}

function standalone_invoice_quote_builder_catalog(): array
{
    $components = documents_inventory_components(false);
    $kits = documents_inventory_kits(false);
    $taxProfiles = documents_inventory_tax_profiles(false);
    $variants = documents_inventory_component_variants(false);
    return [
        'components' => is_array($components) ? array_values($components) : [],
        'kits' => is_array($kits) ? array_values($kits) : [],
        'tax_profiles' => is_array($taxProfiles) ? array_values($taxProfiles) : [],
        'variants' => is_array($variants) ? array_values($variants) : [],
    ];
}

function standalone_invoice_quote_builder_customers(?CustomerFsStore $store = null): array
{
    $store = $store ?? new CustomerFsStore();
    $rows = [];
    foreach ($store->listActiveCustomers() as $customer) {
        if (!is_array($customer)) continue;
        $mapped = documents_map_customer_record($customer);
        $rows[] = array_merge($mapped, [
            'serial_number' => safe_text((string)($customer['serial_number'] ?? '')),
            'customer_type' => safe_text((string)($customer['customer_type'] ?? '')),
        ]);
    }
    return $rows;
}

function standalone_invoice_quote_builder_parse_items(array $post, array $catalog, array $quoteDefaults): array
{
    $defaultHsn = safe_text((string)($quoteDefaults['defaults']['hsn_solar'] ?? '8541')) ?: '8541';
    $kitMap = [];
    foreach ((array)($catalog['kits'] ?? []) as $kit) {
        if (is_array($kit) && safe_text((string)($kit['id'] ?? '')) !== '') $kitMap[(string)$kit['id']] = $kit;
    }
    $componentMap = [];
    foreach ((array)($catalog['components'] ?? []) as $component) {
        if (is_array($component) && safe_text((string)($component['id'] ?? '')) !== '') $componentMap[(string)$component['id']] = $component;
    }
    $variantMap = [];
    foreach ((array)($catalog['variants'] ?? []) as $variant) {
        if (is_array($variant) && safe_text((string)($variant['id'] ?? '')) !== '') $variantMap[(string)$variant['id']] = $variant;
    }

    $types = is_array($post['quote_item_type'] ?? null) ? $post['quote_item_type'] : [];
    $kitIds = is_array($post['quote_item_kit_id'] ?? null) ? $post['quote_item_kit_id'] : [];
    $componentIds = is_array($post['quote_item_component_id'] ?? null) ? $post['quote_item_component_id'] : [];
    $variantIds = is_array($post['quote_item_variant_id'] ?? null) ? $post['quote_item_variant_id'] : [];
    $qtys = is_array($post['quote_item_qty'] ?? null) ? $post['quote_item_qty'] : [];
    $units = is_array($post['quote_item_unit'] ?? null) ? $post['quote_item_unit'] : [];
    $autoDescriptions = is_array($post['quote_item_auto_description'] ?? null) ? $post['quote_item_auto_description'] : [];
    $customDescriptions = is_array($post['quote_item_custom_description'] ?? null) ? $post['quote_item_custom_description'] : [];
    $descriptionModes = is_array($post['quote_item_description_mode'] ?? null) ? $post['quote_item_description_mode'] : [];
    if ($types === []) return ['ok' => false, 'error' => 'Add at least one kit/component from Items Master.'];

    $structured = [];
    $summary = [];
    foreach ($types as $i => $rawType) {
        $type = safe_text((string)$rawType);
        if (!in_array($type, ['kit', 'component'], true)) $type = 'component';
        $qty = max(0, (float)($qtys[$i] ?? 0));
        if ($qty <= 0) continue;
        $unit = safe_text((string)($units[$i] ?? ''));
        $mode = (string)($descriptionModes[$i] ?? '') === 'manual' ? 'manual' : 'auto';
        $auto = safe_multiline_text((string)($autoDescriptions[$i] ?? ''));
        $custom = safe_multiline_text((string)($customDescriptions[$i] ?? ''));

        if ($type === 'kit') {
            $kitId = safe_text((string)($kitIds[$i] ?? ''));
            $kit = $kitMap[$kitId] ?? null;
            if (!is_array($kit)) return ['ok' => false, 'error' => 'Invoice contains an invalid or archived kit selection.'];
            $name = safe_text((string)($kit['name'] ?? 'Kit')) ?: 'Kit';
            $description = safe_text((string)($kit['description'] ?? ''));
            $hsn = safe_text((string)($kit['hsn'] ?? '')) ?: $defaultHsn;
            $resolvedUnit = $unit !== '' ? $unit : 'set';
            $structured[] = [
                'type' => 'kit', 'kit_id' => $kitId, 'component_id' => '', 'qty' => $qty, 'unit' => $resolvedUnit,
                'variant_id' => '', 'variant_snapshot' => [], 'name_snapshot' => $name,
                'description_snapshot' => $description, 'master_description_snapshot' => $description,
                'auto_description' => $auto, 'custom_description' => $custom, 'description_mode' => $mode,
                'hsn_snapshot' => $hsn, 'meta' => [],
            ];
            $summary[] = ['name'=>$name,'description'=>$description,'hsn'=>$hsn,'qty'=>$qty,'unit'=>$resolvedUnit,'gst_slab'=>'5','basic_amount'=>0];
            continue;
        }

        $componentId = safe_text((string)($componentIds[$i] ?? ''));
        $component = $componentMap[$componentId] ?? null;
        if (!is_array($component)) return ['ok' => false, 'error' => 'Invoice contains an invalid or archived component selection.'];
        $variantId = safe_text((string)($variantIds[$i] ?? ''));
        $variant = null;
        if ($variantId !== '') {
            $variant = $variantMap[$variantId] ?? null;
            if (!is_array($variant) || (string)($variant['component_id'] ?? '') !== $componentId) {
                return ['ok' => false, 'error' => 'Invoice contains an invalid or archived component variant selection.'];
            }
        }
        $name = safe_text((string)($component['name'] ?? 'Component')) ?: 'Component';
        if (is_array($variant) && safe_text((string)($variant['display_name'] ?? '')) !== '') {
            $name .= ' (' . safe_text((string)$variant['display_name']) . ')';
        }
        $description = safe_text((string)($component['description'] ?? '')) ?: safe_text((string)($component['notes'] ?? ''));
        $hsn = safe_text((string)($component['hsn'] ?? '')) ?: $defaultHsn;
        $resolvedUnit = $unit !== '' ? $unit : (safe_text((string)($component['default_unit'] ?? '')) ?: 'pcs');
        $structured[] = [
            'type' => 'component', 'kit_id' => '', 'component_id' => $componentId, 'qty' => $qty, 'unit' => $resolvedUnit,
            'variant_id' => $variantId,
            'variant_snapshot' => is_array($variant) ? [
                'id'=>(string)($variant['id']??''),'display_name'=>(string)($variant['display_name']??''),'brand'=>(string)($variant['brand']??''),
                'technology'=>(string)($variant['technology']??''),'wattage_wp'=>(float)($variant['wattage_wp']??0),'model_no'=>(string)($variant['model_no']??''),
            ] : [],
            'name_snapshot'=>$name,'description_snapshot'=>$description,'master_description_snapshot'=>$description,
            'auto_description'=>$auto,'custom_description'=>$custom,'description_mode'=>$mode,'hsn_snapshot'=>$hsn,'meta'=>[],
        ];
        $summary[] = ['name'=>$name,'description'=>$description,'hsn'=>$hsn,'qty'=>$qty,'unit'=>$resolvedUnit,'gst_slab'=>'5','basic_amount'=>0];
    }
    if ($structured === []) return ['ok' => false, 'error' => 'Add at least one kit/component from Items Master.'];
    return ['ok'=>true,'structured'=>$structured,'summary'=>$summary,'default_hsn'=>$defaultHsn];
}

function standalone_invoice_quote_builder_apply(array $invoice, array $post, ?CustomerFsStore $store = null): array
{
    if (!documents_invoice_is_standalone($invoice)) return ['ok'=>false,'error'=>'Only standalone invoices can use this builder.','invoice'=>$invoice];
    if (!documents_invoice_is_draft($invoice)) return ['ok'=>false,'error'=>'Only draft invoices can be edited.','invoice'=>$invoice];

    $store = $store ?? new CustomerFsStore();
    $quoteDefaults = function_exists('load_quote_defaults') ? load_quote_defaults() : documents_quote_defaults_settings();
    if (!is_array($quoteDefaults)) $quoteDefaults = documents_quote_defaults_settings();
    $catalog = standalone_invoice_quote_builder_catalog();
    $mobile = documents_normalize_mobile((string)($post['customer_mobile'] ?? ''));
    $name = safe_text((string)($post['customer_name'] ?? ''));
    if ($mobile === '' || $name === '') return ['ok'=>false,'error'=>'Customer mobile and name are required.','invoice'=>$invoice];
    $customer = $store->findByMobile($mobile);
    $snapshot = documents_build_quote_snapshot_from_request($post, is_array($customer) ? 'customer' : 'lead', $customer);
    $snapshot['mobile'] = $mobile;
    $snapshot['name'] = $name;

    $mainRaw = trim((string)($post['main_solar_kwp'] ?? ''));
    $nonDcrRaw = trim((string)($post['complimentary_non_dcr_kwp'] ?? ''));
    $main = $mainRaw === '' ? 0.0 : (float)$mainRaw;
    $nonDcr = $nonDcrRaw === '' ? 0.0 : (float)$nonDcrRaw;
    if (!is_finite($main) || $main <= 0) return ['ok'=>false,'error'=>'Main Solar Size / DCR (kWp) must be greater than 0.','invoice'=>$invoice];
    if (!is_finite($nonDcr) || $nonDcr < 0) return ['ok'=>false,'error'=>'Complimentary Non-DCR size cannot be negative.','invoice'=>$invoice];
    $capacity = round($main + $nonDcr, 3);
    if ($capacity <= 0) return ['ok'=>false,'error'=>'Total System Capacity (kWp) must be greater than 0.','invoice'=>$invoice];

    $pricingMode = safe_text((string)($post['pricing_mode'] ?? 'solar_split_70_30'));
    if (!in_array($pricingMode, ['solar_split_70_30','flat_5'], true)) $pricingMode = 'solar_split_70_30';
    $systemType = documents_quote_normalize_system_type((string)($post['system_type'] ?? 'ongrid'));
    $company = documents_get_company_profile_for_quotes();
    $placeOfSupply = safe_text((string)($post['place_of_supply_state'] ?? 'Jharkhand')) ?: 'Jharkhand';
    $taxType = strtolower($placeOfSupply) === strtolower(trim((string)($company['state'] ?? 'Jharkhand'))) ? 'CGST_SGST' : 'IGST';

    $items = standalone_invoice_quote_builder_parse_items($post, $catalog, $quoteDefaults);
    if (empty($items['ok'])) return ['ok'=>false,'error'=>(string)$items['error'],'invoice'=>$invoice];

    $quote = documents_quote_defaults();
    $quote['id'] = '';
    $quote['quote_no'] = '';
    $quote['party_type'] = is_array($customer) ? 'customer' : 'lead';
    $quote['customer_mobile'] = $mobile;
    $quote['customer_name'] = $name;
    $quote['customer_snapshot'] = array_merge(documents_customer_snapshot_defaults(), $snapshot, [
        'mobile'=>$mobile,'name'=>$name,
        'address'=>safe_text((string)($post['site_address'] ?? $snapshot['address'] ?? '')) ?: (string)($snapshot['address'] ?? ''),
        'city'=>safe_text((string)($post['city'] ?? $snapshot['city'] ?? '')),
        'district'=>safe_text((string)($post['district'] ?? $snapshot['district'] ?? '')),
        'pin_code'=>safe_text((string)($post['pin'] ?? $snapshot['pin_code'] ?? '')),
        'state'=>safe_text((string)($post['state'] ?? $snapshot['state'] ?? 'Jharkhand')),
        'meter_number'=>safe_text((string)($post['meter_number'] ?? $snapshot['meter_number'] ?? '')),
        'meter_serial_number'=>safe_text((string)($post['meter_serial_number'] ?? $snapshot['meter_serial_number'] ?? '')),
        'consumer_account_no'=>safe_text((string)($post['consumer_account_no'] ?? $snapshot['consumer_account_no'] ?? '')),
        'application_id'=>safe_text((string)($post['application_id'] ?? $snapshot['application_id'] ?? '')),
        'application_submitted_date'=>safe_text((string)($post['application_submitted_date'] ?? $snapshot['application_submitted_date'] ?? '')),
        'sanction_load_kwp'=>safe_text((string)($post['sanction_load_kwp'] ?? $snapshot['sanction_load_kwp'] ?? '')),
        'installed_pv_module_capacity_kwp'=>safe_text((string)($post['installed_pv_module_capacity_kwp'] ?? $snapshot['installed_pv_module_capacity_kwp'] ?? '')),
        'circle_name'=>safe_text((string)($post['circle_name'] ?? $snapshot['circle_name'] ?? '')),
        'division_name'=>safe_text((string)($post['division_name'] ?? $snapshot['division_name'] ?? '')),
        'sub_division_name'=>safe_text((string)($post['sub_division_name'] ?? $snapshot['sub_division_name'] ?? '')),
    ]);
    $quote['billing_address'] = safe_text((string)($post['billing_address'] ?? '')) ?: (string)$quote['customer_snapshot']['address'];
    $quote['site_address'] = (string)$quote['customer_snapshot']['address'];
    $quote['district'] = (string)$quote['customer_snapshot']['district'];
    $quote['city'] = (string)$quote['customer_snapshot']['city'];
    $quote['state'] = (string)$quote['customer_snapshot']['state'];
    $quote['pin'] = (string)$quote['customer_snapshot']['pin_code'];
    $quote['meter_number'] = (string)$quote['customer_snapshot']['meter_number'];
    $quote['meter_serial_number'] = (string)$quote['customer_snapshot']['meter_serial_number'];
    $quote['consumer_account_no'] = (string)$quote['customer_snapshot']['consumer_account_no'];
    $quote['application_id'] = (string)$quote['customer_snapshot']['application_id'];
    $quote['application_submitted_date'] = (string)$quote['customer_snapshot']['application_submitted_date'];
    $quote['sanction_load_kwp'] = (string)$quote['customer_snapshot']['sanction_load_kwp'];
    $quote['installed_pv_module_capacity_kwp'] = (string)$quote['customer_snapshot']['installed_pv_module_capacity_kwp'];
    $quote['circle_name'] = (string)$quote['customer_snapshot']['circle_name'];
    $quote['division_name'] = (string)$quote['customer_snapshot']['division_name'];
    $quote['sub_division_name'] = (string)$quote['customer_snapshot']['sub_division_name'];
    $quote['system_type'] = $systemType;
    $quote['main_solar_kwp'] = (string)$main;
    $quote['complimentary_non_dcr_kwp'] = (string)$nonDcr;
    $quote['capacity_kwp'] = (string)$capacity;
    $quote['system_capacity_kwp'] = $capacity;
    $quote['project_summary_line'] = safe_text((string)($post['project_summary_line'] ?? ''));
    $quote['pricing_mode'] = $pricingMode;
    $quote['show_tax_breakup'] = !empty($post['show_tax_breakup']);
    $quote['place_of_supply_state'] = $placeOfSupply;
    $quote['tax_type'] = $taxType;
    $quote['tax_profile_id'] = safe_text((string)($post['tax_profile_id'] ?? ''));
    $quote['quote_items'] = documents_normalize_quote_structured_items((array)$items['structured']);
    $quote['items'] = documents_normalize_quote_items((array)$items['summary'], $systemType, $capacity, (string)$items['default_hsn']);

    if ($quote['tax_profile_id'] === '') {
        foreach ($quote['quote_items'] as $line) {
            if (!is_array($line) || (string)($line['type'] ?? '') !== 'kit') continue;
            $kit = documents_inventory_get_kit((string)($line['kit_id'] ?? ''));
            $kitTaxProfileId = safe_text((string)($kit['tax_profile_id'] ?? ''));
            if ($kitTaxProfileId !== '') { $quote['tax_profile_id'] = $kitTaxProfileId; break; }
        }
    }
    if ($quote['tax_profile_id'] === '') $quote['tax_profile_id'] = safe_text((string)($quoteDefaults['defaults']['quotation_tax_profile_id'] ?? ''));

    $priceSelf = max(0, (float)($post['scenario_price_self_funded'] ?? $post['system_total_incl_gst_rs'] ?? 0));
    $priceLoan2 = max(0, (float)($post['scenario_price_loan_upto_2_lacs'] ?? $priceSelf));
    $priceLoanAbove = max(0, (float)($post['scenario_price_loan_above_2_lacs'] ?? $priceSelf));
    $transport = max(0, (float)($post['transportation_rs'] ?? 0));
    $subsidy = max(0, (float)($post['subsidy_expected_rs'] ?? 0));
    $discount = max(0, (float)($post['discount_rs'] ?? 0));
    foreach ([$priceSelf,$priceLoan2,$priceLoanAbove,$transport,$subsidy,$discount] as $value) {
        if (!is_finite($value)) return ['ok'=>false,'error'=>'Pricing values must be valid non-negative numbers.','invoice'=>$invoice];
    }
    $primary = safe_text((string)($post['primary_finance_scenario'] ?? 'self_funded'));
    if (!in_array($primary, ['self_funded','loan_upto_2_lacs','loan_upto_2_lacs_subsidy_to_loan','loan_upto_2_lacs_subsidy_not_to_loan','loan_above_2_lacs','loan_above_2_lacs_subsidy_to_loan','loan_above_2_lacs_subsidy_not_to_loan'], true)) $primary = 'self_funded';
    $priceForPrimary = $primary === 'self_funded' ? $priceSelf : (str_contains($primary, 'above_2') ? $priceLoanAbove : $priceLoan2);
    if ($priceForPrimary <= 0) return ['ok'=>false,'error'=>'Set the selected invoice/quotation-style price before saving.','invoice'=>$invoice];
    $quote['primary_finance_scenario'] = $primary;
    $quote['scenario_prices'] = [
        'self_funded'=>['price'=>round($priceSelf,2)],
        'loan_upto_2_lacs'=>['price'=>round($priceLoan2,2)],
        'loan_above_2_lacs'=>['price'=>round($priceLoanAbove,2)],
    ];
    $quote['discount_rs'] = round($discount,2);
    $quote['discount_note'] = safe_text((string)($post['discount_note'] ?? ''));
    $quote['finance_inputs'] = [
        'monthly_bill_rs'=>safe_text((string)($post['monthly_bill_rs'] ?? '')),
        'unit_rate_rs_per_kwh'=>safe_text((string)($post['unit_rate_rs_per_kwh'] ?? '')),
        'annual_generation_per_kw'=>safe_text((string)($post['annual_generation_per_kw'] ?? '')),
        'subsidy_expected_rs'=>(string)round($subsidy,2),
        'transportation_rs'=>(string)round($transport,2),
        'discount_rs'=>(string)round($discount,2),
        'discount_note'=>$quote['discount_note'],
        'loan'=>[
            'upto_2_lacs_margin_pct'=>safe_text((string)($post['loan_upto_2_lacs_margin_ratio_pct'] ?? '')),
            'upto_2_lacs_loan_pct'=>safe_text((string)($post['loan_upto_2_lacs_loan_ratio_pct'] ?? '')),
            'upto_2_lacs_interest_pct'=>safe_text((string)($post['loan_upto_2_lacs_interest_pct'] ?? '')),
            'upto_2_lacs_tenure_years'=>safe_text((string)($post['loan_upto_2_lacs_tenure_years'] ?? '')),
            'above_2_lacs_margin_pct'=>safe_text((string)($post['loan_above_2_lacs_margin_ratio_pct'] ?? '')),
            'above_2_lacs_loan_pct'=>safe_text((string)($post['loan_above_2_lacs_loan_ratio_pct'] ?? '')),
            'above_2_lacs_interest_pct'=>safe_text((string)($post['loan_above_2_lacs_interest_pct'] ?? '')),
            'above_2_lacs_tenure_years'=>safe_text((string)($post['loan_above_2_lacs_tenure_years'] ?? '')),
        ],
    ];

    $selectedModel = safe_text((string)($post['selected_model_number'] ?? ''));
    $quote['rate_chart_snapshot'] = [
        'system_type'=>$systemType,'model_number'=>$selectedModel,'solar_size_kwp'=>$capacity,'dcr_size_kwp'=>$main,
        'non_dcr_size_kwp'=>$nonDcr,'total_system_size_kwp'=>$capacity,
        'hybrid_inverter_kva'=>(float)($post['hybrid_inverter_kva'] ?? 0),
        'hybrid_phase'=>safe_text((string)($post['hybrid_phase'] ?? '')),
        'hybrid_battery_count'=>(int)($post['hybrid_battery_count'] ?? 0),
        'self_funded_price'=>round($priceSelf,2),'loan_upto_2_lacs_price'=>round($priceLoan2,2),'loan_above_2_lacs_price'=>round($priceLoanAbove,2),'captured_at'=>date('c'),
    ];
    $quote = documents_quote_reconcile_system_configuration($quote);
    if (safe_text((string)($quote['system_reconcile_error'] ?? '')) !== '') return ['ok'=>false,'error'=>(string)$quote['system_reconcile_error'],'invoice'=>$invoice];
    if (function_exists('solar_finance_sync_hybrid_summary_into_quote_items')) $quote = solar_finance_sync_hybrid_summary_into_quote_items($quote);
    $quote['calc'] = documents_calc_quote_pricing_with_tax_profile($quote, round($transport,2), round($subsidy,2), round($priceForPrimary,2), $quoteDefaults);
    $quote['tax_breakdown'] = is_array($quote['calc']['tax_breakdown'] ?? null) ? $quote['calc']['tax_breakdown'] : [];
    $quote['input_total_gst_inclusive'] = round($priceForPrimary,2);
    $quote['special_requests_text'] = safe_multiline_text((string)($post['special_requests_text'] ?? ''));
    $quote['special_requests_inclusive'] = $quote['special_requests_text'];

    $match = documents_standalone_match_customer($invoice, $mobile, $store, true);
    $invoice = (array)($match['invoice'] ?? $invoice);
    $invoice['customer_mobile'] = $mobile;
    $invoice['customer_snapshot'] = $quote['customer_snapshot'];
    $invoice['capacity_kwp'] = (string)$capacity;
    $invoice['pricing_mode'] = $pricingMode;
    $invoice['commercial_items'] = (array)$quote['items'];
    $invoice['tax_breakdown'] = (array)$quote['tax_breakdown'];
    $invoice['calc'] = (array)$quote['calc'];
    $invoice['input_total_gst_inclusive'] = (float)$quote['input_total_gst_inclusive'];
    $invoice['manual_reference'] = [
        'quotation_no'=>safe_text((string)($post['manual_quotation_no'] ?? '')),
        'quotation_date'=>safe_text((string)($post['manual_quotation_date'] ?? '')),
        'external_reference'=>safe_text((string)($post['external_reference'] ?? '')),
        'quotation_amount'=>($post['manual_quotation_amount'] ?? '') === '' ? null : max(0,(float)$post['manual_quotation_amount']),
    ];
    $invoice['quotation_snapshot'] = array_merge(documents_invoice_quote_snapshot($quote), [
        'main_solar_kwp'=>(string)$main,'complimentary_non_dcr_kwp'=>(string)$nonDcr,'capacity_kwp'=>(string)$capacity,
        'system_type'=>$systemType,'project_summary_line'=>$quote['project_summary_line'],'rate_chart_snapshot'=>$quote['rate_chart_snapshot'],
        'quote_items'=>$quote['quote_items'],'finance_inputs'=>$quote['finance_inputs'],'scenario_prices'=>$quote['scenario_prices'],'primary_finance_scenario'=>$primary,
    ]);
    $invoice['standalone_quote_snapshot'] = $invoice['quotation_snapshot'];
    $invoice['standalone_builder'] = ['mode'=>'quotation_style','version'=>1,'updated_at'=>date('c')];
    $invoice['invoice_no'] = safe_text((string)($post['invoice_no'] ?? $invoice['invoice_no'] ?? ''));
    $dateResult = documents_invoice_set_date($invoice, (string)($post['invoice_date'] ?? ''), []);
    if (empty($dateResult['ok'])) return ['ok'=>false,'error'=>(string)$dateResult['error'],'invoice'=>$invoice];
    $invoice = (array)$dateResult['invoice'];
    $invoice['internal_notes'] = safe_multiline_text((string)($post['internal_notes'] ?? $invoice['internal_notes'] ?? ''));

    $finalTotal = (float)($quote['calc']['gross_payable'] ?? $quote['calc']['final_price_incl_gst'] ?? $quote['calc']['grand_total'] ?? $priceForPrimary);
    $recalculated = documents_invoice_recalculate_pricing($invoice, max(0,$finalTotal), '');
    $invoice = (array)($recalculated['invoice'] ?? $invoice);
    $invoice['updated_at'] = date('c');
    return ['ok'=>true,'error'=>'','invoice'=>$invoice,'customer'=>$customer,'linked'=>is_array($customer),'quote_like'=>$quote];
}

function standalone_invoice_quote_builder_sync_sales_record(array $doc): void
{
    $invoiceId = (string)($doc['id'] ?? '');
    if ($invoiceId === '') return;
    $snap = array_merge(documents_customer_snapshot_defaults(), is_array($doc['customer_snapshot'] ?? null) ? $doc['customer_snapshot'] : []);
    $sales = documents_get_sales_document('invoice', $invoiceId) ?: documents_sales_document_defaults('invoice');
    $summary = documents_invoice_payment_summary($doc);
    $sales['id']=$invoiceId;$sales['quotation_id']='';$sales['customer_mobile']=(string)($doc['customer_mobile']??$snap['mobile']??'');$sales['customer_name']=(string)($snap['name']??'');
    $sales['invoice_no']=(string)($doc['invoice_no']??'');$sales['invoice_date']=documents_invoice_authoritative_date($doc);$sales['amount']=$summary['invoice_total'];
    $sales['status']=documents_invoice_normalize_status((string)($doc['status']??'draft'));$sales['document_status']=$sales['status'];$sales['payment_status']=$summary['payment_status'];
    $sales['received_total']=$summary['total_received'];$sales['outstanding']=$summary['outstanding'];$sales['overpayment']=$summary['overpayment'];$sales['revision_no']=(int)($doc['revision_no']??0);
    $sales['finalized_at']=(string)($doc['finalized_at']??'');$sales['cancelled_flag']=documents_invoice_is_cancelled($doc);$sales['archived_flag']=!empty($doc['archived_flag']);
    $sales['created_at']=(string)($sales['created_at']?:($doc['created_at']??date('c')));$sales['updated_at']=(string)($doc['updated_at']??date('c'));
    documents_save_sales_document('invoice',$sales);
}
