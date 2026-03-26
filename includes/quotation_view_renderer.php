<?php
declare(strict_types=1);

function quotation_format_inr(float $amount, int $decimals = 0, bool $forceDecimals = false): string
{
    $hasFraction = abs($amount - round($amount)) > 0.009;
    $fractionDigits = $forceDecimals || ($decimals > 0 && $hasFraction) ? $decimals : 0;
    return '₹' . number_format($amount, $fractionDigits, '.', ',');
}

function quotation_format_inr_indian(float $amount, bool $showDecimals = false): string
{
    $rounded = $showDecimals ? $amount : round($amount);
    $parts = explode('.', number_format($rounded, $showDecimals ? 2 : 0, '.', ''));
    $int = $parts[0];
    $last3 = substr($int, -3);
    $rest = substr($int, 0, -3);
    if ($rest !== '') {
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int = $rest . ',' . $last3;
    }
    return '₹' . $int . ($showDecimals && isset($parts[1]) ? '.' . $parts[1] : '');
}

function quotation_sanitize_html(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    if (strpos($raw, '<') === false) {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $bulletLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^([\-*•]|\d+[.)])\s+(.+)$/u', $line, $m)) {
                $bulletLines[] = '<li>' . htmlspecialchars($m[2], ENT_QUOTES) . '</li>';
            }
        }
        if ($bulletLines !== []) {
            return '<ul>' . implode('', $bulletLines) . '</ul>';
        }
        return nl2br(htmlspecialchars($raw, ENT_QUOTES));
    }

    $allow = '<p><br><b><strong><i><em><u><ul><ol><li><table><thead><tbody><tr><th><td><hr><h1><h2><h3><h4><blockquote><small><span><div>';
    $clean = strip_tags($raw, $allow);
    $clean = preg_replace('#<(script|iframe|style)[^>]*>.*?</\1>#is', '', (string) $clean) ?? '';
    $clean = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';
    $clean = preg_replace('/\s+(style|srcdoc)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';
    return $clean;
}

function quotation_normalize_whatsapp(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) < 10) {
        return '';
    }
    if (strlen($digits) > 12) {
        $digits = substr($digits, -12);
    }
    return $digits;
}


function quotation_format_display_date(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return '—';
    }
    return date('d M Y', $ts);
}

function quotation_shadow_css(string $preset): string
{
    $map = [
        'none' => 'none',
        'soft' => '0 8px 18px rgba(15, 23, 42, 0.06)',
        'medium' => '0 10px 24px rgba(15, 23, 42, 0.10)',
        'strong' => '0 14px 30px rgba(15, 23, 42, 0.16)',
    ];
    return $map[$preset] ?? $map['soft'];
}


function compute_financial_clarity(array $quote, array $calc, array $snapshot): array
{
    $toFloat = static function ($value, float $fallback = 0.0): float {
        if ($value === null) {
            return $fallback;
        }
        if (is_string($value) && trim($value) === '') {
            return $fallback;
        }
        return (float) $value;
    };

    $grossBeforeDiscount = $toFloat($calc['gross_payable_before_discount'] ?? 0);
    $discount = max(0, $toFloat($calc['discount_rs'] ?? 0));
    $grossFallback = max(0, $grossBeforeDiscount - $discount);
    $gross = $toFloat($calc['gross_payable'] ?? $grossFallback);
    $subsidy = $toFloat($calc['subsidy_expected_rs'] ?? 0);
    $capacity = max(0, $toFloat($quote['capacity_kwp'] ?? $quote['system_capacity_kwp'] ?? 0));
    $tariff = max(0.1, $toFloat($snapshot['unit_rate_rs_per_kwh'] ?? null, 1));
    $annualGeneration = max(0, $toFloat($snapshot['annual_generation_kwh_per_kw'] ?? null, 0));
    $monthlyUnitsSolar = ($capacity * $annualGeneration) / 12;

    $monthlyUnitsBefore = null;
    if (($snapshot['monthly_units_before'] ?? null) !== null && (float) $snapshot['monthly_units_before'] > 0) {
        $monthlyUnitsBefore = (float) $snapshot['monthly_units_before'];
    } elseif (($snapshot['monthly_bill_before_rs'] ?? null) !== null && (float) $snapshot['monthly_bill_before_rs'] > 0) {
        $monthlyUnitsBefore = (float) $snapshot['monthly_bill_before_rs'] / $tariff;
    }

    if ($monthlyUnitsBefore === null) {
        $fallbackBill = $toFloat($quote['finance_inputs']['monthly_bill_rs'] ?? 0);
        $monthlyUnitsBefore = $fallbackBill > 0 ? ($fallbackBill / $tariff) : 0.0;
    }
    $residualUnits = max(0, $monthlyUnitsBefore - $monthlyUnitsSolar);
    $residualBill = $residualUnits * $tariff;

    $marginAmount = ($snapshot['margin_amount_rs'] ?? null) !== null ? max(0, (float) $snapshot['margin_amount_rs']) : null;
    if ($marginAmount === null) {
        $marginPct = max(0, $toFloat($snapshot['margin_rule_percent'] ?? null, 10));
        $marginAmount = ($marginPct / 100) * $gross;
    }

    $loanEligible = max(0, $gross - $marginAmount);
    $loanCap = max(0, $toFloat($snapshot['loan_cap_rs'] ?? null, $loanEligible));
    if ($loanCap > 0 && $loanEligible > $loanCap) {
        $loanEligible = $loanCap;
        $marginAmount = max(0, $gross - $loanEligible);
    }

    $effectivePrincipal = max(0, $loanEligible - $subsidy);
    $interestPct = max(0, $toFloat($snapshot['loan_interest_rate_percent'] ?? null, 0));
    $tenureMonths = max(1, (int) round($toFloat($snapshot['loan_tenure_months'] ?? null, 120)));
    $monthlyRate = ($interestPct / 100) / 12;
    if ($effectivePrincipal <= 0) {
        $emi = 0.0;
    } elseif ($monthlyRate <= 0) {
        $emi = $effectivePrincipal / $tenureMonths;
    } else {
        $pow = pow(1 + $monthlyRate, $tenureMonths);
        $emi = ($effectivePrincipal * $monthlyRate * $pow) / max(0.000001, $pow - 1);
    }

    return [
        'gross' => $gross,
        'subsidy' => $subsidy,
        'monthly_bill_before_rs' => $toFloat($snapshot['monthly_bill_before_rs'] ?? 0),
        'monthly_units_before' => $monthlyUnitsBefore,
        'unit_rate_rs_per_kwh' => $tariff,
        'annual_generation_kwh_per_kw' => $annualGeneration,
        'capacity_kwp' => $capacity,
        'monthly_units_solar' => $monthlyUnitsSolar,
        'residual_bill' => $residualBill,
        'margin_amount_rs' => $marginAmount,
        'loan_eligible_rs' => $loanEligible,
        'loan_cap_rs' => $loanCap,
        'loan_interest_rate_percent' => $interestPct,
        'loan_tenure_months' => $tenureMonths,
        'effective_principal_rs' => $effectivePrincipal,
        'emi_rs' => $emi,
        'loan_total_outflow_rs' => $emi + $residualBill,
        'self_upfront_rs' => $gross,
        'self_upfront_net_rs' => max(0, $gross - $subsidy),
        'self_residual_bill_rs' => $residualBill,
    ];
}

