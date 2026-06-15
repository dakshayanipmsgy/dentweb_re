<?php
declare(strict_types=1);
require_once __DIR__ . '/solar_finance_reports.php';
require_once __DIR__ . '/customer_document_acceptance.php';

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


function documents_quote_resolve_finance_scenarios_for_render(array $quote, array $calc, array $snapshot): array
{
    return solar_finance_normalize_for_quote_render($quote, $calc, $snapshot);
}

function compute_financial_clarity(array $quote, array $calc, array $snapshot): array
{
    return documents_quote_resolve_finance_scenarios_for_render($quote, $calc, $snapshot);
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
    $pricingSummarySystemPrice = (float) ($calc['grand_total'] ?? ($calc['final_price_incl_gst'] ?? ($calc['tax_breakdown']['gross_incl_gst'] ?? ($quote['input_total_gst_inclusive'] ?? 0))));
    $taxBreakdown = is_array($calc['tax_breakdown'] ?? null) ? (array) $calc['tax_breakdown'] : (is_array($quote['tax_breakdown'] ?? null) ? (array) $quote['tax_breakdown'] : []);
    $taxSummaryBasic = (float) ($taxBreakdown['basic_total'] ?? 0);
    $taxSummaryGst = (float) ($taxBreakdown['gst_total'] ?? 0);
    $taxSummaryGross = (float) ($taxBreakdown['gross_incl_gst'] ?? $pricingSummarySystemPrice);
    if ($taxSummaryGross <= 0 && $pricingSummarySystemPrice > 0) {
        $taxSummaryGross = $pricingSummarySystemPrice;
        $taxSummaryBasic = max(0, (float) ($calc['basic_total'] ?? 0));
        $taxSummaryGst = max(0, $taxSummaryGross - $taxSummaryBasic);
    }
    $taxItems = is_array($taxBreakdown['items'] ?? null) ? array_values((array) $taxBreakdown['items']) : [];
    $taxItems = array_values(array_filter($taxItems, static function ($row): bool {
        return is_array($row) && (((float) ($row['taxable_value'] ?? 0) > 0) || ((float) ($row['gross_incl_gst'] ?? 0) > 0));
    }));
    $taxItemAllocationBasis = (string) ($taxBreakdown['item_allocation_basis'] ?? 'none');
    $showTaxBreakup = !array_key_exists('show_tax_breakup', $quote)
        ? true
        : (bool) ($quote['show_tax_breakup'] ?? false);

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

    $normalizedStatus = strtolower(trim((string) ($quote['status'] ?? 'draft')));
    $acceptance = is_array($quote['customer_acceptance'] ?? null) ? $quote['customer_acceptance'] : [];
    $hasCustomerAcceptance = trim((string) ($acceptance['confirmed_at'] ?? '')) !== '';
    $publicShareValid = $viewerType === 'public' && $shareUrl !== '' && !empty($quote['public_share_enabled']) && trim((string) ($quote['public_share_token'] ?? '')) !== '';
    $isCurrentVersion = !array_key_exists('is_current_version', $quote) || !empty($quote['is_current_version']);
    $isUnavailableVersion = !empty($quote['archived_flag']) || !empty($quote['archived']) || in_array($normalizedStatus, ['archived', 'cancelled', 'superseded'], true);
    $showAcceptanceAction = $publicShareValid && $normalizedStatus === 'approved' && $isCurrentVersion && !$isUnavailableVersion && !$hasCustomerAcceptance;
    $showAcceptanceResult = $publicShareValid && $hasCustomerAcceptance;
    $acceptanceUrl = $showAcceptanceAction ? 'customer-document-acceptance.php?' . http_build_query(['type' => 'quotation', 'token' => (string) ($quote['public_share_token'] ?? '')]) : '';
    $acceptanceWhatsappUrl = $showAcceptanceResult && (string) ($acceptance['status'] ?? '') === 'whatsapp_pending' ? customer_acceptance_whatsapp_url($acceptance, $shareUrl) : '';

    $bankFields = [
        ['icon' => '🏦', 'label' => 'Bank name', 'value' => trim((string) ($company['bank_name'] ?? ''))],
        ['icon' => '👤', 'label' => 'A/c name', 'value' => trim((string) ($company['bank_account_name'] ?? ''))],
        ['icon' => '🔢', 'label' => 'A/c no', 'value' => trim((string) ($company['bank_account_no'] ?? ''))],
        ['icon' => '✅', 'label' => 'IFSC', 'value' => trim((string) ($company['bank_ifsc'] ?? ''))],
        ['icon' => '📍', 'label' => 'Branch', 'value' => trim((string) ($company['bank_branch'] ?? ''))],
        ['icon' => '💳', 'label' => 'UPI ID', 'value' => 'd.entranchi@ybl'],
    ];
    $upiId = 'd.entranchi@ybl';
    $upiAdvanceUrl = 'upi://pay?pa=d.entranchi%40ybl&pn=Dakshayani%20Enterprises&am=10000.00&cu=INR&tn=Booking%20Advance';
    $upiAnyAmountUrl = 'upi://pay?pa=d.entranchi%40ybl&pn=Dakshayani%20Enterprises&cu=INR&tn=Quotation%20Payment';

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
    $statusBadgeLabel = $isAccepted ? 'Accepted' : ucwords(str_replace(['_', '-'], ' ', $quoteStatus));
    $statusBadgeClass = preg_replace('/[^a-z0-9-]+/', '-', strtolower($quoteStatus)) ?: 'draft';
    $mainSolarKwpRaw = trim((string) ($quote['main_solar_kwp'] ?? ''));
    $complimentarySolarKwpRaw = trim((string) ($quote['complimentary_non_dcr_kwp'] ?? ''));
    $solarSizeValue = static function (string $raw): float {
        if ($raw === '' || !is_numeric($raw)) {
            return 0.0;
        }
        $value = (float) $raw;
        return is_finite($value) && $value > 0 ? $value : 0.0;
    };
    $dcrSolarKwp = $solarSizeValue($mainSolarKwpRaw);
    $nonDcrSolarKwp = $solarSizeValue($complimentarySolarKwpRaw);
    $hasSolarSizeBreakup = $dcrSolarKwp > 0 || $nonDcrSolarKwp > 0;
    $totalSolarKwp = $dcrSolarKwp + $nonDcrSolarKwp;
    $monthlyBillBefore = (float) ($financialClarity['monthly_bill_before_rs'] ?? 0);
    $monthlyBillBeforeDisplay = $monthlyBillBefore > 0
        ? quotation_format_inr_indian($monthlyBillBefore, $showDecimals)
        : '—';
    $discountRsDisplay = max(0, (float) ($calc['discount_rs'] ?? $quote['discount_rs'] ?? 0));
    $discountApplicable = $discountRsDisplay > 0;
    $grossPayableLabel = $discountApplicable ? 'Gross payable (after discount)' : 'Gross payable';
    $grossPayableDisplay = (float) ($financialClarity['gross'] ?? $calc['gross_payable'] ?? 0);
    $heroSubsidyDisplay = (float) ($financialClarity['subsidy'] ?? $calc['subsidy_expected_rs'] ?? 0);
    $selfFundedScenario = is_array($financialClarity['finance_scenarios']['self_funded'] ?? null)
        ? $financialClarity['finance_scenarios']['self_funded']
        : [];
    $netInvestmentDisplay = (float) ($selfFundedScenario['net_investment_after_subsidy'] ?? max(0, $grossPayableDisplay - $heroSubsidyDisplay));

    $displayMultiline = static function (string $value): string {
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        $lines = array_values(array_filter($lines, static function (string $line): bool {
            return trim($line) !== '.';
        }));
        return trim(implode("\n", $lines));
    };
    $registrationProofs = array_values(array_filter([
        ['label' => 'GST Registered', 'value' => trim((string) ($company['gstin'] ?? ''))],
        ['label' => 'UDYAM Registered', 'value' => trim((string) ($company['udyam'] ?? ''))],
        ['label' => 'JREDA Registered', 'value' => trim((string) ($company['jreda_license'] ?? ''))],
        ['label' => 'DWSD Registered', 'value' => trim((string) ($company['dwsd_license'] ?? ''))],
    ], static function (array $proof): bool {
        return $proof['value'] !== '';
    }));

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
<html><head><meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quotation</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--teal-950:#073b3a;--teal-900:#0b4f4c;--teal-800:#0f6661;--teal-700:#0f766e;--teal-100:#ccfbf1;--teal-50:#f0fdfa;--gold:#e7ad20;--gold-soft:#fff8df;--ink:#102a2a;--muted:#5b6f70;--surface:#fff;--page:#f5f8f7;--line:#dce7e5;--success:#147a55;--success-soft:#edf9f2;--warning:#a35b08;--warning-soft:#fff7df;--shadow:0 14px 36px rgba(7,59,58,.08);--radius:18px;--base-font-size:<?= (int)($typo['base_px'] ?? 14) ?>px;--footer-bg:<?= htmlspecialchars($footerBg, ENT_QUOTES) ?>;--footer-text-color:<?= htmlspecialchars($footerText, ENT_QUOTES) ?>}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:radial-gradient(circle at top right,#e9f8f3 0,transparent 28rem),var(--page);color:var(--ink);font:var(--base-font-size)/1.6 Inter,"Segoe UI",Arial,sans-serif}.wrap{max-width:1180px;margin:0 auto;padding:24px}.card{position:relative;margin:0 0 18px;padding:24px;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}.h{font-weight:800}.sec{display:flex;align-items:center;gap:10px;margin:0 0 18px;padding:0;color:var(--teal-950);font-size:1.2rem;letter-spacing:-.01em}.sec:before{content:"";width:5px;height:22px;border-radius:8px;background:linear-gradient(180deg,var(--gold),var(--teal-700))}.section-kicker{margin-bottom:4px;color:var(--teal-700);font-size:.72rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase}.muted{color:var(--muted)}a{color:inherit}.header{overflow:hidden;padding:0;background:linear-gradient(125deg,var(--teal-950),var(--teal-700));color:#fff;border:0}.header:after{content:"";position:absolute;right:-85px;bottom:-130px;width:310px;height:310px;border:55px solid rgba(255,255,255,.06);border-radius:50%}.header-grid{position:relative;z-index:1;display:grid;grid-template-columns:minmax(0,1.45fr) minmax(270px,.75fr);gap:28px;padding:30px}.header-brand{display:flex;align-items:flex-start;gap:16px}.header-logo{flex:0 0 auto;display:grid;place-items:center;min-width:72px;min-height:72px;padding:8px;background:#fff;border-radius:16px}.header-logo img{display:block;max-width:130px;max-height:58px}.brand-name{font-size:1.55rem;font-weight:850;line-height:1.15;letter-spacing:-.02em}.brand-subtitle{margin-top:6px;color:#d9f5ef;font-size:.9rem}.contact-line,.credential-line{margin-top:10px;color:#edfdfa;font-size:.84rem}.contact-line{display:flex;flex-wrap:wrap;gap:6px 14px}.header-meta{align-self:stretch;padding:18px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.22);border-radius:16px;backdrop-filter:blur(6px)}.quote-label{color:#d5f4ed;font-size:.7rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase}.quote-number{margin:3px 0 12px;font-size:1.3rem;font-weight:850}.quote-status-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border:1px solid transparent;border-radius:999px;font-size:.78rem;font-weight:800}.quote-status-badge:before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}.quote-status-badge.draft{color:#824400;background:#fff2bd;border-color:#f4ce65}.quote-status-badge.accepted,.quote-status-badge.approved,.quote-status-badge.final{color:#0b5e43;background:#dff8e9;border-color:#9fddbb}.quote-status-badge.archived,.quote-status-badge.cancelled,.quote-status-badge.rejected{color:#8c2f2f;background:#feecec;border-color:#f4baba}.quote-meta-dates{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px}.meta-date{padding:9px 10px;background:rgba(0,0,0,.12);border-radius:10px}.meta-date span{display:block;color:#ccebe5;font-size:.68rem;font-weight:700;text-transform:uppercase}.meta-date b{font-size:.85rem}.trust-row{position:relative;z-index:1;display:flex;flex-wrap:wrap;gap:8px;padding:13px 30px;background:rgba(0,0,0,.16);border-top:1px solid rgba(255,255,255,.12)}.chip{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border:1px solid rgba(255,255,255,.2);border-radius:999px;background:rgba(255,255,255,.1);color:#fff;font-size:.75rem;font-weight:700}.grid2,.bank-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.customer-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.metric{padding:13px 14px;background:#f8fbfa;border:1px solid var(--line);border-radius:13px}.metric b,.metric .metric-label{display:block;margin-bottom:3px;color:var(--muted);font-size:.7rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase}.hero-card{overflow:hidden;padding:0;border-color:#b7ddd4;background:linear-gradient(140deg,#fff 40%,#ecfaf5)}.hero-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;padding:24px 26px 0}.hero-title{margin:0;color:var(--teal-950);font-size:1.45rem;line-height:1.25}.hero-subtitle{color:var(--muted);font-size:.87rem}.hero-layout{display:grid;grid-template-columns:minmax(250px,.78fr) minmax(0,1.7fr);gap:18px;padding:20px 26px 26px}.system-size-panel{display:flex;flex-direction:column;justify-content:center;padding:24px;background:linear-gradient(145deg,var(--teal-950),var(--teal-700));color:#fff;border-radius:16px}.system-size-panel .metric-label{color:#c9eee6;font-size:.72rem;font-weight:800;text-transform:uppercase}.system-size-value{margin:3px 0;font-size:2.8rem;font-weight:900;line-height:1;letter-spacing:-.06em}.system-size-breakdown{display:grid;gap:5px;margin-top:13px;padding-top:12px;border-top:1px solid rgba(255,255,255,.2)}.system-size-sub{color:#e4faf5;font-size:.78rem}.hero-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.hero-metric{min-height:94px;padding:14px;background:#fff;border:1px solid var(--line);border-radius:14px}.hero-metric .label{display:block;color:var(--muted);font-size:.72rem;font-weight:750}.hero-metric strong{display:block;margin-top:7px;color:var(--teal-950);font-size:1.18rem;line-height:1.2}.hero-metric.highlight{background:var(--success-soft);border-color:#adddc5}.hero-metric.highlight strong{color:var(--success)}.hero-metric.net{background:var(--teal-950);border-color:var(--teal-950)}.hero-metric.net .label{color:#bce7df}.hero-metric.net strong{color:#fff}.save-line{margin-top:10px;padding:12px 14px;color:#0a6546;background:#dff7e9;border-radius:12px;font-size:.88rem;font-weight:800}.friendly-note{background:linear-gradient(90deg,#fffdf5,#fff);border-left:5px solid var(--gold)}.friendly-note>div{color:#314848}.proposal-benefits{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:16px}.proposal-benefit{padding:10px 12px;background:#fff;border:1px solid #eee5c5;border-radius:10px;color:#3d514f;font-size:.8rem;font-weight:700}.table-wrap,.sf-finance-table-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:13px}table{width:100%;border-collapse:separate;border-spacing:0}th,td{padding:11px 12px;border:0;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}thead th{background:#eef6f4;color:var(--teal-950);font-size:.72rem;font-weight:850;letter-spacing:.04em;text-transform:uppercase}tbody tr:nth-child(even){background:#fbfdfc}tbody tr:last-child>td,tbody tr:last-child>th{border-bottom:0}.right{text-align:right}.center{text-align:center}.item-name{color:var(--teal-950);font-weight:850}.item-master-description,.item-custom-description{margin-top:7px;padding-left:13px;color:var(--muted);font-size:.84rem;line-height:1.6;white-space:pre-line;word-break:break-word;border-left:2px solid #cae3dc}.item-custom-description{color:#304d4b;font-weight:650}.pricing-card{border-color:#acd8cd}.pricing-table tbody tr.pricing-gross-row{background:#f5faf8}.pricing-gross-row td{color:var(--teal-950);font-size:1.06rem;font-weight:850}.pricing-table tbody tr:last-child{background:var(--teal-950);color:#fff}.pricing-table tbody tr:nth-last-child(2){background:var(--success-soft);color:var(--success);font-weight:800}.tax-summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:14px}.tax-summary-grid .metric div{color:var(--teal-950);font-size:1.08rem;font-weight:850}.tax-rate-line{display:block}.sf-finance-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:14px}.sf-finance-line{padding:9px 11px;background:#f5faf8;border-radius:9px;font-size:.78rem}.sf-finance-table{min-width:960px}.sf-finance-table th:first-child{position:sticky;left:0;z-index:2;background:#e8f3f0}.sf-finance-table tbody tr:nth-last-child(-n+3){background:#eef9f4}.sf-finance-table tbody th{white-space:nowrap;color:var(--teal-950)}.sf-glance-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.sf-glance-group{padding:16px;background:#f8fbfa;border:1px solid var(--line);border-radius:14px}.sf-glance-group h3{margin:0 0 10px;color:var(--teal-950);font-size:.95rem}.sf-glance-list{display:grid;gap:2px}.sf-glance-item{display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px dashed #d3e3df}.sf-glance-item:last-child{border-bottom:0}.sf-glance-item strong{color:var(--teal-800)}.chart-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.chart-responsive-card{min-width:0;break-inside:avoid;page-break-inside:avoid}.chart-responsive-card canvas{width:100%!important;height:320px!important}.cumulative-chart-card canvas{height:360px!important}.chart-print-img{display:none;width:100%;height:auto}.sf-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.sf-metric{padding:14px;background:#f8fbfa;border:1px solid var(--line);border-radius:12px}.sf-metric strong{display:block;min-height:42px;color:var(--teal-950);font-size:.8rem}.payback-meter{overflow:hidden;height:8px;margin-top:10px;background:#dcebe7;border-radius:20px}.payback-meter-fill{height:100%;background:linear-gradient(90deg,var(--gold),var(--teal-700));border-radius:20px}.proof-card{overflow:hidden;background:linear-gradient(135deg,var(--teal-950),#116d65);color:#fff;border:0}.proof-card .sec{color:#fff}.proof-card .sec:before{background:var(--gold)}.proof-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.proof-point{padding:13px 14px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.17);border-radius:12px;font-weight:700}.registration-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}.registration-chip{padding:6px 10px;background:#fff;color:var(--teal-950);border-radius:999px;font-size:.72rem;font-weight:850}.annexure-shell{background:#f0f6f4}.annexure-stack{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.annexure-card{padding:18px;background:#fff;border:1px solid var(--line);border-radius:14px;break-inside:avoid;page-break-inside:avoid}.annexure-card.wide{grid-column:1/-1}.annexure-title{display:flex;align-items:center;gap:8px;margin-bottom:11px;color:var(--teal-950);font-size:1rem;font-weight:850}.annexure-title span{display:grid;place-items:center;width:27px;height:27px;background:var(--teal-50);color:var(--teal-700);border-radius:8px;font-size:.74rem}.annexure-card ul,.friendly-note ul{padding-left:20px}.annexure-card li{margin:5px 0}.annexure-card table{font-size:.85rem}.next-steps{border-color:#e1c56f;background:linear-gradient(90deg,#fffaf0,#fff)}.proceed-note{margin-top:14px;padding:11px 13px;background:var(--gold-soft);border:1px solid #ecd27f;border-radius:10px;color:#62480a;font-weight:800}.bank-card{border:2px solid #b8d9d1}.bank-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.upi-payment-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:16px}.upi-option{display:grid;grid-template-columns:130px 1fr;gap:14px;align-items:center;padding:14px;background:#f8fbfa;border:1px solid var(--line);border-radius:14px}.upi-option img{display:block;width:130px;height:130px;background:#fff}.upi-option h3{margin:0 0 5px;color:var(--teal-950);font-size:1rem}.upi-option p{margin:4px 0;color:var(--muted);font-size:.8rem}.upi-id{font-weight:850;color:var(--teal-800)!important}.payment-note{margin-top:12px;color:var(--muted);font-size:.76rem}.payment-warning{margin-top:14px;padding:10px 12px;background:#fff8e6;border-radius:10px;color:#795514;font-size:.8rem;font-weight:750}.footer{background:var(--footer-bg);color:var(--footer-text-color);box-shadow:none}.footer-brand-row{display:flex;align-items:center;gap:10px;margin-bottom:10px}.footer-logo{display:grid;place-items:center;padding:5px;background:#fff;border-radius:8px}.footer-logo img{display:block;max-width:110px;max-height:32px}.footer-brand-name{font-size:1.12rem;font-weight:850}.footer-details{display:grid;gap:4px;font-size:.8rem}.acceptance-card{border:2px solid #89cdbd;background:linear-gradient(135deg,#e9faf4,#fff)}.acceptance-card .sec{font-size:1.35rem}.acceptance-card p{max-width:760px;color:#365b54}.acceptance-btn{display:inline-block;margin-top:8px;padding:13px 22px;border-radius:10px;background:var(--teal-700);color:#fff;text-decoration:none;font-weight:850;box-shadow:0 8px 18px rgba(15,118,110,.22)}.acceptance-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:14px}.acceptance-details .metric{background:#fff}.screen-only,.hide-print{display:block}
@media(max-width:900px){.wrap{padding:14px}.header-grid,.hero-layout{grid-template-columns:1fr}.customer-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.hero-metrics,.sf-glance-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.proposal-benefits,.sf-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.chart-grid{grid-template-columns:1fr}.annexure-stack{grid-template-columns:1fr}.annexure-card.wide{grid-column:auto}.bank-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){body{font-size:13px}.wrap{padding:8px}.card{padding:16px;margin-bottom:12px;border-radius:14px}.header{padding:0}.header-grid{padding:20px}.header-brand{flex-direction:column}.header-meta{padding:14px}.quote-meta-dates,.customer-grid,.grid2,.hero-metrics,.sf-glance-grid,.proposal-benefits,.sf-kpis,.proof-grid,.bank-grid,.tax-summary-grid{grid-template-columns:1fr}.trust-row{padding:11px 20px}.hero-heading{padding:20px 20px 0}.hero-layout{padding:15px 20px 20px}.system-size-value{font-size:2.4rem}.sf-finance-summary{grid-template-columns:1fr}.chart-responsive-card canvas{height:280px!important}.table-wrap{margin-left:-6px;margin-right:-6px}.sec{font-size:1.08rem}}
@page{size:A4;margin:12mm 10mm 14mm}
@media print{*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}body{background:#fff;font-size:9.5pt;line-height:1.42}.wrap{max-width:none;padding:0}.card{margin:0 0 8mm;padding:5mm;border-radius:3mm;box-shadow:none;break-inside:avoid;page-break-inside:avoid}.header{padding:0}.header-grid{grid-template-columns:1.35fr .65fr;gap:5mm;padding:6mm}.header-logo{min-width:15mm;min-height:15mm}.trust-row{padding:3mm 6mm}.chip{padding:1mm 2mm;font-size:6.8pt}.customer-grid{grid-template-columns:repeat(4,1fr);gap:2mm}.hero-layout{grid-template-columns:.75fr 1.6fr;gap:3mm;padding:4mm 6mm 6mm}.hero-heading{padding:5mm 6mm 0}.system-size-panel{padding:5mm}.system-size-value{font-size:24pt}.hero-metrics{grid-template-columns:repeat(3,1fr);gap:2mm}.hero-metric{min-height:18mm;padding:3mm}.proposal-benefits{grid-template-columns:repeat(4,1fr)}.table-wrap,.sf-finance-table-wrap{overflow:visible}.sf-finance-table{min-width:0;font-size:6.6pt}.sf-finance-table th,.sf-finance-table td{padding:1.6mm}.sf-finance-table th:first-child{position:static}.sf-finance-summary{grid-template-columns:repeat(3,1fr)}.sf-glance-grid{grid-template-columns:repeat(3,1fr)}.chart-grid{display:block}.chart-responsive-card{min-height:95mm}.chart-responsive-card canvas{display:none!important}.chart-print-img{display:block!important;max-height:90mm;object-fit:contain}.sf-kpis{grid-template-columns:repeat(3,1fr)}.proof-grid{grid-template-columns:repeat(2,1fr)}.annexure-stack{grid-template-columns:repeat(2,1fr);gap:3mm}.annexure-card{padding:4mm}.bank-grid{grid-template-columns:repeat(3,1fr)}.upi-payment-grid{grid-template-columns:repeat(2,1fr);gap:3mm}.upi-option{grid-template-columns:28mm 1fr;gap:3mm;padding:3mm}.upi-option img{width:28mm;height:28mm}thead{display:table-header-group}tr{break-inside:avoid;page-break-inside:avoid}.hide-print,.screen-only{display:none!important}.footer{break-inside:avoid;page-break-inside:avoid}.sec{margin-bottom:3mm}.cumulative-chart-card{page-break-before:auto}}
</style>
</head><body>
<div id="quotation-root" class="wrap">
<section class="card header">
  <div class="header-grid">
    <div class="header-brand">
      <?php if ($hasLogo): ?><div class="header-logo"><img src="<?= htmlspecialchars($logoSrc, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($companyName, ENT_QUOTES) ?> logo"></div><?php endif; ?>
      <div><div class="brand-name"><?= htmlspecialchars($companyName, ENT_QUOTES) ?></div><div class="brand-subtitle">Registered office: <?= htmlspecialchars((string)($company['address_line'] ?? ''), ENT_QUOTES) ?><?= trim((string)($company['city'] ?? '')) !== '' ? ', ' . htmlspecialchars((string)$company['city'], ENT_QUOTES) : '' ?></div><div class="contact-line"><span><?= htmlspecialchars($phonePrimary, ENT_QUOTES) ?></span><?php if ($whatsappDisplay !== ''): ?><span>WhatsApp <?= htmlspecialchars($whatsappDisplay, ENT_QUOTES) ?></span><?php endif; ?><span><?= htmlspecialchars((string)($company['email_primary'] ?? ''), ENT_QUOTES) ?></span><?php if ($website !== ''): ?><span><?= htmlspecialchars($website, ENT_QUOTES) ?></span><?php endif; ?></div><div class="credential-line">GSTIN <?= htmlspecialchars((string)($company['gstin'] ?? ''), ENT_QUOTES) ?> · UDYAM <?= htmlspecialchars((string)($company['udyam'] ?? ''), ENT_QUOTES) ?> · PAN <?= htmlspecialchars((string)($company['pan'] ?? ''), ENT_QUOTES) ?><?php if (trim((string)($company['jreda_license'] ?? '')) !== ''): ?> · JREDA <?= htmlspecialchars((string)$company['jreda_license'], ENT_QUOTES) ?><?php endif; ?><?php if (trim((string)($company['dwsd_license'] ?? '')) !== ''): ?> · DWSD <?= htmlspecialchars((string)$company['dwsd_license'], ENT_QUOTES) ?><?php endif; ?></div></div>
    </div>
    <div class="header-meta"><div class="quote-label">Solar quotation</div><div class="quote-number"><?= htmlspecialchars((string)($quote['quote_no'] ?? ''), ENT_QUOTES) ?></div><span class="quote-status-badge <?= htmlspecialchars($statusBadgeClass, ENT_QUOTES) ?>"><?= htmlspecialchars($statusBadgeLabel, ENT_QUOTES) ?></span><div class="quote-meta-dates"><div class="meta-date"><span>Quotation date</span><b><?= htmlspecialchars($quotationDateDisplay, ENT_QUOTES) ?></b></div><div class="meta-date"><span>Valid until</span><b><?= htmlspecialchars($validUntilDisplay, ENT_QUOTES) ?></b></div></div></div>
  </div>
  <div class="trust-row"><span class="chip">MNRE compliant</span><?php if ($segment === 'RES'): ?><span class="chip">PM Surya Ghar eligible</span><?php endif; ?><span class="chip">Net metering supported</span><span class="chip">25+ year life / warranty</span><?php if (trim((string)($company['jreda_license'] ?? '')) !== ''): ?><span class="chip">JREDA Registered</span><?php endif; ?><?php if (trim((string)($company['dwsd_license'] ?? '')) !== ''): ?><span class="chip">DWSD Registered</span><?php endif; ?></div>
</section>
<?php if ($showAcceptanceAction): ?><section class="card acceptance-card"><div class="section-kicker">Customer confirmation</div><div class="h sec">Ready to proceed?</div><p>Please review this quotation carefully. If the scope, price and terms are acceptable, confirm your acceptance below.</p><a class="acceptance-btn screen-only" href="<?= htmlspecialchars($acceptanceUrl, ENT_QUOTES) ?>">Accept Quotation</a></section><?php elseif ($showAcceptanceResult): ?><section class="card acceptance-card"><div class="section-kicker">Customer confirmation</div><div class="h sec">Quotation accepted</div><div class="acceptance-details"><div class="metric"><span class="metric-label">Acceptance date/time</span><div><?= htmlspecialchars((string)($acceptance['confirmed_at'] ?? '—'), ENT_QUOTES) ?></div></div><div class="metric"><span class="metric-label">Acceptance reference</span><div><?= htmlspecialchars((string)($acceptance['acceptance_ref'] ?? '—'), ENT_QUOTES) ?></div></div><div class="metric"><span class="metric-label">Confirmation evidence</span><div>Customer confirmed through a secure document link and registered-mobile verification.</div></div></div><?php if ($acceptanceWhatsappUrl !== ''): ?><a class="acceptance-btn screen-only" href="<?= htmlspecialchars($acceptanceWhatsappUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener">Open WhatsApp to send confirmation</a><?php endif; ?></section><?php endif; ?>
<?php if ($customerSiteFields !== []): ?><section class="card"><div class="section-kicker">Prepared for</div><div class="h sec">Customer &amp; Site Details</div><div class="customer-grid"><?php foreach ($customerSiteFields as $field): ?><div class="metric"><span class="metric-label"><?= htmlspecialchars((string)($field['label'] ?? ''), ENT_QUOTES) ?></span><div><?= nl2br(htmlspecialchars((string)($field['value'] ?? ''), ENT_QUOTES)) ?></div></div><?php endforeach; ?></div></section><?php endif; ?>
<section class="card hero-card"><div class="hero-heading"><div><div class="section-kicker">Designed around your energy needs</div><h1 class="hero-title">Your Recommended Solar Plan</h1><div class="hero-subtitle">A clear view of the system, savings, subsidy and investment.</div></div></div><div class="hero-layout"><div class="system-size-panel"><span class="metric-label"><?= $hasSolarSizeBreakup ? 'Total Solar System Size' : 'Solar System Size' ?></span><div class="system-size-value"><?= htmlspecialchars($hasSolarSizeBreakup ? number_format($totalSolarKwp, 2, '.', '') : (string)($quote['capacity_kwp'] ?? '0'), ENT_QUOTES) ?> <small>kWp</small></div><?php if ($hasSolarSizeBreakup): ?><div class="system-size-breakdown"><?php if ($dcrSolarKwp > 0): ?><span class="system-size-sub">DCR Solar Size · <?= htmlspecialchars(number_format($dcrSolarKwp, 2, '.', ''), ENT_QUOTES) ?> kWp</span><?php endif; ?><?php if ($nonDcrSolarKwp > 0): ?><span class="system-size-sub">Non-DCR Solar Size · <?= htmlspecialchars(number_format($nonDcrSolarKwp, 2, '.', ''), ENT_QUOTES) ?> kWp</span><?php endif; ?></div><?php endif; ?></div><div><div class="hero-metrics"><div class="hero-metric"><span class="label">Monthly bill without solar</span><strong><?= htmlspecialchars($monthlyBillBeforeDisplay, ENT_QUOTES) ?></strong></div><div class="hero-metric highlight"><span class="label">Estimated monthly saving</span><strong id="heroSaving">—</strong></div><div class="hero-metric"><span class="label">Residual bill</span><strong id="heroResidual">—</strong></div><div class="hero-metric"><span class="label"><?= htmlspecialchars($grossPayableLabel, ENT_QUOTES) ?></span><strong><?= quotation_format_inr_indian($grossPayableDisplay, $showDecimals) ?></strong></div><div class="hero-metric highlight"><span class="label">Subsidy expected</span><strong id="heroSubsidy"><?= quotation_format_inr_indian($heroSubsidyDisplay, $showDecimals) ?></strong></div><div class="hero-metric net"><span class="label">Net investment after subsidy</span><strong><?= quotation_format_inr_indian($netInvestmentDisplay, $showDecimals) ?></strong></div></div><div class="save-line">Your solar plan is designed to reduce monthly electricity outflow while building long-term energy value.</div></div></div></section>
<?php if ($coverNote !== ''): ?><section class="card friendly-note"><div class="section-kicker">A note for your home</div><div class="h sec">Dear Homeowner</div><div><?= quotation_sanitize_html($coverNote) ?></div><div class="proposal-benefits"><div class="proposal-benefit">Clear system scope</div><div class="proposal-benefit">Transparent pricing</div><div class="proposal-benefit">Subsidy guidance</div><div class="proposal-benefit">Installation &amp; support</div></div></section><?php endif; ?>
<section class="card"><div class="section-kicker">Proposed scope</div><div class="h sec">Item Summary</div><div class="table-wrap"><table><thead><tr><th>Sr No</th><th>Item and Description</th><th>HSN</th><th class="center">Quantity</th><th class="center">Unit</th></tr></thead><tbody><?php if ($itemRows === []): ?><tr><td colspan="5" class="center muted">No line items added.</td></tr><?php else: foreach ($itemRows as $idx => $item): ?><tr><td><?= (int)$idx + 1 ?></td><td><div class="item-name"><?= htmlspecialchars((string)($item['name'] ?? ''), ENT_QUOTES) ?></div><?php $itemDesc=$displayMultiline((string)($item['description'] ?? '')); if ($itemDesc !== ''): ?><div class="item-master-description"><?= htmlspecialchars($itemDesc, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?><?php $customDesc=$displayMultiline((string)($item['custom_description'] ?? '')); if ($customDesc !== ''): ?><div class="item-custom-description"><?= htmlspecialchars($customDesc, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?></td><td><?= htmlspecialchars((string)($item['hsn'] ?? ''), ENT_QUOTES) ?></td><td class="center"><?= htmlspecialchars((string)($item['qty'] ?? ''), ENT_QUOTES) ?></td><td class="center"><?= htmlspecialchars((string)($item['unit'] ?? ''), ENT_QUOTES) ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
<?php if($specialReq!==''): ?><section class="card"><div class="h sec">Special Requests From Consumer (Inclusive in the rate)</div><div><?= quotation_sanitize_html($specialReq) ?></div><div class="muted"><i>In case of conflict between annexures and special requests, special requests will be prioritized.</i></div></section><?php endif; ?>
<section class="card pricing-card"><div class="section-kicker">Investment overview</div><div class="h sec">Pricing Summary</div><div class="table-wrap"><table class="pricing-table"><thead><tr><th>#</th><th>Particular</th><th class="right">Amount</th></tr></thead><tbody><tr><td>1</td><td>Total system price incl GST</td><td class="right"><?= quotation_format_inr_indian($pricingSummarySystemPrice, $showDecimals) ?></td></tr><tr><td>2</td><td>Transportation</td><td class="right"><?= quotation_format_inr_indian((float)($calc['transportation_rs'] ?? 0), $showDecimals) ?></td></tr><?php if ($discountApplicable): ?><tr><td>3</td><td>Discount<?php $discountNote=(string)($calc['discount_note'] ?? ''); if(trim($discountNote)!==''): ?><div class="muted"><?= htmlspecialchars($discountNote, ENT_QUOTES) ?></div><?php endif; ?></td><td class="right">- <?= quotation_format_inr_indian($discountRsDisplay, $showDecimals) ?></td></tr><?php endif; ?><tr class="pricing-gross-row"><td><?= $discountApplicable ? '4' : '3' ?></td><td><?= htmlspecialchars($grossPayableLabel, ENT_QUOTES) ?></td><td class="right" id="upfront"></td></tr><tr><td><?= $discountApplicable ? '5' : '4' ?></td><td>Subsidy expected</td><td class="right"><?= quotation_format_inr_indian((float)($calc['subsidy_expected_rs'] ?? 0), $showDecimals) ?></td></tr><tr><td><?= $discountApplicable ? '6' : '5' ?></td><td><b>Net Investment/Cost After Subsidy Credit</b></td><td class="right"><b id="upfrontNet"></b></td></tr></tbody></table></div></section>
<?php if ($showTaxBreakup): ?><section class="card"><div class="section-kicker">Compact statutory detail</div><div class="h sec">Tax Breakup</div><div class="tax-summary-grid"><div class="metric"><b>Basic Value</b><div><?= quotation_format_inr_indian($taxSummaryBasic, $showDecimals) ?></div></div><div class="metric"><b>Total GST</b><div><?= quotation_format_inr_indian($taxSummaryGst, $showDecimals) ?></div></div><div class="metric"><b>Total incl GST</b><div><?= quotation_format_inr_indian($taxSummaryGross, $showDecimals) ?></div></div></div><div class="table-wrap"><table><thead><tr><th>Sr. No.</th><th>Item</th><th>HSN Code</th><th class="right">Basic Value</th><th>GST Rate(s)</th><th class="right">GST Amount</th><th class="right">Total incl GST</th></tr></thead><tbody><?php if ($taxItems === []): ?><tr><td colspan="7" class="center muted">Detailed slab breakup is not available for this quotation.</td></tr><?php else: foreach ($taxItems as $idx => $taxItem): $slabs = is_array($taxItem['slabs'] ?? null) ? $taxItem['slabs'] : []; ?><tr><td><?= (int) $idx + 1 ?></td><td><?= htmlspecialchars((string) ($taxItem['name'] ?? 'Item'), ENT_QUOTES) ?></td><td><?= htmlspecialchars(trim((string) ($taxItem['hsn'] ?? '')) !== '' ? (string) $taxItem['hsn'] : '—', ENT_QUOTES) ?></td><td class="right"><?= quotation_format_inr_indian((float) ($taxItem['taxable_value'] ?? 0), $showDecimals) ?></td><td><?php if ($slabs === []): ?><span class="muted">—</span><?php else: foreach ($slabs as $slab): $shareText = rtrim(rtrim(number_format((float) ($slab['share_pct'] ?? 0), 2, '.', ''), '0'), '.'); $rateText = rtrim(rtrim(number_format((float) ($slab['rate_pct'] ?? 0), 2, '.', ''), '0'), '.'); ?><span class="tax-rate-line"><?= htmlspecialchars($shareText . '% @ ' . $rateText . '%', ENT_QUOTES) ?></span><?php endforeach; endif; ?></td><td class="right"><?= quotation_format_inr_indian((float) ($taxItem['gst_amount'] ?? 0), $showDecimals) ?></td><td class="right"><?= quotation_format_inr_indian((float) ($taxItem['gross_incl_gst'] ?? 0), $showDecimals) ?></td></tr><?php endforeach; endif; ?></tbody></table></div><?php if ($taxItems !== [] && $taxItemAllocationBasis === 'quantity'): ?><div class="muted" style="margin-top:8px;font-size:.8rem">Item-wise basic allocation is distributed using item quantity because line-level taxable value was not stored in this quotation.</div><?php endif; ?></section><?php endif; ?>
<?php
$scenarioOrder = [
    'self_funded' => 'Self Funded',
    'loan_upto_2_lacs_subsidy_to_loan' => 'Loan up to ₹2 lacs (subsidy to loan)',
    'loan_upto_2_lacs_subsidy_not_to_loan' => 'Loan up to ₹2 lacs (subsidy self kept)',
    'loan_above_2_lacs_subsidy_to_loan' => 'Loan above ₹2 lacs (subsidy to loan)',
    'loan_above_2_lacs_subsidy_not_to_loan' => 'Loan above ₹2 lacs (subsidy self kept)',
];
$scenarioRows = is_array($financialClarity['finance_scenarios'] ?? null) ? $financialClarity['finance_scenarios'] : [];
?>
<section class="card"><div class="section-kicker">Every funding path, side by side</div><div class="h sec">Detailed Financial Summary</div><div id="financeBoxes"></div></section>
<section class="card sf-glance-wrap"><div class="section-kicker">The outcome in simple numbers</div><div class="h sec">Solar at a Glance</div><div class="sf-glance-grid" id="glancePanel"></div></section>
<div class="chart-grid"><section class="card chart-responsive-card"><div class="h sec">Monthly Outflow Comparison</div><canvas id="monthlyChart" height="260"></canvas><img id="monthlyChartPrint" class="chart-print-img" alt="Monthly outflow chart for print"></section><section class="card chart-responsive-card cumulative-chart-card"><div class="h sec">Cumulative Expense Over 25 Years</div><canvas id="cumulativeChart" height="360"></canvas><img id="cumulativeChartPrint" class="chart-print-img" alt="Cumulative expense chart for print"></section></div>
<section class="card"><div class="h sec">Payback Meters</div><div id="paybackMeters" class="sf-kpis"></div></section>
<section class="card proof-card"><div class="section-kicker" style="color:#bfe9df">Local capability. Professional delivery.</div><div class="h sec">Why <?= htmlspecialchars($companyName, ENT_QUOTES) ?></div><div class="proof-grid"><?php foreach ($whyPoints as $point): ?><div class="proof-point"><?= htmlspecialchars((string)$point, ENT_QUOTES) ?></div><?php endforeach; ?></div><?php if ($registrationProofs !== []): ?><div class="registration-row"><?php foreach ($registrationProofs as $proof): ?><span class="registration-chip"><?= htmlspecialchars((string)$proof['label'], ENT_QUOTES) ?> · <?= htmlspecialchars((string)$proof['value'], ENT_QUOTES) ?></span><?php endforeach; ?></div><?php endif; ?></section>
<section class="card annexure-shell"><div class="section-kicker">Proposal details</div><div class="h sec">Annexures</div><div class="annexure-stack"><?php $annexureDisplay = ['warranty'=>['Warranty','01'],'system_inclusions'=>['System Inclusions','02'],'pm_subsidy_info'=>['PM Subsidy Information','03'],'completion_milestones'=>['Completion Milestones','04'],'payment_terms'=>['Payment Terms','05'],'system_type_explainer'=>['System Type Explainer','06'],'transportation'=>['Transportation','07'],'terms_conditions'=>['Terms and Conditions','08']]; foreach($annexureDisplay as $k=>$meta): $annVal = trim((string)($ann[$k] ?? '')); if ($annVal === '') { continue; } ?><article class="annexure-card <?= in_array($k, ['system_inclusions','terms_conditions'], true) ? 'wide' : '' ?>"><div class="annexure-title"><span><?= htmlspecialchars($meta[1], ENT_QUOTES) ?></span><?= htmlspecialchars($meta[0], ENT_QUOTES) ?></div><div><?= quotation_sanitize_html($annVal) ?></div></article><?php endforeach; ?></div></section>
<?php $nextStepsHtml = trim((string)($ann['next_steps'] ?? '')); if ($nextStepsHtml !== ''): ?><section class="card next-steps"><div class="section-kicker">Ready when you are</div><div class="h sec">Next Steps</div><div><?= quotation_sanitize_html($nextStepsHtml) ?></div><div class="proceed-note">To proceed, confirm the quotation and pay the token advance as per payment terms.</div></section><?php endif; ?>
<section class="card bank-card" id="quotation-payment-options"><div class="section-kicker">Secure payment information</div><div class="h sec">Payment Options</div><?php if ($bankFields !== []): ?><div class="bank-grid"><?php foreach ($bankFields as $bankField): ?><div class="metric"><span class="metric-label"><?= htmlspecialchars((string)$bankField['label'], ENT_QUOTES) ?></span><div><?= htmlspecialchars((string)$bankField['value'], ENT_QUOTES) ?></div></div><?php endforeach; ?></div><?php endif; ?><div class="upi-payment-grid"><article class="upi-option"><img src="assets/images/payments/upi-advance-10000.svg" alt="Scan UPI QR to pay fixed ₹10,000 booking advance"><div><h3>₹10,000 booking advance</h3><p>Scan this fixed-amount QR to pay the booking/token advance.</p><p class="upi-id">UPI ID: <?= htmlspecialchars($upiId, ENT_QUOTES) ?></p><a class="btn screen-only" href="<?= htmlspecialchars($upiAdvanceUrl, ENT_QUOTES) ?>">Pay ₹10,000 by UPI</a></div></article><article class="upi-option"><img src="assets/images/payments/upi-any-amount.svg" alt="Scan UPI QR to pay any amount"><div><h3>Pay any amount</h3><p>Use this QR for another agreed payment amount.</p><p class="upi-id">UPI ID: <?= htmlspecialchars($upiId, ENT_QUOTES) ?></p><a class="btn screen-only" href="<?= htmlspecialchars($upiAnyAmountUrl, ENT_QUOTES) ?>">Open UPI app</a></div></article></div><p class="payment-note">After payment, share the transaction reference with our team. A receipt is issued only after payment verification.</p><div class="payment-warning">Please verify the payee and UPI ID before payment. Payments should be made only to <?= htmlspecialchars($companyName, ENT_QUOTES) ?>.</div></section>
<footer class="card footer"><div class="footer-brand-row"><?php if ($hasLogo): ?><div class="footer-logo"><img src="<?= htmlspecialchars($logoSrc, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($companyName, ENT_QUOTES) ?> logo"></div><?php endif; ?><div class="footer-brand-name"><?= htmlspecialchars($companyName, ENT_QUOTES) ?></div></div><div class="footer-details"><div>Registered office: <?= htmlspecialchars((string)($company['address_line'] ?? ''), ENT_QUOTES) ?></div><div><?= htmlspecialchars((string)($company['phone_primary'] ?? ''), ENT_QUOTES) ?><?= $whatsappDisplay !== '' ? ' · WhatsApp ' . htmlspecialchars($whatsappDisplay, ENT_QUOTES) : '' ?> · <?= htmlspecialchars((string)($company['email_primary'] ?? ''), ENT_QUOTES) ?><?= $website !== '' ? ' · ' . htmlspecialchars($website, ENT_QUOTES) : '' ?></div><div>GSTIN: <?= htmlspecialchars((string)($company['gstin'] ?? ''), ENT_QUOTES) ?> · UDYAM: <?= htmlspecialchars((string)($company['udyam'] ?? ''), ENT_QUOTES) ?> · PAN: <?= htmlspecialchars((string)($company['pan'] ?? ''), ENT_QUOTES) ?> · JREDA: <?= htmlspecialchars((string)($company['jreda_license'] ?? ''), ENT_QUOTES) ?> · DWSD: <?= htmlspecialchars((string)($company['dwsd_license'] ?? ''), ENT_QUOTES) ?></div><div><small><?= htmlspecialchars((string)($quoteDefaults['global']['quotation_ui']['footer_disclaimer'] ?? 'Values are indicative and subject to site conditions, DISCOM approvals, and policy updates.'), ENT_QUOTES) ?></small></div></div></footer>
</div>
<script>
const finance=<?= json_encode($financialClarity, JSON_UNESCAPED_UNICODE) ?>;
const showDecimals=<?= json_encode($showDecimals) ?>;
const r=x=>'₹'+Number(x||0).toLocaleString('en-IN',{minimumFractionDigits:showDecimals?2:0,maximumFractionDigits:showDecimals?2:0});
const nUnits=x=>Number(x||0).toLocaleString('en-IN',{maximumFractionDigits:0});
const num=v=>{const n=Number(v);return Number.isFinite(n)?n:0;};
const scenarioOrder=['self_funded','loan_upto_2_lacs_subsidy_to_loan','loan_upto_2_lacs_subsidy_not_to_loan','loan_above_2_lacs_subsidy_to_loan','loan_above_2_lacs_subsidy_not_to_loan'];
const scenarioLabels={
  self_funded:'Self Funded',
  loan_upto_2_lacs_subsidy_to_loan:'Loan up to ₹2 lacs (subsidy to loan)',
  loan_upto_2_lacs_subsidy_not_to_loan:'Loan up to ₹2 lacs (subsidy self kept)',
  loan_above_2_lacs_subsidy_to_loan:'Loan above ₹2 lacs (subsidy to loan)',
  loan_above_2_lacs_subsidy_not_to_loan:'Loan above ₹2 lacs (subsidy self kept)'
};
const scenarioColors={
  self_funded:'#f59e0b',
  loan_upto_2_lacs_subsidy_to_loan:'#0f766e',
  loan_upto_2_lacs_subsidy_not_to_loan:'#14b8a6',
  loan_above_2_lacs_subsidy_to_loan:'#1d4ed8',
  loan_above_2_lacs_subsidy_not_to_loan:'#6366f1'
};
const scenarios=(finance&&typeof finance==='object'&&finance.finance_scenarios&&typeof finance.finance_scenarios==='object')?finance.finance_scenarios:{};
const monthlyBill=Math.max(0,num(finance.no_solar_monthly_bill_rs));
const getScenario=(key)=>{const row=scenarios[key];return row&&typeof row==='object'?row:{};};
const isScenarioApplicable=(key)=>{
  const row=getScenario(key);
  if(key.includes('loan_above_2_lacs')){return !!row.applicable;}
  return !Object.prototype.hasOwnProperty.call(row,'applicable') || !!row.applicable;
};
const orderedApplicableScenarios=scenarioOrder.filter((key)=>isScenarioApplicable(key));
const selfScenario=getScenario('self_funded');
const loanUp2Scenario=getScenario('loan_upto_2_lacs_subsidy_to_loan');
const loanUp2NotScenario=getScenario('loan_upto_2_lacs_subsidy_not_to_loan');
const loanAbove2Scenario=getScenario('loan_above_2_lacs_subsidy_to_loan');
const loanAbove2NotScenario=getScenario('loan_above_2_lacs_subsidy_not_to_loan');
const selfOutflow=Math.max(0,num(selfScenario.monthly_outflow_rs));
const loanUp2Outflow=Math.max(0,num(loanUp2Scenario.monthly_outflow_rs));
const loanUp2NotOutflow=Math.max(0,num(loanUp2NotScenario.monthly_outflow_rs));
const loanAbove2Outflow=Math.max(0,num(loanAbove2Scenario.monthly_outflow_rs));
const loanAbove2NotOutflow=Math.max(0,num(loanAbove2NotScenario.monthly_outflow_rs));
const selfResidual=Math.max(0,num(selfScenario.residual_bill_rs));
const selfNetInvestment=Math.max(0,num(selfScenario.net_investment_after_subsidy));
const monthlySaving=Math.max(0,monthlyBill-selfOutflow);
const annualSaving=monthlySaving*12;
const saving25=annualSaving*25;
const annualUnits=Math.max(0,num(finance.capacity_kwp)*num(finance.annual_generation_kwh_per_kw));
const monthlyUnits=annualUnits/12;
const units25=annualUnits*25;
const roofArea= Math.max(0,num(finance.capacity_kwp)*100);
const billOffset=Math.min(100,(monthlyBill>0?(monthlyUnits*num(finance.unit_rate_rs_per_kwh))/monthlyBill*100:0));
const annualCo2=annualUnits*<?= json_encode($emissionFactor) ?>;
const co225=annualCo2*25;
const treeFactor=Math.max(0.1,<?= json_encode($treeAbsorption) ?>);
const annualTrees=annualCo2/treeFactor;
const trees25=co225/treeFactor;
const setText=(id,val)=>{const node=document.getElementById(id);if(node){node.textContent=val;}};
setText('upfront',r(num(finance.gross)));
setText('upfrontNet',r(selfNetInvestment));
setText('heroSubsidy',r(num(finance.subsidy)));
setText('heroResidual',r(num(finance.residual_bill)));
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
    ['No Solar',r(monthlyBill)],
    ...orderedApplicableScenarios.map((scenarioKey)=>{
      const row=getScenario(scenarioKey);
      return [`${scenarioLabels[scenarioKey]} — Monthly Outflow`,r(num(row.monthly_outflow_rs))];
    }),
    ...orderedApplicableScenarios.map((scenarioKey)=>{
      const row=getScenario(scenarioKey);
      return [`${scenarioLabels[scenarioKey]} — Payback`,String(row.payback_display||fmtMonths(num(row.payback_months)))];
    })
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
orderedApplicableScenarios.forEach((scenarioKey)=>{
  const row=getScenario(scenarioKey);
  monthlyLabels.push(scenarioLabels[scenarioKey]);
  monthlyData.push(Math.max(0,num(row.monthly_outflow_rs)));
  monthlyColors.push(scenarioColors[scenarioKey]||'#0f766e');
});
const years=[...Array(26).keys()];
const cumulativeDatasets=[{label:'No Solar',data:years.map(y=>y*12*monthlyBill),borderColor:'#9ca3af',backgroundColor:'#9ca3af'}];
orderedApplicableScenarios.forEach((scenarioKey)=>{
  const row=getScenario(scenarioKey);
  const data=Array.isArray(row.cumulative_series)&&row.cumulative_series.length===years.length
    ? row.cumulative_series.map((point)=>Math.max(0,num(point)))
    : years.map((year)=>{
      const months=year*12;
      if(scenarioKey==='self_funded'){return Math.max(0,num(row.net_investment_after_subsidy))+(months*Math.max(0,num(row.residual_bill_rs)));}
      const start=scenarioKey.includes('subsidy_not_to_loan')?Math.max(0,num(row.initial_investment_after_subsidy_credit_rs||row.net_own_investment_after_subsidy)):Math.max(0,num(row.margin_money_rs));
      const tenureMonths=Math.max(0,Math.round(num(row.tenure_months)||((num(row.tenure_years)||0)*12)));
      const emi=Math.max(0,num(row.emi_rs));
      const residual=Math.max(0,num(row.residual_bill_rs));
      const monthsWithinTenure=Math.min(months,tenureMonths);
      const monthsAfterTenure=Math.max(0,months-tenureMonths);
      return start+(monthsWithinTenure*(emi+residual))+(monthsAfterTenure*residual);
    });
  cumulativeDatasets.push({label:scenarioLabels[scenarioKey],data,borderColor:scenarioColors[scenarioKey]||'#0f766e',backgroundColor:scenarioColors[scenarioKey]||'#0f766e'});
});

const paybackMeters=document.getElementById('paybackMeters');
if(paybackMeters){
  const meterItems=orderedApplicableScenarios.map((scenarioKey)=>{
    const row=getScenario(scenarioKey);
    return [`Payback meter (${scenarioLabels[scenarioKey]})`,String(row.payback_display||fmtMonths(num(row.payback_months))),Math.max(0,num(row.payback_months))];
  });
  paybackMeters.innerHTML=meterItems.map(([label,val,months])=>{const pct=Number.isFinite(months)?Math.max(0,Math.min(100,(months/(25*12))*100)):0;return `<div class="sf-metric"><strong>${label}</strong><div>${val}</div><div class="payback-meter"><div class="payback-meter-fill" style="width:${pct.toFixed(1)}%"></div></div></div>`;}).join('');
}

const financeBoxes=document.getElementById('financeBoxes');
if(financeBoxes){
  const scenarioColumns=orderedApplicableScenarios.map((scenarioKey)=>{
    const row=getScenario(scenarioKey);
    return {
      label:scenarioLabels[scenarioKey],
      metrics:{
        marginMoney:scenarioKey==='self_funded'?'—':r(num(row.margin_money_rs)),
        marginMoneySubsidy:scenarioKey==='self_funded'?r(num(row.net_investment_after_subsidy)):r(num(row.initial_investment_after_subsidy_credit_rs||row.net_own_investment_after_subsidy)),
        loanAmount:scenarioKey==='self_funded'?'—':r(num(row.loan_amount_rs)),
        loanSubsidy:scenarioKey==='self_funded'?'—':r(num(row.effective_loan_principal_rs)),
        interestRate:scenarioKey==='self_funded'?'—':`${num(row.interest_pct).toFixed(showDecimals?2:1)}%`,
        tenure:scenarioKey==='self_funded'?'—':`${num(row.tenure_years).toFixed(1)} years`,
        emi:scenarioKey==='self_funded'?'—':r(num(row.emi_rs)),
        monthlyOutflow:r(num(row.monthly_outflow_rs)),
        payback:String(row.payback_display||'—')
      },
      price:num(row.price),
      subsidy:num(row.subsidy),
      residual:num(row.residual_bill_rs)
    };
  });
  const loanUpToScenario=scenarioColumns.find((scenario)=>scenario.label==='Loan up to ₹2 lacs (subsidy to loan)')||scenarioColumns.find((scenario)=>scenario.label.startsWith('Loan up to ₹2 lacs'));
  const loanAboveScenario=scenarioColumns.find((scenario)=>scenario.label.startsWith('Loan above ₹2 lacs'));
  const anyScenario=scenarioColumns[0]||{price:0,subsidy:0,residual:0};
  const showMarginMoneySubsidy=num(anyScenario.subsidy)>0;
  const financeRows=[
    ['Margin Money','marginMoney'],
    ...(showMarginMoneySubsidy?[['Margin Money - Subsidy','marginMoneySubsidy']]:[]),
    ['Loan Amount','loanAmount'],
    ['Loan - Subsidy','loanSubsidy'],
    ['Interest Rate','interestRate'],
    ['Tenure','tenure'],
    ['EMI','emi'],
    ['Monthly Outflow','monthlyOutflow'],
    ['Payback Time','payback']
  ];
  const financeSummaryLines=[
    `<div class="sf-finance-line"><strong>System Price (Self Funded):</strong> ${r(num((scenarioColumns.find((scenario)=>scenario.label==='Self Funded')||anyScenario).price))}</div>`,
    `<div class="sf-finance-line"><strong>System Price (Loan up to ₹2 lacs):</strong> ${r(num((loanUpToScenario||anyScenario).price))}</div>`,
    loanAboveScenario?`<div class="sf-finance-line"><strong>System Price (Loan above ₹2 lacs):</strong> ${r(num(loanAboveScenario.price))}</div>`:'',
    `<div class="sf-finance-line"><strong>Subsidy:</strong> ${r(num(anyScenario.subsidy))}</div>`,
    `<div class="sf-finance-line"><strong>Residual Bill (same across all scenarios):</strong> ${r(num(anyScenario.residual))}/month</div>`,
    `<div class="sf-finance-line"><strong>Unit Rate of Electricity:</strong> ₹${num(finance.unit_rate_rs_per_kwh).toFixed(showDecimals?2:1)}/kWh</div>`,
    `<div class="sf-finance-line"><strong>Generation per kW of Solar:</strong> ${nUnits(num(finance.annual_generation_kwh_per_kw))} kWh/year</div>`
  ].filter(Boolean).join('');
  financeBoxes.innerHTML=`<div class="sf-finance-summary">${financeSummaryLines}</div><div class="sf-finance-table-wrap"><table class="sf-finance-table"><thead><tr><th>Metric</th>${scenarioColumns.map((scenario)=>`<th>${scenario.label}</th>`).join('')}</tr></thead><tbody>${financeRows.map(([rowLabel,rowKey])=>`<tr><th scope="row">${rowLabel}</th>${scenarioColumns.map((scenario)=>`<td><b>${scenario.metrics[rowKey]??'—'}</b></td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
}

let monthlyChart=null,cumulativeChart=null;
const isMobileCharts=window.matchMedia('(max-width: 700px)').matches;
const chartTypography=isMobileCharts
  ? {title:11,tick:10,legend:10,point:2}
  : {title:13,tick:11,legend:12,point:3};
const chartOptionsCommon={
  responsive:true,
  maintainAspectRatio:false,
  layout:{padding:isMobileCharts?{top:2,right:4,bottom:2,left:2}:{top:6,right:10,bottom:6,left:6}},
  plugins:{
    legend:{
      display:true,
      labels:{
        boxWidth:isMobileCharts?10:14,
        boxHeight:isMobileCharts?10:14,
        padding:isMobileCharts?10:14,
        font:{size:chartTypography.legend}
      }
    },
    tooltip:{bodyFont:{size:isMobileCharts?11:12},titleFont:{size:isMobileCharts?11:12}}
  },
  scales:{
    x:{
      ticks:{font:{size:chartTypography.tick},maxRotation:isMobileCharts?0:20,minRotation:0},
      title:{display:true,font:{size:chartTypography.title}}
    },
    y:{
      ticks:{font:{size:chartTypography.tick}},
      title:{display:true,font:{size:chartTypography.title}}
    }
  }
};
const mctx=document.getElementById('monthlyChart');
if(mctx&&window.Chart){monthlyChart=new Chart(mctx,{type:'bar',data:{labels:monthlyLabels,datasets:[{label:'Monthly Outflow (₹)',data:monthlyData,backgroundColor:monthlyColors,barPercentage:isMobileCharts?0.6:0.72,categoryPercentage:isMobileCharts?0.72:0.8,maxBarThickness:isMobileCharts?34:56}]},options:{...chartOptionsCommon,scales:{...chartOptionsCommon.scales,x:{...chartOptionsCommon.scales.x,title:{...chartOptionsCommon.scales.x.title,text:'Scenario'}},y:{...chartOptionsCommon.scales.y,title:{...chartOptionsCommon.scales.y.title,text:'Monthly Outflow (₹)'}}}}});}
const cctx=document.getElementById('cumulativeChart');
if(cctx&&window.Chart){cumulativeChart=new Chart(cctx,{type:'line',data:{labels:years,datasets:cumulativeDatasets.map(ds=>({...ds,tension:0.2,fill:false,borderWidth:isMobileCharts?2:2.5,pointRadius:chartTypography.point,pointHoverRadius:isMobileCharts?3:4}))},options:{...chartOptionsCommon,scales:{...chartOptionsCommon.scales,x:{...chartOptionsCommon.scales.x,title:{...chartOptionsCommon.scales.x.title,text:'Years'}},y:{...chartOptionsCommon.scales.y,title:{...chartOptionsCommon.scales.y.title,text:'Cumulative Expense (₹)'}}}}});}

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
