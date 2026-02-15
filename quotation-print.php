<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

documents_ensure_structure();
$employeeStore = new EmployeeFsStore();

$viewerType = '';
$viewerId = '';
$user = current_user();
if (is_array($user) && (($user['role_name'] ?? '') === 'admin')) {
    $viewerType = 'admin';
} else {
    $employee = employee_portal_current_employee($employeeStore);
    if ($employee !== null) {
        $viewerType = 'employee';
        $viewerId = (string) ($employee['id'] ?? '');
    }
}
if ($viewerType === '') {
    header('Location: login.php');
    exit;
}

$id = safe_text($_GET['id'] ?? '');
$quote = documents_get_quote($id);
if ($quote === null) {
    http_response_code(404);
    echo 'Quotation not found.';
    exit;
}
if ($viewerType === 'employee' && ((string) ($quote['created_by_id'] ?? '') !== $viewerId || (string) ($quote['created_by_type'] ?? '') !== 'employee')) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
$globalStyle = documents_get_document_style_settings();
$styleOverride = is_array($quote['style_override'] ?? null) ? $quote['style_override'] : [];
$style = documents_merge_style_with_override($globalStyle, $styleOverride);
$defaults = is_array($style['defaults'] ?? null) ? $style['defaults'] : [];
$theme = is_array($style['theme'] ?? null) ? $style['theme'] : [];
$type = is_array($style['typography'] ?? null) ? $style['typography'] : [];
$snapshot = documents_quote_resolve_snapshot($quote);
$financial = is_array($quote['financial_inputs'] ?? null) ? $quote['financial_inputs'] : [];
$ann = is_array($quote['annexures_overrides'] ?? null) ? $quote['annexures_overrides'] : [];

$sectionToggleDefaults = [
    'cover_notes' => true,
    'system_glance' => true,
    'pricing_summary' => true,
    'savings_emi' => true,
    'pm_subsidy_info' => true,
    'system_type_explainer' => true,
    'system_inclusions' => true,
    'payment_terms' => true,
    'warranty' => true,
    'transportation' => true,
    'terms_conditions' => true,
];
$sectionsEnabled = array_merge($sectionToggleDefaults, is_array($quote['sections_enabled'] ?? null) ? $quote['sections_enabled'] : []);

$segmentRateKey = [
    'RES' => 'residential_unit_rate_rs_per_kwh',
    'COM' => 'commercial_unit_rate_rs_per_kwh',
    'IND' => 'industrial_unit_rate_rs_per_kwh',
    'INST' => 'institutional_unit_rate_rs_per_kwh',
];
$segment = (string) ($quote['segment'] ?? 'RES');
$defaultUnitRate = (float) ($defaults[$segmentRateKey[$segment] ?? 'residential_unit_rate_rs_per_kwh'] ?? ($defaults['unit_rate_rs_per_kwh'] ?? 7));
$unitRate = (float) (($financial['unit_rate_rs_per_kwh'] ?? '') !== '' ? $financial['unit_rate_rs_per_kwh'] : $defaultUnitRate);
$interestRate = (float) (($financial['interest_rate_percent'] ?? '') !== '' ? $financial['interest_rate_percent'] : ($defaults['default_bank_interest_rate_percent'] ?? 10));
$tenureYears = (int) (($financial['loan_tenure_years'] ?? '') !== '' ? $financial['loan_tenure_years'] : ($defaults['default_loan_tenure_years'] ?? 10));
$annualGenerationPerKw = (float) (($financial['annual_generation_per_kw'] ?? '') !== '' ? $financial['annual_generation_per_kw'] : ($defaults['default_annual_generation_per_kw'] ?? 1450));
$marginMoneyPercent = (float) (($financial['margin_money_percent'] ?? '') !== '' ? $financial['margin_money_percent'] : 10);
$marginMoneyPercent = max(0, min($marginMoneyPercent, 100));
$minFixedCharge = (float) ($defaults['minimum_fixed_charges_rs'] ?? 200);
$yearsForCumulative = (int) ($financial['years_for_cumulative_chart'] ?? 25);
$yearsForCumulative = $yearsForCumulative > 0 ? $yearsForCumulative : 25;

$capacity = (float) ($quote['capacity_kwp'] ?? 0);
$quoteTotalCost = (float) ($quote['input_total_gst_inclusive'] ?? ($quote['calc']['grand_total'] ?? 0));
$transportationDefaults = [
    'mode' => 'included',
    'charge_rs' => '',
    'district' => (string) ($snapshot['district'] ?? $quote['district'] ?? ''),
    'notes' => '',
];
$transportation = array_merge($transportationDefaults, is_array($quote['transportation'] ?? null) ? $quote['transportation'] : []);
$transportMode = (string) ($transportation['mode'] ?? 'included');
if (!in_array($transportMode, ['included', 'extra', 'not_applicable'], true)) {
    $transportMode = 'included';
}
$transportExtra = $transportMode === 'extra' ? max(0, (float) ($transportation['charge_rs'] ?? 0)) : 0.0;
$grandTotalWithTransport = $quoteTotalCost + $transportExtra;

