<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/solar_finance_reports.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

$token = trim((string) ($_GET['token'] ?? ''));
$reportId = trim((string) ($_GET['report_id'] ?? ''));
$report = solar_finance_find_report($token, $reportId);
if (!is_array($report)) {
    http_response_code(404);
    echo 'Report not found.';
    exit;
}

$company = load_company_profile();
$companyName = trim((string) ($company['brand_name'] ?: $company['company_name'] ?? 'Dakshayani Enterprises'));
$logo = trim((string) ($company['logo_path'] ?? ''));
$phone = trim((string) ($company['phone_primary'] ?? ''));
$email = trim((string) ($company['email_primary'] ?? ''));
$website = trim((string) ($company['website'] ?? ''));
$address = trim((string) ($company['address_line'] ?? ''));
$whatsapp = trim((string) ($company['whatsapp'] ?? ''));

$input = is_array($report['input_snapshot'] ?? null) ? $report['input_snapshot'] : [];
$result = is_array($report['result_snapshot'] ?? null) ? $report['result_snapshot'] : [];
$generation = is_array($result['generation'] ?? null) ? $result['generation'] : [];
$finance = is_array($result['finance'] ?? null) ? $result['finance'] : [];
$payback = is_array($result['payback'] ?? null) ? $result['payback'] : [];
$environment = is_array($result['environment'] ?? null) ? $result['environment'] : [];
$chartsImages = is_array($report['charts_images'] ?? null) ? $report['charts_images'] : [];

$fmtCurrency = static fn ($value): string => '₹' . number_format((float) $value, 0, '.', ',');
$fmtNum = static fn ($value, int $decimals = 0): string => number_format((float) $value, $decimals, '.', ',');
$reportDate = (string) ($report['created_at'] ?? date('Y-m-d H:i:s'));

$normalizedScenariosFromReport = is_array($result['normalized_finance_scenarios'] ?? null)
    ? $result['normalized_finance_scenarios']
    : [];
