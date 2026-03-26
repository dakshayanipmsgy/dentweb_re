<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/solar_finance_reports.php';

$token = trim((string) ($_GET['token'] ?? $_GET['report_id'] ?? ''));
$report = $token !== '' ? solar_finance_find_report($token) : null;

if (!is_array($report)) {
    http_response_code(404);
    echo '<h1>Report not found</h1>';
    exit;
}

$site = website_settings();
$customer = is_array($report['customer'] ?? null) ? $report['customer'] : [];
$inputs = is_array($report['inputs'] ?? null) ? $report['inputs'] : [];
$results = is_array($report['results'] ?? null) ? $report['results'] : [];
$charts = is_array($report['charts'] ?? null) ? $report['charts'] : [];
$createdAt = (string) ($report['created_at'] ?? '');

$fmtCurrency = static fn ($val): string => '₹' . number_format((float) $val, 0, '.', ',');
$fmtNum = static fn ($val, int $dec = 0): string => number_format((float) $val, $dec, '.', ',');
$companyName = (string) ($site['hero']['title'] ?? 'Dakshayani Enterprises');
$tagline = (string) ($site['global']['site_tagline'] ?? 'Smart Solar for Every Home');
$contact = (string) ($site['sections']['cta_strip_cta_link'] ?? 'connect@dakshayani.co.in');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Solar and Finance Report</title>
  <style>
    body{font-family:Arial,sans-serif;background:#f4f7fb;margin:0;color:#10223b} .wrap{max-width:1100px;margin:0 auto;padding:20px}
    .card{background:#fff;border:1px solid #d7e1ef;border-radius:14px;padding:16px;margin-bottom:14px} h1,h2,h3{margin:0 0 10px}
    .grid{display:grid;gap:10px}.g3{grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}.g2{grid-template-columns:repeat(auto-fit,minmax(300px,1fr))}
    .k{font-size:12px;color:#54657d}.v{font-size:16px;font-weight:700} .note{font-size:13px;color:#4c6079}
    .cta{display:inline-block;background:#0f766e;color:#fff;padding:10px 14px;border-radius:8px;text-decoration:none;font-weight:700}
    img{max-width:100%;border:1px solid #dbe4f0;border-radius:8px;background:#fff}
    @media print{body{background:#fff}.wrap{max-width:none;padding:0}.card{break-inside:avoid;box-shadow:none}}
  </style>
</head>
<body>
<div class="wrap">
  <section class="card">
    <h1>Solar and Finance Report</h1>
    <div><?= htmlspecialchars($companyName) ?></div>
    <div class="note"><?= htmlspecialchars($tagline) ?></div>
    <div class="note">Contact: <?= htmlspecialchars($contact) ?></div>
  </section>

  <section class="card">
    <h2>Customer Summary</h2>
    <div class="grid g3">
      <div><div class="k">Customer Name</div><div class="v"><?= htmlspecialchars((string) ($customer['name'] ?? '')) ?></div></div>
      <div><div class="k">Location</div><div class="v"><?= htmlspecialchars((string) ($customer['location'] ?? '')) ?></div></div>
      <div><div class="k">Mobile Number</div><div class="v"><?= htmlspecialchars((string) ($customer['mobile'] ?? '')) ?></div></div>
      <div><div class="k">Generated On</div><div class="v"><?= htmlspecialchars($createdAt) ?></div></div>
    </div>
  </section>

  <section class="card">
    <h2>Quick Summary / At a Glance</h2>
    <div class="grid g3">
      <div><div class="k">System Type</div><div class="v"><?= htmlspecialchars((string) ($inputs['systemType'] ?? '')) ?></div></div>
      <div><div class="k">Solar Size</div><div class="v"><?= htmlspecialchars((string) ($inputs['solarSize'] ?? '')) ?> kW</div></div>
      <div><div class="k">Monthly Bill</div><div class="v"><?= $fmtCurrency($inputs['monthlyBill'] ?? 0) ?></div></div>
      <div><div class="k">Monthly Units</div><div class="v"><?= $fmtNum($inputs['monthlyUnits'] ?? 0, 2) ?></div></div>
      <div><div class="k">Unit Rate</div><div class="v"><?= $fmtCurrency($inputs['unitRate'] ?? 0) ?></div></div>
      <div><div class="k">Daily Generation Assumption</div><div class="v"><?= htmlspecialchars((string) ($inputs['dailyGeneration'] ?? '')) ?></div></div>
      <div><div class="k">Estimated Monthly Generation</div><div class="v"><?= $fmtNum($results['solarUnits'] ?? 0) ?> units</div></div>
      <div><div class="k">Estimated Annual Generation</div><div class="v"><?= $fmtNum($results['solarUnitsAnnual'] ?? 0) ?> units</div></div>
      <div><div class="k">Bill Offset</div><div class="v"><?= $fmtNum($results['billOffsetPercent'] ?? 0, 1) ?>%</div></div>
      <div><div class="k">Roof Area Needed</div><div class="v"><?= $fmtNum($results['roofAreaSqFt'] ?? 0) ?> sq.ft</div></div>
    </div>
  </section>

  <section class="card">
    <h2>Financial Summary</h2>
    <div class="grid g2">
      <?php foreach (['selfFunded' => 'Self funded', 'loanUpTo2' => 'Loan up to 2 lacs', 'loanAbove2' => 'Loan above 2 lacs'] as $key => $label): ?>
        <?php if (!isset($results[$key]) || !is_array($results[$key]) || (($results['higherLoanApplicable'] ?? false) === false && $key === 'loanAbove2')) { continue; } ?>
        <div class="card" style="margin:0">
          <h3><?= htmlspecialchars($label) ?></h3>
          <div class="note">System cost: <?= $fmtCurrency($results[$key]['systemCost'] ?? 0) ?></div>
          <div class="note">Subsidy: <?= $fmtCurrency($inputs['subsidy'] ?? 0) ?></div>
          <div class="note">Loan amount: <?= $fmtCurrency($results[$key]['loanAmount'] ?? 0) ?></div>
          <div class="note">Margin money: <?= $fmtCurrency($results[$key]['margin'] ?? 0) ?></div>
          <div class="note">EMI: <?= $fmtCurrency($results[$key]['emi'] ?? 0) ?></div>
          <div class="note">Residual bill: <?= $fmtCurrency($results[$key]['residual'] ?? 0) ?></div>
          <div class="note">Total monthly outflow: <?= $fmtCurrency($results[$key]['monthlyOutflow'] ?? 0) ?></div>
          <div class="note">Payback period: <?= htmlspecialchars((string) ($results[$key]['paybackText'] ?? '-')) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card">
    <h2>Charts & Graphics</h2>
    <div class="grid g2">
      <?php if (!empty($charts['monthlyOutflow'])): ?><img src="<?= htmlspecialchars((string) $charts['monthlyOutflow']) ?>" alt="Monthly outflow comparison"><?php endif; ?>
      <?php if (!empty($charts['cumulativeExpense'])): ?><img src="<?= htmlspecialchars((string) $charts['cumulativeExpense']) ?>" alt="Cumulative expense over 25 years"><?php endif; ?>
    </div>
  </section>

  <section class="card">
    <h2>Detailed Financial Clarity</h2>
    <pre style="white-space:pre-wrap"><?= htmlspecialchars(json_encode($results['financialClarity'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
  </section>

  <section class="card">
    <h2>Generation Estimate</h2>
    <div class="note">Monthly generation: <?= $fmtNum($results['solarUnits'] ?? 0) ?> units</div>
    <div class="note">Annual generation: <?= $fmtNum($results['solarUnitsAnnual'] ?? 0) ?> units</div>
    <div class="note">25-year generation: <?= $fmtNum($results['solarUnits25Years'] ?? 0) ?> units</div>
    <div class="note">₹ saved in 25 years: <?= $fmtCurrency($results['savings25Years'] ?? 0) ?></div>
    <div class="note">Roof area estimate: <?= $fmtNum($results['roofAreaSqFt'] ?? 0) ?> sq.ft</div>
    <div class="note">Bill offset: <?= $fmtNum($results['billOffsetPercent'] ?? 0, 1) ?>%</div>
  </section>

  <section class="card">
    <h2>Green Impact</h2>
    <div class="note">Annual CO₂ reduction: <?= $fmtNum($results['annualCo2Kg'] ?? 0) ?> kg</div>
    <div class="note">25-year CO₂ reduction: <?= $fmtNum($results['co2_25_years_kg'] ?? 0) ?> kg</div>
    <div class="note">Tree equivalent: <?= $fmtNum($results['treeEquivalent'] ?? 0) ?> trees</div>
  </section>

  <section class="card">
    <h2>Assumptions / Important Note</h2>
    <p class="note">These are indicative estimates. Actual savings depend on roof, shadow, tariff, DISCOM policies, usage pattern, approvals and execution conditions.</p>
  </section>

  <section class="card">
    <h2>Request a quotation</h2>
    <a class="cta" target="_blank" href="https://wa.me/917070278178">WhatsApp Us</a>
    <div class="note" style="margin-top:8px">For support, contact <?= htmlspecialchars($contact) ?></div>
  </section>
</div>
</body>
</html>