function quotation_render(array $quote, array $quoteDefaults, array $company, bool $showAdmin = false, string $shareUrl = '', string $viewerType = 'admin', string $viewerId = ''): void
{
    ini_set('display_errors', '0');
    ob_start();

    $segment = (string) ($quote['segment'] ?? 'RES');
    $segmentDefaults = is_array($quoteDefaults['segments'][$segment] ?? null) ? $quoteDefaults['segments'][$segment] : [];
    $transport = (float) ($quote['calc']['transportation_rs'] ?? $quote['finance_inputs']['transportation_rs'] ?? 0);
    $subsidy = (float) ($quote['calc']['subsidy_expected_rs'] ?? $quote['finance_inputs']['subsidy_expected_rs'] ?? 0);
    $calc = is_array($quote['calc'] ?? null) && !empty($quote['calc'])
        ? (array) $quote['calc']
        : documents_calc_quote_pricing_with_tax_profile($quote, $transport, $subsidy, (float) ($quote['input_total_gst_inclusive'] ?? 0), $quoteDefaults);
    $ann = is_array($quote['annexures_overrides'] ?? null) ? $quote['annexures_overrides'] : [];
    $templateSetId = safe_text((string) ($quote['template_set_id'] ?? ''));
    if ($templateSetId !== '') {
        $templateBlocks = documents_get_template_blocks();
        $templateAnnex = documents_quote_annexure_from_template($templateBlocks, $templateSetId);
        foreach ($templateAnnex as $annKey => $annValue) {
            if (trim((string) ($ann[$annKey] ?? '')) === '' && trim((string) $annValue) !== '') {
                $ann[$annKey] = (string) $annValue;
            }
        }
    }

    $quoteItems = documents_normalize_quote_structured_items(is_array($quote['quote_items'] ?? null) ? $quote['quote_items'] : []);
    $itemRows = [];
    foreach ($quoteItems as $quoteItem) {
        if (!is_array($quoteItem)) {
            continue;
        }
        $lineType = (string) ($quoteItem['type'] ?? 'component');
        $qty = (float) ($quoteItem['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        if ($lineType === 'kit') {
            $kit = documents_inventory_get_kit((string) ($quoteItem['kit_id'] ?? ''));
            $kitName = safe_text((string) ($quoteItem['name_snapshot'] ?? ''));
            if ($kitName === '') {
                $kitName = safe_text((string) ($kit['name'] ?? 'Kit'));
            }
            $description = safe_multiline_text((string) ($quoteItem['master_description_snapshot'] ?? $quoteItem['description_snapshot'] ?? ''));
            if ($description === '') {
                $description = safe_text((string) ($kit['description'] ?? ''));
            }
            $itemRows[] = [
                'name' => ($kitName !== '' ? $kitName : 'Kit'),
                'description' => $description,
                'custom_description' => safe_multiline_text((string) ($quoteItem['custom_description'] ?? '')),
                'hsn' => safe_text((string) ($quoteItem['hsn_snapshot'] ?? '')) ?: (safe_text((string) ($quoteDefaults['defaults']['hsn_solar'] ?? '8541')) ?: '8541'),
                'qty' => $qty,
                'unit' => safe_text((string) ($quoteItem['unit'] ?? '')) ?: 'set',
            ];
            continue;
        }

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
        $description = safe_multiline_text((string) ($quoteItem['master_description_snapshot'] ?? $quoteItem['description_snapshot'] ?? ''));
        if ($description === '') {
            $description = safe_text((string) ($component['description'] ?? ''));
            if ($description === '') {
                $description = safe_text((string) ($component['notes'] ?? ''));
            }
        }
        $itemRows[] = [
            'name' => $name,
            'description' => $description,
            'hsn' => safe_text((string) ($quoteItem['hsn_snapshot'] ?? '')) ?: (safe_text((string) ($component['hsn'] ?? '')) ?: (safe_text((string) ($quoteDefaults['defaults']['hsn_solar'] ?? '8541')) ?: '8541')),
            'qty' => $qty,
            'unit' => safe_text((string) ($quoteItem['unit'] ?? '')) ?: safe_text((string) ($component['default_unit'] ?? '')),
        ];
    }

    $coverNoteSnapshot = trim((string) ($quote['cover_notes_html_snapshot'] ?? ''));
    if ($coverNoteSnapshot === '') {
        $coverNoteSnapshot = trim((string) ($ann['cover_notes'] ?? ''));
    }
    $coverNoteLiveTemplate = '';
    if ($coverNoteSnapshot === '' && $templateSetId !== '') {
        $templateBlocks = documents_get_template_blocks();
        $templateEntry = $templateBlocks[$templateSetId] ?? null;
        if (is_array($templateEntry) && is_array($templateEntry['blocks'] ?? null)) {
            $coverNoteLiveTemplate = trim((string) ($templateEntry['blocks']['cover_notes'] ?? ''));
        }
    }
    $coverNote = $coverNoteSnapshot
        ?: $coverNoteLiveTemplate
        ?: trim((string) ($quoteDefaults['defaults']['cover_note_template'] ?? ''));
    $specialReq = trim((string) ($quote['special_requests_text'] ?? $quote['special_requests_inclusive'] ?? ''));

    $tokens = is_array($quoteDefaults['global']['ui_tokens'] ?? null) ? $quoteDefaults['global']['ui_tokens'] : [];
    $colors = is_array($tokens['colors'] ?? null) ? $tokens['colors'] : [];
    $grads = is_array($tokens['gradients'] ?? null) ? $tokens['gradients'] : [];
    $headerFooter = is_array($tokens['header_footer'] ?? null) ? $tokens['header_footer'] : [];
    $typo = is_array($tokens['typography'] ?? null) ? $tokens['typography'] : [];

    $showDecimals = !empty($quoteDefaults['global']['quotation_ui']['show_decimals']);

    $savingsSnapshot = documents_quote_resolve_customer_savings_inputs($quote, $quoteDefaults);
    $financialClarity = compute_financial_clarity($quote, $calc, $savingsSnapshot);

    $annualGeneration = (float) ($financialClarity['annual_generation_kwh_per_kw'] ?? 0);
    $unitRate = (float) ($financialClarity['unit_rate_rs_per_kwh'] ?? 0);
    $loanInterest = (float) ($financialClarity['loan_interest_rate_percent'] ?? 0);
    $loanTenureMonths = (int) ($financialClarity['loan_tenure_months'] ?? 120);
    $emissionFactor = (float) ($quoteDefaults['global']['energy_defaults']['emission_factor_kg_per_kwh'] ?? 0.82);
    $treeAbsorption = (float) ($quoteDefaults['global']['energy_defaults']['tree_absorption_kg_per_tree_per_year'] ?? 20);

    $rawSubsidyInput = $quote['finance_inputs']['subsidy_expected_rs'] ?? $quote['calc']['subsidy_expected_rs'] ?? null;
    $subsidyProvided = !( $rawSubsidyInput === null || (is_string($rawSubsidyInput) && trim($rawSubsidyInput) === ''));

    $companyName = (string) ($company['brand_name'] ?: ($company['company_name'] ?? 'Dakshayani Enterprises'));
    $website = trim((string) ($company['website'] ?? ''));
    $phonePrimary = trim((string) ($company['phone_primary'] ?? ''));
    $whatsappRaw = trim((string) ($company['whatsapp_number'] ?? ''));
    $whatsappDisplay = $whatsappRaw !== '' ? $whatsappRaw : trim((string) ($company['phone_secondary'] ?? ''));
    $phoneBits = [];
    if ($phonePrimary !== '') {
        $phoneBits[] = '📞 ' . htmlspecialchars($phonePrimary, ENT_QUOTES);
    }
    if ($whatsappDisplay !== '' && $whatsappDisplay !== $phonePrimary) {
        $phoneBits[] = '💬 WhatsApp ' . htmlspecialchars($whatsappDisplay, ENT_QUOTES);
    }
    $whatsappDigits = quotation_normalize_whatsapp($whatsappDisplay);
    $waLink = $whatsappDigits !== '' ? 'https://wa.me/' . $whatsappDigits : '';
    $logoSrc = trim((string) ($company['logo_path'] ?? ''));
    $hasLogo = $logoSrc !== '';

    $bankFields = [
        ['icon' => '🏦', 'label' => 'Bank name', 'value' => trim((string) ($company['bank_name'] ?? ''))],
        ['icon' => '👤', 'label' => 'A/c name', 'value' => trim((string) ($company['bank_account_name'] ?? ''))],
        ['icon' => '🔢', 'label' => 'A/c no', 'value' => trim((string) ($company['bank_account_no'] ?? ''))],
        ['icon' => '✅', 'label' => 'IFSC', 'value' => trim((string) ($company['bank_ifsc'] ?? ''))],
        ['icon' => '📍', 'label' => 'Branch', 'value' => trim((string) ($company['bank_branch'] ?? ''))],
        ['icon' => '💳', 'label' => 'UPI ID', 'value' => trim((string) ($company['upi_id'] ?? ''))],
    ];
    $bankFields = array_values(array_filter($bankFields, static function (array $row): bool {
        return $row['value'] !== '';
    }));

    $whyPoints = $quoteDefaults['global']['quotation_ui']['why_dakshayani_points'] ?? ['Local Jharkhand EPC team'];
    if (!is_array($whyPoints) || $whyPoints === []) {
        $whyPoints = ['Local Jharkhand EPC team'];
    }

    $headerGrad = is_array($grads['header'] ?? null) ? $grads['header'] : [];
    $footerGrad = is_array($grads['footer'] ?? null) ? $grads['footer'] : [];
    $headerBg = !empty($headerGrad['enabled'])
        ? 'linear-gradient(' . ((string) ($headerGrad['direction'] ?? 'to right')) . ', ' . ((string) ($headerGrad['a'] ?? '#0ea5e9')) . ', ' . ((string) ($headerGrad['b'] ?? '#22c55e')) . ')'
        : (string) ($quoteDefaults['global']['branding']['header_bg'] ?? '#0f766e');
    $footerBg = !empty($footerGrad['enabled'])
        ? 'linear-gradient(' . ((string) ($footerGrad['direction'] ?? 'to right')) . ', ' . ((string) ($footerGrad['a'] ?? '#0ea5e9')) . ', ' . ((string) ($footerGrad['b'] ?? '#22c55e')) . ')'
        : (string) ($quoteDefaults['global']['branding']['footer_bg'] ?? '#0f172a');

    $headerText = (string) ($headerFooter['header_text_color'] ?? '#ffffff');
    $footerText = (string) ($headerFooter['footer_text_color'] ?? '#ffffff');
    $quoteStatus = documents_quote_normalize_status((string) ($quote['status'] ?? 'draft'));
    $quoteAcceptedAt = trim((string) ($quote['accepted_at'] ?? ''));
    $isAccepted = $quoteStatus === 'accepted' || $quoteAcceptedAt !== '';
    $statusBadgeLabel = $isAccepted ? 'Accepted' : 'Draft';
    $statusBadgeClass = $isAccepted ? 'accepted' : 'draft';
    $mainSolarKwpRaw = trim((string) ($quote['main_solar_kwp'] ?? ''));
    $complimentarySolarKwpRaw = trim((string) ($quote['complimentary_non_dcr_kwp'] ?? ''));
    $hasMainSolarSplit = $mainSolarKwpRaw !== '';
    $mainSolarKwp = $hasMainSolarSplit ? max(0, (float) $mainSolarKwpRaw) : 0.0;
    $complimentarySolarKwp = $hasMainSolarSplit ? max(0, (float) $complimentarySolarKwpRaw) : 0.0;
    $monthlyBillBefore = (float) ($financialClarity['monthly_bill_before_rs'] ?? 0);
    $monthlyBillBeforeDisplay = $monthlyBillBefore > 0
        ? quotation_format_inr_indian($monthlyBillBefore, $showDecimals)
        : '—';
    $rawDiscountFromQuote = $quote['discount_rs'] ?? null;
    $discountApplicable = false;
    if (is_numeric($rawDiscountFromQuote)) {
        $discountApplicable = (float) $rawDiscountFromQuote > 0;
    } elseif (is_string($rawDiscountFromQuote) && trim($rawDiscountFromQuote) !== '' && is_numeric(trim($rawDiscountFromQuote))) {
        $discountApplicable = (float) trim($rawDiscountFromQuote) > 0;
    }
    $discountRsDisplay = $discountApplicable ? (float) $rawDiscountFromQuote : 0.0;
    $grossPayableLabel = $discountApplicable ? 'Gross payable (after discount)' : 'Gross payable';

    $fieldValue = static function ($value): string {
        if ($value === null) {
            return '';
        }
        return trim((string) $value);
    };
    $customerSiteFields = [
        ['label' => 'Name', 'value' => $fieldValue($quote['customer_name'] ?? '')],
        ['label' => 'Mobile', 'value' => $fieldValue($quote['customer_mobile'] ?? '')],
        ['label' => 'Site Address', 'value' => $fieldValue($quote['site_address'] ?? '')],
        ['label' => 'District', 'value' => $fieldValue($quote['district'] ?? '')],
        ['label' => 'City', 'value' => $fieldValue($quote['city'] ?? '')],
        ['label' => 'State', 'value' => $fieldValue($quote['state'] ?? '')],
        ['label' => 'PIN', 'value' => $fieldValue($quote['pin'] ?? '')],
        ['label' => 'Billing Address', 'value' => $fieldValue($quote['billing_address'] ?? '')],
        ['label' => 'Place of Supply State', 'value' => $fieldValue($quote['place_of_supply_state'] ?? '')],
        ['label' => 'Consumer Account No. (JBVNL)', 'value' => $fieldValue($quote['consumer_account_no'] ?? '')],
        ['label' => 'Meter Number', 'value' => $fieldValue($quote['meter_number'] ?? '')],
        ['label' => 'Meter Serial Number', 'value' => $fieldValue($quote['meter_serial_number'] ?? '')],
        ['label' => 'Application ID', 'value' => $fieldValue($quote['application_id'] ?? '')],
        ['label' => 'Application Submitted Date', 'value' => $fieldValue($quote['application_submitted_date'] ?? '')],
        ['label' => 'Sanction Load', 'value' => $fieldValue($quote['sanction_load_kwp'] ?? '')],
        ['label' => 'Installed PV Capacity', 'value' => $fieldValue($quote['installed_pv_module_capacity_kwp'] ?? '')],
        ['label' => 'Circle', 'value' => $fieldValue($quote['circle_name'] ?? '')],
        ['label' => 'Division', 'value' => $fieldValue($quote['division_name'] ?? '')],
        ['label' => 'Sub Division', 'value' => $fieldValue($quote['sub_division_name'] ?? '')],
    ];
    $customerSiteFields = array_values(array_filter($customerSiteFields, static function (array $field): bool {
        return $field['value'] !== '';
    }));

    $quotationDateRaw = safe_text((string) ($quote['quotation_date'] ?? ''));
    if ($quotationDateRaw === '') {
        $quotationDateRaw = safe_text((string) ($quote['created_at'] ?? ''));
    }
    $quotationDateDisplay = quotation_format_display_date($quotationDateRaw);
    $validUntilDisplay = quotation_format_display_date(safe_text((string) ($quote['valid_until'] ?? '')));
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quotation</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--color-primary:<?= htmlspecialchars((string)($colors['primary'] ?? '#0ea5e9'), ENT_QUOTES) ?>;--color-accent:<?= htmlspecialchars((string)($colors['accent'] ?? '#22c55e'), ENT_QUOTES) ?>;--color-text:<?= htmlspecialchars((string)($colors['text'] ?? '#0f172a'), ENT_QUOTES) ?>;--color-muted:<?= htmlspecialchars((string)($colors['muted_text'] ?? '#475569'), ENT_QUOTES) ?>;--page-bg:<?= htmlspecialchars((string)($colors['page_bg'] ?? '#f8fafc'), ENT_QUOTES) ?>;--card-bg:<?= htmlspecialchars((string)($colors['card_bg'] ?? '#ffffff'), ENT_QUOTES) ?>;--border-color:<?= htmlspecialchars((string)($colors['border'] ?? '#e2e8f0'), ENT_QUOTES) ?>;--base-font-size:<?= (int)($typo['base_px'] ?? 14) ?>px;--h1-size:<?= (int)($typo['h1_px'] ?? 24) ?>px;--h2-size:<?= (int)($typo['h2_px'] ?? 18) ?>px;--h3-size:<?= (int)($typo['h3_px'] ?? 16) ?>px;--line-height:<?= (float)($typo['line_height'] ?? 1.6) ?>;--shadow-preset:<?= quotation_shadow_css((string)($tokens['shadow'] ?? 'soft')) ?>;--header-bg:<?= htmlspecialchars($headerBg, ENT_QUOTES) ?>;--footer-bg:<?= htmlspecialchars($footerBg, ENT_QUOTES) ?>;--header-text-color:<?= htmlspecialchars($headerText, ENT_QUOTES) ?>;--footer-text-color:<?= htmlspecialchars($footerText, ENT_QUOTES) ?>}
body{font-family:Inter,Arial,sans-serif;background:var(--page-bg);margin:0;color:var(--color-text);font-size:var(--base-font-size);line-height:var(--line-height)}
h1{font-size:var(--h1-size)}h2{font-size:var(--h2-size)}h3{font-size:var(--h3-size)}.wrap{max-width:1100px;margin:0 auto;padding:12px}.card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:14px;padding:14px;margin-bottom:12px;box-shadow:var(--shadow-preset)}.h{font-weight:700}.sec{border-bottom:2px solid var(--color-primary);padding-bottom:5px;margin-bottom:8px}.grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.bank-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.metric{background:var(--page-bg);border:1px solid var(--border-color);border-radius:10px;padding:8px}.hero{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.hero .metric b{display:block;font-size:1.2rem;margin-top:4px}.save-line{margin-top:10px;padding:10px 12px;border:1px solid #86efac;background:#f0fdf4;color:#166534;border-radius:10px;font-weight:700}.chip{background:#ccfbf1;color:#134e4a;border-radius:99px;padding:5px 10px;display:inline-block;margin:3px 6px 0 0}.quote-status-row{display:flex;justify-content:flex-end;align-items:center;padding-top:10px;padding-bottom:10px}.quote-status-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 11px;font-size:.86em;font-weight:700;letter-spacing:.01em;border:1px solid transparent}.quote-status-badge.draft{background:#fff7ed;color:#9a3412;border-color:#fed7aa}.quote-status-badge.accepted{background:#ecfdf3;color:#166534;border-color:#bbf7d0}.quote-meta-dates{margin-top:4px;font-size:.85em;line-height:1.5;text-align:right}.quote-meta-dates div{margin-top:2px}.muted{color:var(--color-muted)}.item-master-description{color:var(--color-muted);font-size:.88em;line-height:1.45;margin-top:2px;white-space:normal;word-break:break-word}.item-custom-description{color:#1e293b;font-size:.9em;line-height:1.5;margin-top:4px;font-weight:600;white-space:normal;word-break:break-word}table{width:100%;border-collapse:collapse}th,td{border:1px solid var(--border-color);padding:8px;text-align:left}th{background:#f8fafc}.right{text-align:right}.center{text-align:center}.pricing-gross-row td{font-weight:700;font-size:1.1em}.footer{background:var(--footer-bg);color:var(--footer-text-color)}.footer a,.footer a:visited{color:var(--footer-text-color)}.footer-brand-row{display:flex;align-items:center;gap:8px;margin-bottom:8px}.footer-logo{display:inline-flex;align-items:center;justify-content:center}.footer-logo img{max-height:38px;width:auto;display:block}.footer-brand-name{color:var(--footer-text-color);font-size:1.1em;font-weight:700;line-height:1.3}.header{background:var(--header-bg);color:var(--header-text-color)}.header a,.header a:visited{color:var(--header-text-color)}.header-top{display:flex;align-items:center;gap:12px}.header-logo{background:rgba(255,255,255,.18);padding:4px 8px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center}.header-logo img{max-height:50px;width:auto;display:block}.header-main{min-width:0}.screen-only{display:block}.hide-print{display:block}.chart-block{margin-bottom:12px;break-inside:avoid;page-break-inside:avoid}.chart-title{font-weight:700;margin:2px 0 8px}.chart-legend{display:flex;flex-wrap:wrap;gap:10px;margin-top:8px}.legend-item{display:flex;align-items:center;gap:6px;font-size:.92rem}.legend-swatch{width:12px;height:12px;border-radius:3px;display:inline-block}.bar-chart,.bar-chart *{box-sizing:border-box}.bar-chart{position:relative;overflow:hidden;display:flex;align-items:flex-end;justify-content:space-around;gap:10px;min-height:220px;padding:10px 12px 14px;border:1px solid var(--border-color);border-radius:10px;background:var(--card-bg)}.bar-wrap{display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:8px;flex:1;min-width:0}.bar-area{height:160px;width:100%;padding:8px 8px 0;display:flex;align-items:flex-end;justify-content:center;overflow:hidden}.bar{width:min(52px,100%);max-height:100%;border-radius:8px 8px 4px 4px;min-height:2px}.bar-label{font-size:.82rem;text-align:center;line-height:1.35;width:100%;overflow-wrap:anywhere}.axis-label{font-size:.82rem;color:var(--color-muted);text-align:center;margin-top:6px}.line-chart svg{width:100%;height:220px;border:1px solid var(--border-color);border-radius:10px;background:var(--card-bg)}.chart-print-img{display:none;width:100%;height:auto;max-width:100%;border:1px solid var(--border-color);border-radius:10px;background:#fff}.sf-glance-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:10px}.sf-glance-group{background:#f8faff;border:1px solid #dbe6f7;border-radius:12px;padding:10px}.sf-glance-group h3{margin:0 0 8px;font-size:1rem}.sf-glance-list{display:grid;gap:4px}.sf-glance-item{display:flex;justify-content:space-between;gap:8px;padding:4px 0;border-bottom:1px dashed #d8e1ef}.sf-glance-item:last-child{border-bottom:none}.sf-glance-label{font-weight:600;color:#23324d;font-size:.88rem}.sf-glance-value{font-weight:700;text-align:right}.sf-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px}.sf-finance-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px}.sf-finance-grid ul{margin:.4rem 0 0;padding-left:1rem}.payback-meter{width:100%;height:16px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:8px}.payback-meter-fill{height:100%;background:linear-gradient(to right,var(--color-primary),var(--color-accent));width:0}.scenario-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}@media (max-width:700px){.header-top{flex-direction:column;align-items:flex-start}.footer-brand-row{align-items:flex-start}.scenario-grid{grid-template-columns:1fr}.bank-grid{grid-template-columns:1fr}.quote-status-row{justify-content:flex-start}}@media print{.hide-print,.screen-only{display:none!important}.card{break-inside:avoid;box-shadow:none}.chart-block,.bar-chart,.line-chart{overflow:visible!important;height:auto!important}canvas{display:none!important}.chart-print-img{display:block!important}.item-master-description,.item-custom-description{white-space:normal;word-break:break-word;line-height:1.45}}</style>
</head><body><div id="quotation-root" class="wrap">
<section class="card header"><div class="header-top"><?php if ($hasLogo): ?><div class="header-logo"><img src="<?= htmlspecialchars($logoSrc, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($companyName, ENT_QUOTES) ?> logo" /></div><?php endif; ?><div class="header-main"><div class="h"><?= htmlspecialchars($companyName, ENT_QUOTES) ?></div><div><?= htmlspecialchars((string)($company['address_line'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars((string)($company['city'] ?? ''), ENT_QUOTES) ?></div><div><?= implode(' | ', $phoneBits) ?><?= $phoneBits !== [] ? ' | ' : '' ?>✉️ <?= htmlspecialchars((string)($company['email_primary'] ?? ''), ENT_QUOTES) ?> · 🌐 <?= htmlspecialchars($website, ENT_QUOTES) ?><?= $waLink !== '' ? ' · <a href="' . htmlspecialchars($waLink, ENT_QUOTES) . '">Chat</a>' : '' ?></div><div>GSTIN <?= htmlspecialchars((string)($company['gstin'] ?? ''), ENT_QUOTES) ?> · UDYAM <?= htmlspecialchars((string)($company['udyam'] ?? ''), ENT_QUOTES) ?> · PAN <?= htmlspecialchars((string)($company['pan'] ?? ''), ENT_QUOTES) ?></div><div>Quote No <b><?= htmlspecialchars((string)($quote['quote_no'] ?? ''), ENT_QUOTES) ?></b></div></div></div></section>
<section class="card quote-status-row"><div><span class="quote-status-badge <?= htmlspecialchars($statusBadgeClass, ENT_QUOTES) ?>">Status: <?= htmlspecialchars($statusBadgeLabel, ENT_QUOTES) ?></span><div class="quote-meta-dates muted"><div>Quotation Date: <?= htmlspecialchars($quotationDateDisplay, ENT_QUOTES) ?></div><div>Valid Until: <?= htmlspecialchars($validUntilDisplay, ENT_QUOTES) ?></div></div></div></section>
<section class="card"><span class="chip">✅ MNRE compliant</span><span class="chip"><?= $segment === 'RES' ? '✅ PM Surya Ghar eligible' : 'ℹ️ Segment specific policy' ?></span><span class="chip">🔌 Net metering supported</span><span class="chip">🛡️ 25+ year life / warranty</span></section>
<?php if ($customerSiteFields !== []): ?><section class="card"><div class="h sec">🏠 Customer &amp; Site 📍</div><div class="grid2"><?php foreach ($customerSiteFields as $field): ?><div class="metric"><b><?= htmlspecialchars((string) ($field['label'] ?? ''), ENT_QUOTES) ?></b><div><?= nl2br(htmlspecialchars((string) ($field['value'] ?? ''), ENT_QUOTES)) ?></div></div><?php endforeach; ?></div></section><?php endif; ?>
<?php if ($coverNote !== ''): ?><section class="card"><div><?= quotation_sanitize_html($coverNote) ?></div></section><?php endif; ?>
<section class="card"><div class="h sec">⚡ At a glance</div><div class="hero"><div class="metric">System Size<?php if ($hasMainSolarSplit): ?><b class="system-size-main"><?= htmlspecialchars(number_format($mainSolarKwp, 2, '.', ''), ENT_QUOTES) ?> kWp</b><?php if ($complimentarySolarKwp > 0): ?><small class="system-size-sub">Complimentary Non-DCR Solar Size: <?= htmlspecialchars(number_format($complimentarySolarKwp, 2, '.', ''), ENT_QUOTES) ?> kWp</small><?php endif; ?><?php else: ?><b><?= htmlspecialchars((string)($quote['capacity_kwp'] ?? '0'), ENT_QUOTES) ?> kWp</b><?php endif; ?></div><div class="metric">Monthly Bill (Without Solar)<b><?= htmlspecialchars($monthlyBillBeforeDisplay, ENT_QUOTES) ?></b></div><div class="metric">Monthly Outflow (With Solar – Bank Finance)<b id="heroOutflowBank">-</b></div><div class="metric">Monthly Outflow (With Solar – Self Funded)<b id="heroOutflowSelf">-</b></div></div><div class="save-line">🟢 You save approx <span id="heroSaving">-</span> every month</div></section>
<section class="card"><div class="h sec">📦 Item summary</div><table><thead><tr><th>Sr No</th><th>Item and Description</th><th>HSN</th><th class="center">Quantity</th><th class="center">Unit</th></tr></thead><tbody><?php if ($itemRows === []): ?><tr><td colspan="5" class="center muted">No line items added.</td></tr><?php else: foreach ($itemRows as $idx => $item): ?><tr><td><?= (int)$idx + 1 ?></td><td><div><?= htmlspecialchars((string)($item['name'] ?? ''), ENT_QUOTES) ?></div><?php $itemDesc=(string)($item['description'] ?? ''); if (trim($itemDesc) !== ''): ?><div class="item-master-description"><?= nl2br(htmlspecialchars($itemDesc, ENT_QUOTES, 'UTF-8')) ?></div><?php endif; ?><?php $customDesc=(string)($item['custom_description'] ?? ''); if (trim($customDesc) !== ''): ?><div class="item-custom-description">📝 <?= nl2br(htmlspecialchars($customDesc, ENT_QUOTES, 'UTF-8')) ?></div><?php endif; ?></td><td><?= htmlspecialchars((string)($item['hsn'] ?? ''), ENT_QUOTES) ?></td><td class="center"><?= htmlspecialchars((string)($item['qty'] ?? ''), ENT_QUOTES) ?></td><td class="center"><?= htmlspecialchars((string)($item['unit'] ?? ''), ENT_QUOTES) ?></td></tr><?php endforeach; endif; ?></tbody></table></section>
<?php if($specialReq!==''): ?><section class="card"><div class="h sec">✍️ Special Requests From Consumer (Inclusive in the rate)</div><div><?= quotation_sanitize_html($specialReq) ?></div><div><i>In case of conflict between annexures and special requests, special requests will be prioritized.</i></div></section><?php endif; ?>
<section class="card"><div class="h sec">💰 Pricing summary</div><table><thead><tr><th>#</th><th>Particular</th><th class="right">Amount</th></tr></thead><tbody><tr><td>1</td><td>Total system price incl GST</td><td class="right"><?= quotation_format_inr_indian((float)($calc['system_total_incl_gst_rs'] ?? $quote['input_total_gst_inclusive'] ?? 0), $showDecimals) ?></td></tr><tr><td>2</td><td>Transportation</td><td class="right"><?= quotation_format_inr_indian((float)($calc['transportation_rs'] ?? 0), $showDecimals) ?></td></tr><?php if ($discountApplicable): ?><tr><td>3</td><td>Discount<?php $discountNote=(string)($calc['discount_note'] ?? ''); if(trim($discountNote)!==''): ?><div class="muted" style="font-size:.85em;margin-top:2px"><?= htmlspecialchars($discountNote, ENT_QUOTES) ?></div><?php endif; ?></td><td class="right">- <?= quotation_format_inr_indian($discountRsDisplay, $showDecimals) ?></td></tr><?php endif; ?><tr class="pricing-gross-row"><td><?= $discountApplicable ? '4' : '3' ?></td><td><?= htmlspecialchars($grossPayableLabel, ENT_QUOTES) ?></td><td class="right" id="upfront"></td></tr><tr><td><?= $discountApplicable ? '5' : '4' ?></td><td>Subsidy expected</td><td class="right"><?= quotation_format_inr_indian((float)($calc['subsidy_expected_rs'] ?? 0), $showDecimals) ?></td></tr><tr><td><?= $discountApplicable ? '6' : '5' ?></td><td><b>Net Investment/Cost After Subsidy Credit</b></td><td class="right"><b id="upfrontNet"></b></td></tr></tbody></table></section>
<section class="card sf-glance-wrap"><div class="h sec">☀️ Solar at a Glance</div><div class="sf-glance-grid" id="glancePanel"></div></section>
<section class="card"><div class="h sec">📊 Monthly Outflow Comparison</div><canvas id="monthlyChart" height="130"></canvas><img id="monthlyChartPrint" class="chart-print-img" alt="Monthly outflow chart for print"></section>
<section class="card"><div class="h sec">📈 Cumulative Expense Over 25 Years</div><canvas id="cumulativeChart" height="130"></canvas><img id="cumulativeChartPrint" class="chart-print-img" alt="Cumulative expense chart for print"></section>
<section class="card"><div class="h sec">⏱️ Payback Meters</div><div id="paybackMeters" class="sf-kpis"></div></section>
<section class="card"><div class="h sec">🏦 Financial Clarity</div><div class="muted" style="font-size:.85em;margin-bottom:8px">Assumptions: ₹<?= number_format((float)($financialClarity['unit_rate_rs_per_kwh'] ?? 0), 2) ?>/unit, <?= number_format((float)($financialClarity['annual_generation_kwh_per_kw'] ?? 0), 0) ?> kWh/kWp/year, <?= number_format((float)($financialClarity['loan_interest_rate_percent'] ?? 0), 2) ?>% for <?= (int)($financialClarity['loan_tenure_months'] ?? 0) ?> months.</div><div id="financeBoxes" class="sf-finance-grid"></div></section>
<section class="card"><div class="h sec">⭐ Why <?= htmlspecialchars($companyName, ENT_QUOTES) ?></div><ul><?php foreach ($whyPoints as $point): ?><li><?= htmlspecialchars((string)$point, ENT_QUOTES) ?></li><?php endforeach; ?></ul></section>
<section class="card"><div class="h sec">📑 Annexures</div><?php foreach(['warranty'=>'Warranty','system_inclusions'=>'System inclusions','pm_subsidy_info'=>'PM subsidy info','completion_milestones'=>'Completion milestones','payment_terms'=>'Payment terms','system_type_explainer'=>'System Type explainer (ongrid vs hybrid vs offgrid)','transportation'=>'Transportation','terms_conditions'=>'Terms and conditions'] as $k=>$label): ?><?php $annVal = trim((string)($ann[$k] ?? '')); if ($annVal === '') { continue; } ?><div class="metric"><div class="h"><?= htmlspecialchars($label, ENT_QUOTES) ?></div><div><?= quotation_sanitize_html($annVal) ?></div></div><?php endforeach; ?></section>
<?php $nextStepsHtml = trim((string)($ann['next_steps'] ?? '')); if ($nextStepsHtml !== ''): ?><section class="card"><div class="h sec">🚀 Next steps</div><div><?= quotation_sanitize_html($nextStepsHtml) ?></div></section><?php endif; ?>
<?php if ($bankFields !== []): ?>
<section class="card"><div class="h sec">Bank Details</div><div class="bank-grid"><?php foreach ($bankFields as $bankField): ?><div class="metric"><div class="h"><?= htmlspecialchars((string) ($bankField['icon'] . ' ' . $bankField['label']), ENT_QUOTES) ?></div><div><?= htmlspecialchars((string) $bankField['value'], ENT_QUOTES) ?></div></div><?php endforeach; ?></div></section>
<?php endif; ?>
<section class="card footer"><div class="footer-brand-row"><?php if ($hasLogo): ?><div class="footer-logo"><img src="<?= htmlspecialchars($logoSrc, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($companyName, ENT_QUOTES) ?> logo" /></div><?php endif; ?><div class="footer-brand-name"><?= htmlspecialchars($companyName, ENT_QUOTES) ?></div></div><div>Registered office: <?= htmlspecialchars((string)($company['address_line'] ?? ''), ENT_QUOTES) ?></div><div>📞 <?= htmlspecialchars((string)($company['phone_primary'] ?? ''), ENT_QUOTES) ?><?= $whatsappDisplay !== '' ? ' · 💬 ' . htmlspecialchars($whatsappDisplay, ENT_QUOTES) : '' ?> · ✉️ <?= htmlspecialchars((string)($company['email_primary'] ?? ''), ENT_QUOTES) ?> · 🌐 <?= htmlspecialchars($website, ENT_QUOTES) ?></div><div>GSTIN: <?= htmlspecialchars((string)($company['gstin'] ?? ''), ENT_QUOTES) ?> · UDYAM: <?= htmlspecialchars((string)($company['udyam'] ?? ''), ENT_QUOTES) ?> · PAN: <?= htmlspecialchars((string)($company['pan'] ?? ''), ENT_QUOTES) ?></div><div>JREDA: <?= htmlspecialchars((string)($company['jreda_license'] ?? ''), ENT_QUOTES) ?> · DWSD: <?= htmlspecialchars((string)($company['dwsd_license'] ?? ''), ENT_QUOTES) ?></div><div><small>🧾 <?= htmlspecialchars((string)($quoteDefaults['global']['quotation_ui']['footer_disclaimer'] ?? 'Values are indicative and subject to site conditions, DISCOM approvals, and policy updates.'), ENT_QUOTES) ?></small></div></section>
</div>
<script>
const q={
  gross:<?= json_encode((float)($financialClarity['gross'] ?? 0)) ?>,
  subsidy:<?= json_encode((float)($financialClarity['subsidy'] ?? 0)) ?>,
  selfUpfrontNet:<?= json_encode((float)($financialClarity['self_upfront_net_rs'] ?? 0)) ?>,
  subsidyProvided:<?= json_encode($subsidyProvided) ?>,
  monthly:<?= json_encode((float)($financialClarity['monthly_bill_before_rs'] ?? 0)) ?>,
  unit:<?= json_encode((float)($financialClarity['unit_rate_rs_per_kwh'] ?? 0)) ?>,
  cap:<?= json_encode((float)($financialClarity['capacity_kwp'] ?? 0)) ?>,
  gen:<?= json_encode((float)($financialClarity['annual_generation_kwh_per_kw'] ?? 0)) ?>,
  margin:<?= json_encode((float)($financialClarity['margin_amount_rs'] ?? 0)) ?>,
  loan:<?= json_encode((float)($financialClarity['loan_eligible_rs'] ?? 0)) ?>,
  loanEff:<?= json_encode((float)($financialClarity['effective_principal_rs'] ?? 0)) ?>,
  emi:<?= json_encode((float)($financialClarity['emi_rs'] ?? 0)) ?>,
  residual:<?= json_encode((float)($financialClarity['residual_bill'] ?? 0)) ?>,
  out:<?= json_encode((float)($financialClarity['loan_total_outflow_rs'] ?? 0)) ?>,
  systemType:<?= json_encode((string)($quote['system_type'] ?? 'Ongrid')) ?>,
  mainSolar:<?= json_encode($hasMainSolarSplit ? $mainSolarKwp : null) ?>,
  complimentarySolar:<?= json_encode($hasMainSolarSplit ? $complimentarySolarKwp : null) ?>,
  loanEnabled:<?= json_encode((bool)($savingsSnapshot['bank_loan_enabled'] ?? true)) ?>,
  loanTenureMonths:<?= json_encode($loanTenureMonths) ?>,
  loanInterest:<?= json_encode($loanInterest) ?>,
  inverterKva:<?= json_encode((string)($quote['hybrid_inverter_kva'] ?? $quote['inverter_kva'] ?? '')) ?>,
  batteryCount:<?= json_encode((string)($quote['hybrid_battery_count'] ?? $quote['battery_count'] ?? '')) ?>,
  phase:<?= json_encode((string)($quote['hybrid_phase'] ?? $quote['phase'] ?? '')) ?>,
  transport:<?= json_encode((float)($calc['transportation_rs'] ?? 0)) ?>,
  discount:<?= json_encode((float)($calc['discount_rs'] ?? 0)) ?>,
  loanAmountRaw:<?= json_encode((float)($quote['finance_inputs']['loan']['loan_amount'] ?? 0)) ?>,
  annualGenerationPerKw:<?= json_encode($annualGeneration) ?>
};
const showDecimals=<?= json_encode($showDecimals) ?>;
const r=x=>'₹'+Number(x||0).toLocaleString('en-IN',{minimumFractionDigits:showDecimals?2:0,maximumFractionDigits:showDecimals?2:0});
const nUnits=x=>Number(x||0).toLocaleString('en-IN',{maximumFractionDigits:0});
const num=v=>{const n=Number(v);return Number.isFinite(n)?n:0;};
const monthlyBill=Math.max(0,num(q.monthly));
const residual=Math.max(0,num(q.residual));
const gross=Math.max(0,num(q.gross));
const subsidy=Math.max(0,num(q.subsidy));
const loanEligible=Math.max(0,num(q.loan));
const margin=Math.max(0,num(q.margin));
const emi=Math.max(0,num(q.emi));
const outflowLoan=Math.max(0,num(q.out));
const tenureMonths=Math.max(1,Math.round(num(q.loanTenureMonths)||120));
const upfrontNetRaw=num(q.selfUpfrontNet);
const upfrontNet=Math.max(0,upfrontNetRaw>0?upfrontNetRaw:(gross-subsidy));
const monthlySaving=Math.max(0,monthlyBill-residual);
const annualSaving=monthlySaving*12;
const saving25=annualSaving*25;
const annualUnits=Math.max(0,num(q.cap)*num(q.annualGenerationPerKw));
const monthlyUnits=annualUnits/12;
const units25=annualUnits*25;
const roofArea= Math.max(0,num(q.cap)*100);
const billOffset=Math.min(100,(monthlyBill>0?(monthlyUnits*num(q.unit))/monthlyBill*100:0));
const annualCo2=annualUnits*<?= json_encode($emissionFactor) ?>;
const co225=annualCo2*25;
const treeFactor=Math.max(0.1,<?= json_encode($treeAbsorption) ?>);
const annualTrees=annualCo2/treeFactor;
const trees25=co225/treeFactor;
const loanBaseline=Math.max(0,margin>0?margin:Math.max(0,gross-loanEligible));
const monthlyOutflowSelf=residual;
const hasLoanScenario=!!q.loanEnabled && (loanEligible>0 || emi>0 || outflowLoan>0);

const setText=(id,val)=>{const node=document.getElementById(id);if(node){node.textContent=val;}};
setText('upfront',r(gross));
setText('upfrontNet',r(upfrontNet));
setText('heroOutflowBank',hasLoanScenario?r(outflowLoan):'—');
setText('heroOutflowSelf',r(monthlyOutflowSelf));
setText('heroSaving',r(monthlySaving));

const fmtMonths=(months)=>{
  if(!Number.isFinite(months)||months<0) return '—';
  if(months>25*12) return 'Not within 25 years';
  const y=Math.floor(months/12);
  const m=months%12;
  if(y===0) return `${m} month${m===1?'':'s'}`;
  if(m===0) return `${y} year${y===1?'':'s'}`;
  return `${y} year${y===1?'':'s'} ${m} month${m===1?'':'s'}`;
};
const selfPaybackMonths=monthlySaving>0?Math.round((upfrontNet/monthlySaving)):Infinity;
let loanPaybackMonth=null;
if(hasLoanScenario){
  let cumLoan=loanBaseline, cumNoSolar=0;
  for(let m=1;m<=25*12;m++){cumLoan+=outflowLoan;cumNoSolar+=monthlyBill;if(cumLoan<=cumNoSolar){loanPaybackMonth=m;break;}}
}

const glanceGroups=[
  {title:'Generation & Savings',rows:[
    ['Expected monthly generation',`${nUnits(monthlyUnits)} units`],
    ['Expected annual generation',`${nUnits(annualUnits)} units`],
    ['Expected generation in 25 years',`${nUnits(units25)} units`],
    ['Estimated monthly savings',r(monthlySaving)],
    ['Estimated annual savings',r(annualSaving)],
    ['Estimated savings in 25 years',r(saving25)]
  ]},
  {title:'Payback & Monthly Outflow',rows:[
    ...(hasLoanScenario?[['Estimated payback period — Loan',fmtMonths(loanPaybackMonth)]]:[]),
    ['Estimated payback period — Self Funded',fmtMonths(selfPaybackMonths)],
    ['No Solar',r(monthlyBill)],
    ...(hasLoanScenario?[['With Solar (Loan)',r(outflowLoan)]]:[]),
    ['With Solar (Self Funded)',r(monthlyOutflowSelf)]
  ]},
  {title:'Feasibility / Impact',rows:[
    ['Roof area needed',`${roofArea.toFixed(0)} sq.ft`],
    ['Bill offset %',`${billOffset.toFixed(1)}%`],
    ['Annual CO₂ reduction',`${annualCo2.toFixed(0)} kg`],
    ['25-year CO₂ reduction',`${co225.toFixed(0)} kg`],
    ['Annual trees equivalent',`${annualTrees.toFixed(0)} trees`],
    ['25-year trees equivalent',`${trees25.toFixed(0)} trees`]
  ]}
];
const glancePanel=document.getElementById('glancePanel');
if(glancePanel){
  glancePanel.innerHTML=glanceGroups.map(g=>`<article class="sf-glance-group"><h3>${g.title}</h3><div class="sf-glance-list">${g.rows.filter(([,v])=>v!==''&&v!==null&&v!==undefined).map(([l,v])=>`<div class="sf-glance-item"><span class="sf-glance-label">${l}</span><span class="sf-glance-value">${v}</span></div>`).join('')}</div></article>`).join('');
}

const monthlyLabels=['No Solar'];const monthlyData=[monthlyBill];const monthlyColors=['#9ca3af'];
if(hasLoanScenario){monthlyLabels.push('With Solar (Loan)');monthlyData.push(outflowLoan);monthlyColors.push('#0f766e');}
monthlyLabels.push('With Solar (Self Funded)');monthlyData.push(monthlyOutflowSelf);monthlyColors.push('#f59e0b');
const years=[...Array(26).keys()];
const cumulativeDatasets=[
  {label:'No Solar',data:years.map(y=>y*12*monthlyBill),borderColor:'#9ca3af',backgroundColor:'#9ca3af'},
  ...(hasLoanScenario?[{label:'With Solar (Loan)',data:years.map(y=>{const m=y*12;const emiPart=Math.min(m,tenureMonths)*emi;const residualPart=m*residual;return loanBaseline+emiPart+residualPart;}),borderColor:'#0f766e',backgroundColor:'#0f766e'}]:[]),
  {label:'With Solar (Self Funded)',data:years.map(y=>upfrontNet+y*12*residual),borderColor:'#f59e0b',backgroundColor:'#f59e0b'}
];

const paybackMeters=document.getElementById('paybackMeters');
if(paybackMeters){
  const meterItems=[];
  if(hasLoanScenario){meterItems.push(['Payback meter (Loan)',fmtMonths(loanPaybackMonth),loanPaybackMonth]);}
  meterItems.push(['Payback meter (Self Funded)',fmtMonths(selfPaybackMonths),selfPaybackMonths]);
  paybackMeters.innerHTML=meterItems.map(([label,val,months])=>{const pct=Number.isFinite(months)?Math.max(0,Math.min(100,(months/(25*12))*100)):0;return `<div class="sf-metric"><strong>${label}</strong><div>${val}</div><div class="payback-meter"><div class="payback-meter-fill" style="width:${pct.toFixed(1)}%"></div></div></div>`;}).join('');
}

const financeBoxes=document.getElementById('financeBoxes');
if(financeBoxes){
  const cards=[[ 'No Solar', [['Monthly bill',r(monthlyBill)],['25 year expense',r(monthlyBill*12*25)]] ]];
  if(hasLoanScenario){cards.push(['Loan scenario',[['System cost',r(gross)],['Subsidy',r(subsidy)],['Margin money',r(loanBaseline)],['Loan amount / effective principal',r(Math.max(0,loanEligible-subsidy))],['EMI',r(emi)],['Residual bill',r(residual)],['Total monthly outflow',r(outflowLoan)],['Monthly saving',r(Math.max(0,monthlyBill-outflowLoan))],['Annual saving',r(Math.max(0,monthlyBill-outflowLoan)*12)]]]);}
  cards.push(['Self Funded',[['System cost',r(gross)],['Subsidy',r(subsidy)],['Net investment',r(upfrontNet)],['Residual bill',r(residual)],['Total monthly outflow',r(monthlyOutflowSelf)],['Monthly saving',r(monthlySaving)],['Annual saving',r(annualSaving)]]]);
  financeBoxes.innerHTML=cards.map(([t,rows])=>`<div class='sf-metric'><strong>${t}</strong><ul>${rows.map(([k,v])=>`<li>${k}: <b>${v}</b></li>`).join('')}</ul></div>`).join('');
}

let monthlyChart=null,cumulativeChart=null;
const mctx=document.getElementById('monthlyChart');
if(mctx&&window.Chart){monthlyChart=new Chart(mctx,{type:'bar',data:{labels:monthlyLabels,datasets:[{label:'Monthly Outflow (₹)',data:monthlyData,backgroundColor:monthlyColors}]},options:{plugins:{legend:{display:true}},scales:{x:{title:{display:true,text:'Scenario'}},y:{title:{display:true,text:'Monthly Outflow (₹)'}}}}});}
const cctx=document.getElementById('cumulativeChart');
if(cctx&&window.Chart){cumulativeChart=new Chart(cctx,{type:'line',data:{labels:years,datasets:cumulativeDatasets.map(ds=>({...ds,tension:0.2,fill:false,borderWidth:2}))},options:{plugins:{legend:{display:true}},scales:{x:{title:{display:true,text:'Years'}},y:{title:{display:true,text:'Cumulative Expense (₹)'}}}}});}

const buildChartPrintImages=()=>{
  const mImg=document.getElementById('monthlyChartPrint');
  if(mImg&&mctx&&mctx.toDataURL){mImg.src=mctx.toDataURL('image/png');}
  const cImg=document.getElementById('cumulativeChartPrint');
  if(cImg&&cctx&&cctx.toDataURL){cImg.src=cctx.toDataURL('image/png');}
};
if(document.readyState==='complete'){setTimeout(buildChartPrintImages,200);}else{window.addEventListener('load',()=>setTimeout(buildChartPrintImages,200),{once:true});}
window.addEventListener('beforeprint',buildChartPrintImages);
const co2YearEl=document.getElementById('co2y');if(co2YearEl){co2YearEl.textContent=annualCo2.toFixed(0)+' kg';}
const treeYearEl=document.getElementById('treey');if(treeYearEl){treeYearEl.textContent=annualTrees.toFixed(1);}
const co225El=document.getElementById('co225');if(co225El){co225El.textContent=co225.toFixed(0)+' kg';}
const tree25El=document.getElementById('tree25');if(tree25El){tree25El.textContent=trees25.toFixed(1);}
</script>
</body></html>
<?php
    $output = ob_get_clean();
    echo ltrim((string) $output);
}