$metaDefaults = [
    'quotation_date' => date('Y-m-d', strtotime((string) ($quote['created_at'] ?? 'now'))),
    'valid_until_date' => '',
    'validity_days_default' => 15,
];
$meta = array_merge($metaDefaults, is_array($quote['meta'] ?? null) ? $quote['meta'] : []);
$quotationDateRaw = safe_text((string) ($meta['quotation_date'] ?? ''));
if ($quotationDateRaw === '' || strtotime($quotationDateRaw) === false) {
    $quotationDateRaw = $metaDefaults['quotation_date'];
}
$validityDays = max(1, (int) ($meta['validity_days_default'] ?? 15));
$validUntilRaw = safe_text((string) ($meta['valid_until_date'] ?? ''));
if ($validUntilRaw === '' || strtotime($validUntilRaw) === false) {
    $validUntilRaw = date('Y-m-d', strtotime($quotationDateRaw . ' +' . $validityDays . ' days'));
}
$preparedOnDisplay = date('d-m-Y', strtotime($quotationDateRaw));
$validUntilDisplay = date('d-m-Y', strtotime($validUntilRaw));

$pricingBaseForFinance = (string) ($quote['financial_calc']['pricing_base_for_finance'] ?? 'grand_total');
if (!in_array($pricingBaseForFinance, ['quotation', 'grand_total'], true)) {
    $pricingBaseForFinance = 'grand_total';
}
$financeBaseCost = $pricingBaseForFinance === 'grand_total' ? $grandTotalWithTransport : $quoteTotalCost;
$isPmQuote = documents_quote_is_pm_surya_ghar($quote);
$subsidyModeled = $isPmQuote ? documents_calculate_pm_surya_subsidy($capacity) : 0.0;
$financialSubsidy = (float) ($financial['subsidy_expected_rs'] ?? 0);
$rootSubsidy = (float) ($quote['subsidy_expected_rs'] ?? ($quote['subsidy_amount_rs'] ?? 0));
$subsidy = $financialSubsidy > 0 ? $financialSubsidy : $rootSubsidy;
if ($subsidy <= 0) {
    $subsidy = $subsidyModeled;
}

$marginMoneyAmount = max(0, $financeBaseCost * ($marginMoneyPercent / 100));
$initialLoanAmount = max($financeBaseCost - $marginMoneyAmount, 0);
$effectivePrincipal = max($initialLoanAmount - $subsidy, 0);

$nMonths = max(1, $tenureYears * 12);
$monthlyRate = ($interestRate / 100) / 12;
$emi = $effectivePrincipal > 0
    ? ($monthlyRate > 0
        ? ($effectivePrincipal * $monthlyRate * pow(1 + $monthlyRate, $nMonths)) / (pow(1 + $monthlyRate, $nMonths) - 1)
        : ($effectivePrincipal / $nMonths))
    : 0;

$monthlyBillInput = (float) ($financial['estimated_monthly_bill_rs'] ?? 0);
$monthlyUnitsInput = (float) ($financial['estimated_monthly_units_kwh'] ?? 0);
$monthlyBill = 0.0;
$monthlyUnitsBefore = 0.0;
if ($monthlyBillInput > 0) {
    $monthlyBill = $monthlyBillInput;
    $monthlyUnitsBefore = $unitRate > 0 ? ($monthlyBill / $unitRate) : 0;
} elseif ($monthlyUnitsInput > 0) {
    $monthlyUnitsBefore = $monthlyUnitsInput;
    $monthlyBill = $monthlyUnitsBefore * $unitRate;
}

$monthlySolarUnits = ($capacity * $annualGenerationPerKw) / 12;
$monthlyUnitsAfter = max($monthlyUnitsBefore - $monthlySolarUnits, 0);
$computedMonthlyBillAfter = $monthlyUnitsAfter * $unitRate;
$manualMinOverride = (float) ($financial['min_monthly_bill_rs_override'] ?? 0);
$residualBill = $manualMinOverride > 0 ? $manualMinOverride : max($computedMonthlyBillAfter, $minFixedCharge);

$annualGeneration = $capacity * $annualGenerationPerKw;
$annualBill = $monthlyBill * 12;
$annualLoanSpend = ($emi + $residualBill) * 12;
$annualAfterLoanSpend = $residualBill * 12;
$annualSavings = max($annualBill - $annualAfterLoanSpend, 0);
$netCostAfterSubsidy = max(0, $grandTotalWithTransport - $subsidy);
$paybackYears = $annualSavings > 0 ? ($netCostAfterSubsidy / $annualSavings) : null;
$netMonthlyOutflow = $emi + $residualBill;
$netMonthlyBenefit = $monthlyBill > 0 ? max($monthlyBill - $netMonthlyOutflow, 0) : 0.0;

$emissionFactor = (float) (($financial['emission_factor_kg_per_kwh'] ?? '') !== '' ? $financial['emission_factor_kg_per_kwh'] : ($defaults['default_emission_factor_kg_per_kwh'] ?? 0.82));
$kgPerTree = (float) (($financial['kg_co2_absorbed_per_tree_per_year'] ?? '') !== '' ? $financial['kg_co2_absorbed_per_tree_per_year'] : ($defaults['kg_co2_absorbed_per_tree_per_year'] ?? 20));
$co2YearTons = ($annualGeneration * $emissionFactor) / 1000;
$treesYear = $kgPerTree > 0 ? ($annualGeneration * $emissionFactor / $kgPerTree) : 0;
$co2LifetimeTons = $co2YearTons * 25;
$treesLifetime = $treesYear * 25;

