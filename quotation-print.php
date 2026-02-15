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
$snapshot = documents_quote_resolve_snapshot($quote);
$visuals = documents_quote_effective_visuals($quote);
$finance = documents_quote_effective_finance_inputs($quote);

$capacity = (float) ($quote['capacity_kwp'] ?? 0);
$generationAnnual = $capacity * max(0.0, (float) $finance['annual_generation_kwh_per_kw']);
$annualSavings = $generationAnnual * max(0.0, (float) $finance['unit_rate_rs_per_kwh']);
$co2Year = $generationAnnual * max(0.0, (float) $visuals['emission_factor_kg_per_kwh']);
$treesYear = $co2Year / max(0.01, (float) $visuals['tree_absorption_kg_per_year']);
$co2_25 = $co2Year * 25;
$trees25 = $treesYear * 25;

$grandTotal = (float) ($quote['calc']['grand_total'] ?? 0);
$subsidy = 0.0;
if ($capacity >= 2 && $capacity < 3) {
    $subsidy = 60000.0;
} elseif ($capacity >= 3) {
    $subsidy = 78000.0;
}
$netCost = max(0.0, $grandTotal - $subsidy);

$monthlyBill = is_numeric($finance['monthly_bill_estimate_rs']) ? (float) $finance['monthly_bill_estimate_rs'] : null;
if ($monthlyBill === null && is_numeric($finance['monthly_units_estimate_kwh'])) {
    $monthlyBill = (float) $finance['monthly_units_estimate_kwh'] * (float) $finance['unit_rate_rs_per_kwh'];
}
$analysisYears = max(1, (int) ($finance['analysis_years'] ?? 10));
$months = $analysisYears * 12;

$loanPrincipal = $grandTotal * (1 - ((float) $finance['down_payment_percent'] / 100));
$rateM = ((float) $finance['loan_interest_rate_percent']) / 1200;
$nMonths = max(1, ((int) $finance['loan_tenure_years']) * 12);
$emi = $rateM > 0 ? ($loanPrincipal * $rateM * pow(1 + $rateM, $nMonths)) / (pow(1 + $rateM, $nMonths) - 1) : ($loanPrincipal / $nMonths);
$solarLoanSpend = $emi * min($months, $nMonths);
$gridSpend = ($monthlyBill ?? 0) * $months;

$annualBase = $monthlyBill !== null ? min($annualSavings, $monthlyBill * 12) : $annualSavings;
$paybackYears = $annualBase > 0 ? $netCost / $annualBase : null;

$watermark = is_array($visuals['watermark'] ?? null) ? $visuals['watermark'] : [];
$watermarkEnabled = !empty($watermark['enabled']) && safe_text((string) ($watermark['image_path'] ?? '')) !== '';

$barsMax = max(1.0, $solarLoanSpend, $gridSpend);
$barSolarW = (int) round(($solarLoanSpend / $barsMax) * 220);
$barGridW = (int) round(($gridSpend / $barsMax) * 220);