$normalizedFinance = solar_finance_normalize_for_quote_render(
    [
        'primary_finance_scenario' => 'loan_upto_2_lacs_subsidy_to_loan',
        'capacity_kwp' => (float) ($input['solar_size_kw'] ?? 0),
        'scenario_prices' => [
            'self_funded' => ['price' => (float) ($input['system_cost_self'] ?? ($input['system_cost_self_funded'] ?? 0))],
            'loan_upto_2_lacs_subsidy_to_loan' => ['price' => (float) ($input['system_cost_up2'] ?? 0)],
            'loan_upto_2_lacs_subsidy_not_to_loan' => ['price' => (float) ($input['system_cost_up2'] ?? 0)],
            'loan_above_2_lacs_subsidy_to_loan' => ['price' => (float) ($input['system_cost_above2'] ?? 0), 'applicable' => (bool) ($input['higher_loan_applicable'] ?? false)],
            'loan_above_2_lacs_subsidy_not_to_loan' => ['price' => (float) ($input['system_cost_above2'] ?? 0), 'applicable' => (bool) ($input['higher_loan_applicable'] ?? false)],
        ],
        'finance_scenarios' => $normalizedScenariosFromReport !== [] ? $normalizedScenariosFromReport : $finance,
        'finance_inputs' => ['monthly_bill_rs' => (float) ($input['monthly_bill'] ?? 0)],
    ],
    ['gross_payable' => (float) ($input['system_cost_up2'] ?? 0), 'subsidy_expected_rs' => (float) ($input['subsidy'] ?? 0)],
    [
        'monthly_bill_before_rs' => (float) ($input['monthly_bill'] ?? 0),
        'unit_rate_rs_per_kwh' => (float) ($input['unit_rate'] ?? 0),
        'annual_generation_kwh_per_kw' => ((float) ($input['daily_generation_per_kw'] ?? 0)) * 360,
    ]
);
$scenarioLabelMap = solar_finance_supported_scenario_labels();
$scenarioKeys = array_keys($scenarioLabelMap);
$scenarioList = [];
foreach ($scenarioKeys as $scenarioKey) {
    $scenarioData = is_array($normalizedFinance['finance_scenarios'][$scenarioKey] ?? null) ? $normalizedFinance['finance_scenarios'][$scenarioKey] : [];
    if ($scenarioData === []) {
        continue;
    }
    if (array_key_exists('applicable', $scenarioData) && !$scenarioData['applicable']) {
        continue;
    }
    $scenarioList[] = [
        'key' => $scenarioKey,
        'label' => $scenarioLabelMap[$scenarioKey] ?? $scenarioKey,
        'data' => $scenarioData,
        'payback' => (string) ($scenarioData['payback_display'] ?? ($payback[$scenarioKey] ?? '—')),
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Solar and Finance Report</title>
<style>
body{font-family:Arial,sans-serif;background:#f7fafc;color:#0f172a;margin:0}.wrap{max-width:1100px;margin:0 auto;padding:16px}.card{background:#fff;border:1px solid #dbe4f0;border-radius:12px;padding:16px;margin-bottom:12px;break-inside:avoid}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.metric{border:1px solid #dbe4f0;background:#f8fbff;border-radius:10px;padding:10px}.header{display:flex;justify-content:space-between;gap:12px;align-items:center}.logo{max-height:56px}.title{font-size:1.4rem;font-weight:700}.sub{font-size:.92rem;color:#475569}.table-wrap{overflow-x:auto;margin-top:8px}.table{width:100%;min-width:780px;border-collapse:collapse}.table th,.table td{border:1px solid #dbe4f0;padding:8px;text-align:left;font-size:.92rem}.table thead th{background:#f1f6ff}.table tbody th{background:#f8fbff;font-weight:700;white-space:nowrap}.finance-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin:8px 0}.finance-line{border:1px solid #dbe4f0;background:#f8fbff;border-radius:10px;padding:10px;font-size:.92rem;color:#1f2d46}.finance-note{margin-top:10px;padding:10px;border:1px solid #a5f3fc;background:#ecfeff;border-radius:10px;color:#155e75;font-size:.92rem}.cta{background:#ecfeff;border-color:#99f6e4}.print-btn{position:sticky;top:8px;display:inline-block;background:#0f766e;color:#fff;border:0;padding:8px 12px;border-radius:8px;cursor:pointer}.chart{width:100%;border:1px solid #dbe4f0;border-radius:10px;background:#fff}
@media print{body{background:#fff}.print-hide{display:none}.card{box-shadow:none}}
</style>
</head>
<body>
<div class="wrap">
  <div class="print-hide" style="margin-bottom:10px;"><button class="print-btn" onclick="window.print()">Print (Ctrl + P)</button></div>

  <section class="card header">
    <div>
      <div class="title"><?= htmlspecialchars($companyName, ENT_QUOTES) ?></div>
      <div class="sub"><?= htmlspecialchars($address, ENT_QUOTES) ?></div>
      <div class="sub">📞 <?= htmlspecialchars($phone, ENT_QUOTES) ?> · ✉️ <?= htmlspecialchars($email, ENT_QUOTES) ?> · 🌐 <?= htmlspecialchars($website, ENT_QUOTES) ?></div>
    </div>
    <div>
      <?php if ($logo !== ''): ?><img class="logo" src="<?= htmlspecialchars($logo, ENT_QUOTES) ?>" alt="logo"><?php endif; ?>
      <div class="title">Solar and Finance Report</div>
    </div>
  </section>

  <section class="card">
    <h3>Customer Summary</h3>
    <div class="grid">
      <div class="metric"><strong>Customer Name</strong><div><?= htmlspecialchars((string) ($report['customer_name'] ?? ''), ENT_QUOTES) ?></div></div>
      <div class="metric"><strong>Location</strong><div><?= htmlspecialchars((string) ($report['location'] ?? ''), ENT_QUOTES) ?></div></div>
      <div class="metric"><strong>Mobile Number</strong><div><?= htmlspecialchars((string) ($report['mobile'] ?? ''), ENT_QUOTES) ?></div></div>
      <div class="metric"><strong>Date of Report</strong><div><?= htmlspecialchars($reportDate, ENT_QUOTES) ?></div></div>
    </div>
  </section>

  <section class="card">
    <h3>Quick Summary / At a Glance</h3>
    <div class="grid">
      <div class="metric"><strong>System type</strong><div><?= htmlspecialchars((string) ($input['system_type'] ?? ''), ENT_QUOTES) ?></div></div>
      <div class="metric"><strong>Solar size</strong><div><?= $fmtNum($input['solar_size_kw'] ?? 0, 1) ?> kW</div></div>
      <div class="metric"><strong>Monthly bill</strong><div><?= $fmtCurrency($input['monthly_bill'] ?? 0) ?></div></div>
      <div class="metric"><strong>Monthly units</strong><div><?= $fmtNum($input['monthly_units'] ?? 0) ?></div></div>
      <div class="metric"><strong>Unit rate</strong><div><?= $fmtCurrency($input['unit_rate'] ?? 0) ?></div></div>
      <div class="metric"><strong>Daily generation assumption</strong><div><?= $fmtNum($generation['daily_generation_assumption'] ?? 0, 2) ?></div></div>
      <div class="metric"><strong>Estimated monthly generation</strong><div><?= $fmtNum($generation['monthly_units'] ?? 0) ?> units</div></div>
      <div class="metric"><strong>Estimated annual generation</strong><div><?= $fmtNum($generation['annual_units'] ?? 0) ?> units</div></div>
      <div class="metric"><strong>Bill offset</strong><div><?= $fmtNum($generation['bill_offset_percent'] ?? 0, 1) ?>%</div></div>
      <div class="metric"><strong>Roof area needed</strong><div><?= $fmtNum($generation['roof_area_sqft'] ?? 0) ?> sq.ft</div></div>
    </div>
  </section>

  <section class="card">
    <h3>Detailed Financial Summary</h3>
    <div class="finance-summary">
      <div class="finance-line"><strong>System Price (Loan up to ₹2 lacs):</strong> <?= $fmtCurrency($input['system_cost_up2'] ?? 0) ?></div>
      <?php if (($input['higher_loan_applicable'] ?? false)): ?>
        <div class="finance-line"><strong>System Price (Loan above ₹2 lacs):</strong> <?= $fmtCurrency($input['system_cost_above2'] ?? 0) ?></div>
      <?php endif; ?>
      <div class="finance-line"><strong>Subsidy:</strong> <?= $fmtCurrency($input['subsidy'] ?? 0) ?></div>
      <div class="finance-line"><strong>Residual Bill (same across all scenarios):</strong> <?= $fmtCurrency($normalizedFinance['residual_bill'] ?? 0) ?>/month</div>
    </div>
    <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Metric</th>
          <?php foreach ($scenarioList as $scenario): ?>
            <th><?= htmlspecialchars((string) $scenario['label'], ENT_QUOTES) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php
      $rows = [
          'Margin Money' => static fn (array $scenario, array $data): string => $scenario['key'] === 'self_funded' ? '—' : $fmtCurrency($data['margin_money_rs'] ?? 0),
          'Margin Money - Subsidy' => static fn (array $scenario, array $data): string => $scenario['key'] === 'self_funded'
              ? $fmtCurrency($data['net_investment_after_subsidy'] ?? 0)
              : (str_ends_with((string) $scenario['key'], '_subsidy_not_to_loan')
                  ? $fmtCurrency($data['initial_investment_after_subsidy_credit_rs'] ?? ($data['net_own_investment_after_subsidy'] ?? 0))
                  : '—'),
          'Loan Amount' => static fn (array $scenario, array $data): string => $scenario['key'] === 'self_funded' ? '—' : $fmtCurrency($data['loan_amount_rs'] ?? 0),
          'Loan - Subsidy' => static fn (array $scenario, array $data): string => $scenario['key'] === 'self_funded' ? '—' : $fmtCurrency($data['effective_loan_principal_rs'] ?? 0),
          'EMI' => static fn (array $scenario, array $data): string => $scenario['key'] === 'self_funded' ? '—' : $fmtCurrency($data['emi_rs'] ?? 0),
          'Monthly Outflow' => static fn (array $scenario, array $data): string => $fmtCurrency($data['monthly_outflow_rs'] ?? ($data['residual_bill_rs'] ?? 0)),
          'Payback Time' => static fn (array $scenario, array $data): string => htmlspecialchars((string) ($scenario['payback'] ?? '—'), ENT_QUOTES),
      ];
      ?>
      <?php foreach ($rows as $rowLabel => $resolver): ?>
        <tr>
          <th scope="row"><?= htmlspecialchars($rowLabel, ENT_QUOTES) ?></th>
          <?php foreach ($scenarioList as $scenario): ?>
            <?php $scenarioData = is_array($scenario['data']) ? $scenario['data'] : []; ?>
            <td><b><?= $resolver($scenario, $scenarioData) ?></b></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>

  <section class="card">
    <h3>Charts & Graphics</h3>
    <?php if (($chartsImages['monthly_outflow'] ?? '') !== ''): ?><img class="chart" src="<?= htmlspecialchars((string) $chartsImages['monthly_outflow'], ENT_QUOTES) ?>" alt="Monthly outflow chart"><?php endif; ?>
    <?php if (($chartsImages['cumulative_expense'] ?? '') !== ''): ?><img class="chart" src="<?= htmlspecialchars((string) $chartsImages['cumulative_expense'], ENT_QUOTES) ?>" alt="Cumulative expense chart" style="margin-top:10px;"><?php endif; ?>
  </section>

  <section class="card">
    <h3>Generation Estimate</h3>
    <div class="grid">
      <div class="metric"><strong>Monthly generation</strong><div><?= $fmtNum($generation['monthly_units'] ?? 0) ?> units</div></div>
      <div class="metric"><strong>Annual generation</strong><div><?= $fmtNum($generation['annual_units'] ?? 0) ?> units</div></div>
      <div class="metric"><strong>25-year generation</strong><div><?= $fmtNum($generation['units_25_year'] ?? 0) ?> units</div></div>
      <div class="metric"><strong>₹ saved in 25 years</strong><div><?= $fmtCurrency(($finance['self_funded']['annual_saving'] ?? 0) * 25) ?></div></div>
      <div class="metric"><strong>Roof area estimate</strong><div><?= $fmtNum($generation['roof_area_sqft'] ?? 0) ?> sq.ft</div></div>
      <div class="metric"><strong>Bill offset %</strong><div><?= $fmtNum($generation['bill_offset_percent'] ?? 0, 1) ?>%</div></div>
    </div>
  </section>

  <section class="card">
    <h3>Green Impact</h3>
    <div class="grid">
      <div class="metric"><strong>Annual CO₂ reduction</strong><div><?= $fmtNum($environment['annual_co2_kg'] ?? 0) ?> kg</div></div>
      <div class="metric"><strong>25-year CO₂ reduction</strong><div><?= $fmtNum($environment['co2_25_year_kg'] ?? 0) ?> kg</div></div>
      <div class="metric"><strong>Tree equivalent</strong><div><?= $fmtNum($environment['tree_equivalent'] ?? 0) ?> trees</div></div>
    </div>
  </section>

  <section class="card">
    <h3>Assumptions / Important Note</h3>
    <p>These are indicative estimates. Actual savings depend on roof condition, shadow analysis, tariff changes, DISCOM policy, usage pattern, approvals, and site conditions.</p>
  </section>

  <section class="card cta">
    <h3>Request a quotation</h3>
    <p>To get an exact project proposal, please contact us with this report.</p>
    <p>📞 <?= htmlspecialchars($phone, ENT_QUOTES) ?> · ✉️ <?= htmlspecialchars($email, ENT_QUOTES) ?></p>
    <?php if ($whatsapp !== ''): ?><p>WhatsApp: <?= htmlspecialchars($whatsapp, ENT_QUOTES) ?></p><?php endif; ?>
  </section>
</div>
</body>
</html>
