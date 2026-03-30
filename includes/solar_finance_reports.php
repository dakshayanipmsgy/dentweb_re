<?php
declare(strict_types=1);

require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/../admin/includes/documents_helpers.php';

function solar_finance_reports_storage_path(): string
{
    $dir = __DIR__ . '/../data/solar_finance';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir . '/reports.json';
}

function solar_finance_load_reports(): array
{
    $path = solar_finance_reports_storage_path();
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function solar_finance_save_reports(array $reports): void
{
    $path = solar_finance_reports_storage_path();
    $encoded = json_encode(array_values($reports), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Unable to encode reports.');
    }

    file_put_contents($path, $encoded, LOCK_EX);
}

function solar_finance_normalize_mobile(string $mobile): string
{
    $digits = preg_replace('/\D+/', '', $mobile) ?? '';
    if ($digits === '') {
        return '';
    }

    $core = '';
    if (preg_match('/^0\d{10}$/', $digits) === 1) {
        $core = substr($digits, 1);
    } elseif (preg_match('/^91\d{10}$/', $digits) === 1) {
        $core = substr($digits, 2);
    } elseif (preg_match('/^\d{10}$/', $digits) === 1) {
        $core = $digits;
    }

    if (preg_match('/^\d{10}$/', $core) !== 1) {
        return '';
    }

    return '91' . $core;
}

function solar_finance_mobile_key(string $mobile): string
{
    $normalized = solar_finance_normalize_mobile($mobile);
    if ($normalized !== '') {
        return substr($normalized, -10);
    }

    $digits = preg_replace('/\D+/', '', $mobile) ?? '';
    if (preg_match('/^0\d{10}$/', $digits) === 1) {
        return substr($digits, -10);
    }
    if (preg_match('/^91\d{10}$/', $digits) === 1) {
        return substr($digits, -10);
    }
    if (preg_match('/^\d{10}$/', $digits) === 1) {
        return $digits;
    }

    return $digits;
}

function solar_finance_generate_report_id(): string
{
    return 'sfr_' . bin2hex(random_bytes(6));
}

function solar_finance_generate_report_token(): string
{
    return bin2hex(random_bytes(24));
}

function solar_finance_create_report(array $payload): array
{
    $reports = solar_finance_load_reports();
    $now = date('Y-m-d H:i:s');

    $record = [
        'report_id' => solar_finance_generate_report_id(),
        'public_token' => solar_finance_generate_report_token(),
        'customer_name' => trim((string) ($payload['customer']['name'] ?? '')),
        'location' => trim((string) ($payload['customer']['location'] ?? '')),
        'mobile' => solar_finance_normalize_mobile((string) ($payload['customer']['mobile_normalized'] ?? ($payload['customer']['mobile_raw'] ?? ''))),
        'input_snapshot' => is_array($payload['inputs'] ?? null) ? $payload['inputs'] : [],
        'result_snapshot' => is_array($payload['results'] ?? null) ? $payload['results'] : [],
        'charts_images' => is_array($payload['charts_images'] ?? null) ? $payload['charts_images'] : [],
        'created_at' => $now,
        'generated_at' => $now,
        'source' => 'Solar and Finance',
        'created_by' => 'public_calculator',
    ];

    $reports[] = $record;
    solar_finance_save_reports($reports);

    return $record;
}

function solar_finance_find_report(?string $token, ?string $reportId): ?array
{
    $token = trim((string) $token);
    $reportId = trim((string) $reportId);

    foreach (solar_finance_load_reports() as $report) {
        if ($token !== '' && hash_equals((string) ($report['public_token'] ?? ''), $token)) {
            return $report;
        }
        if ($token === '' && $reportId !== '' && (string) ($report['report_id'] ?? '') === $reportId) {
            return $report;
        }
    }

    return null;
}

function solar_finance_append_note(string $existingNotes, string $line): string
{
    $existingNotes = trim($existingNotes);
    return $existingNotes === '' ? $line : ($existingNotes . "\n" . $line);
}

function solar_finance_create_or_update_lead(array $report): array
{
    $mobile = (string) ($report['mobile'] ?? '');
    $mobileKey = solar_finance_mobile_key($mobile);

    if ($mobileKey === '') {
        return ['action' => 'skipped', 'lead_id' => ''];
    }

    $leads = load_all_leads();
    $existing = null;
    foreach ($leads as $lead) {
        $existingKey = solar_finance_mobile_key((string) ($lead['mobile'] ?? ''));
        if ($existingKey !== '' && $existingKey === $mobileKey) {
            $existing = $lead;
            break;
        }
    }

    $input = is_array($report['input_snapshot'] ?? null) ? $report['input_snapshot'] : [];
    $noteLine = sprintf(
        '[%s] Lead created from Solar and Finance report generation (%s).',
        date('d M Y h:i A'),
        (string) ($report['report_id'] ?? '')
    );

    $payload = [
        'name' => (string) ($report['customer_name'] ?? ''),
        'mobile' => $mobile,
        'city' => (string) ($report['location'] ?? ''),
        'lead_source' => 'Solar and Finance',
        'status' => 'Interested',
        'monthly_bill' => (string) ($input['monthly_bill'] ?? ''),
        'interest_type' => (string) ($input['system_type'] ?? ''),
        'finance_subsidy' => (string) ($input['subsidy'] ?? ''),
    ];

    if (is_array($existing)) {
        $payload['notes'] = solar_finance_append_note((string) ($existing['notes'] ?? ''), $noteLine);
        $updated = update_lead((string) ($existing['id'] ?? ''), $payload);

        return ['action' => $updated === null ? 'skipped' : 'updated', 'lead_id' => (string) ($existing['id'] ?? '')];
    }

    $payload['notes'] = $noteLine;
    $created = add_lead($payload);

    return ['action' => 'created', 'lead_id' => (string) ($created['id'] ?? '')];
}

function solar_finance_normalize_kit_name(string $name): string
{
    $normalized = strtolower(trim($name));
    return preg_replace('/\s+/', ' ', $normalized) ?? '';
}

function solar_finance_find_matching_kit(string $systemType): ?array
{
    $systemTypeKey = solar_finance_normalize_kit_name($systemType);
    $aliasesBySystemType = [
        'hybrid' => [
            'hybrid solar power generation system',
            'hybrid solar power generation system tbased',
        ],
        'ongrid' => [
            'ongrid solar power generation system',
        ],
    ];

    $targetNames = $aliasesBySystemType[$systemTypeKey] ?? $aliasesBySystemType['ongrid'];
    $targetLookup = [];
    foreach ($targetNames as $alias) {
        $targetLookup[solar_finance_normalize_kit_name($alias)] = true;
    }

    foreach (documents_inventory_kits(false) as $kit) {
        if (!is_array($kit) || !empty($kit['inactive'])) {
            continue;
        }

        $name = solar_finance_normalize_kit_name((string) ($kit['name'] ?? ''));
        if ($name !== '' && isset($targetLookup[$name])) {
            return $kit;
        }
    }

    return null;
}

function solar_finance_quote_mobile_key_from_quote(array $quote): string
{
    $candidates = [
        (string) ($quote['customer_mobile'] ?? ''),
        (string) ($quote['source_lead_mobile'] ?? ''),
        (string) (($quote['source']['lead_mobile'] ?? '')),
    ];
    foreach ($candidates as $candidate) {
        $key = solar_finance_mobile_key($candidate);
        if ($key !== '') {
            return $key;
        }
    }
    return '';
}

function solar_finance_default_residential_template_name(): string
{
    return 'pm surya ghar - residential (subsidy) (res)';
}

function solar_finance_resolve_template_context(string $currentTemplateSetId): array
{
    $templatesRaw = json_load(documents_templates_dir() . '/template_sets.json', []);
    $templates = [];
    foreach ($templatesRaw as $row) {
        if (!is_array($row) || !empty($row['archived_flag'])) {
            continue;
        }
        $templates[] = $row;
    }

    $templateBlocks = documents_sync_template_block_entries($templates);
    $templateBlocks = is_array($templateBlocks) ? $templateBlocks : [];

    $templateById = [];
    foreach ($templates as $tplRow) {
        $templateId = safe_text((string) ($tplRow['id'] ?? ''));
        if ($templateId === '') {
            continue;
        }
        $templateById[$templateId] = $tplRow;
    }

    $resolvedTemplateSetId = safe_text($currentTemplateSetId);
    if ($resolvedTemplateSetId === '' || !isset($templateById[$resolvedTemplateSetId])) {
        $defaultTemplateName = solar_finance_default_residential_template_name();
        foreach ($templates as $tplRow) {
            $templateName = trim((string) ($tplRow['name'] ?? ''));
            $segmentName = trim((string) ($tplRow['segment'] ?? ''));
            $displayName = trim($templateName . ($segmentName !== '' ? (' (' . $segmentName . ')') : ''));
            if (strcasecmp($displayName, $defaultTemplateName) === 0) {
                $resolvedTemplateSetId = safe_text((string) ($tplRow['id'] ?? ''));
                break;
            }
        }
    }

    if ($resolvedTemplateSetId === '' || !isset($templateById[$resolvedTemplateSetId])) {
        foreach ($templates as $tplRow) {
            $candidateId = safe_text((string) ($tplRow['id'] ?? ''));
            if ($candidateId !== '') {
                $resolvedTemplateSetId = $candidateId;
                break;
            }
        }
    }

    return [
        'template_set_id' => $resolvedTemplateSetId,
        'template' => is_array($templateById[$resolvedTemplateSetId] ?? null) ? $templateById[$resolvedTemplateSetId] : null,
        'template_blocks' => $templateBlocks,
    ];
}

function solar_finance_quote_has_attachment_snapshot(array $attachments): bool
{
    if (!empty($attachments['include_ongrid_diagram']) || !empty($attachments['include_hybrid_diagram']) || !empty($attachments['include_offgrid_diagram'])) {
        return true;
    }
    if (trim((string) ($attachments['ongrid_diagram_media_id'] ?? '')) !== '') {
        return true;
    }
    if (trim((string) ($attachments['hybrid_diagram_media_id'] ?? '')) !== '') {
        return true;
    }
    if (trim((string) ($attachments['offgrid_diagram_media_id'] ?? '')) !== '') {
        return true;
    }
    return !empty($attachments['additional_media_ids']) && is_array($attachments['additional_media_ids']);
}

function solar_finance_apply_template_snapshot_to_quote(array $quote, bool $isCreate): array
{
    $templateContext = solar_finance_resolve_template_context((string) ($quote['template_set_id'] ?? ''));
    $templateSetId = safe_text((string) ($templateContext['template_set_id'] ?? ''));
    $templateBlocks = is_array($templateContext['template_blocks'] ?? null) ? $templateContext['template_blocks'] : [];
    $templateEntry = is_array($templateBlocks[$templateSetId] ?? null) ? $templateBlocks[$templateSetId] : [];

    if ($templateSetId !== '') {
        $quote['template_set_id'] = $templateSetId;
        if ($isCreate) {
            $templateSegment = safe_text((string) (($templateContext['template']['segment'] ?? '') ?: ''));
            if ($templateSegment !== '') {
                $quote['segment'] = $templateSegment;
            }
        }
    }

    $blockDefaults = documents_quote_annexure_from_template($templateBlocks, $templateSetId);
    $annexure = documents_template_block_defaults();
    $existingAnnexure = is_array($quote['annexures_overrides'] ?? null) ? $quote['annexures_overrides'] : [];
    foreach ($annexure as $key => $defaultValue) {
        $value = safe_text((string) ($existingAnnexure[$key] ?? $defaultValue));
        if ($value === '' && ($blockDefaults[$key] ?? '') !== '') {
            $value = safe_text((string) $blockDefaults[$key]);
        }
        $annexure[$key] = $value;
    }
    $quote['annexures_overrides'] = $annexure;
    $quote['cover_notes_html_snapshot'] = trim((string) ($annexure['cover_notes'] ?? ''));

    $templateAttachments = (($templateEntry['attachments'] ?? null) && is_array($templateEntry['attachments']))
        ? $templateEntry['attachments']
        : documents_template_attachment_defaults();
    $existingAttachments = is_array($quote['template_attachments'] ?? null) ? $quote['template_attachments'] : [];
    if ($isCreate || !solar_finance_quote_has_attachment_snapshot($existingAttachments)) {
        $quote['template_attachments'] = $templateAttachments;
    }

    return $quote;
}

function create_or_update_solar_finance_quote(array $payload): array
{
    $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
    $inputs = is_array($payload['inputs'] ?? null) ? $payload['inputs'] : [];

    $customerName = trim((string) ($customer['name'] ?? ''));
    $city = trim((string) ($customer['location'] ?? ''));
    $mobile = solar_finance_normalize_mobile((string) ($customer['mobile_normalized'] ?? ($customer['mobile_raw'] ?? '')));
    $mobileKey = solar_finance_mobile_key($mobile);
    if ($customerName === '' || $city === '' || $mobileKey === '') {
        return ['success' => false, 'action' => 'skipped', 'message' => 'Required customer details are missing.'];
    }

    $systemType = strtolower(trim((string) ($inputs['system_type'] ?? 'Ongrid'))) === 'hybrid' ? 'Hybrid' : 'Ongrid';
    $kit = solar_finance_find_matching_kit($systemType);
    if (!is_array($kit)) {
        return ['success' => false, 'action' => 'skipped', 'message' => 'Required kit not found in Items Master.'];
    }

    $higherLoanApplicable = (bool) ($inputs['higher_loan_applicable'] ?? false);
    $systemCostUp2 = max(0, (float) ($inputs['system_cost_up2'] ?? 0));
    $systemCostAbove2 = max(0, (float) ($inputs['system_cost_above2'] ?? 0));
    $useAbove2Scenario = $higherLoanApplicable && $systemCostAbove2 > 0;
    $selectedSystemPrice = $useAbove2Scenario ? $systemCostAbove2 : $systemCostUp2;

    $loanInterest = $useAbove2Scenario
        ? (float) ($inputs['interest_rate_above2'] ?? 0)
        : (float) ($inputs['interest_rate_up2'] ?? 0);
    $loanAmount = $useAbove2Scenario
        ? (float) ($inputs['loan_amount_above2'] ?? 0)
        : (float) ($inputs['loan_amount_up2'] ?? 0);
    $marginMoney = $useAbove2Scenario
        ? (float) ($inputs['margin_money_above2'] ?? 0)
        : (float) ($inputs['margin_money_up2'] ?? 0);

    $subsidy = max(0, (float) ($inputs['subsidy'] ?? 0));
    $monthlyBill = max(0, (float) ($inputs['monthly_bill'] ?? 0));
    $solarSize = max(0, (float) ($inputs['solar_size_kw'] ?? 0));
    $unitRate = max(0, (float) ($inputs['unit_rate'] ?? 0));
    $dailyGenerationPerKw = max(0, (float) ($inputs['daily_generation_per_kw'] ?? 0));
    $annualGenerationPerKw = $dailyGenerationPerKw > 0 ? $dailyGenerationPerKw * 360 : 0.0;
    $loanTenureYears = max(0, (float) ($inputs['loan_tenure_years'] ?? 0));

    $linkedQuoteId = safe_text((string) ($payload['linked_quote_id'] ?? ''));
    $existing = null;
    if ($linkedQuoteId !== '') {
        $linked = documents_get_quote($linkedQuoteId);
        if (is_array($linked)) {
            $linkedKey = solar_finance_quote_mobile_key_from_quote($linked);
            $linkedSource = strtolower(trim((string) ($linked['source']['type'] ?? '')));
            if ($linkedSource === 'solar_and_finance' && $linkedKey === $mobileKey) {
                $existing = $linked;
            }
        }
    }

    if (!is_array($existing)) {
        foreach (documents_list_quotes() as $quote) {
            $sourceType = strtolower(trim((string) ($quote['source']['type'] ?? '')));
            $status = documents_quote_normalize_status((string) ($quote['status'] ?? 'draft'));
            if ($sourceType !== 'solar_and_finance' || $status !== 'draft') {
                continue;
            }
            $quoteMobileKey = solar_finance_quote_mobile_key_from_quote($quote);
            if ($quoteMobileKey === '' || $quoteMobileKey !== $mobileKey) {
                continue;
            }
            $existing = $quote;
            break;
        }
    }

    if (is_array($existing) && !($existing['auto_sync_enabled'] ?? true)) {
        return [
            'success' => true,
            'action' => 'skipped_manual_lock',
            'quote_id' => (string) ($existing['id'] ?? ''),
            'message' => 'Auto-sync is disabled for this quotation.',
        ];
    }

    $isCreate = !is_array($existing);
    $quote = $isCreate ? documents_quote_defaults() : $existing;

    if ($isCreate) {
        $number = documents_generate_quote_number('RES');
        $quote['id'] = 'qtn_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $quote['quote_no'] = ($number['ok'] ?? false) ? (string) ($number['quote_no'] ?? '') : '';
        $quote['created_at'] = date('c');
        $quote['created_by_type'] = 'system';
        $quote['created_by_id'] = 'solar_finance';
        $quote['created_by_name'] = 'Solar and Finance';
        $quote['segment'] = 'RES';
    }

    $quote['updated_at'] = date('c');
    $quote['status'] = 'draft';
    $quote['party_type'] = 'lead';
    $quote['customer_name'] = $customerName;
    $quote['customer_mobile'] = $mobile;
    $quote['city'] = $city;
    $quote['district'] = $city;
    $quote['state'] = safe_text((string) ($quote['state'] ?? 'Jharkhand')) ?: 'Jharkhand';
    $quote['system_type'] = $systemType;
    $quote['main_solar_kwp'] = $solarSize > 0 ? (string) $solarSize : '';
    $quote['complimentary_non_dcr_kwp'] = '0';
    $quote['capacity_kwp'] = $solarSize > 0 ? (string) $solarSize : '';
    $quote['system_capacity_kwp'] = $solarSize;
    $quote['input_total_gst_inclusive'] = $selectedSystemPrice;

    $quote['source'] = [
        'type' => 'solar_and_finance',
        'lead_id' => '',
        'lead_mobile' => $mobile,
        'note' => 'Created from Solar and Finance',
    ];
    $quote['source_lead_mobile'] = $mobile;
    $quote['auto_created'] = true;
    $quote['auto_sync_enabled'] = true;
    $quote['auto_source'] = 'solar_and_finance';
    $quote['auto_sync_updated_at'] = date('c');
    $quote['auto_sync_scenario'] = $useAbove2Scenario ? 'loan_above_2_lacs_subsidy_to_loan' : 'loan_upto_2_lacs_subsidy_to_loan';
    $quote['primary_finance_scenario'] = $quote['auto_sync_scenario'];
    $quote['scenario_prices'] = [
        'self_funded' => ['price' => max(0, (float) ($inputs['system_cost_self_funded'] ?? $systemCostUp2))],
        'loan_upto_2_lacs_subsidy_to_loan' => ['price' => $systemCostUp2],
        'loan_upto_2_lacs_subsidy_not_to_loan' => ['price' => $systemCostUp2],
        'loan_above_2_lacs_subsidy_to_loan' => ['price' => $systemCostAbove2, 'applicable' => $higherLoanApplicable && (($systemCostAbove2 * 0.8) >= 200000)],
        'loan_above_2_lacs_subsidy_not_to_loan' => ['price' => $systemCostAbove2, 'applicable' => $higherLoanApplicable && (($systemCostAbove2 * 0.8) >= 200000)],
        'loan_upto_2_lacs' => ['price' => $systemCostUp2],
        'loan_above_2_lacs' => ['price' => $systemCostAbove2, 'applicable' => $higherLoanApplicable && (($systemCostAbove2 * 0.8) >= 200000)],
    ];
    $quote['rate_chart_snapshot'] = [
        'source' => 'solar_and_finance',
        'captured_at' => date('c'),
        'self_funded_price' => (float) ($quote['scenario_prices']['self_funded']['price'] ?? 0),
        'loan_upto_2_lacs_price' => $systemCostUp2,
        'loan_above_2_lacs_price' => $systemCostAbove2,
    ];

    $quote['customer_snapshot'] = array_merge(documents_customer_snapshot_defaults(), is_array($quote['customer_snapshot'] ?? null) ? $quote['customer_snapshot'] : [], [
        'mobile' => $mobile,
        'name' => $customerName,
        'city' => $city,
        'district' => $city,
        'state' => (string) ($quote['state'] ?? 'Jharkhand'),
    ]);

    $kitId = safe_text((string) ($kit['id'] ?? ''));
    $kitName = (string) ($kit['name'] ?? 'Kit');
    $kitDescription = safe_text((string) ($kit['description'] ?? ''));
    $kitHsn = safe_text((string) ($kit['hsn'] ?? '')) ?: '8541';
    $quote['quote_items'] = documents_normalize_quote_structured_items([[
        'type' => 'kit',
        'kit_id' => $kitId,
        'component_id' => '',
        'qty' => 1,
        'unit' => 'set',
        'variant_id' => '',
        'variant_snapshot' => [],
        'name_snapshot' => $kitName,
        'description_snapshot' => $kitDescription,
        'master_description_snapshot' => $kitDescription,
        'custom_description' => '',
        'hsn_snapshot' => $kitHsn,
        'meta' => [],
    ]]);
    $quote['items'] = documents_normalize_quote_items([[
        'name' => $kitName,
        'description' => $kitDescription,
        'hsn' => $kitHsn,
        'qty' => 1,
        'unit' => 'set',
        'gst_slab' => '5',
        'basic_amount' => 0,
    ]], $systemType, $solarSize, $kitHsn);

    $quote['finance_inputs']['monthly_bill_rs'] = (string) $monthlyBill;
    $quote['finance_inputs']['unit_rate_rs_per_kwh'] = $unitRate > 0 ? (string) $unitRate : '';
    $quote['finance_inputs']['annual_generation_per_kw'] = $annualGenerationPerKw > 0 ? (string) $annualGenerationPerKw : '';
    $quote['finance_inputs']['subsidy_expected_rs'] = (string) $subsidy;
    $quote['finance_inputs']['loan']['enabled'] = true;
    $quote['finance_inputs']['loan']['interest_pct'] = (string) $loanInterest;
    $quote['finance_inputs']['loan']['tenure_years'] = (string) $loanTenureYears;
    $quote['finance_inputs']['loan']['loan_amount'] = (string) $loanAmount;
    $quote['finance_inputs']['loan']['margin_pct'] = '';

    $quote['customer_savings_inputs']['bank_loan_enabled'] = true;
    $quote['customer_savings_inputs']['loan_interest_rate_percent'] = $loanInterest;
    $quote['customer_savings_inputs']['loan_tenure_months'] = $loanTenureYears > 0 ? (int) round($loanTenureYears * 12) : null;
    $quote['customer_savings_inputs']['loan_cap_rs'] = $loanAmount;
    $quote['customer_savings_inputs']['margin_amount_rs'] = $marginMoney;
    $quote['customer_savings_inputs']['monthly_bill_before_rs'] = $monthlyBill;
    $quote['customer_savings_inputs']['unit_rate_rs_per_kwh'] = $unitRate > 0 ? $unitRate : null;
    $quote['customer_savings_inputs']['annual_generation_kwh_per_kw'] = $annualGenerationPerKw > 0 ? $annualGenerationPerKw : null;
    $quote['finance_scenarios'] = [
        'self_funded' => [
            'price' => (float) ($quote['scenario_prices']['self_funded']['price'] ?? 0),
            'subsidy' => $subsidy,
            'gross_payable' => (float) ($quote['scenario_prices']['self_funded']['price'] ?? 0),
            'net_investment_after_subsidy' => max(0, (float) ($quote['scenario_prices']['self_funded']['price'] ?? 0) - $subsidy),
            'monthly_outflow' => 0,
            'payback' => 0,
            'applicable' => true,
        ],
        'loan_upto_2_lacs_subsidy_to_loan' => [
            'price' => $systemCostUp2, 'subsidy' => $subsidy, 'gross_payable' => $systemCostUp2,
            'net_investment_after_subsidy' => max(0, $systemCostUp2 - $subsidy), 'monthly_outflow' => 0, 'payback' => 0, 'applicable' => true,
            'margin_money_rs' => (float) ($inputs['margin_money_up2'] ?? 0), 'loan_amount_rs' => (float) ($inputs['loan_amount_up2'] ?? 0),
            'effective_loan_principal_rs' => max(0, (float) ($inputs['loan_amount_up2'] ?? 0) - $subsidy), 'interest_pct' => (float) ($inputs['interest_rate_up2'] ?? 0),
            'tenure_years' => $loanTenureYears, 'emi_rs' => 0, 'residual_bill_rs' => 0,
            'net_own_investment_after_subsidy' => max(0, (float) ($inputs['margin_money_up2'] ?? 0) - $subsidy),
            'subsidy_credit_month' => 12,
        ],
        'loan_upto_2_lacs_subsidy_not_to_loan' => [
            'price' => $systemCostUp2, 'subsidy' => $subsidy, 'gross_payable' => $systemCostUp2,
            'net_investment_after_subsidy' => max(0, $systemCostUp2 - $subsidy), 'monthly_outflow' => 0, 'payback' => 0, 'applicable' => true,
            'margin_money_rs' => (float) ($inputs['margin_money_up2'] ?? 0), 'loan_amount_rs' => (float) ($inputs['loan_amount_up2'] ?? 0),
            'effective_loan_principal_rs' => max(0, (float) ($inputs['loan_amount_up2'] ?? 0)), 'interest_pct' => (float) ($inputs['interest_rate_up2'] ?? 0),
            'tenure_years' => $loanTenureYears, 'emi_rs' => 0, 'residual_bill_rs' => 0,
            'net_own_investment_after_subsidy' => max(0, (float) ($inputs['margin_money_up2'] ?? 0) - $subsidy),
            'subsidy_credit_month' => 12,
        ],
        'loan_above_2_lacs_subsidy_to_loan' => [
            'price' => $systemCostAbove2, 'subsidy' => $subsidy, 'gross_payable' => $systemCostAbove2,
            'net_investment_after_subsidy' => max(0, $systemCostAbove2 - $subsidy), 'monthly_outflow' => 0, 'payback' => 0,
            'applicable' => $higherLoanApplicable && (($systemCostAbove2 * 0.8) >= 200000),
            'margin_money_rs' => (float) ($inputs['margin_money_above2'] ?? 0), 'loan_amount_rs' => (float) ($inputs['loan_amount_above2'] ?? 0),
            'effective_loan_principal_rs' => max(0, (float) ($inputs['loan_amount_above2'] ?? 0) - $subsidy), 'interest_pct' => (float) ($inputs['interest_rate_above2'] ?? 0),
            'tenure_years' => $loanTenureYears, 'emi_rs' => 0, 'residual_bill_rs' => 0,
            'net_own_investment_after_subsidy' => max(0, (float) ($inputs['margin_money_above2'] ?? 0) - $subsidy),
            'subsidy_credit_month' => 12,
        ],
        'loan_above_2_lacs_subsidy_not_to_loan' => [
            'price' => $systemCostAbove2, 'subsidy' => $subsidy, 'gross_payable' => $systemCostAbove2,
            'net_investment_after_subsidy' => max(0, $systemCostAbove2 - $subsidy), 'monthly_outflow' => 0, 'payback' => 0,
            'applicable' => $higherLoanApplicable && (($systemCostAbove2 * 0.8) >= 200000),
            'margin_money_rs' => (float) ($inputs['margin_money_above2'] ?? 0), 'loan_amount_rs' => (float) ($inputs['loan_amount_above2'] ?? 0),
            'effective_loan_principal_rs' => max(0, (float) ($inputs['loan_amount_above2'] ?? 0)), 'interest_pct' => (float) ($inputs['interest_rate_above2'] ?? 0),
            'tenure_years' => $loanTenureYears, 'emi_rs' => 0, 'residual_bill_rs' => 0,
            'net_own_investment_after_subsidy' => max(0, (float) ($inputs['margin_money_above2'] ?? 0) - $subsidy),
            'subsidy_credit_month' => 12,
        ],
        'loan_upto_2_lacs' => [
            'price' => $systemCostUp2, 'subsidy' => $subsidy, 'gross_payable' => $systemCostUp2, 'applicable' => true,
            'margin_money_rs' => (float) ($inputs['margin_money_up2'] ?? 0), 'loan_amount_rs' => (float) ($inputs['loan_amount_up2'] ?? 0),
            'effective_loan_principal_rs' => max(0, (float) ($inputs['loan_amount_up2'] ?? 0) - $subsidy), 'interest_pct' => (float) ($inputs['interest_rate_up2'] ?? 0),
            'tenure_years' => $loanTenureYears,
        ],
        'loan_above_2_lacs' => [
            'price' => $systemCostAbove2, 'subsidy' => $subsidy, 'gross_payable' => $systemCostAbove2,
            'applicable' => $higherLoanApplicable && (($systemCostAbove2 * 0.8) >= 200000),
            'margin_money_rs' => (float) ($inputs['margin_money_above2'] ?? 0), 'loan_amount_rs' => (float) ($inputs['loan_amount_above2'] ?? 0),
            'effective_loan_principal_rs' => max(0, (float) ($inputs['loan_amount_above2'] ?? 0) - $subsidy), 'interest_pct' => (float) ($inputs['interest_rate_above2'] ?? 0),
            'tenure_years' => $loanTenureYears,
        ],
    ];

    $quoteDefaults = documents_get_quote_defaults_settings();
    $quote['calc'] = documents_calc_quote_pricing_with_tax_profile($quote, 0.0, $subsidy, $selectedSystemPrice, $quoteDefaults);
    $quote['tax_breakdown'] = is_array($quote['calc']['tax_breakdown'] ?? null)
        ? (array) $quote['calc']['tax_breakdown']
        : ['basic_total' => 0, 'gst_total' => 0, 'gross_incl_gst' => 0, 'slabs' => []];
    $quote = solar_finance_apply_template_snapshot_to_quote($quote, $isCreate);

    $saved = documents_save_quote($quote);
    if (!($saved['ok'] ?? false)) {
        return ['success' => false, 'action' => 'failed', 'message' => (string) ($saved['error'] ?? 'Unable to save quotation.')];
    }

    return [
        'success' => true,
        'action' => $isCreate ? 'created' : 'updated',
        'quote_id' => (string) ($quote['id'] ?? ''),
        'quote_no' => (string) ($quote['quote_no'] ?? ''),
        'scenario' => $quote['auto_sync_scenario'],
    ];
}