$warrantySnippet = trim(strip_tags((string) ($ann['warranty'] ?? '')));
$warrantyLabel = $warrantySnippet !== '' ? 'Warranty Included ✅ (details inside)' : 'Warranty Included ✅';
$showSavingsGraphs = isset($financial['show_savings_graphs']) ? (bool) $financial['show_savings_graphs'] : true;
$hasSavingsInputs = $monthlyBill > 0 || $monthlyUnitsBefore > 0;

$financialCalc = [
    'quotation_value_q' => round($quoteTotalCost, 2),
    'margin_amount' => round($marginMoneyAmount, 2),
    'initial_loan_amount' => round($initialLoanAmount, 2),
    'effective_loan_principal_for_emi' => round($effectivePrincipal, 2),
    'emi' => round($emi, 2),
    'subsidy_expected_rs' => round($subsidy, 2),
];
$quote['financial_inputs']['margin_money_percent'] = round($marginMoneyPercent, 2);
$quote['financial_inputs']['loan_method'] = 'pm_subsidy_reduces_principal';
$quote['financial_inputs']['show_subsidy_in_emi_logic'] = true;
$quote['financial_calc'] = $financialCalc;
@documents_save_quote($quote);

$safeHtml = static function (string $value): string {
    $clean = strip_tags($value, '<p><br><ul><ol><li><strong><em><b><i><u><table><thead><tbody><tr><td><th>');
    return trim($clean) !== '' ? $clean : '';
};
$sectionHtml = static function (array $annexures, string $key, $safeHtml): string {
    return $safeHtml((string) ($annexures[$key] ?? ''));
};
$showSection = static function (string $key, array $sectionsEnabled, string $html): bool {
    return !empty($sectionsEnabled[$key]) && trim(strip_tags($html)) !== '';
};

$companyName = (string) ($company['company_name'] ?: $company['brand_name']);
$licenses = array_filter([(string) ($company['jreda_license'] ?? ''), (string) ($company['dwsd_license'] ?? '')]);
$headerPhones = array_values(array_filter([(string) ($company['phone_primary'] ?? ''), (string) ($company['phone_secondary'] ?? '')]));
$headerEmails = array_values(array_filter([(string) ($company['email_primary'] ?? ''), (string) ($company['email_secondary'] ?? '')]));
$companyAddress = trim(implode(', ', array_filter([
    (string) ($company['address_line'] ?? ''),
    (string) ($company['city'] ?? ''),
    (string) ($company['district'] ?? ''),
    (string) ($company['state'] ?? ''),
    (string) ($company['pin'] ?? ''),
])));
$identityParts = array_values(array_filter([
    (string) ($company['gstin'] ?? '') !== '' ? ('GSTIN: ' . (string) $company['gstin']) : '',
    (string) ($company['pan'] ?? '') !== '' ? ('PAN: ' . (string) $company['pan']) : '',
    (string) ($company['udyam'] ?? '') !== '' ? ('Udyam: ' . (string) $company['udyam']) : '',
]));
$fields = [
    'Name' => (string) ($snapshot['name'] ?: $quote['customer_name']),
    'Mobile' => (string) ($snapshot['mobile'] ?: $quote['customer_mobile']),
    'Address' => (string) ($quote['site_address'] ?: $snapshot['address']),
    'City' => (string) ($snapshot['city'] ?: $quote['city']),
    'District' => (string) ($snapshot['district'] ?: $quote['district']),
];
$websiteText = (string) ($company['website'] ?? '');
$websiteDisplay = preg_replace('#^https?://#', '', $websiteText ?? '') ?: '';
$whatsAppNumber = '7070278178';
$whatsAppLink = 'https://wa.me/91' . $whatsAppNumber;

$coverNotesHtml = $sectionHtml($ann, 'cover_notes', $safeHtml);
$systemInclusionsHtml = $sectionHtml($ann, 'system_inclusions', $safeHtml);
$paymentTermsHtml = $sectionHtml($ann, 'payment_terms', $safeHtml);
$warrantyHtml = $sectionHtml($ann, 'warranty', $safeHtml);
$transportationHtml = $sectionHtml($ann, 'transportation', $safeHtml);
$termsHtml = $sectionHtml($ann, 'terms_conditions', $safeHtml);
$systemTypeExplainerHtml = $sectionHtml($ann, 'system_type_explainer', $safeHtml);
$pmSubsidyHtml = $sectionHtml($ann, 'pm_subsidy_info', $safeHtml);

