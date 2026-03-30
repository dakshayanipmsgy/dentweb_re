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
      <thead><tr><th>Scenario</th><th>System cost</th><th>Subsidy</th><th>Margin Money</th><th>Initial Investment After Subsidy Credit</th><th>Remaining Subsidy After Reducing Initial Investment</th><th>Loan Amount</th><th>Effective Loan Principal After Subsidy Adjustment</th><th>EMI</th><th>Residual bill</th><th>Monthly outflow</th><th>Payback</th></tr></thead>
      <tbody>
        <tr><td>Self funded</td><td><?= $fmtCurrency($finance['self_funded']['system_cost'] ?? 0) ?></td><td><?= $fmtCurrency($finance['self_funded']['subsidy'] ?? 0) ?></td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td><td><?= $fmtCurrency($finance['self_funded']['residual_bill'] ?? 0) ?></td><td><?= $fmtCurrency($finance['self_funded']['residual_bill'] ?? 0) ?></td><td><?= htmlspecialchars((string) ($payback['self_funded'] ?? '—'), ENT_QUOTES) ?></td></tr>
        <tr><td>Loan up to 2 lacs (subsidy to loan)</td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_to_loan']['system_cost'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_to_loan']['subsidy'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_to_loan']['margin_money'] ?? 0) ?></td><td>—</td><td>—</td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_to_loan']['loan_amount'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_to_loan']['effective_loan'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_to_loan']['emi'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_to_loan']['residual_bill'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_to_loan']['total_monthly_outflow'] ?? 0) ?></td><td><?= htmlspecialchars((string) ($payback['loan_upto_2_lacs_subsidy_to_loan'] ?? '—'), ENT_QUOTES) ?></td></tr>
        <tr><td>Loan up to 2 lacs (subsidy not to loan)</td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_not_to_loan']['system_cost'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_not_to_loan']['subsidy'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_not_to_loan']['margin_money'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_not_to_loan']['initial_investment_after_subsidy_credit'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_not_to_loan']['remaining_subsidy_after_reducing_initial_investment'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_not_to_loan']['loan_amount'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_not_to_loan']['effective_loan'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_not_to_loan']['emi'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_not_to_loan']['residual_bill'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_not_to_loan']['total_monthly_outflow'] ?? 0) ?></td><td><?= htmlspecialchars((string) ($payback['loan_upto_2_lacs_subsidy_not_to_loan'] ?? '—'), ENT_QUOTES) ?></td></tr>
        <?php if (is_array($finance['loan_above_2_lacs_subsidy_to_loan'] ?? null)): ?>
        <tr><td>Loan above 2 lacs (subsidy to loan)</td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_to_loan']['system_cost'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_to_loan']['subsidy'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_to_loan']['margin_money'] ?? 0) ?></td><td>—</td><td>—</td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_to_loan']['loan_amount'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_to_loan']['effective_loan'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_to_loan']['emi'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_to_loan']['residual_bill'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_to_loan']['total_monthly_outflow'] ?? 0) ?></td><td><?= htmlspecialchars((string) ($payback['loan_above_2_lacs_subsidy_to_loan'] ?? '—'), ENT_QUOTES) ?></td></tr>
        <tr><td>Loan above 2 lacs (subsidy not to loan)</td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_not_to_loan']['system_cost'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_not_to_loan']['subsidy'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_not_to_loan']['margin_money'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_not_to_loan']['initial_investment_after_subsidy_credit'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_not_to_loan']['remaining_subsidy_after_reducing_initial_investment'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_not_to_loan']['loan_amount'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_not_to_loan']['effective_loan'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_not_to_loan']['emi'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_not_to_loan']['residual_bill'] ?? 0) ?></td><td><?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_not_to_loan']['total_monthly_outflow'] ?? 0) ?></td><td><?= htmlspecialchars((string) ($payback['loan_above_2_lacs_subsidy_not_to_loan'] ?? '—'), ENT_QUOTES) ?></td></tr>
        <?php endif; ?>
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
      <div class="metric"><strong>No Solar (25 years)</strong><div><?= $fmtCurrency($finance['no_solar']['expense_25_year'] ?? 0) ?></div></div>
      <div class="metric"><strong>Loan up to 2 lacs (subsidy to loan)</strong><div>Monthly outflow: <?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_to_loan']['total_monthly_outflow'] ?? 0) ?></div></div>
      <div class="metric"><strong>Loan up to 2 lacs (subsidy not to loan)</strong><div>Monthly outflow: <?= $fmtCurrency($finance['loan_upto_2_lacs_subsidy_not_to_loan']['total_monthly_outflow'] ?? 0) ?></div></div>
      <?php if (is_array($finance['loan_above_2_lacs_subsidy_to_loan'] ?? null)): ?><div class="metric"><strong>Loan above 2 lacs (subsidy to loan)</strong><div>Monthly outflow: <?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_to_loan']['total_monthly_outflow'] ?? 0) ?></div></div><?php endif; ?>
      <?php if (is_array($finance['loan_above_2_lacs_subsidy_not_to_loan'] ?? null)): ?><div class="metric"><strong>Loan above 2 lacs (subsidy not to loan)</strong><div>Monthly outflow: <?= $fmtCurrency($finance['loan_above_2_lacs_subsidy_not_to_loan']['total_monthly_outflow'] ?? 0) ?></div></div><?php endif; ?>
      <div class="metric"><strong>Self funded</strong><div>Annual saving: <?= $fmtCurrency($finance['self_funded']['annual_saving'] ?? 0) ?></div></div>
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
    <h3>Estimate Disclaimer</h3>
    <p>All values shown are indicative estimates for planning and understanding purposes only. Actual generation, savings, EMI, bill reduction, monthly outflow, and payback may vary depending on site conditions, shadow-free roof area, rooftop orientation, weather and seasonal changes, solar panel cleanliness and condition, inverter and battery performance, grid availability, electricity tariff and billing structure, subsidy approval and release, financing terms, installation quality, maintenance, and actual electricity usage pattern.</p>
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
