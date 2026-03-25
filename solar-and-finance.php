<?php
$contentPath = __DIR__ . '/storage/site/solar_finance_content.json';
$defaults = [
    'page_title' => 'Solar & Finance',
    'page_subtitle' => 'Understand your solar need, savings, and financing in a simple way',
    'hero_intro' => 'This guide helps you understand rooftop solar, compare on-grid vs hybrid options, estimate your possible savings, and take the next step confidently.',
    'education_blocks' => [],
    'faq' => [],
    'defaults' => [
        'daily_generation' => 5,
        'unit_rate' => 8,
        'efficiency_factor' => 1,
        'system_size' => 3,
        'system_cost' => 180000,
        'subsidy' => 78000,
        'loan_interest' => 8.5,
        'loan_tenure_years' => 7,
        'tariff_escalation' => 3,
        'solar_degradation' => 0.7,
        'fixed_charge' => 150,
        'yearly_om' => 3000,
        'inverter_reserve' => 35000,
        'inverter_year' => 12,
        'roof_area_per_kw' => 90,
    ],
    'green_factors' => [
        'co2_per_kwh_kg' => 0.82,
        'tree_kg_per_year' => 21,
    ],
    'cta' => [
        'primary' => 'Request a Quotation',
        'secondary' => 'Talk to Dakshayani Enterprises',
        'tertiary' => 'Upload your electricity bill',
    ],
];

$config = $defaults;
if (is_file($contentPath)) {
    $raw = file_get_contents($contentPath);
    $decoded = json_decode((string) $raw, true);
    if (is_array($decoded)) {
        $config = array_replace_recursive($defaults, $decoded);
    }
}

