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

    $inputSnapshot = is_array($payload['inputs'] ?? null) ? $payload['inputs'] : [];
    $resultSnapshot = is_array($payload['results'] ?? null) ? $payload['results'] : [];
    $normalizedScenarios = solar_finance_build_normalized_scenario_snapshot($inputSnapshot, $resultSnapshot);
    if (!is_array($resultSnapshot['finance'] ?? null)) {
        $resultSnapshot['finance'] = [];
    }
    $resultSnapshot['finance'] = array_merge($resultSnapshot['finance'], $normalizedScenarios);
    $resultSnapshot['normalized_finance_scenarios'] = $normalizedScenarios;

    $record = [
        'report_id' => solar_finance_generate_report_id(),
        'public_token' => solar_finance_generate_report_token(),
        'customer_name' => trim((string) ($payload['customer']['name'] ?? '')),
        'location' => trim((string) ($payload['customer']['location'] ?? '')),
        'mobile' => solar_finance_normalize_mobile((string) ($payload['customer']['mobile_normalized'] ?? ($payload['customer']['mobile_raw'] ?? ''))),
        'input_snapshot' => $inputSnapshot,
        'result_snapshot' => $resultSnapshot,
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

function solar_finance_supported_scenario_labels(): array
{
    return [
        'self_funded' => 'Self Funded',
        'loan_upto_2_lacs_subsidy_to_loan' => 'Loan up to ₹2 lacs (subsidy to loan)',
        'loan_upto_2_lacs_subsidy_not_to_loan' => 'Loan up to ₹2 lacs (subsidy self kept)',
        'loan_above_2_lacs_subsidy_to_loan' => 'Loan above ₹2 lacs (subsidy to loan)',
        'loan_above_2_lacs_subsidy_not_to_loan' => 'Loan above ₹2 lacs (subsidy self kept)',
    ];
}

function solar_finance_format_payback_from_months(int $months): string
{
    if ($months <= 0) {
        return '—';
    }
    if ($months > 25 * 12) {
        return 'Not within 25 years';
    }
    $years = intdiv($months, 12);
    $remainingMonths = $months % 12;
    if ($years <= 0) {
        return $remainingMonths . ' month' . ($remainingMonths === 1 ? '' : 's');
    }
    if ($remainingMonths <= 0) {
        return $years . ' year' . ($years === 1 ? '' : 's');
    }
    return $years . ' year' . ($years === 1 ? '' : 's') . ' ' . $remainingMonths . ' month' . ($remainingMonths === 1 ? '' : 's');
}

function solar_finance_build_normalized_scenario_snapshot(array $inputs, array $results = []): array
{
    $scenarioLabels = solar_finance_supported_scenario_labels();
    $num = static function ($value, float $fallback = 0.0): float {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $fallback;
        }
        return (float) $value;
    };
    $toInt = static function ($value, int $fallback = 0): int {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $fallback;
        }
        return (int) round((float) $value);
    };
    $emi = static function (float $principal, float $interestPct, int $tenureMonths): float {
        if ($principal <= 0 || $tenureMonths <= 0) {
            return 0.0;
        }
        $monthlyRate = ($interestPct / 100) / 12;
        if ($monthlyRate <= 0) {
            return $principal / $tenureMonths;
        }
        $pow = pow(1 + $monthlyRate, $tenureMonths);
        return ($principal * $monthlyRate * $pow) / max(0.000001, $pow - 1);
    };
    $calculatePayback = static function (float $initialSolarCost, float $monthlyOutflowLoan, float $noSolarMonthlyBill): int {
        if ($noSolarMonthlyBill <= 0) {
            return 0;
        }
        $cumSolar = max(0, $initialSolarCost);
        $cumNoSolar = 0.0;
        $horizon = 25 * 12;
        for ($month = 1; $month <= $horizon; $month++) {
            $cumSolar += max(0, $monthlyOutflowLoan);
            $cumNoSolar += max(0, $noSolarMonthlyBill);
            if ($cumSolar <= $cumNoSolar) {
                return $month;
            }
        }
        return $horizon + 1;
    };

    $monthlyBill = max(0, $num($inputs['monthly_bill'] ?? 0));
    $unitRate = max(0, $num($inputs['unit_rate'] ?? 0));
    $dailyGeneration = max(0, $num($inputs['daily_generation_per_kw'] ?? 0));
    $solarSize = max(0, $num($inputs['solar_size_kw'] ?? 0));
    $monthlySolarUnits = max(0, $solarSize * $dailyGeneration * 30);
    $residualBill = max(0, $monthlyBill - ($monthlySolarUnits * $unitRate));
    $subsidy = max(0, $num($inputs['subsidy'] ?? 0));
    $tenureYears = max(0, $num($inputs['loan_tenure_years'] ?? 10));
    $tenureMonths = max(1, (int) round($tenureYears * 12));
    $interestUp2 = max(0, $num($inputs['interest_rate_up2'] ?? 0));
    $interestAbove2 = max(0, $num($inputs['interest_rate_above2'] ?? 0));
    $higherLoanApplicable = (bool) ($inputs['higher_loan_applicable'] ?? false);

    $priceSelf = max(0, $num($inputs['system_cost_self'] ?? ($inputs['system_cost_self_funded'] ?? 0)));
    $priceUp2 = max(0, $num($inputs['system_cost_up2'] ?? 0));
    $priceAbove2 = max(0, $num($inputs['system_cost_above2'] ?? 0));
    $priceSelf = $priceSelf > 0 ? $priceSelf : $priceUp2;
    $isAbove2Applicable = $higherLoanApplicable && $priceAbove2 > 0 && (($priceAbove2 * 0.8) >= 200000);

    $marginUp2 = max(0, $num($inputs['margin_money_up2'] ?? 0));
    $loanUp2 = max(0, $num($inputs['loan_amount_up2'] ?? 0));
    if ($priceUp2 > 0 && $loanUp2 <= 0) {
        $loanUp2 = min(200000, round($priceUp2 * 0.9));
    }
    if ($priceUp2 > 0 && $marginUp2 <= 0) {
        $marginUp2 = max($priceUp2 - $loanUp2, round($priceUp2 * 0.1));
    }
    $marginAbove2 = max(0, $num($inputs['margin_money_above2'] ?? 0));
    $loanAbove2 = max(0, $num($inputs['loan_amount_above2'] ?? 0));
    if ($isAbove2Applicable && $loanAbove2 <= 0) {
        $loanAbove2 = round($priceAbove2 * 0.8);
    }
    if ($isAbove2Applicable && $marginAbove2 <= 0) {
        $marginAbove2 = max($priceAbove2 - $loanAbove2, round($priceAbove2 * 0.2));
    }

    $scenarioInputs = [
        'self_funded' => ['price' => $priceSelf, 'loan' => 0.0, 'margin' => 0.0, 'interest' => 0.0, 'applicable' => true, 'subsidy_to_loan' => false],
        'loan_upto_2_lacs_subsidy_to_loan' => ['price' => $priceUp2, 'loan' => $loanUp2, 'margin' => $marginUp2, 'interest' => $interestUp2, 'applicable' => true, 'subsidy_to_loan' => true],
        'loan_upto_2_lacs_subsidy_not_to_loan' => ['price' => $priceUp2, 'loan' => $loanUp2, 'margin' => $marginUp2, 'interest' => $interestUp2, 'applicable' => true, 'subsidy_to_loan' => false],
        'loan_above_2_lacs_subsidy_to_loan' => ['price' => $priceAbove2, 'loan' => $loanAbove2, 'margin' => $marginAbove2, 'interest' => $interestAbove2, 'applicable' => $isAbove2Applicable, 'subsidy_to_loan' => true],
        'loan_above_2_lacs_subsidy_not_to_loan' => ['price' => $priceAbove2, 'loan' => $loanAbove2, 'margin' => $marginAbove2, 'interest' => $interestAbove2, 'applicable' => $isAbove2Applicable, 'subsidy_to_loan' => false],
    ];

    $normalized = [];
    foreach ($scenarioInputs as $key => $row) {
        $isSelf = $key === 'self_funded';
        $price = max(0, (float) $row['price']);
        $loanAmount = $isSelf ? 0.0 : max(0, (float) $row['loan']);
        $marginMoney = $isSelf ? 0.0 : max(0, (float) $row['margin']);
        $interestPct = $isSelf ? 0.0 : max(0, (float) $row['interest']);
        $subsidyToLoan = !$isSelf && !empty($row['subsidy_to_loan']);
        $effectivePrincipal = 0.0;
        $initialInvestment = max(0, $price - $subsidy);
        if (!$isSelf) {
            if ($subsidyToLoan) {
                $effectivePrincipal = max(0, $loanAmount - $subsidy);
                $initialInvestment = $marginMoney;
            } else {
                $remainingSubsidy = max(0, $subsidy - $marginMoney);
                $effectivePrincipal = max(0, $loanAmount - $remainingSubsidy);
                $initialInvestment = max(0, $marginMoney - $subsidy);
            }
        }
        $emiRs = $isSelf ? 0.0 : $emi($effectivePrincipal, $interestPct, $tenureMonths);
        $monthlyOutflow = $isSelf ? $residualBill : ($emiRs + $residualBill);
        $paybackMonths = $calculatePayback($initialInvestment, $monthlyOutflow, $monthlyBill);
        $cumulative = [];
        for ($year = 0; $year <= 25; $year++) {
            $months = $year * 12;
            if ($isSelf) {
                $cumulative[] = max(0, $price - $subsidy) + ($months * $residualBill);
                continue;
            }
            $monthsWithinTenure = min($months, $tenureMonths);
            $monthsAfterTenure = max(0, $months - $tenureMonths);
            $cumulative[] = $initialInvestment + ($monthsWithinTenure * ($emiRs + $residualBill)) + ($monthsAfterTenure * $residualBill);
        }
        $normalized[$key] = [
            'label' => $scenarioLabels[$key] ?? $key,
            'applicable' => (bool) ($row['applicable'] ?? true),
            'price' => $price,
            'subsidy' => $subsidy,
            'margin_money_rs' => $marginMoney,
            'initial_investment_after_subsidy_credit_rs' => $initialInvestment,
            'loan_amount_rs' => $loanAmount,
            'effective_loan_principal_rs' => $effectivePrincipal,
            'interest_pct' => $interestPct,
            'tenure_years' => $tenureMonths / 12,
            'tenure_months' => $tenureMonths,
            'emi_rs' => $emiRs,
            'residual_bill_rs' => $residualBill,
            'monthly_outflow_rs' => $monthlyOutflow,
            'payback_months' => $paybackMonths,
            'payback_display' => solar_finance_format_payback_from_months($paybackMonths),
            'cumulative_series' => $cumulative,
            'net_investment_after_subsidy' => max(0, $price - $subsidy),
            'net_own_investment_after_subsidy' => $initialInvestment,
        ];
    }

    foreach ($scenarioLabels as $scenarioKey => $scenarioLabel) {
        if (!isset($normalized[$scenarioKey])) {
            $normalized[$scenarioKey] = [
                'label' => $scenarioLabel,
                'applicable' => false,
                'price' => 0.0,
                'subsidy' => $subsidy,
                'margin_money_rs' => 0.0,
                'initial_investment_after_subsidy_credit_rs' => 0.0,
                'loan_amount_rs' => 0.0,
                'effective_loan_principal_rs' => 0.0,
                'interest_pct' => 0.0,
                'tenure_years' => $tenureMonths / 12,
                'tenure_months' => $tenureMonths,
                'emi_rs' => 0.0,
                'residual_bill_rs' => $residualBill,
                'monthly_outflow_rs' => $residualBill,
                'payback_months' => 0,
                'payback_display' => '—',
                'cumulative_series' => array_fill(0, 26, 0.0),
                'net_investment_after_subsidy' => 0.0,
                'net_own_investment_after_subsidy' => 0.0,
            ];
        }
    }

    return $normalized;
}

