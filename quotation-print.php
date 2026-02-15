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
$analysisMode = (string) ($financial['analysis_mode'] ?? 'simple_monthly');
if (!in_array($analysisMode, ['simple_monthly', 'advanced_10y_yearly'], true)) {
    $analysisMode = 'simple_monthly';
}
$yearsForCumulative = (int) ($financial['years_for_cumulative_chart'] ?? 25);
$yearsForCumulative = $yearsForCumulative > 0 ? $yearsForCumulative : 25;

$capacity = (float) ($quote['capacity_kwp'] ?? 0);
$sanctionCapacity = (float) ($snapshot['sanction_load_kwp'] ?? ($quote['sanction_load_kwp'] ?? 0));
$subsidyCapacityBasis = $sanctionCapacity > 0 ? $sanctionCapacity : $capacity;
$quoteTotalCost = (float) ($quote['input_total_gst_inclusive'] ?? ($quote['calc']['grand_total'] ?? 0));
$isPmQuote = documents_quote_is_pm_surya_ghar($quote);
$subsidyModeled = $isPmQuote ? documents_calculate_pm_surya_subsidy((float) floor($subsidyCapacityBasis)) : 0.0;
$financialSubsidy = (float) ($financial['subsidy_expected_rs'] ?? 0);
$rootSubsidy = (float) ($quote['subsidy_expected_rs'] ?? ($quote['subsidy_amount_rs'] ?? 0));
$subsidy = $financialSubsidy > 0 ? $financialSubsidy : $rootSubsidy;
if ($subsidy <= 0) {
    $subsidy = $subsidyModeled;
}

$marginMoneyAmount = max(0, $quoteTotalCost * ($marginMoneyPercent / 100));
$initialLoanAmount = max($quoteTotalCost - $marginMoneyAmount, 0);
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

$annualBill = $monthlyBill * 12;
$annualLoanSpend = ($emi + $residualBill) * 12;
$annualAfterLoanSpend = $residualBill * 12;
$annualSavings = max($annualBill - $annualAfterLoanSpend, 0);
$netCostAfterSubsidy = max(0, $quoteTotalCost - $subsidy);
$paybackYears = $annualSavings > 0 ? ($netCostAfterSubsidy / $annualSavings) : null;

$emissionFactor = (float) (($financial['emission_factor_kg_per_kwh'] ?? '') !== '' ? $financial['emission_factor_kg_per_kwh'] : ($defaults['default_emission_factor_kg_per_kwh'] ?? 0.82));
$kgPerTree = (float) (($financial['kg_co2_absorbed_per_tree_per_year'] ?? '') !== '' ? $financial['kg_co2_absorbed_per_tree_per_year'] : ($defaults['kg_co2_absorbed_per_tree_per_year'] ?? 20));
$annualGeneration = $capacity * $annualGenerationPerKw;
$co2YearTons = ($annualGeneration * $emissionFactor) / 1000;
$treesYear = $kgPerTree > 0 ? ($annualGeneration * $emissionFactor / $kgPerTree) : 0;
$co2LifetimeTons = $co2YearTons * 25;
$treesLifetime = $treesYear * 25;

$warrantySnippet = trim(strip_tags((string) ($ann['warranty'] ?? '')));
$warrantyLabel = $warrantySnippet !== '' ? mb_substr($warrantySnippet, 0, 40) . (mb_strlen($warrantySnippet) > 40 ? '…' : '') : 'Included';

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
$fields = [
    'Name' => (string) ($snapshot['name'] ?: $quote['customer_name']),
    'Mobile' => (string) ($snapshot['mobile'] ?: $quote['customer_mobile']),
    'Address' => (string) ($quote['site_address'] ?: $snapshot['address']),
    'City' => (string) ($snapshot['city'] ?: $quote['city']),
    'District' => (string) ($snapshot['district'] ?: $quote['district']),
];

