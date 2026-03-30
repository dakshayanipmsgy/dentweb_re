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

$scenarioLabelMap = [
    'self_funded' => 'Self Funded',
    'loan_upto_2_lacs_subsidy_to_loan' => 'Loan up to ₹2 lacs (subsidy credited to loan account)',
    'loan_upto_2_lacs_subsidy_not_to_loan' => 'Loan up to ₹2 lacs (subsidy self kept)',
    'loan_above_2_lacs_subsidy_to_loan' => 'Loan above ₹2 lacs (subsidy credited to loan account)',
    'loan_above_2_lacs_subsidy_not_to_loan' => 'Loan above ₹2 lacs (subsidy self kept)',
];
$scenarioKeys = [
    'self_funded',
    'loan_upto_2_lacs_subsidy_to_loan',
    'loan_upto_2_lacs_subsidy_not_to_loan',
    'loan_above_2_lacs_subsidy_to_loan',
    'loan_above_2_lacs_subsidy_not_to_loan',
];
$scenarioList = [];
foreach ($scenarioKeys as $scenarioKey) {
    $scenarioData = is_array($finance[$scenarioKey] ?? null) ? $finance[$scenarioKey] : [];
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
        'payback' => (string) ($payback[$scenarioKey] ?? '—'),
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
body{font-family:Arial,sans-serif;background:#f7fafc;color:#0f172a;margin:0}.wrap{max-width:1100px;margin:0 auto;padding:16px}.card{background:#fff;border:1px solid #dbe4f0;border-radius:12px;padding:16px;margin-bottom:12px;break-inside:avoid}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.metric{border:1px solid #dbe4f0;background:#f8fbff;border-radius:10px;padding:10px}.header{display:flex;justify-content:space-between;gap:12px;align-items:center}.logo{max-height:56px}.title{font-size:1.4rem;font-weight:700}.sub{font-size:.92rem;color:#475569}.table{width:100%;border-collapse:collapse;margin-top:8px}.table th,.table td{border:1px solid #dbe4f0;padding:8px;text-align:left;font-size:.92rem}.cta{background:#ecfeff;border-color:#99f6e4}.print-btn{position:sticky;top:8px;display:inline-block;background:#0f766e;color:#fff;border:0;padding:8px 12px;border-radius:8px;cursor:pointer}.chart{width:100%;border:1px solid #dbe4f0;border-radius:10px;background:#fff}
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
    <h3>Financial Summary</h3>
    <table class="table">
      <thead>
        <tr>
          <th>Scenario</th>
          <th>System Price</th>
          <th>Margin Money</th>
          <th>Loan Amount</th>
          <th>EMI</th>
          <th>Residual Bill</th>
          <th>Monthly Outflow</th>
          <th>Payback</th>
          <th>Main Investment Note</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($scenarioList as $scenario): ?>
        <?php $scenarioData = is_array($scenario['data']) ? $scenario['data'] : []; ?>
        <tr>
          <td><?= htmlspecialchars((string) $scenario['label'], ENT_QUOTES) ?></td>
          <td><?= $fmtCurrency($scenarioData['system_cost'] ?? 0) ?></td>
          <td><?= $scenario['key'] === 'self_funded' ? '—' : $fmtCurrency($scenarioData['margin_money'] ?? 0) ?></td>
          <td><?= $scenario['key'] === 'self_funded' ? '—' : $fmtCurrency($scenarioData['loan_amount'] ?? 0) ?></td>
          <td><?= $scenario['key'] === 'self_funded' ? '—' : $fmtCurrency($scenarioData['emi'] ?? 0) ?></td>
          <td><?= $fmtCurrency($scenarioData['residual_bill'] ?? 0) ?></td>
          <td><?= $fmtCurrency($scenarioData['total_monthly_outflow'] ?? ($scenarioData['residual_bill'] ?? 0)) ?></td>
          <td><?= htmlspecialchars((string) $scenario['payback'], ENT_QUOTES) ?></td>
          <td>
            <?php if ($scenario['key'] === 'self_funded'): ?>
              Subsidy: <?= $fmtCurrency($scenarioData['subsidy'] ?? 0) ?> · Net Investment: <?= $fmtCurrency($scenarioData['net_investment'] ?? 0) ?>
            <?php elseif (str_ends_with((string) $scenario['key'], '_subsidy_not_to_loan')): ?>
              Initial Investment After Subsidy Credit: <?= $fmtCurrency($scenarioData['initial_investment_after_subsidy_credit'] ?? 0) ?>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="card">
    <h3>Charts & Graphics</h3>
    <?php if (($chartsImages['monthly_outflow'] ?? '') !== ''): ?><img class="chart" src="<?= htmlspecialchars((string) $chartsImages['monthly_outflow'], ENT_QUOTES) ?>" alt="Monthly outflow chart"><?php endif; ?>
    <?php if (($chartsImages['cumulative_expense'] ?? '') !== ''): ?><img class="chart" src="<?= htmlspecialchars((string) $chartsImages['cumulative_expense'], ENT_QUOTES) ?>" alt="Cumulative expense chart" style="margin-top:10px;"><?php endif; ?>
  </section>

  <section class="card">
    <h3>Detailed Financial Clarity</h3>
    <div class="grid">
      <?php foreach ($scenarioList as $scenario): ?>
        <?php $scenarioData = is_array($scenario['data']) ? $scenario['data'] : []; ?>
        <div class="metric">
          <strong><?= htmlspecialchars((string) $scenario['label'], ENT_QUOTES) ?></strong>
          <ul style="margin:8px 0 0 16px;padding:0;line-height:1.55;">
            <li>System Price: <b><?= $fmtCurrency($scenarioData['system_cost'] ?? 0) ?></b></li>
            <li>Subsidy: <b><?= $fmtCurrency($scenarioData['subsidy'] ?? 0) ?></b></li>
            <li>Residual Bill: <b><?= $fmtCurrency($scenarioData['residual_bill'] ?? 0) ?></b></li>
            <li>Monthly Outflow: <b><?= $fmtCurrency($scenarioData['total_monthly_outflow'] ?? ($scenarioData['residual_bill'] ?? 0)) ?></b></li>
            <li>Payback: <b><?= htmlspecialchars((string) $scenario['payback'], ENT_QUOTES) ?></b></li>
            <?php if ($scenario['key'] === 'self_funded'): ?>
              <li>Initial Investment / Net Investment After Subsidy: <b><?= $fmtCurrency($scenarioData['net_investment'] ?? 0) ?></b></li>
            <?php else: ?>
              <li>Margin Money: <b><?= $fmtCurrency($scenarioData['margin_money'] ?? 0) ?></b></li>
              <li>Loan Amount: <b><?= $fmtCurrency($scenarioData['loan_amount'] ?? 0) ?></b></li>
              <li>Interest: <b><?= $fmtNum($scenarioData['interest_rate'] ?? 0, 2) ?>%</b></li>
              <li>Tenure: <b><?= $fmtNum($scenarioData['tenure_years'] ?? 0) ?> years</b></li>
              <li>EMI: <b><?= $fmtCurrency($scenarioData['emi'] ?? 0) ?></b></li>
              <?php if (str_ends_with((string) $scenario['key'], '_subsidy_to_loan')): ?>
                <li>Effective Loan After Subsidy Credit: <b><?= $fmtCurrency($scenarioData['effective_loan'] ?? 0) ?></b></li>
              <?php endif; ?>
              <?php if (str_ends_with((string) $scenario['key'], '_subsidy_not_to_loan')): ?>
                <li>Initial Investment After Subsidy Credit: <b><?= $fmtCurrency($scenarioData['initial_investment_after_subsidy_credit'] ?? 0) ?></b></li>
                <li>Remaining Subsidy After Margin Adjustment: <b><?= $fmtCurrency($scenarioData['remaining_subsidy_after_margin_adjustment'] ?? 0) ?></b></li>
                <li>Effective Loan Amount After Remaining Subsidy Adjustment: <b><?= $fmtCurrency($scenarioData['effective_loan_amount_after_remaining_subsidy_adjustment'] ?? ($scenarioData['effective_loan'] ?? 0)) ?></b></li>
              <?php endif; ?>
            <?php endif; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
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