$subsidySlabLabel = $capacity < 1 ? 'Below slab threshold' : ($capacity < 2 ? '1kW slab' : ($capacity < 3 ? '2kW slab' : '3kW+ slab'));
$whyIdealBullets = [
    'System size selected to match practical daytime load and future savings.',
    'Optimized for Jharkhand weather profile with reliable annual generation expectations.',
    'Supports long-term bill reduction while retaining subsidy eligibility process support.',
];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Print <?= htmlspecialchars((string) $quote['quote_no'], ENT_QUOTES) ?></title>
<style>
:root{--brandPrimary:<?= htmlspecialchars((string)($theme['primary_color'] ?? '#0b5fff'), ENT_QUOTES) ?>;--brandAccent:<?= htmlspecialchars((string)($theme['accent_color'] ?? '#00b894'), ENT_QUOTES) ?>;--brandGreen:<?= htmlspecialchars((string)($theme['chart_green_color'] ?? '#16a34a'), ENT_QUOTES) ?>;--brandOrange:<?= htmlspecialchars((string)($theme['chart_yellow_color'] ?? '#f59e0b'), ENT_QUOTES) ?>;--brandRed:<?= htmlspecialchars((string)($theme['chart_red_color'] ?? '#dc2626'), ENT_QUOTES) ?>;--text:<?= htmlspecialchars((string)($theme['text_color'] ?? '#111827'), ENT_QUOTES) ?>;--muted:<?= htmlspecialchars((string)($theme['muted_text_color'] ?? '#6b7280'), ENT_QUOTES) ?>;--bg:<?= htmlspecialchars((string)($theme['bg_color'] ?? '#ffffff'), ENT_QUOTES) ?>;--card:<?= htmlspecialchars((string)($theme['card_bg'] ?? '#f8fafc'), ENT_QUOTES) ?>;--border:<?= htmlspecialchars((string)($theme['border_color'] ?? '#e5e7eb'), ENT_QUOTES) ?>;--heading:<?= htmlspecialchars((string)(($theme['heading_color'] ?? $theme['primary_color']) ?? '#0b5fff'), ENT_QUOTES) ?>;}
body{font-family:Arial,sans-serif;color:var(--text);margin:0;background:var(--bg);font-size:<?= (int)($type['base_font_size_px'] ?? 14) ?>px;line-height:<?= (float)($type['line_height'] ?? 1.45) ?>}
.wrap{max-width:1020px;margin:0 auto;padding:14px}
.section-card{background:#fff;border:1px solid var(--border);border-radius:16px;padding:14px;margin-bottom:12px;box-shadow:0 2px 8px rgba(2,6,23,.04)}
.section-title{margin:0 0 10px;color:var(--heading);font-size:<?= (int)($type['h3_px'] ?? 16) ?>px;border-left:4px solid var(--brandAccent);padding-left:10px}
.hero-header{border-top:5px solid var(--brandPrimary)}
.head-grid{display:grid;grid-template-columns:140px 1fr auto;gap:12px;align-items:center}
.contact-right{text-align:right;font-size:12px;color:var(--muted)}
.authority{font-size:<?= (int)($type['h2_px'] ?? 20) ?>px;font-weight:700;color:var(--brandPrimary);margin:10px 0 2px}
.sub-authority{font-size:14px;color:var(--brandAccent);font-weight:700}
.badges{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.badge-pill{border:1px solid color-mix(in srgb, var(--brandPrimary) 30%, white);background:color-mix(in srgb, var(--brandAccent) 8%, white);border-radius:999px;padding:6px 10px;font-size:12px;font-weight:700;color:var(--brandPrimary)}
.hero-grid{display:grid;grid-template-columns:2fr 1fr;gap:10px}
.key-values{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.kv{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:9px}
.kv .k{font-size:12px;color:var(--muted)} .kv .v{font-size:17px;font-weight:700;margin-top:3px}
.orange-strip{background:color-mix(in srgb, var(--brandOrange) 12%, white);border:1px solid color-mix(in srgb, var(--brandOrange) 45%, white);border-radius:12px;padding:10px}
.small-muted{font-size:12px;color:var(--muted)}
.cards-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.icon-card{border:1px solid var(--border);background:var(--card);border-radius:12px;padding:10px}
.icon-card .v{font-size:18px;font-weight:700;margin-top:4px}
.warranty-badge{display:inline-block;margin-top:8px;border-radius:999px;padding:4px 12px;background:#ecfdf5;border:1px solid #86efac;color:#166534;font-size:12px;font-weight:700}
.table{width:100%;border-collapse:collapse}
.table th,.table td{border:1px solid var(--border);padding:8px;text-align:left;vertical-align:top}
.table th{background:var(--card)} .table td:last-child,.table th:last-child{text-align:right}
.big-benefit{font-size:24px;font-weight:800;color:var(--brandGreen)}
.graph-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.graph-card canvas{width:100%;height:300px;border:1px solid var(--border);border-radius:14px;background:white}
.soft-note{background:var(--card);border:1px dashed var(--border);border-radius:12px;padding:10px;color:var(--muted)}
.footer{margin-top:8px;font-size:12px;color:var(--muted);text-align:center;padding:12px;border-top:1px solid var(--border)}
@media (max-width:900px){.head-grid,.hero-grid,.cards-3,.graph-grid,.key-values{grid-template-columns:1fr}.contact-right{text-align:left}}
</style></head>
<body><div class="wrap">
<div class="section-card hero-header">
  <div class="head-grid">
    <div><?php if ((string)($company['logo_path'] ?? '') !== ''): ?><img src="<?= htmlspecialchars((string)$company['logo_path'], ENT_QUOTES) ?>" alt="Logo" style="max-width:130px;max-height:70px"><?php endif; ?></div>
    <div>
      <strong><?= htmlspecialchars($companyName, ENT_QUOTES) ?></strong>
      <div class="small-muted">Quotation No: <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?> | Prepared: <?= htmlspecialchars($preparedOnDisplay, ENT_QUOTES) ?> | Valid till: <?= htmlspecialchars($validUntilDisplay, ENT_QUOTES) ?></div>
      <?php if ($companyAddress !== ''): ?><div class="small-muted"><?= htmlspecialchars($companyAddress, ENT_QUOTES) ?></div><?php endif; ?>
    </div>
    <div class="contact-right">
      <?php if (!empty($headerPhones)): ?><div>📞 <?= htmlspecialchars(implode(', ', $headerPhones), ENT_QUOTES) ?></div><?php endif; ?>
      <div>💬 <a href="<?= htmlspecialchars($whatsAppLink, ENT_QUOTES) ?>" target="_blank" rel="noopener">WhatsApp: <?= htmlspecialchars($whatsAppNumber, ENT_QUOTES) ?></a></div>
      <?php if ($websiteDisplay !== ''): ?><div>🌐 <?= htmlspecialchars($websiteDisplay, ENT_QUOTES) ?></div><?php endif; ?>
    </div>
  </div>
  <div class="authority">Jharkhand’s Trusted Solar EPC Partner under PM Surya Ghar Yojana</div>
  <div class="sub-authority">PM Surya Ghar: Muft Bijli Yojana Specialist</div>
  <div class="badges">
    <?php foreach (['MNRE-Compliant EPC','JBVNL Net Metering Support','End-to-End Subsidy Assistance','Local Service Team (Jharkhand)','Quality Components','After-Sales Support'] as $label): ?>
      <span class="badge-pill"><?= htmlspecialchars($label, ENT_QUOTES) ?></span>
    <?php endforeach; ?>
  </div>
</div>

<div class="section-card">
  <h2 class="section-title">Solar Benefit Snapshot</h2>
  <div class="hero-grid">
    <div class="key-values">
      <?php if ($hasSavingsInputs): ?>
      <div class="kv"><div class="k">Current Monthly Bill</div><div class="v">₹<?= number_format($monthlyBill, 0) ?></div></div>
      <div class="kv"><div class="k">Estimated Bill After Solar</div><div class="v">₹<?= number_format($residualBill, 0) ?></div></div>
      <div class="kv"><div class="k">Estimated Monthly Savings</div><div class="v">₹<?= number_format(max($monthlyBill - $residualBill, 0), 0) ?></div></div>
      <?php else: ?>
      <div class="kv" style="grid-column:1/-1"><div class="k">Savings Snapshot</div><div class="v" style="font-size:15px">To be confirmed after last 3 electricity bills.</div></div>
      <?php endif; ?>
      <div class="kv"><div class="k">System Cost (with GST)</div><div class="v">₹<?= number_format($grandTotalWithTransport, 0) ?></div></div>
      <div class="kv"><div class="k">Govt Subsidy Expected</div><div class="v">₹<?= number_format($subsidy, 0) ?></div></div>
      <div class="kv"><div class="k">Net Cost to Customer</div><div class="v">₹<?= number_format($netCostAfterSubsidy, 0) ?></div></div>
      <div class="kv"><div class="k">Payback</div><div class="v"><?= $paybackYears === null ? 'To be confirmed' : number_format($paybackYears, 1) . ' years' ?></div></div>
    </div>
    <div class="orange-strip">
      <div style="font-size:18px;font-weight:800;color:var(--brandOrange)">Estimated EMI: ₹<?= number_format($emi, 0) ?> / month</div>
      <ul>
        <li>In many cases, EMI is lower than current electricity bill.</li>
        <li>10-year finance options available.</li>
        <li>Subsidy benefit retained by customer (as per scheme rules).</li>
      </ul>
      <div>Quick Query? <a href="<?= htmlspecialchars($whatsAppLink, ENT_QUOTES) ?>" target="_blank" rel="noopener">WhatsApp: <?= htmlspecialchars($whatsAppNumber, ENT_QUOTES) ?></a></div>
    </div>
  </div>
</div>

<?php if (!empty($sectionsEnabled['system_glance'])): ?>
<div class="section-card">
  <h2 class="section-title">Your System at a Glance</h2>
  <div class="cards-3">
    <div class="icon-card"><div>⚡ System Type</div><div class="v"><?= htmlspecialchars((string)$quote['system_type'], ENT_QUOTES) ?></div></div>
    <div class="icon-card"><div>🔋 System Size (Installed PV)</div><div class="v"><?= number_format($capacity,2) ?> kWp</div></div>
    <div class="icon-card"><div>☀️ Expected Generation</div><div class="v"><?= number_format($annualGeneration,0) ?> kWh/year</div></div>
  </div>
  <div class="small-muted" style="margin-top:8px">Estimated Monthly Generation: <?= number_format($monthlySolarUnits,0) ?> kWh/month</div>
  <span class="warranty-badge"><?= htmlspecialchars($warrantyLabel, ENT_QUOTES) ?></span>
  <div class="section-card" style="margin-top:12px">
    <h3 class="section-title" style="margin-bottom:8px">Why this system is ideal for you</h3>
    <ul>
      <?php foreach ($whyIdealBullets as $bullet): ?><li><?= htmlspecialchars($bullet, ENT_QUOTES) ?></li><?php endforeach; ?>
    </ul>
    <?php if (trim(strip_tags($systemTypeExplainerHtml)) !== ''): ?><div><?= $systemTypeExplainerHtml ?></div><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($sectionsEnabled['pricing_summary'])): ?>
<div class="section-card">
  <h2 class="section-title">Pricing Summary + Subsidy + Transportation</h2>
  <table class="table">
    <tr><th>Final Price (GST)</th><td>₹<?= number_format($quoteTotalCost,2) ?></td></tr>
    <tr><th>Transportation</th><td><?php if ($transportMode === 'included'): ?>Included<?php elseif ($transportMode === 'extra'): ?>Extra (₹<?= number_format($transportExtra,2) ?>)<?php else: ?>N/A<?php endif; ?></td></tr>
    <tr><th>Grand Total (GST)</th><td>₹<?= number_format($grandTotalWithTransport,2) ?></td></tr>
    <tr><th>Subsidy Expected</th><td>₹<?= number_format($subsidy,2) ?></td></tr>
    <tr><th>Net Cost After Subsidy</th><td><strong>₹<?= number_format($netCostAfterSubsidy,2) ?></strong></td></tr>
  </table>
</div>
<?php endif; ?>

<?php if (!empty($sectionsEnabled['savings_emi']) && $showSavingsGraphs): ?>
<div class="section-card">
  <h2 class="section-title">EMI vs Bill Psychology</h2>
  <div class="cards-3">
    <div class="icon-card"><div>Your current electricity bill</div><div class="v"><?= $hasSavingsInputs ? '₹' . number_format($monthlyBill,0) : 'To be confirmed' ?></div></div>
    <div class="icon-card"><div>Your solar EMI</div><div class="v">₹<?= number_format($emi,0) ?></div></div>
    <div class="icon-card"><div>Estimated bill after solar</div><div class="v"><?= $hasSavingsInputs ? '₹' . number_format($residualBill,0) : 'To be confirmed' ?></div></div>
  </div>
  <div class="section-card" style="margin-top:10px;background:var(--card)">
    <div>Your net monthly outflow: <strong><?= $hasSavingsInputs ? '₹' . number_format($netMonthlyOutflow,0) : 'To be confirmed' ?></strong></div>
    <div class="big-benefit">Net Monthly Benefit: <?= $hasSavingsInputs ? '₹' . number_format($netMonthlyBenefit,0) : 'To be confirmed after last 3 electricity bills.' ?></div>
  </div>
  <?php if (!$hasSavingsInputs): ?><div class="soft-note">Final savings will be confirmed after last 3 electricity bills.</div><?php endif; ?>
</div>

<div class="section-card">
  <h2 class="section-title">Savings Graphs</h2>
  <?php if ($hasSavingsInputs): ?>
    <div class="graph-grid">
      <div class="graph-card"><h3>Graph 1 — Monthly Spend Comparison</h3><canvas id="graph1Monthly"></canvas><div class="small-muted">Solar starts saving from Year 1 ✅</div></div>
      <div class="graph-card"><h3>Graph 2 — Cumulative Spend Over Years</h3><canvas id="graph2Cumulative"></canvas></div>
      <div class="graph-card" style="grid-column:1/-1"><h3>Graph 3 — Payback Meter</h3><canvas id="graph3Payback"></canvas></div>
    </div>
  <?php else: ?>
    <div class="soft-note">Final savings will be confirmed after last 3 electricity bills.</div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="section-card">
  <h2 class="section-title">Technical Credibility / Inclusions / PM Subsidy Info</h2>
  <div class="cards-3">
    <div class="icon-card"><div>Annual CO₂ reduction</div><div class="v"><?= number_format($co2YearTons,2) ?> tons</div></div>
    <div class="icon-card"><div>Trees equivalent / year</div><div class="v"><?= number_format($treesYear,0) ?></div></div>
    <div class="icon-card"><div>25-year impact</div><div class="v"><?= number_format($co2LifetimeTons,1) ?> tons CO₂</div></div>
  </div>
  <?php if ($showSection('system_inclusions', $sectionsEnabled, $systemInclusionsHtml)): ?><div style="margin-top:10px"><?= $systemInclusionsHtml ?></div><?php endif; ?>
  <?php if ($isPmQuote && !empty($sectionsEnabled['pm_subsidy_info'])): ?>
    <div class="section-card" style="margin-top:10px">
      <div><strong>Rule:</strong> 1kW → ₹30,000 | 2kW → ₹60,000 | 3kW and above → ₹78,000</div>
      <div><strong>Applied for this quote:</strong> ₹<?= number_format($subsidy,2) ?> (<?= htmlspecialchars($subsidySlabLabel, ENT_QUOTES) ?>)</div>
      <div class="small-muted">Subsidy subject to MNRE/DISCOM approval &amp; availability.</div>
      <?php if (trim(strip_tags($pmSubsidyHtml)) !== ''): ?><div><?= $pmSubsidyHtml ?></div><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<div class="section-card">
  <h2 class="section-title">Payment Terms + Timeline + Bank Clarity</h2>
  <?php if ($showSection('payment_terms', $sectionsEnabled, $paymentTermsHtml)): ?><div><?= $paymentTermsHtml ?></div><?php endif; ?>
  <div class="section-card" style="margin-top:10px">
    <h3 class="section-title">Timeline &amp; Process</h3>
    <div>Application → Survey → Material Planning → Installation → Net Metering → Commissioning → Subsidy Processing</div>
  </div>
  <div class="section-card" style="margin-top:10px">
    <h3 class="section-title">Bank Case Clarity</h3>
    <ul>
      <li><strong>Bank financed:</strong> Loan disbursement before dispatch, then installation workflow starts.</li>
      <li><strong>Self-funded:</strong> Payment milestones as per agreed terms, followed by same execution and subsidy process.</li>
    </ul>
  </div>
</div>

<?php if ($showSection('warranty', $sectionsEnabled, $warrantyHtml)): ?><div class="section-card"><h2 class="section-title">Warranty</h2><div><?= $warrantyHtml ?></div></div><?php endif; ?>
<?php if ($showSection('terms_conditions', $sectionsEnabled, $termsHtml)): ?><div class="section-card"><h2 class="section-title">Terms &amp; Conditions</h2><div><?= $termsHtml ?></div></div><?php endif; ?>

<div class="section-card">
  <h2 class="section-title">For Dakshayani Enterprises</h2>
  <div>Authorized Signatory</div>
  <div>Mob: <?= htmlspecialchars($whatsAppNumber, ENT_QUOTES) ?></div>
  <p>Thank you for considering Dakshayani Enterprises. We look forward to powering your home/business.</p>
</div>

<div class="footer">
  <?= htmlspecialchars($companyName, ENT_QUOTES) ?><?php if (!empty($licenses)): ?> | <?= htmlspecialchars(implode(' • ', $licenses), ENT_QUOTES) ?><?php endif; ?><?php if (!empty($identityParts)): ?> | <?= htmlspecialchars(implode(' | ', $identityParts), ENT_QUOTES) ?><?php endif; ?>
  <br>
  <?php if (!empty($headerPhones)): ?>📞 <?= htmlspecialchars(implode(', ', $headerPhones), ENT_QUOTES) ?> | <?php endif; ?>💬 <a href="<?= htmlspecialchars($whatsAppLink, ENT_QUOTES) ?>" target="_blank" rel="noopener">WhatsApp: <?= htmlspecialchars($whatsAppNumber, ENT_QUOTES) ?></a><?php if ($websiteDisplay !== ''): ?> | 🌐 <?= htmlspecialchars($websiteDisplay, ENT_QUOTES) ?><?php endif; ?>
</div>

</div>
<script>
(function(){
const data={
  hasSavingsInputs: <?= json_encode($hasSavingsInputs) ?>,
  yearsForCumulative: <?= json_encode($yearsForCumulative) ?>,
  tenureYears: <?= json_encode($tenureYears) ?>,
  monthlyBill: <?= json_encode(round($monthlyBill,2)) ?>,
  residualBill: <?= json_encode(round($residualBill,2)) ?>,
  emi: <?= json_encode(round($emi,2)) ?>,
  annualBill: <?= json_encode(round($annualBill,2)) ?>,
  annualLoanSpend: <?= json_encode(round($annualLoanSpend,2)) ?>,
  annualAfterLoanSpend: <?= json_encode(round($annualAfterLoanSpend,2)) ?>,
  marginMoney: <?= json_encode(round($marginMoneyAmount,2)) ?>,
  netCostAfterSubsidy: <?= json_encode(round($netCostAfterSubsidy,2)) ?>,
  annualSavings: <?= json_encode(round($annualSavings,2)) ?>,
  paybackYears: <?= json_encode($paybackYears) ?>,
  chartRed: getComputedStyle(document.documentElement).getPropertyValue('--brandRed').trim() || '#dc2626',
  chartOrange: getComputedStyle(document.documentElement).getPropertyValue('--brandOrange').trim() || '#f59e0b',
  chartGreen: getComputedStyle(document.documentElement).getPropertyValue('--brandGreen').trim() || '#16a34a',
};
const rupees=v=>'₹'+Math.round(v).toLocaleString('en-IN');
function setupCanvas(id,h){const c=document.getElementById(id);if(!c){return null;}const dpr=window.devicePixelRatio||1;const w=Math.max(620,Math.floor(c.clientWidth||620));c.width=w*dpr;c.height=h*dpr;const ctx=c.getContext('2d');ctx.setTransform(dpr,0,0,dpr,0,0);ctx.lineJoin='round';ctx.lineCap='round';return {ctx,w,h};}
function axes(ctx,left,top,right,bottom,xLabel,yLabel){ctx.strokeStyle='#94a3b8';ctx.lineWidth=1.4;ctx.beginPath();ctx.moveTo(left,top);ctx.lineTo(left,bottom);ctx.lineTo(right,bottom);ctx.stroke();ctx.fillStyle='#334155';ctx.font='12px Arial';ctx.fillText(xLabel,(left+right)/2-28,bottom+26);ctx.save();ctx.translate(left-44,(top+bottom)/2+20);ctx.rotate(-Math.PI/2);ctx.fillText(yLabel,0,0);ctx.restore();}
function drawGraph1(){const p=setupCanvas('graph1Monthly',300);if(!p)return;const {ctx,w,h}=p;ctx.clearRect(0,0,w,h);const left=78,right=w-24,top=30,bottom=h-52;axes(ctx,left,top,right,bottom,'Comparison Cases','₹ / month');
const bars=[{name:'Without Solar',color:data.chartRed,value:data.monthlyBill},{name:'Solar + EMI',color:data.chartOrange,value:data.emi+data.residualBill},{name:'Solar after Loan',color:data.chartGreen,value:data.residualBill}];
const max=Math.max(1,...bars.map(b=>b.value))*1.25;const bw=Math.min(72,((right-left)/bars.length)-34);const gap=((right-left)-(bars.length*bw))/(bars.length+1);
for(let i=0;i<bars.length;i++){const b=bars[i];const x=left+gap*(i+1)+bw*i;const bh=((bottom-top)*b.value/max);ctx.fillStyle=b.color;ctx.beginPath();ctx.roundRect(x,bottom-bh,bw,bh,8);ctx.fill();ctx.fillStyle='#0f172a';ctx.font='bold 11px Arial';ctx.fillText(rupees(b.value),x-2,bottom-bh-8);ctx.font='11px Arial';ctx.fillText(b.name,x-4,bottom+18);} }
function drawLine(ctx,points,color,dashed){ctx.strokeStyle=color;ctx.lineWidth=3;if(dashed){ctx.setLineDash([6,5]);}else{ctx.setLineDash([]);}ctx.beginPath();points.forEach((p,i)=>{if(i===0){ctx.moveTo(p.x,p.y);}else{ctx.lineTo(p.x,p.y);}});ctx.stroke();ctx.setLineDash([]);}
function drawGraph2(){const p=setupCanvas('graph2Cumulative',300);if(!p)return;const {ctx,w,h}=p;ctx.clearRect(0,0,w,h);const left=78,right=w-22,top=30,bottom=h-52;axes(ctx,left,top,right,bottom,'Years','Total money spent (₹)');
const years=Math.max(1,data.yearsForCumulative);const red=[],orange=[],green=[];let loanCum=data.marginMoney;
for(let y=0;y<=years;y++){red.push(data.annualBill*y);if(y===0){orange.push(data.marginMoney);green.push(data.netCostAfterSubsidy);}else{loanCum+=y<=data.tenureYears?data.annualLoanSpend:data.annualAfterLoanSpend;orange.push(loanCum);green.push(data.netCostAfterSubsidy+data.annualAfterLoanSpend*y);}}
const max=Math.max(1,...red,...orange,...green);const toPoints=(arr)=>arr.map((v,i)=>({x:left+(right-left)*(i/years),y:bottom-(bottom-top)*(v/max)}));
for(let i=0;i<=5;i++){const gy=bottom-((bottom-top)*i/5);ctx.strokeStyle='#e2e8f0';ctx.beginPath();ctx.moveTo(left,gy);ctx.lineTo(right,gy);ctx.stroke();}
const redPts=toPoints(red), orangePts=toPoints(orange), greenPts=toPoints(green);drawLine(ctx,redPts,data.chartRed,true);drawLine(ctx,orangePts,data.chartOrange,false);drawLine(ctx,greenPts,data.chartGreen,false);
let breakEvenYear=null;for(let y=0;y<green.length;y++){if(green[y]<=red[y]){breakEvenYear=y;break;}}
if(breakEvenYear!==null){const x=left+(right-left)*(breakEvenYear/years);ctx.strokeStyle='#334155';ctx.setLineDash([4,4]);ctx.beginPath();ctx.moveTo(x,top);ctx.lineTo(x,bottom);ctx.stroke();ctx.setLineDash([]);ctx.fillStyle='#334155';ctx.fillText('Break-Even',x+6,top+14);ctx.fillStyle='rgba(22,163,74,0.12)';ctx.fillRect(x,top,right-x,bottom-top);ctx.fillStyle='#166534';ctx.fillText('Your Profit Zone',x+8,top+30);}
ctx.fillStyle=data.chartRed;ctx.fillText('■ Without Solar',left,bottom+18);ctx.fillStyle=data.chartOrange;ctx.fillText('■ Solar + Loan',left+120,bottom+18);ctx.fillStyle=data.chartGreen;ctx.fillText('■ Self finance',left+225,bottom+18);
}
function drawGraph3(){const p=setupCanvas('graph3Payback',260);if(!p)return;const {ctx,w,h}=p;ctx.clearRect(0,0,w,h);const cx=w/2,cy=185,r=120;ctx.lineWidth=16;ctx.strokeStyle='#e2e8f0';ctx.beginPath();ctx.arc(cx,cy,r,Math.PI,0);ctx.stroke();
const years=Math.min(25,Math.max(0,data.paybackYears===null?25:data.paybackYears));const end=Math.PI+(Math.PI*(years/25));ctx.strokeStyle=data.chartGreen;ctx.beginPath();ctx.arc(cx,cy,r,Math.PI,end);ctx.stroke();ctx.fillStyle='#0f172a';ctx.font='bold 22px Arial';ctx.fillText(data.paybackYears===null?'N/A':Number(data.paybackYears).toFixed(1)+' yrs',cx-40,145);ctx.font='13px Arial';ctx.fillText('Estimated Payback',cx-58,166);ctx.fillText('Annual Savings: '+rupees(data.annualSavings),cx-85,212);ctx.fillStyle='#475569';ctx.fillText('0y',cx-r-6,cy+22);ctx.fillText('25y',cx+r-20,cy+22);}
function renderAll(){if(!data.hasSavingsInputs){return;}drawGraph1();drawGraph2();drawGraph3();}
window.addEventListener('load',function(){renderAll();if(location.search.indexOf('autoprint=1')!==-1){window.print();}});
window.addEventListener('resize',renderAll);
})();
</script>
</body></html>