$coverNotesHtml = $sectionHtml($ann, 'cover_notes', $safeHtml);
$systemInclusionsHtml = $sectionHtml($ann, 'system_inclusions', $safeHtml);
$paymentTermsHtml = $sectionHtml($ann, 'payment_terms', $safeHtml);
$warrantyHtml = $sectionHtml($ann, 'warranty', $safeHtml);
$transportationHtml = $sectionHtml($ann, 'transportation', $safeHtml);
$termsHtml = $sectionHtml($ann, 'terms_conditions', $safeHtml);
$systemTypeExplainerHtml = $sectionHtml($ann, 'system_type_explainer', $safeHtml);
$pmSubsidyHtml = $sectionHtml($ann, 'pm_subsidy_info', $safeHtml);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Print <?= htmlspecialchars((string) $quote['quote_no'], ENT_QUOTES) ?></title>
<style>
:root{--p:<?= htmlspecialchars((string)($theme['primary_color'] ?? '#0b5fff'), ENT_QUOTES) ?>;--a:<?= htmlspecialchars((string)($theme['accent_color'] ?? '#00b894'), ENT_QUOTES) ?>;--txt:<?= htmlspecialchars((string)($theme['text_color'] ?? '#111827'), ENT_QUOTES) ?>;--mut:<?= htmlspecialchars((string)($theme['muted_text_color'] ?? '#6b7280'), ENT_QUOTES) ?>;--card:<?= htmlspecialchars((string)($theme['card_bg'] ?? '#f8fafc'), ENT_QUOTES) ?>;--bd:<?= htmlspecialchars((string)($theme['border_color'] ?? '#e5e7eb'), ENT_QUOTES) ?>;}
body{font-family:Arial,sans-serif;color:var(--txt);margin:0;background:#f7fafc;font-size:<?= (int)($type['base_font_size_px'] ?? 14) ?>px;line-height:<?= (float)($type['line_height'] ?? 1.45) ?>}
.wrap{max-width:980px;margin:0 auto;padding:14px}.section-card{background:#fff;border:1px solid var(--bd);border-radius:16px;padding:14px;margin-bottom:12px}.card-title{margin:0 0 8px;font-size:<?= (int)($type['h3_px'] ?? 16) ?>px}.mini-card-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.mini-card{background:var(--card);border:1px solid var(--bd);border-radius:12px;padding:10px}.mini-card .label{font-size:12px;color:var(--mut)}.mini-card .value{font-weight:700;margin-top:4px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #e2e8f0;padding:8px;text-align:left;vertical-align:top}.muted{color:var(--mut);font-size:12px}.pricing-table td:last-child,.pricing-table th:last-child{text-align:right}.pricing-big{font-size:20px;font-weight:700;color:#0f172a}.cols-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.explainer{background:var(--card);border:1px solid var(--bd);border-radius:12px;padding:10px}.highlight{background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:10px}.checklist li{margin:6px 0}.graph-wrap canvas{width:100%;height:290px;border:1px solid var(--bd);border-radius:12px;background:#fff}.footer-card{background:#f8fafc}
@media (max-width: 900px){.mini-card-grid,.cols-3{grid-template-columns:repeat(2,minmax(0,1fr));}}
</style></head>
<body><div class="wrap">
<div class="section-card"><div style="display:flex;justify-content:space-between;align-items:center;gap:12px"><div><h1 style="margin:0;font-size:<?= (int)($type['h1_px'] ?? 26) ?>px"><?= htmlspecialchars($companyName, ENT_QUOTES) ?></h1><div class="muted">Quotation No: <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></div></div><?php if ((string)($company['logo_path'] ?? '') !== ''): ?><img src="<?= htmlspecialchars((string)$company['logo_path'], ENT_QUOTES) ?>" alt="Logo" style="max-height:70px;max-width:180px"><?php endif; ?></div></div>

<div class="section-card"><h2 class="card-title">👤 Customer Snapshot</h2><table><?php foreach ($fields as $label=>$value): if (trim((string)$value)==='') { continue; } ?><tr><th style="width:34%" ><?= htmlspecialchars($label, ENT_QUOTES) ?></th><td><?= nl2br(htmlspecialchars((string)$value, ENT_QUOTES)) ?></td></tr><?php endforeach; ?></table></div>

<?php if ($showSection('cover_notes', $sectionsEnabled, $coverNotesHtml)): ?><div class="section-card"><h2 class="card-title">👋 Cover Notes</h2><div><?= $coverNotesHtml ?></div></div><?php endif; ?>

<?php if (!empty($sectionsEnabled['system_glance'])): ?><div class="section-card"><h2 class="card-title">⚡ Your System at a Glance</h2><div class="mini-card-grid"><div class="mini-card"><div class="label">⚡ System Type</div><div class="value"><?= htmlspecialchars((string)$quote['system_type'], ENT_QUOTES) ?></div></div><div class="mini-card"><div class="label">🔋 Capacity</div><div class="value"><?= number_format($capacity,2) ?> kWp</div></div><div class="mini-card"><div class="label">🛡️ Warranty</div><div class="value"><?= htmlspecialchars($warrantyLabel, ENT_QUOTES) ?></div></div><div class="mini-card"><div class="label">☀️ Expected Generation</div><div class="value"><?= number_format($annualGeneration,0) ?> kWh/year</div></div></div></div>
<div class="section-card"><h2 class="card-title">🌿 Environmental Impact</h2><div class="mini-card-grid"><div class="mini-card"><div class="label">🌿 CO₂/year</div><div class="value"><?= number_format($co2YearTons,2) ?> tons</div></div><div class="mini-card"><div class="label">🌳 Trees/year</div><div class="value"><?= number_format($treesYear,0) ?></div></div><div class="mini-card"><div class="label">🌿 CO₂/25y</div><div class="value"><?= number_format($co2LifetimeTons,2) ?> tons</div></div><div class="mini-card"><div class="label">🌳 Trees/25y</div><div class="value"><?= number_format($treesLifetime,0) ?></div></div></div></div><?php endif; ?>

<?php if (!empty($sectionsEnabled['pricing_summary'])): ?><div class="section-card"><h2 class="card-title">💰 Pricing Summary</h2><table class="pricing-table"><tr><th>Final Price (GST)</th><td class="pricing-big">₹<?= number_format($quoteTotalCost,2) ?></td></tr><tr><th>Subsidy Expected</th><td>₹<?= number_format($subsidy,2) ?></td></tr><tr><th>Net Cost After Subsidy</th><td>₹<?= number_format($netCostAfterSubsidy,2) ?></td></tr><tr><th>Margin Money (<?= number_format($marginMoneyPercent,2) ?>%)</th><td>₹<?= number_format($marginMoneyAmount,2) ?></td></tr><tr><th>Loan Amount (Initial)</th><td>₹<?= number_format($initialLoanAmount,2) ?></td></tr><tr><th>EMI Principal after Subsidy</th><td>₹<?= number_format($effectivePrincipal,2) ?></td></tr><tr><th>EMI / month</th><td>₹<?= number_format($emi,2) ?></td></tr></table></div><?php endif; ?>

<?php if (!empty($sectionsEnabled['savings_emi'])): ?><div class="section-card graph-wrap"><h2 class="card-title">📉 Savings & EMI Comparison</h2><div class="muted">Quotation: ₹<?= number_format($quoteTotalCost,2) ?> | Margin (<?= number_format($marginMoneyPercent,2) ?>%): ₹<?= number_format($marginMoneyAmount,2) ?> | Loan: ₹<?= number_format($initialLoanAmount,2) ?> | Subsidy: ₹<?= number_format($subsidy,2) ?> | EMI Principal after subsidy: ₹<?= number_format($effectivePrincipal,2) ?></div><div class="section-card"><h3 class="card-title">Graph 1 — Monthly Bill vs Solar EMI</h3><canvas id="graph1Monthly"></canvas></div><div class="section-card"><h3 class="card-title">Graph 2 — Cumulative Money Spent Over Time</h3><canvas id="graph2Cumulative"></canvas></div><div class="section-card"><h3 class="card-title">Graph 3 — Payback Snapshot</h3><canvas id="graph3Payback"></canvas></div><?php if (!($monthlyBill > 0 || $monthlyUnitsBefore > 0)): ?><div class="highlight">Please provide previous monthly bill OR monthly units in quotation financial inputs to render graph trends.</div><?php endif; ?></div><?php endif; ?>

<?php if ($isPmQuote && !empty($sectionsEnabled['pm_subsidy_info'])): ?><div class="section-card"><h2 class="card-title">🏛️ PM Subsidy Info</h2><div class="highlight"><strong>Rule:</strong> 1kW: ₹30,000 | 2kW: ₹60,000 | 3kW+: ₹78,000</div><p><strong>Applied for this quote:</strong> Capacity basis <?= number_format(floor($subsidyCapacityBasis),0) ?> kW → <strong>₹<?= number_format($subsidy,2) ?></strong></p><?php if (trim(strip_tags($pmSubsidyHtml)) !== ''): ?><div><?= $pmSubsidyHtml ?></div><?php endif; ?></div><?php endif; ?>

<?php if (!empty($sectionsEnabled['system_type_explainer'])): ?><div class="section-card"><h2 class="card-title">🔎 System Type Explainer</h2><div class="cols-3"><div class="explainer"><h3>Ongrid ⚡</h3><p>Grid-connected, no battery, high ROI.</p></div><div class="explainer"><h3>Hybrid 🔋</h3><p>Grid + battery backup for outages.</p></div><div class="explainer"><h3>Offgrid 🏕️</h3><p>Independent battery-based setup.</p></div></div><p class="muted">PM subsidy supports typically Ongrid/Hybrid and does not support Offgrid.</p><?php if ($showSection('system_type_explainer', $sectionsEnabled, $systemTypeExplainerHtml)): ?><div><?= $systemTypeExplainerHtml ?></div><?php endif; ?></div><?php endif; ?>

<?php if ($showSection('system_inclusions', $sectionsEnabled, $systemInclusionsHtml)): ?><div class="section-card"><h2 class="card-title">✅ System Inclusions</h2><?php if (trim((string)($quote['special_requests_inclusive'] ?? '')) !== ''): ?><div class="highlight"><strong>Special Requests From Customers Inclusive in the rate:</strong> <?= nl2br(htmlspecialchars((string)$quote['special_requests_inclusive'], ENT_QUOTES)) ?><br><span class="muted">This note takes priority in case of any conflict.</span></div><?php endif; ?><div class="checklist"><?= $systemInclusionsHtml ?></div></div><?php endif; ?>

<?php if ($showSection('payment_terms', $sectionsEnabled, $paymentTermsHtml)): ?><div class="section-card"><h2 class="card-title">💳 Payment Terms</h2><div><?= $paymentTermsHtml ?></div></div><?php endif; ?>
<?php if ($showSection('warranty', $sectionsEnabled, $warrantyHtml)): ?><div class="section-card"><h2 class="card-title">🛡️ Warranty</h2><div><?= $warrantyHtml ?></div></div><?php endif; ?>
<?php if ($showSection('transportation', $sectionsEnabled, $transportationHtml)): ?><div class="section-card"><h2 class="card-title">🚚 Transportation</h2><div><?= $transportationHtml ?></div></div><?php endif; ?>
<?php if ($showSection('terms_conditions', $sectionsEnabled, $termsHtml)): ?><div class="section-card"><h2 class="card-title">📜 Terms & Conditions</h2><div><?= $termsHtml ?></div></div><?php endif; ?>

<div class="section-card footer-card"><h2 class="card-title">🏢 Company Details & Branding</h2><table>
<?php if ($companyName !== ''): ?><tr><th>Firm name</th><td><?= htmlspecialchars($companyName, ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php $addr = trim(implode(', ', array_filter([(string)($company['address_line'] ?? ''),(string)($company['city'] ?? ''),(string)($company['district'] ?? ''),(string)($company['state'] ?? ''),(string)($company['pin'] ?? '')]))); if ($addr !== ''): ?><tr><th>Address</th><td><?= htmlspecialchars($addr, ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php $phones = trim(implode(', ', array_filter([(string)($company['phone_primary'] ?? ''),(string)($company['phone_secondary'] ?? '')]))); if ($phones !== ''): ?><tr><th>Phone</th><td><?= htmlspecialchars($phones, ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php $emails = trim(implode(', ', array_filter([(string)($company['email_primary'] ?? ''),(string)($company['email_secondary'] ?? '')]))); if ($emails !== ''): ?><tr><th>Email</th><td><?= htmlspecialchars($emails, ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php if ((string)($company['website'] ?? '') !== ''): ?><tr><th>Website</th><td><?= htmlspecialchars((string)$company['website'], ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php if ((string)($company['gstin'] ?? '') !== ''): ?><tr><th>GSTIN</th><td><?= htmlspecialchars((string)$company['gstin'], ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php if ((string)($company['pan'] ?? '') !== ''): ?><tr><th>PAN</th><td><?= htmlspecialchars((string)$company['pan'], ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php if ((string)($company['udyam'] ?? '') !== ''): ?><tr><th>Udyam</th><td><?= htmlspecialchars((string)$company['udyam'], ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php if (!empty($licenses)): ?><tr><th>Licences</th><td><ul><?php foreach ($licenses as $license): ?><li><?= htmlspecialchars($license, ENT_QUOTES) ?></li><?php endforeach; ?></ul></td></tr><?php endif; ?>
<?php if ((string)($company['bank_account_name'] ?? '') !== '' || (string)($company['bank_account_no'] ?? '') !== ''): ?><tr><th>Bank details</th><td><?= htmlspecialchars((string)($company['bank_account_name'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars((string)($company['bank_name'] ?? ''), ENT_QUOTES) ?>, A/C <?= htmlspecialchars((string)($company['bank_account_no'] ?? ''), ENT_QUOTES) ?>, IFSC <?= htmlspecialchars((string)($company['bank_ifsc'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars((string)($company['bank_branch'] ?? ''), ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php if ((string)($company['upi_id'] ?? '') !== ''): ?><tr><th>UPI ID</th><td><?= htmlspecialchars((string)$company['upi_id'], ENT_QUOTES) ?></td></tr><?php endif; ?>
<?php if ((string)($company['upi_qr_path'] ?? '') !== ''): ?><tr><th>UPI QR</th><td><img src="<?= htmlspecialchars((string)$company['upi_qr_path'], ENT_QUOTES) ?>" alt="UPI QR" style="max-height:110px"></td></tr><?php endif; ?>
</table></div>
</div>
<script>
(function(){
const data={
  graphsEnabled: <?= json_encode($monthlyBill > 0 || $monthlyUnitsBefore > 0) ?>,
  yearsForCumulative: <?= json_encode($yearsForCumulative) ?>,
  tenureYears: <?= json_encode($tenureYears) ?>,
  monthlyBill: <?= json_encode(round($monthlyBill,2)) ?>,
  residualBill: <?= json_encode(round($residualBill,2)) ?>,
  emi: <?= json_encode(round($emi,2)) ?>,
  annualBill: <?= json_encode(round($annualBill,2)) ?>,
  annualLoanSpend: <?= json_encode(round($annualLoanSpend,2)) ?>,
  annualAfterLoanSpend: <?= json_encode(round($annualAfterLoanSpend,2)) ?>,
  marginMoney: <?= json_encode(round($marginMoneyAmount,2)) ?>,
  quoteValue: <?= json_encode(round($quoteTotalCost,2)) ?>,
  initialLoan: <?= json_encode(round($initialLoanAmount,2)) ?>,
  subsidy: <?= json_encode(round($subsidy,2)) ?>,
  effectivePrincipal: <?= json_encode(round($effectivePrincipal,2)) ?>,
  netCostAfterSubsidy: <?= json_encode(round($netCostAfterSubsidy,2)) ?>,
  annualSavings: <?= json_encode(round($annualSavings,2)) ?>,
  paybackYears: <?= json_encode($paybackYears) ?>,
};
const rupees=v=>'₹'+Math.round(v).toLocaleString('en-IN');
function setupCanvas(id,h){const c=document.getElementById(id);if(!c){return null;}const r=window.devicePixelRatio||1;const w=Math.max(640,Math.floor(c.clientWidth||640));c.width=w*r;c.height=h*r;const ctx=c.getContext('2d');ctx.setTransform(r,0,0,r,0,0);return {ctx,w,h};}
function axes(ctx,left,top,right,bottom,xLabel,yLabel){ctx.strokeStyle='#94a3b8';ctx.beginPath();ctx.moveTo(left,top);ctx.lineTo(left,bottom);ctx.lineTo(right,bottom);ctx.stroke();ctx.fillStyle='#334155';ctx.font='12px Arial';ctx.fillText(xLabel,(left+right)/2-20,bottom+24);ctx.save();ctx.translate(left-40,(top+bottom)/2+20);ctx.rotate(-Math.PI/2);ctx.fillText(yLabel,0,0);ctx.restore();}
function drawGraph1(){const p=setupCanvas('graph1Monthly',290);if(!p)return;const {ctx,w,h}=p;ctx.clearRect(0,0,w,h);const left=70,right=w-22,top=30,bottom=h-45;axes(ctx,left,top,right,bottom,'Case','₹ per month');
const bars=[
{name:'Monthly Bill',color:'#dc2626',value:data.monthlyBill},
{name:'EMI + Residual',color:'#f59e0b',value:data.emi+data.residualBill},
{name:'Residual Only',color:'#16a34a',value:data.residualBill},
];
const max=Math.max(1,...bars.map(b=>b.value))*1.25;const bw=50;const gap=(right-left-3*bw)/4;
bars.forEach((b,i)=>{const x=left+gap*(i+1)+bw*i;const bh=(bottom-top)*b.value/max;ctx.fillStyle=b.color;ctx.fillRect(x,bottom-bh,bw,bh);ctx.fillStyle='#111827';ctx.font='11px Arial';ctx.fillText(rupees(b.value),x-6,bottom-bh-6);ctx.fillText(b.name,x-8,bottom+16);});
ctx.fillStyle='#475569';ctx.fillText('Quotation: '+rupees(data.quoteValue)+' | Margin: '+rupees(data.marginMoney)+' | Loan: '+rupees(data.initialLoan),left,16);
ctx.fillText('Subsidy: '+rupees(data.subsidy)+' | EMI principal after subsidy: '+rupees(data.effectivePrincipal),left,28);
}
function drawGraph2(){const p=setupCanvas('graph2Cumulative',290);if(!p)return;const {ctx,w,h}=p;ctx.clearRect(0,0,w,h);const left=70,right=w-20,top=25,bottom=h-45;axes(ctx,left,top,right,bottom,'Years','Total money spent (₹)');
const years=Math.max(1,data.yearsForCumulative);const red=[],yellow=[],green=[];let ycum=data.marginMoney;
for(let y=0;y<=years;y++){red.push(data.annualBill*y);if(y===0){yellow.push(data.marginMoney);green.push(data.netCostAfterSubsidy);}else{ycum+=y<=data.tenureYears?data.annualLoanSpend:data.annualAfterLoanSpend;yellow.push(ycum);green.push(data.netCostAfterSubsidy+data.annualAfterLoanSpend*y);}}
const max=Math.max(1,...red,...yellow,...green);for(let i=0;i<=5;i++){const gy=bottom-((bottom-top)*i/5);ctx.strokeStyle='#e2e8f0';ctx.beginPath();ctx.moveTo(left,gy);ctx.lineTo(right,gy);ctx.stroke();}
function line(arr,color){ctx.strokeStyle=color;ctx.lineWidth=2;ctx.beginPath();arr.forEach((v,i)=>{const x=left+(right-left)*(i/years);const y=bottom-(bottom-top)*(v/max);if(i===0)ctx.moveTo(x,y);else ctx.lineTo(x,y);});ctx.stroke();}
line(red,'#dc2626');line(yellow,'#f59e0b');line(green,'#16a34a');ctx.fillStyle='#dc2626';ctx.fillText('■ Without solar',left,bottom+18);ctx.fillStyle='#f59e0b';ctx.fillText('■ Solar + Loan',left+120,bottom+18);ctx.fillStyle='#16a34a';ctx.fillText('■ Self finance',left+230,bottom+18);
}
function renderPaybackCard(){const p=setupCanvas('graph3Payback',230);if(!p)return;const {ctx,w,h}=p;ctx.clearRect(0,0,w,h);ctx.fillStyle='#111827';ctx.font='13px Arial';ctx.fillText('Annual savings: '+rupees(data.annualSavings),20,30);ctx.fillText('Payback years (self finance): '+(data.paybackYears===null?'N/A':Number(data.paybackYears).toFixed(2)+'y'),20,54);
const left=20,right=w-20,y=140;ctx.strokeStyle='#cbd5e1';ctx.lineWidth=10;ctx.beginPath();ctx.moveTo(left,y);ctx.lineTo(right,y);ctx.stroke();if(data.paybackYears!==null){const pos=Math.min(25,Math.max(0,data.paybackYears));const x=left+(right-left)*(pos/25);ctx.fillStyle='#22c55e';ctx.beginPath();ctx.arc(x,y,8,0,Math.PI*2);ctx.fill();ctx.fillStyle='#334155';ctx.fillText('0y',left,y+20);ctx.fillText('25y',right-20,y+20);} }
function renderAll(){drawGraph1();drawGraph2();renderPaybackCard();}
window.addEventListener('load',function(){renderAll();if(location.search.indexOf('autoprint=1')!==-1){window.print();}});
window.addEventListener('resize',renderAll);
})();
</script>
</body></html>