$linePointsSolar = [];
$linePointsGrid = [];
for ($y = 1; $y <= $analysisYears; $y++) {
    $sx = 30 + (int) round(($y - 1) * (280 / max(1, $analysisYears - 1)));
    $solarYSpend = $emi * min($y * 12, $nMonths);
    $gridYSpend = ($monthlyBill ?? 0) * ($y * 12);
    $maxYSpend = max(1.0, $solarLoanSpend, $gridSpend);
    $sySolar = 170 - (int) round(($solarYSpend / $maxYSpend) * 130);
    $syGrid = 170 - (int) round(($gridYSpend / $maxYSpend) * 130);
    $linePointsSolar[] = $sx . ',' . $sySolar;
    $linePointsGrid[] = $sx . ',' . $syGrid;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Solar Proposal <?= htmlspecialchars((string) $quote['quote_no'], ENT_QUOTES) ?></title>
  <style>
    :root{--base-font-size:<?= htmlspecialchars((string)$visuals['base_font_size_px'], ENT_QUOTES) ?>px;--heading-scale:<?= htmlspecialchars((string)$visuals['heading_scale'], ENT_QUOTES) ?>;--primary:<?= htmlspecialchars((string)$visuals['colors']['primary'], ENT_QUOTES) ?>;--secondary:<?= htmlspecialchars((string)$visuals['colors']['secondary'], ENT_QUOTES) ?>;--accent:<?= htmlspecialchars((string)$visuals['colors']['accent'], ENT_QUOTES) ?>;--muted:<?= htmlspecialchars((string)$visuals['colors']['muted'], ENT_QUOTES) ?>}
    body{font-family:Arial,sans-serif;font-size:var(--base-font-size);line-height:1.5;background:#f8fafc;color:#0f172a;margin:0}
    .topbar{position:sticky;top:0;background:#ffffffd9;backdrop-filter:blur(8px);padding:8px 18px;border-bottom:1px solid #e2e8f0;z-index:9}
    .wrap{max-width:1050px;margin:0 auto;padding:14px}
    .hero,.card{background:#fff;border:1px solid #dbe1ea;border-radius:14px;padding:14px;margin-bottom:12px}
    h1,h2,h3{margin:0 0 8px 0;line-height:1.2}.hero h1{font-size:calc(1.9rem * var(--heading-scale))}
    .muted{color:var(--muted)} .grid{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
    .pill{display:inline-block;background:#e0f2fe;color:#075985;padding:4px 9px;border-radius:999px;font-size:12px;margin:2px}
    table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe1ea;padding:8px;text-align:left}th{background:#f8fafc}
    .num{font-size:1.4rem;font-weight:700;color:var(--primary)} .badge{background:#f0fdf4;border:1px solid #86efac;color:#166534;padding:5px 9px;border-radius:999px;font-size:12px;display:inline-block}
    .wm{display:none}
    @media print {
      @page{size:A4;margin:12mm}
      .topbar{display:none} body{background:#fff}
      .wm{display:block;position:fixed;inset:0;z-index:-1;pointer-events:none;opacity:<?= htmlspecialchars((string) ($watermark['opacity'] ?? 0.08), ENT_QUOTES) ?>;background-position:center center;background-repeat:no-repeat;background-size:<?= htmlspecialchars((string) ($watermark['scale_percent'] ?? 70), ENT_QUOTES) ?>% auto;background-image:url('<?= htmlspecialchars((string) ($watermark['image_path'] ?? ''), ENT_QUOTES) ?>')}
      .hero,.card{break-inside:avoid-page}
    }
  </style>
</head>
<body>
<?php if ($watermarkEnabled): ?><div class="wm" aria-hidden="true"></div><?php endif; ?>
<div class="topbar">To print or save PDF: <strong>use Ctrl + P</strong></div>
<main class="wrap">
  <section class="hero">
    <h1>☀️ Solar Proposal / Quotation</h1>
    <p><strong><?= htmlspecialchars((string) ($snapshot['name'] ?: $quote['customer_name']), ENT_QUOTES) ?></strong> · <?= htmlspecialchars((string) ($snapshot['mobile'] ?: $quote['customer_mobile']), ENT_QUOTES) ?></p>
    <p class="muted"><?= nl2br(htmlspecialchars((string) ($quote['site_address'] ?: $snapshot['address']), ENT_QUOTES)) ?></p>
    <p>Quote: <strong><?= htmlspecialchars((string) $quote['quote_no'], ENT_QUOTES) ?></strong> · Date: <?= htmlspecialchars(substr((string) $quote['created_at'],0,10), ENT_QUOTES) ?> · Validity: <?= htmlspecialchars((string) $quote['valid_until'], ENT_QUOTES) ?></p>
    <p>System: <strong><?= htmlspecialchars((string) $quote['system_type'], ENT_QUOTES) ?></strong> · Capacity: <strong><?= htmlspecialchars((string) $quote['capacity_kwp'], ENT_QUOTES) ?> kWp</strong></p>
    <span class="pill">MNRE / PM Surya Ghar</span><span class="pill">70/30 GST compliant</span><span class="pill">5-year O&M</span>
  </section>

  <section class="card">
    <h2>📊 Solar Benefit Snapshot</h2>
    <div class="grid">
      <div><div class="muted">Annual Generation</div><div class="num"><?= number_format($generationAnnual, 0) ?> kWh</div></div>
      <div><div class="muted">Estimated Annual Savings</div><div class="num">₹<?= number_format($annualSavings, 0) ?></div></div>
      <div><div class="muted">Expected Subsidy</div><div class="num">₹<?= number_format($subsidy, 0) ?></div></div>
      <div><div class="muted">Net Cost After Subsidy</div><div class="num">₹<?= number_format($netCost, 0) ?></div></div>
      <div><div class="muted">CO₂ Saved / Year</div><div class="num"><?= number_format($co2Year, 0) ?> kg</div></div>
      <div><div class="muted">Trees Equivalent / Year</div><div class="num"><?= number_format($treesYear, 0) ?></div></div>
    </div>
    <p class="muted">25-year impact: <strong><?= number_format($co2_25, 0) ?> kg CO₂</strong> and <strong><?= number_format($trees25, 0) ?> trees equivalent</strong>.</p>
  </section>

  <section class="card"><h2>🔧 Your System at a Glance</h2><div class="grid"><div>🔋 High-efficiency solar modules</div><div>⚡ MNRE compliant inverter</div><div>📶 Net metering support</div><div>🛠️ Warranty & O&M as per annexure</div></div></section>

  <section class="card"><h2>💰 Pricing Summary</h2><table><thead><tr><th>Description</th><th>Basic</th><th>GST</th><th>Total</th></tr></thead><tbody><tr><td>Solar Power Generation System (5%)</td><td><?= number_format((float)$quote['calc']['bucket_5_basic'],2) ?></td><td><?= number_format((float)$quote['calc']['bucket_5_gst'],2) ?></td><td><?= number_format((float)$quote['calc']['bucket_5_basic'] + (float)$quote['calc']['bucket_5_gst'],2) ?></td></tr><tr><td>Solar Power Generation System (18%)</td><td><?= number_format((float)$quote['calc']['bucket_18_basic'],2) ?></td><td><?= number_format((float)$quote['calc']['bucket_18_gst'],2) ?></td><td><?= number_format((float)$quote['calc']['bucket_18_basic'] + (float)$quote['calc']['bucket_18_gst'],2) ?></td></tr><tr><th colspan="3">Grand Total</th><th>₹<?= number_format($grandTotal,2) ?></th></tr><tr><th colspan="3">Subsidy (estimated)</th><th>₹<?= number_format($subsidy,2) ?></th></tr><tr><th colspan="3">Net Outflow</th><th>₹<?= number_format($netCost,2) ?></th></tr></tbody></table></section>

  <section class="card">
    <h2>📉 Loan vs Electricity Bills (<?= (int)$analysisYears ?> years)</h2>
    <?php if ($monthlyBill === null): ?><p class="muted">Add your monthly bill to see savings graph.</p><?php endif; ?>
    <svg width="100%" viewBox="0 0 360 110" role="img" aria-label="Bar chart">
      <rect x="120" y="20" width="<?= $barSolarW ?>" height="24" fill="<?= htmlspecialchars((string)$visuals['colors']['primary'], ENT_QUOTES) ?>" rx="6"></rect>
      <rect x="120" y="60" width="<?= $barGridW ?>" height="24" fill="<?= htmlspecialchars((string)$visuals['colors']['accent'], ENT_QUOTES) ?>" rx="6"></rect>
      <text x="8" y="36" font-size="12">With Solar (EMI)</text><text x="8" y="76" font-size="12">Without Solar (Bills)</text>
      <text x="<?= 126 + $barSolarW ?>" y="36" font-size="12">₹<?= number_format($solarLoanSpend,0) ?></text>
      <text x="<?= 126 + $barGridW ?>" y="76" font-size="12">₹<?= number_format($gridSpend,0) ?></text>
    </svg>
    <svg width="100%" viewBox="0 0 340 200" role="img" aria-label="Line chart">
      <line x1="30" y1="170" x2="320" y2="170" stroke="#94a3b8"/><line x1="30" y1="20" x2="30" y2="170" stroke="#94a3b8"/>
      <polyline points="<?= htmlspecialchars(implode(' ', $linePointsSolar), ENT_QUOTES) ?>" fill="none" stroke="<?= htmlspecialchars((string)$visuals['colors']['primary'], ENT_QUOTES) ?>" stroke-width="3"/>
      <polyline points="<?= htmlspecialchars(implode(' ', $linePointsGrid), ENT_QUOTES) ?>" fill="none" stroke="<?= htmlspecialchars((string)$visuals['colors']['accent'], ENT_QUOTES) ?>" stroke-width="3"/>
    </svg>
  </section>

  <section class="card">
    <h2>⏳ Self-financed Payback</h2>
    <?php $payMeter = $paybackYears !== null ? min(1.0, $paybackYears / 12.0) : 1.0; $angle = 180 * $payMeter; ?>
    <svg width="260" height="150" viewBox="0 0 260 150"><path d="M20 130 A110 110 0 0 1 240 130" stroke="#e2e8f0" stroke-width="18" fill="none"/><path d="M20 130 A110 110 0 0 1 <?= 20 + (220*$payMeter) ?> 130" stroke="<?= htmlspecialchars((string)$visuals['colors']['secondary'], ENT_QUOTES) ?>" stroke-width="18" fill="none"/><text x="130" y="95" text-anchor="middle" font-size="24" fill="#0f172a"><?= $paybackYears === null ? 'N/A' : number_format($paybackYears,1).'y' ?></text></svg>
    <p><strong>Estimated payback:</strong> <?= $paybackYears === null ? 'Need monthly bill/units input' : number_format($paybackYears, 1) . ' years' ?></p>
  </section>

  <section class="card"><h2>✅ Inclusions & Process</h2><p class="muted">System inclusions summary, PM subsidy clarifications, and technical commitments are provided in annexures below.</p><p><span class="badge">Confirm quotation</span> <span class="badge">Survey</span> <span class="badge">Material prep</span> <span class="badge">Installation</span> <span class="badge">Net meter</span> <span class="badge">Commissioning</span> <span class="badge">Subsidy</span></p></section>

  <?php if (trim((string) ($quote['special_requests_inclusive'] ?? '')) !== ''): ?><section class="card" style="border-color:#f59e0b;background:#fffbeb"><h2>📝 Special Requests From Customer (Inclusive in rate)</h2><p><?= nl2br(htmlspecialchars((string) $quote['special_requests_inclusive'], ENT_QUOTES)) ?></p><p class="muted">If any conflict occurs, special requests will take priority.</p></section><?php endif; ?>

  <?php foreach (['cover_notes'=>'Cover Notes','system_inclusions'=>'System Inclusions','payment_terms'=>'Payment Terms','warranty'=>'Warranty','transportation'=>'Transportation','system_type_explainer'=>'System Type Explainer','terms_conditions'=>'Terms & Conditions','pm_subsidy_info'=>'PM Subsidy Info'] as $k=>$heading): ?>
    <?php if (trim((string) ($quote['annexures_overrides'][$k] ?? '')) !== ''): ?><section class="card"><h3><?= htmlspecialchars($heading, ENT_QUOTES) ?></h3><div><?= nl2br(htmlspecialchars((string) ($quote['annexures_overrides'][$k] ?? ''), ENT_QUOTES)) ?></div></section><?php endif; ?>
  <?php endforeach; ?>

  <section class="card"><h3>Contact</h3><p><strong><?= htmlspecialchars((string)($company['brand_name'] ?: $company['company_name']), ENT_QUOTES) ?></strong><br><?= htmlspecialchars((string)$company['address_line'], ENT_QUOTES) ?>, <?= htmlspecialchars((string)$company['city'], ENT_QUOTES) ?><br><?= htmlspecialchars((string)$company['phone'], ENT_QUOTES) ?> · <?= htmlspecialchars((string)$company['email'], ENT_QUOTES) ?></p></section>
</main>
</body>
</html>