$title = (string) ($config['page_title'] ?? 'Solar & Finance');
$subtitle = (string) ($config['page_subtitle'] ?? 'Understand your solar need, savings, and financing in a simple way');
$heroIntro = (string) ($config['hero_intro'] ?? '');
$educationBlocks = is_array($config['education_blocks'] ?? null) ? $config['education_blocks'] : [];
$faq = is_array($config['faq'] ?? null) ? $config['faq'] : [];
$defaultValues = is_array($config['defaults'] ?? null) ? $config['defaults'] : [];
$greenFactors = is_array($config['green_factors'] ?? null) ? $config['green_factors'] : [];
$cta = is_array($config['cta'] ?? null) ? $config['cta'] : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($title) ?> | Dakshayani Enterprises</title>
  <meta name="description" content="Simple step-by-step solar and finance planner with savings, loan comparison, and quotation support." />
  <link rel="icon" href="images/favicon.ico" />
  <link rel="stylesheet" href="style.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer"/>
  <style>
    .sf-page { background: #f8fbff; }
    .sf-wrap { max-width: 1180px; margin: 0 auto; padding: 2rem 1rem 4rem; }
    .sf-hero { background: #fff; border: 1px solid #dbeafe; border-radius: 1.3rem; padding: 2rem; margin-bottom: 2rem; }
    .sf-hero p { max-width: 760px; color: #475569; }
    .sf-kicker { color: #1d4ed8; text-transform: uppercase; font-weight: 700; letter-spacing: .03em; margin-bottom: .4rem; }
    .sf-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
    .sf-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1rem 1.1rem; }
    .sf-card h3 { margin: 0 0 .45rem; font-size: 1.05rem; }
    .sf-card p { margin: 0; color: #475569; }
    .sf-icon { width: 2.2rem; height: 2.2rem; border-radius: 999px; display: inline-grid; place-items: center; background: #eff6ff; color: #1d4ed8; margin-bottom: .6rem; }
    .sf-section { margin-top: 2.3rem; }
    .sf-form-card { background: #fff; border: 1px solid #dbeafe; border-radius: 1rem; padding: 1.15rem; }
    .sf-form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .9rem; }
    .sf-form-grid .span-2 { grid-column: span 2; }
    .sf-form-grid .span-3 { grid-column: 1 / -1; }
    label { display: block; font-weight: 600; font-size: .9rem; margin-bottom: .25rem; color: #1e293b; }
    .sf-input, .sf-select { width: 100%; border: 1px solid #cbd5e1; border-radius: .7rem; padding: .62rem .65rem; font: inherit; background: #fff; }
    .help { color: #64748b; font-size: .78rem; margin-top: .2rem; }
    .sf-title { margin-bottom: .75rem; font-size: 1.15rem; }
    .sf-btn-main { margin-top: 1.4rem; width: 100%; padding: .95rem 1rem; border-radius: .75rem; border: none; background: #0f172a; color: #fff; font-weight: 700; font-size: 1rem; cursor: pointer; }
    .sf-results { margin-top: 1.5rem; display: grid; gap: 1rem; }
    .sf-summary-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: .75rem; }
    .sf-summary { background: #fff; border-radius: .9rem; border: 1px solid #e2e8f0; padding: .8rem; }
    .sf-summary .k { color: #64748b; font-size: .8rem; }
    .sf-summary .v { font-size: 1.05rem; font-weight: 700; color: #0f172a; margin-top: .25rem; }
    .sf-chart { background: #fff; border: 1px solid #e2e8f0; border-radius: .9rem; padding: .9rem; }
    .sf-chart canvas { width: 100%; height: 250px; }
    .sf-meter-wrap { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
    .sf-meter { background: #fff; border: 1px solid #e2e8f0; border-radius: .9rem; padding: .95rem; }
    .meter-track { width: 100%; height: 11px; border-radius: 999px; background: #e2e8f0; overflow: hidden; margin-top: .6rem; }
    .meter-fill { height: 100%; background: linear-gradient(90deg, #0ea5e9, #16a34a); }
    .sf-two-col { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
    .sf-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .4rem; }
    .sf-list li { display: flex; justify-content: space-between; gap: .75rem; border-bottom: 1px dashed #e2e8f0; padding-bottom: .35rem; }
    .sf-list span:last-child { font-weight: 600; color: #0f172a; text-align: right; }
    .sf-reco { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: .9rem; padding: .95rem; }
    .sf-disclaimer { font-size: .83rem; color: #64748b; background: #fff; border: 1px solid #e2e8f0; border-radius: .8rem; padding: .8rem; }
    .sf-cta-box { background: #0f172a; color: #fff; border-radius: 1rem; padding: 1.2rem; text-align: center; }
    .sf-cta-box a { display: inline-flex; align-items: center; gap: .45rem; margin: .35rem; }
    .sf-hidden { display: none; }
    @media (max-width: 980px) {
      .sf-form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .sf-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .sf-two-col, .sf-meter-wrap, .sf-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
      .sf-wrap { padding: 1.2rem .8rem 4rem; }
      .sf-hero { padding: 1.25rem; }
      .sf-form-grid { grid-template-columns: 1fr; }
      .sf-form-grid .span-2, .sf-form-grid .span-3 { grid-column: auto; }
      .sf-chart canvas { height: 210px; }
    }
  </style>
</head>
<body class="sf-page">
<header class="site-header"></header>
<main class="sf-wrap">
  <section class="sf-hero">
    <p class="sf-kicker">Simple guided journey</p>
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($subtitle) ?></p>
    <p><?= htmlspecialchars($heroIntro) ?></p>
  </section>

  <section class="sf-section">
    <h2>Learn solar in simple language</h2>
    <div class="sf-grid">
      <?php foreach ($educationBlocks as $block): ?>
        <article class="sf-card">
          <span class="sf-icon"><i class="fa-solid <?= htmlspecialchars((string) ($block['icon'] ?? 'fa-circle-info')) ?>"></i></span>
          <h3><?= htmlspecialchars((string) ($block['title'] ?? '')) ?></h3>
          <p><?= htmlspecialchars((string) ($block['text'] ?? '')) ?></p>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="sf-grid sf-section">
      <figure class="sf-card">
        <img src="/images/pmsgy.jpg" alt="On-grid rooftop concept diagram" style="width:100%;border-radius:.8rem;object-fit:cover;max-height:220px;">
        <figcaption style="margin-top:.5rem;color:#475569;">On-grid overview: solar powers your load and can export excess where net metering applies.</figcaption>
      </figure>
      <figure class="sf-card">
        <img src="/images/large solar small.jpg" alt="Hybrid rooftop solar concept with backup" style="width:100%;border-radius:.8rem;object-fit:cover;max-height:220px;">
        <figcaption style="margin-top:.5rem;color:#475569;">Hybrid overview: solar + battery helps when power cuts are frequent.</figcaption>
      </figure>
      <figure class="sf-card">
        <img src="/images/collage.jpg" alt="Solar process flow from survey to commissioning" style="width:100%;border-radius:.8rem;object-fit:cover;max-height:220px;">
        <figcaption style="margin-top:.5rem;color:#475569;">Simple process flow: bill review → survey → design → approvals → installation.</figcaption>
      </figure>
      <figure class="sf-card">
        <img src="/images/dedicatedgrounops.jpg" alt="Benefits of rooftop solar for household and business" style="width:100%;border-radius:.8rem;object-fit:cover;max-height:220px;">
        <figcaption style="margin-top:.5rem;color:#475569;">Benefits: lower bills, tariff protection, and cleaner energy.</figcaption>
      </figure>
    </div>

    <?php if (!empty($faq)): ?>
      <div class="sf-section">
        <h2>FAQ</h2>
        <?php foreach ($faq as $item): ?>
          <details class="sf-card">
            <summary><strong><?= htmlspecialchars((string) ($item['q'] ?? 'Question')) ?></strong></summary>
            <p style="margin-top:.55rem;"><?= htmlspecialchars((string) ($item['a'] ?? '')) ?></p>
          </details>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="sf-section">
    <h2>How solar adoption would look like</h2>
    <form id="sf-form" class="sf-form-card">
      <h3 class="sf-title">Group 1 — Tell us about your situation</h3>
      <div class="sf-form-grid">
        <div><label for="customer_name">Customer name (optional)</label><input id="customer_name" class="sf-input" type="text"></div>
        <div><label for="mobile">Mobile number (optional)</label><input id="mobile" class="sf-input" type="text"></div>
        <div><label for="monthly_bill">Average monthly bill (₹)</label><input id="monthly_bill" class="sf-input" type="number" min="0" step="1"></div>
        <div><label for="monthly_units">Average monthly units (optional)</label><input id="monthly_units" class="sf-input" type="number" min="0" step="0.01"></div>
        <div><label for="property_type">Property type</label><select id="property_type" class="sf-select"><option>Home</option><option>Apartment / Society</option><option>Shop / Office</option><option>School / Hospital / Institution</option><option>Industrial / Commercial</option></select></div>
        <div><label for="roof_type">Roof type</label><select id="roof_type" class="sf-select"><option>RCC</option><option>Tin / Sheet</option><option>Elevated / Other</option></select></div>
        <div><label for="roof_area">Available shadow-free roof area (sq ft)</label><input id="roof_area" class="sf-input" type="number" min="0" step="1"></div>
        <div><label for="location">Location / City</label><input id="location" class="sf-input" type="text"></div>
        <div><label for="phase_type">Single phase / three phase (optional)</label><select id="phase_type" class="sf-select"><option value="">Select</option><option>Single phase</option><option>Three phase</option></select></div>
        <div><label for="daytime_usage">Daytime usage level</label><select id="daytime_usage" class="sf-select"><option>Low</option><option selected>Medium</option><option>High</option></select></div>
        <div><label for="need_backup">Need backup during power cuts?</label><select id="need_backup" class="sf-select"><option>No</option><option>Yes</option></select></div>
      </div>

      <h3 class="sf-title" style="margin-top:1.4rem;">Group 2 — Solar and finance inputs</h3>
      <div class="sf-form-grid">
        <div><label for="solar_size">Solar power size (kW)</label><input id="solar_size" class="sf-input" type="number" min="0" step="0.1" value="<?= htmlspecialchars((string) ($defaultValues['system_size'] ?? 3)) ?>"></div>
        <div><label for="daily_generation">Daily generation per kW (kWh)</label><input id="daily_generation" class="sf-input" type="number" min="0" step="0.1" value="<?= htmlspecialchars((string) ($defaultValues['daily_generation'] ?? 5)) ?>"></div>
        <div><label for="unit_rate">Unit rate (₹ / unit)</label><input id="unit_rate" class="sf-input" type="number" min="0" step="0.01" value="<?= htmlspecialchars((string) ($defaultValues['unit_rate'] ?? 8)) ?>"></div>
        <div><label for="system_cost">Cost of solar system (₹)</label><input id="system_cost" class="sf-input" type="number" min="0" step="1" value="<?= htmlspecialchars((string) ($defaultValues['system_cost'] ?? 180000)) ?>"></div>
        <div><label for="subsidy">Subsidy (₹)</label><input id="subsidy" class="sf-input" type="number" min="0" step="1" value="<?= htmlspecialchars((string) ($defaultValues['subsidy'] ?? 78000)) ?>"></div>
        <div><label for="loan_amount">Loan amount (₹)</label><input id="loan_amount" class="sf-input" type="number" min="0" step="1"></div>
        <div><label for="margin_money">Margin money (₹)</label><input id="margin_money" class="sf-input" type="number" min="0" step="1"></div>
        <div><label for="loan_interest">Loan interest rate (%)</label><input id="loan_interest" class="sf-input" type="number" min="0" step="0.01" value="<?= htmlspecialchars((string) ($defaultValues['loan_interest'] ?? 8.5)) ?>"></div>
        <div><label for="loan_tenure">Loan tenure (years)</label><input id="loan_tenure" class="sf-input" type="number" min="0" step="1" value="<?= htmlspecialchars((string) ($defaultValues['loan_tenure_years'] ?? 7)) ?>"></div>
      </div>

      <details style="margin-top:1.4rem;">
        <summary><strong>Advanced assumptions</strong></summary>
        <div class="sf-form-grid" style="margin-top:.9rem;">
          <div><label for="tariff_escalation">Annual tariff escalation %</label><input id="tariff_escalation" class="sf-input" type="number" min="0" step="0.1" value="<?= htmlspecialchars((string) ($defaultValues['tariff_escalation'] ?? 3)) ?>"></div>
          <div><label for="solar_degradation">Annual solar degradation %</label><input id="solar_degradation" class="sf-input" type="number" min="0" step="0.1" value="<?= htmlspecialchars((string) ($defaultValues['solar_degradation'] ?? 0.7)) ?>"></div>
          <div><label for="fixed_charge">Monthly fixed charge / minimum bill (₹)</label><input id="fixed_charge" class="sf-input" type="number" min="0" step="1" value="<?= htmlspecialchars((string) ($defaultValues['fixed_charge'] ?? 150)) ?>"></div>
          <div><label for="yearly_om">Yearly O&amp;M allowance (₹)</label><input id="yearly_om" class="sf-input" type="number" min="0" step="1" value="<?= htmlspecialchars((string) ($defaultValues['yearly_om'] ?? 3000)) ?>"></div>
          <div><label for="inverter_reserve">Inverter replacement reserve (₹)</label><input id="inverter_reserve" class="sf-input" type="number" min="0" step="1" value="<?= htmlspecialchars((string) ($defaultValues['inverter_reserve'] ?? 35000)) ?>"></div>
          <div><label for="inverter_year">Inverter replacement year</label><input id="inverter_year" class="sf-input" type="number" min="1" step="1" value="<?= htmlspecialchars((string) ($defaultValues['inverter_year'] ?? 12)) ?>"></div>
          <div><label for="efficiency_factor">Efficiency / shadow factor</label><input id="efficiency_factor" class="sf-input" type="number" min="0.4" max="1.2" step="0.01" value="<?= htmlspecialchars((string) ($defaultValues['efficiency_factor'] ?? 1)) ?>"></div>
          <div><label for="net_metering">Net metering assumption</label><select id="net_metering" class="sf-select"><option value="1">Standard offset assumption</option><option value="0.85">Conservative offset assumption</option></select></div>
          <div><label for="roof_area_per_kw">Roof area planning factor (sq ft/kW)</label><input id="roof_area_per_kw" class="sf-input" type="number" min="60" step="1" value="<?= htmlspecialchars((string) ($defaultValues['roof_area_per_kw'] ?? 90)) ?>"></div>
        </div>
      </details>

      <button type="submit" class="sf-btn-main">How solar adoption would look like</button>
      <p id="baseline_note" class="help"></p>
    </form>

    <div id="sf-results" class="sf-results sf-hidden">
      <section class="sf-summary-grid" id="summary_cards"></section>
      <section class="sf-chart"><h3>Monthly outflow comparison</h3><canvas id="monthly_chart"></canvas></section>
      <section class="sf-chart"><h3>Cumulative expense over 25 years</h3><canvas id="cumulative_chart"></canvas></section>
      <section class="sf-meter-wrap" id="payback_meters"></section>
      <section class="sf-two-col" id="financial_cards"></section>
      <section class="sf-two-col" id="generation_green"></section>
      <section class="sf-reco" id="recommendation"></section>
      <section class="sf-disclaimer">Estimates are indicative only. Actual savings depend on roof condition, shadow-free area, consumption pattern, tariff, approvals, and billing structure. Net metering, subsidy, and financing depend on policy and eligibility. Final recommendation should be based on electricity bill review and site survey.</section>
      <section class="sf-cta-box">
        <h3 style="color:#fff;margin-top:0;">Take the next step</h3>
        <a id="wa_cta" class="btn btn-primary" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i><?= htmlspecialchars((string) ($cta['primary'] ?? 'Request a Quotation')) ?></a>
        <a class="btn btn-secondary" href="https://wa.me/917070278178" target="_blank" rel="noopener"><i class="fa-solid fa-headset"></i><?= htmlspecialchars((string) ($cta['secondary'] ?? 'Talk to Dakshayani Enterprises')) ?></a>
        <a class="btn btn-secondary" href="/contact.html"><i class="fa-solid fa-file-upload"></i><?= htmlspecialchars((string) ($cta['tertiary'] ?? 'Upload your electricity bill')) ?></a>
      </section>
    </div>
  </section>
</main>
<footer class="site-footer"></footer>
<script>
(function () {
  const co2Factor = <?= json_encode((float) ($greenFactors['co2_per_kwh_kg'] ?? 0.82)) ?>;
  const treeKgPerYear = <?= json_encode((float) ($greenFactors['tree_kg_per_year'] ?? 21)) ?>;

  const form = document.getElementById('sf-form');
  const resultsEl = document.getElementById('sf-results');
  const baselineNoteEl = document.getElementById('baseline_note');

  const fmt = (n) => new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(Math.max(0, Number(n) || 0));
  const fmt2 = (n) => new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2 }).format(Math.max(0, Number(n) || 0));

  function num(id, fallback = 0) {
    const val = parseFloat(document.getElementById(id).value);
    return Number.isFinite(val) ? val : fallback;
  }

  function value(id) {
    return (document.getElementById(id).value || '').trim();
  }

  function emi(principal, annualRate, months) {
    if (principal <= 0 || months <= 0) return 0;
    const r = annualRate / 1200;
    if (r <= 0) return principal / months;
    return principal * r * Math.pow(1 + r, months) / (Math.pow(1 + r, months) - 1);
  }

  function drawBar(canvasId, labels, values) {
    const canvas = document.getElementById(canvasId);
    const ctx = canvas.getContext('2d');
    const w = canvas.width = canvas.clientWidth * window.devicePixelRatio;
    const h = canvas.height = canvas.clientHeight * window.devicePixelRatio;
    ctx.scale(window.devicePixelRatio, window.devicePixelRatio);
    const width = canvas.clientWidth;
    const height = canvas.clientHeight;
    ctx.clearRect(0, 0, width, height);
    const maxV = Math.max(...values, 1);
    const barW = (width - 70) / labels.length - 24;
    const colors = ['#334155', '#0ea5e9', '#16a34a'];
    labels.forEach((label, i) => {
      const x = 45 + i * ((width - 70) / labels.length) + 12;
      const bh = (values[i] / maxV) * (height - 72);
      const y = height - 42 - bh;
      ctx.fillStyle = colors[i % colors.length];
      ctx.fillRect(x, y, barW, bh);
      ctx.fillStyle = '#0f172a';
      ctx.font = '12px Poppins';
      ctx.fillText('₹' + fmt(values[i]), x, y - 8);
      ctx.fillText(label, x, height - 16);
    });
  }

  function drawLine(canvasId, series) {
    const canvas = document.getElementById(canvasId);
    const ctx = canvas.getContext('2d');
    const w = canvas.width = canvas.clientWidth * window.devicePixelRatio;
    const h = canvas.height = canvas.clientHeight * window.devicePixelRatio;
    ctx.scale(window.devicePixelRatio, window.devicePixelRatio);
    const width = canvas.clientWidth;
    const height = canvas.clientHeight;
    ctx.clearRect(0, 0, width, height);
    const padding = 38;
    const points = series.flatMap((s) => s.values);
    const maxY = Math.max(...points, 1);
    const minY = Math.min(...points, 0);
    const span = Math.max(maxY - minY, 1);

    ctx.strokeStyle = '#cbd5e1';
    ctx.beginPath();
    ctx.moveTo(padding, padding);
    ctx.lineTo(padding, height - padding);
    ctx.lineTo(width - padding, height - padding);
    ctx.stroke();

    series.forEach((s) => {
      ctx.strokeStyle = s.color;
      ctx.lineWidth = 2;
      ctx.beginPath();
      s.values.forEach((v, i) => {
        const x = padding + (i / (s.values.length - 1)) * (width - 2 * padding);
        const y = height - padding - ((v - minY) / span) * (height - 2 * padding);
        if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
      });
      ctx.stroke();
    });

    let lx = padding;
    series.forEach((s) => {
      ctx.fillStyle = s.color;
      ctx.fillRect(lx, 10, 14, 4);
      ctx.fillStyle = '#0f172a';
      ctx.font = '12px Poppins';
      ctx.fillText(s.label, lx + 18, 15);
      lx += 165;
    });
  }

  function paybackYears(initial, yearlySavings) {
    if (initial <= 0) return 0;
    let cum = 0;
    for (let y = 0; y < yearlySavings.length; y++) {
      cum += yearlySavings[y];
      if (cum >= initial) return y + 1;
    }
    return 25;
  }

  function buildWhatsappLink(data) {
    const lines = [
      'Hello Dakshayani Team, I want a quotation for solar.',
      '',
      `Customer Name: ${data.customerName || '-'}`,
      `Mobile: ${data.mobile || '-'}`,
      `City/Location: ${data.location || '-'}`,
      `Property Type: ${data.propertyType}`,
      `Roof Type: ${data.roofType}`,
      `Monthly Bill (₹): ${fmt(data.monthlyBill)}`,
      `Monthly Units: ${fmt2(data.monthlyUnits)}`,
      `Solar Size (kW): ${fmt2(data.solarSize)}`,
      `System Cost (₹): ${fmt(data.systemCost)}`,
      `Subsidy (₹): ${fmt(data.subsidy)}`,
      `Loan Amount (₹): ${fmt(data.loanAmount)}`,
      `Margin Money (₹): ${fmt(data.marginMoney)}`,
      `Interest Rate (%): ${fmt2(data.loanInterest)}`,
      `Tenure (years): ${fmt2(data.loanTenureYears)}`,
      `Recommended System: ${data.recommendedSystem}`,
      `Bill Offset %: ${fmt2(data.billOffset)}%`,
      `Roof Area Needed (sq ft): ${fmt(data.roofAreaNeeded)}`,
      `Estimated Monthly Generation (units): ${fmt2(data.monthlyGeneration)}`,
      `Monthly Outflow with Loan (₹): ${fmt(data.monthlyWithLoan)}`,
      `Monthly Outflow without Loan (₹): ${fmt(data.monthlySelf)}`
    ];
    return `https://wa.me/917070278178?text=${encodeURIComponent(lines.join('\n'))}`;
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();

    const monthlyBill = num('monthly_bill');
    const monthlyUnits = num('monthly_units');
    const propertyType = value('property_type');
    const roofType = value('roof_type');
    const roofArea = num('roof_area');
    const location = value('location');
    const daytimeUsage = value('daytime_usage');
    const needBackup = value('need_backup');

    const solarSizeInput = num('solar_size', 0);
    const dailyGeneration = num('daily_generation', 5);
    const unitRate = num('unit_rate', 8);
    const systemCost = num('system_cost');
    const subsidy = num('subsidy');
    const loanInterest = num('loan_interest');
    const loanTenureYears = num('loan_tenure');
    const loanTenureMonths = Math.max(0, Math.round(loanTenureYears * 12));

    const fixedCharge = num('fixed_charge');
    const tariffEsc = num('tariff_escalation') / 100;
    const solarDegradation = num('solar_degradation') / 100;
    const yearlyOm = num('yearly_om');
    const inverterReserve = num('inverter_reserve');
    const inverterYear = Math.max(1, Math.round(num('inverter_year', 12)));
    const efficiencyFactor = num('efficiency_factor', 1) || 1;
    const netMeteringFactor = num('net_metering', 1);
    const roofPerKw = num('roof_area_per_kw', 90);

    const monthlyGenPerKw = dailyGeneration * 30 * efficiencyFactor;

    let recommendedKw = solarSizeInput;
    const inferredUnits = monthlyUnits > 0 ? monthlyUnits : (monthlyBill > 0 && unitRate > 0 ? monthlyBill / unitRate : 0);
    if (inferredUnits > 0 && monthlyGenPerKw > 0) {
      recommendedKw = Math.max(recommendedKw, inferredUnits / (monthlyGenPerKw * netMeteringFactor));
    }
    if (roofArea > 0 && roofPerKw > 0) {
      recommendedKw = Math.min(recommendedKw, roofArea / roofPerKw);
    }
    recommendedKw = Math.max(0.5, recommendedKw);

    const monthlySolarGeneration = recommendedKw * monthlyGenPerKw;
    const monthlySolarValue = monthlySolarGeneration * unitRate * netMeteringFactor;

    let baselineMonthlyBill = 0;
    let baselineSource = '';
    if (monthlyBill > 0) {
      baselineMonthlyBill = monthlyBill;
      baselineSource = 'entered monthly bill';
      baselineNoteEl.textContent = '';
    } else if (monthlyUnits > 0) {
      baselineMonthlyBill = monthlyUnits * unitRate;
      baselineSource = 'monthly units × tariff';
      baselineNoteEl.textContent = 'Monthly bill was not entered, so baseline is derived from monthly units and tariff.';
    } else {
      baselineMonthlyBill = monthlySolarValue;
      baselineSource = 'solar fallback estimate';
      baselineNoteEl.textContent = 'Since monthly bill was not entered, this is an indicative estimate based on solar size, generation and tariff.';
    }

    const residualBill = Math.max(baselineMonthlyBill - monthlySolarValue, fixedCharge > 0 ? fixedCharge : 0);
    const netPayable = Math.max(systemCost - subsidy, 0);
    const loanAmountInput = num('loan_amount', netPayable);
    const loanAmount = loanAmountInput > 0 ? loanAmountInput : netPayable;
    const marginInput = num('margin_money');
    const marginMoney = marginInput > 0 ? marginInput : Math.max(netPayable - loanAmount, 0);
    const monthlyEmi = emi(loanAmount, loanInterest, loanTenureMonths);

    const monthlyNoSolar = baselineMonthlyBill;
    const monthlyWithLoan = monthlyEmi + residualBill;
    const monthlySelf = residualBill;

    const noSolarCumulative = [];
    const loanCumulative = [];
    const selfCumulative = [];
    const yearlySavingSelf = [];
    const yearlySavingLoan = [];
    let cumNo = 0;
    let cumLoan = marginMoney;
    let cumSelf = netPayable;

    for (let year = 1; year <= 25; year++) {
      const tariffFactor = Math.pow(1 + tariffEsc, year - 1);
      const degradationFactor = Math.pow(1 - solarDegradation, year - 1);

      const annualBaseline = baselineMonthlyBill * 12 * tariffFactor;
      const annualSolarValue = monthlySolarValue * 12 * tariffFactor * degradationFactor;
      const annualResidual = Math.max(annualBaseline - annualSolarValue, (fixedCharge > 0 ? fixedCharge : 0) * 12);
      const annualEmi = year <= loanTenureYears ? monthlyEmi * 12 : 0;
      const inverterCost = year === inverterYear ? inverterReserve : 0;

      const annualNo = annualBaseline;
      const annualLoan = annualResidual + annualEmi + yearlyOm + inverterCost;
      const annualSelf = annualResidual + yearlyOm + inverterCost;

      cumNo += annualNo;
      cumLoan += annualLoan;
      cumSelf += annualSelf;
      noSolarCumulative.push(cumNo);
      loanCumulative.push(cumLoan);
      selfCumulative.push(cumSelf);

      yearlySavingSelf.push(annualNo - annualSelf);
      yearlySavingLoan.push(annualNo - annualLoan);
    }

    const selfPayback = paybackYears(netPayable, yearlySavingSelf);
    const loanPayback = paybackYears(marginMoney, yearlySavingLoan);

    const roofAreaNeeded = recommendedKw * roofPerKw;
    const billOffset = baselineMonthlyBill > 0 ? Math.min(100, (monthlySolarValue / baselineMonthlyBill) * 100) : 0;
    const annualGeneration = monthlySolarGeneration * 12;
    const generation25 = annualGeneration * 25 * (1 - (solarDegradation * 0.35));
    const annualSaving = Math.max(monthlyNoSolar - monthlySelf, 0) * 12;
    const saved25 = Math.max(noSolarCumulative[24] - selfCumulative[24], 0);

    const systemSuggested = (needBackup === 'Yes' || daytimeUsage === 'Low') ? 'Hybrid' : 'On-grid';

    document.getElementById('summary_cards').innerHTML = [
      ['Recommended solar size', `${fmt2(recommendedKw)} kW`],
      ['Roof area needed', `${fmt(roofAreaNeeded)} sq ft`],
      ['Estimated monthly generation', `${fmt2(monthlySolarGeneration)} units`],
      ['Approx bill offset', `${fmt2(billOffset)}%`],
      ['Best suggested system', systemSuggested]
    ].map(([k, v]) => `<article class="sf-summary"><div class="k">${k}</div><div class="v">${v}</div></article>`).join('');

    drawBar('monthly_chart', ['No Solar', 'With Solar (Loan)', 'With Solar (Self)'], [monthlyNoSolar, monthlyWithLoan, monthlySelf]);
    drawLine('cumulative_chart', [
      { label: 'No Solar', color: '#334155', values: noSolarCumulative },
      { label: 'With Solar (Loan)', color: '#0ea5e9', values: loanCumulative },
      { label: 'With Solar (Self Funded)', color: '#16a34a', values: selfCumulative }
    ]);

    document.getElementById('payback_meters').innerHTML = [
      ['Self-funded payback', selfPayback, netPayable],
      ['Upfront investment recovery (Loan)', loanPayback, marginMoney]
    ].map(([title, years, amount]) => {
      const pct = Math.max(6, Math.min(100, (years / 25) * 100));
      return `<article class="sf-meter"><h3>${title}</h3><p>Approx ${years} years</p><p class="help">Investment considered: ₹${fmt(amount)}</p><div class="meter-track"><div class="meter-fill" style="width:${pct}%"></div></div></article>`;
    }).join('');

    const financialLoan = {
      'System cost': `₹${fmt(systemCost)}`,
      'Subsidy': `₹${fmt(subsidy)}`,
      'Gross payable / net payable': `₹${fmt(systemCost)} / ₹${fmt(netPayable)}`,
      'Margin money': `₹${fmt(marginMoney)}`,
      'Loan amount': `₹${fmt(loanAmount)}`,
      'Interest rate': `${fmt2(loanInterest)}%`,
      'Tenure': `${fmt2(loanTenureYears)} years`,
      'EMI': `₹${fmt(monthlyEmi)}`,
      'Residual bill': `₹${fmt(residualBill)}`,
      'Total monthly outflow': `₹${fmt(monthlyWithLoan)}`
    };

    const financialSelf = {
      'System cost': `₹${fmt(systemCost)}`,
      'Subsidy': `₹${fmt(subsidy)}`,
      'Net investment': `₹${fmt(netPayable)}`,
      'Residual bill': `₹${fmt(residualBill)}`,
      'Estimated monthly saving': `₹${fmt(Math.max(monthlyNoSolar - monthlySelf, 0))}`,
      'Estimated annual saving': `₹${fmt(annualSaving)}`
    };

    function listHtml(obj, title) {
      return `<article class="sf-card"><h3>${title}</h3><ul class="sf-list">${Object.entries(obj).map(([k,v]) => `<li><span>${k}</span><span>${v}</span></li>`).join('')}</ul></article>`;
    }

    document.getElementById('financial_cards').innerHTML = listHtml(financialLoan, 'With Loan') + listHtml(financialSelf, 'Without Loan');

    const annualCo2 = (annualGeneration * co2Factor) / 1000;
    const co225 = annualCo2 * 25;
    const treesEquivalent = (annualCo2 * 1000) / Math.max(treeKgPerYear, 1);

    const generationCard = {
      'Expected monthly generation': `${fmt2(monthlySolarGeneration)} units`,
      'Expected annual generation': `${fmt2(annualGeneration)} units`,
      '25-year generation': `${fmt2(generation25)} units`,
      'Estimated annual savings': `₹${fmt(annualSaving)}`,
      'Estimated payback': `${selfPayback} years`,
      '₹ saved in 25 years': `₹${fmt(saved25)}`
    };

    const greenCard = {
      'Estimated CO₂ avoided annually': `${fmt2(annualCo2)} tCO₂`,
      'CO₂ avoided in 25 years': `${fmt2(co225)} tCO₂`,
      'Equivalent trees planted': `${fmt(treesEquivalent)}`,
      'Baseline source used': baselineSource
    };

    document.getElementById('generation_green').innerHTML = listHtml(generationCard, 'Generation estimate') + listHtml(greenCard, 'Green impact');

    const recoLines = [
      `Best for lowest monthly outflow: ${monthlySelf <= monthlyWithLoan ? 'With Solar (Self Funded)' : 'With Solar (Loan)'}.`,
      `Best for highest lifetime savings: ${selfCumulative[24] <= loanCumulative[24] ? 'With Solar (Self Funded)' : 'With Solar (Loan)'}.`,
      'Best for lowest initial investment: With Solar (Loan).',
      `Best for backup during power cuts: ${needBackup === 'Yes' ? 'Hybrid' : 'On-grid (hybrid only if backup is needed)'}.`
    ];

    document.getElementById('recommendation').innerHTML = `<h3>Recommendation summary</h3><ul>${recoLines.map((line) => `<li>${line}</li>`).join('')}</ul>`;

    const waUrl = buildWhatsappLink({
      customerName: value('customer_name'),
      mobile: value('mobile'),
      location,
      propertyType,
      roofType,
      monthlyBill: baselineMonthlyBill,
      monthlyUnits,
      solarSize: recommendedKw,
      systemCost,
      subsidy,
      loanAmount,
      marginMoney,
      loanInterest,
      loanTenureYears,
      recommendedSystem: systemSuggested,
      billOffset,
      roofAreaNeeded,
      monthlyGeneration: monthlySolarGeneration,
      monthlyWithLoan,
      monthlySelf
    });
    const waCta = document.getElementById('wa_cta');
    waCta.href = waUrl;

    resultsEl.classList.remove('sf-hidden');
    resultsEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
})();
</script>
<script src="script.js" defer></script>
</body>
</html>