function solar_finance_normalize_for_quote_render(array $quote, array $calc, array $snapshot): array
{
    $toFloat = static function ($value, float $fallback = 0.0): float {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $fallback;
        }
        return (float) $value;
    };
    $toInt = static function ($value, int $fallback = 0): int {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $fallback;
        }
        return (int) round((float) $value);
    };
    $formatPaybackMonths = static function (int $months): string {
        if ($months <= 0) {
            return '—';
        }
        if ($months > 25 * 12) {
            return 'Not within 25 years';
        }
        $years = intdiv($months, 12);
        $remainingMonths = $months % 12;
        if ($years <= 0) {
            return $remainingMonths . ' month' . ($remainingMonths === 1 ? '' : 's');
        }
        if ($remainingMonths <= 0) {
            return $years . ' year' . ($years === 1 ? '' : 's');
        }
        return $years . ' year' . ($years === 1 ? '' : 's') . ' ' . $remainingMonths . ' month' . ($remainingMonths === 1 ? '' : 's');
    };
    $calculatePaybackMonths = static function (float $initialSolarCost, callable $solarMonthlyOutflowResolver, float $noSolarMonthlyBill): int {
        if ($noSolarMonthlyBill <= 0) {
            return 0;
        }
        $cumSolar = max(0, $initialSolarCost);
        $cumNoSolar = 0.0;
        $horizon = 25 * 12;
        for ($month = 1; $month <= $horizon; $month++) {
            $cumSolar += max(0, (float) $solarMonthlyOutflowResolver($month));
            $cumNoSolar += max(0, $noSolarMonthlyBill);
            if ($cumSolar <= $cumNoSolar) {
                return $month;
            }
        }
        return $horizon + 1;
    };

    $supportedPrimary = array_merge(array_keys(solar_finance_supported_scenario_labels()), ['loan_upto_2_lacs', 'loan_above_2_lacs']);
    $primaryScenario = (string) ($quote['primary_finance_scenario'] ?? 'loan_upto_2_lacs_subsidy_to_loan');
    if (!in_array($primaryScenario, $supportedPrimary, true)) {
        $primaryScenario = 'loan_upto_2_lacs_subsidy_to_loan';
    }

    $scenarioPrices = is_array($quote['scenario_prices'] ?? null) ? $quote['scenario_prices'] : [];
    $financeScenariosRaw = is_array($quote['finance_scenarios'] ?? null) ? $quote['finance_scenarios'] : [];
    $grossFallback = max(0, $toFloat($calc['gross_payable'] ?? ($calc['gross_payable_before_discount'] ?? 0)));
    $subsidy = max(0, $toFloat($calc['subsidy_expected_rs'] ?? 0));
    $capacity = max(0, $toFloat($quote['capacity_kwp'] ?? $quote['system_capacity_kwp'] ?? 0));
    $tariff = max(0.1, $toFloat($snapshot['unit_rate_rs_per_kwh'] ?? null, 1));
    $annualGeneration = max(0, $toFloat($snapshot['annual_generation_kwh_per_kw'] ?? null, 0));
    $monthlyUnitsSolar = ($capacity * $annualGeneration) / 12;
    $noSolarMonthlyBill = max(0, $toFloat($snapshot['monthly_bill_before_rs'] ?? null, $toFloat($quote['finance_inputs']['monthly_bill_rs'] ?? 0)));
    $residualBill = max(0, $noSolarMonthlyBill - ($monthlyUnitsSolar * $tariff));
    $monthlyUnitsBefore = $noSolarMonthlyBill > 0 ? ($noSolarMonthlyBill / $tariff) : 0;
    $legacyUp2 = is_array($financeScenariosRaw['loan_upto_2_lacs'] ?? null) ? $financeScenariosRaw['loan_upto_2_lacs'] : [];
    $legacyAbove2 = is_array($financeScenariosRaw['loan_above_2_lacs'] ?? null) ? $financeScenariosRaw['loan_above_2_lacs'] : [];

    $resolvedScenarios = [];
    foreach (solar_finance_supported_scenario_labels() as $scenarioKey => $scenarioLabel) {
        $row = is_array($financeScenariosRaw[$scenarioKey] ?? null) ? $financeScenariosRaw[$scenarioKey] : [];
        if ($row === [] && str_contains($scenarioKey, 'loan_upto_2_lacs')) {
            $row = $legacyUp2;
        }
        if ($row === [] && str_contains($scenarioKey, 'loan_above_2_lacs')) {
            $row = $legacyAbove2;
        }
        $isSelfFunded = $scenarioKey === 'self_funded';
        $isSubsidyNotToLoan = str_contains($scenarioKey, 'subsidy_not_to_loan');
        $isAbove2 = str_contains($scenarioKey, 'loan_above_2_lacs');
        $priceFallback = $scenarioPrices[$scenarioKey]['price']
            ?? ($isAbove2 ? ($scenarioPrices['loan_above_2_lacs']['price'] ?? 0) : ($scenarioPrices['loan_upto_2_lacs']['price'] ?? 0));
        if ($isSelfFunded) {
            $priceFallback = $scenarioPrices['self_funded']['price'] ?? $grossFallback;
        }
        $price = max(0, $toFloat($row['price'] ?? $priceFallback ?? $grossFallback));
        $scenarioSubsidy = max(0, $toFloat($row['subsidy'] ?? $subsidy));
        $interestPct = max(0, $toFloat($row['interest_pct'] ?? $snapshot['loan_interest_rate_percent'] ?? 0));
        $tenureMonths = max(1, $toInt($row['tenure_months'] ?? null, (int) round(max(0, $toFloat($row['tenure_years'] ?? null, 10)) * 12)));
        $tenureYears = $tenureMonths / 12;
        $marginMoney = max(0, $toFloat($row['margin_money_rs'] ?? 0));
        $loanAmount = max(0, $toFloat($row['loan_amount_rs'] ?? 0));
        $scenarioResidual = max(0, $toFloat($row['residual_bill_rs'] ?? $residualBill));
        $applicable = $isAbove2 ? (bool) ($row['applicable'] ?? ($legacyAbove2['applicable'] ?? false)) : (bool) ($row['applicable'] ?? true);
        $effectivePrincipal = 0.0;
        $initialInvestment = max(0, $price - $scenarioSubsidy);
        $emi = 0.0;
        if (!$isSelfFunded) {
            if ($marginMoney <= 0 && $loanAmount <= 0) {
                $loanAmount = max(0, $price * 0.8);
                $marginMoney = max(0, $price - $loanAmount);
            }
            if ($isSubsidyNotToLoan) {
                $remainingSubsidy = max(0, $scenarioSubsidy - $marginMoney);
                $effectivePrincipal = max(0, $loanAmount - $remainingSubsidy);
                $initialInvestment = max(0, $marginMoney - $scenarioSubsidy);
            } else {
                $effectivePrincipal = max(0, $toFloat($row['effective_loan_principal_rs'] ?? ($loanAmount - $scenarioSubsidy)));
                $initialInvestment = $marginMoney;
            }
            $emi = max(0, $toFloat($row['emi_rs'] ?? 0));
            if ($emi <= 0 && $effectivePrincipal > 0) {
                $monthlyRate = ($interestPct / 100) / 12;
                $emi = $monthlyRate <= 0
                    ? ($effectivePrincipal / max(1, $tenureMonths))
                    : (($effectivePrincipal * $monthlyRate * pow(1 + $monthlyRate, $tenureMonths)) / max(0.000001, pow(1 + $monthlyRate, $tenureMonths) - 1));
            }
        }
        $monthlyOutflow = $isSelfFunded ? $scenarioResidual : ($emi + $scenarioResidual);
        $paybackMonths = $calculatePaybackMonths($isSelfFunded ? max(0, $price - $scenarioSubsidy) : $initialInvestment, static function (int $month) use ($isSelfFunded, $emi, $scenarioResidual, $tenureMonths): float {
            if ($isSelfFunded) {
                return $scenarioResidual;
            }
            return ($month <= $tenureMonths ? $emi : 0.0) + $scenarioResidual;
        }, $noSolarMonthlyBill);
        $cumulativeSeries = [];
        for ($year = 0; $year <= 25; $year++) {
            $months = $year * 12;
            if ($isSelfFunded) {
                $cumulativeSeries[] = max(0, $price - $scenarioSubsidy) + ($months * $scenarioResidual);
            } else {
                $monthsWithinTenure = min($months, $tenureMonths);
                $monthsAfterTenure = max(0, $months - $tenureMonths);
                $cumulativeSeries[] = $initialInvestment + ($monthsWithinTenure * ($emi + $scenarioResidual)) + ($monthsAfterTenure * $scenarioResidual);
            }
        }
        $resolvedScenarios[$scenarioKey] = [
            'label' => $scenarioLabel,
            'applicable' => $applicable,
            'price' => $price,
            'subsidy' => $scenarioSubsidy,
            'margin_money_rs' => $isSelfFunded ? 0 : $marginMoney,
            'initial_investment_after_subsidy_credit_rs' => $isSelfFunded ? max(0, $price - $scenarioSubsidy) : $initialInvestment,
            'loan_amount_rs' => $isSelfFunded ? 0 : $loanAmount,
            'effective_loan_principal_rs' => $effectivePrincipal,
            'interest_pct' => $interestPct,
            'tenure_years' => $tenureYears,
            'tenure_months' => $tenureMonths,
            'emi_rs' => $emi,
            'residual_bill_rs' => $scenarioResidual,
            'monthly_outflow_rs' => $monthlyOutflow,
            'payback_months' => $paybackMonths,
            'payback_display' => $formatPaybackMonths($paybackMonths),
            'cumulative_series' => $cumulativeSeries,
            'is_primary' => $primaryScenario === $scenarioKey,
            'net_investment_after_subsidy' => $isSelfFunded ? max(0, $price - $scenarioSubsidy) : max(0, $price - $scenarioSubsidy),
            'net_own_investment_after_subsidy' => $isSelfFunded ? max(0, $price - $scenarioSubsidy) : $initialInvestment,
        ];
    }

    return [
        'primary_finance_scenario' => $primaryScenario,
        'gross' => (float) ($resolvedScenarios[$primaryScenario]['price'] ?? $grossFallback),
        'subsidy' => $subsidy,
        'monthly_bill_before_rs' => $noSolarMonthlyBill,
        'no_solar_monthly_bill_rs' => $noSolarMonthlyBill,
        'monthly_units_before' => $monthlyUnitsBefore,
        'unit_rate_rs_per_kwh' => $tariff,
        'annual_generation_kwh_per_kw' => $annualGeneration,
        'capacity_kwp' => $capacity,
        'monthly_units_solar' => $monthlyUnitsSolar,
        'residual_bill' => (float) ($resolvedScenarios['self_funded']['residual_bill_rs'] ?? $residualBill),
        'finance_scenarios' => $resolvedScenarios,
        'scenario_prices' => $scenarioPrices,
    ];
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
    $systemCostSelf = max(0, (float) ($inputs['system_cost_self'] ?? ($inputs['system_cost_self_funded'] ?? 0)));
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
    $marginUp2 = max(0, (float) ($inputs['margin_money_up2'] ?? 0));
    $loanUp2 = max(0, (float) ($inputs['loan_amount_up2'] ?? 0));
    $marginAbove2 = max(0, (float) ($inputs['margin_money_above2'] ?? 0));
    $loanAbove2 = max(0, (float) ($inputs['loan_amount_above2'] ?? 0));
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
            'quote_view_url' => solar_finance_quote_public_view_url($existing),
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
    if (safe_text((string) ($quote['public_share_token'] ?? '')) === '') {
        $quote['public_share_token'] = documents_generate_quote_public_share_token();
        $quote['public_share_created_at'] = date('c');
    }
    $quote['public_share_enabled'] = true;
    $quote['public_share_revoked_at'] = null;
    $quote['auto_sync_scenario'] = $useAbove2Scenario ? 'loan_above_2_lacs_subsidy_to_loan' : 'loan_upto_2_lacs_subsidy_to_loan';
    $quote['primary_finance_scenario'] = $quote['auto_sync_scenario'];
    $quote['scenario_prices'] = [
        'self_funded' => ['price' => $systemCostSelf > 0 ? $systemCostSelf : $systemCostUp2],
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
    $quote['finance_scenarios'] = solar_finance_build_normalized_scenario_snapshot($inputs, is_array($payload['results'] ?? null) ? $payload['results'] : []);
    $quote['finance_scenarios']['loan_upto_2_lacs'] = $quote['finance_scenarios']['loan_upto_2_lacs_subsidy_to_loan'] ?? [];
    $quote['finance_scenarios']['loan_above_2_lacs'] = $quote['finance_scenarios']['loan_above_2_lacs_subsidy_to_loan'] ?? [];
    $normalizedFinance = solar_finance_normalize_for_quote_render($quote, ['gross_payable' => $selectedSystemPrice, 'subsidy_expected_rs' => $subsidy], [
        'monthly_bill_before_rs' => $monthlyBill,
        'unit_rate_rs_per_kwh' => $unitRate,
        'annual_generation_kwh_per_kw' => $annualGenerationPerKw,
    ]);
    $quote['finance_scenarios'] = is_array($normalizedFinance['finance_scenarios'] ?? null)
        ? $normalizedFinance['finance_scenarios']
        : $quote['finance_scenarios'];

    $quoteDefaults = documents_get_quote_defaults_settings();
    $quote['calc'] = documents_calc_quote_pricing_with_tax_profile($quote, 0.0, $subsidy, $selectedSystemPrice, $quoteDefaults);
    $quote['tax_breakdown'] = is_array($quote['calc']['tax_breakdown'] ?? null)
        ? (array) $quote['calc']['tax_breakdown']
        : ['basic_total' => 0, 'gst_total' => 0, 'gross_incl_gst' => 0, 'slabs' => []];

    $templateContext = solar_finance_resolve_template_context((string) ($quote['template_set_id'] ?? ''));
    if (safe_text((string) ($templateContext['template_set_id'] ?? '')) === '') {
        return ['success' => false, 'action' => 'failed', 'message' => 'No active quotation template available.'];
    }
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
        'quote_view_url' => solar_finance_quote_public_view_url($quote),
    ];
}

function solar_finance_quote_public_view_url(array $quote): string
{
    $token = safe_text((string) ($quote['public_share_token'] ?? ''));
    if ($token === '') {
        return '';
    }

    return '/quotation-public.php?t=' . urlencode($token);
}
